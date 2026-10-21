# Stufe 2c – Reparaturen, Defekt melden, Codes und Etiketten – Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Kurze Codes `F<n>`/`A<n>` mit Feld „Code“ in der Kopfzeile und Kurzlink `/c/<Code>`, „Defekt melden“ am Handy für alle Mitglieder, Reparaturen mit Ersatzteilen, Kosten und Status samt automatischem Fahrzeug-/Anlagenstatus, Hinweise in der App (ohne E-Mail) und Etiketten zum Drucken aus dem Browser (Fahrzeug-Klebchen, Verpackung, Anlagen-QR).

**Architecture:** Neuer Namensraum `Emc\Codes` (reine Code-Logik `Code` plus `CodeAufloesung` mit Datenbank) und `Emc\Reparatur` nach dem Muster von `Emc\Wartung`: Repository, Validatoren, ein Dienst für Transaktionen und Aufräumen, die reine Statusregel `StatusAutomatik` (ohne Datenbank, voll unit-testbar), Controller. Fotos hängen über `dokument_zuordnung` mit `objekt_typ = 'reparatur'`. `Emc\Etiketten` erzeugt Druckseiten mit festen Bogenmaßen; QR-Codes kommen serverseitig als SVG aus `chillerlan/php-qrcode`.

**Tech Stack:** PHP 8.2, PDO/MariaDB 10.6+/MySQL 8, PHPUnit 11, bestehendes Template-System (`View`, `e()`, `url()`), Bootstrap 5, Druck-CSS (`@page`, mm-Maße); neue Composer-Abhängigkeit `chillerlan/php-qrcode` (^5.0).

**Spec:** `docs/superpowers/specs/2026-10-20-stufe-2-wartung-reparatur-design.md` (Abschnitte 2 Zeile 2c, 3, 4 (DSGVO-Ergänzung Reparaturen), 6, 8–10); Hauptspezifikation `docs/superpowers/specs/2026-10-18-emc78-fahrzeug-anlagen-verwaltung-design.md`.

## Global Constraints

- PHP ≥ 8.2, kein Framework; MariaDB ≥ 10.6 und MySQL ≥ 8 (SQL muss auf beiden laufen). Einzige neue Composer-Abhängigkeit: `chillerlan/php-qrcode` (Task 6).
- Neue Migrationen `014_reparatur.sql` (Task 2) und `015_etikett.sql` (Task 6); 001–013 nicht ändern. `MigratorTest` (Versionslisten) je Task ergänzen.
- Ränge: Mitglied 1, Fahrzeugwart 2, Bearbeiter 3, Admin 4. Neue Aktion `etiketten_drucken` (2). Defekt melden: alle (`lesen`). Reparaturen bearbeiten: Fahrzeugwart an allen Fahrzeugen, Bearbeiter an Anlagen, Mitglieder an **eigenen** Fahrzeugen (Eigentümer = ihre Person **oder** selbst angelegt = `Berechtigung::darfFahrzeugWarten`).
- **Reparaturen werden nicht gelöscht**, nur verworfen. Ausnahmen (ohne Schaltfläche): DSGVO-Löschung eigener Fahrzeuge und das Löschen des Fahrzeugs bzw. der Anlage selbst (sonst blieben verwaiste Zeilen).
- Codes: Fahrzeug `F<n>` mit `n` = Zahl der Inventarnummer ohne führende Nullen (`EMC-0012` → `F12`), Anlage `A<n>` (`AnlagenCode::formatiere`). Eingabe ohne Rücksicht auf Groß-/Kleinschreibung und Leerzeichen.
- Objektbezüge über `objekt_typ` (`fahrzeug`, `anlage`) + `objekt_id`. Personenbezüge `NULL`-bar mit `ON DELETE SET NULL`, angezeigt exakt als **„ehemaliges Mitglied“** (`Aenderungsprotokoll::EHEMALIGES_MITGLIED`).
- Oberfläche und Meldungen auf Deutsch, Anrede „du“. Statusnamen der Reparatur: „gemeldet“, „geplant“, „in Arbeit“, „wartet auf Teile“, „erledigt“, „verworfen“; Prioritäten „dringend“, „normal“, „niedrig“; Einstufung beim Melden „fährt nicht mehr“ (= dringend) / „fährt, aber …“ (= normal).
- Keine E-Mails (Spezifikation 2: nicht Teil von Stufe 2). Hinweise nur in der App.
- Alle POST-Formulare mit CSRF-Token (globaler Schutz in `App`), Ausgaben mit `e()`. Datumsanzeige `d.m.Y`, Beträge mit Komma oder Punkt eingeben, Anzeige `12,50 €`; Beträge als `DECIMAL(8,2)`, Summe in SQL (keine Gleitkomma-Summen in PHP).
- Seiten, die Tabellen/Spalten aus 014/015 brauchen, fangen fehlende Tabellen ab (SQLSTATE `42S02`/`42S22` → Seite ohne Block bzw. Weiterleitung auf `/admin/update`, wie `WartungDienst::blockDaten`/`WartungController::vorUpdate`).
- Tests: `TEST_DB_PASSWORT=test vendor/bin/phpunit` muss nach jedem Task vollständig grün sein.
- Commit-Nachrichten auf Deutsch, enden mit den Zeilen `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` und `Claude-Session: https://claude.ai/code/session_01WPjn12EeTpMLbw1UrLaWZi`. Nicht pushen.

## Review Focus

1. **Von Hand gesetzter Status wird überschrieben oder zurückgesetzt:** Ein ausgeliehenes oder ausgemustertes Fahrzeug bekommt eine dringende Meldung → Status bleibt; wer nach einem automatisch gesetzten „defekt“ den Status von Hand ändert, behält ihn auch, wenn später die letzte Reparatur erledigt wird. → Tests in Task 2.
2. **Eingabevarianten im Feld „Code“:** ` f 012 `, `a3`, `F0`, `F12a`, `0003` (Adresse 3), `EMC-0012` (Suche), Fahrzeug mit Inventarnummer außerhalb `EMC-<Zahl>` (kein Code, per Suche auffindbar), ausgemustertes Fahrzeug (gefunden, gekennzeichnet „Abstellgleis“). → Tests in Task 1.
3. **Kurzlink ohne Anmeldung:** `/c/A3` → Login → direkt zur Anlage; seltsame Codes (`/c/%2F%2Fbeispiel.de`, `/c/F12%0A`) bleiben innerhalb der App und landen in der Suche. → Test in Task 1.
4. **Gleichzeitiges Bearbeiten und Beträge:** Zwei Personen speichern dieselbe Reparatur (zweite bekommt den Konflikt, Ersatzteile unverändert); `12,5`, `12.50`, `1234` gültig, `-1`, `12,345`, `1.234,50` → Fehler; Summe exakt in Cent. → Tests in Task 4.
5. **Etiketten über den Bogen hinaus:** Startposition 185 bei 10 Klebchen → 5 auf Bogen 1, 5 auf Bogen 2; Startposition außerhalb 1–189/1–24 → 1; „noch nicht gedruckt“ ohne ausgemusterte Fahrzeuge; nur der POST „Als gedruckt vermerken“ setzt `etikett_gedruckt_am`, das Anzeigen der Druckseite nicht. → Tests in Task 6.

---

### Task 1: Codes F/A, Feld „Code“ und Kurzlink

**Files:**
- Create: `src/Codes/Code.php`, `src/Codes/CodeErgebnis.php`, `src/Codes/CodeAufloesung.php`, `src/Codes/CodeController.php`, `templates/codes/ergebnis.php`
- Modify: `src/Fahrzeuge/FahrzeugRepository.php`, `templates/partials/navigation.php` (Feld „Code“), `templates/fahrzeuge/detail.php` (Code neben der Inventarnummer), `src/Core/App.php`, `src/routes.php`
- Test: `tests/Unit/Codes/CodeTest.php`, `tests/Integration/Codes/CodeAufloesungTest.php`, `tests/Integration/Codes/CodeHttpTest.php`

**Interfaces:**
- `Codes\Code` (rein):
  - `fahrzeug(string $inventarnummer): ?string` – `^EMC-0*([1-9][0-9]*)$` → `'F' . n`, sonst null (auch `EMC-0000`).
  - `normalisiere(string $eingabe): string` – alle Leerraumzeichen (auch geschütztes Leerzeichen, `\s` mit `u`) entfernen, `mb_strtoupper`.
  - `zerlege(string $eingabe): ?array{typ:string, nummer:int}` – nach `normalisiere`: `^([FA])0*([1-9][0-9]{0,8})$` → `typ` `fahrzeug`/`anlage`.
  - `istAdresse(string $eingabe): ?int` – nach `normalisiere`: `^[0-9]{1,6}$` → `(int)` (führende Nullen egal), sonst null.
- `FahrzeugRepository`:
  - `findeNachCodeNummer(int $n): ?array` – `inventarnummer REGEXP '^EMC-[0-9]+$' AND CAST(SUBSTRING(inventarnummer, 5) AS UNSIGNED) = ?`.
  - `nachAdresse(int $adresse): list<array>` – verschiedene Fahrzeuge mit einem Decoder dieser Adresse (alle Protokolle, auch ausgemusterte), mit `spurweite_name`, `titelbild_dokument_id`, `bezeichnung`, `status`, `inventarnummer`; sortiert nach Inventarnummer.
- `Codes\CodeErgebnis` (readonly): `string $art` (`direkt`, `auswahl`, `suche`, `leer`), `?string $ziel` (Pfad ohne Basis-URL, z. B. `/fahrzeuge/5`), `list<array> $fahrzeuge`, `list<array> $anlagen`, `?string $meldung`.
- `Codes\CodeAufloesung::__construct(FahrzeugRepository $fahrzeuge, AnlageRepository $anlagen)`; `loese(string $eingabe, string $zusatz = ''): CodeErgebnis` – `$zusatz` wird an jedes Ziel gehängt (`/defekt` in Task 3).
  - Leer → `leer`. `F<n>`/`A<n>` gefunden → `direkt`; nicht gefunden → `suche` mit Meldung **„Kein Fahrzeug und keine Anlage mit diesem Code.“** und Suchergebnis für die Roheingabe.
  - Reine Zahl → Adresse: ein Treffer `direkt`, mehrere `auswahl`, keiner `suche` mit Meldung „Kein Fahrzeug mit der Digitaladresse <n>.“ und Suchergebnis.
  - Sonst `suche`: Fahrzeuge über `FahrzeugRepository::suche(new FahrzeugFilter(text: …))` (Bezeichnung, Baureihe, Inventarnummer …; findet bei Text auch ausgemusterte) und Anlagen über `AnlageRepository::alle(null, $text)`; keine Treffer → Meldung „Nichts gefunden.“
- Routen (`lesen`): `GET /code` (Feld `q`, optional `fuer=defekt`) und `GET /c/{code}` → `CodeController::suche`/`kurzlink`. `direkt` → Weiterleitung auf `url($ziel)`; `leer` → Weiterleitung auf `/`; sonst 200 mit `templates/codes/ergebnis.php` (Liste mit Foto, Code, Bezeichnung bzw. Name, Spurweite; ausgemusterte mit Badge „Abstellgleis“ aus `fahrzeuge/_labels.php`; `auswahl` mit Überschrift „Mehrere Fahrzeuge mit Adresse <n>“). Ohne Anmeldung leitet `App` GET-Anfragen bereits auf `/login?ziel=<Pfad>` um – nichts Neues nötig, nur testen.
- Kopfzeile: `<form method="get" action="/code" role="search">` mit `<input name="q" autocapitalize="characters" autocomplete="off" placeholder="Code, z. B. F12">` und sichtbar verstecktem Label „Code“; auch auf dem Handy außerhalb des zugeklappten Menüs sichtbar (vor dem Menüknopf). Fahrzeugseite zeigt `Code::fahrzeug(...)` groß neben der Inventarnummer (fehlt der Code: nichts).

- [ ] **Step 1: Failing tests**
  - `CodeTest`: `fahrzeug('EMC-0012')` = `F12`, `fahrzeug('EMC-12345')` = `F12345`, `fahrzeug('EMC-0000')`/`fahrzeug('ALT-7')` = null; `zerlege(' f 012 ')` = fahrzeug/12, `zerlege('a3')` = anlage/3, `zerlege('F0')`/`zerlege('F12a')`/`zerlege('X3')` = null; `istAdresse('0003')` = 3, `istAdresse('1234567')` = null, `istAdresse('F3')` = null.
  - `CodeAufloesungTest`: `F12` → `direkt` `/fahrzeuge/<id>`; `A3` → `/anlagen/<id>`; `F99` → `suche` mit Meldung; Adresse 3 an zwei Fahrzeugen → `auswahl` mit beiden; Adresse 3 an genau einem (ausgemustert) → `direkt`; `EMC-0012` → `suche` mit dem Fahrzeug; Fahrzeug `ALT-7` per Text auffindbar; `loese('F12', '/defekt')` → `/fahrzeuge/<id>/defekt`.
  - `CodeHttpTest`: angemeldet `GET /code?q=f12` → 302 auf `/fahrzeuge/<id>`; `GET /code?q=3` mit zwei Treffern → 200 mit beiden Bezeichnungen und „Abstellgleis“ beim ausgemusterten; `GET /code?q=F99` → 200 mit „Kein Fahrzeug und keine Anlage mit diesem Code.“; ohne Anmeldung `GET /c/A3` → 302 auf `/login?ziel=%2Fc%2FA3`, danach `POST /login` mit `ziel=/c/A3` → 302 auf `/c/A3` → 302 auf `/anlagen/<id>`; `GET /c/%2F%2Fbeispiel.de` und `GET /c/F12%0A` → nie eine Weiterleitung, deren `Location` nicht mit der Basis-URL + `/` beginnt (200 Suchseite oder 404); jede Seite enthält das Feld `name="q"`.
- [ ] **Step 2:** `TEST_DB_PASSWORT=test vendor/bin/phpunit --filter 'Code'` → FAIL.
- [ ] **Step 3: Implement** wie in Interfaces.
- [ ] **Step 4:** Filter → PASS; volle Suite grün.
- [ ] **Step 5: Commit** „Codes F<n>/A<n>: Feld „Code“ in der Kopfzeile und Kurzlink /c/<Code>“.

### Task 2: Reparatur-Datenmodell, Rechte und automatischer Status

**Files:**
- Create: `migrations/014_reparatur.sql`, `src/Reparatur/ReparaturStatus.php`, `src/Reparatur/StatusAutomatik.php`, `src/Reparatur/ReparaturRepository.php`, `src/Reparatur/ReparaturDienst.php`
- Modify: `src/Auth/Berechtigung.php`, `src/Fahrzeuge/FahrzeugRepository.php`, `src/Anlagen/AnlageRepository.php`, `src/Fahrzeuge/FahrzeugController.php` + `src/Anlagen/AnlageController.php` (Löschen räumt Reparaturen auf), `src/Core/App.php`, `tests/Integration/Betrieb/MigratorTest.php`
- Test: `tests/Unit/Reparatur/StatusAutomatikTest.php`, `tests/Integration/Reparatur/ReparaturDienstTest.php`, `tests/Unit/Auth/BerechtigungTest.php` (ergänzen)

**Interfaces:**
- Migration 014:
  - `ALTER TABLE fahrzeug ADD COLUMN status_automatisch TINYINT(1) NOT NULL DEFAULT 0;` und ebenso `anlage` (Kommentar: 1 = aktueller Status wurde von einer Reparatur gesetzt).
  - `reparatur` (id, `objekt_typ ENUM('fahrzeug','anlage') NOT NULL`, `objekt_id INT UNSIGNED NOT NULL`, `beschreibung VARCHAR(2000) NOT NULL`, `wo_bauteil VARCHAR(150) NULL`, `gemeldet_von_benutzer_id` FK → benutzer `ON DELETE SET NULL`, `gemeldet_am DATETIME NOT NULL`, `prioritaet ENUM('dringend','normal','niedrig') NOT NULL DEFAULT 'normal'`, `zustaendig_person_id` FK → person `ON DELETE SET NULL`, `termin DATE NULL`, `status ENUM('gemeldet','geplant','in_arbeit','wartet_auf_teile','erledigt','verworfen') NOT NULL DEFAULT 'gemeldet'`, `notizen TEXT NULL`, `was_gemacht TEXT NULL`, `abgeschlossen_am DATETIME NULL`, `erstellt_am`, `geaendert_am DATETIME(6) … ON UPDATE CURRENT_TIMESTAMP(6)`; Indizes `(objekt_typ, objekt_id, status)`, `(status, prioritaet)`, `(gemeldet_von_benutzer_id)`).
  - `ersatzteil` (id, `reparatur_id` FK CASCADE, `bezeichnung VARCHAR(150) NOT NULL`, `artikelnummer VARCHAR(60) NULL`, `bezugsquelle VARCHAR(150) NULL`, `kosten DECIMAL(8,2) NULL`, `bestellt TINYINT(1) NOT NULL DEFAULT 0`, `sortierung INT NOT NULL DEFAULT 0`).
  - `reparatur_gesehen` (`benutzer_id` FK CASCADE, `reparatur_id` FK CASCADE, `gesehen_am DATETIME NOT NULL`, PK beide) – wird in Task 5 befüllt.
- `enum ReparaturStatus: string` (`Gemeldet = 'gemeldet'`, `Geplant`, `InArbeit = 'in_arbeit'`, `WartetAufTeile = 'wartet_auf_teile'`, `Erledigt`, `Verworfen`); `label()`; `istOffen(): bool` (alle außer erledigt/verworfen); `const OFFEN_SQL = "('gemeldet','geplant','in_arbeit','wartet_auf_teile')"`. Prioritäten als `ReparaturRepository::PRIORITAETEN = ['dringend' => 'dringend', 'normal' => 'normal', 'niedrig' => 'niedrig']`.
- `StatusAutomatik::ziel(string $objektTyp, string $aktuell, bool $automatisch, list<array{prioritaet:string, status:string}> $offene): ?array{status:string, automatisch:bool}` (rein; null = nichts ändern). Regel:
  - Geschützt (nie angefasst): Fahrzeug `ausgeliehen`, `ausgemustert`.
  - Soll: irgendeine offene mit `dringend` → `defekt`; sonst irgendeine `in_arbeit` → `in_wartung` (Anlage `in_arbeit`); sonst kein Soll.
  - Soll gesetzt: `aktuell === soll` → null (Kennzeichen bleibt, auch ein von Hand gesetztes „defekt“ bleibt von Hand); sonst `[soll, true]`.
  - Kein Soll: nur wenn `automatisch` → `[einsatzbereit bzw. in_ordnung, false]` (deckt „letzte offene erledigt/verworfen“ und „dringend auf normal herabgestuft“ ab); sonst null.
- Von Hand geänderter Status löscht das Kennzeichen: `FahrzeugRepository::aktualisieren` und `AnlageRepository::aktualisieren` setzen in SET **vor** `status = ?` die Zuweisung `status_automatisch = IF(status = ?, status_automatisch, 0)` (gleicher Grund wie `AUSGEMUSTERT_AM`); `setzeAbgestellt` setzt `status_automatisch = 0`. Neu je Repository: `setzeStatusAutomatisch(int $id, string $status, bool $automatisch): void`.
- `Berechtigung`:
  - `darfReparaturBearbeiten(?Benutzer $b, string $objektTyp, array $objekt): bool` – gleiche Regel wie `darfWartungEintragen`.
  - `siehtDefekthinweise(?Benutzer $b, string $objektTyp): bool` – `fahrzeug` ab Rang 2, `anlage` ab Rang 3 (gleiche Regel wie `darfWartungsplanAendern`).
  - `ETIKETTEN_DRUCKEN = 'etiketten_drucken'` (Rang 2; genutzt in Task 6).
- `ReparaturRepository` (nur SQL, keine eigenen Transaktionen): `anlegen(string $objektTyp, int $objektId, array $d, ?int $benutzerId): int` (`gemeldet_am` = jetzt, Status `gemeldet`); `finde(int $id): ?array` (mit `gemeldet_von_name`, `zustaendig_name` – beide null, wenn Bezug fehlt –, `kosten_gesamt` als String per `SUM(kosten)`, `ersatzteile: list<array>`); `offeneFuerObjekt(string $typ, int $id): list<array{prioritaet:string,status:string}>`; `fuerObjekt(string $typ, int $id): list<array>` (offene zuerst, dann neueste); `aktualisieren(int $id, array $d, list<array> $teile, string $erwarteterStand): void` (wirft `KonfliktFehler`; ersetzt Ersatzteile; `abgeschlossen_am` = jetzt beim Wechsel auf erledigt/verworfen, NULL beim Wiederöffnen); `idsFuerObjekt(string $typ, int $id): list<int>`; `loeschen(int $id): void`.
- `ReparaturDienst::__construct(\PDO $pdo, ReparaturRepository $rep, FahrzeugRepository $fz, AnlageRepository $anl, DokumentRepository $dok, Aenderungsprotokoll $protokoll)`:
  - `wendeStatusAn(string $objektTyp, int $objektId, ?int $benutzerId): void` – Objekt `FOR UPDATE` lesen, `StatusAutomatik::ziel` auswerten, ggf. `setzeStatusAutomatisch`; bei geändertem Status Protokoll am Objekt `['status' => ['alt' => <alt>, 'neu' => '<neu> (automatisch durch Reparatur)']]`. Läuft in der Transaktion des Aufrufers.
  - `entferneFuerObjekt(string $objektTyp, int $objektId): void` – wie `WartungDienst::entferneFuerObjekt` (Fotos `reparatur` zuerst, dann Zeilen; Ersatzteile/Gesehen per CASCADE). `FahrzeugController::loeschen` und `AnlageController::loeschen` rufen es neben `WartungDienst::entferneFuerObjekt` auf (42S02 → nichts zu tun).

- [ ] **Step 1: Failing tests**
  - `StatusAutomatikTest` (Tabelle von Fällen): dringend offen + einsatzbereit → defekt/auto; nur `in_arbeit` → in_wartung/auto (Anlage → `in_arbeit`); dringend und `in_arbeit` → defekt (Vorrang); offen, normal, gemeldet → null; keine offenen + auto defekt → einsatzbereit/false; keine offenen + **von Hand** in_wartung → null; dringend + `ausgeliehen` bzw. `ausgemustert` → null; dringend + von Hand defekt → null; Herabstufung (offen normal, auto defekt) → einsatzbereit/false; Anlage keine offenen + auto defekt → `in_ordnung`.
  - `ReparaturDienstTest`: Reparatur anlegen + `wendeStatusAn` → Fahrzeug `defekt`, `status_automatisch` 1, Protokolleintrag „(automatisch durch Reparatur)“; zweite Reparatur `in_arbeit`, erste erledigt → `in_wartung`; letzte erledigt → `einsatzbereit`, Kennzeichen 0; nach automatischem „defekt“ von Hand auf „ausgeliehen“ (`FahrzeugRepository::aktualisieren`) → Kennzeichen 0, Erledigen lässt „ausgeliehen“; unverändert gespeichertes Fahrzeugformular behält das Kennzeichen; `setzeAbgestellt` löscht es; `entferneFuerObjekt` entfernt Reparaturen, Ersatzteile und Fotos.
  - `BerechtigungTest::test_reparatur_rechte`: Mitglied als Eigentümer bzw. Anleger true, fremd false; Fahrzeugwart fremdes privates Fahrzeug true, Anlage false; Bearbeiter Anlage true; `siehtDefekthinweise(fahrzeugwart, 'anlage')` false, `(bearbeiter, 'anlage')` true, `(mitglied, 'fahrzeug')` false; `etiketten_drucken` ab Fahrzeugwart.
  - `MigratorTest`: `014_reparatur`; Migration auf bestehenden Daten: vorhandene Fahrzeuge/Anlagen haben `status_automatisch = 0`.
- [ ] **Step 2:** Filter `StatusAutomatik|ReparaturDienst|Berechtigung|Migrator` → FAIL.
- [ ] **Step 3: Implement** wie in Interfaces; Container-Einträge für `ReparaturRepository` und `ReparaturDienst`.
- [ ] **Step 4:** Filter → PASS; volle Suite grün (bestehende Fahrzeug-/Anlagen-Tests unverändert).
- [ ] **Step 5: Commit** „Reparaturen: Datenmodell, Rechte und automatischer Status“.

### Task 3: Defekt melden und „Meine Meldungen“

**Files:**
- Create: `src/Reparatur/MeldungValidator.php`, `src/Reparatur/MeldungController.php`, `templates/reparatur/melden.php`, `templates/reparatur/meine.php`, `templates/reparatur/objekt_waehlen.php`
- Modify: `src/Dateien/UploadService.php`, `src/Dateien/DateiController.php`, `src/OneDrive/Pfadbildner.php`, `src/OneDrive/Uebertragung.php`, `src/Codes/CodeController.php` (`fuer=defekt`), `templates/fahrzeuge/detail.php`, `templates/fahrzeuge/_tabelle.php`, `templates/anlagen/detail.php`, `templates/anlagen/liste.php`, `templates/partials/navigation.php`, `src/Core/App.php` (Container, Pfadprüfung 3c), `src/routes.php`
- Test: `tests/Unit/Reparatur/MeldungValidatorTest.php`, `tests/Integration/Reparatur/MeldungHttpTest.php`, `tests/Unit/OneDrive/PfadbildnerTest.php` (ergänzen)

**Interfaces:**
- `MeldungValidator::pruefe(array $eingabe, string $objektTyp): array{0: array, 1: array<string,string>}` – `beschreibung` Pflicht (max. 2000): „Bitte beschreibe den Defekt.“; `wo_bauteil` nur bei Anlagen (max. 150), bei Fahrzeugen ignoriert; `einstufung` `dringend` | `normal` (Standard `normal`) → `prioritaet`.
- Routen (`lesen`): `GET /defekt` (Objekt wählen: Feld „Code, Digitaladresse oder Name“ → `GET /code?fuer=defekt`), `GET|POST /fahrzeuge/{id}/defekt`, `GET|POST /anlagen/{id}/defekt`, `GET /meine-meldungen`. `CodeController` hängt bei `fuer=defekt` den Zusatz `/defekt` an (Ziel und Links der Ergebnisliste).
- Formular (mobil zuerst, eine Spalte, große Schaltflächen): Objekt (Code + Bezeichnung/Name, Foto), „Was ist kaputt?“ (Textarea), bei Anlagen „Wo/Bauteil“ (Platzhalter „z. B. W12 Antrieb brummt“), Einstufung als zwei Radio-Kacheln „fährt nicht mehr“ / „fährt, aber …“, `fotos[]` (`accept="image/*" multiple`, ohne `capture`, damit Kamera **und** Galerie angeboten werden), Schaltfläche „Defekt melden“.
- Absenden: in einer Transaktion `ReparaturRepository::anlegen` + `ReparaturDienst::wendeStatusAn`; danach Fotos über `UploadService::speichere(…, reparaturId: $id)` – nur Bilder, sonst Warnung „<Datei>: Nur Fotos können an eine Meldung angehängt werden.“, die Meldung bleibt. Protokoll an `reparatur`/`angelegt` und am Objekt `['reparatur' => ['alt' => null, 'neu' => 'Defekt gemeldet: <beschreibung, auf 80 Zeichen gekürzt>']]`. Weiterleitung auf die Objektseite mit `Session::meldung('erfolg', 'Danke! Dein Defekt wurde gemeldet.', '/meine-meldungen', 'Meine Meldungen')`.
- `ReparaturRepository::vonBenutzer(int $benutzerId): list<array>` (neueste zuerst, mit Objektkennung und -name, Status) → `meine.php` mit Status-Badge; leer: „Du hast noch keine Defekte gemeldet.“
- `UploadService::speichere(…, ?int $wartungId = null, ?int $reparaturId = null)` und OneDrive-Zuordnung `reparatur`. `Pfadbildner::fuerDokument(array $dokument, ?array $fahrzeug, ?array $anlage = null, string $unterordner = '')` ersetzt den Parameter `bool $wartung` (Aufrufer übergeben `'Wartung'` bzw. `'Reparatur'`); `Uebertragung::hochladen` löst `reparatur` → Objekt auf wie `wartung`.
- `DateiController::loeschen`: Foto einer Reparatur löschen mit `darfReparaturBearbeiten` (Bearbeiter immer), Protokoll am Objekt, Rückweg `…/{id}#reparaturen`; Logik von `wartungsObjekte` auf beide Typen verallgemeinern.
- Schaltflächen „Defekt melden“: Fahrzeug- und Anlagenseite (oben, deutlich), je Zeile in Fahrzeug- und Anlagenliste (klein). Menü: Hauptpunkt „Defekt melden“ (`/defekt`).
- App 3c: Pfade `/defekt`, `/meine-meldungen`, `/reparaturen…`, `/etiketten…` sowie `…/defekt` bei ausstehender Migration → `/admin/update`.

- [ ] **Step 1: Failing tests**
  - `MeldungValidatorTest`: leere Beschreibung → Fehler; 2001 Zeichen → Fehler; `einstufung` `dringend` → `prioritaet` dringend, unbekannt → normal; `wo_bauteil` bei Fahrzeug verworfen.
  - `MeldungHttpTest`:
    - `test_mitglied_meldet_an_fremdem_fahrzeug`: 302 auf `/fahrzeuge/<id>`, Zeile `reparatur` Status `gemeldet`, `gemeldet_von_benutzer_id` = Mitglied, Meldung „Danke! Dein Defekt wurde gemeldet.“ mit Link `/meine-meldungen`.
    - `test_dringend_setzt_defekt`: „fährt nicht mehr“ → Fahrzeug `defekt`; „fährt, aber …“ → bleibt `einsatzbereit`.
    - `test_anlage_mit_wo_bauteil`: `wo_bauteil` = „W12 Antrieb brummt“, Anlage bei dringend `defekt`.
    - `test_foto_an_meldung`: JPG → Zuordnung `reparatur`; PDF → Warnung, Meldung gespeichert; Mitglied darf das Foto anschließend nicht löschen (403), Fahrzeugwart schon.
    - `test_leere_beschreibung_422`.
    - `test_defekt_ueber_code`: `GET /code?q=F12&fuer=defekt` → 302 auf `/fahrzeuge/<id>/defekt`.
    - `test_meine_meldungen`: zeigt nur eigene Meldungen mit Status.
    - Fahrzeugseite, Anlagenseite und Fahrzeugliste enthalten „Defekt melden“; Menü enthält „Defekt melden“.
  - `PfadbildnerTest::test_reparatur_unterordner`: Fahrzeug `EMC-0012 V 200` → `Fahrzeuge/EMC-0012 V 200/Reparatur/Foto.jpg`; bestehender Wartungstest mit `'Wartung'`.
- [ ] **Step 2:** Filter `Meldung|Pfadbildner|Datei` → FAIL.
- [ ] **Step 3: Implement** wie in Interfaces.
- [ ] **Step 4:** Filter → PASS; volle Suite grün.
- [ ] **Step 5: Commit** „Defekt melden am Handy mit Fotos und „Meine Meldungen““.

### Task 4: Reparatur bearbeiten, Seite „Reparaturen“ und Reparaturblock

**Files:**
- Create: `src/Reparatur/ReparaturValidator.php`, `src/Reparatur/ReparaturFilter.php`, `src/Reparatur/ReparaturController.php`, `templates/reparatur/liste.php`, `templates/reparatur/detail.php`, `templates/reparatur/formular.php`, `templates/reparatur/_block.php`, `templates/reparatur/_labels.php`
- Modify: `src/Reparatur/ReparaturRepository.php` (`suche`), `src/Fahrzeuge/FahrzeugController.php` + `templates/fahrzeuge/detail.php` (Tab „Reparaturen“), `src/Anlagen/AnlageController.php` + `templates/anlagen/detail.php` (Abschnitt „Reparaturen“), `templates/partials/navigation.php`, `src/Core/App.php`, `src/routes.php`
- Test: `tests/Unit/Reparatur/ReparaturValidatorTest.php`, `tests/Integration/Reparatur/ReparaturHttpTest.php`

**Interfaces:**
- `ReparaturValidator::pruefe(array $eingabe, list<int> $personIds, string $objektTyp): array{0: array, 1: array<string,string>, 2: list<array>}` (Daten, Fehler, Ersatzteile):
  - Beschreibung Pflicht (wie Meldung), `wo_bauteil` nur Anlagen, `prioritaet` aus `PRIORITAETEN`, `status` aus `ReparaturStatus`, `zustaendig` leer oder aktive Person, `termin` leer oder gültiges Datum („Bitte ein gültiges Datum angeben.“), `notizen` max. 5000.
  - Status `erledigt` verlangt `was_gemacht`: „Bitte kurz beschreiben, was gemacht wurde.“
  - Ersatzteile `teile[<n>][bezeichnung|artikelnummer|bezugsquelle|kosten|bestellt]`; komplett leere Zeilen entfallen; sonst Bezeichnung Pflicht: „Bitte eine Bezeichnung für das Ersatzteil angeben.“; Kosten leer oder `^\d{1,6}([.,]\d{1,2})?$` → String mit Punkt („12,5“ → „12.50“), sonst „Bitte die Kosten als Betrag angeben, z. B. 12,50.“ (Fehlerschlüssel `teile.<n>.kosten`).
- `ReparaturFilter` (readonly): `?string $status` (Statuswert oder `offen`, Standard `offen`, `alle`), `?string $typ` (`fahrzeug`/`anlage`), `?int $zustaendigId`; `static ausRequest(Request $r): self`. `ReparaturRepository::suche(ReparaturFilter $f): list<array>` – mit Objektkennung (Code bzw. Inventarnummer), Objektname, Spurweite, `kosten_gesamt`; sortiert dringend vor normal vor niedrig, dann `gemeldet_am` absteigend.
- Routen: `GET /reparaturen`, `GET /reparaturen/{id}` (`lesen`); `GET /reparaturen/{id}/bearbeiten`, `POST /reparaturen/{id}`, `POST /reparaturen/{id}/fotos` (`lesen` + `darfReparaturBearbeiten` im Controller, 403).
- Speichern: Stand-Feld `stand` (= `geaendert_am`) wie bei Fahrzeugen; in einer Transaktion `ReparaturRepository::aktualisieren` + `ReparaturDienst::wendeStatusAn`; `KonfliktFehler` → 409 mit dem Formular und dem Hinweis wie bei Fahrzeugen, nichts gespeichert. Protokoll an `reparatur` per `Aenderungsprotokoll::diff` (Ersatzteile als Text „<Bezeichnung> (<Kosten>)“). Erfolgsmeldungen „Die Reparatur wurde gespeichert.“, bei `verworfen` „Die Reparatur wurde verworfen.“ Verwerfen erfolgt über den Status (kein Löschen).
- Detailseite: alle Felder, gemeldet von (fehlt der Bezug: „ehemaliges Mitglied“), Ersatzteile-Tabelle mit „Kosten gesamt“ (`12,50 €`), Fotos (mit „Foto löschen“ bei Recht), Schaltfläche „Bearbeiten“ nur mit Recht. Formular: Ersatzteile als Tabellenzeilen plus eine leere Zeile; „Zeile hinzufügen“ per `assets/app.js` (ohne JS bleibt die eine leere Zeile).
- `_block.php` (Parameter `$objektTyp`, `$objekt`, `$reparaturen`, `$darfBearbeiten`): offene oben, abgeschlossene darunter, Link je Reparatur, Schaltfläche „Defekt melden“. Daten per `ReparaturDienst::blockDaten(string $objektTyp, array $objekt): ?array` (42S02 → null, Block fehlt). Fahrzeugseite: Tab „Reparaturen“ nach „Wartung“ mit Anzahl offener als Badge; Anlagenseite: Abschnitt „Reparaturen“ (Anker `#reparaturen`).
- Menü: Hauptpunkt „Reparaturen“ nach „Wartung“ (für alle). Liste mit Filterleiste Status (Standard „offen“), Objekt (Fahrzeuge/Anlagen), zuständig.

- [ ] **Step 1: Failing tests**
  - `ReparaturValidatorTest`: Kosten `12,5` → `12.50`, `12.50`, `1234` gültig; `-1`, `12,345`, `1.234,50`, `abc` → Fehler; leere Ersatzteilzeile entfällt; Zeile nur mit Kosten → Bezeichnungsfehler; `erledigt` ohne `was_gemacht` → Fehler; unbekannte Person → Fehler.
  - `ReparaturHttpTest`:
    - `test_fahrzeugwart_plant_und_erledigt`: Status `geplant`, zuständig, Termin, zwei Ersatzteile (12,50 und 7,25) → Detailseite zeigt „19,75 €“; `in_arbeit` → Fahrzeug `in_wartung`; `erledigt` mit „Was wurde gemacht“ → `abgeschlossen_am` gesetzt, Fahrzeug `einsatzbereit`.
    - `test_mitglied_an_eigenem_fahrzeug_erlaubt_fremd_403` (GET Formular und POST).
    - `test_fahrzeugwart_an_anlage_403_bearbeiter_erlaubt`.
    - `test_verwerfen_statt_loeschen`: `verworfen` → Zeile bleibt, Meldung „Die Reparatur wurde verworfen.“, in Filter „offen“ nicht mehr sichtbar, in „alle“ schon; es gibt keine Löschroute (`POST /reparaturen/{id}/loeschen` → 404).
    - `test_gleichzeitige_bearbeitung`: zweimal mit demselben `stand` speichern → zweites 409, Beschreibung und Ersatzteile des ersten bleiben.
    - `test_liste_filter_und_sortierung`: dringend vor normal; Filter `typ=anlage`, `zustaendig`, `status=alle`.
    - `test_ehemaliges_mitglied`: `gemeldet_von_benutzer_id` NULL → „ehemaliges Mitglied“.
    - Fahrzeugseite hat Tab „Reparaturen“ mit der offenen Reparatur; Anlagenseite Abschnitt „Reparaturen“; Menü enthält „Reparaturen“.
- [ ] **Step 2:** Filter `Reparatur` → FAIL.
- [ ] **Step 3: Implement** nach dem Muster von `FahrzeugController::aendern` (Stand, Konflikt, Protokoll) und `WartungController` (Fotos).
- [ ] **Step 4:** Filter → PASS; volle Suite grün.
- [ ] **Step 5: Commit** „Reparaturen bearbeiten: Ersatzteile, Kosten, Status, Verwerfen und Übersicht“.

### Task 5: Hinweise „neue Defektmeldungen“ und Kachel „Offene Defekte“

**Files:**
- Modify: `src/Reparatur/ReparaturRepository.php`, `src/Reparatur/ReparaturController.php` (Gesehen vermerken), `src/Core/App.php` (`betriebshinweise`), `src/Start/StartController.php`, `templates/start.php`
- Test: `tests/Integration/Reparatur/HinweisHttpTest.php`, `tests/Integration/Wartung/WartungUebersichtHttpTest.php` (Startseite weiter grün)

**Interfaces:**
- „Neu“ = Status `gemeldet` **und** noch keine Zeile in `reparatur_gesehen` (gleich von wem). Eine Zeile entsteht nur, wenn jemand mit `siehtDefekthinweise` für den Objekttyp die Detailseite öffnet; das Melden selbst zählt nicht. Eigene Meldungen zählen im eigenen Hinweis nicht mit.
- `ReparaturRepository::markiereGesehen(int $reparaturId, int $benutzerId): void` (`INSERT IGNORE`); `anzahlNeu(bool $fahrzeuge, bool $anlagen, int $ausserBenutzerId): int`; `offeneDefekte(int $max = 5): array{anzahl:int, dringend:int, liste:list<array>}` (offene, dringende zuerst, dann älteste Meldung zuerst; mit Objektkennung, -name und Link).
- Betriebshinweis (nur ohne ausstehende Migration, in eigenem `try`): für Benutzer mit `siehtDefekthinweise(b, 'fahrzeug')` oder `(b, 'anlage')`: „1 neue Defektmeldung“ / „N neue Defektmeldungen“, Link `/reparaturen?status=gemeldet`, Linktext „Ansehen“, Typ `warning`.
- Startseite: Kachel „Offene Defekte“ vor der Wartungs-Kachel (für alle): „N offen“, davon „M dringend“ (rotes Badge), die fünf dringendsten mit Link aufs Objekt und Status, Link „Alle Reparaturen“, leer: „Keine offenen Defekte.“; 42S02 → Kachel fehlt (wie `wartung()`). Schnellzugriff um „Defekt melden“ ergänzen. Kommentar in `start.php`/`StartController` („Stufe 2c ergänzt …“) entsprechend anpassen.

- [ ] **Step 1: Failing tests** (`HinweisHttpTest`)
  - `test_fahrzeugwart_sieht_neue_fahrzeugmeldung`: Mitglied meldet am Fahrzeug → Fahrzeugwart sieht „1 neue Defektmeldung“; Meldung an einer Anlage ändert für ihn nichts, Bearbeiter sieht „2 neue Defektmeldungen“.
  - `test_oeffnen_macht_nicht_mehr_neu`: Fahrzeugwart öffnet `/reparaturen/{id}` → Hinweis weg, auch für den Bearbeiter; öffnet stattdessen das Mitglied (oder ein Fahrzeugwart eine Anlagenmeldung), bleibt sie neu.
  - `test_statuswechsel_macht_nicht_mehr_neu`: Status `geplant` → nicht mehr gezählt.
  - `test_eigene_meldung_zaehlt_nicht`: Fahrzeugwart meldet selbst → kein Hinweis für ihn, wohl für den Bearbeiter.
  - `test_mitglied_sieht_keinen_hinweis`.
  - `test_startseite_offene_defekte`: normale und dringende Meldung → Kachel „Offene Defekte“, „2 offen“, „1 dringend“, dringende zuerst; nach Erledigen beider „Keine offenen Defekte.“
- [ ] **Step 2:** Filter `Hinweis|WartungUebersicht` → FAIL.
- [ ] **Step 3: Implement** wie in Interfaces.
- [ ] **Step 4:** Filter → PASS; volle Suite grün.
- [ ] **Step 5: Commit** „Hinweise auf neue Defektmeldungen und Kachel „Offene Defekte““.

### Task 6: Etiketten (Fahrzeug-Klebchen, Verpackung, Anlagen-QR)

**Files:**
- Create: `migrations/015_etikett.sql`, `src/Etiketten/Bogen.php`, `src/Etiketten/QrCode.php`, `src/Etiketten/EtikettAuswahl.php`, `src/Etiketten/EtikettController.php`, `templates/etiketten/auswahl.php`, `templates/etiketten/druck.php` (eigenes Layout ohne Menü, `View::render(…, null)`)
- Modify: `composer.json`, `composer.lock`, `public/assets/app.css` (Druckstile), `templates/fahrzeuge/detail.php` + `templates/anlagen/detail.php` (Link „Etikett drucken“ mit Recht), `templates/partials/navigation.php` („Verwaltung › Etiketten“), `src/Core/App.php`, `src/routes.php`, `tests/Integration/Betrieb/MigratorTest.php`
- Test: `tests/Unit/Etiketten/BogenTest.php`, `tests/Unit/Etiketten/QrCodeTest.php`, `tests/Integration/Etiketten/EtikettHttpTest.php`

**Interfaces:**
- Abhängigkeit: `composer require --prefer-dist chillerlan/php-qrcode:^5.0`, danach `git remote -v` prüfen (unverändert `origin https://github.com/brokermaler-ship-it/Kleine-Helfer-gro-e-Wirkung`), `composer.json` und `composer.lock` committen (`vendor/` bleibt ungetrackt; `bin/release.sh` installiert es per `composer install --no-dev` ins Paket).
- Migration 015: `ALTER TABLE fahrzeug ADD COLUMN etikett_gedruckt_am DATETIME NULL;` und ebenso `anlage`.
- `Bogen` (readonly, mm): `breite`, `hoehe`, `spalten`, `zeilen`, `randOben`, `randLinks`, `abstandX`, `abstandY`; `static klebchen(): self` = 25,4 × 10, 7 × 27 = **189**, Rand oben 13,5, links 8,48, Abstand X 2,54, Y 0 (Avery Zweckform L4731; Kommentar „bei anderem Bogen nur hier anpassen, Probedruck auf Normalpapier“); `static anlage(): self` = 70 × 37, 3 × 8 = **24**, Rand oben 0,5, links 0, Abstände 0; `proBogen(): int`; `verteile(list<T> $etiketten, int $start): list<list<?T>>` – Bögen mit Leerfeldern vor `$start` auf dem ersten Bogen; `$start` außerhalb 1…`proBogen()` → 1.
- `QrCode::svg(string $text): string` – `chillerlan\QRCode\QRCode` mit `QROptions` (`outputType` = `QROutputInterface::MARKUP_SVG`, `outputBase64` = false, `eccLevel` = `EccLevel::M`, `addQuietzone` = true); Ergebnis ohne XML-Kopf (falls die Option fehlt: `<?xml…?>` abschneiden), damit es inline ins HTML passt.
- Inhalt: Klebchen nur `F12`, fett, Versalhöhe ca. 3 mm (`font-size: 4.2mm`), zentriert; Verpackung (gleicher Bogen) `F12` fett plus Bezeichnung in höchstens zwei Zeilen (`font-size: 1.9mm`, Überlauf abgeschnitten); Anlage: QR links (ca. 30 × 30 mm) mit Kurzlink `<Basis>/c/A3`, rechts Name und `A3` groß. Basis = `rtrim(oeffentliche_url, '/') . basis_url`; ist `oeffentliche_url` leer, Schema und Host der Anfrage, und die Auswahlseite warnt „Bitte oeffentliche_url in der config.php eintragen, damit die QR-Codes auch später stimmen.“ Fahrzeuge ohne Code (`Code::fahrzeug` null) werden übersprungen und als Hinweis aufgezählt.
- `EtikettAuswahl::__construct(\PDO $pdo)`; `fahrzeuge(string $auswahl, array $ids, ?int $spurweiteId): list<array>` mit `auswahl` `einzeln` (`ids[]`, auch ausgemusterte), `spurweite`, `loks` (Gattung `lok` und `triebwagen`), `ungedruckt` (`etikett_gedruckt_am IS NULL`); außer bei `einzeln` ohne ausgemusterte; sortiert nach Inventarnummer. `anlagen(string $auswahl, array $ids): list<array>` (`einzeln`, `alle`, `ungedruckt`). `vermerkeGedruckt(string $typ, list<int> $ids): int`.
- Routen (`etiketten_drucken`): `GET /etiketten` (Formular: Art `klebchen`/`verpackung`/`anlage`, Auswahl, Spurweite, Startposition 1–189 bzw. 1–24, Anzahl je Auswahl), `GET /etiketten/druck` (gleiche Parameter; Seite mit Hinweis „Beim Drucken „Tatsächliche Größe“ bzw. 100 % und Ränder „Keine“ wählen.“, Schaltflächen „Drucken“ (`window.print()`) und Formular „Als gedruckt vermerken“; der Hinweis- und Schaltflächenbereich ist `@media print` ausgeblendet), `POST /etiketten/gedruckt` (`typ`, `ids[]`) → Meldung „1 Etikett als gedruckt vermerkt.“ bzw. „N Etiketten als gedruckt vermerkt.“, zurück auf `/etiketten`.
- Druck-CSS: `@page { size: A4; margin: 0 }`, je Bogen ein Block 210 × 297 mm mit `break-after: page`, Felder absolut in mm aus `Bogen` (inline `style`).

- [ ] **Step 1: Failing tests**
  - `BogenTest`: `klebchen()->proBogen()` = 189, `anlage()->proBogen()` = 24; Klebchen passt auf A4 (`randLinks + spalten·breite + (spalten−1)·abstandX ≤ 210`, Zeilen ≤ 297); `verteile(10 Etiketten, 185)` → 2 Bögen, Bogen 1 mit 184 Leerfeldern und 5 Etiketten, Bogen 2 mit 5; `verteile(…, 0)` und `(…, 190)` wie Start 1; leere Liste → keine Bögen.
  - `QrCodeTest`: beginnt mit `<svg`, enthält `viewBox`, kein `<?xml`; gleicher Text → gleiche Ausgabe; anderer Text → andere Ausgabe.
  - `EtikettHttpTest`:
    - Mitglied `GET /etiketten` → 403; Fahrzeugwart 200.
    - `test_klebchen_nur_code`: `GET /etiketten/druck?art=klebchen&auswahl=einzeln&ids[]=<id>` → enthält `F12`, nicht die Bezeichnung; `art=verpackung` enthält beide.
    - `test_ungedruckt_ohne_ausgemusterte_und_nur_post_vermerkt`: zwei Fahrzeuge, eins ausgemustert → Druckseite enthält nur das andere; danach ist `etikett_gedruckt_am` noch NULL; `POST /etiketten/gedruckt` → gesetzt, Meldung „1 Etikett als gedruckt vermerkt.“, neue Auswahl `ungedruckt` leer.
    - `test_loks_und_spurweite`: `loks` liefert Lok und Triebwagen, keinen Wagen; `spurweite` nur diese Spurweite.
    - `test_anlage_qr_mit_kurzlink`: mit `oeffentliche_url = https://verein.example` → Seite enthält `<svg` und Name, `A1`; Kurzlink-Text `https://verein.example/c/A1` im `title`/`aria-label`.
    - `test_fahrzeug_ohne_code_wird_uebersprungen`: Inventarnummer `ALT-7` → Hinweis mit `ALT-7`.
  - `MigratorTest`: `015_etikett`.
- [ ] **Step 2:** Filter `Bogen|QrCode|Etikett|Migrator` → FAIL (QrCode zuerst wegen fehlender Bibliothek).
- [ ] **Step 3: Implement** – zuerst die Abhängigkeit (`composer require --prefer-dist chillerlan/php-qrcode:^5.0`, `git remote -v` prüfen), dann wie in Interfaces.
- [ ] **Step 4:** Filter → PASS; volle Suite grün. Druckseite einmal im Browser als PDF drucken und Maße prüfen (Klebchen 25,4 × 10 mm, 7 × 27).
- [ ] **Step 5: Commit** „Etiketten: Fahrzeug-Klebchen, Verpackung und Anlagen-QR zum Drucken“ (mit `composer.json`, `composer.lock`).

### Task 7: DSGVO-Löschung für Reparaturen, Beispieldaten, README, Version 0.6.0

**Files:**
- Modify: `src/Personen/DsgvoLoeschung.php`, `templates/personen/dsgvo.php`, `src/Core/App.php` (Konstruktor-Argument), `src/Betrieb/DemoDaten.php`, `README.md`, `VERSION` → `0.6.0`
- Test: `tests/Integration/Personen/DsgvoLoeschungTest.php`, `tests/Integration/Betrieb/DemoDatenTest.php`

**Interfaces:**
- Consumes: `ReparaturRepository::idsFuerObjekt`/`loeschen` (Task 2), Dokument-Hilfen der DSGVO-Löschung (`dokumenteNurHier($id, 'reparatur')`, `loescheDokument`) – innerhalb der bestehenden Transaktion, ohne eigene Transaktionen.
- `DsgvoLoeschung::__construct(…, ?ReparaturRepository $reparaturen = null)` (Standard `new ReparaturRepository($pdo)` wie bei Wartungen).
- `loesche`, Schritt 3 je gelöschtem eigenen Fahrzeug (Eigentümer = Person): Fotos jeder Reparatur (nur dort zugeordnete) per `loescheDokument` (Pfade sammeln), Zuordnungen `reparatur` entfernen, Reparaturen löschen (Ersatzteile und Gesehen per CASCADE), `['reparatur', $rid]` zu `$eigeneObjekte` (Protokoll-Einträge entfernt). 42S02 vor Migration 014 → keine Reparaturen.
- Übergebene und Vereins-/fremde Objekte behalten ihre Reparaturen; `gemeldet_von_benutzer_id` (Konto gelöscht, Schritt 4) und `zustaendig_person_id` (Person gelöscht, Schritt 6) leeren sich per `ON DELETE SET NULL`; `reparatur_gesehen` des Kontos per CASCADE. Freitexte (Beschreibung, Notizen) werden nicht durchsucht – im README erwähnen.
- `vorschau()`: je Fahrzeug zusätzlich `reparaturen:int`; außerdem `reparaturen_in_vereinsdaten:int` (an anderen Objekten gemeldet oder zuständig). Vorschau-Seite nennt beide Zahlen.
- `DemoDaten`: eine dringende Meldung „Lok bleibt auf der Weiche stehen, Motor brummt“ an einer Demo-Lok von einem Demo-Mitglied (Status `gemeldet`, Fahrzeug dadurch automatisch „defekt“ über `ReparaturDienst::wendeStatusAn`), eine Anlagen-Meldung an „Clubanlage H0“ mit Wo/Bauteil „W12 Antrieb brummt“ (normal, `geplant`, zuständig Demo-Bearbeiter, Termin in 7 Tagen), eine erledigte Reparatur mit zwei Ersatzteilen (Haftreifen 4,90 €, Schleifer 7,50 €) und „Was wurde gemacht“. Daten relativ zu heute. Das schon manuell `in_wartung` stehende Demo-Fahrzeug bleibt unberührt.
- README: Abschnitte „Defekt melden“ (Ablauf am Handy, QR an der Anlage), „Reparaturen“ (Status, Ersatzteile, Verwerfen statt Löschen, automatischer Status und seine Grenzen), „Codes“ (F/A, Zahl = Digitaladresse, Kurzlink), „Etiketten“ (Bögen L4731 bzw. 70 × 37 mm/24, Startposition, 100 %-Druck, `oeffentliche_url` für QR); Rollentabelle um Reparaturen und Etiketten; DSGVO-Abschnitt um Reparaturen; Hinweis auf die neue Bibliothek `chillerlan/php-qrcode` (im Release enthalten).

- [ ] **Step 1: Failing tests**
  - `DsgvoLoeschungTest::test_eigene_fahrzeuge_mit_reparaturen_geloescht`: eigenes Fahrzeug mit Reparatur, Ersatzteil, Foto und Gesehen-Zeile → `reparatur`, `ersatzteil`, `reparatur_gesehen` dazu leer, Datei vom Webspace weg, kein Protokolleintrag `reparatur` mehr.
  - `test_reparatur_am_vereinsfahrzeug_bleibt_bezuege_geleert`: gemeldet von der Person und ihr zuständig → `gemeldet_von_benutzer_id` und `zustaendig_person_id` NULL, Reparatur vorhanden, Detailseite zeigt „ehemaliges Mitglied“.
  - `test_uebergebenes_fahrzeug_behaelt_reparaturen`, `test_vorschau_zaehlt_reparaturen`.
  - `test_fehler_mitten_drin_laesst_reparaturen_unveraendert` (bestehenden Rollback-Test um eine Reparatur erweitern).
  - `DemoDatenTest`: nach `erzeuge(true)` genau drei Reparaturen, `offeneDefekte()['dringend']` ≥ 1, die Demo-Lok mit der dringenden Meldung hat Status `defekt` mit `status_automatisch = 1`.
- [ ] **Step 2:** Filter `DsgvoLoeschung|DemoDaten` → FAIL.
- [ ] **Step 3: Implement** wie in Interfaces; README und `VERSION` (`0.6.0`).
- [ ] **Step 4:** Volle Suite grün; `bash bin/release.sh` erzeugt `dist/emc78-verwaltung-0.6.0.zip`; `unzip -l` zeigt `migrations/014_reparatur.sql`, `migrations/015_etikett.sql`, `src/Reparatur/StatusAutomatik.php`, `src/Etiketten/QrCode.php` und `vendor/chillerlan/php-qrcode/`, keine `tests/Support`-Dateien und kein `vendor/phpunit`; `git remote -v` unverändert.
- [ ] **Step 5: Commit** „Stufe 2c: DSGVO-Löschung für Reparaturen, Beispieldaten, README, Version 0.6.0“.
