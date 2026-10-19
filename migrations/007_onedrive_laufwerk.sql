-- Version 0.3.1: Laufwerk (drive) je Dokument und je Lösch-Auftrag merken (MySQL >= 8 / MariaDB >= 10.6).
-- Wird später ein Zielordner in einem anderen OneDrive gewählt, bleiben bereits übertragene Dateien damit erreichbar.

ALTER TABLE dokument ADD COLUMN onedrive_drive_id VARCHAR(100) NULL AFTER onedrive_item_id;

ALTER TABLE onedrive_auftrag ADD COLUMN drive_id VARCHAR(100) NULL AFTER item_id;

-- Bestehende Einträge: Laufwerk des aktuell gespeicherten Zielordners (Einstellung onedrive_ziel, JSON {drive_id,item_id,name}).
-- JSON_VALID schützt vor leerem oder ungültigem Inhalt (z. B. nach einem Kontowechsel), sonst bricht MySQL ab.
UPDATE dokument SET onedrive_drive_id = (
    SELECT CASE WHEN JSON_VALID(e.wert) THEN NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.wert, '$.drive_id')), ''), 'null') END
    FROM einstellung e WHERE e.schluessel = 'onedrive_ziel'
) WHERE ablage = 'onedrive' AND onedrive_drive_id IS NULL;

UPDATE onedrive_auftrag SET drive_id = (
    SELECT CASE WHEN JSON_VALID(e.wert) THEN NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.wert, '$.drive_id')), ''), 'null') END
    FROM einstellung e WHERE e.schluessel = 'onedrive_ziel'
) WHERE art = 'loeschen' AND drive_id IS NULL AND status IN ('wartet', 'fehler');
