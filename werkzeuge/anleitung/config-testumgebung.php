<?php

// Konfiguration für die TESTUMGEBUNG test.emc78.de
// Die Werte in <spitzen Klammern> durch eure Daten aus dem STRATO-Kundenbereich ersetzen
// (die spitzen Klammern mit entfernen). Diese Datei gehört in den Ordner /test/ (neben README.md).
return [
    'db_dsn' => 'mysql:host=<DATENBANK-HOST>;dbname=<DATENBANK-NAME>;charset=utf8mb4',
    'db_benutzer' => '<DATENBANK-BENUTZER>',
    'db_passwort' => '<DATENBANK-PASSWORT>',
    'basis_url' => '',
    'speicher_pfad' => __DIR__ . '/storage',
    'upload_max_mb' => 64,
    'app_name' => 'EMC 78 Lokschuppen',
    'debug' => false,
    'cookie_secure' => true,
    'sitzung_minuten' => 480,
    // Testumgebung einschalten (nur hier, niemals in mitglieder.emc78.de!)
    'testumgebung' => true,
    // Eigenes Passwort für admin@test.emc78.de, mindestens 10 Zeichen, nur an den Vorstand weitergeben
    'testumgebung_admin_passwort' => '<EIGENES-ADMIN-PASSWORT>',
    // Vollständige Adresse der Testinstanz (nur https://, ohne Schrägstrich am Ende)
    'oeffentliche_url' => 'https://test.emc78.de',
    // OneDrive (optional, Abschnitt 8 der Anleitung). Leer lassen, bis die App bei Microsoft registriert ist.
    // Für die Testinstanz eine eigene App-Registrierung bzw. Umleitungs-URI und einen eigenen Testordner verwenden.
    'onedrive_client_id' => '',
    'onedrive_client_secret' => '',
    // 44 Zeichen, eigener Wert nur für die Testinstanz (Vorschlag auf Verwaltung › OneDrive). Später nicht mehr ändern.
    'app_schluessel' => '',
    'onedrive_originale_lokal_behalten' => true,
    'onedrive_zeitlimit_s' => 20,
    'onedrive_zeitbudget_s' => 25,
    // Öffentliche Modellbahnbörse /boerse (Abschnitt 10 der Anleitung). Erst einschalten, wenn der Vorstand es freigibt.
    // In der Testumgebung erscheint dann eine Testleiste, Suchmaschinen nehmen die Seiten nicht auf.
    'boerse_oeffentlich' => false,
    'datenschutz_url' => '',
    // E-Mail-Versand für „Kennwort vergessen“ (Abschnitt 9). Leer lassen = Anfragen gehen an die Administratoren;
    // in der Testumgebung zeigt die App den Link zusätzlich direkt an.
    'mail_smtp_host' => '',
    'mail_smtp_verschluesselung' => 'ssl',
    'mail_smtp_port' => 0,
    'mail_smtp_benutzer' => '',
    'mail_smtp_passwort' => '',
    'mail_absender' => '',
    'mail_absender_name' => 'EMC 78 Lokschuppen',
];
