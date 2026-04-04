<?php
namespace pilot\missionroster\controller;

class main {

    protected $db, $template, $helper, $request, $user, $table_prefix, $auth;

    // Purge automatique des missions superieures a x mois
    const ROSTER_PURGE_MONTHS = 12;

    // Liste des simulateurs disponibles — modifiez ici pour ajouter/retirer un tag
    const ALLOWED_TAGS = ['IL2', 'DCS', 'BMS', 'AUTRES'];

    public function __construct($db, $template, $helper, $request, $user, $table_prefix, $auth) {
        $this->db           = $db;
        $this->template     = $template;
        $this->helper       = $helper;
        $this->request      = $request;
        $this->user         = $user;
        $this->table_prefix = $table_prefix;
        $this->auth         = $auth;
    }

    // =========================================================================
    // UTILITAIRES PRIVÉS
    // =========================================================================

    // Retourne les IDs des groupes système toujours autorisés (hors bots)
    private function get_system_group_ids() {
        $res = $this->db->sql_query(
            "SELECT group_id FROM " . GROUPS_TABLE . "
             WHERE group_type = 3
             AND UPPER(group_name) NOT IN ('BOTS')"
        );
        $ids = [];
        while ($row = $this->db->sql_fetchrow($res)) { $ids[] = (int)$row['group_id']; }
        return $ids;
    }

    // Retourne les IDs des groupes admin (non décochables par le créateur)
    private function get_admin_group_ids() {
        $res = $this->db->sql_query(
            "SELECT group_id FROM " . GROUPS_TABLE . "
             WHERE group_type = 3
             AND UPPER(group_name) IN ('ADMINISTRATORS', 'GLOBAL_MODERATORS')"
        );
        $ids = [];
        while ($row = $this->db->sql_fetchrow($res)) { $ids[] = (int)$row['group_id']; }
        return $ids;
    }

    // Convertit un timestamp UTC en chaîne datetime-local (format input HTML)
    // en utilisant le timezone de l'utilisateur — symétrique de date_to_timestamp()
    private function timestamp_to_local($ts) {
        if (!$ts) { return ''; }
        try {
            $tz_str = '';
            if (!empty($this->user->data['user_timezone'])) {
                $tz_str = $this->user->data['user_timezone'];
            } else {
                global $config;
                $tz_str = !empty($config['board_timezone']) ? $config['board_timezone'] : 'UTC';
            }
            $tz = new \DateTimeZone($tz_str);
            $dt = new \DateTime('@' . $ts); // @ = timestamp UTC
            $dt->setTimezone($tz);
            return $dt->format('Y-m-d\TH:i');
        } catch (\Exception $e) {
            return date('Y-m-d\TH:i', $ts);
        }
    }

    // Convertit une date saisie (format HTML datetime-local) en timestamp UTC
    // Utilise le timezone de l'utilisateur connecté, identique à format_date()
    // pour garantir que ce qui est saisi = ce qui est affiché
    private function date_to_timestamp($date_str) {
        if (empty($date_str)) { return 0; }
        try {
            // Priorité : timezone de l'utilisateur (cohérent avec format_date)
            // sinon timezone du forum, sinon UTC
            $tz_str = '';
            if (!empty($this->user->data['user_timezone'])) {
                $tz_str = $this->user->data['user_timezone'];
            } else {
                global $config;
                $tz_str = !empty($config['board_timezone']) ? $config['board_timezone'] : 'UTC';
            }
            $tz = new \DateTimeZone($tz_str);
            $dt = new \DateTime($date_str, $tz);
            return $dt->getTimestamp();
        } catch (\Exception $e) {
            return (int)strtotime($date_str);
        }
    }

    // Vérifie si la colonne external_name existe dans mission_roster
    // (présente uniquement après migration v201)
    private function has_external_name_column() {
        static $checked = null;
        if ($checked !== null) { return $checked; }
        $result  = $this->db->sql_query('SHOW COLUMNS FROM ' . $this->table_prefix . 'mission_roster');
        $checked = false;
        while ($row = $this->db->sql_fetchrow($result)) {
            if ($row['Field'] === 'external_name') { $checked = true; break; }
        }
        $this->db->sql_freeresult($result);
        return $checked;
    }

    private function can_edit_mission($mission) {
        $uid = (int)$this->user->data['user_id'];
        if ($uid <= 0) { return false; }
        // Créateur toujours autorisé
        if ((int)$mission['creator_id'] === $uid) { return true; }
        // Admins et modérateurs globaux toujours autorisés
        if ($this->auth->acl_get('a_') || $this->auth->acl_get('m_')) { return true; }
        // Permission ACP u_mission_edit (définie par l'admin pour des groupes spécifiques)
        if ($this->auth->acl_get('u_mission_edit')) { return true; }
        return false;
    }

    private function count_titulaires($mission_id, $slot_name) {
        $sql = 'SELECT COUNT(*) as cnt FROM ' . $this->table_prefix . 'mission_roster
                WHERE mission_id = ' . (int)$mission_id . "
                AND slot_name = '" . $this->db->sql_escape($slot_name) . "'
                AND status = 'Titulaire'";
        $row = $this->db->sql_fetchrow($this->db->sql_query($sql));
        return (int)$row['cnt'];
    }

    private function promote_reservistes_to_fill($mission_id, $slot_name, $new_max, $mission_title) {
        $places = $new_max - $this->count_titulaires($mission_id, $slot_name);
        if ($places <= 0) { return; }
        $sql = 'SELECT r.id, r.user_id, u.username
                FROM ' . $this->table_prefix . 'mission_roster r
                JOIN ' . USERS_TABLE . ' u ON r.user_id = u.user_id
                WHERE r.mission_id = ' . (int)$mission_id . "
                AND r.slot_name = '" . $this->db->sql_escape($slot_name) . "'
                AND r.status = 'Reserviste'
                ORDER BY r.id ASC";
        $res = $this->db->sql_query($sql);
        $n = 0;
        while ($row = $this->db->sql_fetchrow($res)) {
            if ($n >= $places) { break; }
            $this->db->sql_query('UPDATE ' . $this->table_prefix . 'mission_roster
                SET status = \'Titulaire\' WHERE id = ' . (int)$row['id']);
            $this->send_pm('promotion', $row['user_id'], $row['username'], $mission_title, $mission_id);
            $n++;
        }
    }

    private function purge_old_missions() {
        $cutoff = strtotime('-' . self::ROSTER_PURGE_MONTHS . ' months');
        $res    = $this->db->sql_query('SELECT id FROM ' . $this->table_prefix . 'missions WHERE date_mission < ' . (int)$cutoff);
        $ids = [];
        while ($row = $this->db->sql_fetchrow($res)) { $ids[] = (int)$row['id']; }
        if (!$ids) { return; }
        $in = implode(',', $ids);
        $this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'mission_roster WHERE mission_id IN (' . $in . ')');
        $this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'missions WHERE id IN (' . $in . ')');
    }

    private function send_pm($type, $recipient_id, $recipient_name, $mission_title, $mission_id, $extra = []) {
        // Vérification que le destinataire est un utilisateur valide et actif
        $recipient_id = (int)$recipient_id;
        if ($recipient_id <= 0 || $recipient_id === ANONYMOUS) { return; }

        $check = $this->db->sql_fetchrow($this->db->sql_query(
            'SELECT user_id FROM ' . USERS_TABLE . '
             WHERE user_id = ' . $recipient_id . '
             AND user_type <> 2
             AND user_inactive_reason = 0'
        ));
        if (!$check) { return; }

        // Limite de 80 MPs par mission pour éviter la surcharge
        static $pm_counters = [];
        $key = $mission_id . '_' . $type;
        if (!isset($pm_counters[$key])) { $pm_counters[$key] = 0; }
        if ($pm_counters[$key] >= 80) { return; }
        $pm_counters[$key]++;

        // Décoder le titre pour éviter &quot; ou &amp; dans le texte du MP
        $mission_title = html_entity_decode($mission_title, ENT_QUOTES, 'UTF-8');

        $sender_id   = (int)$this->user->data['user_id'];
        $sender_name = $this->db->sql_escape($this->user->data['username'] ?? 'Mission Roster');

        $url = generate_board_url() . $this->helper->route('pilot_mission_view', ['id' => $mission_id]);
        $mission_title_safe = $this->db->sql_escape($mission_title);

        switch ($type) {
            case 'promotion':
                $subject = '[Mission Roster] Promu Titulaire — ' . $mission_title;
                $body    = 'Bonjour ' . $recipient_name . ', une place s\'est libérée, vous êtes promu Titulaire pour "' . $mission_title . '". ' . '[url]' . $url . '[/url]';
                break;
            case 'retrogradation':
                $subject = '[Mission Roster] Statut modifié — ' . $mission_title;
                $body    = 'Bonjour ' . $recipient_name . ', suite à une réduction de places dans "' . ($extra['slot_name'] ?? '') . '", vous passez Titulaire → Réserviste. ' . '[url]' . $url . '[/url]';
                break;
            case 'desinscription':
                $subject = '[Mission Roster] Inscription annulée — ' . $mission_title;
                $body    = 'Bonjour ' . $recipient_name . ', le groupe "' . ($extra['slot_name'] ?? '') . '" a été supprimé, votre inscription est annulée. ' . '[url]' . $url . '[/url]';
                break;
            case 'modification':
                $subject = '[Mission Roster] Inscription modifiée — ' . $mission_title;
                $body    = 'Bonjour ' . $recipient_name . ', votre inscription a été modifiée. Groupe : ' . ($extra['new_slot'] ?? '') . ' — Statut : ' . ($extra['new_status'] ?? '') . '. ' . '[url]' . $url . '[/url]';
                break;
            case 'admin_add':
                $subject = '[Mission Roster] Vous avez été inscrit — ' . $mission_title;
                $body    = 'Bonjour ' . $recipient_name . ', un administrateur vous a inscrit. Groupe : ' . ($extra['slot'] ?? '') . ' — Statut : ' . ($extra['status'] ?? '') . '. ' . '[url]' . $url . '[/url]';
                break;
            case 'admin_modif':
                $subject = '[Mission Roster] Inscription modifiée par un admin — ' . $mission_title;
                $body    = 'Bonjour ' . $recipient_name . ', un administrateur a modifié votre inscription. Groupe : ' . ($extra['new_slot'] ?? '') . ' — Statut : ' . ($extra['new_status'] ?? '') . '. ' . '[url]' . $url . '[/url]';
                break;
            case 'admin_remove':
                $subject = '[Mission Roster] Désinscrit — ' . $mission_title;
                $body    = 'Bonjour ' . $recipient_name . ', un administrateur vous a désinscrit de "' . $mission_title . '". ' . '[url]' . $url . '[/url]';
                break;
            case 'annulation':
                $subject = '[Mission Roster] Mission annulée — ' . $mission_title;
                $body    = 'Bonjour ' . $recipient_name . ', la mission "' . $mission_title . '" a été annulée. Toutes les inscriptions sont supprimées.';
                break;
            case 'date_change':
                $subject = '[Mission Roster] Mission reportée — ' . $mission_title;
                $body    = 'Bonjour ' . $recipient_name . ', la date de la mission "' . $mission_title . '" a été modifiée.'
                         . ' Nouvelle date : ' . ($extra['new_date'] ?? '') . '.'
                         . ' Votre inscription a été annulée — vous pouvez vous réinscrire si la nouvelle date vous convient. '
                         . '[url]' . $url . '[/url]';
                break;
            case 'date_minor_change':
                $subject = '[Mission Roster] Horaire modifié — ' . $mission_title;
                $body    = 'Bonjour ' . $recipient_name . ', l\'horaire de la mission "' . $mission_title . '" a été légèrement modifié.'
                         . ' Nouvel horaire : ' . ($extra['new_date'] ?? '') . '.'
                         . ' Votre inscription est maintenue. '
                         . '[url]' . $url . '[/url]';
                break;
            default: return;
        }

        $now = time();
        $subject_safe = $this->db->sql_escape($subject);
        $body_safe    = $this->db->sql_escape($body);

        // Insertion du message dans phpbb_privmsgs
        $this->db->sql_query(
            "INSERT INTO " . $this->table_prefix . "privmsgs
             (root_level, author_id, icon_id, author_ip, message_time,
              enable_bbcode, enable_smilies, enable_magic_url, enable_sig,
              message_subject, message_text, message_attachment,
              bbcode_bitfield, bbcode_uid, to_address, bcc_address, message_reported)
             VALUES
             (0, " . $sender_id . ", 0, '0.0.0.0', " . $now . ",
              1, 0, 1, 0,
              '" . $subject_safe . "', '" . $body_safe . "', 0,
              '', '', 'u_" . $recipient_id . "', '', 0)"
        );
        $pm_id = (int)$this->db->sql_nextid();
        if (!$pm_id) { return; }

        // Liaison expéditeur
        $this->db->sql_query(
            "INSERT INTO " . $this->table_prefix . "privmsgs_to
             (msg_id, user_id, author_id, pm_deleted, pm_new, pm_unread, pm_replied, pm_marked, pm_forwarded, folder_id)
             VALUES (" . $pm_id . ", " . $sender_id . ", " . $sender_id . ", 0, 0, 0, 1, 0, 0, -1)"
        );

        // Liaison destinataire
        $this->db->sql_query(
            "INSERT INTO " . $this->table_prefix . "privmsgs_to
             (msg_id, user_id, author_id, pm_deleted, pm_new, pm_unread, pm_replied, pm_marked, pm_forwarded, folder_id)
             VALUES (" . $pm_id . ", " . $recipient_id . ", " . $sender_id . ", 0, 1, 1, 0, 0, 0, 0)"
        );

        // Mise à jour du compteur de MPs non lus du destinataire
        $this->db->sql_query(
            "UPDATE " . USERS_TABLE . "
             SET user_unread_privmsg = user_unread_privmsg + 1,
                 user_new_privmsg = user_new_privmsg + 1
             WHERE user_id = " . $recipient_id
        );
    }

    // =========================================================================
    // LISTE DES MISSIONS
    // =========================================================================
    public function list_view() {
        $filter_tag    = $this->request->variable('filter_tag', '', true);
        $sort          = $this->request->variable('sort', 'asc');
        $sort_by       = $this->request->variable('sort_by', 'date'); // date | creator
        $show          = $this->request->variable('show', 'future');
        $now           = time();
        $sort_dir      = $sort === 'desc' ? 'DESC' : 'ASC';

        $where_parts = [];
        if ($show === 'future')   { $where_parts[] = 'm.date_mission >= ' . (int)$now; }
        elseif ($show === 'past') { $where_parts[] = 'm.date_mission < '  . (int)$now; }
        if ($filter_tag !== '') {
            $tag = '[' . str_replace(['[', ']'], '', $filter_tag) . ']';
            $where_parts[] = "m.sim_tag = '" . $this->db->sql_escape($tag) . "'";
        }
        $where_sql  = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';
        $order_col  = $sort_by === 'creator' ? 'u.username' : 'm.date_mission';

        $res = $this->db->sql_query(
            'SELECT m.*, u.username AS creator_name,
                    (SELECT COUNT(*) FROM ' . $this->table_prefix . 'mission_roster r
                     WHERE r.mission_id = m.id AND r.status = \'Titulaire\') AS total_t
             FROM ' . $this->table_prefix . 'missions m
             LEFT JOIN ' . USERS_TABLE . ' u ON m.creator_id = u.user_id
             ' . $where_sql . '
             ORDER BY ' . $order_col . ' ' . $sort_dir
        );
        while ($row = $this->db->sql_fetchrow($res)) {
            // Calcul total slots depuis JSON (fallback PHP si MySQL JSON non dispo)
            $slots     = json_decode($row['slots_config'], true) ?: [];
            $total_max = array_sum(array_column($slots, 'count'));
            $this->template->assign_block_vars('missions', [
                'TAG'          => htmlspecialchars($row['sim_tag'], ENT_NOQUOTES, 'UTF-8'),
                'TITRE'        => html_entity_decode($row['titre'], ENT_QUOTES, 'UTF-8'),
                'DATE'         => $this->user->format_date($row['date_mission']),
                'IS_PAST'      => $row['date_mission'] < $now,
                'CREATOR'      => $row['creator_name'] ?? '—',
                'SLOTS_RATIO'  => (int)$row['total_t'] . '/' . $total_max,
                'U_VIEW'       => $this->helper->route('pilot_mission_view', ['id' => (int)$row['id']]),
            ]);
        }
        $res_tags = $this->db->sql_query(
            'SELECT DISTINCT sim_tag FROM ' . $this->table_prefix . 'missions ORDER BY sim_tag ASC'
        );
        while ($tag = $this->db->sql_fetchrow($res_tags)) {
            $this->template->assign_block_vars('sim_tags', [
                'VALUE' => htmlspecialchars(trim($tag['sim_tag'], '[]'), ENT_NOQUOTES, 'UTF-8'),
            ]);
        }
        $base = $this->helper->route('pilot_mission_list');
        $this->template->assign_vars([
            'S_CAN_CREATE'         => $this->auth->acl_get('u_mission_create'),
            'U_CREATE'             => $this->helper->route('pilot_mission_create'),
            'FILTER_TAG'           => htmlspecialchars($filter_tag, ENT_NOQUOTES, 'UTF-8'),
            'SORT_CURRENT'         => $sort,
            'SORT_BY_CURRENT'      => $sort_by,
            'SHOW_CURRENT'         => $show,
            'U_SORT_DATE_TOGGLE'   => $base . '?sort_by=date&sort='    . ($sort_by === 'date'    ? ($sort === 'asc' ? 'desc' : 'asc') : 'asc') . '&show=' . $show . ($filter_tag !== '' ? '&filter_tag=' . urlencode($filter_tag) : ''),
            'U_SORT_CREATOR_TOGGLE'=> $base . '?sort_by=creator&sort=' . ($sort_by === 'creator' ? ($sort === 'asc' ? 'desc' : 'asc') : 'asc') . '&show=' . $show . ($filter_tag !== '' ? '&filter_tag=' . urlencode($filter_tag) : ''),
            'U_LIST_BASE'          => $base,
        ]);
        return $this->helper->render('mission_list.html', 'Liste des Missions');
    }

    // =========================================================================
    // VUE DÉTAILLÉE
    // =========================================================================
    public function view_mission($id) {
        $mission_id = (int)$id;
        $m = $this->db->sql_fetchrow($this->db->sql_query(
            'SELECT m.*, u.username AS creator_name FROM ' . $this->table_prefix . 'missions m
             LEFT JOIN ' . USERS_TABLE . ' u ON m.creator_id = u.user_id
             WHERE m.id = ' . $mission_id
        ));
        if (!$m) { trigger_error('MISSION_NOT_FOUND'); }

        // Anonymous ne peut jamais s'inscrire
        $is_anonymous = ((int)$this->user->data['user_id'] === ANONYMOUS);

        $res_u    = $this->db->sql_query(
            'SELECT group_id FROM ' . USER_GROUP_TABLE . ' WHERE user_id = ' . (int)$this->user->data['user_id'] . ' AND user_pending = 0'
        );
        $u_groups = [];
        while ($rg = $this->db->sql_fetchrow($res_u)) { $u_groups[] = $rg['group_id']; }
        $can_register = !$is_anonymous && (bool)array_intersect(explode(',', $m['allowed_groups']), $u_groups);

        $roster = []; $stats = ['Titulaire' => 0, 'Reserviste' => 0, 'Probable' => 0];
        $user_in = false; $user_row = null; $roster_rows = [];
        $total_t = 0; $total_max = 0;

        $res_r = $this->db->sql_query(
            'SELECT r.*, u.username FROM ' . $this->table_prefix . 'mission_roster r
             LEFT JOIN ' . USERS_TABLE . ' u ON r.user_id = u.user_id AND r.user_id > 0
             WHERE r.mission_id = ' . $mission_id . ' ORDER BY r.id ASC'
        );
        while ($row = $this->db->sql_fetchrow($res_r)) {
            $display = !empty($row['external_name'])
                ? '[EXT] ' . $row['external_name']
                : ($row['username'] ?? '?');
            $roster[$row['slot_name']][$row['status']][] = $display;
            $stats[$row['status']]++;
            $roster_rows[] = $row;
            if ((int)$row['user_id'] === (int)$this->user->data['user_id']) {
                $user_in = true; $user_row = $row;
            }
        }

        $slots = json_decode($m['slots_config'], true);
        $slots_status = [];
        foreach ($slots as $s) {
            $nb_t    = count($roster[$s['name']]['Titulaire'] ?? []);
            $is_full = $nb_t >= $s['count'];
            $total_t   += $nb_t;
            $total_max += (int)$s['count'];
            $this->template->assign_block_vars('slots', [
                'NAME'    => htmlspecialchars($s['name'], ENT_NOQUOTES, 'UTF-8'),
                'MAX'     => (int)$s['count'],
                'COUNT_T' => $nb_t,
                'PERCENT' => $s['count'] > 0 ? round($nb_t / $s['count'] * 100) : 0,
                'LIST_T'  => implode(', ', $roster[$s['name']]['Titulaire']  ?? ['—']),
                'LIST_R'  => implode(', ', $roster[$s['name']]['Reserviste'] ?? ['—']),
                'LIST_P'  => implode(', ', $roster[$s['name']]['Probable']   ?? ['—']),
                'S_FULL'  => $is_full,
            ]);
            foreach (array_filter($roster_rows, fn($r) => $r['slot_name'] === $s['name']) as $ins) {
                $ext_name = $ins['external_name'] ?? '';
                $disp_name = !empty($ext_name)
                    ? '[EXT] ' . htmlspecialchars($ext_name, ENT_NOQUOTES, 'UTF-8')
                    : htmlspecialchars($ins['username'] ?? '', ENT_NOQUOTES, 'UTF-8');
                $this->template->assign_block_vars('slots.admin_inscrits', [
                    'USER_ID'     => (int)$ins['user_id'],
                    'USERNAME'    => $disp_name,
                    'STATUS'      => htmlspecialchars($ins['status'], ENT_NOQUOTES, 'UTF-8'),
                    'SLOT_NAME'   => htmlspecialchars($ins['slot_name'], ENT_NOQUOTES, 'UTF-8'),
                    'IS_EXTERNAL' => !empty($ext_name),
                ]);
            }
            $slots_status[] = [
                'name'       => $s['name'],
                'max'        => (int)$s['count'],
                'titulaires' => $nb_t,
                'full'       => $is_full,
            ];
        }

        $can_edit = $this->can_edit_mission($m);
        if ($can_edit) {
            add_form_key('pilot_roster_cancel_' . $mission_id);
            $res_m = $this->db->sql_query(
                'SELECT user_id, username FROM ' . USERS_TABLE
                . ' WHERE user_type <> 2 AND user_id <> ' . ANONYMOUS . ' ORDER BY username ASC'
            );
            while ($mb = $this->db->sql_fetchrow($res_m)) {
                $this->template->assign_block_vars('all_members', [
                    'ID'   => (int)$mb['user_id'],
                    'NAME' => htmlspecialchars($mb['username'], ENT_NOQUOTES, 'UTF-8'),
                ]);
            }
        }

        $this->template->assign_vars([
            'MISSION_TITLE'       => html_entity_decode($m['titre'], ENT_QUOTES, 'UTF-8'),
            'MISSION_DESCRIPTION' => html_entity_decode($m['description'], ENT_QUOTES, 'UTF-8'),
            'MISSION_CREATOR'     => $m['creator_name'] ?? '—',
            'SIM_TAG'             => htmlspecialchars($m['sim_tag'], ENT_NOQUOTES, 'UTF-8'),
            'MISSION_DATE'        => $this->user->format_date($m['date_mission']),
            'MISSION_DATE_LIMITE' => $this->user->format_date($m['date_limite']),
            'MISSION_ID'          => $mission_id,
            'TOTAL_T'             => $stats['Titulaire'],
            'TOTAL_R'             => $stats['Reserviste'],
            'TOTAL_P'             => $stats['Probable'],
            'SLOTS_RATIO'         => $total_t . '/' . $total_max,
            'ALLOW_P'             => (bool)$m['allow_probables'],
            'USER_INSCRI'         => $user_in,
            'USER_SLOT'           => $user_row ? htmlspecialchars($user_row['slot_name'], ENT_NOQUOTES, 'UTF-8') : '',
            'USER_STATUS'         => $user_row ? htmlspecialchars($user_row['status'], ENT_NOQUOTES, 'UTF-8')    : '',
            'S_CAN_JOIN'          => $can_register && time() < $m['date_limite'] && time() < $m['date_mission'],
            'S_CAN_MODIFY'        => $user_in && time() < $m['date_mission'],
            'S_CAN_EDIT'          => $can_edit,
            'U_JOIN'              => $this->helper->route('pilot_mission_join',        ['id' => $mission_id]),
            'U_LEAVE'             => $this->helper->route('pilot_mission_leave',       ['id' => $mission_id]),
            'U_MODIFY'            => $this->helper->route('pilot_mission_modify_view', ['id' => $mission_id]),
            'U_EDIT'              => $this->helper->route('pilot_mission_edit',        ['id' => $mission_id]),
            'U_CANCEL'            => $this->helper->route('pilot_mission_cancel',      ['id' => $mission_id]),
            'U_ADMIN_ADD'         => $this->helper->route('pilot_roster_admin_add',    ['id' => $mission_id]),
            'U_ADMIN_EDIT'        => $this->helper->route('pilot_roster_admin_edit',   ['id' => $mission_id]),
            'U_ADMIN_REMOVE'      => $this->helper->route('pilot_roster_admin_remove', ['id' => $mission_id]),
            'SLOTS_STATUS_JSON'   => addslashes(json_encode($slots_status)),
            'CAPTURE_IMAGE_URL'   => generate_board_url() . $this->helper->route('pilot_mission_image', ['id' => $mission_id]),
            'U_LIST'              => $this->helper->route('pilot_mission_list'),
        ]);
        return $this->helper->render('mission_view.html', html_entity_decode($m['titre'], ENT_QUOTES, 'UTF-8'));
    }

    // =========================================================================
    // AJAX : statut slots (JSON)
    // =========================================================================
    public function slots_status($id) {
        $mission_id = (int)$id;
        $mission    = $this->db->sql_fetchrow($this->db->sql_query(
            'SELECT slots_config FROM ' . $this->table_prefix . 'missions WHERE id = ' . $mission_id
        ));
        if (!$mission) { header('Content-Type: application/json'); echo '{"error":"not found"}'; exit; }
        $out = [];
        foreach (json_decode($mission['slots_config'], true) as $slot) {
            $t     = $this->count_titulaires($mission_id, $slot['name']);
            $out[] = ['name' => $slot['name'], 'max' => (int)$slot['count'], 'titulaires' => $t, 'full' => $t >= $slot['count']];
        }
        header('Content-Type: application/json');
        echo json_encode($out);
        exit;
    }

    // =========================================================================
    // CRÉATION — Formulaire
    // =========================================================================
    public function create_view() {
        if (!$this->auth->acl_get('u_mission_create')) { trigger_error('NOT_AUTHORISED'); }
        add_form_key('pilot_roster');
        foreach (self::ALLOWED_TAGS as $sim) {
            $this->template->assign_block_vars('sims', ['NAME' => $sim]);
        }
        $admin_ids = $this->get_admin_group_ids();
        // Tous les groupes sauf bots
        $res = $this->db->sql_query(
            "SELECT group_id, group_name, group_type FROM " . GROUPS_TABLE . "
             WHERE UPPER(group_name) NOT IN ('BOTS')
             ORDER BY group_type ASC, group_name ASC"
        );
        while ($row = $this->db->sql_fetchrow($res)) {
            $is_admin = in_array((int)$row['group_id'], $admin_ids);
            $this->template->assign_block_vars('glist', [
                'ID'        => $row['group_id'],
                'NAME'      => $row['group_name'],
                'CHECKED'   => true,  // tous cochés par défaut
                'IS_ADMIN'  => $is_admin, // non décochable
                'IS_GUESTS' => strtoupper($row['group_name']) === 'GUESTS',
            ]);
        }
        $this->template->assign_vars(['U_SUBMIT' => $this->helper->route('pilot_mission_submit')]);
        return $this->helper->render('mission_create.html', 'Créer une Mission');
    }

    // =========================================================================
    // CRÉATION — Enregistrement
    // =========================================================================
    public function submit() {
        if (!check_form_key('pilot_roster')) { trigger_error('FORM_INVALID'); }

        $slots    = [];
        $g_names  = $this->request->variable('g_name',  ['']);
        $g_counts = $this->request->variable('g_count', [0]);
        foreach ($g_names as $i => $name) {
            $name  = mb_substr(trim($name), 0, 100);
            $name  = htmlspecialchars(strip_tags($name), ENT_NOQUOTES, 'UTF-8');
            $count = (int)($g_counts[$i] ?? 0);
            if ($name && $count > 0) { $slots[] = ['name' => $name, 'count' => $count]; }
        }
        if (empty($slots))      { trigger_error('Aucun slot valide défini.'); }
        if (count($slots) > 50) { trigger_error('Maximum 50 slots par mission.'); }

        $date_mission_ts = $this->date_to_timestamp($this->request->variable('date_mission', '', true));
        if (!$date_mission_ts || $date_mission_ts <= 0) { trigger_error('Date de mission invalide.'); }

        $date_limite_raw = trim($this->request->variable('date_limite', '', true));
        $date_limite_ts  = !empty($date_limite_raw) ? $this->date_to_timestamp($date_limite_raw) : 0;
        if (!$date_limite_ts || $date_limite_ts <= 0) { $date_limite_ts = $date_mission_ts; }

        $admin_ids   = $this->get_admin_group_ids();
        $chosen      = array_map('intval', $this->request->variable('allowed', [0]));
        $all_allowed = array_unique(array_merge($admin_ids, $chosen));

        $this->db->sql_query(
            'INSERT INTO ' . $this->table_prefix . 'missions ' .
            $this->db->sql_build_array('INSERT', [
                'sim_tag'        => '[' . (in_array($this->request->variable('sim_tag', '', true), self::ALLOWED_TAGS) ? $this->request->variable('sim_tag', '', true) : self::ALLOWED_TAGS[0]) . ']',
                'titre'          => $this->request->variable('titre', '', true),
                'description'    => $this->request->variable('description', '', true),
                'slots_config'   => json_encode($slots),
                'allowed_groups' => implode(',', $all_allowed),
                'date_mission'   => $date_mission_ts,
                'date_limite'    => $date_limite_ts,
                'allow_probables'=> $this->request->variable('allow_p', 1),
                'creator_id'     => (int)$this->user->data['user_id'],
            ])
        );
        $this->purge_old_missions();
        redirect($this->helper->route('pilot_mission_list')); return;
    }

    // =========================================================================
    // ÉDITION — Formulaire
    // =========================================================================
    public function edit_view($id) {
        $mission_id = (int)$id;
        $m = $this->db->sql_fetchrow($this->db->sql_query(
            'SELECT * FROM ' . $this->table_prefix . 'missions WHERE id = ' . $mission_id
        ));
        if (!$m)                          { trigger_error('MISSION_NOT_FOUND'); }
        if (!$this->can_edit_mission($m)) { trigger_error('NOT_AUTHORISED'); }
        add_form_key('pilot_roster_edit_' . $mission_id);

        $allowed_saved = explode(',', $m['allowed_groups']);
        foreach (self::ALLOWED_TAGS as $sim) {
            $this->template->assign_block_vars('sims', ['NAME' => $sim]);
        }
        foreach (json_decode($m['slots_config'], true) as $s) {
            $this->template->assign_block_vars('existing_slots', [
                'NAME'  => htmlspecialchars($s['name'], ENT_NOQUOTES, 'UTF-8'),
                'COUNT' => (int)$s['count'],
            ]);
        }
        $admin_ids = $this->get_admin_group_ids();
        $res = $this->db->sql_query(
            "SELECT group_id, group_name, group_type FROM " . GROUPS_TABLE . "
             WHERE UPPER(group_name) NOT IN ('BOTS')
             ORDER BY group_type ASC, group_name ASC"
        );
        while ($row = $this->db->sql_fetchrow($res)) {
            $is_admin   = in_array((int)$row['group_id'], $admin_ids);
            $is_checked = $is_admin || in_array((string)$row['group_id'], $allowed_saved);
            $this->template->assign_block_vars('glist', [
                'ID'        => $row['group_id'],
                'NAME'      => $row['group_name'],
                'CHECKED'   => $is_checked,
                'IS_ADMIN'  => $is_admin,
                'IS_GUESTS' => strtoupper($row['group_name']) === 'GUESTS',
            ]);
        }
        $this->template->assign_vars([
            'MISSION_ID'         => $mission_id,
            'SIM_TAG_VALUE'      => htmlspecialchars(trim($m['sim_tag'], '[]'), ENT_COMPAT, 'UTF-8'),
            'TITRE_VALUE'        => htmlspecialchars($m['titre'], ENT_COMPAT, 'UTF-8'),
            'DESCRIPTION_VALUE'  => htmlspecialchars($m['description'], ENT_COMPAT, 'UTF-8'),
            'DATE_MISSION_VALUE' => $this->timestamp_to_local($m['date_mission']),
            'DATE_LIMITE_VALUE'  => $this->timestamp_to_local($m['date_limite']),
            'ALLOW_P_CHECKED'    => $m['allow_probables'] ? 'checked' : '',
            'U_UPDATE'           => $this->helper->route('pilot_mission_update', ['id' => $mission_id]),
            'U_BACK'             => $this->helper->route('pilot_mission_view',   ['id' => $mission_id]),
        ]);
        return $this->helper->render('mission_edit.html', 'Modifier la Mission');
    }

    // =========================================================================
    // ÉDITION — Traitement
    // =========================================================================
    public function update_mission($id) {
        $mission_id = (int)$id;
        $m = $this->db->sql_fetchrow($this->db->sql_query(
            'SELECT * FROM ' . $this->table_prefix . 'missions WHERE id = ' . $mission_id
        ));
        if (!$m)                          { trigger_error('MISSION_NOT_FOUND'); }
        if (!$this->can_edit_mission($m)) { trigger_error('NOT_AUTHORISED'); }
        if (!check_form_key('pilot_roster_edit_' . $mission_id)) { trigger_error('FORM_INVALID'); }

        $new_slots = [];
        $g_names   = $this->request->variable('g_name',  ['']);
        $g_counts  = $this->request->variable('g_count', [0]);
        foreach ($g_names as $i => $name) {
            $name  = mb_substr(trim($name), 0, 100);
            $name  = htmlspecialchars(strip_tags($name), ENT_NOQUOTES, 'UTF-8');
            $count = (int)($g_counts[$i] ?? 0);
            if ($name && $count > 0) { $new_slots[] = ['name' => $name, 'count' => $count]; }
        }
        if (empty($new_slots)) { trigger_error('Aucun slot valide.'); }

        $old_slots    = json_decode($m['slots_config'], true);
        $old_slot_map = array_column($old_slots, 'count', 'name');
        $new_names    = array_column($new_slots, 'name');

        foreach ($old_slots as $old) {
            if (!in_array($old['name'], $new_names)) {
                $res = $this->db->sql_query(
                    'SELECT r.user_id, u.username FROM ' . $this->table_prefix . 'mission_roster r
                     JOIN ' . USERS_TABLE . " u ON r.user_id = u.user_id
                     WHERE r.mission_id = " . $mission_id . "
                     AND r.slot_name = '" . $this->db->sql_escape($old['name']) . "'"
                );
                while ($ins = $this->db->sql_fetchrow($res)) {
                    $this->send_pm('desinscription', $ins['user_id'], $ins['username'], $m['titre'], $mission_id, ['slot_name' => $old['name']]);
                }
                $this->db->sql_query(
                    'DELETE FROM ' . $this->table_prefix . "mission_roster
                     WHERE mission_id = " . $mission_id . "
                     AND slot_name = '" . $this->db->sql_escape($old['name']) . "'"
                );
            }
        }
        foreach ($new_slots as $new) {
            if (!isset($old_slot_map[$new['name']])) { continue; }
            $old_max = (int)$old_slot_map[$new['name']];
            $new_max = (int)$new['count'];
            if ($new_max < $old_max) {
                $res  = $this->db->sql_query(
                    'SELECT r.id, r.user_id, u.username FROM ' . $this->table_prefix . 'mission_roster r
                     JOIN ' . USERS_TABLE . " u ON r.user_id = u.user_id
                     WHERE r.mission_id = " . $mission_id . "
                     AND r.slot_name = '" . $this->db->sql_escape($new['name']) . "'
                     AND r.status = 'Titulaire'
                     ORDER BY r.id ASC"
                );
                $tits = [];
                while ($row = $this->db->sql_fetchrow($res)) { $tits[] = $row; }
                foreach (array_slice($tits, $new_max) as $ex) {
                    $this->db->sql_query(
                        "UPDATE " . $this->table_prefix . "mission_roster SET status = 'Reserviste' WHERE id = " . (int)$ex['id']
                    );
                    $this->send_pm('retrogradation', $ex['user_id'], $ex['username'], $m['titre'], $mission_id, ['slot_name' => $new['name']]);
                }
            } elseif ($new_max > $old_max) {
                $this->promote_reservistes_to_fill($mission_id, $new['name'], $new_max, $m['titre']);
            }
        }

        $date_mission_raw = trim($this->request->variable('date_mission', '', true));
        $date_limite_raw  = trim($this->request->variable('date_limite',  '', true));
        $date_mission_ts  = !empty($date_mission_raw) ? $this->date_to_timestamp($date_mission_raw) : $m['date_mission'];
        if (!$date_mission_ts) { $date_mission_ts = $m['date_mission']; }
        $date_limite_ts   = !empty($date_limite_raw)  ? $this->date_to_timestamp($date_limite_raw)  : $date_mission_ts;
        if (!$date_limite_ts)  { $date_limite_ts  = $date_mission_ts; }

        // ── Gestion du changement de date de mission ──────────────────────────
        $old_ts  = (int)$m['date_mission'];
        $new_ts  = (int)$date_mission_ts;
        $delta   = abs($new_ts - $old_ts); // différence en secondes

        if ($new_ts !== $old_ts) {
            // Formater la nouvelle date pour les MPs
            $new_date_str = $this->user->format_date($new_ts);

            // Récupérer tous les inscrits
            $res_inscrits = $this->db->sql_query(
                'SELECT r.user_id, u.username FROM ' . $this->table_prefix . 'mission_roster r
                 JOIN ' . USERS_TABLE . ' u ON r.user_id = u.user_id
                 WHERE r.mission_id = ' . $mission_id
            );
            $inscrits = [];
            while ($row = $this->db->sql_fetchrow($res_inscrits)) {
                $inscrits[] = $row;
            }

            // Même jour ET écart <= 1h (3600 secondes) -> notification sans désinscription
            $old_day = date('Y-m-d', $old_ts);
            $new_day = date('Y-m-d', $new_ts);
            $same_day_minor = ($old_day === $new_day && $delta <= 3600);

            if ($same_day_minor) {
                // Notification uniquement — inscription maintenue
                foreach ($inscrits as $ins) {
                    $this->send_pm('date_minor_change', $ins['user_id'], $ins['username'],
                        $m['titre'], $mission_id, ['new_date' => $new_date_str]);
                }
            } else {
                // Changement significatif -> désinscription de tous + MP
                foreach ($inscrits as $ins) {
                    $this->send_pm('date_change', $ins['user_id'], $ins['username'],
                        $m['titre'], $mission_id, ['new_date' => $new_date_str]);
                }
                // Supprimer toutes les inscriptions
                $this->db->sql_query(
                    'DELETE FROM ' . $this->table_prefix . 'mission_roster WHERE mission_id = ' . $mission_id
                );
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        // Groupes admin toujours forcés + choix du créateur via checkboxes
        $admin_ids   = $this->get_admin_group_ids();
        $chosen      = array_map('intval', $this->request->variable('allowed', [0]));
        $all_allowed = array_unique(array_merge($admin_ids, $chosen));

        $this->db->sql_query(
            'UPDATE ' . $this->table_prefix . 'missions SET ' .
            $this->db->sql_build_array('UPDATE', [
                'sim_tag'        => '[' . (in_array($this->request->variable('sim_tag', '', true), self::ALLOWED_TAGS) ? $this->request->variable('sim_tag', '', true) : self::ALLOWED_TAGS[0]) . ']',
                'titre'          => $this->request->variable('titre', '', true),
                'description'    => $this->request->variable('description', '', true),
                'slots_config'   => json_encode($new_slots),
                'allowed_groups' => implode(',', $all_allowed),
                'date_mission'   => $date_mission_ts,
                'date_limite'    => $date_limite_ts,
                'allow_probables'=> $this->request->variable('allow_p', 0),
            ]) . ' WHERE id = ' . $mission_id
        );
        redirect($this->helper->route('pilot_mission_view', ['id' => $mission_id])); return;
    }

    // =========================================================================
    // GESTION MANUELLE ROSTER — Inscrire
    // =========================================================================
    public function admin_roster_add($id) {
        $mission_id    = (int)$id;
        $m = $this->db->sql_fetchrow($this->db->sql_query(
            'SELECT * FROM ' . $this->table_prefix . 'missions WHERE id = ' . $mission_id
        ));
        if (!$m || !$this->can_edit_mission($m)) { trigger_error('NOT_AUTHORISED'); }

        $target_id     = (int)$this->request->variable('user_id', 0);
        $external_name = trim(mb_substr($this->request->variable('external_name', '', true), 0, 100));
        $slot_name     = $this->request->variable('slot_name', '', true);
        $status        = $this->request->variable('status', 'Titulaire', true);

        // Doit avoir soit un user_id valide soit un nom externe
        if (!$target_id && empty($external_name)) { trigger_error('Utilisateur ou nom externe requis.'); }

        $slot_found = false;
        foreach (json_decode($m['slots_config'], true) as $slot) {
            if ($slot['name'] === $slot_name) { $slot_found = true; break; }
        }
        if (!$slot_found) { trigger_error('SLOT_NOT_FOUND'); }

        if ($target_id) {
            // Membre forum
            $res_u    = $this->db->sql_fetchrow($this->db->sql_query(
                'SELECT username FROM ' . USERS_TABLE . ' WHERE user_id = ' . $target_id
            ));
            $username = $res_u ? $res_u['username'] : '';
            $this->db->sql_query(
                'DELETE FROM ' . $this->table_prefix . 'mission_roster
                 WHERE mission_id = ' . $mission_id . ' AND user_id = ' . $target_id
            );
            $insert_data = [
                'mission_id' => $mission_id,
                'user_id'    => $target_id,
                'slot_name'  => $slot_name,
                'status'     => $status,
            ];
            if ($this->has_external_name_column()) { $insert_data['external_name'] = ''; }
            $this->db->sql_query(
                'INSERT INTO ' . $this->table_prefix . 'mission_roster ' .
                $this->db->sql_build_array('INSERT', $insert_data)
            );
            if ($username) {
                $this->send_pm('admin_add', $target_id, $username, $m['titre'], $mission_id, [
                    'slot' => $slot_name, 'status' => $status,
                ]);
            }
        } else {
            // Non-membre(s) externe(s) — séparés par ";" — pas de MP
            $names = array_filter(array_map(function($n){ return trim(strip_tags($n)); }, explode(';', $external_name)));
            foreach ($names as $ext_single) {
                if (empty($ext_single)) { continue; }
                $ext_single = mb_substr($ext_single, 0, 100);
                // Éviter les doublons
                $exists = $this->db->sql_fetchrow($this->db->sql_query(
                    "SELECT id FROM " . $this->table_prefix . "mission_roster
                     WHERE mission_id = " . $mission_id . "
                     AND user_id = 0
                     AND external_name = '" . $this->db->sql_escape($ext_single) . "'"
                ));
                if (!$exists) {
                    if ($this->has_external_name_column()) {
                        $this->db->sql_query(
                            'INSERT INTO ' . $this->table_prefix . 'mission_roster ' .
                            $this->db->sql_build_array('INSERT', [
                                'mission_id'    => $mission_id,
                                'user_id'       => 0,
                                'slot_name'     => $slot_name,
                                'status'        => $status,
                                'external_name' => $ext_single,
                            ])
                        );
                    }
                }
            }
        }
        redirect($this->helper->route('pilot_mission_view', ['id' => $mission_id])); return;
    }

    // =========================================================================
    // GESTION MANUELLE ROSTER — Modifier
    // =========================================================================
    public function admin_roster_edit($id) {
        $mission_id    = (int)$id;
        $m = $this->db->sql_fetchrow($this->db->sql_query(
            'SELECT * FROM ' . $this->table_prefix . 'missions WHERE id = ' . $mission_id
        ));
        if (!$m || !$this->can_edit_mission($m)) { trigger_error('NOT_AUTHORISED'); }

        $target_id     = (int)$this->request->variable('user_id', 0);
        $external_name = trim(mb_substr($this->request->variable('external_name', '', true), 0, 100));
        $new_slot      = $this->request->variable('slot_name', '', true);
        $new_status    = $this->request->variable('status', 'Titulaire', true);

        if ($target_id) {
            // Membre forum
            $res_u    = $this->db->sql_fetchrow($this->db->sql_query(
                'SELECT username FROM ' . USERS_TABLE . ' WHERE user_id = ' . $target_id
            ));
            $username = $res_u ? $res_u['username'] : '';
            $this->db->sql_query(
                "UPDATE " . $this->table_prefix . "mission_roster
                 SET slot_name = '" . $this->db->sql_escape($new_slot) . "',
                     status = '" . $this->db->sql_escape($new_status) . "'
                 WHERE mission_id = " . $mission_id . " AND user_id = " . $target_id
            );
            if ($username) {
                $this->send_pm('admin_modif', $target_id, $username, $m['titre'], $mission_id, [
                    'new_slot' => $new_slot, 'new_status' => $new_status,
                ]);
            }
        } elseif (!empty($external_name)) {
            // Non-membre externe — pas de MP
            // On identifie l'externe par son ancien nom passé en data-external-name
            $old_external = trim($this->request->variable('old_external_name', '', true));
            $this->db->sql_query(
                "UPDATE " . $this->table_prefix . "mission_roster
                 SET slot_name = '" . $this->db->sql_escape($new_slot) . "',
                     status = '" . $this->db->sql_escape($new_status) . "',
                     external_name = '" . $this->db->sql_escape(strip_tags($external_name)) . "'
                 WHERE mission_id = " . $mission_id . "
                 AND user_id = 0
                 AND external_name = '" . $this->db->sql_escape($old_external) . "'"
            );
        }
        redirect($this->helper->route('pilot_mission_view', ['id' => $mission_id])); return;
    }

    // =========================================================================
    // GESTION MANUELLE ROSTER — Désinscrire
    // =========================================================================
    public function admin_roster_remove($id) {
        $mission_id = (int)$id;
        $m = $this->db->sql_fetchrow($this->db->sql_query(
            'SELECT * FROM ' . $this->table_prefix . 'missions WHERE id = ' . $mission_id
        ));
        if (!$m || !$this->can_edit_mission($m)) { trigger_error('NOT_AUTHORISED'); }

        $target_id = (int)$this->request->variable('user_id', 0);
        if (!$target_id) { trigger_error('Utilisateur invalide.'); }

        $ins = $this->db->sql_fetchrow($this->db->sql_query(
            'SELECT r.slot_name, r.status, u.username FROM ' . $this->table_prefix . 'mission_roster r
             JOIN ' . USERS_TABLE . ' u ON r.user_id = u.user_id
             WHERE r.mission_id = ' . $mission_id . ' AND r.user_id = ' . $target_id
        ));
        if (!$ins) {
            redirect($this->helper->route('pilot_mission_view', ['id' => $mission_id])); return;
        }

        if ($target_id > 0) {
            // Membre forum
            $this->db->sql_query(
                'DELETE FROM ' . $this->table_prefix . 'mission_roster
                 WHERE mission_id = ' . $mission_id . ' AND user_id = ' . $target_id
            );
        } else {
            // Externe — supprimer par external_name
            $ext_name = $ins['external_name'] ?? '';
            $this->db->sql_query(
                "DELETE FROM " . $this->table_prefix . "mission_roster
                 WHERE mission_id = " . $mission_id . "
                 AND user_id = 0
                 AND external_name = '" . $this->db->sql_escape($ext_name) . "'"
            );
        }
        $this->send_pm('admin_remove', $target_id, $ins['username'], $m['titre'], $mission_id);

        if ($ins['status'] === 'Titulaire') {
            $next = $this->db->sql_fetchrow($this->db->sql_query(
                'SELECT r.user_id, u.username FROM ' . $this->table_prefix . 'mission_roster r
                 JOIN ' . USERS_TABLE . " u ON r.user_id = u.user_id
                 WHERE r.mission_id = " . $mission_id . "
                 AND r.slot_name = '" . $this->db->sql_escape($ins['slot_name']) . "'
                 AND r.status = 'Reserviste'
                 ORDER BY r.id ASC"
            ));
            if ($next) {
                $this->db->sql_query(
                    "UPDATE " . $this->table_prefix . "mission_roster SET status = 'Titulaire'
                     WHERE user_id = " . (int)$next['user_id'] . " AND mission_id = " . $mission_id
                );
                $this->send_pm('promotion', $next['user_id'], $next['username'], $m['titre'], $mission_id);
            }
        }
        redirect($this->helper->route('pilot_mission_view', ['id' => $mission_id])); return;
    }

    // =========================================================================
    // MODIFICATION PAR LE PILOTE — Formulaire
    // =========================================================================
    public function modify_view($id) {
        $mission_id = (int)$id;
        $user_id    = (int)$this->user->data['user_id'];
        $m = $this->db->sql_fetchrow($this->db->sql_query(
            'SELECT * FROM ' . $this->table_prefix . 'missions WHERE id = ' . $mission_id
        ));
        if (!$m) { trigger_error('MISSION_NOT_FOUND'); }
        if (time() >= $m['date_mission']) { trigger_error('La mission est passée, modification impossible.'); }

        $ins = $this->db->sql_fetchrow($this->db->sql_query(
            'SELECT * FROM ' . $this->table_prefix . 'mission_roster
             WHERE mission_id = ' . $mission_id . ' AND user_id = ' . $user_id
        ));
        if (!$ins) { trigger_error('Vous n\'êtes pas inscrit à cette mission.'); }

        add_form_key('pilot_roster_modify_' . $mission_id);
        $can_change_group = time() < $m['date_limite'];
        $slots            = json_decode($m['slots_config'], true);
        $slots_status     = [];
        foreach ($slots as $s) {
            $nb_t = $this->count_titulaires($mission_id, $s['name']);
            $this->template->assign_block_vars('slots', [
                'NAME'     => htmlspecialchars($s['name'], ENT_NOQUOTES, 'UTF-8'),
                'MAX'      => (int)$s['count'],
                'COUNT_T'  => $nb_t,
                'S_FULL'   => $nb_t >= $s['count'],
                'SELECTED' => $s['name'] === $ins['slot_name'] ? 'selected="selected"' : '',
            ]);
            $slots_status[] = [
                'name'       => $s['name'],
                'max'        => (int)$s['count'],
                'titulaires' => $nb_t,
                'full'       => $nb_t >= $s['count'],
            ];
        }
        $this->template->assign_vars([
            'MISSION_TITLE'     => html_entity_decode($m['titre'], ENT_QUOTES, 'UTF-8'),
            'MISSION_ID'        => $mission_id,
            'CURRENT_SLOT'      => htmlspecialchars($ins['slot_name']),
            'CURRENT_STATUS'    => htmlspecialchars($ins['status']),
            'ALLOW_P'           => (bool)$m['allow_probables'],
            'CAN_CHANGE_GROUP'  => $can_change_group,
            'DATE_LIMITE_STR'   => $this->user->format_date($m['date_limite']),
            'SLOTS_STATUS_JSON' => addslashes(json_encode($slots_status)),
            'U_MODIFY_SUBMIT'   => $this->helper->route('pilot_mission_modify_submit', ['id' => $mission_id]),
            'U_LEAVE'           => $this->helper->route('pilot_mission_leave',         ['id' => $mission_id]),
            'U_BACK'            => $this->helper->route('pilot_mission_view',          ['id' => $mission_id]),
        ]);
        return $this->helper->render('mission_modify.html', 'Modifier mon inscription');
    }

    // =========================================================================
    // MODIFICATION PAR LE PILOTE — Traitement
    // =========================================================================
    public function modify_submit($id) {
        $mission_id = (int)$id;
        $user_id    = (int)$this->user->data['user_id'];
        $m = $this->db->sql_fetchrow($this->db->sql_query(
            'SELECT * FROM ' . $this->table_prefix . 'missions WHERE id = ' . $mission_id
        ));
        if (!$m) { trigger_error('MISSION_NOT_FOUND'); }
        if (time() >= $m['date_mission']) { trigger_error('La mission est passée, modification impossible.'); }
        if (!check_form_key('pilot_roster_modify_' . $mission_id)) { trigger_error('FORM_INVALID'); }

        $old = $this->db->sql_fetchrow($this->db->sql_query(
            'SELECT * FROM ' . $this->table_prefix . 'mission_roster
             WHERE mission_id = ' . $mission_id . ' AND user_id = ' . $user_id
        ));
        if (!$old) { trigger_error('Vous n\'êtes pas inscrit à cette mission.'); }

        $new_slot   = $this->request->variable('slot_name', '', true);
        $new_status = $this->request->variable('status', 'Titulaire', true);

        if ($new_slot !== $old['slot_name'] && time() >= $m['date_limite']) {
            trigger_error('Date limite dépassée, changement de groupe impossible.');
        }
        if ($new_status === 'Probable' && !$m['allow_probables']) {
            trigger_error('Les Probables ne sont pas autorisés pour cette mission.');
        }

        $slot_found = false; $new_max = 0;
        foreach (json_decode($m['slots_config'], true) as $slot) {
            if ($slot['name'] === $new_slot) { $slot_found = true; $new_max = (int)$slot['count']; break; }
        }
        if (!$slot_found) { trigger_error('SLOT_NOT_FOUND'); }

        $nb_t_eff = $this->count_titulaires($mission_id, $new_slot);
        if ($new_slot === $old['slot_name'] && $old['status'] === 'Titulaire') { $nb_t_eff--; }

        if ($new_status === 'Titulaire' && $nb_t_eff >= $new_max) {
            $this->template->assign_vars([
                'SLOT_NAME'      => htmlspecialchars($new_slot, ENT_NOQUOTES, 'UTF-8'),
                'MAX_PLACES'     => $new_max,
                'TITULAIRES'     => $this->count_titulaires($mission_id, $new_slot),
                'MISSION_ID'     => $mission_id,
                'U_JOIN'         => $this->helper->route('pilot_mission_modify_submit', ['id' => $mission_id]),
                'U_MISSION_VIEW' => $this->helper->route('pilot_mission_view',          ['id' => $mission_id]),
            ]);
            return $this->helper->render('confirm_reserviste.html', 'Confirmer Réserviste');
        }

        $this->db->sql_query(
            "UPDATE " . $this->table_prefix . "mission_roster
             SET slot_name = '" . $this->db->sql_escape($new_slot) . "',
                 status = '" . $this->db->sql_escape($new_status) . "'
             WHERE mission_id = " . $mission_id . " AND user_id = " . $user_id
        );

        if ($new_slot !== $old['slot_name'] && $old['status'] === 'Titulaire') {
            $next = $this->db->sql_fetchrow($this->db->sql_query(
                'SELECT r.user_id, u.username FROM ' . $this->table_prefix . 'mission_roster r
                 JOIN ' . USERS_TABLE . " u ON r.user_id = u.user_id
                 WHERE r.mission_id = " . $mission_id . "
                 AND r.slot_name = '" . $this->db->sql_escape($old['slot_name']) . "'
                 AND r.status = 'Reserviste'
                 ORDER BY r.id ASC"
            ));
            if ($next) {
                $this->db->sql_query(
                    "UPDATE " . $this->table_prefix . "mission_roster SET status = 'Titulaire'
                     WHERE user_id = " . (int)$next['user_id'] . "
                     AND mission_id = " . $mission_id . "
                     AND slot_name = '" . $this->db->sql_escape($old['slot_name']) . "'"
                );
                $this->send_pm('promotion', $next['user_id'], $next['username'], $m['titre'], $mission_id);
            }
        }
        // Pas de MP si c'est l'utilisateur lui-même qui modifie sa propre inscription
        redirect($this->helper->route('pilot_mission_view', ['id' => $mission_id])); return;
    }

    // =========================================================================
    // INSCRIPTION INITIALE
    // =========================================================================
    public function join_mission($id) {
        $mission_id = (int)$id;
        $slot_name  = $this->request->variable('slot_name', '', true);
        $status     = $this->request->variable('status', 'Titulaire', true);
        $mission    = $this->db->sql_fetchrow($this->db->sql_query(
            'SELECT slots_config, allow_probables, date_mission, date_limite FROM ' . $this->table_prefix . 'missions WHERE id = ' . $mission_id
        ));
        if (!$mission) { trigger_error('MISSION_NOT_FOUND'); }

        // Vérification côté serveur : inscription impossible si mission commencée ou date limite dépassée
        if (time() >= $mission['date_mission']) { trigger_error('La mission a déjà commencé, inscription impossible.'); }
        if (time() >= $mission['date_limite'])   { trigger_error('La date limite d\'inscription est dépassée.'); }

        if ($status === 'Probable' && !$mission['allow_probables']) { trigger_error('Probables non autorisés.'); }

        $slot_found = false; $max_places = 0;
        foreach (json_decode($mission['slots_config'], true) as $slot) {
            if ($slot['name'] === $slot_name) { $slot_found = true; $max_places = $slot['count']; break; }
        }
        if (!$slot_found) { trigger_error('SLOT_NOT_FOUND'); }

        $titulaires = $this->count_titulaires($mission_id, $slot_name);
        if ($titulaires >= $max_places && $status === 'Titulaire') {
            $this->template->assign_vars([
                'SLOT_NAME'      => htmlspecialchars($slot_name, ENT_NOQUOTES, 'UTF-8'),
                'MAX_PLACES'     => $max_places,
                'TITULAIRES'     => $titulaires,
                'MISSION_ID'     => $mission_id,
                'U_JOIN'         => $this->helper->route('pilot_mission_join', ['id' => $mission_id]),
                'U_MISSION_VIEW' => $this->helper->route('pilot_mission_view', ['id' => $mission_id]),
            ]);
            return $this->helper->render('confirm_reserviste.html', 'Confirmer Réserviste');
        }

        $this->leave_mission($mission_id, true);
        $this->db->sql_query(
            'INSERT INTO ' . $this->table_prefix . 'mission_roster ' .
            $this->db->sql_build_array('INSERT', [
                'mission_id' => $mission_id,
                'user_id'    => (int)$this->user->data['user_id'],
                'slot_name'  => $slot_name,
                'status'     => $status,
            ])
        );
        redirect($this->helper->route('pilot_mission_view', ['id' => $mission_id])); return;
    }

    // =========================================================================
    // DÉSINSCRIPTION + PROMOTION AUTO
    // =========================================================================
    public function leave_mission($id, $is_internal = false) {
        $mission_id = (int)$id;
        $user_id    = (int)$this->user->data['user_id'];
        $data = $this->db->sql_fetchrow($this->db->sql_query(
            'SELECT r.slot_name, r.status, m.titre, m.date_mission
             FROM ' . $this->table_prefix . 'mission_roster r
             JOIN ' . $this->table_prefix . 'missions m ON r.mission_id = m.id
             WHERE r.mission_id = ' . $mission_id . ' AND r.user_id = ' . $user_id
        ));
        if (!$data) {
            if ($is_internal) { return false; }
            redirect($this->helper->route('pilot_mission_view', ['id' => $mission_id]));
            return;
        }
        if (!$is_internal && time() >= $data['date_mission']) {
            trigger_error('La mission est passée, désinscription impossible.');
        }
        $this->db->sql_query(
            'DELETE FROM ' . $this->table_prefix . 'mission_roster
             WHERE mission_id = ' . $mission_id . ' AND user_id = ' . $user_id
        );
        if ($data['status'] === 'Titulaire') {
            $next = $this->db->sql_fetchrow($this->db->sql_query(
                'SELECT r.user_id, u.username FROM ' . $this->table_prefix . 'mission_roster r
                 JOIN ' . USERS_TABLE . " u ON r.user_id = u.user_id
                 WHERE r.mission_id = " . $mission_id . "
                 AND r.slot_name = '" . $this->db->sql_escape($data['slot_name']) . "'
                 AND r.status = 'Reserviste'
                 ORDER BY r.id ASC"
            ));
            if ($next) {
                $this->db->sql_query(
                    "UPDATE " . $this->table_prefix . "mission_roster SET status = 'Titulaire'
                     WHERE user_id = " . (int)$next['user_id'] . " AND mission_id = " . $mission_id
                );
                $this->send_pm('promotion', $next['user_id'], $next['username'], $data['titre'], $mission_id);
            }
        }
        if ($is_internal) { return true; }
        redirect($this->helper->route('pilot_mission_view', ['id' => $mission_id]));
    }

    // =========================================================================
    // ANNULATION DE MISSION (créateur / admin)
    // =========================================================================
    public function cancel_mission($id) {
        $mission_id = (int)$id;
        $m = $this->db->sql_fetchrow($this->db->sql_query(
            'SELECT * FROM ' . $this->table_prefix . 'missions WHERE id = ' . $mission_id
        ));
        if (!$m || !$this->can_edit_mission($m)) { trigger_error('NOT_AUTHORISED'); }
        if (!check_form_key('pilot_roster_cancel_' . $mission_id)) { trigger_error('FORM_INVALID'); }

        // Notifier tous les inscrits par MP
        $res = $this->db->sql_query(
            'SELECT r.user_id, u.username FROM ' . $this->table_prefix . 'mission_roster r
             JOIN ' . USERS_TABLE . ' u ON r.user_id = u.user_id
             WHERE r.mission_id = ' . $mission_id
        );
        while ($row = $this->db->sql_fetchrow($res)) {
            $this->send_pm('annulation', $row['user_id'], $row['username'], $m['titre'], $mission_id);
        }

        $this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'mission_roster WHERE mission_id = ' . $mission_id);
        $this->db->sql_query('DELETE FROM ' . $this->table_prefix . 'missions WHERE id = ' . $mission_id);

        redirect($this->helper->route('pilot_mission_list'));
    }

    // =========================================================================
    // IMAGE CAPTURE
    // =========================================================================
    public function generate_image($id) {
        $mission_id = (int)$id;
        $m = $this->db->sql_fetchrow($this->db->sql_query(
            'SELECT * FROM ' . $this->table_prefix . 'missions WHERE id = ' . $mission_id
        ));
        if (!$m) { exit; }
        $slots  = json_decode($m['slots_config'], true);
        $img    = imagecreatetruecolor(500, 100 + count($slots) * 25);
        $bg     = imagecolorallocate($img, 44, 47, 51);
        $white  = imagecolorallocate($img, 255, 255, 255);
        $yellow = imagecolorallocate($img, 255, 204, 0);
        imagefill($img, 0, 0, $bg);
        imagestring($img, 5, 20, 20, $m['sim_tag'] . ' ' . strtoupper($m['titre']), $yellow);
        $y = 65;
        foreach ($slots as $s) {
            imagestring($img, 3, 20, $y, '> ' . $s['name'] . ' : ' . $s['count'] . ' places', $white);
            $y += 25;
        }
        header('Content-Type: image/png');
        imagepng($img);
        imagedestroy($img);
        exit;
    }
}
