<?php

// Nach config.php kopieren und ausfüllen.
return [
    'db_dsn' => 'mysql:host=localhost;dbname=emc78;charset=utf8mb4',
    'db_benutzer' => '',
    'db_passwort' => '',
    'basis_url' => '',
    'speicher_pfad' => __DIR__ . '/storage',
    'upload_max_mb' => 64,
    'app_name' => 'EMC 78 Lokschuppen',
    'debug' => false,
    // Sitzungs-Cookie nur über HTTPS senden; auf false nur für lokale Entwicklung ohne HTTPS.
    'cookie_secure' => true,
    // Minuten ohne Aufruf, nach denen eine Anmeldung verfallen darf (Standard 480 = 8 Stunden).
    'sitzung_minuten' => 480,
    // Nur für die Testinstanz (test.emc78.de): zeigt eine gelbe Leiste, die Testzugänge auf der Anmeldeseite
    // und unter Verwaltung → Testdaten das Laden von Beispieldaten. Nur genau true schaltet sie ein.
    // Im Echtbetrieb immer false.
    'testumgebung' => false,
    // Nur Testinstanz: Passwort für admin@test.emc78.de beim Laden der Beispieldaten (mindestens 10 Zeichen).
    // Wird nirgends angezeigt; nur an Administratoren weitergeben. Ohne diesen Wert werden keine Beispieldaten geladen.
    'testumgebung_admin_passwort' => '',
    // OneDrive-Ablage (optional; ohne diese Werte bleibt alles lokal). App unter https://entra.microsoft.com
    // registrieren (persönliche Konten), Rückruf-URL: <basis_url>/admin/onedrive/rueckruf
    'onedrive_client_id' => '',
    // Nur hier ablegen, niemals in der Datenbank.
    'onedrive_client_secret' => '',
    // 32 Zufallsbytes, base64-kodiert (z. B. php -r "echo base64_encode(random_bytes(32));"). Verschlüsselt die Tokens.
    // Nach dem Verbinden nicht mehr ändern, sonst muss OneDrive neu verbunden werden.
    'app_schluessel' => '',
    // true: Originale bleiben nach der Übertragung zusätzlich lokal liegen.
    'onedrive_originale_lokal_behalten' => false,
    // Zeitlimit je Anfrage an OneDrive in Sekunden.
    'onedrive_zeitlimit_s' => 20,
];
