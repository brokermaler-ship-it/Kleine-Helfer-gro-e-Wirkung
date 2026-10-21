-- Version 0.5.0: Wartungsprotokoll und Ausnahmen im Wartungsplan (MySQL >= 8 / MariaDB >= 10.6).
-- Objektbezug über objekt_typ + objekt_id (fahrzeug, anlage); beim Löschen eines Objekts räumt die Anwendung auf.
-- betriebsstunden: eingegebener Stand (optional); stundenstand_bezug: eingegebener Stand, sonst der Stand des
-- Fahrzeugs beim Eintragen (Bezug für die Fälligkeit nach Stunden; bei Anlagen NULL).
-- Personenbezüge werden bei der DSGVO-Löschung per ON DELETE SET NULL geleert (Anzeige „ehemaliges Mitglied“).
-- Fotos hängen über dokument_zuordnung mit objekt_typ = 'wartung' an der Wartung.

CREATE TABLE wartung (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    objekt_typ ENUM('fahrzeug','anlage') NOT NULL,
    objekt_id INT UNSIGNED NOT NULL,
    datum DATE NOT NULL,
    betriebsstunden DECIMAL(8,1) NULL,
    stundenstand_bezug DECIMAL(8,1) NULL,
    ausgefuehrt_von_person_id INT UNSIGNED NULL,
    notiz TEXT NULL,
    erfasst_von_benutzer_id INT UNSIGNED NULL,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_wartung_objekt (objekt_typ, objekt_id, datum),
    CONSTRAINT fk_wartung_person FOREIGN KEY (ausgefuehrt_von_person_id) REFERENCES person (id) ON DELETE SET NULL,
    CONSTRAINT fk_wartung_benutzer FOREIGN KEY (erfasst_von_benutzer_id) REFERENCES benutzer (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wartung_art (
    wartung_id INT UNSIGNED NOT NULL,
    wartungsart_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (wartung_id, wartungsart_id),
    INDEX idx_wartung_art_art (wartungsart_id),
    CONSTRAINT fk_wartung_art_wartung FOREIGN KEY (wartung_id) REFERENCES wartung (id) ON DELETE CASCADE,
    CONSTRAINT fk_wartung_art_art FOREIGN KEY (wartungsart_id) REFERENCES wartungsart (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ausnahmen je Objekt: aus = 1 schaltet die Art ab; sonst ersetzt das eigene Intervall beide Werte der Art
-- (NULL = Kriterium entfällt, mindestens eines ist gesetzt – geprüft in der Anwendung).
CREATE TABLE wartungsplan_ausnahme (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    objekt_typ ENUM('fahrzeug','anlage') NOT NULL,
    objekt_id INT UNSIGNED NOT NULL,
    wartungsart_id INT UNSIGNED NOT NULL,
    aus TINYINT(1) NOT NULL DEFAULT 0,
    intervall_monate SMALLINT UNSIGNED NULL,
    intervall_stunden SMALLINT UNSIGNED NULL,
    UNIQUE KEY uq_ausnahme (objekt_typ, objekt_id, wartungsart_id),
    CONSTRAINT fk_ausnahme_art FOREIGN KEY (wartungsart_id) REFERENCES wartungsart (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
