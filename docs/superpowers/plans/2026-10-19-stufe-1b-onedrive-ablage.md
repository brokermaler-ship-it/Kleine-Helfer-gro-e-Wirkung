# Stufe 1b – OneDrive-Dateiablage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Fotos und Dokumente werden zusätzlich (bzw. statt lokal) im OneDrive-Ordner des Vereins abgelegt – lesbar sortiert nach Fahrzeug –, mit Nachübertragung bei Ausfällen und Umzug vorhandener Dateien; die App funktioniert unverändert auch ohne Anbindung.

**Architecture:** Kleine Microsoft-Graph-Anbindung über eine injizierbare HTTP-Schnittstelle (cURL in Produktion, Fake in Tests). OAuth 2.0 (Authorization Code, `consumers`-Endpunkt für das private Vereinskonto) mit verschlüsselt gespeichertem Refresh-Token. Eine Warteschlange (`onedrive_auftrag`) entkoppelt Upload/Löschen vom Request: Uploads werden lokal gespeichert, sofort ein Übertragungsversuch, bei Fehler später erneut (Admin-Knopf, Cron-Skript, opportunistisch). Vorschau/Anzeige-Varianten bleiben immer lokal; Originale werden nach erfolgreicher Übertragung lokal entfernt (konfigurierbar) und bei Bedarf über die App aus OneDrive gestreamt.

**Tech Stack:** PHP 8.2 (ext-curl, ext-openssl), MySQL 8 / MariaDB 10.6, Microsoft Graph v1.0.

**Spec:** `docs/superpowers/specs/2026-10-18-emc78-fahrzeug-anlagen-verwaltung-design.md` Abschnitt 4.11 (Konto entschieden: eigenes Vereinskonto; der bestehende EMC-Ordner wird vom bisherigen Inhaber mit Bearbeitungsrechten für das Vereinskonto freigegeben).

## Global Constraints

- PHP ≥ 8.2, keine 8.3-only-Features; SQL läuft auf MySQL ≥ 8 und MariaDB ≥ 10.6.
- Keine neuen Composer-Laufzeitabhängigkeiten (kein Graph-SDK); nur ext-curl und ext-openssl (in `composer.json` `require` ergänzen).
- Ohne OneDrive-Konfiguration verhält sich die App exakt wie Version 0.2.0 (alle bestehenden Tests bleiben grün).
- Zugriff auf Dateien weiterhin **nur nach Anmeldung über die App**; niemals öffentliche OneDrive-Freigabelinks erzeugen.
- Nur Administratoren (`Berechtigung::BETRIEB`) verbinden/trennen OneDrive und starten Umzug/Übertragung.
- Geheimnisse: `onedrive_client_secret` nur in `config.php`; Refresh-/Access-Token nur verschlüsselt (AES-256-GCM, Schlüssel `app_schluessel` aus `config.php`, 32 Byte base64) in `einstellung`.
- Ordnerstruktur im Zielordner: `Fahrzeuge/<Inventarnummer> <Bezeichnung>/<Titel>.<endung>`; Namen bereinigt (keine `\ / : * ? " < > |`, max. 120 Zeichen je Segment, Leerzeichen am Ende entfernt); bei Namenskonflikt `@microsoft.graph.conflictBehavior=rename`. Dokumente ohne Fahrzeugbezug: `Sonstiges/`.
- Uploads ≤ 4 MB per einfachem PUT, größer per Upload-Session in Blöcken von 3 276 800 Byte (10 × 320 KiB).
- Deutsche Oberfläche, deutsche Meldungen; Änderungen an Konto/Zielordner ins Änderungsprotokoll (`objekt_typ` `onedrive`).
- Commit-Nachrichten deutsch mit den Trailer-Zeilen der Sitzung.

## Review Focus

1. **Token läuft ab / wird widerrufen** (Refresh schlägt mit `invalid_grant` fehl) → Status „Verbindung abgelaufen – bitte neu verbinden“, Uploads bleiben lokal in der Warteschlange, nichts geht verloren → Test in Task A/B.
2. **OneDrive kurz nicht erreichbar / 429 / 5xx** → Auftrag bleibt `wartet`, exponentielles Warten (1, 5, 30, 120 min, dann stündlich), Benutzer merkt beim Hochladen nichts außer Hinweis „wird übertragen“ → Test in Task B.
3. **Original lokal schon entfernt, OneDrive nicht erreichbar** → Anzeige-/Vorschau-Varianten funktionieren weiter; Original-Download zeigt verständliche Meldung statt 500 → Test in Task B.
4. **Doppelte Ausführung** (Cron + Admin-Knopf gleichzeitig) → kein doppelter Upload: Aufträge per `UPDATE … WHERE status='wartet' AND gesperrt_bis < NOW()` beansprucht → Test in Task B.
5. **OAuth-Rückruf manipuliert** (fehlender/falscher `state`, Fehlerparameter von Microsoft) → Abbruch mit Meldung, kein Token gespeichert → Test in Task C.

---

## Datenmodell (`migrations/004_onedrive.sql`)

| Tabelle/Spalte | Definition |
|---|---|
| `dokument.ablage` | `ENUM('lokal','onedrive') NOT NULL DEFAULT 'lokal'` – wo das Original maßgeblich liegt |
| `dokument.onedrive_item_id` | `VARCHAR(100) NULL` |
| `dokument.onedrive_pfad` | `VARCHAR(500) NULL` (lesbarer Pfad relativ zum Zielordner) |
| `dokument.original_lokal` | `TINYINT(1) NOT NULL DEFAULT 1` |
| `onedrive_auftrag` | `id`, `art ENUM('hochladen','loeschen')`, `dokument_id INT UNSIGNED NULL` (kein FK, Dokument kann gelöscht sein), `item_id VARCHAR(100) NULL`, `status ENUM('wartet','erledigt','fehler') DEFAULT 'wartet'`, `versuche INT DEFAULT 0`, `letzter_fehler VARCHAR(500) NULL`, `naechster_versuch DATETIME NOT NULL`, `gesperrt_bis DATETIME NULL`, `erstellt_am`, `geaendert_am`; INDEX(status, naechster_versuch) |

Einstellungen (Tabelle `einstellung`): `onedrive_token` (verschlüsselt JSON {refresh_token, access_token, ablauf}), `onedrive_konto` (E-Mail/Anzeigename), `onedrive_ziel` (JSON {drive_id, item_id, name}), `onedrive_status` (`verbunden` | `abgelaufen` | `getrennt`).

Config-Schlüssel (in `config.example.php` dokumentiert, alle optional): `onedrive_client_id`, `onedrive_client_secret`, `app_schluessel`, `onedrive_originale_lokal_behalten` (bool, Standard `false`), `onedrive_zeitlimit_s` (Standard 20).

---

### Task A: Verschlüsselung, Migration, HTTP-Schnittstelle, Graph-/OAuth-Client

**Files:** Create `migrations/004_onedrive.sql`, `src/Core/Tresor.php`, `src/OneDrive/{HttpClient,CurlHttpClient,HttpAntwort,GraphFehler,OAuth,TokenSpeicher,Graph}.php`, Tests `tests/Unit/Core/TresorTest.php`, `tests/Unit/OneDrive/{OAuthTest,GraphTest}.php`, `tests/Support/FakeHttpClient.php`, `tests/Integration/OneDrive/TokenSpeicherTest.php`; Modify `config.example.php`, `composer.json` (ext-curl, ext-openssl; Lock mit `--no-install --prefer-dist` aktualisieren), `src/Core/App.php` (Container).

**Interfaces (Produces):**
- `Tresor::__construct(string $schluesselBase64)` (wirft `DomainFehler('app_schluessel fehlt oder ist ungültig (32 Byte, base64).')`), `verschluessele(string $klar): string` (base64 von nonce‖tag‖ciphertext, AES-256-GCM), `entschluessele(string $geheim): string` (wirft bei Manipulation).
- `interface HttpClient { public function anfrage(string $methode, string $url, array $kopf = [], ?string $koerper = null, int $zeitlimit = 20): HttpAntwort; }`; `HttpAntwort` readonly `int $status, array $kopf, string $koerper`, `json(): array`.
- `OAuth::__construct(HttpClient, string $clientId, string $clientSecret, string $rueckrufUrl)`; `anmeldeUrl(string $state): string` (Endpoint `https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize`, Scopes `offline_access Files.ReadWrite.All User.Read`, `response_type=code`, `prompt=select_account`); `tauscheCode(string $code): array{refresh_token,access_token,ablauf}`; `erneuere(string $refreshToken): array{...}` – `invalid_grant` → `GraphFehler` mit `istAbgelaufen() === true`.
- `TokenSpeicher::__construct(Einstellungen, Tresor)`: `speichere(array $token)`, `lade(): ?array`, `loesche()`, `status(): string`, `setzeStatus(string)`.
- `Graph::__construct(HttpClient, OAuth, TokenSpeicher)`: `ich(): array` (`/me`), `ordnerListe(?string $driveId, ?string $itemId): array` (ohne Argumente: eigene Wurzel-Ordner + `sharedWithMe`-Ordner, jeweils `{drive_id,item_id,name,geteilt}`), `hochladen(string $driveId, string $elternId, string $relPfad, string $datei): array{item_id,pfad}` (≤ 4 MB PUT, sonst Upload-Session), `loeschen(string $driveId, string $itemId): void` (404 gilt als Erfolg), `inhalt(string $driveId, string $itemId): HttpAntwort` (folgt 302). Access-Token wird bei < 5 min Restlaufzeit oder 401 automatisch erneuert; 429/503 → `GraphFehler` mit `wiederholbar() === true` und `warteSekunden()` aus `Retry-After`.

**Tests:** Tresor Rundreise, Manipulation erkannt, falscher Schlüssel; OAuth-URL enthält exakt Scopes/Endpoint, Code-Tausch, `invalid_grant` → abgelaufen; Graph: Token-Erneuerung bei 401 (einmal), kleine Datei PUT-URL mit `conflictBehavior=rename` und korrekt kodiertem Pfad, große Datei → Session mit korrekten `Content-Range`-Blöcken (Testdatei 7 MB → 3 Blöcke), 429 mit Retry-After, 404 beim Löschen ok; TokenSpeicher speichert nichts im Klartext (DB-Wert enthält das Token nicht).

### Task B: Ablage-Warteschlange und Einbindung in Upload/Löschen/Auslieferung

**Files:** Create `src/OneDrive/{Pfadbildner,Auftraege,Uebertragung}.php`, `bin/onedrive-sync.php`, Tests `tests/Unit/OneDrive/PfadbildnerTest.php`, `tests/Integration/OneDrive/{AuftraegeTest,UebertragungTest,DateiOneDriveHttpTest}.php`; Modify `src/Dateien/{UploadService,DokumentRepository,DateiController}.php`, `src/Core/App.php`.

**Interfaces:**
- `Pfadbildner::fuerDokument(array $dokument, ?array $fahrzeug): string` → `Fahrzeuge/EMC-0001 V 200 060/Titel.jpg` bzw. `Sonstiges/Titel.pdf`; `bereinige(string): string` gemäß Global Constraints.
- `Auftraege::__construct(\PDO)`: `neu(string $art, ?int $dokumentId, ?string $itemId): int`, `beanspruche(int $max, \DateTimeImmutable $jetzt): array` (atomar, sperrt 10 min), `erledigt(int $id)`, `fehlgeschlagen(int $id, string $fehler, ?int $warteSekunden, \DateTimeImmutable $jetzt)` (Backoff 60, 300, 1800, 7200, dann 3600 s; nach 20 Versuchen `fehler`), `zaehle(): array{wartet:int,fehler:int,erledigt:int}`.
- `Uebertragung::__construct(Auftraege, Graph, TokenSpeicher, Einstellungen, DokumentRepository, \PDO, string $speicherPfad, bool $originaleBehalten)`: `aktiv(): bool` (Status `verbunden` und Ziel gesetzt), `verarbeite(int $max = 5): array{erledigt:int,fehler:int}` (lädt hoch → setzt `ablage='onedrive'`, `onedrive_item_id`, `onedrive_pfad`; entfernt lokales Original wenn nicht behalten → `original_lokal=0`; Löschaufträge → `Graph::loeschen`; `GraphFehler::istAbgelaufen` → Status `abgelaufen`, Auftrag bleibt `wartet`), `umzug(): int` (legt für alle `ablage='lokal'`-Dokumente ohne offenen Auftrag einen Hochlade-Auftrag an).
- `UploadService::speichere`: nach erfolgreichem Speichern, wenn `Uebertragung::aktiv()`, Auftrag anlegen und `verarbeite(1)` mit `onedrive_zeitlimit_s` versuchen; Fehler werden nie an den Benutzer durchgereicht (nur Status).
- `DokumentRepository::loeschen`: liegt das Dokument in OneDrive, Lösch-Auftrag anlegen (vor dem Löschen der Zeile).
- `DateiController::ausliefern` (Variante `original`): `original_lokal=1` → wie bisher; sonst Stream aus `Graph::inhalt` mit gleichen Headern; bei Fehler Seite 503 „Die Originaldatei liegt im OneDrive und ist gerade nicht erreichbar. Die Vorschau bleibt verfügbar; bitte später erneut versuchen.“
- Tab „Dateien“ zeigt je Datei ein kleines Status-Symbol: „im OneDrive“, „wird übertragen“, „nur lokal“.
- `bin/onedrive-sync.php` (CLI, für Cron): `verarbeite(20)`, Ausgabe einer Zeile Statistik.

**Tests:** Pfadbildner (Sonderzeichen, Länge, Umlaute bleiben); Auftraege Backoff-Zeiten, Beanspruchung schließt Doppelverarbeitung aus (zwei Aufrufe → kein Auftrag zweimal); Upload mit Fake-Graph → Dokument `onedrive`, Original lokal entfernt (bzw. behalten bei Config), Varianten bleiben; Upload bei 503 → Auftrag `wartet`, Datei lokal vollständig nutzbar; Token abgelaufen → Status `abgelaufen`, Auftrag bleibt; Löschen → Lösch-Auftrag und Ausführung; Original-Auslieferung aus Fake-Graph inkl. Header; Graph nicht erreichbar → 503-Seite, Vorschau weiterhin 200; ohne Konfiguration keinerlei Aufträge.

### Task C: Verwaltungsseite „OneDrive“

**Files:** Create `src/OneDrive/OneDriveController.php`, `templates/admin/onedrive.php`, `tests/Integration/OneDrive/OneDriveHttpTest.php`; Modify `src/routes.php`, `templates/partials/navigation.php`.

**Routen (alle `BETRIEB`):** `GET /admin/onedrive` (Status: Konfiguration vollständig? Rückruf-URL zum Kopieren `https://<host><basis_url>/admin/onedrive/rueckruf`, verbundenes Konto, Zielordner, Warteschlange wartet/fehler/erledigt, letzte Fehler), `POST /admin/onedrive/verbinden` (state = 32 Byte zufällig in Session, Redirect zu `anmeldeUrl`), `GET /admin/onedrive/rueckruf` (state prüfen mit `hash_equals`, `error`-Parameter → Meldung; Code tauschen, `ich()` speichern, Status `verbunden`), `GET /admin/onedrive/ordner` + `POST /admin/onedrive/ordner` (Ordner auswählen aus `ordnerListe`, auch Unterordner öffnen; Auswahl speichern), `POST /admin/onedrive/uebertragen` (`verarbeite(10)`), `POST /admin/onedrive/umzug` (`umzug()` + `verarbeite(10)`, Meldung „n Dateien zum Umzug vorgemerkt“), `POST /admin/onedrive/trennen` (Token löschen, Status `getrennt`; Dateien bleiben im OneDrive und lokal soweit vorhanden). Hinweisleiste für Admins bei Status `abgelaufen`: „Die OneDrive-Verbindung ist abgelaufen. Neue Dateien werden lokal gesammelt – bitte unter Verwaltung › OneDrive neu verbinden.“ Änderungsprotokoll-Einträge für verbinden/trennen/Zielordner.

**Tests:** 403 für Mitglied/Bearbeiter auf allen Routen; Rückruf ohne/mit falschem state → Meldung, kein Token; Rückruf mit `error=access_denied` → Meldung; erfolgreicher Rückruf (Fake-HTTP) → Status verbunden, Konto gespeichert; Ordnerauswahl speichert drive/item; Umzug legt Aufträge an; Hinweisleiste bei `abgelaufen`; ohne `onedrive_client_id` zeigt die Seite die Einrichtungshinweise statt des Verbinden-Knopfs.

### Task D: Anleitung, Version, Release

**Files:** Modify `README.md` (Abschnitt „OneDrive anbinden“: Vereinskonto anlegen, Ordnerfreigabe mit Bearbeitungsrechten, App-Registrierung im Microsoft Entra Admin Center bzw. Azure-Portal – Kontotyp „Nur persönliche Microsoft-Konten“, Umleitungs-URI Typ „Web“ = Rückruf-URL, Clientschlüssel anlegen, delegierte Berechtigungen `Files.ReadWrite.All`, `offline_access`, `User.Read` –, `config.php`-Schlüssel inkl. `app_schluessel` erzeugen mit `php -r "echo base64_encode(random_bytes(32));"` oder per Hinweis auf der OneDrive-Seite, Verbinden, Zielordner wählen, Umzug, optional Cron `php bin/onedrive-sync.php` alle 15 min, Verhalten bei Ausfall), `VERSION` → `0.3.0`, `config.example.php`; Release-ZIP neu bauen.

**Tests:** volle Suite grün; ZIP enthält `migrations/004_onedrive.sql` und `bin/onedrive-sync.php`.
