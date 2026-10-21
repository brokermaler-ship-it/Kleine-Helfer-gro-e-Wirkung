-- Version 0.5.0: einfache Anlagenliste (MySQL >= 8 / MariaDB >= 10.6).
-- nummer ergibt den Code A<n>; vergebene Nummern werden nie wiederverwendet (Zähler anlage_zaehler in einstellung).
-- Fotos und Dokumente hängen über dokument_zuordnung mit objekt_typ = 'anlage' an der Anlage.

CREATE TABLE anlage (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nummer INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    spurweite_id INT UNSIGNED NOT NULL,
    kurzbeschreibung VARCHAR(1000) NULL,
    standort VARCHAR(150) NULL,
    status ENUM('in_ordnung','defekt','in_arbeit') NOT NULL DEFAULT 'in_ordnung',
    titelbild_dokument_id INT UNSIGNED NULL,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    geaendert_am DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_anlage_nummer (nummer),
    CONSTRAINT fk_anlage_spurweite FOREIGN KEY (spurweite_id) REFERENCES spurweite (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
