-- Version 0.8.0: Anfragen aus dem öffentlichen Bereich /boerse (MySQL >= 8 / MariaDB >= 10.6).
-- Kaufanfrage zu einem Angebot oder „Sammlung anbieten“ (mit Fotos über dokument_zuordnung, objekt_typ
-- 'boerse_anfrage'; diese Fotos gehen nie nach OneDrive). Anfragen samt Fotos werden 12 Monate nach Eingang
-- automatisch gelöscht (AnfrageDienst::raeumeAuf, bin/boerse-aufraeumen.php). Protokolleinträge enthalten nie
-- Name, E-Mail oder Telefon.
CREATE TABLE boerse_anfrage (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    art ENUM('kaufanfrage','sammlung') NOT NULL,
    angebot_id INT UNSIGNED NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(190) NULL,
    telefon VARCHAR(40) NULL,
    nachricht TEXT NOT NULL,
    wunsch ENUM('verkaufen','spenden') NULL,
    einwilligung_am DATETIME NOT NULL,
    eingegangen_am DATETIME NOT NULL,
    status ENUM('offen','in_bearbeitung','erledigt') NOT NULL DEFAULT 'offen',
    bearbeitet_von_benutzer_id INT UNSIGNED NULL,
    magazin_artikel_id INT UNSIGNED NULL,
    geaendert_am DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    INDEX idx_boerse_anfrage_status (status),
    INDEX idx_boerse_anfrage_eingang (eingegangen_am),
    INDEX idx_boerse_anfrage_angebot (angebot_id),
    INDEX idx_boerse_anfrage_benutzer (bearbeitet_von_benutzer_id),
    INDEX idx_boerse_anfrage_artikel (magazin_artikel_id),
    CONSTRAINT fk_boerse_anfrage_angebot FOREIGN KEY (angebot_id) REFERENCES boerse_angebot (id) ON DELETE SET NULL,
    CONSTRAINT fk_boerse_anfrage_benutzer FOREIGN KEY (bearbeitet_von_benutzer_id) REFERENCES benutzer (id) ON DELETE SET NULL,
    CONSTRAINT fk_boerse_anfrage_artikel FOREIGN KEY (magazin_artikel_id) REFERENCES magazin_artikel (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Begrenzung je IP-Adresse: nur der HMAC-Hash (Salz boerse_ip_salz in einstellung), Zeilen nach 24 Stunden gelöscht.
CREATE TABLE boerse_anfrage_sperre (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip_hash CHAR(64) NOT NULL,
    zeitpunkt DATETIME NOT NULL,
    INDEX idx_boerse_sperre_ip (ip_hash, zeitpunkt),
    INDEX idx_boerse_sperre_zeit (zeitpunkt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
