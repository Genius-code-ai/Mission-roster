<?php
if (!defined('IN_PHPBB'))
{
    exit;
}
if (empty($lang) || !is_array($lang))
{
    $lang = [];
}
$lang = array_merge($lang, [
    'ACL_U_MISSION_CREATE' => 'Peut créer des missions',
    'ACL_U_MISSION_EDIT'    => 'Peut éditer les missions',
]);