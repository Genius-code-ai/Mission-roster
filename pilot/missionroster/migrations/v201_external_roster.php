<?php
namespace pilot\missionroster\migrations;

class v201_external_roster extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        $result  = $this->db->sql_query('SHOW COLUMNS FROM ' . $this->table_prefix . 'mission_roster');
        $columns = [];
        while ($row = $this->db->sql_fetchrow($result)) { $columns[] = $row['Field']; }
        $this->db->sql_freeresult($result);
        return in_array('external_name', $columns);
    }

    public static function depends_on()
    {
        return ['\pilot\missionroster\migrations\v100_initial'];
    }

    public function update_schema()
    {
        return [
            'add_columns' => [
                $this->table_prefix . 'mission_roster' => [
                    'external_name' => ['VCHAR:100', ''],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_columns' => [
                $this->table_prefix . 'mission_roster' => ['external_name'],
            ],
        ];
    }
}
