-- Version 0.8.0: Börsenwart und Magazin (MySQL >= 8 / MariaDB >= 10.6).
-- ist_boersenwart: Zusatzaufgabe am Benutzerkonto, unabhängig von der Rolle (Bearbeiter und Admin dürfen das ohnehin).
-- Vorhandene Benutzer erhalten 0.
ALTER TABLE benutzer ADD COLUMN ist_boersenwart TINYINT(1) NOT NULL DEFAULT 0;

-- Kategorien des Magazins; schluessel markiert die Kategorien, an denen feste Logik hängt (fahrzeuge, literatur,
-- sonstiges) – sie lassen sich umbenennen, aber nicht löschen.
CREATE TABLE magazin_kategorie (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    schluessel VARCHAR(30) NULL,
    sortierung INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_magazin_kategorie_name (name),
    UNIQUE KEY uq_magazin_kategorie_schluessel (schluessel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO magazin_kategorie (name, schluessel, sortierung) VALUES
    ('Gleise und Weichen', NULL, 10),
    ('Fahrzeuge', 'fahrzeuge', 20),
    ('Elektrik und Digital', NULL, 30),
    ('Gebäude und Bausätze', NULL, 40),
    ('Landschaft und Figuren', NULL, 50),
    ('Ersatzteile', NULL, 60),
    ('Werkzeug', NULL, 70),
    ('Literatur', 'literatur', 80),
    ('Sonstiges', 'sonstiges', 90);

-- Artikel des Magazins; Code M<id>. Der Bestand wird nie gespeichert, sondern als SUM(magazin_buchung.menge) gelesen.
-- Artikel werden nicht gelöscht. fahrzeug_id: Fahrzeug vom Abstellgleis (höchstens ein Artikel je Fahrzeug).
-- titelbild_dokument_id ohne Fremdschlüssel (wie bei Fahrzeugen); Fotos hängen über dokument_zuordnung
-- mit objekt_typ = 'magazin_artikel'.
CREATE TABLE magazin_artikel (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    bezeichnung VARCHAR(200) NOT NULL,
    kategorie_id INT UNSIGNED NOT NULL,
    hersteller VARCHAR(100) NULL,
    artikelnummer VARCHAR(60) NULL,
    spurweite_id INT UNSIGNED NULL,
    zustand ENUM('neu','neuwertig','gebraucht','defekt') NOT NULL DEFAULT 'gebraucht',
    lagerort VARCHAR(100) NULL,
    notiz TEXT NULL,
    autor_verlag VARCHAR(200) NULL,
    jahr SMALLINT UNSIGNED NULL,
    isbn VARCHAR(20) NULL,
    fahrzeug_id INT UNSIGNED NULL,
    titelbild_dokument_id INT UNSIGNED NULL,
    angelegt_von_benutzer_id INT UNSIGNED NULL,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    geaendert_am DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_magazin_artikel_fahrzeug (fahrzeug_id),
    INDEX idx_magazin_artikel_kategorie (kategorie_id),
    INDEX idx_magazin_artikel_lagerort (lagerort),
    INDEX idx_magazin_artikel_spurweite (spurweite_id),
    INDEX idx_magazin_artikel_angelegt_von (angelegt_von_benutzer_id),
    CONSTRAINT fk_magazin_artikel_kategorie FOREIGN KEY (kategorie_id) REFERENCES magazin_kategorie (id) ON DELETE RESTRICT,
    CONSTRAINT fk_magazin_artikel_spurweite FOREIGN KEY (spurweite_id) REFERENCES spurweite (id) ON DELETE SET NULL,
    CONSTRAINT fk_magazin_artikel_fahrzeug FOREIGN KEY (fahrzeug_id) REFERENCES fahrzeug (id) ON DELETE SET NULL,
    CONSTRAINT fk_magazin_artikel_benutzer FOREIGN KEY (angelegt_von_benutzer_id) REFERENCES benutzer (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sammlung: Bündel mehrerer Artikel (z. B. ein Nachlass); ein Artikel gehört höchstens zu einer Sammlung.
CREATE TABLE magazin_sammlung (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    notiz TEXT NULL,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    geaendert_am DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE magazin_sammlung_artikel (
    sammlung_id INT UNSIGNED NOT NULL,
    artikel_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (sammlung_id, artikel_id),
    UNIQUE KEY uq_magazin_sammlung_artikel (artikel_id),
    CONSTRAINT fk_sammlung_artikel_sammlung FOREIGN KEY (sammlung_id) REFERENCES magazin_sammlung (id) ON DELETE CASCADE,
    CONSTRAINT fk_sammlung_artikel_artikel FOREIGN KEY (artikel_id) REFERENCES magazin_artikel (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Buchungen sind unveränderlich; Korrektur nur per Gegenbuchung (gleiche Art, Menge negiert, höchstens eine je Buchung).
-- menge vorzeichenbehaftet: Eingang positiv, Ausgang negativ, Umlagerung 0. herkunft nur bei Eingang, ausgang_art nur
-- bei Ausgang; freitext „Spende von …“ bzw. „an wen“; betrag Wert bzw. Betrag; lagerort_neu nur bei Umlagerung.
-- verkauf_position_id: Ausgang aus der Börsenkasse (Fremdschlüssel folgt mit 017_boerse).
CREATE TABLE magazin_buchung (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    artikel_id INT UNSIGNED NOT NULL,
    art ENUM('eingang','ausgang','umlagerung') NOT NULL,
    datum DATE NOT NULL,
    menge INT NOT NULL,
    herkunft ENUM('spende','nachlass','kauf','fund','sonstiges') NULL,
    ausgang_art ENUM('gegen_spende','verkauft_boerse','verschrottet','ausgeliehen','sonstiges') NULL,
    freitext VARCHAR(200) NULL,
    betrag DECIMAL(10,2) NULL,
    lagerort_neu VARCHAR(100) NULL,
    gegenbuchung_zu_id INT UNSIGNED NULL,
    verkauf_position_id INT UNSIGNED NULL,
    benutzer_id INT UNSIGNED NULL,
    erstellt_am DATETIME NOT NULL,
    UNIQUE KEY uq_magazin_buchung_gegenbuchung (gegenbuchung_zu_id),
    INDEX idx_magazin_buchung_artikel (artikel_id, datum),
    INDEX idx_magazin_buchung_position (verkauf_position_id),
    INDEX idx_magazin_buchung_benutzer (benutzer_id),
    CONSTRAINT fk_magazin_buchung_artikel FOREIGN KEY (artikel_id) REFERENCES magazin_artikel (id) ON DELETE RESTRICT,
    CONSTRAINT fk_magazin_buchung_gegenbuchung FOREIGN KEY (gegenbuchung_zu_id) REFERENCES magazin_buchung (id) ON DELETE SET NULL,
    CONSTRAINT fk_magazin_buchung_benutzer FOREIGN KEY (benutzer_id) REFERENCES benutzer (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
