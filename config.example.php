<?php

// Nach config.php kopieren und ausfüllen.
return [
    'db_dsn' => 'mysql:host=localhost;dbname=emc78;charset=utf8mb4',
    'db_benutzer' => '',
    'db_passwort' => '',
    'basis_url' => '',
    'speicher_pfad' => __DIR__ . '/storage',
    'upload_max_mb' => 64,
    'app_name' => 'EMC 78 Halle – Fahrzeugverwaltung',
    'debug' => false,
    // Sitzungs-Cookie nur über HTTPS senden; auf false nur für lokale Entwicklung ohne HTTPS.
    'cookie_secure' => true,
];
