-- Version 0.8.0: Modellbahnbörse intern (MySQL >= 8 / MariaDB >= 10.6).
-- Geldbeträge als DECIMAL(10,2); die App rechnet nur mit Zeichenketten bzw. ganzen Cent (Emc\Boerse\Geld).

-- Börse (Veranstaltung): Provision in Prozent je verkauftem Mitgliedsstück, Status in_vorbereitung → laeuft →
-- abgeschlossen (nur ein Administrator öffnet wieder).
CREATE TABLE boerse (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    datum DATE NOT NULL,
    ort VARCHAR(150) NULL,
    provision_prozent DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    status ENUM('in_vorbereitung','laeuft','abgeschlossen') NOT NULL DEFAULT 'in_vorbereitung',
    abgeschlossen_am DATETIME NULL,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    geaendert_am DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_boerse_status_datum (status, datum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Laufnummer der Angebote je Jahr (B<Jahr>-<n>); atomar über LAST_INSERT_ID(…) in AngebotRepository::naechsteLaufnummer.
CREATE TABLE boerse_angebot_zaehler (
    jahr SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
    letzte INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Angebot: vom Verein (Magazinartikel mit Menge oder ganze Sammlung) oder von einem Mitglied (Person).
-- boerse_id NULL = dauerhaft (an jeder Börse verkaufbar). oeffentlich: nein / vorgeschlagen (vom Mitglied) /
-- freigegeben (vom Börsenwart). notiz ist intern und erscheint nie öffentlich. Fotos über dokument_zuordnung
-- mit objekt_typ = 'boerse_angebot'; titelbild_dokument_id ohne Fremdschlüssel (wie beim Magazin).
CREATE TABLE boerse_angebot (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    jahr SMALLINT UNSIGNED NOT NULL,
    laufnummer INT UNSIGNED NOT NULL,
    bezeichnung VARCHAR(200) NOT NULL,
    beschreibung TEXT NULL,
    kategorie_id INT UNSIGNED NOT NULL,
    spurweite_id INT UNSIGNED NULL,
    zustand ENUM('neu','neuwertig','gebraucht','defekt') NOT NULL DEFAULT 'gebraucht',
    wunschpreis DECIMAL(10,2) NOT NULL,
    herkunft ENUM('verein','mitglied') NOT NULL,
    magazin_artikel_id INT UNSIGNED NULL,
    magazin_sammlung_id INT UNSIGNED NULL,
    menge INT UNSIGNED NOT NULL DEFAULT 1,
    person_id INT UNSIGNED NULL,
    erloes_spende TINYINT(1) NOT NULL DEFAULT 0,
    boerse_id INT UNSIGNED NULL,
    status ENUM('angeboten','reserviert','verkauft','zurueck') NOT NULL DEFAULT 'angeboten',
    oeffentlich ENUM('nein','vorgeschlagen','freigegeben') NOT NULL DEFAULT 'nein',
    notiz TEXT NULL,
    titelbild_dokument_id INT UNSIGNED NULL,
    angelegt_von_benutzer_id INT UNSIGNED NULL,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    geaendert_am DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_boerse_angebot_nummer (jahr, laufnummer),
    INDEX idx_boerse_angebot_boerse (boerse_id, status),
    INDEX idx_boerse_angebot_oeffentlich (oeffentlich, status),
    INDEX idx_boerse_angebot_person (person_id),
    INDEX idx_boerse_angebot_artikel (magazin_artikel_id, status),
    INDEX idx_boerse_angebot_sammlung (magazin_sammlung_id, status),
    INDEX idx_boerse_angebot_kategorie (kategorie_id),
    INDEX idx_boerse_angebot_spurweite (spurweite_id),
    INDEX idx_boerse_angebot_angelegt_von (angelegt_von_benutzer_id),
    CONSTRAINT fk_boerse_angebot_kategorie FOREIGN KEY (kategorie_id) REFERENCES magazin_kategorie (id) ON DELETE RESTRICT,
    CONSTRAINT fk_boerse_angebot_spurweite FOREIGN KEY (spurweite_id) REFERENCES spurweite (id) ON DELETE SET NULL,
    CONSTRAINT fk_boerse_angebot_artikel FOREIGN KEY (magazin_artikel_id) REFERENCES magazin_artikel (id) ON DELETE RESTRICT,
    CONSTRAINT fk_boerse_angebot_sammlung FOREIGN KEY (magazin_sammlung_id) REFERENCES magazin_sammlung (id) ON DELETE RESTRICT,
    CONSTRAINT fk_boerse_angebot_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE SET NULL,
    CONSTRAINT fk_boerse_angebot_boerse FOREIGN KEY (boerse_id) REFERENCES boerse (id) ON DELETE RESTRICT,
    CONSTRAINT fk_boerse_angebot_benutzer FOREIGN KEY (angelegt_von_benutzer_id) REFERENCES benutzer (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verkauf an der Kasse (ohne Käuferdaten); Storno nur am selben Tag, der Verkauf bleibt mit storniert_am stehen.
CREATE TABLE boerse_verkauf (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    boerse_id INT UNSIGNED NOT NULL,
    summe DECIMAL(10,2) NOT NULL,
    zahlart ENUM('bar','karte_ueberweisung') NOT NULL,
    verkauft_am DATETIME NOT NULL,
    kassiert_von_benutzer_id INT UNSIGNED NULL,
    storniert_am DATETIME NULL,
    storno_grund VARCHAR(300) NULL,
    storniert_von_benutzer_id INT UNSIGNED NULL,
    INDEX idx_boerse_verkauf_boerse (boerse_id, storniert_am),
    INDEX idx_boerse_verkauf_kassiert (kassiert_von_benutzer_id),
    INDEX idx_boerse_verkauf_storniert (storniert_von_benutzer_id),
    CONSTRAINT fk_boerse_verkauf_boerse FOREIGN KEY (boerse_id) REFERENCES boerse (id) ON DELETE RESTRICT,
    CONSTRAINT fk_boerse_verkauf_kassiert FOREIGN KEY (kassiert_von_benutzer_id) REFERENCES benutzer (id) ON DELETE SET NULL,
    CONSTRAINT fk_boerse_verkauf_storniert FOREIGN KEY (storniert_von_benutzer_id) REFERENCES benutzer (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Position eines Verkaufs: Herkunft, Person, Spende und Provision werden beim Verkauf festgeschrieben (spätere
-- Änderungen am Angebot oder an der Börse ändern die Abrechnung nicht).
CREATE TABLE boerse_verkauf_position (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    verkauf_id INT UNSIGNED NOT NULL,
    angebot_id INT UNSIGNED NOT NULL,
    preis DECIMAL(10,2) NOT NULL,
    provision DECIMAL(10,2) NOT NULL,
    herkunft ENUM('verein','mitglied') NOT NULL,
    person_id INT UNSIGNED NULL,
    erloes_spende TINYINT(1) NOT NULL,
    INDEX idx_boerse_position_verkauf (verkauf_id),
    INDEX idx_boerse_position_angebot (angebot_id),
    INDEX idx_boerse_position_person (person_id),
    CONSTRAINT fk_boerse_position_verkauf FOREIGN KEY (verkauf_id) REFERENCES boerse_verkauf (id) ON DELETE CASCADE,
    CONSTRAINT fk_boerse_position_angebot FOREIGN KEY (angebot_id) REFERENCES boerse_angebot (id) ON DELETE RESTRICT,
    CONSTRAINT fk_boerse_position_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auszahlung an ein Mitglied je Börse (Haken „ausgezahlt“ mit Betrag und Datum).
CREATE TABLE boerse_auszahlung (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    boerse_id INT UNSIGNED NOT NULL,
    person_id INT UNSIGNED NULL,
    betrag DECIMAL(10,2) NOT NULL,
    ausgezahlt_am DATE NOT NULL,
    vermerkt_von_benutzer_id INT UNSIGNED NULL,
    UNIQUE KEY uq_boerse_auszahlung (boerse_id, person_id),
    INDEX idx_boerse_auszahlung_person (person_id),
    INDEX idx_boerse_auszahlung_benutzer (vermerkt_von_benutzer_id),
    CONSTRAINT fk_boerse_auszahlung_boerse FOREIGN KEY (boerse_id) REFERENCES boerse (id) ON DELETE CASCADE,
    CONSTRAINT fk_boerse_auszahlung_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE SET NULL,
    CONSTRAINT fk_boerse_auszahlung_benutzer FOREIGN KEY (vermerkt_von_benutzer_id) REFERENCES benutzer (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ausgang aus der Kasse verweist auf die Verkaufsposition (Spalte aus 016_magazin).
ALTER TABLE magazin_buchung ADD CONSTRAINT fk_magazin_buchung_position FOREIGN KEY (verkauf_position_id) REFERENCES boerse_verkauf_position (id) ON DELETE SET NULL;
