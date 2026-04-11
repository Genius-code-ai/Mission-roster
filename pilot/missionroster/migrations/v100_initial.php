<?php
namespace pilot\missionroster\migrations;

class v100_initial extends \phpbb\db\migration\migration
{
    /**
     * Vérifie si la migration est déjà complètement installée.
     * Vérifie l'existence des deux tables ET de toutes leurs colonnes.
     * Si une colonne manque, retourne false pour forcer la réinstallation.
     */
    public function effectively_installed()
    {
        $prefix = $this->table_prefix;

        // Vérifier table missions
        if (!$this->db_tools->sql_table_exists($prefix . 'missions')) {
            return false;
        }

        // Vérifier table mission_roster
        if (!$this->db_tools->sql_table_exists($prefix . 'mission_roster')) {
            return false;
        }

        // Vérifier colonnes critiques de phpbb_missions
        $required_missions = [
            'id', 'sim_tag', 'titre', 'description', 'slots_config',
            'allowed_groups', 'date_mission', 'date_limite',
            'allow_probables', 'creator_id'
        ];
        $result  = $this->db->sql_query('SHOW COLUMNS FROM ' . $prefix . 'missions');
        $present = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $present[] = $row['Field'];
        }
        $this->db->sql_freeresult($result);
        foreach ($required_missions as $col) {
            if (!in_array($col, $present)) {
                return false;
            }
        }

        // Vérifier colonnes critiques de phpbb_mission_roster
        $required_roster = ['id', 'mission_id', 'user_id', 'slot_name', 'status'];
        $result  = $this->db->sql_query('SHOW COLUMNS FROM ' . $prefix . 'mission_roster');
        $present = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $present[] = $row['Field'];
        }
        $this->db->sql_freeresult($result);
        foreach ($required_roster as $col) {
            if (!in_array($col, $present)) {
                return false;
            }
        }

        return true;
    }

    public function update_schema()
    {
        return [
            'add_tables' => [
                $this->table_prefix . 'missions' => [
                    'COLUMNS' => [
                        'id'             => ['UINT', null, 'auto_increment'],
                        'sim_tag'        => ['VCHAR:20', ''],
                        'titre'          => ['VCHAR:255', ''],
                        'description'    => ['MTEXT_UNI', ''],
                        'slots_config'   => ['MTEXT_UNI', ''],
                        'allowed_groups' => ['VCHAR:255', ''],
                        'date_mission'   => ['TIMESTAMP', 0],
                        'date_limite'    => ['TIMESTAMP', 0],
                        'allow_probables'=> ['TINT:1', 1],
                        'creator_id'     => ['UINT', 0],
                    ],
                    'PRIMARY_KEY' => 'id',
                ],
                $this->table_prefix . 'mission_roster' => [
                    'COLUMNS' => [
                        'id'         => ['UINT', null, 'auto_increment'],
                        'mission_id' => ['UINT', 0],
                        'user_id'    => ['UINT', 0],
                        'slot_name'  => ['VCHAR:255', ''],
                        'status'     => ['VCHAR:20', 'Titulaire'],
                    ],
                    'PRIMARY_KEY' => 'id',
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_tables' => [
                $this->table_prefix . 'missions',
                $this->table_prefix . 'mission_roster',
            ],
        ];
    }

    public function update_data()
    {
        return [
            ['permission.add', ['u_mission_create', true]],
            ['permission.add', ['u_mission_edit',   true]],
        ];
    }
}
