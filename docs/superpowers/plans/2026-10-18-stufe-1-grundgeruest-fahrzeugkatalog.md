# Stufe 1 – Grundgerüst & Fahrzeugkatalog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lauffähige, auf normalem PHP-Webspace installierbare Web-App mit Login, Rollen, Personen, Spurweiten und vollständigem Fahrzeugkatalog (Decoder, Adressprüfung, Funktionstasten mit CSV-Import und Vorlagen, CVs, Fotos/Dokumente), Datenbank-Updates und Datensicherung.

**Architecture:** Schlankes PHP ohne Framework: ein Front-Controller (`public/index.php`) mit kleinem Router, serverseitig gerenderte PHP-Templates mit Bootstrap 5, Fachlogik je Bereich in `src/<Bereich>/` (Repository + Validator/Service + Controller). Datenbankzugriff über PDO mit vorbereiteten Abfragen, Schemaänderungen über nummerierte SQL-Migrationen. Uploads liegen in `storage/` außerhalb des öffentlichen Ordners und werden nur über die App mit Rechteprüfung ausgeliefert.

**Tech Stack:** PHP 8.2+, MySQL 8 / MariaDB 10.6+, PDO, GD, fileinfo, Bootstrap 5.3.3 (lokal), PHPUnit 11 (nur Entwicklung), Composer (nur Autoload/Dev; `vendor/` im Release-Paket).

**Spec:** `docs/superpowers/specs/2026-10-18-emc78-fahrzeug-anlagen-verwaltung-design.md` (Abschnitte 2, 3, 4.1, 4.7 Grundfunktion, 5; Stufe 1 aus Abschnitt 6)

## Global Constraints

- PHP ≥ 8.2: keine Sprachfeatures/Funktionen erst ab 8.3 (z. B. kein `json_validate`, keine typisierten Klassenkonstanten).
- SQL muss auf MySQL ≥ 8 **und** MariaDB ≥ 10.6 laufen: InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`, keine JSON-Spaltenfunktionen (JSON als `LONGTEXT` speichern).
- Keine Laufzeit-Abhängigkeiten über Composer in Stufe 1; Composer nur für PSR-4-Autoload (`Emc\` → `src/`) und PHPUnit (dev).
- Kein CDN, kein Build-Schritt: Bootstrap 5.3.3 (CSS + bundle JS) liegt unter `public/assets/vendor/bootstrap/`.
- Oberfläche vollständig deutsch, responsive (PC, Tablet, Handy).
- Standardwerte: Stromsystem **2-Leiter** (`2L`, wählbar `3L`); Protokoll **DCC** (wählbar Motorola, mfx, Selectrix).
- Inventarnummer automatisch im Format `EMC-0001`.
- Adressen eindeutig **im ganzen Verein je Spurweite und Protokoll**; Konflikt = **Warnung**, Speichern bleibt möglich.
- Adressbereiche: DCC 1–10239 (≤ 127 „kurz“, sonst „lang“); Motorola 1–255; Selectrix 0–111; mfx ohne Pflichtadresse (falls angegeben 1–16383).
- Funktionstasten F0–F31; CSV-Vorlage `Taste;Beschreibung;Schaltart`.
- Rollen: `mitglied` (alles lesen), `bearbeiter` (+ Fahrzeuge, Personen, Dateien bearbeiten), `admin` (+ Benutzer, Stammlisten, Datensicherung, Updates).
- Alle Inhalte nur nach Anmeldung; jede Änderung an Fahrzeug, Decoder, Person, Benutzer wird im Änderungsprotokoll erfasst.
- Uploads: Positivliste, Endung **und** Inhalt geprüft, nie ausführbar, Ablage in `storage/`, Auslieferung nur über die App.
- Unterscheidung **Fehler** (verhindert Speichern) und **Warnung** (Speichern möglich); deutsche Meldungen am Feld.
- Sicherungshinweis, wenn letzte Sicherung > 30 Tage alt oder nie erfolgt.
- Commit-Nachrichten auf Deutsch im Stil des Repos („… hinzufügen“).

## Review Focus

1. **CSV aus deutschem Excel** (Windows-1252, BOM, Semikolon, „F 1“ mit Leerzeichen, Umlaute) muss korrekt eingelesen werden → Test in Task 8.
2. **Zwei Bearbeiter ändern gleichzeitig dasselbe Fahrzeug** → der zweite Speichervorgang darf die Änderung des ersten nicht stillschweigend überschreiben, sondern meldet „wurde zwischenzeitlich geändert“ → Test in Task 6.
3. **Handyfotos** (mehrere MB, EXIF-Drehung) müssen aufrecht und verkleinert angezeigt werden → Test in Task 9.
4. **Upload größer als `post_max_size`**: PHP leert dann `$_POST`, was sonst als CSRF-Fehler erscheint → verständliche Meldung „Datei zu groß“ → Test in Task 9.
5. **Löschen einer Person mit Fahrzeugen oder Login** muss mit klarer Meldung verhindert werden, statt eines Datenbankfehlers → Test in Task 4.

---

## Dateistruktur

```
.htaccess                    Umleitung nach public/ + Sperre aller anderen Ordner (Webspace ohne eigenes Document-Root)
composer.json, phpunit.xml, VERSION, README.md, config.example.php, .gitignore
bin/release.sh               Baut dist/emc78-verwaltung-<VERSION>.zip
.github/workflows/tests.yml  PHPUnit mit MariaDB-Service
public/index.php             Front-Controller
public/.htaccess             Rewrite auf index.php
public/assets/app.css, app.js, vendor/bootstrap/…
migrations/001_grundgeruest.sql, 002_spurweiten_start.sql
src/Core/      App, Container, Router, Request, Response, View, Session, Csrf, Database, DomainFehler, helpers.php
src/routes.php Routendefinitionen
src/Auth/      Rolle, Benutzer, Berechtigung, Auth, AnmeldeErgebnis, AuthController, InstallController
src/Personen/  PersonRepository, BenutzerRepository, PersonController, BenutzerController, KontoController
src/Protokoll/ Aenderungsprotokoll
src/Stammdaten/SpurweiteRepository, SpurweiteController
src/Fahrzeuge/ FahrzeugValidator, FahrzeugRepository, FahrzeugFilter, Inventarnummer, KonfliktFehler, FahrzeugController
src/Digital/   Protokoll, AdressValidator, Adresspruefung, DecoderRepository, DecoderController,
               FunktionRepository, FunktionCsvImport, CsvErgebnis, FunktionsvorlageRepository, FunktionController,
               CvRepository, CvController, AdressenController
src/Dateien/   DateiTyp, UploadService, UploadFehler, Bildbearbeitung, DokumentRepository, DateiController
src/Betrieb/   Migrator, Sicherung, Einstellungen, UpdateController, SicherungController
templates/     layout.php, partials/*, auth/*, personen/*, admin/*, fahrzeuge/*, digital/*, dateien/*, fehler/*
storage/.htaccess (Require all denied), storage/dateien/.gitkeep
tests/bootstrap.php, tests/Unit/…, tests/Integration/DbTestCase.php, tests/Integration/…, tests/fixtures/…
```

## Datenmodell Stufe 1 (`migrations/001_grundgeruest.sql`)

Alle Tabellen InnoDB, `utf8mb4_unicode_ci`, `id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY`, `erstellt_am`/`geaendert_am DATETIME` (wo sinnvoll; `geaendert_am` mit `ON UPDATE CURRENT_TIMESTAMP`).

| Tabelle | Spalten (neben id) | Schlüssel/Regeln |
|---|---|---|
| `schema_migration` | `version VARCHAR(100)` PK statt id, `ausgefuehrt_am` | |
| `einstellung` | `schluessel VARCHAR(100)` PK statt id, `wert TEXT` | |
| `person` | `vorname`, `nachname` (VARCHAR 100, Pflicht), `email`, `telefon`, `notiz TEXT`, `aktiv TINYINT(1) DEFAULT 1` | |
| `benutzer` | `person_id` (UNIQUE, FK person RESTRICT), `email` (UNIQUE), `passwort_hash`, `rolle ENUM('mitglied','bearbeiter','admin')`, `aktiv`, `muss_passwort_aendern`, `letzter_login` | |
| `login_versuch` | `email`, `ip VARCHAR(45)`, `zeitpunkt`, `erfolgreich TINYINT(1)` | INDEX(email, zeitpunkt) |
| `spurweite` | `name VARCHAR(30)` UNIQUE, `massstab VARCHAR(20)`, `sortierung INT` | |
| `fahrzeug` | `inventarnummer VARCHAR(20)` UNIQUE, `bezeichnung VARCHAR(150)` Pflicht, `baureihe`, `bahnverwaltung`, `epoche VARCHAR(5)`, `gattung ENUM('lok','triebwagen','wagen','sonderfahrzeug')`, `hersteller`, `artikelnummer`, `spurweite_id` (FK RESTRICT), `stromsystem ENUM('2L','3L') DEFAULT '2L'`, `eigentuemer_person_id` (NULL = Verein, FK RESTRICT), `status ENUM('einsatzbereit','in_wartung','defekt','ausgeliehen','ausgemustert') DEFAULT 'einsatzbereit'`, `betriebsstunden DECIMAL(8,1) DEFAULT 0`, `titelbild_dokument_id` (NULL), `notiz TEXT`, `geaendert_am DATETIME(6)` (für Konflikterkennung, Task 6) | |
| `decoder` | `fahrzeug_id` (FK CASCADE), `bezeichnung` (z. B. „Motor“, „Sound Wagen 2“), `hersteller`, `typ`, `firmware`, `sound TINYINT(1)`, `protokoll ENUM('dcc','motorola','mfx','selectrix') DEFAULT 'dcc'`, `adresse INT NULL` | INDEX(protokoll, adresse) |
| `funktion` | `decoder_id` (FK CASCADE), `taste TINYINT`, `beschreibung VARCHAR(100)`, `schaltart ENUM('dauer','moment')` | UNIQUE(decoder_id, taste) |
| `funktionsvorlage` | `name VARCHAR(100)` UNIQUE, `decoder_typ VARCHAR(100)` | |
| `vorlage_funktion` | `vorlage_id` (FK CASCADE), `taste`, `beschreibung`, `schaltart` | UNIQUE(vorlage_id, taste) |
| `cv_wert` | `decoder_id` (FK CASCADE), `cv SMALLINT`, `wert SMALLINT`, `kommentar VARCHAR(200)` | UNIQUE(decoder_id, cv) |
| `dokument` | `titel`, `kategorie ENUM('foto','anleitung','schaltplan','gleisplan','rechnung','sonstiges')`, `original_name`, `endung VARCHAR(10)`, `mime`, `groesse INT`, `pfad VARCHAR(255)` (relativ zu storage), `hat_anzeige TINYINT(1)`, `hochgeladen_von` (FK benutzer SET NULL), `hochgeladen_am` | |
| `dokument_zuordnung` | `dokument_id` (FK CASCADE), `objekt_typ VARCHAR(30)`, `objekt_id INT` | UNIQUE(dokument_id, objekt_typ, objekt_id), INDEX(objekt_typ, objekt_id) |
| `aenderungsprotokoll` | `benutzer_id` (NULL), `objekt_typ`, `objekt_id`, `aktion ENUM('angelegt','geaendert','geloescht')`, `aenderungen LONGTEXT` (JSON), `zeitpunkt` | INDEX(objekt_typ, objekt_id) |

`002_spurweiten_start.sql` legt an (name, massstab, sortierung): H0 1:87 10, H0e 1:87 11, H0m 1:87 12, TT 1:120 20, N 1:160 30, Z 1:220 40, 0 1:45 50, 1 1:32 60, G/IIm 1:22,5 70.

---

### Task 1: Projektgerüst, Router, Views

**Files:**
- Create: `composer.json`, `phpunit.xml`, `VERSION` (`0.1.0`), `.gitignore` (`/vendor`, `/config.php`, `/storage/dateien/*`, `!/storage/dateien/.gitkeep`, `/dist`, `.phpunit.cache`), `config.example.php`, `.htaccess`, `public/.htaccess`, `public/index.php`, `public/assets/app.css`, `public/assets/app.js`, `public/assets/vendor/bootstrap/bootstrap.min.css`, `public/assets/vendor/bootstrap/bootstrap.bundle.min.js`, `src/Core/{Request,Response,Router,View,Container,App,DomainFehler}.php`, `src/Core/helpers.php`, `src/routes.php`, `templates/layout.php`, `templates/fehler/{404,403,419,500}.php`, `storage/.htaccess`, `storage/dateien/.gitkeep`, `.github/workflows/tests.yml`
- Test: `tests/bootstrap.php`, `tests/Unit/Core/RouterTest.php`, `tests/Unit/Core/ViewTest.php`

**Interfaces:**
- Produces:
  - `Request::__construct(string $method, string $path, array $query = [], array $post = [], array $files = [], array $server = [])`, `Request::fromGlobals(): Request`, `method(): string`, `path(): string`, `input(string $key, mixed $default = null): mixed` (POST vor GET), `query(string $key, mixed $default = null): mixed`, `file(string $key): ?array`, `server(string $key, mixed $default = null): mixed`, `isPost(): bool`
  - `Response::__construct(string $body = '', int $status = 200, array $headers = [])`, öffentliche readonly `$body`, `$status`, `$headers`; `Response::redirect(string $url): Response` (303), `Response::json(array $daten, int $status = 200): Response`, `send(): void`
  - `Router::add(string $method, string $pattern, callable $handler): void` – Muster wie `/fahrzeuge/{id}`, Platzhalter `{name}` matcht `[^/]+`; Handler-Signatur `fn(Request $r, array $params): Response`. `Router::dispatch(Request $r): Response` – kein Pfad-Treffer → 404, Pfad-Treffer mit anderer Methode → 405.
  - `View::__construct(string $templateDir)`, `render(string $template, array $daten = [], ?string $layout = 'layout'): string` – Template sieht `$daten` als Variablen, Layout erhält `$inhalt`.
  - `e(?string $s): string` (htmlspecialchars, ENT_QUOTES, UTF-8), `url(string $pfad, array $query = []): string` (berücksichtigt `basis_url` aus Config) in `helpers.php`
  - `Container::set(string $id, callable $fabrik): void`, `get(string $id): mixed` (einmal erzeugt, danach gleiche Instanz)
  - `App::erzeuge(array $config): App`, `handle(Request $r): Response`, `container(): Container`, `config(): array`
  - `DomainFehler extends \RuntimeException` (Meldung ist benutzertauglicher deutscher Text)
  - `config.example.php` gibt Array zurück: `db_dsn`, `db_benutzer`, `db_passwort`, `basis_url` (`''`), `speicher_pfad` (`__DIR__ . '/storage'`), `upload_max_mb` (`64`), `app_name` (`'EMC 78 Halle – Fahrzeugverwaltung'`), `debug` (`false`)

- [ ] **Step 1: Composer-Projekt anlegen** – `composer.json` mit `"require": {"php": ">=8.2", "ext-pdo": "*", "ext-gd": "*", "ext-fileinfo": "*"}`, `"require-dev": {"phpunit/phpunit": "^11"}`, Autoload `"Emc\\": "src/"`, `"files": ["src/Core/helpers.php"]`, `"config": {"platform": {"php": "8.2.0"}}`. Run: `composer install` → Expected: `vendor/autoload.php` existiert.

- [ ] **Step 2: Failing tests schreiben**

```php
// tests/Unit/Core/RouterTest.php
public function test_platzhalter_werden_uebergeben(): void {
    $router = new Router();
    $router->add('GET', '/fahrzeuge/{id}', fn(Request $r, array $p) => new Response('id=' . $p['id']));
    $antwort = $router->dispatch(new Request('GET', '/fahrzeuge/42'));
    $this->assertSame(200, $antwort->status);
    $this->assertSame('id=42', $antwort->body);
}
public function test_unbekannter_pfad_liefert_404(): void { /* dispatch GET /gibtsnicht → status 404 */ }
public function test_falsche_methode_liefert_405(): void { /* nur GET registriert, POST /fahrzeuge/1 → 405 */ }
public function test_abschliessender_schraegstrich_wird_ignoriert(): void { /* GET /fahrzeuge/ trifft Route /fahrzeuge */ }

// tests/Unit/Core/ViewTest.php
public function test_e_escapet_html(): void {
    $this->assertSame('&lt;b&gt;&quot;x&quot;&#039;', e('<b>"x"\''));
    $this->assertSame('', e(null));
}
public function test_render_mit_layout(): void { /* Fixture-Templates in tests/fixtures/templates: seite.php gibt "Hallo <?= e($name) ?>" aus, layout.php umschließt mit "[<?= $inhalt ?>]" → render('seite', ['name'=>'Lok'], 'layout') === '[Hallo Lok]' */ }
```

- [ ] **Step 3: Run** `vendor/bin/phpunit tests/Unit/Core` → Expected: FAIL (Klassen fehlen).

- [ ] **Step 4: Core-Klassen implementieren** wie in Interfaces. `App::handle` fängt `\Throwable` → 500-Seite (bei `debug` mit Meldung) und schreibt den Fehler per `error_log`. `public/index.php`: lädt `vendor/autoload.php`, `config.php` (fehlt sie → schlichte HTML-Seite „Bitte config.example.php nach config.php kopieren und ausfüllen.“), `App::erzeuge($config)->handle(Request::fromGlobals())->send()`. Pfad in `Request::fromGlobals` ohne `basis_url`-Präfix und ohne Query.

- [ ] **Step 5: Run** `vendor/bin/phpunit tests/Unit/Core` → Expected: PASS.

- [ ] **Step 6: Webspace-Dateien und Layout**
  - `public/.htaccess`: `RewriteEngine On`, vorhandene Dateien direkt ausliefern, sonst `index.php`.
  - Root-`.htaccess`: leitet alles nach `public/` um (`RewriteRule ^(?!public/)(.*)$ public/$1 [L]`) und verweigert direkten Zugriff auf `src/`, `templates/`, `migrations/`, `storage/`, `vendor/`, `tests/`, `bin/`, `config.php`, `composer.*`.
  - `storage/.htaccess`: `Require all denied`.
  - Bootstrap 5.3.3 herunterladen: `curl -fsSL https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css -o public/assets/vendor/bootstrap/bootstrap.min.css` und `…/dist/js/bootstrap.bundle.min.js` analog.
  - `templates/layout.php`: HTML5, `<meta name="viewport" content="width=device-width, initial-scale=1">`, Titel aus `app_name`, Navbar (collapse auf Handy) mit Platzhaltern für Navigation/Benutzermenü (Task 3 füllt sie), Bereich für Flash-Meldungen (`$meldungen`), `$inhalt`.
  - Fehlerseiten 403 („Dafür fehlt dir die Berechtigung.“), 404 („Seite nicht gefunden.“), 419 („Deine Sitzung ist abgelaufen. Bitte lade die Seite neu und versuche es noch einmal.“), 500 („Es ist ein technischer Fehler aufgetreten.“).

- [ ] **Step 7: CI** – `.github/workflows/tests.yml`: Ubuntu, PHP 8.2 (shivammathur/setup-php@v2, Extensions pdo_mysql, gd, fileinfo, exif, zip), Service `mariadb:10.11` mit `MARIADB_ROOT_PASSWORD=test`, `MARIADB_DATABASE=emc_test`, Env `TEST_DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=emc_test;charset=utf8mb4`, `TEST_DB_BENUTZER=root`, `TEST_DB_PASSWORT=test`; `composer install`, `vendor/bin/phpunit`.

- [ ] **Step 8: Manuell prüfen** – Run: `cp config.example.php config.php && php -S localhost:8080 -t public` und `curl -s -o /dev/null -w '%{http_code}' localhost:8080/gibtsnicht` → Expected: `404`.

- [ ] **Step 9: Commit** – `git add -A && git commit -m "Projektgerüst mit Router, Views und Webspace-Konfiguration hinzufügen"`

---

### Task 2: Datenbank und Migrationen

**Files:**
- Create: `src/Core/Database.php`, `src/Betrieb/Migrator.php`, `src/Betrieb/Einstellungen.php`, `migrations/001_grundgeruest.sql`, `migrations/002_spurweiten_start.sql`
- Test: `tests/Integration/DbTestCase.php`, `tests/Integration/Betrieb/MigratorTest.php`, `tests/Integration/Betrieb/EinstellungenTest.php`

**Interfaces:**
- Consumes: `App`, `Container` (Task 1)
- Produces:
  - `Database::verbinde(string $dsn, string $benutzer, string $passwort): \PDO` – `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES false`, `SET NAMES utf8mb4`
  - `Migrator::__construct(\PDO $pdo, string $verzeichnis)`, `ausstehend(): array` (Dateinamen ohne `.sql`, sortiert), `ausfuehren(): array` (ausgeführte Versionen), `istInstalliert(): bool` (Tabelle `schema_migration` existiert)
  - `Einstellungen::__construct(\PDO $pdo)`, `hole(string $schluessel): ?string`, `setze(string $schluessel, string $wert): void`
  - Container-Einträge: `'pdo'`, `Migrator::class`, `Einstellungen::class`
  - `DbTestCase extends TestCase`: `protected \PDO $pdo` – vor der ersten Testmethode der Klasse werden alle Tabellen gelöscht und alle Migrationen ausgeführt; vor jedem Test werden alle Tabellen außer `schema_migration` und `spurweite` geleert (`SET FOREIGN_KEY_CHECKS=0; TRUNCATE …`). Liest `TEST_DB_DSN`, `TEST_DB_BENUTZER`, `TEST_DB_PASSWORT` (Default `mysql:host=127.0.0.1;dbname=emc_test;charset=utf8mb4`, `root`, `''`).

- [ ] **Step 1: Lokale Test-Datenbank bereitstellen** (nur falls nicht vorhanden): `apt-get install -y mariadb-server && service mariadb start && mysql -e "CREATE DATABASE emc_test CHARACTER SET utf8mb4; ALTER USER root@localhost IDENTIFIED BY 'test';"` und Env-Variablen wie in CI setzen. Expected: `mysql -uroot -ptest -e 'select 1'` gibt `1` aus.

- [ ] **Step 2: Failing tests schreiben**

```php
// MigratorTest
public function test_frische_datenbank_hat_alle_migrationen_ausstehend(): void {
    // Tabellen löschen, neuer Migrator → istInstalliert() false, ausstehend() === ['001_grundgeruest','002_spurweiten_start']
}
public function test_ausfuehren_legt_tabellen_an_und_ist_idempotent(): void {
    // ausfuehren() === ['001_grundgeruest','002_spurweiten_start']; danach ausstehend() === [] und zweites ausfuehren() === []
    // Tabelle fahrzeug existiert; SELECT COUNT(*) FROM spurweite === 9
}
public function test_fehlerhafte_migration_wird_nicht_als_ausgefuehrt_markiert(): void {
    // temporäres Verzeichnis mit 001_ok.sql (CREATE TABLE t1 …) und 002_kaputt.sql (SELEKT) → ausfuehren() wirft \PDOException,
    // danach ausstehend() === ['002_kaputt']
}
// EinstellungenTest
public function test_setzen_und_lesen(): void { /* hole('x') === null; setze('x','1'); setze('x','2'); hole('x') === '2' */ }
```

- [ ] **Step 3: Run** `vendor/bin/phpunit tests/Integration/Betrieb` → Expected: FAIL.

- [ ] **Step 4: Implementieren.** `Migrator::ausfuehren` führt jede Datei einzeln aus, zerlegt Anweisungen an `;` am Zeilenende, trägt die Version erst nach Erfolg in `schema_migration` ein (DDL ist in MySQL nicht transaktional – daher pro Datei eintragen). Schema exakt nach Abschnitt „Datenmodell Stufe 1“.

- [ ] **Step 5: Run** `vendor/bin/phpunit tests/Integration/Betrieb` → Expected: PASS.

- [ ] **Step 6: Commit** – `git commit -m "Datenbankanbindung und Migrationen hinzufügen"`

---

### Task 3: Sitzung, CSRF, Anmeldung, Rechte, Installation

**Files:**
- Create: `src/Core/Session.php`, `src/Core/Csrf.php`, `src/Auth/{Rolle,Benutzer,Berechtigung,Auth,AnmeldeErgebnis,AuthController,InstallController}.php`, `templates/auth/{login,install}.php`, `templates/partials/{navigation,meldungen,csrf}.php`
- Modify: `src/Core/App.php` (Middleware), `src/routes.php`, `templates/layout.php`
- Test: `tests/Unit/Auth/BerechtigungTest.php`, `tests/Unit/Core/CsrfTest.php`, `tests/Integration/Auth/AuthTest.php`, `tests/Integration/Auth/HttpZugriffTest.php`

**Interfaces:**
- Consumes: `Router`, `App`, `'pdo'`, `Migrator` (Tasks 1–2)
- Produces:
  - `Session::__construct(?array &$speicher = null)` (null → `$_SESSION` nach `session_start` mit `httponly`, `samesite=Lax`, `secure` bei HTTPS), `hole(string $k, mixed $d = null)`, `setze(string $k, mixed $v)`, `entferne(string $k)`, `erneuere(): void`, `meldung(string $typ, string $text): void` (`typ` ∈ `erfolg|warnung|fehler`), `meldungenAbholen(): array<array{typ:string,text:string}>`
  - `Csrf::__construct(Session $s)`, `token(): string`, `pruefe(?string $token): bool` (hash_equals); Formularfeld-Name `_csrf`
  - `enum Rolle: string { case Mitglied = 'mitglied'; case Bearbeiter = 'bearbeiter'; case Admin = 'admin'; }` mit `label(): string` („Mitglied“, „Bearbeiter“, „Administrator“)
  - `final class Benutzer` (readonly): `int $id, int $personId, string $email, string $anzeigename, Rolle $rolle, bool $mussPasswortAendern`
  - `Berechtigung::darf(?Benutzer $b, string $aktion): bool` mit Konstanten `LESEN='lesen'`, `FAHRZEUG_BEARBEITEN`, `PERSON_BEARBEITEN`, `DATEI_BEARBEITEN`, `BENUTZER_VERWALTEN`, `STAMMDATEN_VERWALTEN`, `BETRIEB` (Sicherung/Update)
  - `enum AnmeldeErgebnis { Erfolg; Falsch; Gesperrt; Inaktiv; }`
  - `Auth::__construct(\PDO $pdo, Session $s)`, `anmelden(string $email, string $passwort, string $ip): AnmeldeErgebnis`, `abmelden(): void`, `benutzer(): ?Benutzer`
  - `App` erhält `erfordert(string $aktion, callable $handler): callable` (403 bei fehlender Berechtigung) und Middleware in `handle()` in dieser Reihenfolge: (1) kein Schema → Weiterleitung `/install`; (2) POST ohne gültiges `_csrf` → 419; (3) nicht angemeldet und Pfad ∉ {`/login`, `/install`} → Redirect `/login?ziel=<pfad>`; (4) `muss_passwort_aendern` → Redirect `/konto/passwort` (außer dorthin und `/logout`).
  - View erhält automatisch `$benutzer`, `$csrf`, `$meldungen`, `$darf` (Closure `fn(string $aktion): bool`).

- [ ] **Step 1: Failing tests schreiben**

```php
// BerechtigungTest – Matrix
public function test_rechte_matrix(): void {
    $m = $this->benutzer(Rolle::Mitglied); $b = $this->benutzer(Rolle::Bearbeiter); $a = $this->benutzer(Rolle::Admin);
    $this->assertFalse(Berechtigung::darf(null, Berechtigung::LESEN));
    $this->assertTrue(Berechtigung::darf($m, Berechtigung::LESEN));
    $this->assertFalse(Berechtigung::darf($m, Berechtigung::FAHRZEUG_BEARBEITEN));
    $this->assertTrue(Berechtigung::darf($b, Berechtigung::FAHRZEUG_BEARBEITEN));
    $this->assertTrue(Berechtigung::darf($b, Berechtigung::PERSON_BEARBEITEN));
    $this->assertTrue(Berechtigung::darf($b, Berechtigung::DATEI_BEARBEITEN));
    $this->assertFalse(Berechtigung::darf($b, Berechtigung::BENUTZER_VERWALTEN));
    $this->assertFalse(Berechtigung::darf($b, Berechtigung::BETRIEB));
    foreach ([Berechtigung::BENUTZER_VERWALTEN, Berechtigung::STAMMDATEN_VERWALTEN, Berechtigung::BETRIEB] as $x) $this->assertTrue(Berechtigung::darf($a, $x));
}
// CsrfTest
public function test_token_ist_stabil_und_wird_geprueft(): void { /* token()===token(); pruefe(token) true; pruefe('falsch') false; pruefe(null) false */ }
// AuthTest (DbTestCase; legt Person + Benutzer mit password_hash('geheim-passwort-1') an)
public function test_anmeldung_erfolgreich(): void { /* Erfolg; benutzer()->email stimmt; letzter_login gesetzt */ }
public function test_email_gross_klein_egal(): void { /* 'Max@Verein.DE' gleich 'max@verein.de' → Erfolg */ }
public function test_falsches_passwort(): void { /* Falsch; benutzer() null */ }
public function test_sperre_nach_fuenf_fehlversuchen_in_15_minuten(): void { /* 5× Falsch, 6. Versuch mit richtigem Passwort → Gesperrt */ }
public function test_inaktiver_benutzer(): void { /* aktiv=0 → Inaktiv */ }
// HttpZugriffTest (über App::handle mit Test-Config und Array-Session)
public function test_nicht_angemeldet_wird_zum_login_umgeleitet(): void { /* GET /fahrzeuge → 303, Location /login?ziel=%2Ffahrzeuge */ }
public function test_post_ohne_csrf_liefert_419(): void { /* angemeldet, POST /logout ohne _csrf → 419 */ }
public function test_install_nur_ohne_benutzer(): void { /* Benutzer existiert → GET /install → 404 */ }
public function test_install_legt_admin_an(): void { /* leere Benutzer-Tabelle, POST /install (vorname, nachname, email, passwort ≥10 Zeichen, _csrf) → benutzer mit rolle admin existiert, Redirect /login */ }
```

- [ ] **Step 2: Run** `vendor/bin/phpunit tests/Unit/Auth tests/Unit/Core/CsrfTest.php tests/Integration/Auth` → Expected: FAIL.

- [ ] **Step 3: Implementieren.** Login-Seite: E-Mail, Passwort, Schaltfläche „Anmelden“; Meldungen: Falsch → „E-Mail oder Passwort ist falsch.“, Gesperrt → „Zu viele Fehlversuche. Bitte warte 15 Minuten.“, Inaktiv → „Dein Zugang ist deaktiviert. Bitte wende dich an den Vorstand.“ Nach Erfolg `Session::erneuere()` und Redirect auf `ziel` (nur relative Pfade beginnend mit `/`, sonst `/`). `/install`: führt ausstehende Migrationen aus, zeigt Formular für ersten Administrator (nur solange `benutzer` leer ist, sonst 404). Navigation: „Fahrzeuge“, „Adressen“, „Personen“ (wenn `PERSON_BEARBEITEN`), Dropdown „Verwaltung“ (Benutzer, Spurweiten, Sicherung, Update – je nach Recht), Benutzermenü mit Name, „Passwort ändern“, „Abmelden“ (POST-Formular).

- [ ] **Step 4: Run** dieselben Tests → Expected: PASS.

- [ ] **Step 5: Commit** – `git commit -m "Anmeldung, Rollen, CSRF-Schutz und Erstinstallation hinzufügen"`

---

### Task 4: Personen, Benutzer, Passwort, Änderungsprotokoll

**Files:**
- Create: `src/Protokoll/Aenderungsprotokoll.php`, `src/Personen/{PersonRepository,BenutzerRepository,PersonController,BenutzerController,KontoController}.php`, `templates/personen/{liste,formular}.php`, `templates/admin/benutzer/{liste,formular}.php`, `templates/konto/passwort.php`, `templates/partials/verlauf.php`
- Modify: `src/routes.php`
- Test: `tests/Unit/Protokoll/DiffTest.php`, `tests/Integration/Personen/PersonRepositoryTest.php`, `tests/Integration/Personen/BenutzerRepositoryTest.php`

**Interfaces:**
- Consumes: `DbTestCase`, `Auth`, `Berechtigung`, `App::erfordert` (Tasks 2–3)
- Produces:
  - `Aenderungsprotokoll::__construct(\PDO $pdo)`, `schreibe(?int $benutzerId, string $objektTyp, int $objektId, string $aktion, array $aenderungen = []): void`, `fuerObjekt(string $objektTyp, int $objektId, int $limit = 50): array` (neueste zuerst, inkl. Anzeigename), `static diff(array $alt, array $neu): array` → `['feld' => ['alt' => …, 'neu' => …]]` nur für geänderte Schlüssel aus `$neu`
  - `partials/verlauf.php` rendert eine `fuerObjekt`-Liste (Datum, Person, Aktion, geänderte Felder)
  - `PersonRepository::__construct(\PDO $pdo)`, `alle(bool $nurAktive = true): array` (sortiert nachname, vorname), `finde(int $id): ?array`, `speichern(array $daten, ?int $id = null): int`, `loeschen(int $id): void` – wirft `DomainFehler('Diese Person kann nicht gelöscht werden, weil ihr noch Fahrzeuge gehören.')` bzw. `DomainFehler('Diese Person hat einen Zugang. Bitte zuerst den Zugang löschen oder die Person deaktivieren.')`
  - `BenutzerRepository::__construct(\PDO $pdo)`, `alle(): array`, `anlegen(int $personId, string $email, Rolle $rolle, string $startPasswort): int` (setzt `muss_passwort_aendern=1`), `aendern(int $id, Rolle $rolle, bool $aktiv): void`, `passwortSetzen(int $id, string $passwort, bool $mussAendern): void`, `loeschen(int $id): void`, `static pruefePasswort(string $p): ?string` → `'Das Passwort muss mindestens 10 Zeichen lang sein.'` oder null
  - Routen: `/personen` (GET), `/personen/neu`, `POST /personen`, `/personen/{id}/bearbeiten`, `POST /personen/{id}`, `POST /personen/{id}/loeschen` (alle `PERSON_BEARBEITEN`); `/admin/benutzer…` analog (`BENUTZER_VERWALTEN`, inkl. „Passwort zurücksetzen“ = neues Startpasswort setzen); `/konto/passwort` GET/POST (jeder Angemeldete; altes Passwort, neues, Wiederholung)

- [ ] **Step 1: Failing tests schreiben**

```php
// DiffTest
public function test_diff_nur_geaenderte_felder(): void {
    $this->assertSame(['status' => ['alt' => 'einsatzbereit', 'neu' => 'defekt']],
        Aenderungsprotokoll::diff(['status' => 'einsatzbereit', 'name' => 'V 200'], ['status' => 'defekt', 'name' => 'V 200']));
    $this->assertSame([], Aenderungsprotokoll::diff(['adresse' => 3], ['adresse' => '3'])); // Zahl vs. Formular-String gilt als gleich
}
// PersonRepositoryTest
public function test_person_mit_fahrzeug_kann_nicht_geloescht_werden(): void { /* Fahrzeug mit eigentuemer_person_id → loeschen wirft DomainFehler mit „noch Fahrzeuge“; Person existiert weiter */ }
public function test_person_mit_zugang_kann_nicht_geloescht_werden(): void { /* Benutzer zur Person → DomainFehler mit „Zugang“ */ }
public function test_person_ohne_bezuege_wird_geloescht(): void { /* finde() danach null */ }
// BenutzerRepositoryTest
public function test_doppelte_email_wird_abgelehnt(): void { /* zweites anlegen mit gleicher E-Mail (andere Schreibweise) → DomainFehler('Diese E-Mail-Adresse ist bereits vergeben.') */ }
public function test_neuer_benutzer_muss_passwort_aendern(): void { /* anlegen → muss_passwort_aendern = 1; passwortSetzen(…, false) → 0 und password_verify ok */ }
public function test_passwortregel(): void { /* pruefePasswort('kurz') liefert Meldung; pruefePasswort('lang-genug-1') null */ }
public function test_letzter_admin_bleibt(): void { /* einziger Admin: aendern(id, Rolle::Bearbeiter, true), aendern(id, Rolle::Admin, false) und loeschen(id) werfen je DomainFehler('Es muss mindestens ein aktiver Administrator bleiben.') */ }
```

- [ ] **Step 2: Run** `vendor/bin/phpunit tests/Unit/Protokoll tests/Integration/Personen` → Expected: FAIL.

- [ ] **Step 3: Implementieren.** E-Mails werden klein geschrieben gespeichert. Controller schreiben Protokolleinträge (`objekt_typ` `person` bzw. `benutzer`, Passwort-Hash nie protokollieren). Der letzte aktive Administrator kann weder deaktiviert noch herabgestuft noch gelöscht werden (`DomainFehler('Es muss mindestens ein aktiver Administrator bleiben.')`) (siehe `test_letzter_admin_bleibt`). Personenliste auf dem Handy als Karten, ab `md` als Tabelle.

- [ ] **Step 4: Run** → Expected: PASS.

- [ ] **Step 5: Commit** – `git commit -m "Personen- und Benutzerverwaltung mit Änderungsprotokoll hinzufügen"`

---

### Task 5: Spurweiten (Stammliste)

**Files:**
- Create: `src/Stammdaten/{SpurweiteRepository,SpurweiteController}.php`, `templates/admin/spurweiten.php`
- Modify: `src/routes.php`
- Test: `tests/Integration/Stammdaten/SpurweiteRepositoryTest.php`

**Interfaces:**
- Produces: `SpurweiteRepository::__construct(\PDO $pdo)`, `alle(): array` (nach `sortierung`, `name`), `finde(int $id): ?array`, `speichern(string $name, ?string $massstab, int $sortierung, ?int $id = null): int`, `loeschen(int $id): void` – wirft `DomainFehler('Diese Spurweite wird noch von Fahrzeugen verwendet.')`. Routen `/admin/spurweiten` GET/POST, `POST /admin/spurweiten/{id}/loeschen` (`STAMMDATEN_VERWALTEN`); eine Seite mit Liste und Inline-Formular.

- [ ] **Step 1: Failing tests** – `test_startbestand_ist_vorhanden` (9 Einträge, erster `H0`), `test_name_eindeutig` (zweites `N` → `DomainFehler('Diese Spurweite gibt es bereits.')`), `test_verwendete_spurweite_nicht_loeschbar`.
- [ ] **Step 2: Run** `vendor/bin/phpunit tests/Integration/Stammdaten` → FAIL.
- [ ] **Step 3: Implementieren.**
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** – `git commit -m "Verwaltung der Spurweiten hinzufügen"`

---

### Task 6: Fahrzeuge anlegen, bearbeiten, suchen

**Files:**
- Create: `src/Fahrzeuge/{FahrzeugValidator,FahrzeugRepository,FahrzeugFilter,Inventarnummer,KonfliktFehler,FahrzeugController}.php`, `templates/fahrzeuge/{liste,formular,detail}.php`
- Modify: `src/routes.php`, `public/assets/app.css`
- Test: `tests/Unit/Fahrzeuge/FahrzeugValidatorTest.php`, `tests/Integration/Fahrzeuge/FahrzeugRepositoryTest.php`, `tests/Integration/Fahrzeuge/FahrzeugHttpTest.php`

**Interfaces:**
- Consumes: `Aenderungsprotokoll`, `PersonRepository::alle`, `SpurweiteRepository::alle`, `App::erfordert` (Tasks 3–5)
- Produces:
  - `FahrzeugValidator::pruefe(array $d): array<string,string>` (Feld → Fehlermeldung). Regeln: `bezeichnung` Pflicht, ≤ 150 Zeichen („Bitte eine Bezeichnung eingeben.“); `spurweite_id` muss existierende Spurweite sein („Bitte eine Spurweite wählen.“ – Prüfung der Existenz im Repository); `gattung` ∈ lok|triebwagen|wagen|sonderfahrzeug; `stromsystem` ∈ 2L|3L; `status` ∈ einsatzbereit|in_wartung|defekt|ausgeliehen|ausgemustert; `epoche` leer oder ∈ I|II|III|IV|V|VI; `betriebsstunden` Zahl ≥ 0 (Komma als Dezimaltrenner erlaubt).
  - `FahrzeugValidator::normalisiere(array $d): array` – trimmt Strings, leere Strings → null, Standardwerte `stromsystem='2L'`, `status='einsatzbereit'`, `gattung='lok'`, `eigentuemer_person_id` leer → null (= Verein), `betriebsstunden` „12,5“ → `12.5`.
  - `final class FahrzeugFilter` (readonly, public): `?string $text`, `?int $spurweiteId`, `?string $eigentuemer` (`'verein'|'mitglied'|null`), `?int $personId`, `?string $status`, `?string $gattung`; `static ausRequest(Request $r): self`.
  - `FahrzeugRepository::__construct(\PDO $pdo)`, `suche(FahrzeugFilter $f): array` (Text in inventarnummer, bezeichnung, baureihe, hersteller, artikelnummer; ohne Statusfilter werden `ausgemustert` ausgeblendet; Sortierung inventarnummer; liefert zusätzlich `spurweite_name`, `eigentuemer_name`, `titelbild_dokument_id`, `adressen` (kommagetrennt aus Decodern)), `finde(int $id): ?array`, `anlegen(array $d): int`, `aktualisieren(int $id, array $d, string $erwarteterStand): void` (wirft `KonfliktFehler`, wenn `geaendert_am` ≠ `$erwarteterStand`), `loeschen(int $id): void`
  - `Inventarnummer::naechste(\PDO $pdo): string` – höchste vorhandene Nummer + 1, Format `EMC-%04d` (ab 10000 fünfstellig); `anlegen` wiederholt bei Duplikat (UNIQUE) bis zu 3×.
  - `KonfliktFehler extends DomainFehler` mit Meldung „Dieses Fahrzeug wurde inzwischen von jemand anderem geändert. Bitte lade die Seite neu und trage deine Änderung erneut ein.“
  - Routen: `GET /fahrzeuge` (Liste + Filter, `LESEN`), `GET /fahrzeuge/neu`, `POST /fahrzeuge`, `GET /fahrzeuge/{id}` (`LESEN`), `GET /fahrzeuge/{id}/bearbeiten`, `POST /fahrzeuge/{id}`, `POST /fahrzeuge/{id}/loeschen` (`FAHRZEUG_BEARBEITEN`; Löschen fragt per Bestätigungsdialog „Fahrzeug endgültig löschen? Besser: Status ‚ausgemustert‘.“); `GET /` → Redirect `/fahrzeuge`.
  - Detailseite mit Tabs (Bootstrap nav-tabs): **Übersicht**, **Digital** (Task 7/8 füllen), **Dateien** (Task 9 füllt), **Verlauf** (`partials/verlauf.php`). Jeder Tab ist ein eigenes Partial `templates/fahrzeuge/tab_<name>.php`.
  - Kennzeichnung: Mitgliederfahrzeuge zeigen ein Badge „extern – Eigentum von <Name>“; Vereinsfahrzeuge „Verein“.

- [ ] **Step 1: Failing tests schreiben**

```php
// FahrzeugValidatorTest
public function test_pflichtfelder(): void {
    $f = FahrzeugValidator::pruefe(FahrzeugValidator::normalisiere(['bezeichnung' => '  ']));
    $this->assertSame('Bitte eine Bezeichnung eingeben.', $f['bezeichnung']);
    $this->assertArrayHasKey('spurweite_id', $f);
}
public function test_standardwerte(): void {
    $d = FahrzeugValidator::normalisiere(['bezeichnung' => 'BR 218', 'spurweite_id' => '1', 'eigentuemer_person_id' => '']);
    $this->assertSame('2L', $d['stromsystem']); $this->assertSame('einsatzbereit', $d['status']);
    $this->assertNull($d['eigentuemer_person_id']);
}
public function test_betriebsstunden_mit_komma(): void { /* '12,5' → 12.5; '-1' → Fehler 'betriebsstunden' */ }
public function test_ungueltige_auswahlwerte(): void { /* stromsystem 'AC', status 'kaputt', epoche 'VII' → je Fehler */ }
// FahrzeugRepositoryTest
public function test_inventarnummern_fortlaufend(): void { /* drei anlegen → EMC-0001, EMC-0002, EMC-0003; nach Löschen von 0003 bekommt das nächste EMC-0003 nicht doppelt: es gibt keine Kollision mit vorhandenen */ }
public function test_suche_nach_text_und_eigentuemer(): void { /* 'V 200' Verein, 'BR 01' Mitglied → Filter text '01' liefert nur BR 01; eigentuemer 'verein' nur V 200 */ }
public function test_ausgemusterte_standardmaessig_ausgeblendet(): void { /* status ausgemustert erscheint nur mit status-Filter 'ausgemustert' */ }
public function test_gleichzeitige_aenderung_wird_erkannt(): void {
    $id = $this->repo->anlegen($this->daten());
    $stand = $this->repo->finde($id)['geaendert_am'];
    $this->repo->aktualisieren($id, ['bezeichnung' => 'Erste Änderung'] + $this->daten(), $stand);
    $this->expectException(KonfliktFehler::class);
    $this->repo->aktualisieren($id, ['bezeichnung' => 'Zweite Änderung'] + $this->daten(), $stand);
}
// FahrzeugHttpTest
public function test_mitglied_darf_nicht_anlegen(): void { /* POST /fahrzeuge als Mitglied → 403 */ }
public function test_bearbeiter_legt_an_und_protokoll_entsteht(): void { /* POST /fahrzeuge → Redirect /fahrzeuge/{id}; aenderungsprotokoll hat 'angelegt' */ }
public function test_formularfehler_werden_angezeigt(): void { /* POST ohne bezeichnung → 200/422 mit Text 'Bitte eine Bezeichnung eingeben.' und eingegebene Werte bleiben im Formular */ }
```

- [ ] **Step 2: Run** `vendor/bin/phpunit tests/Unit/Fahrzeuge tests/Integration/Fahrzeuge` → FAIL.

- [ ] **Step 3: Implementieren.** `geaendert_am` mit Sekundenauflösung reicht nicht für schnelle Folgeänderungen: daher ist die Spalte `DATETIME(6)` (siehe Datenmodell); im `UPDATE … WHERE id = ? AND geaendert_am = ?` prüfen (0 betroffene Zeilen ⇒ `KonfliktFehler`). Formular übergibt den Stand als Hidden-Feld `_stand`. Liste: Handy = Karten mit Titelbild-Vorschau, Bezeichnung, Inventarnummer, Spurweite, Adresse(n), Status-Badge; ab `md` Tabelle. Filterleiste einklappbar auf dem Handy.

- [ ] **Step 4: Run** → PASS.

- [ ] **Step 5: Commit** – `git commit -m "Fahrzeugkatalog mit Suche und Konflikterkennung hinzufügen"`

---

### Task 7: Decoder und Adressprüfung

**Files:**
- Create: `src/Digital/{Protokoll,AdressValidator,Adresspruefung,DecoderRepository,DecoderController,AdressenController}.php`, `templates/digital/{decoder_formular,adressen}.php`, Inhalt für `templates/fahrzeuge/tab_digital.php`
- Modify: `src/routes.php`, `public/assets/app.js` (Live-Prüfung)
- Test: `tests/Unit/Digital/AdressValidatorTest.php`, `tests/Integration/Digital/AdresspruefungTest.php`, `tests/Integration/Digital/DecoderHttpTest.php`

**Interfaces:**
- Consumes: `FahrzeugRepository::finde`, `Aenderungsprotokoll`, `Session::meldung` (Tasks 3–6)
- Produces:
  - `enum Protokoll: string { case Dcc = 'dcc'; case Motorola = 'motorola'; case Mfx = 'mfx'; case Selectrix = 'selectrix'; }` mit `label()` („DCC“, „Motorola“, „mfx“, „Selectrix“)
  - `AdressValidator::pruefe(Protokoll $p, ?int $adresse): ?string` – Meldungen: „Bitte eine Adresse eingeben.“ (Pflicht außer mfx), „Für DCC sind Adressen von 1 bis 10239 möglich.“, „Für Motorola sind Adressen von 1 bis 255 möglich.“, „Für Selectrix sind Adressen von 0 bis 111 möglich.“, „Für mfx sind Adressen von 1 bis 16383 möglich.“
  - `AdressValidator::adressart(Protokoll $p, int $adresse): ?string` – DCC: `'kurz'` bis 127, sonst `'lang'`; andere `null`
  - `Adresspruefung::__construct(\PDO $pdo)`, `konflikte(int $spurweiteId, Protokoll $p, int $adresse, ?int $ohneFahrzeugId = null): array<array{fahrzeug_id:int, inventarnummer:string, bezeichnung:string, decoder_id:int}>` – gleiche Spurweite, gleiches Protokoll, gleiche Adresse, anderes Fahrzeug, Fahrzeug nicht `ausgemustert`
  - `Adresspruefung::uebersicht(int $spurweiteId): array` – alle Decoder-Adressen der Spurweite (ohne ausgemusterte), sortiert nach Protokoll, Adresse; je Zeile `protokoll, adresse, adressart, fahrzeug_id, inventarnummer, bezeichnung, eigentuemer_name, doppelt (bool)`
  - `DecoderRepository::__construct(\PDO $pdo)`, `fuerFahrzeug(int $fahrzeugId): array`, `finde(int $id): ?array`, `anlegen(int $fahrzeugId, array $d): int`, `aktualisieren(int $id, array $d): void`, `loeschen(int $id): void`
  - Routen: `POST /fahrzeuge/{id}/decoder`, `GET /decoder/{id}/bearbeiten`, `POST /decoder/{id}`, `POST /decoder/{id}/loeschen` (`FAHRZEUG_BEARBEITEN`); `GET /adressen?spurweite=<id>` (`LESEN`, Standard: erste Spurweite; Auswahlfeld oben; doppelte Zeilen `table-warning` mit ⚠️); `GET /api/adresspruefung?fahrzeug=<id>&spurweite=<id>&protokoll=<p>&adresse=<n>` → `{"fehler": ?string, "adressart": ?string, "konflikte": [...]}`
  - Nach Speichern mit Konflikt: Speichern erfolgt, Meldung Typ `warnung`: „Achtung: Adresse 3 (DCC) ist in Spur H0 bereits vergeben an EMC-0007 V 200 060.“ (mehrere Fahrzeuge kommagetrennt). Im Formular zeigt `app.js` dieselbe Warnung live unter dem Adressfeld (Debounce 300 ms).

- [ ] **Step 1: Failing tests schreiben**

```php
// AdressValidatorTest
/** @dataProvider grenzen */
public function test_grenzen(Protokoll $p, ?int $a, bool $ok): void { $this->assertSame($ok, AdressValidator::pruefe($p, $a) === null); }
public static function grenzen(): array { return [
  [Protokoll::Dcc, 1, true], [Protokoll::Dcc, 10239, true], [Protokoll::Dcc, 0, false], [Protokoll::Dcc, 10240, false], [Protokoll::Dcc, null, false],
  [Protokoll::Motorola, 255, true], [Protokoll::Motorola, 256, false],
  [Protokoll::Selectrix, 0, true], [Protokoll::Selectrix, 111, true], [Protokoll::Selectrix, 112, false],
  [Protokoll::Mfx, null, true], [Protokoll::Mfx, 16384, false],
]; }
public function test_adressart(): void { /* Dcc 127 'kurz', Dcc 128 'lang', Motorola 5 null */ }
// AdresspruefungTest
public function test_konflikt_nur_in_gleicher_spurweite(): void { /* H0-Lok DCC 3 und N-Lok DCC 3 → konflikte(H0, Dcc, 3, N-Lok-unabhängig) liefert nur H0-Lok; für neue H0-Lok 1 Konflikt, für neue TT-Lok 0 */ }
public function test_konflikt_nur_bei_gleichem_protokoll(): void { /* H0 DCC 3 vs. H0 Motorola 3 → kein Konflikt */ }
public function test_eigenes_fahrzeug_und_ausgemusterte_zaehlen_nicht(): void { /* zwei Decoder desselben Fahrzeugs mit Adresse 3 → kein Konflikt; ausgemustertes H0 DCC 3 → kein Konflikt */ }
public function test_uebersicht_markiert_doppelte(): void { /* zwei H0 DCC 3, eine H0 DCC 4 → doppelt true,true,false */ }
// DecoderHttpTest
public function test_speichern_trotz_konflikt_mit_warnung(): void { /* POST /fahrzeuge/{id}/decoder mit belegter Adresse → Decoder existiert, Session-Meldung typ 'warnung' enthält 'bereits vergeben an EMC-0001' */ }
public function test_ungueltige_adresse_verhindert_speichern(): void { /* DCC 0 → kein Decoder, Fehlertext 'Für DCC sind Adressen von 1 bis 10239 möglich.' */ }
public function test_api_liefert_konflikte_als_json(): void { /* GET /api/adresspruefung … → Content-Type application/json, konflikte[0].inventarnummer === 'EMC-0001' */ }
```

- [ ] **Step 2: Run** `vendor/bin/phpunit tests/Unit/Digital tests/Integration/Digital` → FAIL.
- [ ] **Step 3: Implementieren.** Tab „Digital“: je Decoder eine Karte (Bezeichnung, Hersteller/Typ, Protokoll, Adresse + „kurz/lang“, Sound-Badge, Konflikt-Badge ⚠️ falls vorhanden) mit Links „Bearbeiten“, „Funktionen“, „CVs“; Formular „Decoder hinzufügen“ mit Protokoll-Standard DCC.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** – `git commit -m "Decoder mit Adressprüfung und Adressübersicht hinzufügen"`

---

### Task 8: Funktionstasten, CSV-Import, Vorlagen, CVs

**Files:**
- Create: `src/Digital/{FunktionRepository,FunktionCsvImport,CsvErgebnis,FunktionsvorlageRepository,FunktionController,CvRepository,CvController}.php`, `templates/digital/{funktionen,csv_vorschau,cvs}.php`
- Modify: `src/routes.php`, `templates/fahrzeuge/tab_digital.php`
- Test: `tests/Unit/Digital/FunktionCsvImportTest.php`, `tests/fixtures/csv/{excel_ansi.csv,utf8_bom.csv,komma.csv,fehlerhaft.csv}`, `tests/Integration/Digital/FunktionRepositoryTest.php`, `tests/Integration/Digital/FunktionsvorlageRepositoryTest.php`, `tests/Integration/Digital/CvRepositoryTest.php`

**Interfaces:**
- Consumes: `DecoderRepository::finde`, `Session`, `Aenderungsprotokoll` (Tasks 3, 7)
- Produces:
  - `FunktionRepository::__construct(\PDO $pdo)`, `fuerDecoder(int $decoderId): array<int, array{taste:int,beschreibung:string,schaltart:string}>` (Schlüssel = Taste), `ersetzen(int $decoderId, array $funktionen): void` (Transaktion; Zeilen mit leerer Beschreibung werden nicht gespeichert)
  - `final class CsvErgebnis` (readonly): `array $zeilen` (Liste `{taste:int, beschreibung:string, schaltart:'dauer'|'moment'}`), `array $fehler` (Liste von Strings)
  - `FunktionCsvImport::parse(string $inhalt): CsvErgebnis` – Regeln: UTF-8-BOM entfernen; ist der Inhalt kein gültiges UTF-8, aus Windows-1252 umwandeln; Trennzeichen aus der ersten nicht leeren Zeile: `;` vor Tab vor `,`; erste Zeile ist Kopfzeile, wenn ihre erste Zelle keine Taste ist; Taste-Muster `/^\s*F?\s*(\d{1,2})\s*$/i`, gültig 0–31; Schaltart `dauer|d|moment|m|leer` (Groß/klein egal, leer = dauer); leere Zeilen überspringen; Beschreibung getrimmt, max. 100 Zeichen. Fehlermeldungen: „Zeile 4: Taste „F40“ ist ungültig (erlaubt F0–F31).“, „Zeile 5: Taste F2 kommt doppelt vor.“, „Zeile 6: Schaltart „kurz“ ist unbekannt (erlaubt Dauer oder Moment).“, „Zeile 7: Beschreibung fehlt.“
  - `FunktionCsvImport::VORLAGE` = `"\u{FEFF}Taste;Beschreibung;Schaltart\r\nF0;Licht;Dauer\r\nF1;Fahrgeräusch;Dauer\r\nF2;Pfeife;Moment\r\n"`
  - `FunktionsvorlageRepository::__construct(\PDO $pdo)`, `alle(): array`, `ausDecoder(int $decoderId, string $name, ?string $decoderTyp): int` (`DomainFehler('Eine Vorlage mit diesem Namen gibt es bereits.')`), `aufDecoderAnwenden(int $vorlageId, int $decoderId): void` (ersetzt Belegung), `loeschen(int $id): void`
  - `CvRepository::__construct(\PDO $pdo)`, `fuerDecoder(int $decoderId): array` (nach CV), `speichern(int $decoderId, int $cv, int $wert, ?string $kommentar): void` (Upsert), `loeschen(int $decoderId, int $cv): void`, `static pruefe(int $cv, int $wert): ?string` – „CV muss zwischen 1 und 1024 liegen.“, „Wert muss zwischen 0 und 255 liegen.“
  - Routen (`FAHRZEUG_BEARBEITEN` für POST, `LESEN` für GET): `GET /decoder/{id}/funktionen` (Tabelle F0–F31, im Bearbeitungsmodus als Formular), `POST /decoder/{id}/funktionen`, `POST /decoder/{id}/funktionen/csv` (Upload → Vorschau, in Session unter `csv_vorschau_<decoderId>`), `POST /decoder/{id}/funktionen/csv/uebernehmen` (nur ohne Fehler möglich; Hinweis „Die bisherige Belegung wird ersetzt.“), `GET /funktionen/vorlage.csv` (Download, `text/csv; charset=utf-8`), `POST /decoder/{id}/vorlage` (als Vorlage speichern), `POST /decoder/{id}/vorlage-anwenden`, `GET /decoder/{id}/cvs`, `POST /decoder/{id}/cvs`, `POST /decoder/{id}/cvs/{cv}/loeschen`

- [ ] **Step 1: Failing tests schreiben**

```php
// FunktionCsvImportTest
public function test_excel_ansi_mit_umlauten(): void {
    // Fixture excel_ansi.csv: Windows-1252, Semikolon, Kopfzeile, Zeile "F 1;Fahrgeräusch;Dauer", "f2;Pfeife kurz;moment"
    $e = FunktionCsvImport::parse(file_get_contents(__DIR__ . '/../../fixtures/csv/excel_ansi.csv'));
    $this->assertSame([], $e->fehler);
    $this->assertSame(['taste' => 1, 'beschreibung' => 'Fahrgeräusch', 'schaltart' => 'dauer'], $e->zeilen[0]);
    $this->assertSame('moment', $e->zeilen[1]['schaltart']);
}
public function test_utf8_mit_bom_ohne_kopfzeile(): void { /* "\u{FEFF}F0;Licht;\nF3;Bremse;M" → 2 Zeilen, F0 dauer, F3 moment */ }
public function test_komma_als_trenner(): void { /* "Taste,Beschreibung,Schaltart\n4,Kupplung,Moment" → taste 4 */ }
public function test_fehlerzeilen(): void {
    $e = FunktionCsvImport::parse("Taste;Beschreibung;Schaltart\nF40;X;Dauer\nF2;A;Dauer\nF2;B;Dauer\nF3;C;kurz\nF5;;Dauer");
    $this->assertSame([
        'Zeile 2: Taste „F40“ ist ungültig (erlaubt F0–F31).',
        'Zeile 4: Taste F2 kommt doppelt vor.',
        'Zeile 5: Schaltart „kurz“ ist unbekannt (erlaubt Dauer oder Moment).',
        'Zeile 6: Beschreibung fehlt.',
    ], $e->fehler);
}
public function test_vorlage_ist_selbst_importierbar(): void { /* parse(VORLAGE) → 3 Zeilen, keine Fehler */ }
// FunktionRepositoryTest
public function test_ersetzen_ueberschreibt_belegung(): void { /* ersetzen mit F0,F1; dann mit F2 → fuerDecoder hat nur Schlüssel 2 */ }
// FunktionsvorlageRepositoryTest
public function test_vorlage_von_decoder_auf_anderen_uebertragen(): void { /* Decoder A F0–F2, ausDecoder(A,'ESU LokSound V 200') → aufDecoderAnwenden(vorlage, B) → B hat dieselben 3 Funktionen */ }
public function test_vorlagenname_eindeutig(): void { /* zweimal gleicher Name → DomainFehler */ }
// CvRepositoryTest
public function test_upsert_und_grenzen(): void { /* speichern(d,29,6,…); speichern(d,29,14,…) → ein Eintrag mit 14; pruefe(0,1) und pruefe(1,256) liefern Meldung */ }
```

- [ ] **Step 2: Fixtures erzeugen** – `excel_ansi.csv` mit `printf 'Taste;Beschreibung;Schaltart\r\nF 1;Fahrger\xe4usch;Dauer\r\nf2;Pfeife kurz;moment\r\n' > tests/fixtures/csv/excel_ansi.csv`; übrige analog zu den Tests.
- [ ] **Step 3: Run** `vendor/bin/phpunit --filter 'Funktion|Cv'` → FAIL.
- [ ] **Step 4: Implementieren.** Funktionstabelle auf dem Handy: eine Zeile pro Taste mit Taste, Beschreibung, Symbol für Moment-Taste; leere Tasten ausblendbar („Nur belegte Tasten“). Tab „Digital“ zeigt je Decoder die Anzahl belegter Tasten und CVs.
- [ ] **Step 5: Run** → PASS.
- [ ] **Step 6: Commit** – `git commit -m "Funktionstasten mit CSV-Import und Vorlagen sowie CV-Liste hinzufügen"`

---

### Task 9: Fotos und Dokumente

**Files:**
- Create: `src/Dateien/{DateiTyp,UploadService,UploadFehler,Bildbearbeitung,DokumentRepository,DateiController}.php`, Inhalt für `templates/fahrzeuge/tab_dateien.php`, `templates/dateien/upload_formular.php`
- Modify: `src/Core/App.php` (Prüfung `post_max_size` vor CSRF), `src/routes.php`, `templates/fahrzeuge/liste.php` (Titelbild)
- Test: `tests/Unit/Dateien/DateiTypTest.php`, `tests/Integration/Dateien/UploadServiceTest.php`, `tests/Integration/Dateien/DateiHttpTest.php`, `tests/fixtures/dateien/{foto_gedreht.jpg,anleitung.pdf,getarnt.jpg}`

**Interfaces:**
- Consumes: `Berechtigung::DATEI_BEARBEITEN`, `FahrzeugRepository`, `Aenderungsprotokoll` (Tasks 3, 6)
- Produces:
  - `DateiTyp::fuerEndung(string $endung): ?array{mimes: list<string>, anzeige: 'inline'|'download', bild: bool}` – Positivliste: `jpg`/`jpeg` [image/jpeg], `png` [image/png], `webp` [image/webp], `pdf` [application/pdf] inline; `txt` [text/plain], `csv` [text/plain, text/csv] inline; `docx`, `xlsx` [jeweiliger OOXML-Typ, application/zip, application/octet-stream], `odt`, `ods` [jeweiliger ODF-Typ, application/zip] download. Alles andere `null`.
  - `UploadFehler extends DomainFehler`; Meldungen: „Dateien vom Typ .php sind nicht erlaubt.“ (Endung eingesetzt), „Der Inhalt der Datei passt nicht zur Endung .jpg.“, „Die Datei ist zu groß (höchstens 64 MB).“ (Wert aus Config), „Die Datei wurde nur teilweise hochgeladen. Bitte erneut versuchen.“, „Es wurde keine Datei ausgewählt.“
  - `Bildbearbeitung::erzeugeVarianten(string $quelle, string $zielBasis): bool` – EXIF-Ausrichtung korrigieren (falls `exif_read_data` verfügbar), `<zielBasis>.anzeige.jpg` (längste Seite 1600 px) und `<zielBasis>.vorschau.jpg` (400 px), JPEG-Qualität 82; ohne GD `false`
  - `UploadService::__construct(\PDO $pdo, string $speicherPfad, int $maxBytes, Bildbearbeitung $bild)`, `speichere(array $phpDatei, string $titel, string $kategorie, ?int $benutzerId): int` – Ablage `storage/dateien/JJJJ/MM/<32 Hex-Zeichen>` ohne Endung; Inhaltsprüfung mit `finfo`
  - `DokumentRepository::__construct(\PDO $pdo, string $speicherPfad)`, `finde(int $id): ?array`, `zuordnen(int $dokumentId, string $objektTyp, int $objektId): void`, `fuerObjekt(string $objektTyp, int $objektId): array` (neueste zuerst), `loeschen(int $id): void` (Datei + Varianten entfernen; setzt betroffene `fahrzeug.titelbild_dokument_id` auf NULL)
  - Routen: `POST /fahrzeuge/{id}/dateien` (`DATEI_BEARBEITEN`; Felder `datei`, `titel` (Standard: Originalname), `kategorie`; Bilder werden automatisch Kategorie `foto`; erstes Foto wird Titelbild), `POST /fahrzeuge/{id}/titelbild` (`dokument_id`), `POST /dateien/{id}/loeschen`, `GET /dateien/{id}` und `GET /dateien/{id}/{variante}` mit `variante` ∈ `anzeige|vorschau` (`LESEN`)
  - Auslieferung: `Content-Type` = gespeicherter MIME-Typ, `Content-Disposition: inline` bzw. `attachment` mit `filename*=UTF-8''…`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, max-age=3600`; fehlende Variante → Original
  - `App::handle`: Bei POST mit `CONTENT_LENGTH` > `post_max_size` → Meldung `fehler` „Die Datei ist zu groß (höchstens <n> MB).“ und Redirect auf Referer (nur gleiche Herkunft, sonst `/`), **bevor** die CSRF-Prüfung greift.

- [ ] **Step 1: Fixtures** – `foto_gedreht.jpg`: 300×200-JPEG mit EXIF `Orientation=6` (per Python/Pillow oder `exiftool` erzeugen und einchecken); `anleitung.pdf`: minimales gültiges PDF; `getarnt.jpg`: Textdatei mit `<?php echo 1;`.

- [ ] **Step 2: Failing tests schreiben**

```php
// DateiTypTest
public function test_positivliste(): void { $this->assertNotNull(DateiTyp::fuerEndung('JPEG')); $this->assertNull(DateiTyp::fuerEndung('php')); $this->assertNull(DateiTyp::fuerEndung('phtml')); $this->assertNull(DateiTyp::fuerEndung('svg')); }
// UploadServiceTest
public function test_php_endung_abgelehnt(): void { /* name 'x.php' → UploadFehler 'Dateien vom Typ .php sind nicht erlaubt.' */ }
public function test_getarnte_datei_abgelehnt(): void { /* getarnt.jpg → UploadFehler 'Der Inhalt der Datei passt nicht zur Endung .jpg.' */ }
public function test_zu_gross(): void { /* maxBytes 10, Datei 11 Byte → UploadFehler mit 'zu groß' */ }
public function test_gedrehtes_handyfoto_wird_aufrecht_verkleinert(): void {
    $id = $this->service->speichere($this->upload('foto_gedreht.jpg'), 'Lok', 'foto', null);
    $dok = $this->repo->finde($id);
    [$b, $h] = getimagesize($this->speicher . '/' . $dok['pfad'] . '.vorschau.jpg');
    $this->assertGreaterThan($b, $h); // 300×200 mit Orientation 6 → hochkant
    $this->assertLessThanOrEqual(400, max($b, $h));
    $this->assertSame(1, $dok['hat_anzeige']);
}
public function test_gespeicherte_datei_hat_keine_endung(): void { /* pfad endet nicht auf .pdf, Datei existiert */ }
// DateiHttpTest
public function test_dateien_nur_angemeldet(): void { /* GET /dateien/{id} ohne Login → 303 /login… */ }
public function test_pdf_wird_inline_mit_nosniff_geliefert(): void { /* Header Content-Type application/pdf, Content-Disposition beginnt mit 'inline', X-Content-Type-Options nosniff */ }
public function test_zu_grosser_post_zeigt_verstaendliche_meldung(): void {
    // Request POST /fahrzeuge/1/dateien mit server CONTENT_LENGTH > post_max_size, leerem $_POST → Redirect, Session-Meldung typ 'fehler' beginnt mit 'Die Datei ist zu groß', Status ≠ 419
}
public function test_mitglied_darf_nicht_hochladen(): void { /* → 403 */ }
```

- [ ] **Step 3: Run** `vendor/bin/phpunit --filter 'Datei|Upload'` → FAIL.
- [ ] **Step 4: Implementieren.** Tab „Dateien“: Fotogalerie (Vorschaubilder, Klick öffnet Anzeige-Variante in Bootstrap-Modal, Stern-Schaltfläche „Als Titelbild“), darunter Dokumentenliste (Symbol nach Endung, Titel, Kategorie, Größe, Datum, Hochgeladen von, Löschen). Upload-Formular mit `accept`-Attribut aus der Positivliste und `capture`-freiem `<input type="file" multiple>` (Handy bietet Kamera an); mehrere Dateien werden nacheinander gespeichert, Fehler je Datei gemeldet.
- [ ] **Step 5: Run** → PASS.
- [ ] **Step 6: Commit** – `git commit -m "Fotos und Dokumente an Fahrzeugen hinzufügen"`

---

### Task 10: Datensicherung, Update-Seite, Release und Anleitung

**Files:**
- Create: `src/Betrieb/{Sicherung,SicherungController,UpdateController}.php`, `templates/admin/{sicherung,update}.php`, `templates/partials/betriebshinweise.php`, `templates/fehler/wartung.php`, `bin/release.sh`, `README.md`
- Modify: `src/Core/App.php`, `src/routes.php`, `templates/layout.php`
- Test: `tests/Integration/Betrieb/SicherungTest.php`, `tests/Integration/Betrieb/UpdateHttpTest.php`

**Interfaces:**
- Consumes: `Migrator`, `Einstellungen`, `DokumentRepository`, `Berechtigung::BETRIEB` (Tasks 2, 3, 9)
- Produces:
  - `Sicherung::__construct(\PDO $pdo, string $speicherPfad, Einstellungen $e)`, `erstelle(string $zielZip): void` – ZIP mit `datenbank.sql` (Kopf `SET NAMES utf8mb4; SET FOREIGN_KEY_CHECKS=0;`, je Tabelle `DROP TABLE IF EXISTS`, `SHOW CREATE TABLE`, `INSERT` in Blöcken à 100 Zeilen mit `PDO::quote`/`NULL`, Ende `SET FOREIGN_KEY_CHECKS=1;`), Ordner `dateien/` (Inhalt von `storage/dateien`), `info.txt` (Datum, App-Version aus `VERSION`, letzte Migration); setzt Einstellung `letzte_sicherung` (ISO-8601)
  - `Sicherung::istUeberfaellig(\DateTimeImmutable $jetzt): bool` – true, wenn nie oder älter als 30 Tage
  - Routen (`BETRIEB`): `GET /admin/sicherung` (Datum letzte Sicherung, Schaltfläche „Sicherung herunterladen“, Hinweis zur Wiederherstellung), `POST /admin/sicherung` (streamt `emc78-sicherung-JJJJ-MM-TT.zip`, löscht Temp-Datei), `GET /admin/update` (ausstehende Migrationen), `POST /admin/update` (führt aus, Meldung „Datenbank wurde aktualisiert (n Schritte).“)
  - `App::handle`: stehen Migrationen aus → Administratoren sehen Hinweisleiste „Es steht ein Datenbank-Update an.“ mit Link; alle anderen Rollen erhalten `templates/fehler/wartung.php` (Status 503, „Die Anwendung wird gerade aktualisiert. Bitte versuche es in ein paar Minuten noch einmal.“) – außer `/login`, `/logout`, `/admin/update`. Ist die Sicherung überfällig, sehen Administratoren die Hinweisleiste „Die letzte Datensicherung ist älter als 30 Tage.“ (`partials/betriebshinweise.php`).

- [ ] **Step 1: Failing tests schreiben**

```php
// SicherungTest
public function test_sicherung_laesst_sich_wiederherstellen(): void {
    // 2 Fahrzeuge (Umlaute + Anführungszeichen in Bezeichnung: 'Köf „Rangier“ II'), 1 Decoder, 1 Dokument mit Datei anlegen
    $this->sicherung->erstelle($zip = sys_get_temp_dir() . '/s.zip');
    // ZIP enthält datenbank.sql, info.txt, dateien/<pfad>
    // datenbank.sql in eine zweite, leere Testdatenbank (emc_test_wiederherstellung) einspielen
    // → gleiche Zeilenzahl in fahrzeug, decoder, dokument; Bezeichnung exakt 'Köf „Rangier“ II'
}
public function test_ueberfaellig(): void { /* nie → true; setze letzte_sicherung = jetzt-29 Tage → false; jetzt-31 Tage → true */ }
// UpdateHttpTest
public function test_mitglied_sieht_wartungsseite_bei_ausstehender_migration(): void { /* zusätzliche Migration 999_test.sql im Test-Migrationsverzeichnis → GET /fahrzeuge als Mitglied → 503 */ }
public function test_admin_fuehrt_update_aus(): void { /* POST /admin/update → ausstehend() leer, danach GET /fahrzeuge als Mitglied → 200 */ }
```

- [ ] **Step 2: Run** `vendor/bin/phpunit tests/Integration/Betrieb` → FAIL.
- [ ] **Step 3: Implementieren** (Wiederherstellungs-Test: zweite Datenbank im Test mit `CREATE DATABASE IF NOT EXISTS`; SQL-Datei an `;\n` am Zeilenende zerlegen, wie im Migrator).
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Release-Skript** `bin/release.sh`: kopiert Repo nach `build/` ohne `.git`, `tests`, `docs`, `.github`, `config.php`, `storage/dateien/*`; `composer install --no-dev --optimize-autoloader --working-dir=build`; `zip -r dist/emc78-verwaltung-$(cat VERSION).zip`. Run: `bash bin/release.sh && unzip -l dist/emc78-verwaltung-0.1.0.zip | grep -c 'vendor/autoload.php'` → Expected: `1`, und `unzip -l … | grep -c 'tests/'` → `0`.
- [ ] **Step 6: README.md** (deutsch, für den Vereinsverantwortlichen): Voraussetzungen (PHP ≥ 8.2 mit pdo_mysql, gd, fileinfo; MySQL ≥ 8 / MariaDB ≥ 10.6; HTTPS), **Installation** (ZIP entpacken, per FTP hochladen, Datenbank im Hoster-Menü anlegen, `config.example.php` → `config.php` ausfüllen, Document-Root wenn möglich auf `public/`, sonst greift die Root-`.htaccess`, Seite aufrufen → `/install`), **Update** (neues ZIP hochladen ohne `config.php` und `storage/` zu überschreiben, als Admin „Verwaltung → Update“), **Sicherung und Wiederherstellung** (ZIP herunterladen; Wiederherstellen: `datenbank.sql` per phpMyAdmin importieren, Ordner `dateien/` nach `storage/dateien/` hochladen), **Entwicklung** (Tests lokal mit MariaDB, `vendor/bin/phpunit`, Release bauen).
- [ ] **Step 7: Gesamtlauf** – Run: `vendor/bin/phpunit` → Expected: alle Tests grün. Manuelle Prüfliste mit `php -S localhost:8080 -t public` in einem Handy-großen Browserfenster (Playwright, 390×844) und am Desktop: Installation → Admin anlegen → Spurweite → Person → Fahrzeug → Decoder mit doppelter Adresse (Warnung sichtbar, live im Formular) → CSV-Import mit Fixture `excel_ansi.csv` → Foto hochladen → Sicherung herunterladen → als Mitglied anmelden (keine Bearbeiten-Schaltflächen sichtbar).
- [ ] **Step 8: Commit** – `git commit -m "Datensicherung, Update-Seite, Release-Skript und Anleitung hinzufügen"`

---

### Task 11: Installierbare Web-App, Testumgebung mit Beispieldaten

**Files:**
- Create: `public/manifest.webmanifest`, `public/assets/icons/{icon-192.png,icon-512.png,apple-touch-icon.png}` (schlichtes Symbol „EMC 78“, bis ein Vereinslogo vorliegt), `bin/demo-daten.php`, `tests/fixtures/demo/` (2–3 kleine Beispielfotos)
- Modify: `templates/layout.php`, `config.example.php`, `src/Core/App.php`, `README.md`
- Test: `tests/Integration/Betrieb/DemoDatenTest.php`, `tests/Unit/Core/LayoutPwaTest.php`

**Interfaces:**
- Consumes: Repositories aus Tasks 4–9, `UploadService` (Task 9), `Migrator` (Task 2)
- Produces:
  - Config-Schlüssel `testumgebung` (bool, Standard `false`). Ist er `true`, zeigt das Layout oben eine gelbe Leiste „Testumgebung – Daten können jederzeit zurückgesetzt werden.“
  - `manifest.webmanifest`: `name` = `app_name`-Standard „EMC 78 Halle – Fahrzeugverwaltung“, `short_name` „EMC 78“, `start_url` und `scope` relativ zu `basis_url` (`./`), `display` `standalone`, `lang` `de`, Icons 192/512.
  - Layout-Kopf: `<link rel="manifest">`, `<meta name="theme-color">`, `<link rel="apple-touch-icon">`, `<meta name="apple-mobile-web-app-capable" content="yes">`, `<meta name="apple-mobile-web-app-title" content="EMC 78">`; alle Pfade über `url()`, damit Unterordner-Installation funktioniert. Kein Service Worker (Offline ist kein Ziel; Daten müssen aktuell sein).
  - `bin/demo-daten.php [--zuruecksetzen]`: CLI; bricht ab, wenn `testumgebung` nicht `true` ist („Beispieldaten nur in der Testumgebung“). Legt an: 6 Personen, Benutzer `admin@test.emc78.de` (Admin), `bearbeiter@test.emc78.de`, `mitglied@test.emc78.de` mit Passwort `Test-EMC78!` (ohne Passwortwechsel-Zwang), 12 Fahrzeuge in H0 und N (Verein und Mitglieder gemischt, ein ausgemustertes), Decoder mit absichtlich **einer doppelten Adresse** in H0, Funktionsbelegungen inkl. einer Vorlage, CVs, Fotos aus `tests/fixtures/demo/`. `--zuruecksetzen` leert vorher alle Tabellen außer `schema_migration` und `spurweite` und `storage/dateien`.

- [ ] **Step 1: Failing tests** – `DemoDatenTest::test_bricht_ausserhalb_testumgebung_ab`; `test_legt_beispieldaten_mit_doppelter_adresse_an` (12 Fahrzeuge, `Adresspruefung::uebersicht(H0)` enthält mindestens zwei Zeilen mit `doppelt` true, Anmeldung als `mitglied@test.emc78.de` mit `Test-EMC78!` ergibt `AnmeldeErgebnis::Erfolg`); `test_zuruecksetzen_ist_wiederholbar` (zweimal mit `--zuruecksetzen` → weiterhin 12 Fahrzeuge). `LayoutPwaTest`: gerendertes Layout mit `basis_url` `/mitglieder` enthält `href="/mitglieder/manifest.webmanifest"` und `apple-touch-icon`; mit `testumgebung` true enthält es „Testumgebung“, sonst nicht.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter 'DemoDaten|LayoutPwa'` → FAIL.
- [ ] **Step 3: Implementieren** (Demo-Logik als Klasse `src/Betrieb/DemoDaten.php` mit `__construct(App $app)` und `erzeuge(bool $zuruecksetzen): void`; `bin/demo-daten.php` ruft sie nur auf).
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: README ergänzen** – Abschnitte „Installation als Subdomain (empfohlen: mitglieder.emc78.de)“, „Installation im Unterordner (www.emc78.de/mitglieder, `basis_url` = `/mitglieder`)“, „Testumgebung einrichten (test.emc78.de, `testumgebung` = true, `php bin/demo-daten.php --zuruecksetzen` bzw. ohne SSH: Admin-Seite folgt später)“, „Auf dem Handy installieren“ (Android: Chrome-Menü → „App installieren“/„Zum Startbildschirm“; iPhone: Safari → Teilen → „Zum Home-Bildschirm“), „Link auf der Vereinswebseite“.
- [ ] **Step 6: Manuell prüfen** – Playwright mit Gerätebeschreibung „iPhone 13“ und „Pixel 7“: Login als `mitglied@test.emc78.de`, Fahrzeugliste, Detail, Adressübersicht mit Warnung; Screenshots nach `docs/screenshots/` (für die Vorstellung im Verein).
- [ ] **Step 7: Commit** – `git commit -m "Installierbare Web-App und Testumgebung mit Beispieldaten hinzufügen"`

---

## Spec-Abdeckung (Stufe 1)

| Spec | Task |
|---|---|
| 2 Rahmenbedingungen (Webspace, responsive, FTP, Standardwerte) | 1, 6, 7, 10 |
| 3 Rollen, Personen ohne Login, Änderungsprotokoll | 3, 4 |
| 4.1 Fahrzeug, Eigentümer/extern, Status, Fotos, Betriebsstunden-Feld | 6, 9 |
| 4.1 Decoder (mehrere), Plausibilität, Adressprüfung je Spurweite, Übersicht | 7 |
| 4.1 Funktionstasten, CSV-Import, Vorlagen, CV-Liste | 8 |
| 4.7 Dateien Grundfunktion, Sicherheit | 9 |
| 5 Sicherheit (Login, Sperre, CSRF, Sitzung), Migrationen, Sicherung, README, Fehler/Warnung, Tests | 3, 2, 10, alle |
| 2 Mobil (PWA), Erreichbarkeit über emc78.de, Testumgebung | 11 |

Bewusst **nicht** in Stufe 1 (laut Spec spätere Stufen): PDF-Import der Funktionstasten, QR-Codes, Wartung/Reparatur (Stufe 2); erweiterte Dateitypen, Volltextsuche (Stufe 6). **Passwort-Zurücksetzen per E-Mail** wird nachgereicht, sobald der Mailversand des Webspace geklärt ist (Spec, offener Punkt 1); bis dahin setzt ein Administrator ein neues Startpasswort (Task 4).
