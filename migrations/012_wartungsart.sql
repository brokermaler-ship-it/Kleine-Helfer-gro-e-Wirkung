-- Version 0.5.0: Wartungsarten (Stammliste) mit Startbestand (MySQL >= 8 / MariaDB >= 10.6).
-- gilt_fuer: fahrzeug_alle, fahrzeug_antrieb (Lok und Triebwagen), fahrzeug_wagen, anlage.
-- Mindestens eines der Intervalle ist gesetzt (geprüft in der Anwendung); Anlagen haben keine Betriebsstunden.

CREATE TABLE wartungsart (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    gilt_fuer ENUM('fahrzeug_alle','fahrzeug_antrieb','fahrzeug_wagen','anlage') NOT NULL,
    intervall_monate SMALLINT UNSIGNED NULL,
    intervall_stunden SMALLINT UNSIGNED NULL,
    aktiv TINYINT(1) NOT NULL DEFAULT 1,
    sortierung INT NOT NULL DEFAULT 0,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wartungsart_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO wartungsart (name, gilt_fuer, intervall_monate, intervall_stunden, sortierung) VALUES
    ('Räder und Radschleifer reinigen', 'fahrzeug_alle', 6, 20, 10),
    ('Getriebe und Lager schmieren', 'fahrzeug_antrieb', 12, 50, 20),
    ('Haftreifen prüfen/tauschen', 'fahrzeug_antrieb', 12, 50, 30),
    ('Motor/Kohlen prüfen', 'fahrzeug_antrieb', 24, 100, 40),
    ('Kupplungen prüfen', 'fahrzeug_alle', 12, NULL, 50),
    ('Gleise reinigen', 'anlage', 3, NULL, 60),
    ('Weichen und Antriebe prüfen', 'anlage', 12, NULL, 70),
    ('Verkabelung und Stecker prüfen', 'anlage', 12, NULL, 80);
