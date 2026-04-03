<?php
namespace pilot\missionroster\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
    protected $helper;
    protected $db;
    protected $user;
    protected $template;
    protected $auth;
    protected $table_prefix;

    public function __construct($helper, $db, $user, $template, $auth, $table_prefix)
    {
        $this->helper       = $helper;
        $this->db           = $db;
        $this->user         = $user;
        $this->template     = $template;
        $this->auth         = $auth;
        $this->table_prefix = $table_prefix;
    }

    public static function getSubscribedEvents()
    {
        return [
            'core.permissions'                     => 'add_permissions',
            'core.page_header'                     => 'add_next_mission_and_editor_button',
            'core.text_formatter_s9e_render_after' => 'on_s9e_render_after',
        ];
    }

    // =========================================================================
    // EV-02 — Rendu HTML serveur de [roster=ID]
    // =========================================================================

    public function on_s9e_render_after($event)
    {
        try {
            $text = isset($event['text']) ? $event['text'] : '';
            if ($text === '') {
                return;
            }

            // s9e peut encoder les crochets en entités HTML (&#91; &#93;)
            // ou les laisser bruts selon la configuration du forum.
            // On détecte les deux formes avant de lancer le preg_replace.
            $has_raw     = strpos($text, '[roster=')    !== false;
            $has_encoded = strpos($text, '&#91;roster=') !== false;
            if (!$has_raw && !$has_encoded) {
                return;
            }

            // Pattern robuste : accepte [ ou &#91; en ouverture, ] ou &#93; en fermeture
            $pattern  = '#(?:\[|&#91;)roster=(\d+)(?:\]|&#93;)#i';
            $new_text = preg_replace_callback($pattern, function ($matches) {
                $id = (int) $matches[1];
                return $id > 0 ? $this->render_roster_block($id) : $this->render_error_placeholder();
            }, $text);
            $event['text'] = $new_text;
        } catch (\Exception $e) {
            return;
        }
    }

    protected function render_roster_block($mission_id)
    {
        // ── Mission ──────────────────────────────────────────────────────────
        $result = $this->db->sql_query(
            'SELECT m.*, u.username AS creator_name
             FROM ' . $this->table_prefix . 'missions m
             LEFT JOIN ' . USERS_TABLE . ' u ON m.creator_id = u.user_id
             WHERE m.id = ' . $mission_id
        );
        $m = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        if (!$m) { return $this->render_error_placeholder(); }

        // ── Roster ───────────────────────────────────────────────────────────
        $has_ext = $this->has_external_name_column();
        $result_r = $this->db->sql_query(
            'SELECT r.slot_name, r.status, r.user_id'
            . ($has_ext ? ', r.external_name' : '')
            . ', u.username
               FROM ' . $this->table_prefix . 'mission_roster r
               LEFT JOIN ' . USERS_TABLE . ' u ON r.user_id = u.user_id AND r.user_id > 0
               WHERE r.mission_id = ' . $mission_id . ' ORDER BY r.id ASC'
        );
        $roster = [];
        $stats  = ['Titulaire' => 0, 'Reserviste' => 0, 'Probable' => 0];
        while ($row = $this->db->sql_fetchrow($result_r)) {
            $ext_name = $has_ext ? ($row['external_name'] ?? '') : '';
            $display  = !empty($ext_name)
                ? '<span class="rmb-ext">[EXT] ' . htmlspecialchars($ext_name, ENT_NOQUOTES, 'UTF-8') . '</span>'
                : htmlspecialchars($row['username'] ?? '?', ENT_NOQUOTES, 'UTF-8');
            $roster[$row['slot_name']][$row['status']][] = $display;
            if (isset($stats[$row['status']])) { $stats[$row['status']]++; }
        }
        $this->db->sql_freeresult($result_r);

        // ── Inscription utilisateur courant ──────────────────────────────────
        $uid     = (int) $this->user->data['user_id'];
        $is_anon = ($uid === ANONYMOUS);
        $row_ui  = null;
        if (!$is_anon) {
            $res_ui = $this->db->sql_query(
                'SELECT slot_name, status FROM ' . $this->table_prefix . 'mission_roster
                 WHERE mission_id = ' . $mission_id . ' AND user_id = ' . $uid . ' LIMIT 1'
            );
            $row_ui = $this->db->sql_fetchrow($res_ui);
            $this->db->sql_freeresult($res_ui);
        }

        // ── Slots HTML ───────────────────────────────────────────────────────
        $slots_config    = json_decode($m['slots_config'], true) ?: [];
        $allow_probables = (bool) $m['allow_probables'];
        $total_t = 0; $total_max = 0;
        $fmt = function($list) {
            return empty($list) ? '<em style="color:#94a3b8;">—</em>' : implode(', ', $list);
        };

        $slots_html = '';
        foreach ($slots_config as $s) {
            $sname   = htmlspecialchars($s['name'], ENT_NOQUOTES, 'UTF-8');
            $smax    = (int) $s['count'];
            $list_t  = $roster[$s['name']]['Titulaire']  ?? [];
            $list_r  = $roster[$s['name']]['Reserviste'] ?? [];
            $list_p  = $roster[$s['name']]['Probable']   ?? [];
            $nb_t    = count($list_t);
            $is_full = $nb_t >= $smax;
            $pct     = $smax > 0 ? round($nb_t / $smax * 100) : 0;
            $total_t   += $nb_t;
            $total_max += $smax;

            $slots_html .= '<div class="rmb-slot">'
                . '<div class="rmb-slot-hd">'
                .   '<span class="rmb-slot-name">' . $sname . '</span>'
                .   '<span class="rmb-slot-ratio">' . $nb_t . '/' . $smax
                .     ($is_full ? ' <span class="rmb-badge-full">COMPLET</span>' : '')
                .   '</span>'
                . '</div>'
                . '<div class="rmb-progress"><div class="rmb-bar' . ($is_full ? ' rmb-bar-full' : '') . '" style="width:' . $pct . '%"></div></div>'
                . '<div class="rmb-line">🟢 <strong>Titulaires :</strong> ' . $fmt($list_t) . '</div>'
                . '<div class="rmb-line">🟣 <strong>Réservistes :</strong> ' . $fmt($list_r) . '</div>'
                . ($allow_probables ? '<div class="rmb-line">🟡 <strong>Probables :</strong> ' . $fmt($list_p) . '</div>' : '')
                . '</div>';
        }

        // ── Meta ─────────────────────────────────────────────────────────────
        $titre   = htmlspecialchars(html_entity_decode($m['titre'], ENT_QUOTES, 'UTF-8'), ENT_NOQUOTES, 'UTF-8');
        $sim_tag = htmlspecialchars($m['sim_tag'], ENT_NOQUOTES, 'UTF-8');
        $creator = htmlspecialchars($m['creator_name'] ?? '—', ENT_NOQUOTES, 'UTF-8');
        $date_m  = $this->user->format_date($m['date_mission']);
        $date_l  = $this->user->format_date($m['date_limite']);
        $desc    = !empty($m['description'])
            ? '<p class="rmb-desc">' . htmlspecialchars(html_entity_decode($m['description'], ENT_QUOTES, 'UTF-8'), ENT_NOQUOTES, 'UTF-8') . '</p>'
            : '';
        $url_view = $this->helper->route('pilot_mission_view', ['id' => $mission_id]);
        $url_list = $this->helper->route('pilot_mission_list');

        // Statut
        $now = time();
        if ($now >= (int) $m['date_mission']) {
            $status_badge = '<span class="rmb-status rmb-s-past">✈ Mission passée</span>';
        } elseif ($now >= (int) $m['date_limite']) {
            $status_badge = '<span class="rmb-status rmb-s-closed">🔒 Inscriptions fermées</span>';
        } else {
            $status_badge = '<span class="rmb-status rmb-s-open">✅ Inscriptions ouvertes</span>';
        }

        // Bloc "mon inscription"
        $my_ins_html = '';
        if ($row_ui) {
            $sc = ['Titulaire' => 'rmb-badge-t', 'Reserviste' => 'rmb-badge-r', 'Probable' => 'rmb-badge-p'][$row_ui['status']] ?? 'rmb-badge-t';
            $my_ins_html = '<div class="rmb-my-ins">'
                . '✅ Vous êtes inscrit — groupe : <strong>' . htmlspecialchars($row_ui['slot_name'], ENT_NOQUOTES, 'UTF-8') . '</strong>'
                . ' — statut : <span class="rmb-badge ' . $sc . '">' . htmlspecialchars($row_ui['status'], ENT_NOQUOTES, 'UTF-8') . '</span>'
                . '</div>';
        }

        // CTA
        if ($now >= (int) $m['date_mission']) {
            $cta = '<a href="' . $url_view . '" class="rmb-cta rmb-cta-sec">📋 Voir le roster final</a>';
        } elseif (!$is_anon && $row_ui) {
            $cta = '<a href="' . $url_view . '" class="rmb-cta rmb-cta-warn">✎ Modifier / annuler mon inscription</a>';
        } elseif (!$is_anon && !$row_ui && $now < (int) $m['date_limite']) {
            $cta = '<a href="' . $url_view . '" class="rmb-cta rmb-cta-pri">✈ S\'inscrire à cette mission</a>';
        } else {
            $cta = '<a href="' . $url_view . '" class="rmb-cta rmb-cta-sec">📋 Voir la mission complète</a>';
        }

        // ── Assemblage ───────────────────────────────────────────────────────
        $html  = $this->get_embed_css();
        $html .= '<div class="rmb-wrap">';

        // En-tête sombre
        $html .= '<div class="rmb-header">'
            . '<div class="rmb-hd-top">'
            .   '<div style="display:flex;align-items:center;gap:8px;">'
            .     '<span class="rmb-tag">' . $sim_tag . '</span>'
            .     '<h3 class="rmb-title">' . $titre . '</h3>'
            .   '</div>'
            .   $status_badge
            . '</div>'
            . '<div class="rmb-meta">👤 <strong>' . $creator . '</strong> &nbsp;|&nbsp; 📅 ' . $date_m . ' &nbsp;|&nbsp; ⏰ Inscriptions jusqu\'au ' . $date_l . '</div>'
            . $desc
            . '<div class="rmb-stats">'
            .   '<div class="rmb-stat"><span class="rmb-sv" style="color:#4ade80;">' . $stats['Titulaire'] . '</span><span class="rmb-sl">Titulaires</span></div>'
            .   '<div class="rmb-stat"><span class="rmb-sv" style="color:#c4b5fd;">' . $stats['Reserviste'] . '</span><span class="rmb-sl">Réservistes</span></div>'
            .   ($allow_probables ? '<div class="rmb-stat"><span class="rmb-sv" style="color:#fcd34d;">' . $stats['Probable'] . '</span><span class="rmb-sl">Probables</span></div>' : '')
            .   '<div class="rmb-stat"><span class="rmb-sv" style="color:#7dd3fc;">' . $total_t . '/' . $total_max . '</span><span class="rmb-sl">Slots</span></div>'
            . '</div>'
            . '</div>'; // /rmb-header

        // Grille slots
        $html .= '<div class="rmb-slots">' . $slots_html . '</div>';

        // Mon inscription
        $html .= $my_ins_html;

        // Pied de page
        $html .= '<div class="rmb-footer">' . $cta . '<a href="' . $url_list . '" class="rmb-cta rmb-cta-ghost">← Toutes les missions</a></div>';

        $html .= '</div>'; // /rmb-wrap
        return $html;
    }

    protected function get_embed_css()
    {
        static $injected = false;
        if ($injected) { return ''; }
        $injected = true;
        return '<style>
.rmb-wrap{border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin:16px 0;font-family:system-ui,sans-serif;font-size:.875rem;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.08);}
.rmb-header{padding:16px 20px;background:linear-gradient(135deg,#1e293b 0%,#334155 100%);color:#f8fafc;}
.rmb-hd-top{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;margin-bottom:6px;}
.rmb-title{margin:0;font-size:1.05rem;font-weight:700;color:#f8fafc;}
.rmb-tag{display:inline-block;padding:2px 8px;border-radius:4px;font-size:.75rem;font-weight:700;background:#fbbf24;color:#1c1917;white-space:nowrap;}
.rmb-meta{color:#94a3b8;font-size:.8rem;margin-bottom:4px;}
.rmb-desc{color:#cbd5e1;font-size:.82rem;margin:4px 0 0;}
.rmb-status{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.74rem;font-weight:600;white-space:nowrap;}
.rmb-s-open{background:#dcfce7;color:#166534;}
.rmb-s-closed{background:#fef3c7;color:#92400e;}
.rmb-s-past{background:#f1f5f9;color:#64748b;}
.rmb-stats{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;}
.rmb-stat{display:flex;flex-direction:column;align-items:center;background:rgba(255,255,255,.08);border-radius:6px;padding:6px 14px;min-width:58px;}
.rmb-sv{font-size:1.2rem;font-weight:700;}
.rmb-sl{font-size:.63rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;}
.rmb-slots{padding:12px 16px;display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:10px;}
.rmb-slot{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;}
.rmb-slot-hd{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;}
.rmb-slot-name{font-weight:700;color:#1e293b;}
.rmb-slot-ratio{color:#64748b;font-size:.8rem;}
.rmb-progress{height:5px;background:#e2e8f0;border-radius:3px;margin-bottom:8px;overflow:hidden;}
.rmb-bar{height:100%;border-radius:3px;background:#22c55e;}
.rmb-bar-full{background:#ef4444;}
.rmb-line{color:#475569;font-size:.82rem;margin:3px 0;line-height:1.4;}
.rmb-line strong{color:#1e293b;}
.rmb-ext{color:#d97706;font-weight:500;}
.rmb-badge{display:inline-block;padding:1px 7px;border-radius:20px;font-size:.72rem;font-weight:600;}
.rmb-badge-t{background:#dcfce7;color:#166534;}
.rmb-badge-r{background:#ede9fe;color:#5b21b6;}
.rmb-badge-p{background:#fef9c3;color:#854d0e;}
.rmb-badge-full{background:#fee2e2;color:#991b1b;font-size:.7rem;padding:1px 6px;border-radius:4px;margin-left:4px;}
.rmb-my-ins{margin:0 16px 12px;padding:10px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:7px;color:#166534;font-size:.84rem;}
.rmb-footer{display:flex;gap:8px;align-items:center;padding:12px 16px;border-top:1px solid #f1f5f9;flex-wrap:wrap;}
.rmb-cta{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:6px;font-size:.82rem;font-weight:600;text-decoration:none;transition:opacity .15s;}
.rmb-cta:hover{opacity:.85;}
.rmb-cta-pri{background:#3b82f6;color:#fff !important;}
.rmb-cta-sec{background:#e2e8f0;color:#1e293b !important;}
.rmb-cta-warn{background:#f59e0b;color:#fff !important;}
.rmb-cta-ghost{color:#64748b !important;font-size:.8rem;padding:7px 10px;}
</style>';
    }

    protected function has_external_name_column()
    {
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

    protected function render_error_placeholder()
    {
        return '<div style="'
            . 'display:flex;align-items:center;gap:10px;'
            . 'border:1px solid #fca5a5;background:#fef2f2;'
            . 'border-radius:8px;padding:12px 16px;margin:8px 0;'
            . 'font-family:system-ui,sans-serif;font-size:.875rem;'
            . '">'
            . '<span style="font-size:1.4rem;line-height:1;flex-shrink:0;">⚠️</span>'
            . '<div>'
            .   '<strong style="color:#991b1b;display:block;margin-bottom:2px;">Mission introuvable</strong>'
            .   '<span style="color:#b91c1c;font-size:.82rem;">Cette mission a été supprimée ou l\'identifiant est invalide.</span>'
            . '</div>'
            . '</div>';
    }

    // =========================================================================
    // Permissions
    // =========================================================================

    public function add_permissions($event)
    {
        $permissions = $event['permissions'];
        $permissions['u_mission_create'] = ['lang' => 'ACL_U_MISSION_CREATE', 'cat' => 'misc'];
        $permissions['u_mission_edit']   = ['lang' => 'ACL_U_MISSION_EDIT',   'cat' => 'misc'];
        $event['permissions'] = $permissions;
    }

    // =========================================================================
    // Navbar prochaine mission + bouton BBCode éditeur (page_header)
    // =========================================================================

    public function add_next_mission_and_editor_button($event)
    {
        // ── Prochaine mission dans la navbar ─────────────────────────────────
        $now = time();
        $row = $this->db->sql_fetchrow($this->db->sql_query(
            'SELECT id, titre, sim_tag, date_mission
             FROM ' . $this->table_prefix . 'missions
             WHERE date_mission >= ' . (int) $now . '
             ORDER BY date_mission ASC LIMIT 1'
        ));
        if ($row) {
            $this->template->assign_vars([
                'NEXT_MISSION_TITRE' => htmlspecialchars(html_entity_decode($row['titre'], ENT_QUOTES, 'UTF-8'), ENT_NOQUOTES, 'UTF-8'),
                'NEXT_MISSION_TAG'   => htmlspecialchars(trim($row['sim_tag'], '[]'), ENT_NOQUOTES, 'UTF-8'),
                'NEXT_MISSION_DATE'  => $this->user->format_date($row['date_mission']),
                'NEXT_MISSION_URL'   => $this->helper->route('pilot_mission_view', ['id' => (int) $row['id']]),
            ]);
        }

        // ── Bouton BBCode dans l'éditeur ─────────────────────────────────────
        // Détection de la page posting (création ou édition de message)
        // via le script PHP courant — fonctionne indépendamment de l'event template
        $current_script = defined('PHP_SELF') ? PHP_SELF : (isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '');
        $is_posting_page = (
            strpos($current_script, 'posting.php') !== false
            || strpos($current_script, 'ucp.php')    !== false
        );

        if (!$is_posting_page) {
            return;
        }

        // Charger les 20 prochaines missions pour le sélecteur rapide
        try {
            $result = $this->db->sql_query(
                'SELECT id, titre, sim_tag, date_mission
                 FROM ' . $this->table_prefix . 'missions
                 WHERE date_mission >= ' . $now . '
                 ORDER BY date_mission ASC
                 LIMIT 20'
            );
            while ($mis = $this->db->sql_fetchrow($result)) {
                $this->template->assign_block_vars('roster_missions', [
                    'ID'    => (int) $mis['id'],
                    'LABEL' => htmlspecialchars(
                        '[' . trim($mis['sim_tag'], '[]') . '] '
                        . html_entity_decode($mis['titre'], ENT_QUOTES, 'UTF-8')
                        . ' — ' . $this->user->format_date($mis['date_mission']),
                        ENT_QUOTES, 'UTF-8'
                    ),
                ]);
            }
            $this->db->sql_freeresult($result);
        } catch (\Exception $e) {
            // Table missions absente ou erreur SQL : on n'affiche pas le bouton
            return;
        }

        $this->template->assign_vars([
            'S_ROSTER_BBCODE_BUTTON' => true,
            'U_MISSION_LIST'         => $this->helper->route('pilot_mission_list'),
        ]);
    }
}
