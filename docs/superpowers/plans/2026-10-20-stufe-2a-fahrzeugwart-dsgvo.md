# Stufe 2a – Rolle Fahrzeugwart und DSGVO-Löschung – Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Neue Rolle „Fahrzeugwart“ (alles rund um alle Fahrzeuge) und eine vollständige, transaktionale DSGVO-Löschung einer Person mit Vorschau, Übergabe von Fahrzeugen an den Verein und Bereinigung des Änderungsprotokolls.

**Architecture:** Die Rolle wird als Rang 2 in die bestehende Rangfolge von `Berechtigung` eingefügt (Mitglied 1, Fahrzeugwart 2, Bearbeiter 3, Admin 4). Die Löschung kapselt ein neuer Dienst `Emc\Personen\DsgvoLoeschung` (Vorschau + Ausführung in einer DB-Transaktion; Dateien erst nach dem Commit). `DokumentRepository` bekommt eine transaktionsfreie Löschvariante, `Aenderungsprotokoll` eine Anonymisierung.

**Tech Stack:** PHP 8.2, PDO/MariaDB 10.6+/MySQL 8, PHPUnit 11, bestehendes Template-System (`View`, `e()`, `url()`).

**Spec:** `docs/superpowers/specs/2026-10-20-stufe-2-wartung-reparatur-design.md` (Abschnitte 3 und 4); Hauptspezifikation `docs/superpowers/specs/2026-10-18-emc78-fahrzeug-anlagen-verwaltung-design.md`.

## Global Constraints

- PHP ≥ 8.2, kein Framework; MariaDB ≥ 10.6 und MySQL ≥ 8 (SQL muss auf beiden laufen).
- Neue Migration als `migrations/008_rolle_fahrzeugwart.sql`; bestehende Migrationen 001–007 nicht ändern.
- Oberfläche und Meldungen auf Deutsch; Anzeigetext für entfernte Personenbezüge exakt **„ehemaliges Mitglied“**.
- Rollenwert in der Datenbank `fahrzeugwart`, Anzeigename „Fahrzeugwart“.
- DSGVO-Löschung nur für Administratoren; Bestätigung durch Eingabe des **Nachnamens** (Vergleich ohne Groß-/Kleinschreibung, Leerzeichen am Rand egal).
- Alle POST-Formulare mit CSRF-Token (globaler Schutz in `App`), Ausgaben mit `e()`.
- Composer nur mit `--prefer-dist`; danach `git remote -v` prüfen (origin `https://github.com/brokermaler-ship-it/Kleine-Helfer-gro-e-Wirkung`).
- Tests: `TEST_DB_PASSWORT=test vendor/bin/phpunit` muss vollständig grün sein.
- Commit-Nachrichten auf Deutsch, enden mit den Zeilen `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` und `Claude-Session: https://claude.ai/code/session_01WPjn12EeTpMLbw1UrLaWZi`.

## Review Focus

1. **Person hat einen Zugang und eigene Fahrzeuge mit Fotos im OneDrive:** alles weg, für jede OneDrive-Datei genau ein Lösch-Auftrag, keine Datei auf dem Webspace übrig. → Test in Task 3.
2. **Fehler mitten in der Löschung** (z. B. Fremdschlüssel): nichts gelöscht, keine Datei vom Webspace entfernt. → Test in Task 3.
3. **Admin löscht sich selbst oder den letzten aktiven Admin:** verweigert mit Meldung (sonst sperrt sich der Verein aus). → Test in Task 3.
4. **Name kommt in Protokolleinträgen anderer Objekte vor** (z. B. Benutzer-Anlage mit E-Mail, Personenänderung mit Telefon) und ein anderes Mitglied heißt gleich mit Vornamen: nur die volle Kombination bzw. E-Mail/Telefon wird ersetzt, der Vorname allein nicht. → Test in Task 2.
5. **Fahrzeugwart ruft Personen-, Benutzer- oder Betriebsseiten auf:** 403; an fremden privaten Fahrzeugen dagegen Bearbeiten, Dateien und Löschen erlaubt. → Test in Task 1.

---

### Task 1: Rolle Fahrzeugwart

**Files:**
- Create: `migrations/008_rolle_fahrzeugwart.sql`
- Modify: `src/Auth/Rolle.php`, `src/Auth/Berechtigung.php`, `src/Betrieb/DemoDaten.php`, `README.md` (Rollentabelle, Testzugänge), `tests/Integration/Betrieb/MigratorTest.php` (Liste der Versionen)
- Test: `tests/Unit/Auth/BerechtigungTest.php`, `tests/Integration/Fahrzeuge/` (neuer HTTP-Test `FahrzeugwartHttpTest.php`)

**Interfaces:**
- Produces: `Rolle::Fahrzeugwart` (`'fahrzeugwart'`, Label „Fahrzeugwart“); `Berechtigung::PERSON_DSGVO_LOESCHEN` (Rang 4); Ränge: `LESEN` 1, `FAHRZEUG_ANLEGEN` 1, `FAHRZEUG_BEARBEITEN` 2, `PERSON_BEARBEITEN` 3, `DATEI_BEARBEITEN` 3, `BENUTZER_VERWALTEN` 4, `STAMMDATEN_VERWALTEN` 4, `BETRIEB` 4, `PERSON_DSGVO_LOESCHEN` 4.

- [ ] **Step 1: Failing tests**
  - `BerechtigungTest::test_fahrzeugwart_darf_fahrzeuge_aber_nicht_personen`: Fahrzeugwart → `darf(FAHRZEUG_BEARBEITEN)` true, `darf(PERSON_BEARBEITEN)` false, `darf(DATEI_BEARBEITEN)` false, `darf(BETRIEB)` false; `darfFahrzeugBearbeiten` für ein Fahrzeug mit `angelegt_von_benutzer_id` eines anderen Benutzers → true.
  - `BerechtigungTest::test_rangfolge`: Bearbeiter darf alles, was Fahrzeugwart darf, plus `PERSON_BEARBEITEN`; nur Admin darf `PERSON_DSGVO_LOESCHEN`.
  - `FahrzeugwartHttpTest`: als Fahrzeugwart `GET /fahrzeuge/{id}/bearbeiten` eines privaten Fahrzeugs eines anderen Mitglieds → 200; `POST /fahrzeuge/{id}/loeschen` → Weiterleitung, Fahrzeug weg; `GET /personen` → 403; `GET /admin/benutzer` → 403; `GET /admin/sicherung` → 403.
  - `MigratorTest`: Versionsliste um `008_rolle_fahrzeugwart`.
- [ ] **Step 2:** `TEST_DB_PASSWORT=test vendor/bin/phpunit --filter 'Berechtigung|Fahrzeugwart|Migrator'` → FAIL.
- [ ] **Step 3: Implement**
  - Migration: `ALTER TABLE benutzer MODIFY rolle ENUM('mitglied','fahrzeugwart','bearbeiter','admin') NOT NULL DEFAULT 'mitglied';`
  - `Rolle`: Fall `Fahrzeugwart = 'fahrzeugwart'` zwischen Mitglied und Bearbeiter (Reihenfolge der `cases()` bestimmt die Auswahlliste im Benutzerformular).
  - `Berechtigung`: Ränge wie oben, `match` um Fahrzeugwart ⇒ 2; Docblock „1 Mitglied, 2 Fahrzeugwart, 3 Bearbeiter, 4 Admin“; Kommentar von `darfFahrzeugBearbeiten` auf „Fahrzeugwart und höher“.
  - `DemoDaten`: zusätzlicher Testzugang `fahrzeugwart@test.emc78.de` (Person „Dieter Wagner“, erfundener Name), Passwort `self::PASSWORT`; README-Abschnitt Testzugänge entsprechend.
  - README: Rollentabelle gemäß Spezifikation Abschnitt 3.
- [ ] **Step 4:** Gleicher Filter → PASS; danach volle Suite grün.
- [ ] **Step 5: Commit** „Rolle Fahrzeugwart: alles rund um alle Fahrzeuge“.

### Task 2: Bausteine – Dokumente ohne eigene Transaktion löschen, Protokoll anonymisieren

**Files:**
- Modify: `src/Dateien/DokumentRepository.php`, `src/Protokoll/Aenderungsprotokoll.php`, `templates/partials/verlauf.php`, `templates/fahrzeuge/tab_dateien.php`
- Test: `tests/Integration/Dateien/DokumentRepositoryTest.php` (bestehend oder neu), `tests/Integration/Protokoll/AenderungsprotokollTest.php` (bestehend oder neu)

**Interfaces:**
- Produces:
  - `DokumentRepository::loescheDatensatz(int $id): ?string` – löscht Datensatz, Zuordnungen, Titelbild-Verweise und legt ggf. den OneDrive-Lösch-Auftrag an, **ohne** eigene Transaktion und ohne Dateien anzufassen; gibt den relativen `pfad` zurück (null, wenn nicht vorhanden).
  - `DokumentRepository::entferneDateien(string $relativerPfad): void` – entfernt Original, `.anzeige.jpg`, `.vorschau.jpg`.
  - `DokumentRepository::idsFuerObjekt(string $objektTyp, int $objektId): list<int>` – Dokumente, die **nur** diesem Objekt zugeordnet sind (geteilte nicht).
  - `Aenderungsprotokoll::anonymisiere(list<string> $begriffe, ?int $benutzerId, list<array{0:string,1:int}> $eigeneObjekte): int` – löscht Einträge der eigenen Objekte (`[['person', 17], ['benutzer', 5]]`), ersetzt in allen übrigen `aenderungen`-Werten jede Fundstelle eines Begriffs (ohne Groß-/Kleinschreibung) durch „ehemaliges Mitglied“, setzt `benutzer_id = NULL` für `$benutzerId`; Rückgabe = Anzahl geänderter/gelöschter Einträge.
- Bestehendes `loeschen(int $id)` bleibt mit gleichem Verhalten und wird auf die neuen Methoden umgestellt (eigene Transaktion um `loescheDatensatz`, danach `entferneDateien`).

- [ ] **Step 1: Failing tests**
  - `test_loesche_datensatz_ohne_transaktion_innerhalb_fremder_transaktion`: `$pdo->beginTransaction()`, `loescheDatensatz($id)` liefert den Pfad, `rollBack()` → Dokument existiert wieder, Datei noch vorhanden.
  - `test_ids_fuer_objekt_ohne_geteilte`: Dokument A nur an Fahrzeug 1, Dokument B an Fahrzeug 1 und 2 → `idsFuerObjekt('fahrzeug', 1) === [A]`.
  - `test_anonymisiere_ersetzt_volle_namen_email_telefon`: Einträge mit Werten `"Carla Muster"`, `"carla@test.de"`, `"0345 123"`, `"Muster, Carla"` werden zu „ehemaliges Mitglied“; ein Eintrag mit `"Carla Schmidt"` bleibt unverändert (Begriffe sind `["Carla Muster", "Muster, Carla", "carla@test.de", "0345 123"]`, nicht der Vorname allein).
  - `test_anonymisiere_loescht_eigene_objekte_und_leert_benutzer`: Einträge `objekt_typ='person', objekt_id=17` gelöscht; Einträge mit `benutzer_id=5` haben danach `NULL`.
  - Verlauf: Eintrag ohne Benutzer zeigt „ehemaliges Mitglied“ statt „unbekannt“; Dateiliste ohne Uploader zeigt „ehemaliges Mitglied“ statt „–“.
- [ ] **Step 2:** Filter `DokumentRepository|Aenderungsprotokoll` → FAIL.
- [ ] **Step 3: Implement** wie in Interfaces. Ersetzung in PHP: Einträge per `LIKE` auf jeden Begriff vorfiltern, JSON dekodieren, rekursiv nur String-Werte ersetzen (`str_ireplace`), Begriffe nach Länge absteigend anwenden, zurückschreiben. Leere Begriffe ignorieren.
- [ ] **Step 4:** Filter → PASS; volle Suite grün.
- [ ] **Step 5: Commit** „Bausteine für die DSGVO-Löschung: Dokumente transaktionsfrei löschen, Protokoll anonymisieren“.

### Task 3: Dienst `DsgvoLoeschung` (Vorschau und Ausführung)

**Files:**
- Create: `src/Personen/DsgvoLoeschung.php`
- Modify: `src/Core/App.php` (Container-Eintrag)
- Test: `tests/Integration/Personen/DsgvoLoeschungTest.php`

**Interfaces:**
- Consumes: Task 2 (`loescheDatensatz`, `entferneDateien`, `idsFuerObjekt`, `anonymisiere`); bestehende `FahrzeugRepository::loeschen`, `Uebertragung::nebenbei()` (nullable).
- Produces:
  - `DsgvoLoeschung::vorschau(int $personId): array{person: array, benutzer: ?array, fahrzeuge: list<array{id:int, inventarnummer:string, bezeichnung:string, dokumente:int}>, protokolleintraege:int, verweigert:?string}` – `verweigert` enthält den Grund, wenn gelöscht werden darf nicht (eigene Person des ausführenden Admins, letzter aktiver Admin).
  - `DsgvoLoeschung::loesche(int $personId, list<int> $anVereinUebergeben, int $ausfuehrenderBenutzerId): void` – wirft `DomainFehler` bei Verweigerung bzw. bei IDs, die nicht zu den Fahrzeugen der Person gehören.
- Später (2b/2c) ergänzt `loesche` die Tabellen für Wartungen und Reparaturen; Wartungen/Reparaturen eigener Fahrzeuge werden mitgelöscht, Bezüge in Vereinsdaten auf `NULL` gesetzt.

Ablauf von `loesche` (in dieser Reihenfolge, Schritte 1–6 in **einer** Transaktion):
1. Verweigerungsgründe prüfen.
2. Fahrzeuge aus `$anVereinUebergeben`: `eigentuemer_person_id = NULL`; Protokolleintrag „geaendert“ am Fahrzeug (eigentuemer → „Verein“).
3. Übrige eigene Fahrzeuge: je Dokument aus `idsFuerObjekt('fahrzeug', id)` `loescheDatensatz` (Pfade sammeln), geteilte Zuordnungen lösen, dann `FahrzeugRepository::loeschen` (Decoder/Funktionen/CVs per CASCADE).
4. Benutzerkonto: `login_versuch` mit dessen E-Mail löschen, `benutzer` löschen (FKs setzen `hochgeladen_von` und `angelegt_von_benutzer_id` auf NULL).
5. `anonymisiere(begriffe, benutzerId, [['person', $id], ['benutzer', $bid]] plus je gelöschtem eigenen Fahrzeug ['fahrzeug', $fahrzeugId])` mit Begriffen: „Vorname Nachname“, „Nachname, Vorname“, „Nachname Vorname“, E-Mail der Person, E-Mail des Kontos, Telefon.
6. `person` löschen; Protokolleintrag `objekt_typ='person'`, `objekt_id=$id`, Aktion `geloescht`, Änderungen `['dsgvo' => ['alt' => null, 'neu' => 'nach DSGVO gelöscht']]`, Benutzer = ausführender Admin.
7. Nach dem Commit: `entferneDateien` für alle gesammelten Pfade, dann `nebenbei()`.

- [ ] **Step 1: Failing tests** (`DsgvoLoeschungTest`)
  - `test_vorschau_listet_eigene_fahrzeuge_und_konto`.
  - `test_loesche_entfernt_person_konto_eigene_fahrzeuge_und_dateien`: Person mit Konto, 2 eigenen Fahrzeugen (eins mit Foto lokal, eins mit `ablage='onedrive'`), danach: keine Person, kein Benutzer, keine Fahrzeuge, keine Decoder, Datei vom Webspace weg, genau 1 `onedrive_auftrag` mit `art='loeschen'`, kein `login_versuch` mit der E-Mail.
  - `test_uebergabe_an_verein_behaelt_fahrzeug`: übergebenes Fahrzeug existiert mit `eigentuemer_person_id IS NULL`.
  - `test_vereinsfahrzeug_bleibt_bezug_wird_geleert`: Vereinsfahrzeug mit `angelegt_von_benutzer_id` der Person → bleibt, Feld `NULL`.
  - `test_protokoll_enthaelt_keine_personendaten_mehr`: nach Löschung kommt keiner der Begriffe mehr in `aenderungsprotokoll.aenderungen` vor; genau ein Eintrag „nach DSGVO gelöscht“ zur Person.
  - `test_fehler_mitten_drin_laesst_alles_unveraendert`: Fehler erzwingen (z. B. Trigger oder Test-Unterklasse, die nach Schritt 3 wirft) → Person, Fahrzeuge und Datei auf dem Webspace noch vorhanden.
  - `test_eigene_person_und_letzter_admin_verweigert`.
  - `test_fremde_fahrzeug_id_bei_uebergabe_wirft`.
- [ ] **Step 2:** Filter `DsgvoLoeschung` → FAIL.
- [ ] **Step 3: Implement** gemäß Ablauf.
- [ ] **Step 4:** Filter → PASS; volle Suite grün.
- [ ] **Step 5: Commit** „DSGVO-Löschung: Vorschau und transaktionale Ausführung“.

### Task 4: Oberfläche, Hinweise, Version 0.4.0

**Files:**
- Create: `templates/personen/dsgvo.php`
- Modify: `src/Personen/PersonController.php` (Aktionen `dsgvoVorschau`, `dsgvoLoeschen`), `src/routes.php`, `templates/personen/formular.php` (Schaltfläche nur für Admins), `README.md` (Abschnitt „Person nach DSGVO löschen“, Empfehlung: Sicherungen nach 12 Monaten vernichten), `VERSION` → `0.4.0`, `docs/offene-punkte-stufe-1.md` (DSGVO-Punkt erledigt)
- Test: `tests/Integration/Personen/DsgvoHttpTest.php`

**Interfaces:**
- Consumes: Task 3 (`vorschau`, `loesche`), Task 1 (`PERSON_DSGVO_LOESCHEN`).
- Routen: `GET /personen/{id}/dsgvo` und `POST /personen/{id}/dsgvo`, beide `erfordert('person_dsgvo_loeschen', …)`. POST-Felder: `nachname` (Bestätigung), `uebergeben[]` (Fahrzeug-IDs).

- [ ] **Step 1: Failing tests** (`DsgvoHttpTest`)
  - Bearbeiter und Fahrzeugwart → 403 auf GET und POST.
  - Admin GET → 200, Seite nennt Inventarnummern der eigenen Fahrzeuge, enthält Hinweis auf ältere Sicherungen und Checkbox „an den Verein übergeben“ je Fahrzeug.
  - POST mit falschem Nachnamen → 422, nichts gelöscht, Meldung „Bitte den Nachnamen zur Bestätigung eingeben.“
  - POST korrekt (Nachname in anderer Groß-/Kleinschreibung mit Leerzeichen) → Weiterleitung auf `/personen`, Erfolgsmeldung „Die Person wurde nach DSGVO gelöscht.“, Person weg.
  - Verweigerung (eigene Person) → Seite zeigt Grund, kein Lösch-Formular.
  - Personenformular: Schaltfläche „Person endgültig löschen (DSGVO)“ für Admin sichtbar, für Bearbeiter nicht.
- [ ] **Step 2:** Filter `DsgvoHttp` → FAIL.
- [ ] **Step 3: Implement** Controller, Template (Tabelle wie in Spezifikation Abschnitt 4, Bootstrap-Gefahrenkasten), Routen, Schaltfläche, README, VERSION.
- [ ] **Step 4:** Volle Suite grün; `bash bin/release.sh` erzeugt `dist/emc78-verwaltung-0.4.0.zip` mit `migrations/008_rolle_fahrzeugwart.sql`.
- [ ] **Step 5: Commit** „DSGVO-Löschung in der Oberfläche, Version 0.4.0“.
