-- Version 0.4.0: neue Rolle Fahrzeugwart zwischen Mitglied und Bearbeiter (MySQL >= 8 / MariaDB >= 10.6).
-- Bestehende Konten behalten ihre Rolle.

ALTER TABLE benutzer MODIFY rolle ENUM('mitglied','fahrzeugwart','bearbeiter','admin') NOT NULL DEFAULT 'mitglied';
