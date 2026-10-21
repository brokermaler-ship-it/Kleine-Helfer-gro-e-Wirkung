-- Version 0.6.0: Etiketten (MySQL >= 8 / MariaDB >= 10.6).
-- etikett_gedruckt_am: gesetzt nur über „Als gedruckt vermerken“ (nicht beim Anzeigen der Druckseite);
-- NULL = noch kein Etikett gedruckt (Auswahl „noch nicht gedruckt“).
ALTER TABLE fahrzeug ADD COLUMN etikett_gedruckt_am DATETIME NULL;
ALTER TABLE anlage ADD COLUMN etikett_gedruckt_am DATETIME NULL;
