-- Version 0.5.0: Kennwort vergessen (MySQL >= 8 / MariaDB >= 10.6).
-- kennwort_stand: wird beim Zurücksetzen erhöht; Sitzungen mit älterem Stand enden.
-- kennwort_token: nur der SHA-256-Hash des Tokens, 60 Minuten gültig, einmal verwendbar.
-- kennwort_anfrage: Rückfall ohne E-Mail-Versand – Administratoren setzen ein Startkennwort.
-- kennwort_versuch: Begrenzung je E-Mail und IP, beide nur gehasht, nach 24 Stunden gelöscht.

ALTER TABLE benutzer ADD COLUMN kennwort_stand INT UNSIGNED NOT NULL DEFAULT 0;

CREATE TABLE kennwort_token (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    benutzer_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    gueltig_bis DATETIME NOT NULL,
    verwendet_am DATETIME NULL,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_kennwort_token_hash (token_hash),
    INDEX idx_kennwort_token_gueltig (gueltig_bis),
    CONSTRAINT fk_kennwort_token_benutzer FOREIGN KEY (benutzer_id) REFERENCES benutzer (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE kennwort_anfrage (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    benutzer_id INT UNSIGNED NOT NULL,
    erstellt_am DATETIME NOT NULL,
    erledigt_am DATETIME NULL,
    erledigt_von_benutzer_id INT UNSIGNED NULL,
    INDEX idx_kennwort_anfrage_erledigt (erledigt_am),
    CONSTRAINT fk_kennwort_anfrage_benutzer FOREIGN KEY (benutzer_id) REFERENCES benutzer (id) ON DELETE CASCADE,
    CONSTRAINT fk_kennwort_anfrage_erledigt_von FOREIGN KEY (erledigt_von_benutzer_id) REFERENCES benutzer (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE kennwort_versuch (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    zeitpunkt DATETIME NOT NULL,
    INDEX idx_kennwort_versuch_email (email_hash, zeitpunkt),
    INDEX idx_kennwort_versuch_ip (ip_hash, zeitpunkt),
    INDEX idx_kennwort_versuch_zeit (zeitpunkt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
