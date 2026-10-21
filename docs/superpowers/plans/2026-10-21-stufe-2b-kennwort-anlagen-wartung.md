# Stufe 2b – Kennwort vergessen, Anlagenliste und Wartung – Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Selbstbedienung „Kennwort vergessen“ (E-Mail per SMTP mit Rückfall auf eine Anfrage an die Administratoren), eine einfache Anlagenliste mit Code `A<n>` sowie Wartungsarten, Wartungsplan mit Ausnahmen, Wartungsprotokoll und Fälligkeits-Ampel für Fahrzeuge und Anlagen.

**Architecture:** Neuer Namensraum `Emc\Mail` (kleine SMTP-Klasse hinter dem Interface `Mailer`, Socket-Zugriff hinter `SmtpVerbindung`, damit Tests ohne Netz laufen) und Dienst `Emc\Auth\KennwortVergessen` (Tokens, Anfragen, Begrenzung). `Emc\Anlagen` folgt dem Muster der Fahrzeuge (Repository, Validator, Controller, Stand-Prüfung, `dokument_zuordnung` mit `objekt_typ = 'anlage'`). `Emc\Wartung` trennt die reine Berechnung (`Faelligkeitsrechner`, ohne Datenbank, voll unit-testbar) vom Zusammenstellen der Daten (`Wartungsplan`). Die neue Startseite `/` zeigt die Wartungs-Kachel (2c ergänzt dort „Offene Defekte“).

**Tech Stack:** PHP 8.2, PDO/MariaDB 10.6+/MySQL 8, PHPUnit 11, bestehendes Template-System (`View`, `e()`, `url()`), Bootstrap 5; keine neuen Composer-Abhängigkeiten.

**Spec:** `docs/superpowers/specs/2026-10-20-stufe-2-wartung-reparatur-design.md` (Abschnitte 2 Zeile 2b, 3, 5, 7a, 8–10); Hauptspezifikation `docs/superpowers/specs/2026-10-18-emc78-fahrzeug-anlagen-verwaltung-design.md` (4.2).

## Global Constraints

- PHP ≥ 8.2, kein Framework; MariaDB ≥ 10.6 und MySQL ≥ 8 (SQL muss auf beiden laufen). Keine neue Composer-Abhängigkeit (auch nicht für SMTP).
- Neue Migrationen `010_kennwort_vergessen.sql`, `011_anlage.sql`, `012_wartungsart.sql`, `013_wartung.sql`; 001–009 nicht ändern. `MigratorTest` (Versionslisten) je Task ergänzen.
- Ränge: Mitglied 1, Fahrzeugwart 2, Bearbeiter 3, Admin 4. Neue Aktionen: `anlage_bearbeiten` (3), `wartungsart_verwalten` (2).
- Oberfläche und Meldungen auf Deutsch, Anrede „du“. Entfernte Personenbezüge zeigen exakt **„ehemaliges Mitglied“** (`Aenderungsprotokoll::EHEMALIGES_MITGLIED`).
- Neue Seiten und Links rund ums Zurücksetzen heißen „Kennwort“ (Spezifikation 7a: Link **„Kennwort vergessen?“**); bestehende Texte mit „Passwort“ bleiben unverändert. Kennwortregel wie bisher: `BenutzerRepository::pruefePasswort` (mindestens `MIN_PASSWORT` = 10 Zeichen).
- Konfiguration (alle optional, in `config.example.php` mit Kommentar): `mail_smtp_host`, `mail_smtp_port` (Standard 465), `mail_smtp_verschluesselung` (`ssl`/`tls`, Standard `ssl`), `mail_smtp_benutzer`, `mail_smtp_passwort`, `mail_absender`, `mail_absender_name` (Standard „EMC 78 Lokschuppen“). E-Mail-Versand gilt als eingerichtet, wenn `mail_smtp_host`, `mail_absender` und `oeffentliche_url` gesetzt sind.
- Objektbezüge über `objekt_typ` (`fahrzeug`, `anlage`) + `objekt_id`; Fotos/Dokumente über `dokument_zuordnung`. Personenbezüge `NULL`-bar mit `ON DELETE SET NULL`.
- Alle POST-Formulare mit CSRF-Token (globaler Schutz in `App`), Ausgaben mit `e()`. Datumsanzeige `d.m.Y`, Eingabe von Stunden mit Komma oder Punkt.
- Tests: `TEST_DB_PASSWORT=test vendor/bin/phpunit` muss nach jedem Task vollständig grün sein.
- Commit-Nachrichten auf Deutsch, enden mit den Zeilen `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` und `Claude-Session: https://claude.ai/code/session_01WPjn12EeTpMLbw1UrLaWZi`. Nicht pushen.

## Review Focus

1. **Ausforschen von Konten über „Kennwort vergessen“:** bekannte, unbekannte, deaktivierte Adresse, überschrittene Begrenzung und gescheiterter Versand liefern denselben Status und denselben Seitentext; nur bei bekanntem aktivem Konto entsteht ein Token oder eine Anfrage. → Test in Task 1.
2. **Alter, benutzter oder abgelaufener Link** (auch: zweiter Link nach neuer Anfrage, Link eines inzwischen deaktivierten Kontos, manipulierter Token): Formular wird nicht angezeigt, Kennwort bleibt unverändert, Meldung „Der Link ist ungültig oder abgelaufen.“ → Test in Task 1.
3. **Monatsende und Stichtag:** letzte Wartung am 31.08. + 6 Monate ist am 28.02. (Schaltjahr 29.02.) fällig, nicht am 03.03.; am Fälligkeitstag selbst gelb, ab dem Folgetag rot. → Test in Task 5.
4. **Nachgetragene ältere Wartung mit kleinerem Stundenstand:** senkt `fahrzeug.betriebsstunden` nicht und verdrängt nicht die jüngere Wartung als Bezug der Fälligkeit (maßgeblich ist das jüngste Datum). → Tests in Task 4 und Task 5.
5. **Fahrzeug wechselt Gattung oder geht aufs Abstellgleis:** passende Wartungsarten ändern sich sofort (Wagen → Lok bekommt die Lok-Arten als „noch nie erfasst“), ausgemusterte Fahrzeuge verschwinden aus Übersicht und Kachel, ihr Verlauf bleibt. → Test in Task 5.

---

### Task 1: Kennwort vergessen

**Files:**
- Create: `migrations/010_kennwort_vergessen.sql`, `src/Mail/Mailer.php`, `src/Mail/Nachricht.php`, `src/Mail/MailFehler.php`, `src/Mail/SmtpVerbindung.php`, `src/Mail/StreamVerbindung.php`, `src/Mail/SmtpMailer.php`, `src/Auth/KennwortVergessen.php`, `src/Auth/KennwortController.php`, `templates/auth/kennwort_vergessen.php`, `templates/auth/kennwort_neu.php`, `tests/Support/FakeSmtpVerbindung.php`, `tests/Support/FakeMailer.php`
- Modify: `src/Auth/Auth.php` (Kennwort-Stand, `anmeldenAls`), `src/Core/App.php` (Container, öffentliche Pfade, Betriebshinweis), `src/routes.php`, `templates/auth/login.php` (Link), `src/Personen/BenutzerController.php` + `templates/admin/benutzer/liste.php` (Anfragen), `src/Personen/DsgvoLoeschung.php`, `config.example.php`, `tests/Integration/Betrieb/MigratorTest.php`
- Test: `tests/Unit/Mail/SmtpMailerTest.php`, `tests/Integration/Auth/KennwortVergessenTest.php`, `tests/Integration/Auth/KennwortHttpTest.php`, `tests/Integration/Personen/DsgvoLoeschungTest.php` (ergänzen)

**Interfaces:**
- Migration 010:
  - `ALTER TABLE benutzer ADD COLUMN kennwort_stand INT UNSIGNED NOT NULL DEFAULT 0;`
  - `kennwort_token` (id, `benutzer_id` FK → benutzer `ON DELETE CASCADE`, `token_hash CHAR(64) NOT NULL UNIQUE`, `gueltig_bis DATETIME NOT NULL`, `verwendet_am DATETIME NULL`, `erstellt_am`)
  - `kennwort_anfrage` (id, `benutzer_id` FK CASCADE, `erstellt_am DATETIME NOT NULL`, `erledigt_am DATETIME NULL`, `erledigt_von_benutzer_id` FK → benutzer `ON DELETE SET NULL`, Index `erledigt_am`)
  - `kennwort_versuch` (id, `email_hash CHAR(64)`, `ip_hash CHAR(64)`, `zeitpunkt DATETIME`, Indizes `(email_hash, zeitpunkt)`, `(ip_hash, zeitpunkt)`)
- `Mail\Nachricht` (readonly): `__construct(string $an, string $betreff, string $text)`.
- `Mail\Mailer` (Interface): `sende(Nachricht $n): void` – wirft `MailFehler` (extends `\RuntimeException`; Meldung nie mit Kennwort).
- `Mail\SmtpVerbindung` (Interface): `oeffne(string $host, int $port, bool $ssl, int $zeitlimit): void`, `schreibe(string $zeile): void` (hängt CRLF an), `liesAntwort(): array{code:int, text:string}` (mehrzeilige `250-…`-Antworten zusammengefasst), `starteTls(): void`, `schliesse(): void`. `StreamVerbindung` implementiert das mit `stream_socket_client` (`ssl://` bzw. `tcp://`, Zertifikatsprüfung an), `stream_socket_enable_crypto(…, STREAM_CRYPTO_METHOD_TLS_CLIENT)`, `stream_set_timeout`.
- `Mail\SmtpMailer implements Mailer`: `__construct(SmtpVerbindung $v, string $host, int $port, string $verschluesselung, string $benutzer, string $passwort, string $absender, string $absenderName, int $zeitlimit = 15)`; `static ausConfig(array $config, ?SmtpVerbindung $v = null): ?self` (null, wenn `mail_smtp_host`, `mail_absender` oder `oeffentliche_url` leer).
  - Dialog: 220 → `EHLO <Domain des Absenders>` 250 → bei `tls`: `STARTTLS` 220, `starteTls()`, erneut `EHLO` → `AUTH LOGIN` 334, Benutzer base64 334, Kennwort base64 235 (Auth nur, wenn Benutzer gesetzt) → `MAIL FROM:<absender>` 250 → `RCPT TO:<an>` 250/251 → `DATA` 354 → Nachricht, `.` 250 → `QUIT`. Jeder andere Code → `MailFehler('SMTP: unerwartete Antwort <code> auf <Befehl>')` (für AUTH nur „AUTH“), Verbindung immer schließen.
  - Nachricht: Kopfzeilen `Date` (RFC 2822), `From: =?UTF-8?B?…?= <absender>`, `To`, `Subject: =?UTF-8?B?…?=`, `Message-ID: <hex@domain>`, `MIME-Version: 1.0`, `Content-Type: text/plain; charset=UTF-8`, `Content-Transfer-Encoding: base64`; Körper `chunk_split(base64_encode($text), 76, "\r\n")` (dadurch kein Punkt am Zeilenanfang). Empfänger/Absender ohne `FILTER_VALIDATE_EMAIL` oder mit CR/LF → `MailFehler`, bevor eine Verbindung geöffnet wird.
- `Auth\KennwortVergessen`:
  - Konstanten `TOKEN_MINUTEN = 60`, `MAX_JE_EMAIL = 3`, `MAX_JE_IP = 10` (je Stunde), `AUFBEWAHRUNG_STUNDEN = 24`, `ANTWORT = 'Wenn die Adresse bei uns bekannt ist, bekommst du gleich eine E-Mail mit einem Link zum Zurücksetzen. Kommt keine E-Mail an, meldet sich ein Administrator mit einem neuen Kennwort bei dir.'`, `UNGUELTIG = 'Der Link ist ungültig oder abgelaufen.'`.
  - `__construct(\PDO $pdo, Einstellungen $einstellungen, Aenderungsprotokoll $protokoll, ?Mailer $mailer, string $linkBasis, bool $testumgebung, string $appName)` – `$linkBasis` = `<oeffentliche_url><basis_url>` (leer, wenn nicht gesetzt).
  - `beantrage(string $email, string $ip): ?string` – liefert den Link nur in der Testumgebung ohne Mailer und nur bei bekanntem aktivem Konto (sonst null). Ablauf: `kennwort_versuch` älter als 24 h löschen → Begrenzung prüfen (überschritten: still nichts tun, nicht zählen) → Versuch speichern (`email_hash` = `hash('sha256', mb_strtolower(trim($email)))`, `ip_hash` = `hash_hmac('sha256', $ip, <Salz>)`, Salz 32 Zufallsbytes hex in `einstellung` `kennwort_ip_salz`, beim ersten Bedarf erzeugt) → Konto suchen (`aktiv = 1`); unbekannt/inaktiv: nur Protokolleintrag `schreibe(null, 'kennwort_anfrage', 0, 'angelegt', ['adresse' => ['alt' => null, 'neu' => 'unbekannt']])` → bekannt: ältere unbenutzte Tokens des Kontos löschen, Token = `bin2hex(random_bytes(32))`, gespeichert nur `hash('sha256', $token)`, `gueltig_bis` = jetzt + 60 min → mit Mailer: E-Mail senden; bei `MailFehler` Token löschen, `error_log` ohne Adresse, Rückfall → ohne Mailer oder nach Rückfall: offene Anfrage des Kontos anlegen (je Konto höchstens eine offene; eine weitere aktualisiert `erstellt_am`).
  - E-Mail: Betreff „Neues Kennwort für <appName>“; Text mit Rufname, Link `<linkBasis>/kennwort/neu?t=<Token>`, „Der Link ist 60 Minuten gültig und funktioniert nur einmal. Wenn du kein neues Kennwort angefordert hast, kannst du diese E-Mail ignorieren.“
  - `pruefeToken(string $token): ?int` – Benutzer-ID, wenn Hash existiert, unbenutzt, `gueltig_bis` > jetzt, Konto aktiv.
  - `setzeNeu(string $token, string $kennwort): int` – wirft `DomainFehler(UNGUELTIG)` bzw. mit der Meldung von `pruefePasswort`; in einer Transaktion: Token `verwendet_am`, übrige Tokens des Kontos löschen, `passwortSetzen(id, $kennwort, false)`, `kennwort_stand = kennwort_stand + 1`, offene Anfragen erledigen, Protokoll am Benutzer `['passwort' => ['alt' => null, 'neu' => 'über „Kennwort vergessen“ neu gesetzt']]`.
  - `offeneAnfragen(): list<array{id:int, benutzer_id:int, email:string, name:string, erstellt_am:string}>`, `anzahlOffen(): int`, `erledigeFuer(int $benutzerId, ?int $vonBenutzerId): void`, `verwerfe(int $anfrageId, int $vonBenutzerId): void`.
- `Auth`: `anmelden` und neues `anmeldenAls(int $benutzerId): void` legen `kennwort_stand` in die Sitzung (Schlüssel `kennwort_stand`); `benutzer()` liest `b.kennwort_stand` mit und meldet ab, wenn der Sitzungswert (fehlend = 0) abweicht. So enden alle anderen Sitzungen des Kontos.
- Routen (öffentlich, in `App::durchMiddleware` neben `/login` freigeben): `GET|POST /kennwort/vergessen`, `GET|POST /kennwort/neu` (Feld `t`, `neues_passwort`, `wiederholung`). Angemeldete werden von `/kennwort/vergessen` auf `/` weitergeleitet. `POST /admin/kennwort-anfragen/{id}/verwerfen` (`benutzer_verwalten`).
- Betriebshinweis für `BENUTZER_VERWALTEN`: „1 Anfrage auf neues Kennwort“ / „N Anfragen auf neues Kennwort“, Link `/admin/benutzer`, Linktext „Anfragen ansehen“. `BenutzerController::passwortZuruecksetzen` ruft `erledigeFuer($id, $admin->id)`.
- `DsgvoLoeschung::loesche`, Schritt 4: zusätzlich `kennwort_versuch` mit den `email_hash`-Werten aller bekannten E-Mails löschen; Tokens und Anfragen gehen per CASCADE mit dem Konto.

- [ ] **Step 1: Failing tests**
  - `SmtpMailerTest` (mit `FakeSmtpVerbindung`, die Antworten aus einer Liste liefert und geschriebene Zeilen sammelt):
    - `test_ssl_dialog_mit_anmeldung`: Port 465/`ssl` → `oeffne(host, 465, true, 15)`; geschriebene Befehle in Reihenfolge `EHLO`, `AUTH LOGIN`, `base64(benutzer)`, `base64(passwort)`, `MAIL FROM:<verein@example.org>`, `RCPT TO:<max@example.org>`, `DATA`, …, `.`, `QUIT`; Kopf enthält `Subject: =?UTF-8?B?` und `Content-Transfer-Encoding: base64`.
    - `test_tls_startet_starttls_und_wiederholt_ehlo`: `oeffne(…, false, …)`, `starteTls()` aufgerufen, zwei `EHLO`.
    - `test_falsches_kennwort_wirft_ohne_kennwort_in_meldung`: 535 auf das Kennwort → `MailFehler`, Meldung enthält „AUTH“, nicht das Kennwort; `schliesse()` aufgerufen.
    - `test_zeilenumbruch_im_empfaenger_wirft_vor_verbindung`: `"a@b.de\r\nRCPT TO:<x@y.de>"` → `MailFehler`, `oeffne` nie aufgerufen.
    - `test_aus_config_ohne_host_ist_null` und Standardwerte Port 465, `ssl`, Name „EMC 78 Lokschuppen“.
  - `KennwortVergessenTest`:
    - `test_token_nur_als_hash_60_minuten_gueltig`: mit `FakeMailer`; Link in der Mail, `token_hash` = sha256 des Tokens aus dem Link, `gueltig_bis` = jetzt + 60 min (±1 min).
    - `test_neue_anfrage_macht_alten_token_ungueltig`, `test_token_einmal_verwendbar`, `test_abgelaufener_token` (`gueltig_bis` per UPDATE in die Vergangenheit), `test_token_deaktiviertes_konto` → `pruefeToken` null.
    - `test_setze_neu_prueft_kennwortregel`: 9 Zeichen → `DomainFehler`, Token bleibt gültig.
    - `test_setze_neu_erhoeht_kennwort_stand_und_erledigt_anfragen`.
    - `test_begrenzung_je_email_und_ip`: 4. Anfrage derselben Adresse in einer Stunde → keine Mail; 11. Anfrage einer IP mit verschiedenen Adressen → keine Mail; `kennwort_versuch` enthält keine Klartext-IP und keine Klartext-Adresse; Einträge älter als 24 h werden beim nächsten Aufruf gelöscht.
    - `test_versand_scheitert_faellt_auf_anfrage_zurueck`: `FakeMailer` scheitert → kein Token, genau eine offene Anfrage.
    - `test_ohne_mailer_anfrage_und_nur_testumgebung_liefert_link`: ohne Mailer → Anfrage; `testumgebung = false` → Rückgabe null; `true` → Link mit `/kennwort/neu?t=`.
    - `test_unbekannte_adresse_keine_anfrage_aber_protokoll_ohne_adresse`: keine Zeile in `kennwort_anfrage`, ein Protokolleintrag `kennwort_anfrage`, dessen `aenderungen` die Adresse nicht enthält.
  - `KennwortHttpTest`:
    - Anmeldeseite enthält Link „Kennwort vergessen?“ auf `/kennwort/vergessen`; die Seite ist ohne Anmeldung erreichbar (200).
    - `test_antwort_immer_gleich`: POST mit bekannter, unbekannter, deaktivierter Adresse und nach überschrittener Begrenzung → jeweils 200 und identischer Body (enthält `KennwortVergessen::ANTWORT`).
    - `test_neues_kennwort_beendet_andere_sitzungen_und_meldet_an`: Sitzung A angemeldet; über Link neues Kennwort setzen in Sitzung B → B Weiterleitung auf `/`, Meldung „Dein Kennwort wurde geändert. Du bist jetzt angemeldet.“; Sitzung A danach `GET /fahrzeuge` → Weiterleitung auf `/login`.
    - Ungültiger Token: GET → 200 mit `UNGUELTIG` und Link „Neuen Link anfordern“, kein Formular; POST → 422, Kennwort unverändert.
    - Testumgebung ohne Postfach: Antwortseite zeigt zusätzlich den Link; ohne `testumgebung` nicht.
    - Admin: Hinweisleiste „1 Anfrage auf neues Kennwort“; `/admin/benutzer` listet Name und Zeitpunkt; Startkennwort setzen erledigt die Anfrage; „Verwerfen“ erledigt sie ebenfalls; Bearbeiter sieht keinen Hinweis und bekommt 403 auf `verwerfen`.
  - `DsgvoLoeschungTest::test_loesche_entfernt_kennwort_tokens_anfragen_und_versuche`.
  - `MigratorTest`: Versionsliste um `010_kennwort_vergessen`.
- [ ] **Step 2:** `TEST_DB_PASSWORT=test vendor/bin/phpunit --filter 'SmtpMailer|Kennwort|DsgvoLoeschung|Migrator'` → FAIL.
- [ ] **Step 3: Implement** wie in Interfaces. Container: `Mailer::class` → `SmtpMailer::ausConfig($config, new StreamVerbindung())` (nullable, Tests ersetzen ihn per `container()->set(Mailer::class, …)`); `KennwortVergessen` mit `rtrim(oeffentliche_url, '/') . basis_url`. Templates im Stil der Anmeldeseite (Karte, Logo). `config.example.php`: Abschnitt „E-Mail-Versand (optional)“ mit allen Schlüsseln und Hinweis, dass ohne Postfach Anfragen an die Administratoren gehen.
- [ ] **Step 4:** Filter → PASS; volle Suite grün.
- [ ] **Step 5: Commit** „Kennwort vergessen: E-Mail per SMTP mit Rückfall auf Admin-Anfrage“.

### Task 2: Einfache Anlagenliste

**Files:**
- Create: `migrations/011_anlage.sql`, `src/Anlagen/AnlagenCode.php`, `src/Anlagen/AnlageRepository.php`, `src/Anlagen/AnlageValidator.php`, `src/Anlagen/AnlageController.php`, `templates/anlagen/liste.php`, `templates/anlagen/formular.php`, `templates/anlagen/detail.php`, `templates/partials/dateien.php`
- Modify: `src/Auth/Berechtigung.php`, `src/Dateien/DateiController.php`, `src/Dateien/DokumentRepository.php` (Titelbild Anlage), `src/Dateien/UploadService.php`, `src/OneDrive/Pfadbildner.php`, `src/OneDrive/Uebertragung.php`, `templates/fahrzeuge/tab_dateien.php` (nutzt den neuen Partial), `templates/partials/navigation.php`, `src/Core/App.php`, `src/routes.php`, `tests/Integration/Betrieb/MigratorTest.php`
- Test: `tests/Integration/Anlagen/AnlageRepositoryTest.php`, `tests/Integration/Anlagen/AnlageHttpTest.php`, `tests/Unit/Anlagen/AnlageValidatorTest.php`, `tests/Unit/OneDrive/PfadbildnerTest.php` (ergänzen), `tests/Unit/Auth/BerechtigungTest.php` (ergänzen)

**Interfaces:**
- Migration 011: `anlage` (id, `nummer INT UNSIGNED NOT NULL UNIQUE`, `name VARCHAR(150) NOT NULL`, `spurweite_id` FK RESTRICT NOT NULL, `kurzbeschreibung VARCHAR(1000) NULL`, `standort VARCHAR(150) NULL`, `status ENUM('in_ordnung','defekt','in_arbeit') NOT NULL DEFAULT 'in_ordnung'`, `titelbild_dokument_id INT UNSIGNED NULL`, `erstellt_am`, `geaendert_am DATETIME(6) … ON UPDATE CURRENT_TIMESTAMP(6)`).
- `AnlagenCode`: `ZAEHLER = 'anlage_zaehler'`; `naechste(\PDO): int` (= max(höchste `nummer`, Zähler) + 1), `merke(\PDO, int): void`, `formatiere(int $n): string` → `'A' . $n`. Nummern werden nie wiederverwendet (wie `Inventarnummer`).
- `AnlageRepository`: `alle(?int $spurweiteId = null, ?string $text = null): list<array>` (mit `spurweite_name`, `code`), `finde(int): ?array`, `findeNachNummer(int): ?array`, `anlegen(array $d): int` (vergibt `nummer` in einer Transaktion), `aktualisieren(int $id, array $d, string $erwarteterStand): void` (wirft `KonfliktFehler` wie `FahrzeugRepository`), `loeschen(int $id): void`, `setzeTitelbild(int $id, ?int $dokumentId): void`.
- `AnlageValidator::STATUS = ['in_ordnung','defekt','in_arbeit']`, Anzeigenamen „in Ordnung“, „defekt“, „in Arbeit“; `pruefe(array $eingabe, list<int> $spurweitenIds): array{0: array, 1: array<string,string>}`. Meldungen: „Bitte einen Namen eingeben.“, „Bitte eine Spurweite wählen.“
- `Berechtigung::ANLAGE_BEARBEITEN = 'anlage_bearbeiten'` (Rang 3).
- Routen: `GET /anlagen`, `GET /anlagen/{id}` (`lesen`); `GET /anlagen/neu`, `POST /anlagen`, `GET /anlagen/{id}/bearbeiten`, `POST /anlagen/{id}`, `POST /anlagen/{id}/loeschen`, `POST /anlagen/{id}/dateien`, `POST /anlagen/{id}/titelbild` (`anlage_bearbeiten`).
- Löschen einer Anlage entfernt ihre Zuordnungen/Dokumente (`entferneVonObjekt('anlage', id)`), Protokoll `geloescht`. Löschen einer Datei, die einer Anlage zugeordnet ist, leitet auf `/anlagen/{id}` zurück; `loescheDatensatz` leert auch `anlage.titelbild_dokument_id`.
- `UploadService::speichere(…, ?int $fahrzeugId = null, ?int $anlageId = null)`; `Pfadbildner::fuerDokument(array $dokument, ?array $fahrzeug, ?array $anlage = null)` → `Anlagen/A3 Clubanlage/<Datei>`; `Uebertragung` sucht bei fehlendem Fahrzeug die Anlage (`nummer`, `name`).
- `templates/partials/dateien.php`: Parameter `$dateien`, `$uploadUrl`, `$titelbildUrl`, `$darfDateien`, `$titelbildId`; Fahrzeug- und Anlagenseite nutzen ihn.
- Menü: Hauptpunkt „Anlagen“ nach „Fahrzeuge“ (für alle Angemeldeten); Liste mit Code, Name, Spurweite, Standort, Status; Detailseite zeigt Code groß neben dem Namen.

- [ ] **Step 1: Failing tests**
  - `AnlageRepositoryTest::test_code_fortlaufend_nie_wiederverwendet`: anlegen → A1, A2; A2 löschen; nächste → A3.
  - `AnlageRepositoryTest::test_stand_pruefung`: veralteter Stand → `KonfliktFehler`.
  - `AnlageValidatorTest`: leerer Name, fehlende/unbekannte Spurweite, unbekannter Status → Fehler; Name auf 150 Zeichen begrenzt.
  - `BerechtigungTest::test_anlage_bearbeiten_ab_bearbeiter`: Fahrzeugwart false, Bearbeiter true.
  - `AnlageHttpTest`: Mitglied `GET /anlagen` und `/anlagen/{id}` → 200 mit „A1“; Mitglied und Fahrzeugwart `GET /anlagen/neu`, `POST /anlagen`, `POST /anlagen/{id}/loeschen` → 403; Bearbeiter anlegen → Weiterleitung `/anlagen/{id}`, Meldung „Die Anlage wurde angelegt.“, Protokolleintrag `anlage`/`angelegt`; ändern mit veraltetem Stand → 409-Verhalten wie bei Fahrzeugen; Foto hochladen (`tests/fixtures/dateien/foto_gedreht.jpg`) → `dokument_zuordnung` mit `objekt_typ = 'anlage'`, wird Titelbild; Anlage löschen → Dokument und Datei weg; Menü enthält „Anlagen“.
  - `PfadbildnerTest::test_anlage`: `['nummer' => 3, 'name' => 'Clubanlage']` → `Anlagen/A3 Clubanlage/Foto.jpg`.
  - `MigratorTest`: `011_anlage`.
- [ ] **Step 2:** Filter `Anlage|Pfadbildner|Berechtigung|Migrator` → FAIL.
- [ ] **Step 3: Implement** nach dem Muster von `FahrzeugController`/`FahrzeugRepository` (Stand-Feld `stand` im Formular, Protokoll per `Aenderungsprotokoll::diff`, `Session::meldung`). Bestehende Datei-Tests der Fahrzeuge müssen unverändert grün bleiben.
- [ ] **Step 4:** Filter → PASS; volle Suite grün.
- [ ] **Step 5: Commit** „Einfache Anlagenliste mit Code A<n>, Fotos und Dokumenten“.

### Task 3: Wartungsarten (Stammliste mit Startbestand)

**Files:**
- Create: `migrations/012_wartungsart.sql`, `src/Wartung/GiltFuer.php`, `src/Wartung/WartungsartRepository.php`, `src/Wartung/WartungsartValidator.php`, `src/Wartung/WartungsartController.php`, `templates/wartung/wartungsarten.php`
- Modify: `src/Auth/Berechtigung.php`, `templates/partials/navigation.php`, `src/Core/App.php`, `src/routes.php`, `tests/Integration/Betrieb/MigratorTest.php`
- Test: `tests/Unit/Wartung/GiltFuerTest.php`, `tests/Unit/Wartung/WartungsartValidatorTest.php`, `tests/Integration/Wartung/WartungsartHttpTest.php`

**Interfaces:**
- Migration 012: `wartungsart` (id, `name VARCHAR(100) NOT NULL UNIQUE`, `gilt_fuer ENUM('fahrzeug_alle','fahrzeug_antrieb','fahrzeug_wagen','anlage') NOT NULL`, `intervall_monate SMALLINT UNSIGNED NULL`, `intervall_stunden SMALLINT UNSIGNED NULL`, `aktiv TINYINT(1) NOT NULL DEFAULT 1`, `sortierung INT NOT NULL DEFAULT 0`, `erstellt_am`, `geaendert_am`) und Startbestand (Sortierung 10, 20, … 80):

  | name | gilt_fuer | monate | stunden |
  |---|---|---|---|
  | Räder und Radschleifer reinigen | fahrzeug_alle | 6 | 20 |
  | Getriebe und Lager schmieren | fahrzeug_antrieb | 12 | 50 |
  | Haftreifen prüfen/tauschen | fahrzeug_antrieb | 12 | 50 |
  | Motor/Kohlen prüfen | fahrzeug_antrieb | 24 | 100 |
  | Kupplungen prüfen | fahrzeug_alle | 12 | NULL |
  | Gleise reinigen | anlage | 3 | NULL |
  | Weichen und Antriebe prüfen | anlage | 12 | NULL |
  | Verkabelung und Stecker prüfen | anlage | 12 | NULL |

- `enum GiltFuer: string` (`FahrzeugAlle`, `FahrzeugAntrieb`, `FahrzeugWagen`, `Anlage`); `label(): string` → „Fahrzeuge (alle)“, „Lok/Triebwagen“, „Wagen“, „Anlagen“; `passt(string $objektTyp, ?string $gattung): bool` – `fahrzeug_antrieb` = Gattung `lok` oder `triebwagen`, `fahrzeug_wagen` = `wagen`; `sonderfahrzeug` nur über `fahrzeug_alle`.
- `WartungsartValidator::pruefe(array $eingabe): array{0: array, 1: array<string,string>}` – Name Pflicht (max. 100); Intervalle ganze Zahlen 1–999 oder leer; mindestens eines: „Bitte ein Intervall in Monaten oder Betriebsstunden angeben.“; bei `anlage` keine Stunden: „Anlagen haben keine Betriebsstunden – bitte ein Intervall in Monaten angeben.“
- `WartungsartRepository`: `alle(): list<array>` (sortiert nach `sortierung`, `name`), `finde(int): ?array`, `speichern(array $d, ?int $id = null): int` (doppelter Name → `DomainFehler('Diese Wartungsart gibt es schon.')`), `loeschen(int $id): void` (bereits verwendet → `DomainFehler('Diese Wartungsart wurde schon verwendet. Bitte stattdessen deaktivieren.')`; bis Task 4 gibt es keine Verwendung), `passendeFuer(string $objektTyp, ?string $gattung): list<array>` (nur aktive).
- `Berechtigung::WARTUNGSART_VERWALTEN = 'wartungsart_verwalten'` (Rang 2).
- Routen wie Spurweiten: `GET /admin/wartungsarten`, `POST /admin/wartungsarten`, `POST /admin/wartungsarten/{id}/loeschen` (alle `wartungsart_verwalten`). Menü: „Verwaltung › Wartungsarten“ (damit sieht der Fahrzeugwart den Punkt „Verwaltung“ mit nur diesem Eintrag).

- [ ] **Step 1: Failing tests**
  - `GiltFuerTest`: Tabelle `(gilt_fuer, objektTyp, gattung) → bool`, u. a. `fahrzeug_antrieb`+`triebwagen` true, `fahrzeug_antrieb`+`wagen` false, `fahrzeug_alle`+`sonderfahrzeug` true, `anlage`+`fahrzeug` false, `fahrzeug_alle`+`anlage` false.
  - `WartungsartValidatorTest`: beide Intervalle leer → Fehler; `anlage` mit Stunden → Fehler; `0` und `abc` → Fehler.
  - `WartungsartHttpTest`: Mitglied `GET /admin/wartungsarten` → 403; Fahrzeugwart → 200 und listet die 8 Startarten mit „6 Monate oder 20 h“; anlegen, ändern, deaktivieren (`aktiv` 0), löschen unbenutzter Art → Weiterleitung mit Erfolgsmeldung; doppelter Name → 422.
  - `MigratorTest`: `012_wartungsart`; `test_migration_012_startbestand`: genau die 8 Zeilen der Tabelle oben (Name, gilt_fuer, Intervalle).
- [ ] **Step 2:** Filter `GiltFuer|Wartungsart|Migrator` → FAIL.
- [ ] **Step 3: Implement** nach dem Muster von `SpurweiteController` (eine Seite mit Liste und Formular, `?bearbeiten=<id>`). Anzeige der Intervalle: „6 Monate oder 20 h“, „12 Monate“, „50 h“.
- [ ] **Step 4:** Filter → PASS; volle Suite grün.
- [ ] **Step 5: Commit** „Wartungsarten mit Startbestand, Pflege ab Fahrzeugwart“.

### Task 4: Wartungsprotokoll und Ausnahmen im Wartungsplan

**Files:**
- Create: `migrations/013_wartung.sql`, `src/Wartung/WartungRepository.php`, `src/Wartung/AusnahmeRepository.php`, `src/Wartung/WartungValidator.php`, `src/Wartung/WartungController.php`, `templates/wartung/_block.php`
- Modify: `src/Auth/Berechtigung.php`, `src/Fahrzeuge/FahrzeugController.php` (Tab „Wartung“, Löschen räumt auf), `templates/fahrzeuge/detail.php`, `src/Anlagen/AnlageController.php`, `templates/anlagen/detail.php`, `src/Wartung/WartungsartRepository.php` (`loeschen` prüft Verwendung), `src/OneDrive/Pfadbildner.php`, `src/OneDrive/Uebertragung.php`, `src/Core/App.php`, `src/routes.php`, `tests/Integration/Betrieb/MigratorTest.php`
- Test: `tests/Unit/Wartung/WartungValidatorTest.php`, `tests/Integration/Wartung/WartungHttpTest.php`, `tests/Unit/Auth/BerechtigungTest.php` (ergänzen)

**Interfaces:**
- Migration 013:
  - `wartung` (id, `objekt_typ ENUM('fahrzeug','anlage') NOT NULL`, `objekt_id INT UNSIGNED NOT NULL`, `datum DATE NOT NULL`, `betriebsstunden DECIMAL(8,1) NULL` (eingegeben), `stundenstand_bezug DECIMAL(8,1) NULL` (eingegebener Stand, sonst der Stand des Fahrzeugs beim Eintragen – nur bei einem Eintrag für heute und wenn das Fahrzeug Betriebsstunden führt, sonst NULL; bei Anlagen NULL), `ausgefuehrt_von_person_id` FK → person `ON DELETE SET NULL`, `notiz TEXT NULL`, `erfasst_von_benutzer_id` FK → benutzer `ON DELETE SET NULL`, `erstellt_am`; Index `(objekt_typ, objekt_id, datum)`).
  - `wartung_art` (`wartung_id` FK CASCADE, `wartungsart_id` FK RESTRICT, PK beide).
  - `wartungsplan_ausnahme` (id, `objekt_typ ENUM('fahrzeug','anlage')`, `objekt_id`, `wartungsart_id` FK CASCADE, `aus TINYINT(1) NOT NULL DEFAULT 0`, `intervall_monate SMALLINT UNSIGNED NULL`, `intervall_stunden SMALLINT UNSIGNED NULL`, UNIQUE `(objekt_typ, objekt_id, wartungsart_id)`).
- `Berechtigung`:
  - `darfFahrzeugWarten(?Benutzer $b, array $fahrzeug): bool` – Fahrzeugwart und höher; Mitglied, wenn `eigentuemer_person_id === $b->personId` oder `angelegt_von_benutzer_id === $b->id`.
  - `darfWartungEintragen(?Benutzer $b, string $objektTyp, array $objekt): bool` – `fahrzeug` → `darfFahrzeugWarten`, `anlage` → `ANLAGE_BEARBEITEN`.
  - `darfWartungsplanAendern(?Benutzer $b, string $objektTyp): bool` – `fahrzeug` → `FAHRZEUG_BEARBEITEN`, `anlage` → `ANLAGE_BEARBEITEN`.
- `WartungValidator::pruefe(array $eingabe, list<int> $erlaubteArtIds, list<int> $personIds, bool $mitStunden, string $heute): array{0: array, 1: array<string,string>}` – Meldungen: „Bitte ein gültiges Datum angeben.“, „Das Datum darf nicht in der Zukunft liegen.“, „Bitte mindestens eine Wartungsart wählen.“, „Diese Wartungsart passt nicht zu diesem Objekt.“, „Bitte einen Stundenstand ab 0 angeben.“ (Komma oder Punkt, eine Nachkommastelle), Notiz max. 2000 Zeichen. Bei `!$mitStunden` wird `betriebsstunden` ignoriert.
- `WartungRepository`: `eintragen(string $objektTyp, int $objektId, array $d, list<int> $artIds, ?int $benutzerId): int`; `fuerObjekt(string $objektTyp, int $objektId): list<array{id:int, datum:string, betriebsstunden:?string, arten:list<string>, ausgefuehrt_von:?string, notiz:?string, fotos:list<array>}>` (neueste zuerst, `ausgefuehrt_von` = „Vorname Nachname“ oder null); `finde(int): ?array`; `loeschen(int $id): void`; `idsFuerObjekt(string $objektTyp, int $objektId): list<int>`; `letzteJeArt(string $objektTyp, ?int $objektId = null): array<string, array{datum:string, stundenstand:?float}>` (Schlüssel `"<typ>:<id>:<artId>"`, jüngstes `datum`, bei gleichem Datum höchste `id`; `stundenstand` aus der jüngsten Wartung dieser Art mit `stundenstand_bezug`, aber nur wenn sie am Datum der jüngsten Wartung liegt, sonst null; eine Abfrage) – wird in Task 5 genutzt. `fuehrtStunden(int $fahrzeugId, float $aktuell): bool` (Stand > 0 oder eine Wartung mit eingegebenem Stand) und `FUEHRT_STUNDEN_SQL` für Listen.
- `AusnahmeRepository`: `fuerObjekt(string $objektTyp, int $objektId): array<int, array{aus:bool, intervall_monate:?int, intervall_stunden:?int}>` (Schlüssel Wartungsart-ID), `alle(): array<string, …>` (Schlüssel `"<typ>:<id>:<artId>"`), `speichere(string $objektTyp, int $objektId, int $artId, string $modus, ?int $monate, ?int $stunden): void` (`standard` löscht die Zeile, `aus`, `eigen`), `loescheFuerObjekt(string $objektTyp, int $objektId): void`. Ein eigenes Intervall ersetzt beide Werte der Wartungsart (leer = Kriterium entfällt), mindestens eines muss gesetzt sein: „Bitte ein eigenes Intervall in Monaten oder Stunden angeben.“
- Routen (`lesen` + Objektprüfung im Controller, 403): `POST /fahrzeuge/{id}/wartungen`, `POST /anlagen/{id}/wartungen`, `POST /wartungen/{id}/loeschen` (gleiches Recht wie Eintragen am Objekt), `POST /fahrzeuge/{id}/wartungsplan`, `POST /anlagen/{id}/wartungsplan` (Felder je Art `modus[<artId>]`, `monate[<artId>]`, `stunden[<artId>]`).
- Eintragen: Formularfelder `datum` (vorbelegt heute), `arten[]` (nur aktive, passende, nicht abgeschaltete Arten), `betriebsstunden` (nur Fahrzeuge), `ausgefuehrt_von` (aktive Personen, vorbelegt die eigene), `notiz`, `fotos[]`. Ist der Stand höher als `fahrzeug.betriebsstunden`, wird das Fahrzeug aktualisiert (Protokoll am Fahrzeug `betriebsstunden` alt → neu). Der Fahrzeug- bzw. Anlagenstatus bleibt unverändert. Protokoll am Objekt: `['wartung' => ['alt' => null, 'neu' => '<d.m.Y>: <Arten, Komma-getrennt>']]`; Löschen entsprechend `alt` → `null`. Erfolgsmeldung „Die Wartung wurde eingetragen.“
- Fotos: über `UploadService` mit `dokument_zuordnung` `objekt_typ = 'wartung'`; nur Bilder (`DateiTyp` `bild`), sonst Warnung „<Datei>: Nur Fotos können an eine Wartung angehängt werden.“, die Wartung bleibt gespeichert. OneDrive-Pfad eines Wartungsfotos: Ordner des Objekts + `/Wartung/<Datei>` (`Pfadbildner::fuerDokument(..., $fahrzeug, $anlage, bool $wartung = false)`; `Uebertragung` löst `wartung` → Objekt auf).
- Fahrzeug bzw. Anlage löschen: vorher Wartungen (samt nur ihnen zugeordneter Fotos über `DokumentRepository::loeschen`) und Ausnahmen des Objekts entfernen.
- `templates/wartung/_block.php` (Parameter `$objektTyp`, `$objekt`, `$wartungen`, `$arten`, `$ausnahmen`, `$personen`, `$darfEintragen`, `$darfPlan`): Formular „Wartung eintragen“, Verlauf (Datum, Arten, Stand, ausgeführt von bzw. „ehemaliges Mitglied“ bei NULL, Notiz, Vorschaubilder), aufklappbarer Bereich „Wartungsplan anpassen“. Fahrzeugseite: neuer Tab „Wartung“ zwischen „Dateien“ und „Verlauf“; Anlagenseite: Abschnitt „Wartung“. Weiterleitungen nach Eintragen/Löschen/Plan und „Zurück“ gehen auf `…/{id}#wartung`. Einzelne Wartungsfotos löscht `/dateien/{id}/loeschen` mit demselben Recht wie die Wartung (Protokoll am Objekt, zurück auf `#wartung`); der Verlauf zeigt dafür je Foto „Foto löschen“.

- [ ] **Step 1: Failing tests**
  - `WartungValidatorTest`: keine Art, fremde Art-ID, Datum morgen, `-1`, `12,25` (zwei Nachkommastellen → Fehler), `12,5` → 12.5.
  - `BerechtigungTest::test_darf_fahrzeug_warten`: Mitglied als Eigentümer (fremd angelegt) true; Mitglied fremd false; Fahrzeugwart fremd privat true; `darfWartungEintragen(fahrzeugwart, 'anlage', …)` false, Bearbeiter true; `darfWartungsplanAendern(mitglied, 'fahrzeug')` false.
  - `WartungHttpTest`:
    - `test_mitglied_traegt_an_eigenem_fahrzeug_ein`: Weiterleitung auf `/fahrzeuge/{id}`, Zeile in `wartung` und zwei in `wartung_art`, `ausgefuehrt_von_person_id` = eigene Person, Status unverändert `einsatzbereit`.
    - `test_mitglied_an_fremdem_fahrzeug_403`, `test_fahrzeugwart_an_anlage_403`, `test_bearbeiter_an_anlage_erlaubt`.
    - `test_hoeherer_stand_aktualisiert_fahrzeug`: Fahrzeug 40,0 h, Eingabe `52,5` → `betriebsstunden` 52.5, Protokolleintrag am Fahrzeug.
    - `test_niedrigerer_stand_nachtrag_aendert_fahrzeug_nicht`: Fahrzeug 40,0 h, Eingabe `30` → bleibt 40.0, `stundenstand_bezug` 30.0.
    - `test_ohne_stand_bezug_ist_aktueller_stand`: leer → `betriebsstunden` NULL, `stundenstand_bezug` 40.0.
    - `test_nachtrag_ohne_stand_hat_keinen_stundenbezug`: Datum gestern, leer → `stundenstand_bezug` NULL (damaliger Stand unbekannt).
    - `test_fahrzeug_ohne_betriebsstunden_bekommt_keinen_bezug`: Wagen mit 0 h ohne Eingabe → NULL; nach einer Eingabe „0“ zählt 0.0.
    - `test_unpassende_art_422`: „Haftreifen prüfen/tauschen“ an einem Wagen → 422, keine Zeile.
    - `test_foto_an_wartung`: JPG → Zuordnung `wartung`; PDF → Warnung, Wartung trotzdem gespeichert.
    - `test_ausnahmen`: Mitglied POST Wartungsplan → 403; Fahrzeugwart `aus` → Zeile `aus = 1`, Art nicht mehr im Eintragen-Formular; `eigen` ohne Intervall → 422 mit Meldung; `eigen` 3 Monate → gespeichert; `standard` → Zeile weg. Anlage: Bearbeiter erlaubt, Fahrzeugwart 403.
    - `test_fahrzeug_loeschen_entfernt_wartungen_ausnahmen_fotos`.
    - `test_wartung_loeschen`: gleiches Recht wie Eintragen; Fahrzeugstunden bleiben.
    - `test_verlauf_zeigt_ehemaliges_mitglied`: `ausgefuehrt_von_person_id` NULL → „ehemaliges Mitglied“.
    - `test_verwendete_wartungsart_nicht_loeschbar`: Löschen → Meldung „Diese Wartungsart wurde schon verwendet. Bitte stattdessen deaktivieren.“
  - `MigratorTest`: `013_wartung`.
- [ ] **Step 2:** Filter `Wartung|Berechtigung|Migrator` → FAIL.
- [ ] **Step 3: Implement** wie in Interfaces; Eintragen und Betriebsstunden-Aktualisierung in einer Transaktion, Fotos danach.
- [ ] **Step 4:** Filter → PASS; volle Suite grün.
- [ ] **Step 5: Commit** „Wartungsprotokoll und Ausnahmen im Wartungsplan“.

### Task 5: Fälligkeit, Ampel und Anzeigen

**Files:**
- Create: `src/Wartung/Ampel.php`, `src/Wartung/Faelligkeit.php`, `src/Wartung/Faelligkeitsrechner.php`, `src/Wartung/Wartungsplan.php`, `src/Wartung/WartungFilter.php`, `src/Wartung/UebersichtController.php`, `src/Start/StartController.php`, `templates/wartung/uebersicht.php`, `templates/wartung/_ampel.php`, `templates/start.php`
- Modify: `templates/wartung/_block.php` (Ampel je Art), `src/Fahrzeuge/FahrzeugController.php`, `src/Anlagen/AnlageController.php`, `templates/partials/navigation.php` (Hauptpunkt „Wartung“), `src/Core/App.php`, `src/routes.php` (`/` → Startseite), `tests/Integration/Fahrzeuge/FahrzeugHttpTest.php` (`test_startseite_leitet_zur_liste` ersetzen)
- Test: `tests/Unit/Wartung/FaelligkeitsrechnerTest.php`, `tests/Integration/Wartung/WartungsplanTest.php`, `tests/Integration/Wartung/WartungUebersichtHttpTest.php`

**Interfaces:**
- `enum Ampel: string` (`Rot = 'rot'`, `Gelb = 'gelb'`, `Gruen = 'gruen'`); `rang(): int` (Rot 0, Gelb 1, Grün 2); `farbe(): string` (`danger`, `warning`, `success`); Texte „überfällig“, „bald fällig“, „in Ordnung“, bei nie erfasster Wartung „noch nie erfasst“.
- `Faelligkeit` (readonly): `Ampel $ampel`, `bool $nochNieErfasst`, `?\DateTimeImmutable $faelligAm`, `?float $faelligBeiStunden`, `float $rest` (kleinster verbleibender Anteil des Intervalls über alle Kriterien, negativ = überfällig; nie erfasst: 0.0), `bool $stundenstandFehlt = false`; `kategorie()` → `rot`/`gelb`/`nie`/`gruen`.
- `Faelligkeitsrechner::berechne(?int $monate, ?int $stunden, ?\DateTimeImmutable $letzteAm, ?float $letzteStunden, ?float $aktuelleStunden, \DateTimeImmutable $heute): Faelligkeit` (rein, ohne Datenbank):
  - Ohne letzte Wartung: Gelb, `nochNieErfasst`.
  - Zeit: `faelligAm = plusMonate($letzteAm, $monate)`; Rot, wenn `heute > faelligAm`; Gelb, wenn `heute >= plusMonate(faelligAm, -1)`; sonst Grün. `rest` = Resttage / Intervalltage.
  - Stunden (nur wenn `$stunden` und `$aktuelleStunden` nicht null; `$aktuelleStunden` null = Anlage oder Fahrzeug ohne Betriebsstunden): `faelligBei = letzteStunden + stunden`; Rot, wenn `aktuell > faelligBei`; Gelb, wenn `faelligBei − aktuell <= 0,1 · stunden`; sonst Grün. Differenzen vor dem Vergleich auf eine Nachkommastelle runden (`round(…, 1)`), damit 117,9 bei Grenze 2,0 nicht an Gleitkomma-Resten scheitert.
  - Fehlender Stundenbezug (`$letzteStunden` null, Fahrzeug führt Stunden): Stundenkriterium Gelb mit Rest 0.0 (wie „noch nie erfasst“, Spezifikation 5.5); Rot aus der Zeit bleibt Rot. Kommt das Gelb allein daher, ist `stundenstandFehlt` gesetzt (Text „Stundenstand fehlt“).
  - Kein Kriterium berechenbar (nur Stundenintervall bei einem Fahrzeug ohne Betriebsstunden, aber mit Wartung): Gelb, `stundenstandFehlt`, Rest 0.0 (nicht Grün, nicht „noch nie erfasst“).
  - Ergebnis: das schlechtere beider Kriterien („zuerst erreicht“).
  - `plusMonate(\DateTimeImmutable $d, int $n): \DateTimeImmutable` – Tag auf das Monatsende begrenzt (31.08. + 6 → 28.02.).
- `Wartungsplan`:
  - Fahrzeuge mit 0 Betriebsstunden ohne je eingegebenen Stand führen keine Stunden: das Stundenkriterium entfällt (nur Monate; nur Stundenintervall → Gelb „noch nie erfasst“ bzw. nach einer Wartung „Stundenstand fehlt“), „bei … h“ wird nicht angezeigt.
  - `fuerObjekt(string $objektTyp, array $objekt, \DateTimeImmutable $heute): list<array{art: array, monate: ?int, stunden: ?int, eigenes_intervall: bool, letzte: ?array{datum:string, stundenstand:?float}, faelligkeit: Faelligkeit}>` – aktive, passende (`GiltFuer::passt`), nicht abgeschaltete Arten; Ausnahme-Intervall ersetzt das der Art; ausgemusterte Fahrzeuge → leere Liste.
  - `uebersicht(WartungFilter $f, \DateTimeImmutable $heute): list<array{objekt_typ:string, objekt: array, art: array, faelligkeit: Faelligkeit}>` – sortiert nach `ampel->rang()`, dann (innerhalb Gelb) mit Datum vor „noch nie erfasst“, dann `rest` aufsteigend, dann Objektname. Lädt Objekte, Arten, `letzteJeArt` und `AusnahmeRepository::alle` mit je einer Abfrage (keine Abfrage je Zeile).
  - `zusammenfassung(\DateTimeImmutable $heute): array{rot:int, gelb:int, nie:int, dringendste: list<…>}` (`gelb` ohne „noch nie erfasst“; höchstens 5 Einträge, nur Rot/Gelb); `static zaehle(list $zeilen): array{rot,gelb,nie,gruen}`.
- `WartungFilter` (readonly): `?string $typ` (`fahrzeug`/`anlage`), `?int $spurweiteId`, `?string $eigentuemer` (`verein`/`mitglied`; Anlagen zählen als Verein), `?string $stand` (`rot`/`gelb`/`nie`/`gruen`); `static ausRequest(Request $r): self`, `ohneStand()`, `passtStand(Faelligkeit)`.
- Routen: `GET /wartung` (`lesen`) → `UebersichtController::uebersicht`; `GET /` (`lesen`) → `StartController::start` (Kachel „Wartung“: „N überfällig“, „M bald fällig“, „K noch nie erfasst“, die fünf dringendsten mit Link aufs Objekt, Link „Alle Fälligkeiten“; außerdem Schnellzugriffe „Fahrzeuge“, „Anlagen“). Menü: Hauptpunkt „Wartung“ (Fälligkeiten).
- Übersicht-Tabelle: Ampel, Objekt (Inventarnummer/Code + Bezeichnung/Name, Link), Wartungsart, letzte Wartung, fällig am / bei h; Filterleiste wie die Fahrzeugliste (`_filter.php` als Vorbild) mit Filter „Stand“; darüber die Anzahl je Stand (ohne Stand-Filter gezählt).
- `_ampel.php`: Bootstrap-Badge mit Farbe und Text (nicht nur Farbe, für Barrierefreiheit); Wartungsblock zeigt je Art Ampel, Intervall (mit Kennzeichen „eigenes Intervall“), letzte Wartung, fällig am/bei; bei ausgemusterten Fahrzeugen „Ausgemusterte Fahrzeuge werden im Wartungsplan nicht berücksichtigt.“

- [ ] **Step 1: Failing tests**
  - `FaelligkeitsrechnerTest`:
    - `test_noch_nie_erfasst_ist_gelb`.
    - `test_monate`: letzte 15.01.2026, 6 Monate → `faelligAm` 15.07.2026; heute 14.06. Grün, 15.06. Gelb, 15.07. Gelb, 16.07. Rot.
    - `test_monatsende`: 31.08.2026 + 6 → 28.02.2027; 31.08.2027 + 6 → 29.02.2028; 31.03.2026 − 1 → 28.02.2026.
    - `test_stunden`: Intervall 20, letzte bei 100,0: aktuell 117,9 Grün, 118,0 Gelb, 120,0 Gelb, 120,1 Rot.
    - `test_zuerst_erreicht`: Zeit Grün + Stunden Rot → Rot; Zeit Rot + Stunden Grün → Rot.
    - `test_nur_monate_und_anlage_ohne_stunden`: `$aktuelleStunden` null → nur Zeit zählt; Intervall nur Stunden und `$letzteStunden` null (Fahrzeug führt Stunden) → Gelb „Stundenstand fehlt“, Rest 0.0.
    - `test_fehlender_stundenstand_bei_monaten_und_stunden`, `test_kein_kriterium_berechenbar_ist_gelb_stundenstand_fehlt` (Gelb, `stundenstandFehlt`).
    - `test_rest_ordnet_dringlichkeit`: 10 Tage überfällig hat kleineres `rest` als 2 Tage überfällig.
  - `WartungsplanTest`:
    - `test_arten_nach_gattung`: Lok → 5 Fahrzeugarten, Wagen → 2, Anlage → 3 (Startbestand).
    - `test_ausgemustert_inaktiv_und_abgeschaltet_entfallen`.
    - `test_eigenes_intervall_ersetzt_standard`: Ausnahme 3 Monate, letzte Wartung vor 4 Monaten → Rot (Standard 6 wäre Grün).
    - `test_juengstes_datum_ist_bezug`: Wartung am 01.09. (Stand 60) und Nachtrag am 01.03. (Stand 30) → Bezug 01.09./60.
    - `test_eine_wartung_mehrere_arten`: beide Arten bekommen dasselbe Datum.
    - `test_gattungswechsel`: Wagen mit erfasster Kupplungsprüfung → Lok: Lok-Arten erscheinen als „noch nie erfasst“, Kupplungen behält ihr Datum.
    - `test_uebersicht_filter_und_sortierung`: Rot vor Gelb (mit Datum) vor Gelb (nie erfasst) vor Grün; `typ=anlage` nur Anlagen; `eigentuemer=mitglied` keine Anlagen, keine Vereinsfahrzeuge; Spurweite filtert beide Typen.
  - `WartungUebersichtHttpTest`: Mitglied `GET /wartung` → 200, überfälliges Fahrzeug vor bald fälliger Anlage; ausgemustertes Fahrzeug fehlt; `GET /` → 200 mit Kachel „Wartung“ und „1 überfällig“; Fahrzeugseite zeigt im Tab „Wartung“ „überfällig“ an der passenden Art; Anlagenseite zeigt „noch nie erfasst“; Menü enthält „Wartung“.
  - `FahrzeugHttpTest::test_startseite_zeigt_kacheln` ersetzt `test_startseite_leitet_zur_liste`.
- [ ] **Step 2:** Filter `Faelligkeit|Wartungsplan|WartungUebersicht|FahrzeugHttp` → FAIL.
- [ ] **Step 3: Implement** wie in Interfaces; „heute“ = `new \DateTimeImmutable('today')` (Zeitzone der App) im Controller, an alle Dienste übergeben.
- [ ] **Step 4:** Filter → PASS; volle Suite grün.
- [ ] **Step 5: Commit** „Fälligkeit und Ampel: Übersicht Wartung, Startseite, Wartungsblock“.

### Task 6: DSGVO-Löschung für Wartungen, Beispieldaten, README, Version 0.5.0

**Files:**
- Modify: `src/Personen/DsgvoLoeschung.php`, `templates/personen/dsgvo.php`, `src/Betrieb/DemoDaten.php`, `README.md`, `VERSION` → `0.5.0`
- Test: `tests/Integration/Personen/DsgvoLoeschungTest.php`, `tests/Integration/Betrieb/DemoDatenTest.php`

**Interfaces:**
- Consumes: `WartungRepository::idsFuerObjekt`, `DokumentRepository::idsFuerObjekt`/`loescheDatensatz` (Task 2a), `AusnahmeRepository::loescheFuerObjekt` – innerhalb der bestehenden Transaktion, ohne eigene Transaktionen.
- `DsgvoLoeschung::loesche`, Schritt 3 je gelöschtem eigenen Fahrzeug: Fotos jeder Wartung (`idsFuerObjekt('wartung', $wid)`) per `loescheDokument` (Pfade sammeln), Wartungen (`wartung_art` per CASCADE) und Ausnahmen löschen, `['wartung', $wid]` zu `$eigeneObjekte`. Übergebene und Vereinsfahrzeuge behalten ihre Wartungen; `ausgefuehrt_von_person_id` und `erfasst_von_benutzer_id` werden per `ON DELETE SET NULL` geleert (Anzeige „ehemaliges Mitglied“).
- `vorschau()`: je Fahrzeug zusätzlich `wartungen:int`; außerdem `wartungen_in_vereinsdaten:int` (Wartungen fremder/Vereins-Objekte, die die Person ausgeführt hat). Vorschau-Seite nennt beide Zahlen.
- `DemoDaten`: eine Anlage „Clubanlage H0“ (A1, Standort „Vereinsheim, Raum 2“), Wartung „Gleise reinigen“ vor 4 Monaten (→ überfällig); an Demo-Fahrzeugen: eine Lok mit „Räder und Radschleifer reinigen“ vor 2 Wochen (→ in Ordnung), eine Lok mit „Getriebe und Lager schmieren“ vor 11,5 Monaten (→ bald fällig), Rest „noch nie erfasst“; Daten relativ zu heute, ausgeführt von erfundenen Demo-Personen.
- README: Abschnitte „Kennwort vergessen“ (mit/ohne Postfach, `mail_*`-Schlüssel, STRATO-Beispiel `smtp.strato.de`, Port 465, `ssl`, Testumgebung zeigt den Link), „Anlagen“, „Wartung“ (Wartungsarten, Ausnahmen, Ampelregeln); Rollentabelle um Anlagen (Bearbeiter) und Wartung ergänzen; DSGVO-Abschnitt um Wartungen und Kennwort-Anfragen.

- [ ] **Step 1: Failing tests**
  - `DsgvoLoeschungTest::test_eigene_fahrzeuge_mit_wartungen_und_fotos_geloescht`: eigenes Fahrzeug mit Wartung + Foto und Ausnahme → `wartung`, `wartung_art`, `wartungsplan_ausnahme` leer, Datei vom Webspace weg.
  - `test_wartung_am_vereinsfahrzeug_bleibt_bezug_geleert`: `ausgefuehrt_von_person_id` und `erfasst_von_benutzer_id` NULL, Wartung vorhanden.
  - `test_uebergebenes_fahrzeug_behaelt_wartungen`.
  - `test_vorschau_zaehlt_wartungen`.
  - `test_fehler_mitten_drin_laesst_wartungen_unveraendert` (bestehenden Rollback-Test um eine Wartung erweitern).
  - `DemoDatenTest`: nach `erzeuge(true)` genau eine Anlage mit Code A1; `Wartungsplan::zusammenfassung` liefert `rot` ≥ 1 und `gelb` ≥ 1.
- [ ] **Step 2:** Filter `DsgvoLoeschung|DemoDaten` → FAIL.
- [ ] **Step 3: Implement** wie in Interfaces; README und `VERSION` (`0.5.0`).
- [ ] **Step 4:** Volle Suite grün; `bash bin/release.sh` erzeugt `dist/emc78-verwaltung-0.5.0.zip`; `unzip -l` zeigt `migrations/010_kennwort_vergessen.sql` bis `migrations/013_wartung.sql` und `src/Mail/SmtpMailer.php`, keine `tests/Support`-Dateien.
- [ ] **Step 5: Commit** „Stufe 2b: DSGVO-Löschung für Wartungen, Beispieldaten, README, Version 0.5.0“.
