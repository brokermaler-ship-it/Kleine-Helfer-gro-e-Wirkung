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
    // Zeitzone für Datum und Uhrzeit (Standard Europe/Berlin).
    'zeitzone' => 'Europe/Berlin',
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
    // ---- OneDrive-Ablage (optional) ----
    // Ohne diese Werte bleibt alles lokal auf dem Webspace. Schritt-für-Schritt-Anleitung: README.md, Abschnitt „OneDrive anbinden“.
    // OneDrive ist erst aktiv, wenn oeffentliche_url, onedrive_client_id, onedrive_client_secret und app_schluessel gesetzt sind.
    // Öffentliche Adresse der Installation ohne Pfad und ohne Schrägstrich am Ende, nur https
    // (Echtbetrieb 'https://mitglieder.emc78.de', Testinstanz 'https://test.emc78.de').
    // Daraus entsteht die Rückruf-URL <oeffentliche_url><basis_url>/admin/onedrive/rueckruf; sie steht auch unter
    // Verwaltung → OneDrive und muss genau so als Umleitungs-URI (Typ „Web“) bei der App-Registrierung eingetragen sein.
    'oeffentliche_url' => '',
    // „Anwendungs-ID (Client)“ der App-Registrierung (https://entra.microsoft.com, Kontotyp „Nur persönliche Microsoft-Konten“,
    // delegierte Berechtigungen Files.ReadWrite.All, offline_access, User.Read).
    'onedrive_client_id' => '',
    // „Wert“ des geheimen Clientschlüssels der App-Registrierung. Nur hier ablegen, niemals in der Datenbank oder weitergeben.
    // Der Schlüssel hat ein Ablaufdatum: vorher einen neuen anlegen und hier ersetzen.
    'onedrive_client_secret' => '',
    // 32 Zufallsbytes, base64-kodiert (44 Zeichen), verschlüsselt die gespeicherten Microsoft-Tokens. Erzeugen mit
    // php -r "echo base64_encode(random_bytes(32));" oder den Vorschlag unter Verwaltung → OneDrive übernehmen.
    // Nach dem Verbinden nicht mehr ändern: ein neuer Schlüssel macht die gespeicherte Verbindung unlesbar (neu verbinden).
    // Je Instanz (Echtbetrieb, Testinstanz) ein eigener Schlüssel.
    'app_schluessel' => '',
    // true (Standard): Originale bleiben nach der Übertragung zusätzlich lokal liegen, die Datensicherung enthält sie dann.
    // false: Originale werden nach der Übertragung lokal entfernt und liegen nur noch im OneDrive (spart Speicher;
    // die Datensicherung der App enthält sie dann nicht mehr).
    'onedrive_originale_lokal_behalten' => true,
    // Zeitlimit je Anfrage an OneDrive in Sekunden (Standard 20).
    'onedrive_zeitlimit_s' => 20,
];
