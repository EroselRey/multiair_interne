<?php
// MULTIAIR — configuration (copier en config.php et adapter ; ne jamais versionner config.php)
return [
    // Accès à la page
    'login'    => 'admin',
    'password' => 'CHANGER_MOI',

    // Clé envoyée par Make dans le header "X-Api-Key" (ou paramètre ?key=)
    'api_key'  => 'CHANGER_MOI_CLE_API',

    // Récupération du mot de passe (lien "mot de passe oublié")
    'recovery_email' => 'cyril.mortier@airwco.com',
    'mail_from'      => 'service.clients@multiairfrance.store',

    // SMTP optionnel (si vide, PHP mail() est utilisé)
    'smtp' => [
        'host' => '', 'port' => 587, 'user' => '', 'pass' => '', 'secure' => 'tls',
    ],

    // Base SQLite
    'db_path' => __DIR__ . '/data/multiair.sqlite',

    // Fuseau horaire
    'timezone' => 'Europe/Paris',

    // Durée de session (jours)
    'session_days' => 30,
];
