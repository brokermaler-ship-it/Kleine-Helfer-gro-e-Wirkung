# EMC 78 Lokschuppen

Webanwendung „EMC 78 Lokschuppen“ des Eisenbahn Modellbahn Clubs 78 e.V. Halle („Die Modellbahnfamilie“) zur Verwaltung des Fahrzeugbestands: Fahrzeuge, Decoder (Adressen, Funktionstasten, CVs), Fotos und Dokumente, Personen und Benutzer. Die Anwendung läuft auf normalem PHP-Webspace und ist für PC, Tablet und Handy gedacht.

## Rollen und Rechte

| Rolle | Darf |
|---|---|
| **Mitglied** | alles lesen (Fahrzeuge, Decoder, Funktionen, CVs, Dateien); **eigene Fahrzeuge anlegen** (der Eigentümer ist immer das Mitglied selbst); die **selbst angelegten Fahrzeuge bearbeiten**, einschließlich Status (auch „ausgemustert“), Decoder, Funktionstasten (Formular, CSV-Import, Vorlagen), CVs sowie Fotos und Dateien dieses Fahrzeugs. Nicht erlaubt: Eigentümer ändern, Fahrzeuge löschen, Fahrzeuge anderer bearbeiten (auch nicht als Eigentümer), Personen verwalten |
| **Bearbeiter** | zusätzlich **alle** Fahrzeuge anlegen (Eigentümer frei wählbar), bearbeiten und löschen, ebenso alle Dateien; Personen verwalten |
| **Administrator** | zusätzlich Benutzer, Stammdaten (Spurweiten), Datensicherung und Update |

Jedes Fahrzeug merkt sich, wer es angelegt hat. Fahrzeuge, die schon vor Version 0.2.0 vorhanden waren, gelten als von einem Bearbeiter angelegt; Mitglieder können sie nicht ändern. Alle Rechte werden auf dem Server für jedes einzelne Fahrzeug geprüft, nicht nur durch ausgeblendete Schaltflächen. Eine Datei, die mehreren Objekten zugeordnet ist, kann nur ein Bearbeiter löschen.

Beim Update auf 0.2.0 muss nach dem Hochladen der neuen Dateien einmal im Verwaltungsmenü unter „Update“ die Datenbank-Migration `003_fahrzeug_angelegt_von` ausgeführt werden.

## Voraussetzungen

- PHP ab Version 8.2 mit den Erweiterungen `pdo_mysql`, `mbstring`, `gd`, `fileinfo` und `zip`
- MySQL ab Version 8 oder MariaDB ab Version 10.6
- HTTPS (Pflicht, weil Anmeldungen darüber laufen)
- FTP-Zugang zum Webspace und Zugang zur Datenbankverwaltung des Hosters (z. B. phpMyAdmin)

## Installation

Allgemein gilt für jeden Webspace (die Schritte für STRATO stehen im nächsten Abschnitt):

1. Die Datei `emc78-verwaltung-<Version>.zip` auf dem eigenen Computer entpacken.
2. Alle entpackten Dateien und Ordner per FTP/SFTP hochladen (z. B. in ein eigenes Verzeichnis oder eine Subdomain).
3. Im Menü des Hosters eine **leere Datenbank** mit Benutzer und Passwort anlegen und die Zugangsdaten notieren.
4. Die Datei `config.example.php` als `config.php` kopieren und ausfüllen: Datenbank-DSN (`mysql:host=...;dbname=...;charset=utf8mb4`), Benutzer, Passwort, `basis_url` (leer lassen, wenn die Anwendung im Hauptverzeichnis der Domain liegt, sonst z. B. `/mitglieder`).
5. Wenn möglich, im Hostermenü den **Document-Root auf den Ordner `public/`** legen. Das ist die sicherste Einstellung. Geht das nicht, leitet die mitgelieferte `.htaccess` im Hauptordner alles nach `public/` um und sperrt die übrigen Ordner (`src`, `storage`, `vendor` …).
6. Die Adresse der Seite im Browser aufrufen. Es erscheint automatisch die Seite `/install`: Dort den ersten Administrator anlegen. Die Datenbank wird dabei eingerichtet.

## Installation bei STRATO als Subdomain mitglieder.emc78.de

Die Anwendung soll unter **https://mitglieder.emc78.de** erreichbar sein. Die Menünamen im STRATO-Kundenbereich ändern sich gelegentlich; die folgenden Bezeichnungen sind deshalb sinngemäß zu verstehen.

1. **PHP-Version:** Im STRATO-Kundenbereich (Menüpunkt sinngemäß „Paket-Verwaltung“ bzw. „Einstellungen“ → „PHP-Einstellungen“ oder „PHP-Version“) eine **PHP-Version ab 8.2** wählen und speichern. Falls Auswahlfelder für Erweiterungen angeboten werden: `gd`, `fileinfo`, `zip`, `mbstring` und `pdo_mysql` müssen aktiv sein (meist sind sie es schon).
2. **Datenbank:** Im Kundenbereich (Menüpunkt sinngemäß „Datenbanken und Webspace“ → „Datenbanken“ bzw. „MySQL-Datenbank“) eine **neue MySQL-Datenbank anlegen**. Notieren: Datenbankname, Benutzername, Passwort und den **Datenbank-Server** (bei STRATO ein eigener Servername, nicht `localhost`). Der Server steht in der Datenbankübersicht.
3. **SFTP-Zugang:** Im Kundenbereich (sinngemäß „SFTP-Zugang“ bzw. „FTP-Zugang“ im Bereich Webspace) einen SFTP-Zugang einrichten bzw. das Passwort setzen. Mit einem Programm wie FileZilla oder WinSCP verbinden.
4. **Dateien hochladen:** Das entpackte Verzeichnis des Release-ZIPs vollständig in einen neuen Ordner `/mitglieder/` auf dem Webspace hochladen. Es entstehen z. B. `/mitglieder/public`, `/mitglieder/src`, `/mitglieder/vendor`, `/mitglieder/storage`.
5. **Subdomain anlegen:** Im Kundenbereich (sinngemäß „Domains“ → „Subdomains“ bzw. „Domain verwalten“) die **Subdomain `mitglieder.emc78.de`** anlegen und als **Ziel-Verzeichnis `/mitglieder/public`** eintragen. Damit liegt nur der Ordner `public/` im Netz, alle anderen Ordner sind von außen nicht erreichbar. (Die Subdomain kann einige Minuten bis Stunden brauchen, bis sie erreichbar ist.)
6. **SSL aktivieren:** Im Kundenbereich (sinngemäß „Domains“ → „SSL-Verwaltung“ bzw. „SSL-Zertifikate“) für die Subdomain ein **SSL-Zertifikat aktivieren** (bei STRATO gibt es ein kostenloses Standard-Zertifikat) und zuweisen. Erst wenn `https://mitglieder.emc78.de` ohne Warnung im Browser öffnet, geht es weiter.
7. **`config.php` anlegen:** `/mitglieder/config.example.php` herunterladen, als `config.php` speichern und ausfüllen:
   - `db_dsn`: `mysql:host=<Datenbank-Server>;dbname=<Datenbankname>;charset=utf8mb4`
   - `db_benutzer` und `db_passwort`: die Zugangsdaten aus Schritt 2
   - `basis_url`: leer lassen (die Subdomain zeigt direkt auf `public/`)
   - `cookie_secure`: `true` lassen
   
   Die fertige Datei nach `/mitglieder/config.php` hochladen (nicht in `public/`).
8. **Installieren:** `https://mitglieder.emc78.de/install` aufrufen und den ersten Administrator anlegen. Danach `https://mitglieder.emc78.de` öffnen und anmelden.

Prüfen, ob der Ordner `/mitglieder/storage` für PHP beschreibbar ist (Foto hochladen). Wenn nicht, dem Ordner per SFTP-Programm die Schreibrechte `775` geben.

## Alternative: Unterordner (`basis_url` = `/mitglieder`)

Kann keine Subdomain eingerichtet werden, läuft die Anwendung auch in einem Unterordner der Hauptdomain, z. B. `https://emc78.de/mitglieder`:

1. Das entpackte Release-Verzeichnis nach `/mitglieder/` im Webspace der Hauptdomain hochladen.
2. In der `config.php` `basis_url` auf `/mitglieder` setzen.
3. Die mitgelieferte `.htaccess` im Ordner `/mitglieder/` leitet alle Aufrufe nach `public/` um und sperrt `src`, `storage`, `vendor` und die übrigen Ordner. Bitte nach dem Hochladen testen, dass z. B. `https://emc78.de/mitglieder/storage/` und `https://emc78.de/mitglieder/config.php` **nicht** abrufbar sind.
4. `https://emc78.de/mitglieder/install` aufrufen.

Die Installation auf dem Handy (siehe unten) funktioniert auch im Unterordner.

## Testumgebung test.emc78.de

Neuerungen und Beispieldaten lassen sich gefahrlos auf einer eigenen Testinstanz ausprobieren:

1. Eine **zweite, eigene Datenbank** anlegen (nie dieselbe wie im Echtbetrieb).
2. Das Release-ZIP in ein **eigenes Verzeichnis `/test/`** hochladen und die Subdomain `test.emc78.de` mit dem Ziel `/test/public` anlegen; SSL wie oben aktivieren.
3. In der `config.php` der Testinstanz die zweite Datenbank eintragen, **`'testumgebung' => true`** setzen (genau so, ohne Anführungszeichen; `'true'`, `'false'` oder `1` schalten die Testumgebung nicht ein) und unter **`'testumgebung_admin_passwort'`** ein eigenes, geheimes Passwort für den Test-Administrator eintragen (mindestens 10 Zeichen). Ohne dieses Passwort werden keine Beispieldaten geladen. Die Testinstanz zeigt dann oben eine gelbe Leiste „Testumgebung“ und auf der Anmeldeseite die Test-Zugänge für Mitglied und Bearbeiter.
4. **Testinstanz zusätzlich mit einem Verzeichnisschutz sichern:** Im STRATO-Kundenbereich (sinngemäß „Sicherheit“ bzw. „Webspace“ → „Verzeichnisschutz“) für das Verzeichnis `/test` einen Verzeichnisschutz (htaccess-Passwort) mit Benutzername und Passwort einrichten und diese nur an Vereinsmitglieder weitergeben, die testen sollen. Die Test-Zugänge stehen auf der Anmeldeseite der Testinstanz; ohne Verzeichnisschutz könnte sie jeder im Internet benutzen. Danach `https://test.emc78.de` aufrufen: Erst fragt der Browser nach dem Verzeichnisschutz-Passwort, dann erscheint die Anmeldeseite. Erscheint stattdessen ein Fehler, hat der Verzeichnisschutz eine mitgelieferte `.htaccess` ersetzt; dann deren Zeilen aus dem Release-ZIP wieder ergänzen.
5. `https://test.emc78.de/install` aufrufen und einen Administrator anlegen.
6. Beispieldaten laden, entweder
   - im Browser als Administrator unter **Verwaltung → Testdaten** mit „Beispieldaten neu laden“ oder
   - per SSH (falls das STRATO-Paket SSH bietet) im Verzeichnis `/test/`: `php bin/demo-daten.php --zuruecksetzen`

   Beides **löscht alle vorhandenen Daten der Testinstanz** und legt neu an: 6 erfundene Personen, 12 Fahrzeuge in H0 und N (Verein und Mitglieder, ein ausgemustertes), Decoder mit Funktionstasten und CVs, Beispielfotos und absichtlich **eine doppelte DCC-Adresse in H0**, an der man die Warnung der Adressprüfung sieht. Nach dem Laden im Browser wird man abgemeldet.
7. Anmelden mit `bearbeiter@test.emc78.de` oder `mitglied@test.emc78.de` (Passwort `Test-EMC78!`) oder als Administrator mit `admin@test.emc78.de` und dem Passwort aus `testumgebung_admin_passwort`. Den Admin-Zugang erfahren Tester beim Vorstand; er steht bewusst nicht auf der Anmeldeseite.

Anmeldungen gelten nur für die Instanz, an der man sich angemeldet hat: Echtbetrieb und Testinstanz speichern ihre Sitzungen getrennt (jeweils in `storage/sitzungen/`) und erkennen fremde Sitzungen, auch wenn beide im selben STRATO-Paket liegen.

`testumgebung` im Echtbetrieb **nie** auf `true` setzen; Testdaten-Seite und Test-Zugänge sind dort bewusst nicht vorhanden.

## Auf dem Handy installieren

Die Anwendung lässt sich wie eine App auf den Startbildschirm legen (sie öffnet dann ohne Adresszeile). Sie benötigt weiterhin eine Internetverbindung, damit die Daten aktuell sind.

- **Android (Chrome):** Seite öffnen, anmelden, Chrome-Menü (drei Punkte) → **„App installieren“** bzw. **„Zum Startbildschirm hinzufügen“**.
- **iPhone/iPad (Safari):** Seite in Safari öffnen, **Teilen**-Symbol → **„Zum Home-Bildschirm“**.

Das Symbol zeigt das Vereinslogo auf weißem Grund. Es wird aus `public/assets/marke/logo-512.png` mit `php bin/erzeuge-bilder.php` erzeugt (`icon-192.png`, `icon-512.png`, `icon-maskable-512.png`, `apple-touch-icon.png` in `public/assets/icons/`).

## Gestaltung anpassen

Alle Vereinsfarben stehen als CSS-Variablen ganz oben in `public/assets/app.css` (`--emc-rot`, `--emc-rot-dunkel`, `--emc-schwarz`, `--emc-grau`, `--emc-grau-hell`, dazu `--emc-rot-rgb`); Bootstrap ist dort auf diese Werte abgebildet. Logo, Slogan und Gleisgrafik liegen in `public/assets/marke/`. Den Anzeigenamen stellt `app_name` in der `config.php` ein.

## Link auf der Vereinswebseite

Die Mitgliederverwaltung ist nicht öffentlich, aber leicht erreichbar, wenn auf der Vereinswebseite emc78.de ein Verweis steht, z. B. im Menü oder in der Fußzeile:

```html
<a href="https://mitglieder.emc78.de">Mitgliederbereich (Fahrzeugverwaltung)</a>
```

Alle Inhalte sind erst nach der Anmeldung sichtbar. Benutzer legt ein Administrator unter **Verwaltung → Benutzer** an.

Hinweise:

- **Fehler 500 direkt nach dem Hochladen?** Manche Hoster erlauben die Anweisung `Options` in `.htaccess` nicht. Dann in der Datei `public/.htaccess` die Zeile `Options -Indexes` entfernen.
- Die Einstellung `cookie_secure` in der `config.php` muss im Betrieb auf `true` bleiben (HTTPS). Nur für lokale Tests ohne HTTPS darf sie `false` sein.
- Der Ordner `storage/` muss für PHP beschreibbar sein (hier liegen die hochgeladenen Dateien und unter `storage/sitzungen/` die Anmeldesitzungen). Er ist per `.htaccess` gegen direkten Abruf gesperrt; Dateien werden nur nach Anmeldung über die Anwendung ausgeliefert.
- Eine Anmeldung verfällt nach `sitzung_minuten` (Standard 480 = 8 Stunden) ohne Aufruf und spätestens beim Schließen des Browsers.
- Die maximale Upload-Größe richtet sich nach `upload_max_mb` in der `config.php` und den PHP-Grenzen des Hosters (`upload_max_filesize`, `post_max_size`).

## Update

1. Als Administrator zuerst eine Sicherung erstellen (Verwaltung → Sicherung).
2. Das neue ZIP entpacken und die Dateien per FTP hochladen, bestehende Dateien überschreiben. **Dabei `config.php` und den Ordner `storage/` nicht löschen.** Das ZIP enthält keine `config.php` und keine hochgeladenen Dateien. Es enthält nur `storage/.htaccess`, `storage/dateien/.gitkeep` und `storage/sitzungen/.gitkeep`; diese drei Dateien beim Hochladen zu überschreiben ist harmlos, vorhandene Fotos, Dokumente und Sitzungen bleiben erhalten.
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
4. Den Ordner `dateien` aus dem entpackten ZIP per FTP **in den Ordner `storage/` hinein** hochladen, sodass z. B. `storage/dateien/2026/…` entsteht (nicht `storage/dateien/dateien/…`).
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

Das Skript baut aus dem aktuellen Stand von `HEAD` (nur eingecheckte Dateien) im Ordner `build/` ein Paket ohne Tests (nur die Beispielfotos der Testumgebung liegen bei) und Entwicklungsdateien, installiert die Produktions-Abhängigkeiten und erzeugt `dist/emc78-verwaltung-<Version>.zip`. Die Version steht in der Datei `VERSION`.

Die App-Symbole (`public/assets/icons/`, aus dem Vereinslogo) und die Beispielfotos (`tests/fixtures/demo/`, Platzhalter) sind mit `php bin/erzeuge-bilder.php` erzeugt und eingecheckt; das Skript muss nur laufen, wenn sich das Aussehen ändern soll.
