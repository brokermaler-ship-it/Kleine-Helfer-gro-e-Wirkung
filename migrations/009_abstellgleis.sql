-- Version 0.4.0: Abstellgleis – Datum, seit dem ein Fahrzeug ausgemustert ist (MySQL >= 8 / MariaDB >= 10.6).
-- Vorhandene ausgemusterte Fahrzeuge bekommen als Näherung den Tag ihrer letzten Änderung;
-- geaendert_am = geaendert_am verhindert, dass ON UPDATE den Zeitstempel dabei neu setzt.

ALTER TABLE fahrzeug ADD COLUMN ausgemustert_am DATE NULL;

UPDATE fahrzeug SET ausgemustert_am = DATE(geaendert_am), geaendert_am = geaendert_am WHERE status = 'ausgemustert';
