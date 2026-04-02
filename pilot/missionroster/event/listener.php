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
        ];
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
