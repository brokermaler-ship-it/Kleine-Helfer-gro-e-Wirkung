-- Version 0.8.1: Fahrzeug-Import aus einer CSV-Liste (MySQL >= 8 / MariaDB >= 10.6).
-- „ungeprüft“: importierte Fahrzeuge, die noch jemand ansehen soll (Filter in der Fahrzeugliste, Knopf
-- „Als geprüft markieren“). Vorhandene Fahrzeuge gelten als geprüft.
-- fahrzeug_import: jede importierte Datei (SHA-256 über den Rohinhalt) nur einmal; das Konto darf gelöscht werden
-- (DSGVO-Löschung), dann bleibt der Eintrag ohne Bezug.
ALTER TABLE fahrzeug
    ADD COLUMN ungeprueft TINYINT(1) NOT NULL DEFAULT 0,
    ADD INDEX idx_fahrzeug_ungeprueft (ungeprueft);

CREATE TABLE fahrzeug_import (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    datei_sha256 CHAR(64) NOT NULL,
    dateiname VARCHAR(190) NOT NULL,
    anzahl INT UNSIGNED NOT NULL,
    benutzer_id INT UNSIGNED NULL,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fahrzeug_import_sha256 (datei_sha256),
    INDEX idx_fahrzeug_import_benutzer (benutzer_id),
    CONSTRAINT fk_fahrzeug_import_benutzer FOREIGN KEY (benutzer_id) REFERENCES benutzer (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
