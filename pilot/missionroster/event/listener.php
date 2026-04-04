<?php
namespace pilot\missionroster\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Listener principal de l'extension Mission Roster.
 *
 * Fonctionnalités :
 *  - Permissions ACP (u_mission_create, u_mission_edit)
 *  - Navbar : prochaine mission + bouton Roster dans l'éditeur de post
 *  - BBCode [roster=N] : carte de lien avec ratio AJAX dans les posts forum
 *    via core.viewtopic_post_row_after (event garanti sur viewtopic.php)
 */
class listener implements EventSubscriberInterface
{
    protected $helper;
    protected $db;
    protected $user;
    protected $template;
    protected $auth;
    protected $request;
    protected $table_prefix;

    public function __construct($helper, $db, $user, $template, $auth, $request, $table_prefix)
    {
        $this->helper       = $helper;
        $this->db           = $db;
        $this->user         = $user;
        $this->template     = $template;
        $this->auth         = $auth;
        $this->request      = $request;
        $this->table_prefix = $table_prefix;
    }

    public static function getSubscribedEvents()
    {
        return [
            'core.permissions'              => 'add_permissions',
            'core.page_header'              => 'add_next_mission_and_editor_button',
            // Rendu BBCode [roster=N] — event garanti sur viewtopic.php
            'core.viewtopic_modify_post_row' => 'render_roster_in_post',
        ];
    }
   
    // =========================================================================
    // BBCode [roster=N] — carte rendue dans les posts forum
    // =========================================================================

    /**
     * Détecte [roster=N] dans le MESSAGE HTML du post et le remplace
     * par une carte de lien avec ratio AJAX.
     */
    public function render_roster_in_post($event)
    {
        $post_row = $event['post_row'];
        $message  = isset($post_row['MESSAGE']) ? $post_row['MESSAGE'] : '';

        if ($message === '') {
            return;
        }

        $has_roster = (
            strpos($message, '[roster=')     !== false ||
            strpos($message, '&#91;roster=') !== false
        );

        if (!$has_roster) {
            return;
        }

        // Pattern gérant crochets bruts et entités HTML &#91; &#93;
        $pattern = '/(?:\[|&#91;)roster=(\d+)(?:\]|&#93;)/i';

        $message = preg_replace_callback($pattern, function ($matches) {
            $id = (int) $matches[1];
            if ($id <= 0) {
                return $this->render_error_placeholder();
            }
            try {
                return $this->build_roster_card($id);
            } catch (\Exception $e) {
                return $this->render_error_placeholder();
            }
        }, $message);

        $post_row['MESSAGE'] = $message;
        $event['post_row']   = $post_row;
    }

    /**
     * Construit la carte de lien pour une mission donnée.
     * Ratio inscrits/slots affiché initialement puis rafraîchi par AJAX.
     */
    protected function build_roster_card($mission_id)
    {
        // ── Mission ──────────────────────────────────────────────────────────
        $result = $this->db->sql_query(
            'SELECT m.id, m.titre, m.sim_tag, m.description, m.date_mission,
                    m.date_limite, m.allow_probables, m.slots_config,
                    u.username AS creator_name
             FROM ' . $this->table_prefix . 'missions m
             LEFT JOIN ' . USERS_TABLE . ' u ON m.creator_id = u.user_id
             WHERE m.id = ' . $mission_id
        );
        $m = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$m) {
            return $this->render_error_placeholder();
        }

        // ── Ratio titulaires / max ────────────────────────────────────────────
        $slots_config = json_decode($m['slots_config'], true) ?: [];
        $total_max    = 0;
        foreach ($slots_config as $s) {
            $total_max += (int) $s['count'];
        }

        $res_count = $this->db->sql_query(
            'SELECT COUNT(*) AS cnt FROM ' . $this->table_prefix . 'mission_roster
             WHERE mission_id = ' . $mission_id . " AND status = 'Titulaire'"
        );
        $row_count = $this->db->sql_fetchrow($res_count);
        $total_t   = (int) ($row_count['cnt'] ?? 0);
        $this->db->sql_freeresult($res_count);

        // ── Inscription utilisateur courant ───────────────────────────────────
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

        // ── Meta ─────────────────────────────────────────────────────────────
        $titre   = htmlspecialchars(html_entity_decode($m['titre'], ENT_QUOTES, 'UTF-8'), ENT_NOQUOTES, 'UTF-8');
        $sim_tag = htmlspecialchars($m['sim_tag'], ENT_NOQUOTES, 'UTF-8');
        $creator = htmlspecialchars($m['creator_name'] ?? '—', ENT_NOQUOTES, 'UTF-8');
        $date_m  = $this->user->format_date($m['date_mission']);
        $date_l  = $this->user->format_date($m['date_limite']);
        $desc    = !empty($m['description'])
            ? '<p class="rmbc-desc">' . htmlspecialchars(
                html_entity_decode($m['description'], ENT_QUOTES, 'UTF-8'), ENT_NOQUOTES, 'UTF-8'
              ) . '</p>'
            : '';

        $url_view   = $this->helper->route('pilot_mission_view',        ['id' => $mission_id]);
        $url_status = $this->helper->route('pilot_mission_slots_status', ['id' => $mission_id]);

        // Statut
        $now = time();
        if ($now >= (int) $m['date_mission']) {
            $status_html = '<span class="rmbc-status rmbc-s-past">Mission passee</span>';
        } elseif ($now >= (int) $m['date_limite']) {
            $status_html = '<span class="rmbc-status rmbc-s-closed">Inscriptions fermees</span>';
        } else {
            $status_html = '<span class="rmbc-status rmbc-s-open">Inscriptions ouvertes</span>';
        }

        // Badge inscription
        $my_badge = '';
        if ($row_ui) {
            $sc = [
                'Titulaire'  => 'rmbc-badge-t',
                'Reserviste' => 'rmbc-badge-r',
                'Probable'   => 'rmbc-badge-p',
            ][$row_ui['status']] ?? 'rmbc-badge-t';
            $my_badge = ' <span class="rmbc-badge ' . $sc . '">'
                . htmlspecialchars($row_ui['status'], ENT_NOQUOTES, 'UTF-8')
                . '</span>';
        }

        // CTA
        if ($now >= (int) $m['date_mission']) {
            $cta = '<a href="' . $url_view . '" class="rmbc-cta rmbc-cta-sec">Voir le roster final</a>';
        } elseif (!$is_anon && $row_ui) {
            $cta = '<a href="' . $url_view . '" class="rmbc-cta rmbc-cta-warn">Modifier mon inscription</a>';
        } elseif (!$is_anon && !$row_ui && $now < (int) $m['date_limite']) {
            $cta = '<a href="' . $url_view . '" class="rmbc-cta rmbc-cta-pri">S\'inscrire a cette mission</a>';
        } else {
            $cta = '<a href="' . $url_view . '" class="rmbc-cta rmbc-cta-sec">Voir la mission</a>';
        }

        $ratio_init = $total_t . '/' . $total_max;
        $pct_init   = $total_max > 0 ? round($total_t / $total_max * 100) : 0;
        $bar_class  = ($total_t >= $total_max && $total_max > 0) ? ' rmbc-bar-full' : '';
        $widget_id  = 'rmbc-' . $mission_id;
        // Utilise le format_date de phpBB pour respecter le fuseau de l'user
        $now_time   = $this->user->format_date(time(), 'H:i');
        // Récupérer le décalage (offset) pour le JavaScript
        $user_offset = $this->user->timezone->getOffset(new \DateTime('now', new \DateTimeZone('UTC')));

        // ── Assemblage ────────────────────────────────────────────────────────
        $html  = $this->get_card_css();
        $html .= '<div class="rmbc-wrap" id="' . $widget_id . '"'
            . ' data-mission-id="' . $mission_id . '"'
            . ' data-timezone="' . (int) $user_offset . '"' // On passe le décalage ici
            . ' data-status-url="' . htmlspecialchars($url_status, ENT_COMPAT, 'UTF-8') . '">';

        $html .= '<div class="rmbc-header">'
            . '<div class="rmbc-hd-top">'
            .   '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">'
            .     '<span class="rmbc-tag">' . $sim_tag . '</span>'
            .     '<strong class="rmbc-title">' . $titre . '</strong>'
            .     $my_badge
            .   '</div>'
            .   $status_html
            . '</div>'
            . '<div class="rmbc-meta">Par <strong>' . $creator . '</strong>'
            .   ' | Mission : ' . $date_m
            .   ' | Inscriptions jusqu\'au ' . $date_l
            . '</div>'
            . $desc
            . '</div>';

        $html .= '<div class="rmbc-body">'
            . '<div class="rmbc-ratio-row">'
            .   '<span class="rmbc-ratio-lbl">Titulaires inscrits</span>'
            .   '<span class="rmbc-ratio-val" id="' . $widget_id . '-ratio">' . $ratio_init . '</span>'
            . '</div>'
            . '<div class="rmbc-progress">'
            .   '<div class="rmbc-bar' . $bar_class . '" id="' . $widget_id . '-bar"'
            .   ' style="width:' . $pct_init . '%"></div>'
            . '</div>'
            . '<div class="rmbc-refresh-row">'
            .   '<span id="' . $widget_id . '-ts">Mis a jour a ' . $now_time . '</span>'
            .   ' &nbsp;·&nbsp;'
            .   '<a href="#" class="rmbc-refresh-lnk"'
            .   ' onclick="rmbcRefresh(\'' . $widget_id . '\');return false;">Actualiser</a>'
            . '</div>'
            . '</div>';

        $html .= '<div class="rmbc-footer">'
            . $cta
            . ' <a href="' . $this->helper->route('pilot_mission_list') . '" class="rmbc-cta rmbc-cta-ghost">Toutes les missions</a>'
            . '</div>';

        $html .= '</div>';

        $html .= $this->get_ajax_script();

        return $html;
    }

    /**
     * CSS de la carte — injecté une seule fois par page.
     */
    protected function get_card_css()
    {
        static $injected = false;
        if ($injected) { return ''; }
        $injected = true;
        return '<style>
.rmbc-wrap{border:1px solid #cbd5e1;border-radius:8px;overflow:hidden;margin:10px 0;font-family:system-ui,sans-serif;font-size:.875rem;background:#fff;max-width:680px;}
.rmbc-header{padding:12px 16px;background:#1e293b;color:#f8fafc;}
.rmbc-hd-top{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:6px;margin-bottom:4px;}
.rmbc-tag{display:inline-block;padding:1px 7px;border-radius:3px;font-size:.71rem;font-weight:700;background:#fbbf24;color:#1c1917;}
.rmbc-title{font-size:.92rem;font-weight:700;color:#f8fafc;}
.rmbc-meta{color:#94a3b8;font-size:.77rem;margin-top:2px;}
.rmbc-desc{color:#cbd5e1;font-size:.79rem;margin:3px 0 0;}
.rmbc-status{display:inline-block;padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:600;}
.rmbc-s-open{background:#dcfce7;color:#166534;}
.rmbc-s-closed{background:#fef3c7;color:#92400e;}
.rmbc-s-past{background:#e2e8f0;color:#475569;}
.rmbc-badge{display:inline-block;padding:1px 6px;border-radius:20px;font-size:.7rem;font-weight:600;}
.rmbc-badge-t{background:#dcfce7;color:#166534;}
.rmbc-badge-r{background:#ede9fe;color:#5b21b6;}
.rmbc-badge-p{background:#fef9c3;color:#854d0e;}
.rmbc-body{padding:10px 16px;}
.rmbc-ratio-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;}
.rmbc-ratio-lbl{font-size:.79rem;color:#64748b;}
.rmbc-ratio-val{font-size:.95rem;font-weight:700;color:#1e293b;}
.rmbc-progress{height:7px;background:#e2e8f0;border-radius:4px;overflow:hidden;margin-bottom:5px;}
.rmbc-bar{height:100%;border-radius:4px;background:#22c55e;transition:width .4s ease;}
.rmbc-bar-full{background:#ef4444;}
.rmbc-refresh-row{font-size:.73rem;color:#94a3b8;}
.rmbc-refresh-lnk{color:#3b82f6;text-decoration:none;}
.rmbc-refresh-lnk:hover{text-decoration:underline;}
.rmbc-footer{display:flex;align-items:center;gap:8px;padding:8px 16px;border-top:1px solid #f1f5f9;background:#f8fafc;flex-wrap:wrap;}
.rmbc-cta{display:inline-flex;align-items:center;padding:5px 12px;border-radius:5px;font-size:.79rem;font-weight:600;text-decoration:none;transition:opacity .15s;}
.rmbc-cta:hover{opacity:.85;}
.rmbc-cta-pri{background:#3b82f6;color:#fff !important;}
.rmbc-cta-sec{background:#e2e8f0;color:#1e293b !important;}
.rmbc-cta-warn{background:#f59e0b;color:#fff !important;}
.rmbc-cta-ghost{color:#64748b !important;font-size:.77rem;}
</style>';
    }

/**
     * Script AJAX — auto-refresh toutes les 60s + bouton manuel.
     * Injecté une seule fois par page.
     */
    protected function get_ajax_script()
    {
        static $script_done = false;
        if ($script_done) { return ''; }
        $script_done = true;
        return '<script>
(function(){
    var INTERVAL = 60000;
    function pad(n){ return n < 10 ? "0"+n : ""+n; }
    
    function update(wid, data){
        if(!data || !data.slots) return;

        var t=0, m=0;
        data.slots.forEach(function(s){ 
            t += parseInt(s.titulaires) || 0; 
            m += parseInt(s.max || s.count) || 0; 
        });

        var pct = m > 0 ? Math.round(t/m*100) : 0;
        var full = m > 0 && t >= m;
        
        var elRatio = document.getElementById(wid+"-ratio");
        if(elRatio) elRatio.textContent = t + "/" + m;
        
        var elBar = document.getElementById(wid+"-bar");
        if(elBar){ 
            elBar.style.width = pct + "%"; 
            elBar.className = "rmbc-bar" + (full ? " rmbc-bar-full" : ""); 
        }
        
        var elTs = document.getElementById(wid+"-ts");
        if(elTs){ 
            var wrap = document.getElementById(wid);
            // Récupération du décalage horaire envoyé par PHP
            var offset = parseInt(wrap.getAttribute("data-timezone")) || 0;
            
            // Calcul de l heure basée sur l offset utilisateur
            var now = new Date();
            var utc = now.getTime() + (now.getTimezoneOffset() * 60000);
            var userDate = new Date(utc + (offset * 1000));

            elTs.textContent = "Mis a jour a " + pad(userDate.getHours()) + ":" + pad(userDate.getMinutes()); 
        }
    }

    window.rmbcRefresh = function(wid){
        var wrap = document.getElementById(wid);
        if(!wrap) return;
        var url = wrap.getAttribute("data-status-url");
        var lnk = wrap.querySelector(".rmbc-refresh-lnk");
        
        if(lnk) lnk.textContent = "...";

        fetch(url, {credentials: "same-origin"})
            .then(function(r){ return r.json(); })
            .then(function(d){ 
                update(wid, d); 
                if(lnk) lnk.textContent = "Actualiser"; 
            })
            .catch(function(err){ 
                console.error("Erreur Mission Roster:", err);
                if(lnk) lnk.textContent = "Actualiser"; 
            });
    };

    setInterval(function(){
        document.querySelectorAll(".rmbc-wrap[data-mission-id]").forEach(function(w){ 
            window.rmbcRefresh(w.id); 
        });
    }, INTERVAL);
})();
</script>';
    }

    /**
     * Vérifie si la colonne external_name existe (migration v201).
     */
    protected function has_external_name_column()
    {
        static $checked = null;
        if ($checked !== null) { return $checked; }
        try {
            $result  = $this->db->sql_query('SHOW COLUMNS FROM ' . $this->table_prefix . 'mission_roster');
            $checked = false;
            while ($row = $this->db->sql_fetchrow($result)) {
                if ($row['Field'] === 'external_name') { $checked = true; break; }
            }
            $this->db->sql_freeresult($result);
        } catch (\Exception $e) {
            $checked = false;
        }
        return $checked;
    }

    /**
     * Placeholder affiché si la mission est introuvable.
     */
    protected function render_error_placeholder($debug = '')
    {
        return '<div style="display:flex;align-items:center;gap:8px;border:1px solid #fca5a5;'
            . 'background:#fef2f2;border-radius:6px;padding:8px 12px;margin:6px 0;'
            . 'font-family:system-ui,sans-serif;font-size:.84rem;">'
            . '<span style="font-size:1.2rem;flex-shrink:0;">&#9888;</span>'
            . '<div>'
            .   '<strong style="color:#991b1b;">Mission introuvable</strong> '
            .   '<span style="color:#b91c1c;">Cette mission a ete supprimee ou l\'identifiant est invalide.</span>'
            . '</div>'
            . '</div>';
    }

    // =========================================================================
    // Permissions ACP
    // =========================================================================

    public function add_permissions($event)
    {
        $permissions = $event['permissions'];
        $permissions['u_mission_create'] = ['lang' => 'ACL_U_MISSION_CREATE', 'cat' => 'misc'];
        $permissions['u_mission_edit']   = ['lang' => 'ACL_U_MISSION_EDIT',   'cat' => 'misc'];
        $event['permissions'] = $permissions;
    }

    // =========================================================================
    // Navbar + bouton Roster dans l'editeur de post
    // =========================================================================

     // =========================================================================
    // Affichage en haut de forum avec liens rapides et fonctionnel
    // =========================================================================

    /**
     * Variables globales (Navbar + Editeur)
     */
    public function add_next_mission_and_editor_button($event)
    {
        // --- CONFIGURATION DES OPTIONS D'AFFICHAGE ---
        $this->template->assign_vars([
            'S_DISPLAY_MISSION_LINK'          => true, // Afficher l'icône avion (Navbar)
            'S_DISPLAY_NEXT_MISSION_REMINDER' => true, // Afficher le rappel (Top Right)
            'U_MISSION_LIST'                  => $this->helper->route('pilot_mission_list'), // Doit être ici pour être global
        ]);

        $now = time();
        
        // Prochaine mission pour le rappel
        $sql = 'SELECT id, titre, sim_tag, date_mission
                FROM ' . $this->table_prefix . 'missions
                WHERE date_mission >= ' . (int) $now . '
                ORDER BY date_mission ASC LIMIT 1';
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if ($row) {
            $this->template->assign_vars([
                'NEXT_MISSION_TITRE' => htmlspecialchars(html_entity_decode($row['titre'], ENT_QUOTES, 'UTF-8'), ENT_NOQUOTES, 'UTF-8'),
                'NEXT_MISSION_TAG'   => htmlspecialchars(trim($row['sim_tag'], '[]'), ENT_NOQUOTES, 'UTF-8'),
                'NEXT_MISSION_DATE'  => $this->user->format_date($row['date_mission']),
                'NEXT_MISSION_URL'   => $this->helper->route('pilot_mission_view', ['id' => (int) $row['id']]),
            ]);
        }

        // Bouton Roster dans l'editeur (posting.php uniquement)
        $script          = $this->request->server('PHP_SELF');
        $is_posting_page = (
            strpos($script, 'posting.php') !== false ||
            strpos($script, 'ucp.php')     !== false
        );
        if (!$is_posting_page) {
            return;
        }

        try {
            $result = $this->db->sql_query(
                'SELECT id, titre, sim_tag, date_mission
                 FROM ' . $this->table_prefix . 'missions
                 WHERE date_mission >= ' . $now . '
                 ORDER BY date_mission ASC LIMIT 20'
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
            return;
        }

        $this->template->assign_vars([
            'S_ROSTER_BBCODE_BUTTON' => true,
            'U_MISSION_LIST'         => $this->helper->route('pilot_mission_list'),
        ]);
    }
}
