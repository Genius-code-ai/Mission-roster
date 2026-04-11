<?php

namespace pilot\missionroster\migrations;

class v102_add_edit_permission extends \phpbb\db\migration\migration
{
    public function update_data()
    {
        return [
            ['permission.add', ['u_mission_edit']],
        ];
    }
}