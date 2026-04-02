# Mission-roster
Mission roster for forum phbb

1. État du code actuel
Architecture
ext/pilot/missionroster/
├── composer.json
├── ext.php                          ← vérification intégrité installation
├── event/listener.php               ← core.permissions + core.page_header
├── config/
│   ├── routing.yml                  ← 16 routes
│   └── services.yml                 ← controller + listener
├── migrations/
│   ├── v100_initial.php             ← tables + permissions u_mission_create/edit
│   └── v201_external_roster.php     ← colonne external_name (roster)
└── styles/all/template/
    ├── event/
    │   └── overall_header_navbar_before.html  ← lien Missions + prochaine mission
    ├── mission_list.html
    ├── mission_view.html
    ├── mission_create.html
    ├── mission_edit.html
    ├── mission_modify.html
    └── confirm_reserviste.html

Base de données
phpbb_missions
id, sim_tag, titre, description, slots_config (JSON), allowed_groups, date_mission (timestamp UTC), date_limite (timestamp UTC), allow_probables, creator_id
phpbb_mission_roster
id, mission_id, user_id, slot_name, status, external_name (v201)

Constantes modifiables
phpconst ROSTER_PURGE_MONTHS = 12;          // purge automatique
const ALLOWED_TAGS = ['IL2','DCS','BMS','AUTRES'];  // tags simulateurs

Permissions ACP
u_mission_create — Peut créer des missions
u_mission_edit — Peut éditer les missions (en plus du créateur)

Traductions à ajouter manuellement dans language/fr/acp/permissions.php :
php'ACL_U_MISSION_CREATE' => 'Peut créer des missions',
'ACL_U_MISSION_EDIT'   => 'Peut éditer les missions',

Gestion timezone
date_to_timestamp() et timestamp_to_local() utilisent user_timezone > board_timezone > UTC — cohérentes avec format_date() de phpBB.

Méthode de sécurité
has_external_name_column() — vérification dynamique de l'existence de la colonne external_name avant toute requête la référençant (compatibilité avant migration v201).

2. Fonctionnalités actuelles
Liste des missions (/app.php/missions)

Filtre Futures / Toutes / Passées (défaut : Futures)
Filtre par tag simulateur
Tri par date ou créateur ASC/DESC
Ratio slots inscrits/total (ex: 3/9)
Colonne créateur

Création de mission

Tags simulateur en liste fixe (select)
Slots dynamiques avec nom et nombre de places
Date mission (défaut : date du jour à 21h00)
Date limite d'inscription (vide par défaut = date mission ; pré-remplie au clic)
Groupes autorisés à s'inscrire (checkboxes — admins non décochables)
Option Probables activée/désactivée
Purge automatique missions > 12 mois

Vue détail mission (/app.php/mission/{id})

Breadcrumb "← Retour à la liste"
Infos : tag, titre, créateur, date mission, date limite
Stats : total Titulaires / Réservistes / Probables
Slots avec ratio inscrits/max, liste par statut
Bouton 📷 Copier image (canvas JS côté client — Discord compatible)
Bouton ✖ Annuler la mission (modal confirmation + MP à tous les inscrits)

Inscription (/app.php/mission/join/{id})

Réserviste sélectionnable uniquement si slot complet
Probable toujours disponible si autorisé par le créateur
Blocage si mission commencée ou date limite dépassée (côté serveur ET client)
Modal de confirmation si slot complet (propose Réserviste)

Désinscription + promotion automatique

Désinscription bloquée après début de mission (sauf admin/créateur)
Promotion automatique Réserviste → Titulaire si place libérée + MP

Modification d'inscription par le pilote

Changement de slot autorisé avant date limite
Changement de statut autorisé avant date mission
Pas de MP si auto-modification

Gestion admin/créateur du roster

Modal "Inscrire un participant" avec deux onglets :

Membre forum : liste déroulante
Externe [EXT] : saisie libre, plusieurs noms séparés par ";"


Modification d'inscription (slot + statut)
Désinscription avec promotion automatique du réserviste suivant
Pilotes externes affichés en orange avec badge [EXT]

Droits d'édition mission

Créateur : toujours autorisé
Admins / modérateurs globaux : toujours autorisés
Groupes avec u_mission_edit : autorisés via ACP

Messages privés automatiques

Promotion Réserviste → Titulaire
Rétrogradation Titulaire → Réserviste
Désinscription (slot supprimé)
Admin : inscription / modification / désinscription
Annulation de mission
Changement de date (majeur : désinscription + MP / mineur ≤1h même jour : notification)
Limite : 80 MPs par action pour éviter surcharge
Liens cliquables dans les MPs (BBCode [url])
Titres sans entités HTML (html_entity_decode)

Changement de date

Changement majeur (jour différent OU > 1h) : désinscription de tous + MP + confirmation JS obligatoire
Changement mineur (même jour ≤ 1h) : notification uniquement, inscriptions conservées

Navbar forum

Lien "✈ Missions" dans le menu Liens rapides (via overall_header_navbar_before.html et event phpBB)
Rappel prochaine mission à droite : 🕐 Prochaine mission : [date] [tag] [nom] — cliquable

Sécurité

Protection CSRF sur tous les formulaires (add_form_key / check_form_key)
Validation sim_tag contre ALLOWED_TAGS
Noms de slots : strip_tags + mb_substr(100)
XSS : htmlspecialchars ENT_COMPAT pour attributs HTML, html_entity_decode pour affichage texte
SLOTS_STATUS_JSON : addslashes(json_encode()) pour éviter casse JSON
Anonymous bloqué à l'inscription
Vérification côté serveur de toutes les contraintes (dates, droits)
