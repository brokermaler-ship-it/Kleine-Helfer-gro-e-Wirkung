-- Stufe 1b: atomare Beanspruchung der OneDrive-Aufträge (MySQL >= 8 / MariaDB >= 10.6)

ALTER TABLE onedrive_auftrag
    ADD COLUMN sperr_token VARCHAR(32) NULL AFTER gesperrt_bis,
    ADD INDEX idx_onedrive_auftrag_dokument (dokument_id, art, status);
