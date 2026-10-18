-- Stufe 1: Grundgerüst und Fahrzeugkatalog (MySQL >= 8 / MariaDB >= 10.6)

CREATE TABLE IF NOT EXISTS schema_migration (
    version VARCHAR(100) NOT NULL PRIMARY KEY,
    ausgefuehrt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE einstellung (
    schluessel VARCHAR(100) NOT NULL PRIMARY KEY,
    wert TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE person (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vorname VARCHAR(100) NOT NULL,
    nachname VARCHAR(100) NOT NULL,
    email VARCHAR(190) NULL,
    telefon VARCHAR(50) NULL,
    notiz TEXT NULL,
    aktiv TINYINT(1) NOT NULL DEFAULT 1,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE benutzer (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    person_id INT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL,
    passwort_hash VARCHAR(255) NOT NULL,
    rolle ENUM('mitglied','bearbeiter','admin') NOT NULL DEFAULT 'mitglied',
    aktiv TINYINT(1) NOT NULL DEFAULT 1,
    muss_passwort_aendern TINYINT(1) NOT NULL DEFAULT 0,
    letzter_login DATETIME NULL,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_benutzer_person (person_id),
    UNIQUE KEY uq_benutzer_email (email),
    CONSTRAINT fk_benutzer_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_versuch (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    zeitpunkt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    erfolgreich TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_login_email_zeit (email, zeitpunkt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE spurweite (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(30) NOT NULL,
    massstab VARCHAR(20) NULL,
    sortierung INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_spurweite_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fahrzeug (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    inventarnummer VARCHAR(20) NOT NULL,
    bezeichnung VARCHAR(150) NOT NULL,
    baureihe VARCHAR(100) NULL,
    bahnverwaltung VARCHAR(100) NULL,
    epoche VARCHAR(5) NULL,
    gattung ENUM('lok','triebwagen','wagen','sonderfahrzeug') NOT NULL DEFAULT 'lok',
    hersteller VARCHAR(100) NULL,
    artikelnummer VARCHAR(100) NULL,
    spurweite_id INT UNSIGNED NOT NULL,
    stromsystem ENUM('2L','3L') NOT NULL DEFAULT '2L',
    eigentuemer_person_id INT UNSIGNED NULL,
    status ENUM('einsatzbereit','in_wartung','defekt','ausgeliehen','ausgemustert') NOT NULL DEFAULT 'einsatzbereit',
    betriebsstunden DECIMAL(8,1) NOT NULL DEFAULT 0,
    titelbild_dokument_id INT UNSIGNED NULL,
    notiz TEXT NULL,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    geaendert_am DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_fahrzeug_inventarnummer (inventarnummer),
    CONSTRAINT fk_fahrzeug_spurweite FOREIGN KEY (spurweite_id) REFERENCES spurweite (id) ON DELETE RESTRICT,
    CONSTRAINT fk_fahrzeug_eigentuemer FOREIGN KEY (eigentuemer_person_id) REFERENCES person (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE decoder (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fahrzeug_id INT UNSIGNED NOT NULL,
    bezeichnung VARCHAR(100) NOT NULL,
    hersteller VARCHAR(100) NULL,
    typ VARCHAR(100) NULL,
    firmware VARCHAR(50) NULL,
    sound TINYINT(1) NOT NULL DEFAULT 0,
    protokoll ENUM('dcc','motorola','mfx','selectrix') NOT NULL DEFAULT 'dcc',
    adresse INT NULL,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_decoder_protokoll_adresse (protokoll, adresse),
    CONSTRAINT fk_decoder_fahrzeug FOREIGN KEY (fahrzeug_id) REFERENCES fahrzeug (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE funktion (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    decoder_id INT UNSIGNED NOT NULL,
    taste TINYINT UNSIGNED NOT NULL,
    beschreibung VARCHAR(100) NOT NULL,
    schaltart ENUM('dauer','moment') NOT NULL DEFAULT 'dauer',
    UNIQUE KEY uq_funktion_decoder_taste (decoder_id, taste),
    CONSTRAINT fk_funktion_decoder FOREIGN KEY (decoder_id) REFERENCES decoder (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE funktionsvorlage (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    decoder_typ VARCHAR(100) NULL,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_funktionsvorlage_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vorlage_funktion (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vorlage_id INT UNSIGNED NOT NULL,
    taste TINYINT UNSIGNED NOT NULL,
    beschreibung VARCHAR(100) NOT NULL,
    schaltart ENUM('dauer','moment') NOT NULL DEFAULT 'dauer',
    UNIQUE KEY uq_vorlage_funktion_taste (vorlage_id, taste),
    CONSTRAINT fk_vorlage_funktion_vorlage FOREIGN KEY (vorlage_id) REFERENCES funktionsvorlage (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cv_wert (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    decoder_id INT UNSIGNED NOT NULL,
    cv SMALLINT UNSIGNED NOT NULL,
    wert SMALLINT UNSIGNED NOT NULL,
    kommentar VARCHAR(200) NULL,
    UNIQUE KEY uq_cv_decoder_cv (decoder_id, cv),
    CONSTRAINT fk_cv_decoder FOREIGN KEY (decoder_id) REFERENCES decoder (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dokument (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    titel VARCHAR(200) NOT NULL,
    kategorie ENUM('foto','anleitung','schaltplan','gleisplan','rechnung','sonstiges') NOT NULL DEFAULT 'sonstiges',
    original_name VARCHAR(255) NOT NULL,
    endung VARCHAR(10) NOT NULL,
    mime VARCHAR(100) NOT NULL,
    groesse INT UNSIGNED NOT NULL DEFAULT 0,
    pfad VARCHAR(255) NOT NULL,
    hat_anzeige TINYINT(1) NOT NULL DEFAULT 0,
    hochgeladen_von INT UNSIGNED NULL,
    hochgeladen_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dokument_benutzer FOREIGN KEY (hochgeladen_von) REFERENCES benutzer (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dokument_zuordnung (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    dokument_id INT UNSIGNED NOT NULL,
    objekt_typ VARCHAR(30) NOT NULL,
    objekt_id INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_zuordnung (dokument_id, objekt_typ, objekt_id),
    INDEX idx_zuordnung_objekt (objekt_typ, objekt_id),
    CONSTRAINT fk_zuordnung_dokument FOREIGN KEY (dokument_id) REFERENCES dokument (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE aenderungsprotokoll (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    benutzer_id INT UNSIGNED NULL,
    objekt_typ VARCHAR(30) NOT NULL,
    objekt_id INT UNSIGNED NOT NULL,
    aktion ENUM('angelegt','geaendert','geloescht') NOT NULL,
    aenderungen LONGTEXT NULL,
    zeitpunkt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_protokoll_objekt (objekt_typ, objekt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
