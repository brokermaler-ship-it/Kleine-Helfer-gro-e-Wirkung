# EMC 78 Halle – Fahrzeugverwaltung

Webanwendung des Modelleisenbahnclubs EMC 78 Halle e. V. zur Verwaltung des Fahrzeugbestands: Fahrzeuge, Decoder (Adressen, Funktionstasten, CVs), Fotos und Dokumente, Personen und Benutzer. Die Anwendung läuft auf normalem PHP-Webspace und ist für PC, Tablet und Handy gedacht.

## Voraussetzungen

- PHP ab Version 8.2 mit den Erweiterungen `pdo_mysql`, `gd`, `fileinfo` und `zip`
- MySQL ab Version 8 oder MariaDB ab Version 10.6
- HTTPS (Pflicht, weil Anmeldungen darüber laufen)
- FTP-Zugang zum Webspace und Zugang zur Datenbankverwaltung des Hosters (z. B. phpMyAdmin)

## Installation

Eine Anleitung mit den Einzelheiten für STRATO folgt. Allgemein gilt für jeden Webspace:

1. Die Datei `emc78-verwaltung-<Version>.zip` auf dem eigenen Computer entpacken.
2. Alle entpackten Dateien und Ordner per FTP hochladen (z. B. in ein eigenes Verzeichnis oder eine Subdomain).
3. Im Menü des Hosters eine **leere Datenbank** mit Benutzer und Passwort anlegen und die Zugangsdaten notieren.
4. Die Datei `config.example.php` als `config.php` kopieren und ausfüllen: Datenbank-DSN (`mysql:host=...;dbname=...;charset=utf8mb4`), Benutzer, Passwort, `basis_url` (leer lassen, wenn die Anwendung im Hauptverzeichnis der Domain liegt, sonst z. B. `/verwaltung`).
5. Wenn möglich, im Hostermenü den **Document-Root auf den Ordner `public/`** legen. Das ist die sicherste Einstellung. Geht das nicht, leitet die mitgelieferte `.htaccess` im Hauptordner alles nach `public/` um und sperrt die übrigen Ordner (`src`, `storage`, `vendor` …).
6. Die Adresse der Seite im Browser aufrufen. Es erscheint automatisch die Seite `/install`: Dort den ersten Administrator anlegen. Die Datenbank wird dabei eingerichtet.

Hinweise:

- **Fehler 500 direkt nach dem Hochladen?** Manche Hoster erlauben die Anweisung `Options` in `.htaccess` nicht. Dann in der Datei `public/.htaccess` die Zeile `Options -Indexes` entfernen.
- Die Einstellung `cookie_secure` in der `config.php` muss im Betrieb auf `true` bleiben (HTTPS). Nur für lokale Tests ohne HTTPS darf sie `false` sein.
- Der Ordner `storage/` muss für PHP beschreibbar sein (hier liegen die hochgeladenen Dateien). Er ist per `.htaccess` gegen direkten Abruf gesperrt; Dateien werden nur nach Anmeldung über die Anwendung ausgeliefert.
- Die maximale Upload-Größe richtet sich nach `upload_max_mb` in der `config.php` und den PHP-Grenzen des Hosters (`upload_max_filesize`, `post_max_size`).

## Update

1. Als Administrator zuerst eine Sicherung erstellen (Verwaltung → Sicherung).
2. Das neue ZIP entpacken und die Dateien per FTP hochladen, bestehende Dateien überschreiben. **Dabei `config.php` und den Ordner `storage/` nicht überschreiben oder löschen** (das neue ZIP enthält beide nicht).
3. Als Administrator anmelden. Gibt es Datenbank-Änderungen, erscheint oben der Hinweis „Es steht ein Datenbank-Update an.“ Dort auf den Link klicken oder **Verwaltung → Update** öffnen und „Datenbank jetzt aktualisieren“ wählen.

Solange ein Datenbank-Update aussteht, sehen alle anderen Benutzer eine Wartungsseite. Nach dem Update ist alles wieder normal nutzbar.

## Sicherung und Wiederherstellung

**Sicherung:** Als Administrator unter **Verwaltung → Sicherung** auf „Sicherung herunterladen“ klicken. Man erhält eine ZIP-Datei mit

- `datenbank.sql` – alle Daten der Datenbank,
- `dateien/` – alle hochgeladenen Fotos und Dokumente,
- `info.txt` – Datum, Programmversion und letzte Datenbank-Änderung.

Die ZIP-Datei bitte an einem sicheren Ort außerhalb des Webspace aufbewahren. Ist die letzte Sicherung älter als 30 Tage, erinnert die Anwendung die Administratoren daran.

**Wiederherstellung:**

1. Die Anwendung wie unter „Installation“ auf dem Webspace bereitstellen (Schritte 1–5), aber `/install` nicht aufrufen.
2. Das Sicherungs-ZIP entpacken.
3. `datenbank.sql` im Verwaltungswerkzeug des Hosters (phpMyAdmin → Importieren) in die leere Datenbank einspielen. Bestehende Tabellen mit gleichem Namen werden dabei ersetzt.
4. Den Ordner `dateien/` per FTP nach `storage/dateien/` hochladen.
5. Die Anwendung aufrufen und wie gewohnt anmelden.

## Entwicklung

Voraussetzungen: PHP ≥ 8.2, Composer, MariaDB oder MySQL.

```bash
composer install
# Testdatenbank anlegen (einmalig)
mysql -u root -e "CREATE DATABASE emc_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
# Tests ausführen (Zugangsdaten über Umgebungsvariablen anpassbar)
TEST_DB_PASSWORT=geheim vendor/bin/phpunit
# Lokal starten (config.php aus config.example.php erstellen, cookie_secure auf false)
php -S localhost:8080 -t public
```

Umgebungsvariablen für die Tests: `TEST_DB_DSN` (Standard `mysql:host=127.0.0.1;dbname=emc_test;charset=utf8mb4`), `TEST_DB_BENUTZER` (Standard `root`), `TEST_DB_PASSWORT`. Ein Test der Wiederherstellung legt kurzzeitig die Datenbank `emc_test_wiederherstellung` an und löscht sie wieder; der Datenbankbenutzer braucht dafür das Recht `CREATE`/`DROP`.

**Datenbank-Änderungen** erfolgen ausschließlich als neue, aufsteigend nummerierte Dateien in `migrations/` (z. B. `003_xyz.sql`). Bereits veröffentlichte Dateien werden nicht mehr geändert.

**Release bauen:**

```bash
bash bin/release.sh
```

Das Skript baut aus dem aktuellen Stand von `HEAD` (nur eingecheckte Dateien) im Ordner `build/` ein Paket ohne Tests und Entwicklungsdateien, installiert die Produktions-Abhängigkeiten und erzeugt `dist/emc78-verwaltung-<Version>.zip`. Die Version steht in der Datei `VERSION`.
