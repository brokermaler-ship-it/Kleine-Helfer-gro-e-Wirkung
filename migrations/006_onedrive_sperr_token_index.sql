-- Stufe 1b: Index für die Beanspruchung der OneDrive-Aufträge (MySQL >= 8 / MariaDB >= 10.6)

ALTER TABLE onedrive_auftrag ADD INDEX idx_onedrive_auftrag_sperr_token (sperr_token);
