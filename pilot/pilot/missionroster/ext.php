<?php
namespace pilot\missionroster;

class ext extends \phpbb\extension\base
{
    // Colonnes requises pour que l'extension fonctionne correctement
    private $required_columns = [
        'missions' => [
            'id', 'sim_tag', 'titre', 'description', 'slots_config',
            'allowed_groups', 'date_mission', 'date_limite',
            'allow_probables', 'creator_id'
        ],
        'mission_roster' => [
            'id', 'mission_id', 'user_id', 'slot_name', 'status'
        ],
    ];

    /**
     * Vérifie l'intégrité de l'installation avant d'activer l'extension.
     * Si une table ou une colonne manque, retourne false avec un message clair.
     */
    public function is_enabled()
    {
        $db     = $this->container->get('dbal.conn');
        $config = $this->container->get('config');
        $prefix = $config['table_prefix'];

        $errors = [];

        foreach ($this->required_columns as $table => $columns) {
            $full_table = $prefix . $table;

            // Vérifier que la table existe
            try {
                $result = $db->sql_query('SELECT 1 FROM ' . $full_table . ' LIMIT 1');
                $db->sql_freeresult($result);
            } catch (\Exception $e) {
                $errors[] = 'Table manquante : ' . $full_table;
                continue;
            }

            // Vérifier que chaque colonne existe
            $result  = $db->sql_query('SHOW COLUMNS FROM ' . $full_table);
            $present = [];
            while ($row = $db->sql_fetchrow($result)) {
                $present[] = $row['Field'];
            }
            $db->sql_freeresult($result);

            foreach ($columns as $col) {
                if (!in_array($col, $present)) {
                    $errors[] = 'Colonne manquante : ' . $full_table . '.' . $col;
                }
            }
        }

        if (!empty($errors)) {
            // Écrire les erreurs dans le log phpBB pour que l'admin les voie
            $log = $this->container->get('log');
            $user = $this->container->get('user');
            $log->add(
                'admin',
                $user->data['user_id'] ?? ANONYMOUS,
                $user->data['user_ip']  ?? '0.0.0.0',
                'LOG_ERROR_EXTENSION',
                false,
                ['[Mission Roster] Installation incomplète :' . "\n" . implode("\n", $errors)]
            );

            // Bloquer l'activation avec un message explicite
            throw new \phpbb\exception\runtime_exception(
                'L\'extension Mission Roster ne peut pas s\'activer car l\'installation est incomplète.' . "\n\n" .
                implode("\n", $errors) . "\n\n" .
                'Solution : désactivez l\'extension, supprimez les entrées dans phpbb_migrations ' .
                'pour pilot\\missionroster, puis réactivez l\'extension.'
            );
        }

        return parent::is_enabled();
    }
}
