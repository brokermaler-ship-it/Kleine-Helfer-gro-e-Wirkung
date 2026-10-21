-- Version 0.8.0: Indizes auf titelbild_dokument_id (MySQL >= 8 / MariaDB >= 10.6).
-- Beim Löschen eines Fotos setzt DokumentRepository::loescheDatensatz die Titelbild-Verweise per
-- UPDATE … WHERE titelbild_dokument_id = ? zurück. Ohne Index liest (und sperrt) das jede Zeile der Tabelle –
-- auch Angebote und Magazinartikel, die eine Kasse gerade gesperrt hat. Mit Index nur die passenden Zeilen.
CREATE INDEX idx_fahrzeug_titelbild ON fahrzeug (titelbild_dokument_id);
CREATE INDEX idx_anlage_titelbild ON anlage (titelbild_dokument_id);
CREATE INDEX idx_magazin_artikel_titelbild ON magazin_artikel (titelbild_dokument_id);
CREATE INDEX idx_boerse_angebot_titelbild ON boerse_angebot (titelbild_dokument_id);
