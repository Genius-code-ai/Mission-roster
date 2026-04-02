<?php
namespace pilot\missionroster\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
    protected $helper;
    protected $db;
    protected $user;
    protected $template;
    protected $table_prefix;

    public function __construct($helper, $db, $user, $template, $table_prefix)
    {
        $this->helper       = $helper;
        $this->db           = $db;
        $this->user         = $user;
        $this->template     = $template;
        $this->table_prefix = $table_prefix;
    }

    public static function getSubscribedEvents()
    {
        return [
            'core.permissions' => 'add_permissions',
            'core.page_header' => 'add_next_mission',
            // EV-02: handle [roster=ID] BBCode after s9e rendering
            'core.text_formatter_s9e_render_after' => 'on_s9e_render_after',
        ];
    }
    /**
    * Handler for EV-02: replace occurrences of [roster=ID] in the already-rendered HTML
    * (s9e output) with a safe server-side placeholder. The placeholder contains
    * minimal, escaped information and a data attribute for JS enhancement.
    *
    * This method is intentionally non-destructive: it does not remove or alter
    * existing methods such as add_next_mission. On any unexpected error it
    * returns early to avoid breaking forum rendering.
    *
    * Security notes:
    * - ID is cast to int before any DB usage.
    * - All user content is escaped with htmlspecialchars before insertion.
    * - If the mission does not exist, an accessible error placeholder is returned.
    *
    * @param \phpbb\event\data $event
    */
    public function on_s9e_render_after($event)
    {
        try {
            $text = isset($event['text']) ? $event['text'] : '';
            if ($text === '' || strpos($text, '[roster=') === false) {
                // Nothing to do
                return;
            }

            // Strict regex: only digits allowed for ID to avoid malformed input
            $pattern = '#

\[roster=(\d+)\]

#i';

            $new_text = preg_replace_callback($pattern, function ($matches) {
                $id = (int) $matches[1];
                if ($id <= 0) {
                    return $this->render_error_placeholder();
                }

                // Use extension table constant if available, fallback to a safe literal.
                $table = defined('MISSIONROSTER_TABLE') ? MISSIONROSTER_TABLE : 'phpbb_missionroster_missions';

                // Simple, safe query: id is integer-casted above
                $sql = 'SELECT mission_id, title, mission_date FROM ' . $table . ' WHERE mission_id = ' . $id;
                $result = $this->db->sql_query($sql);
                $row = $this->db->sql_fetchrow($result);
                $this->db->sql_freeresult($result);

                if (!$row) {
                    // Mission not found -> immediate server-side error placeholder
                    return $this->render_error_placeholder();
                }

                // Escape all values for safe HTML output
                $title = htmlspecialchars($row['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $date  = htmlspecialchars($row['mission_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                // Minimal placeholder: visible summary + data attributes for JS
                $html = '<div class="mr-roster-placeholder"'
                    . ' data-roster-id="' . $id . '"'
                    . ' data-roster-title="' . $title . '"'
                    . ' data-roster-date="' . $date . '">'
                    . '<div class="mr-roster-summary"><strong>' . $title . '</strong>'
                    . ' <span class="mr-roster-date">' . $date . '</span>'
                    . ' <span class="mr-roster-loading" aria-hidden="true">⏳</span></div>'
                    . '</div>';

                return $html;
            }, $text);

            // Assign modified HTML back to the event so phpBB renders it
            $event['text'] = $new_text;
        } catch (\Exception $e) {
            // Fail silently to avoid breaking forum pages; keep original text.
            return;
        }
    }

    /**
    * Render a safe, accessible error placeholder when a mission is missing or ID invalid.
    *
    * @return string HTML fragment
    */
    protected function render_error_placeholder()
    {
        $msg = 'Mission inexistante';
        $icon = '⚠️';
        return '<div class="mr-roster-error" role="alert" aria-live="polite">'
            . '<span class="mr-roster-error-icon" aria-hidden="true">' . $icon . '</span> '
            . htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</div>';
    }
    
    public function add_permissions($event)
    {
        $permissions = $event['permissions'];
        $permissions['u_mission_create'] = ['lang' => 'ACL_U_MISSION_CREATE', 'cat' => 'misc'];
        $permissions['u_mission_edit']   = ['lang' => 'ACL_U_MISSION_EDIT',   'cat' => 'misc'];
        $event['permissions'] = $permissions;
    }

    public function add_next_mission($event)
    {
        $now = time();
        $row = $this->db->sql_fetchrow($this->db->sql_query(
            'SELECT id, titre, sim_tag, date_mission
             FROM ' . $this->table_prefix . 'missions
             WHERE date_mission >= ' . (int)$now . '
             ORDER BY date_mission ASC
             LIMIT 1'
        ));

        if (!$row) { return; }

        $this->template->assign_vars([
            'NEXT_MISSION_TITRE'   => htmlspecialchars(html_entity_decode($row['titre'], ENT_QUOTES, 'UTF-8'), ENT_NOQUOTES, 'UTF-8'),
            'NEXT_MISSION_TAG'     => htmlspecialchars(trim($row['sim_tag'], '[]'), ENT_NOQUOTES, 'UTF-8'),
            'NEXT_MISSION_DATE'    => $this->user->format_date($row['date_mission']),
            'NEXT_MISSION_URL'     => $this->helper->route('pilot_mission_view', ['id' => (int)$row['id']]),
        ]);
    }
}
