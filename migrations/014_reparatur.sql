-- Version 0.6.0: Reparaturen und Defektmeldungen (MySQL >= 8 / MariaDB >= 10.6).
-- Objektbezug über objekt_typ + objekt_id (fahrzeug, anlage); beim Löschen eines Objekts räumt die Anwendung auf.
-- Reparaturen werden nicht gelöscht, nur verworfen (Ausnahmen: DSGVO-Löschung eigener Fahrzeuge, Löschen des Objekts).
-- Personenbezüge werden bei der DSGVO-Löschung per ON DELETE SET NULL geleert (Anzeige „ehemaliges Mitglied“).
-- Fotos hängen über dokument_zuordnung mit objekt_typ = 'reparatur' an der Reparatur.

-- status_automatisch: 1 = der aktuelle Status wurde von einer Reparatur gesetzt (automatischer Status). Nur dann setzt
-- die Anwendung ihn zurück, wenn keine offene Reparatur ihn mehr verlangt; eine Änderung von Hand löscht das Kennzeichen.
-- Vorhandene Fahrzeuge und Anlagen gelten als von Hand gesetzt (0).
ALTER TABLE fahrzeug ADD COLUMN status_automatisch TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE anlage ADD COLUMN status_automatisch TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE reparatur (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    objekt_typ ENUM('fahrzeug','anlage') NOT NULL,
    objekt_id INT UNSIGNED NOT NULL,
    beschreibung VARCHAR(2000) NOT NULL,
    wo_bauteil VARCHAR(150) NULL,
    gemeldet_von_benutzer_id INT UNSIGNED NULL,
    gemeldet_am DATETIME NOT NULL,
    prioritaet ENUM('dringend','normal','niedrig') NOT NULL DEFAULT 'normal',
    zustaendig_person_id INT UNSIGNED NULL,
    termin DATE NULL,
    status ENUM('gemeldet','geplant','in_arbeit','wartet_auf_teile','erledigt','verworfen') NOT NULL DEFAULT 'gemeldet',
    notizen TEXT NULL,
    was_gemacht TEXT NULL,
    abgeschlossen_am DATETIME NULL,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    geaendert_am DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_reparatur_objekt (objekt_typ, objekt_id, status),
    INDEX idx_reparatur_status (status, prioritaet),
    INDEX idx_reparatur_gemeldet_von (gemeldet_von_benutzer_id),
    CONSTRAINT fk_reparatur_benutzer FOREIGN KEY (gemeldet_von_benutzer_id) REFERENCES benutzer (id) ON DELETE SET NULL,
    CONSTRAINT fk_reparatur_person FOREIGN KEY (zustaendig_person_id) REFERENCES person (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ersatzteil (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reparatur_id INT UNSIGNED NOT NULL,
    bezeichnung VARCHAR(150) NOT NULL,
    artikelnummer VARCHAR(60) NULL,
    bezugsquelle VARCHAR(150) NULL,
    kosten DECIMAL(8,2) NULL,
    bestellt TINYINT(1) NOT NULL DEFAULT 0,
    sortierung INT NOT NULL DEFAULT 0,
    INDEX idx_ersatzteil_reparatur (reparatur_id, sortierung),
    CONSTRAINT fk_ersatzteil_reparatur FOREIGN KEY (reparatur_id) REFERENCES reparatur (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wer eine Defektmeldung geöffnet hat (Hinweis „neue Defektmeldungen“).
CREATE TABLE reparatur_gesehen (
    benutzer_id INT UNSIGNED NOT NULL,
    reparatur_id INT UNSIGNED NOT NULL,
    gesehen_am DATETIME NOT NULL,
    PRIMARY KEY (benutzer_id, reparatur_id),
    INDEX idx_reparatur_gesehen_reparatur (reparatur_id),
    CONSTRAINT fk_gesehen_benutzer FOREIGN KEY (benutzer_id) REFERENCES benutzer (id) ON DELETE CASCADE,
    CONSTRAINT fk_gesehen_reparatur FOREIGN KEY (reparatur_id) REFERENCES reparatur (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
