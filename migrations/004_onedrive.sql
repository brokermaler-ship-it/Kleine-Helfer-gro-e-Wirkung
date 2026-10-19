-- Stufe 1b: Dateiablage in OneDrive (MySQL >= 8 / MariaDB >= 10.6)

ALTER TABLE dokument
    ADD COLUMN ablage ENUM('lokal','onedrive') NOT NULL DEFAULT 'lokal',
    ADD COLUMN onedrive_item_id VARCHAR(100) NULL,
    ADD COLUMN onedrive_pfad VARCHAR(500) NULL,
    ADD COLUMN original_lokal TINYINT(1) NOT NULL DEFAULT 1;

CREATE TABLE onedrive_auftrag (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    art ENUM('hochladen','loeschen') NOT NULL,
    dokument_id INT UNSIGNED NULL,
    item_id VARCHAR(100) NULL,
    status ENUM('wartet','erledigt','fehler') NOT NULL DEFAULT 'wartet',
    versuche INT NOT NULL DEFAULT 0,
    letzter_fehler VARCHAR(500) NULL,
    naechster_versuch DATETIME NOT NULL,
    gesperrt_bis DATETIME NULL,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_onedrive_auftrag_status (status, naechster_versuch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
