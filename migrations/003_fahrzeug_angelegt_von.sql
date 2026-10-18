-- Stufe 2: Wer hat das Fahrzeug angelegt? (MySQL >= 8 / MariaDB >= 10.6)
-- Bestehende Fahrzeuge bleiben NULL und gelten als von einem Bearbeiter angelegt.

ALTER TABLE fahrzeug
    ADD COLUMN angelegt_von_benutzer_id INT UNSIGNED NULL,
    ADD INDEX idx_fahrzeug_angelegt_von (angelegt_von_benutzer_id),
    ADD CONSTRAINT fk_fahrzeug_angelegt_von FOREIGN KEY (angelegt_von_benutzer_id) REFERENCES benutzer (id) ON DELETE SET NULL;
