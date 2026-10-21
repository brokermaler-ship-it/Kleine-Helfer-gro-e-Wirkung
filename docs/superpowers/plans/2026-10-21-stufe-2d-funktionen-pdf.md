# Stufe 2d – Funktionstasten aus PDF – Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Am Decoder lässt sich neben CSV ein Hersteller-PDF hochladen; die App liest den Text aus, erkennt Zeilen wie `F0 Licht`, `F2 – Pfiff`, `F 3: Glocke` (F0–F68) und zeigt sie in derselben Vorschau wie den CSV-Import zum Prüfen und Übernehmen.

**Architecture:** Zwei getrennte Bausteine in `Emc\Digital`: `FunktionPdfErkennung` (rein, arbeitet nur auf Text, voll unit-testbar mit Textbeispielen typischer Anleitungen) und `PdfText` (dünne Hülle um `smalot/pdfparser` mit Größen-, Seiten-, Speicher- und Zeitgrenzen; übersetzt alle Bibliotheksfehler in `DomainFehler` mit deutscher Meldung). `FunktionController` bekommt `pdfHochladen`/`pdfUebernehmen` nach dem Muster von `csvHochladen`/`csvUebernehmen`; die Vorschau-Seite `digital/csv_vorschau.php` wird für beide Quellen genutzt. Das PDF wird nur im Speicher gelesen und nie gespeichert. Die Tastenobergrenze steigt app-weit von F31 auf F68 (eine Konstante).

**Tech Stack:** PHP 8.2, PHPUnit 11, bestehendes Template-System (`View`, `e()`, `url()`), Bootstrap 5; neue Composer-Abhängigkeit `smalot/pdfparser` (^2.12, reines PHP, keine Binärprogramme). Keine Datenbank-Änderung.

**Spec:** `docs/superpowers/specs/2026-10-20-stufe-2-wartung-reparatur-design.md` (Abschnitte 1, 2 Zeile 2d, **7**, 9 „PDF ohne Text …“, 10 „PDF-Erkennung“); Hauptspezifikation `docs/superpowers/specs/2026-10-18-emc78-fahrzeug-anlagen-verwaltung-design.md` (Funktionstasten, Abschnitt Tests).

## Global Constraints

- PHP ≥ 8.2, kein Framework. Einzige neue Composer-Abhängigkeit: `smalot/pdfparser` (Task 2). Keine OCR, keine externen Programme (`pdftotext` o. ä.), kein Netzwerkzugriff.
- **Keine neue Migration**; 001–015 bleiben unverändert, `MigratorTest` bleibt wie er ist. `funktion.taste` ist `TINYINT UNSIGNED` und fasst F68 schon.
- Tastenbereich app-weit **F0–F68** (Stufe-2-Spezifikation geht der Hauptspezifikation „F0–F31“ vor): `FunktionRepository::MAX_TASTE = 68`, genutzt von Formular, CSV- und PDF-Import.
- Rechte wie beim CSV-Import: Routen mit `erfordert('lesen')`, im Controller `Berechtigung::verlangeFahrzeugBearbeiten` über `ladeBearbeitbarenDecoder`.
- **Das PDF wird nicht gespeichert:** nur `file_get_contents` der PHP-Upload-Datei (PHP löscht sie am Ende der Anfrage), kein `move_uploaded_file`, keine eigenen Temp-Dateien, kein Eintrag in `dokument`. In der Sitzung liegen nur die erkannten Zeilen (höchstens 69).
- Grenzen: Datei höchstens **20 MB** (und nie mehr als `Uploadgrenzen::ausConfig($config)->dateiBytes`), gelesen werden höchstens die ersten **150 Seiten** und höchstens **2 MB** Text, Entpacken einzelner Datenströme höchstens **32 MB** (`Config::setDecodeMemoryLimit`), Bilder werden nicht behalten (`setRetainImageContent(false)`), Laufzeit `set_time_limit(30)` für das Auslesen.
- Oberfläche und Meldungen auf Deutsch, Anrede „du“; exakte Texte stehen in den Tasks. Ausgaben mit `e()`, POST-Formulare mit CSRF (globaler Schutz in `App`).
- Tests: `TEST_DB_PASSWORT=test vendor/bin/phpunit` muss nach jedem Task vollständig grün sein. Echte PDFs in Tests werden zur Laufzeit von `tests/Support/MiniPdf.php` erzeugt (keine eingecheckten Binärdateien, keine Inhalte echter Anleitungen).
- Abhängigkeiten nur mit `composer require --prefer-dist …`; vorher und nachher `git remote -v` (erwartet genau `origin https://github.com/brokermaler-ship-it/Kleine-Helfer-gro-e-Wirkung` für fetch und push). `vendor/` bleibt ungetrackt.
- Version **0.7.0** (`VERSION`), README ergänzt.
- Commit-Nachrichten auf Deutsch, enden mit den Zeilen `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` und `Claude-Session: https://claude.ai/code/session_01WPjn12EeTpMLbw1UrLaWZi`. Nicht pushen.

## Review Focus

1. **Bösartige oder riesige PDFs** (Zip-Bombe in einem Datenstrom, 2000 Seiten, 19-MB-Scan, kaputte Querverweistabelle, verschlüsselt): nie eine weiße Seite oder ein Hänger, sondern eine der festen Meldungen; nur die ersten 150 Seiten werden gelesen und das wird angezeigt. → Tests in Task 2 (`PdfTextTest`: Seitengrenze, Größe, Müll, Konfiguration der Grenzen).
2. **Falsche Treffer aus Fließtext:** Inhaltsverzeichnis mit Punktreihen und Seitenzahl, „Rangiergang F5“ (Taste am Zeilenende), „(wie F3)“, Seitenzahlen, Tasten außerhalb F0–F68 → nichts Falsches übernommen, Hinweise statt Fehler. → Tests in Task 1.
3. **Bisher gespeicherte Formularwerte über F31:** Nach der Erweiterung zeigt das Formular F0–F68, und Speichern des Formulars löscht keine Tasten F32–F68 mehr; CSV mit `F40` ist jetzt gültig, `F69` nicht. → Tests in Task 1.
4. **Vorschau verwechselt:** PDF-Vorschau von Decoder A, „Übernehmen“ bei Decoder B → „Es liegt keine PDF-Vorschau vor …“; eine CSV-Vorschau lässt sich nicht über die PDF-Route übernehmen und umgekehrt; zweites „Übernehmen“ nach Erfolg → Meldung, keine Änderung. → Tests in Task 2.
5. **Falsche Datei und keine Ablage:** umbenannte `.docx`, leere Datei, > 20 MB, Mitglied an fremdem Fahrzeug (403) → Meldung, Belegung unverändert; nach jedem PDF-Upload ist `dokument` unverändert und `storage/dateien` enthält keine neue Datei. → Tests in Task 2.

---

### Task 1: Tasten bis F68 und reine PDF-Texterkennung

**Files:**
- Create: `src/Digital/FunktionPdfErkennung.php`, `tests/fixtures/pdf-text/esu_loksound.txt`, `tests/fixtures/pdf-text/zimo.txt`, `tests/fixtures/pdf-text/maerklin_trix.txt`, `tests/fixtures/pdf-text/piko.txt`, `tests/fixtures/pdf-text/roco.txt`
- Modify: `src/Digital/CsvErgebnis.php` (drittes Feld `hinweise`), `src/Digital/FunktionRepository.php` (Konstante), `src/Digital/FunktionCsvImport.php` (Grenze 68), `src/Digital/FunktionController.php` (`speichern`-Schleife), `templates/digital/funktionen.php` (Schleife)
- Test: `tests/Unit/Digital/FunktionPdfErkennungTest.php`, `tests/Unit/Digital/FunktionCsvImportTest.php` (Meldung anpassen, F40 gültig), `tests/Integration/Digital/FunktionHttpTest.php` (Formular bis F68)

**Interfaces:**
- Produces:
  - `FunktionRepository::MAX_TASTE = 68` (public const int).
  - `CsvErgebnis::__construct(array $zeilen, array $fehler, array $hinweise = [])` – `@param list<string> $hinweise`: nicht blockierende Hinweise (nur PDF füllt sie; CSV-Aufrufer bleiben unverändert).
  - `FunktionPdfErkennung::erkenne(string $text): CsvErgebnis` (static) – `zeilen` aufsteigend nach Taste als `array{taste:int,beschreibung:string,schaltart:string}`, `fehler` immer `[]`, `hinweise` in Fundreihenfolge.
- CSV: `FunktionCsvImport` prüft `> FunktionRepository::MAX_TASTE`, Meldung „Zeile %d: Taste „%s“ ist ungültig (erlaubt F0–F68).“; `FunktionController::speichern` und `templates/digital/funktionen.php` laufen `0 … MAX_TASTE`.

**Erkennungsregeln** (in dieser Reihenfolge; nicht aus Signatur und Tests ableitbar):
1. Vorbereiten: Zeilenenden vereinheitlichen; geschütztes Leerzeichen, Tab und andere `\h` bleiben als Spaltentrenner erhalten, werden aber in der Beschreibung später zu einem Leerzeichen. Ligaturen `ﬁ`→`fi`, `ﬂ`→`fl`; zerlegte Umlaute (Vokal + U+0308) zu `ä ö ü Ä Ö Ü` (feste Tabelle, ohne `ext-intl`). Ungültiges UTF-8 → `mb_convert_encoding(…, 'UTF-8', 'Windows-1252')`. Nullbreites Leerzeichen (U+200B) → Leerzeichen; übrige Formatzeichen `\p{Cf}` (BOM, weiches Trennzeichen) und Steuerzeichen `\p{Cc}` außer Tab/Zeilenende entfernen. Zeilen über 2000 Zeichen werden abgeschnitten (auch nach dem Verbinden in Regel 2).
2. Silbentrennung: Endet eine Zeile auf `[a-zäöüß]-` und beginnt die nächste (nach Leerraum) mit einem Kleinbuchstaben, werden beide ohne Bindestrich verbunden. Beginnt die nächste Zeile mit einer Taste oder einem Großbuchstaben, bleibt alles, wie es ist. Beginnt sie mit „und“, „oder“, „bzw.“ oder „sowie“ (Ergänzungsstrich, „Vorder- und Rücklicht“), wird mit Leerzeichen verbunden und der Bindestrich bleibt. Laufzeit linear: geprüft wird nur das jeweils letzte Stück, verbunden am Ende. *(Nachbesserung nach Review)*
3. Tasten-Anfang (Groß-/Kleinschreibung egal) – *geändert nach Review I1*: Ein Anfang zählt nur an einer **sicheren Stelle**: am Zeilenanfang (auch nach einem Aufzählungszeichen `•·*-–` oder einer Nummer `1.`, `2)`, `a)` mit Leerraum), nach einer **Spaltenlücke** (Tab, mindestens zwei Leerzeichen oder `|`) oder nach einem **Wort** `Taste`, `Funktion`, `Funktionstaste`, `Fkt.` an einer dieser Stellen (Märklin „Funktion f0 …“, „Taste F1 = Licht“). Tasten mitten im Satz („Mit F1 schalten Sie …“, „Drücken Sie F1 für Licht.“, „Die Taste F3 schaltet …“) werden ignoriert: sie teilen nichts, werden nicht übernommen und erzeugen keine Hinweise. **Tastenzeile** *(Re-Review I2)*: Liegt die erste Taste einer Zeile am Zeilenanfang (ggf. nach Aufzählungszeichen, Nummer oder Wort), zählen in dieser Zeile weitere Tasten auch nach einem **einzelnen** Leerraum bzw. `|` (smalot gibt mittlere Spaltenlücken als ein Leerzeichen aus: „F0 Licht vorne F8 Rangiergang“ → F0 und F8). Zeilen, die nicht mit einer Taste beginnen, bleiben streng. Muster `F\h?([0-9]{1,3})` (nur ASCII-Ziffern), danach keine Ziffer und kein Buchstabe. Optional ein Bereich `\h*(?:–|—|-|…|\.\.\.?|bis)\h*F?\h?([0-9]{1,3})`. Danach Trenner `\h*(?:[:=|.–—]|-(?=\h))?\h*` – `-` ist nur Trenner, wenn Leerraum folgt (so ist „F1-Taste drücken“ kein Anfang). Ein Anfang zählt nur, wenn danach ein Buchstabe, eine Ziffer, ein Anführungszeichen, `(` oder das Zeilenende folgt, aber keine weitere Taste (so ist „(wie F3) langsam“ kein Anfang). Quantoren possessiv (kein Rückverfolgungs-Limit). Eine Zeile mit mehreren sicheren Anfängen (Spalten) wird an jedem Anfang geteilt, höchstens 70 Anfänge je Zeile; Text vor dem ersten Anfang wird verworfen; die Beschreibung reicht bis zum nächsten sicheren Anfang.
4. Beschreibung säubern: Leerraum zusammenfassen, trimmen, `|` an den Rändern entfernen, Punktreihen mit Seitenzahl am Ende (`(?:\h?\.){3,}\h*[0-9]*$`, auch mit Leerzeichen) entfernen – auch noch einmal nach dem Entfernen einer Moment-Marke. Ist die ganze Beschreibung in Klammern („F1 (Licht)“), die Klammern entfernen. Endet sie auf `(M)`, `[M]`, `(Moment)`, `(Taster)` oder `(Impuls)` (egal welche Schreibung) → `schaltart` `moment`, Marke entfernen; sonst `dauer`.
5. Verwerfen ohne Hinweis: leere Beschreibung, nur Ziffern/Satzzeichen/Häkchen, oder (egal welche Schreibung) „frei“, „nicht belegt“, „unbelegt“, „reserviert“, „ohne Funktion“, „keine Funktion“, „n. b.“, „n.b.“, „-“, „–“, „—“ – verglichen ohne Satzzeichen `.:;,!` und ohne eine Klammer am Ende („frei.“, „frei (s. u.)“). Gilt auch für Bereiche.
6. Hinweise (Zeile wird nicht übernommen, außer bei 6c/6d):
   Je Taste und Art höchstens ein Hinweis; insgesamt höchstens 20, danach ein letzter Hinweis „… und %d weitere Hinweise.“
   a. Bereich mit Text: „Bereich F%d–F%d („%s“) nicht übernommen – bitte von Hand eintragen.“ (zitierter Text höchstens 100 Zeichen)
   b. Taste > 68: „F%d liegt außerhalb von F0–F68 und wurde übersprungen.“
   c. Gleiche Taste erneut mit anderem Text (Vergleich ohne Groß-/Kleinschreibung): erste gewinnt, „F%d kommt mehrfach vor; übernommen wurde „%s“.“ (gleicher Text: still).
   d. Länger als 100 Zeichen: auf 100 kürzen (`mb_substr`, danach trimmen), „Beschreibung von F%d auf 100 Zeichen gekürzt.“

**Fixtures** (UTF-8, frei erfunden im Stil der Hersteller; genau so anlegen, die Tests prüfen diese Werte):

`esu_loksound.txt` (zweispaltige Tabelle, Spalten durch mehrere Leerzeichen):
```
LokSound 5 – Funktionsbelegung
Taste   Funktion                 Taste   Funktion
F0      Licht vorne/hinten       F8      Rangiergang
F1      Fahrgeräusch             F9      Kurvenquietschen
F2      Signalhorn hoch          F10     Bremsenquietschen aus
F3      Signalhorn tief          F11     Kupplungsgeräusch
F4 – F7 nicht belegt
Seite 12
```
`zimo.txt`:
```
ZIMO MX645 Sound-Projekt BR 218
F0 = Stirnlampen
F1 – Fahrgeräusch an/aus
F 2: Pfiff kurz (Moment)
F3: Glocke
F5–F8 Ansagen Bahnsteig
F12 Rangierbeleuch-
tung
```
`maerklin_trix.txt` (das „ä“ in Zeile F3 zerlegt als `a` + U+0308 schreiben, z. B. per `php -r`):
```
Schaltbare Funktionen
Funktion f0   Spitzensignal
Funktion f1   Rauchsatz
Funktion F2   Geräusch: Lokpfeife
Funktion F3   Geräusch: Betriebsgeräusch
Rangiergang     F5
Funktion F13 … F15 Geräusch: Bahnhofsansagen
```
`piko.txt`:
```
PIKO SmartDecoder 4.1 Sound
F0 Licht        F1 Sound an/aus
F2 Pfiff	F3 Glocke
F4 Kurvenquietschen
F2 Pfiff lang
```
(zwischen `Pfiff` und `F3` ein Tab)

`roco.txt`:
```
Funktionstaste | Funktion
F0 | Licht ein/aus
F1 | Sound ein/aus
F2 | Horn (M)
F3 | Pfeife
F69 | Testfunktion
Funktionen im Überblick ............ 7
```

- [ ] **Step 1: Failing tests** in `FunktionPdfErkennungTest` (Hilfsfunktion `tasten(CsvErgebnis): array<int,string>` Taste → Beschreibung):
  - `test_beispiele_der_spezifikation`: `erkenne("F0 Licht\nF1 Sound an\nF2 – Pfiff\nF 3: Glocke")` → `[0 => 'Licht', 1 => 'Sound an', 2 => 'Pfiff', 3 => 'Glocke']`, alle `dauer`, `hinweise` `[]`.
  - `test_esu_zwei_spalten`: Tasten 0, 1, 2, 3, 8, 9, 10, 11 mit `Licht vorne/hinten`, `Fahrgeräusch`, `Signalhorn hoch`, `Signalhorn tief`, `Rangiergang`, `Kurvenquietschen`, `Bremsenquietschen aus`, `Kupplungsgeräusch`; `hinweise` `[]` („F4 – F7 nicht belegt“ still verworfen).
  - `test_zimo_trenner_moment_bereich_trennung`: `[0 => 'Stirnlampen', 1 => 'Fahrgeräusch an/aus', 2 => 'Pfiff kurz', 3 => 'Glocke', 12 => 'Rangierbeleuchtung']`; F2 `moment`; `hinweise` = `['Bereich F5–F8 („Ansagen Bahnsteig“) nicht übernommen – bitte von Hand eintragen.']`.
  - `test_maerklin_kleinbuchstaben_und_zerlegte_umlaute`: `[0 => 'Spitzensignal', 1 => 'Rauchsatz', 2 => 'Geräusch: Lokpfeife', 3 => 'Geräusch: Betriebsgeräusch']` (F3 mit vorkomponiertem „ä“, `assertSame`), keine Taste 5 (Taste am Zeilenende ohne Text), ein Bereichshinweis F13–F15.
  - `test_piko_tab_und_doppelte_taste`: `[0 => 'Licht', 1 => 'Sound an/aus', 2 => 'Pfiff', 3 => 'Glocke', 4 => 'Kurvenquietschen']`; `hinweise` = `['F2 kommt mehrfach vor; übernommen wurde „Pfiff“.']`.
  - `test_roco_pipe_m_marke_und_grenze`: `[0 => 'Licht ein/aus', 1 => 'Sound ein/aus', 2 => 'Horn', 3 => 'Pfeife']`, F2 `moment`; `hinweise` = `['F69 liegt außerhalb von F0–F68 und wurde übersprungen.']`; die Inhaltsverzeichniszeile erzeugt nichts.
  - `test_f68_und_fuehrende_null`: `erkenne("F68 Ansage Ende\nF05 Kuppeln")` → `[5 => 'Kuppeln', 68 => 'Ansage Ende']` (aufsteigend sortiert).
  - `test_fliesstext_ohne_falsche_treffer`: `erkenne("Die Lok fährt (wie F3) langsam.\nSeite 4\nKopf 2 Zylinder")` → `zeilen` `[]`, `hinweise` `[]`.
  - `test_gleiche_taste_gleicher_text_still`: `"F1 Licht\nF1 licht"` → eine Zeile, keine Hinweise.
  - `test_ueberlange_beschreibung`: 120 × `x` → Länge 100, Hinweis „Beschreibung von F1 auf 100 Zeichen gekürzt.“
  - `test_windows_1252_text`: `"F1 Ger\xe4usch"` → `Geräusch`.
  - `FunktionCsvImportTest::test_fehlerzeilen`: `F40` jetzt gültig – Eingabe auf `F69` ändern, Meldung „Zeile 2: Taste „F69“ ist ungültig (erlaubt F0–F68).“; neuer Fall: `F40;Ansage;Dauer` ergibt Taste 40.
  - `FunktionHttpTest::test_formular_bis_f68`: `GET /decoder/<id>/funktionen` enthält `name="beschreibung[68]"`, nicht `beschreibung[69]`; `POST … /funktionen` mit `beschreibung[50] = 'Ansage'` speichert Taste 50; ein vorhandener Eintrag F40 bleibt beim Speichern erhalten.
- [ ] **Step 2:** `TEST_DB_PASSWORT=test vendor/bin/phpunit --filter 'FunktionPdfErkennung|FunktionCsvImport|FunktionHttp'` → FAIL (Klasse fehlt, Grenze 31).
- [ ] **Step 3: Implement** Konstante, `hinweise`, Grenze 68 und `FunktionPdfErkennung::erkenne` nach den Regeln oben (private Hilfsfunktionen für Vorbereiten, Teilen und Säubern).
- [ ] **Step 4:** Filter → PASS; volle Suite grün.
- [ ] **Step 5: Commit** „Funktionstasten bis F68 und Erkennung von Tasten in PDF-Text“.

### Task 2: PDF hochladen, auslesen und in der Vorschau übernehmen

**Files:**
- Create: `src/Digital/PdfText.php`, `tests/Support/MiniPdf.php`
- Modify: `composer.json`, `composer.lock`, `src/Digital/FunktionController.php`, `src/Core/App.php` (Konstruktor-Argument), `src/routes.php`, `templates/digital/funktionen.php` (Abschnitt „PDF-Import“), `templates/digital/csv_vorschau.php` (beide Quellen)
- Test: `tests/Unit/Digital/PdfTextTest.php`, `tests/Integration/Digital/FunktionHttpTest.php` (ergänzen)

**Interfaces:**
- Consumes: `FunktionPdfErkennung::erkenne(string): CsvErgebnis`, `CsvErgebnis::$hinweise`, `FunktionRepository::MAX_TASTE` (Task 1).
- Abhängigkeit (zuerst):
  ```bash
  git remote -v   # notieren
  composer require --prefer-dist smalot/pdfparser:^2.12 --no-interaction
  git remote -v   # muss identisch sein
  git status --short   # nur composer.json und composer.lock
  ```
  Hat sich `git remote -v` verändert: `git remote set-url origin https://github.com/brokermaler-ship-it/Kleine-Helfer-gro-e-Wirkung` (fetch und push) und jede zusätzliche Gegenstelle mit `git remote remove <name>` entfernen, dann erneut prüfen. Ist `^2.12` mit `config.platform.php = 8.2.0` nicht auflösbar, die neueste auflösbare 2.x nehmen und die Einschränkung in `composer.json` entsprechend setzen. Listet `composer show smalot/pdfparser` Pflicht-Erweiterungen (z. B. `ext-zlib`, `ext-iconv`), diese auch in `composer.json` `require` eintragen (für README in Task 3 merken).
- `tests/Support/MiniPdf.php` (`Emc\Tests\Support\MiniPdf`, nur Tests): `static erzeuge(list<list<string>> $seiten): string` – gültiges PDF 1.4 (eine Seite je innerer Liste, Schrift Helvetica mit `/Encoding /WinAnsiEncoding`, je Zeile `(…) Tj T*`; Text nach Windows-1252, `(`, `)`, `\` maskiert; Tab als Leerzeichen; `xref`-Offsets berechnet); `static ohneText(): string` – eine Seite nur mit einem gefüllten Rechteck (`re f`), kein `Tj`.
- `PdfText::__construct(int $maxBytes = self::MAX_BYTES, int $maxSeiten = self::MAX_SEITEN, int $zeitSekunden = 30)`; Konstanten `MAX_BYTES = 20 * 1048576`, `MAX_SEITEN = 150`, `MAX_TEXT = 2 * 1048576`, `DECODE_LIMIT = 32 * 1048576`.
- `PdfText::lies(string $inhalt): array{text:string, seiten:int, gelesen:int}`, wirft `Emc\Core\DomainFehler` mit genau diesen Meldungen:
  - `strlen > maxBytes` → „Die Datei ist zu groß (höchstens %d MB).“ (`max(1, intdiv(maxBytes, 1048576))`).
  - `%PDF-` nicht in den ersten 1024 Bytes → „Die Datei ist kein PDF.“
  - Bibliothek wirft (`\Throwable` beim `parseContent`): Meldung enthält „secured“ oder „encrypt“ (ohne Groß-/Kleinschreibung) → „Das PDF ist geschützt (verschlüsselt) und kann nicht gelesen werden.“; sonst „Das PDF konnte nicht gelesen werden.“ Ursache mit `error_log` (Klasse und Meldung, kein Dateiinhalt).
  - Gesammelter Text ohne Leerraum kürzer als 20 Zeichen → „Das PDF enthält keinen lesbaren Text (vermutlich ein Scan). Texterkennung gibt es nicht – bitte die Tasten von Hand eintragen.“
  - Ablauf: `new \Smalot\PdfParser\Config()` mit `setRetainImageContent(false)` und `setDecodeMemoryLimit(self::DECODE_LIMIT)`; `@set_time_limit($zeitSekunden)`; `(new Parser([], $config))->parseContent($inhalt)`; aus `getPages()` die ersten `maxSeiten` Seiten je `getText()` (Fehler einer Seite: Seite überspringen), Seiten mit `"\n"` verbinden, abbrechen sobald `MAX_TEXT` erreicht. `seiten` = Gesamtzahl, `gelesen` = tatsächlich gelesene Seiten.
- `FunktionController::__construct(…, Session $session, ?PdfText $pdfText = null)` (Standard `new PdfText()`); `App` übergibt `new PdfText(min(PdfText::MAX_BYTES, Uploadgrenzen::ausConfig($this->config)->dateiBytes))`.
- `dateiFehler(mixed $datei, string $art, int $maxBytes): ?string` – bestehende Meldungen mit `$art` (`CSV` bzw. `PDF`): „Bitte eine %s-Datei auswählen.“, „Die Datei ist zu groß (höchstens %d MB).“, „Die Datei konnte nicht hochgeladen werden.“, „Die Datei ist leer.“; CSV ruft mit `('CSV', self::MAX_CSV_BYTES)` (Meldungen unverändert).
- `pdfHochladen(Request $r, array $p): Response`: Datei prüfen → `lies` (DomainFehler → Meldung `fehler`, Weiterleitung `ziel`) → `erkenne(text)`; bei `gelesen < seiten` Hinweis vorn: „Das PDF hat %d Seiten; gelesen wurden die ersten %d.“; keine Zeilen → Meldung „Im PDF wurden keine Funktionstasten gefunden (erwartet z. B. „F0 Licht“). Bitte die Tasten von Hand eintragen.“ und Weiterleitung; sonst Sitzung `pdf_vorschau_<decoderId>` = `['zeilen' => …, 'fehler' => []]` und 200 mit `digital/csv_vorschau` (`titel` „PDF-Vorschau“, `quelle` `pdf`).
- `pdfUebernehmen(Request $r, array $p): Response` und `csvUebernehmen` nutzen eine private `vorschauUebernehmen(array $dec, string $quelle): Response` (Schlüssel `<quelle>_vorschau_<id>`). PDF-Meldungen: ohne Vorschau „Es liegt keine PDF-Vorschau vor. Bitte die Datei erneut hochladen.“, Erfolg „Die Funktionen wurden aus der PDF-Datei übernommen.“ CSV-Meldungen bleiben wörtlich wie bisher. Übernehmen ersetzt die Belegung wie beim CSV-Import (Protokoll über `uebernimm`).
- Routen (`lesen`): `POST /decoder/{id}/funktionen/pdf` → `pdfHochladen`, `POST /decoder/{id}/funktionen/pdf/uebernehmen` → `pdfUebernehmen`.
- `templates/digital/funktionen.php`, nach „CSV-Import“: `<section aria-labelledby="pdf-titel">` mit Überschrift „PDF-Import“, Text „Bedienungsanleitung des Decoders oder der Lok als PDF (höchstens 20 MB). Erkannt werden Zeilen wie „F0 Licht“ oder „F 3: Glocke“; gescannte PDFs ohne Text gehen nicht. Die Datei wird nicht gespeichert.“, Formular `enctype="multipart/form-data"` an `…/funktionen/pdf`, Feld `name="datei"` `id="pdf-datei"` `accept=".pdf,application/pdf"` mit Label „PDF-Datei“, Knopf „Vorschau anzeigen“.
- `templates/digital/csv_vorschau.php`: `$quelle = $quelle ?? 'csv'`; Überschrift „CSV-Vorschau: …“ bzw. „PDF-Vorschau: …“; Formular an `…/<quelle>/uebernehmen`; bei `pdf` über der Tabelle `<p>` „%d Tasten erkannt. Nicht erkannte Tasten bitte nach dem Übernehmen von Hand ergänzen.“ (bei 1: „1 Taste erkannt. …“); sind `hinweise` vorhanden, `<div class="alert alert-info">` mit „Hinweise“ und Liste.

- [ ] **Step 1: Failing tests**
  - `PdfTextTest` (Unit, mit `MiniPdf`):
    - `test_liest_text_mit_umlauten`: `erzeuge([['F0 Licht', 'F1 Fahrgeräusch']])` → `text` enthält `F0 Licht` und `Fahrgeräusch`, `seiten` 1, `gelesen` 1; Ergebnis durch `FunktionPdfErkennung::erkenne` ergibt Tasten 0 und 1.
    - `test_seitengrenze`: `new PdfText(maxSeiten: 2)` mit drei Seiten (`F0 A`, `F1 B`, `F2 C`) → `seiten` 3, `gelesen` 2, `text` ohne `F2`.
    - `test_scan_ohne_text`: `ohneText()` → DomainFehler „Das PDF enthält keinen lesbaren Text (vermutlich ein Scan). …“ (volle Meldung).
    - `test_kein_pdf`: `"PK\x03\x04…"` → „Die Datei ist kein PDF.“
    - `test_kaputtes_pdf`: `"%PDF-1.4\n1 0 obj << /Type /Catalog"` → DomainFehler, Meldung ist eine von „Das PDF konnte nicht gelesen werden.“ oder der Scan-Meldung; nie eine andere Ausnahme.
    - `test_zu_gross`: `new PdfText(maxBytes: 1048576)` mit `str_repeat('x', 1048577)` → „Die Datei ist zu groß (höchstens 1 MB).“
  - `FunktionHttpTest` (Hilfsfunktion `uploadPdf(string $inhalt, string $name = 'anleitung.pdf')` wie `upload`):
    - `test_pdf_upload_vorschau_und_uebernehmen`: Bearbeiter, PDF mit `F0 Licht`, `F2 Pfiff (M)`, `F5–F8 Ansagen` → 200, enthält „PDF-Vorschau“, „2 Tasten erkannt.“, „Hinweise“, `/pdf/uebernehmen`; Sitzung hat `pdf_vorschau_<id>`; `POST …/pdf/uebernehmen` → 303, Belegung = F0 `dauer`, F2 `moment`, Meldung „Die Funktionen wurden aus der PDF-Datei übernommen.“, Sitzungsschlüssel entfernt; zweites Übernehmen → Meldung „Es liegt keine PDF-Vorschau vor. …“, Belegung unverändert.
    - `test_pdf_ohne_text_und_ohne_tasten`: `ohneText()` → 303 mit Scan-Meldung; PDF nur mit „Allgemeine Hinweise“ → 303 mit „Im PDF wurden keine Funktionstasten gefunden …“; jeweils kein `pdf_vorschau_<id>`, Belegung unverändert.
    - `test_pdf_falsche_datei`: ohne Datei → „Bitte eine PDF-Datei auswählen.“; leer → „Die Datei ist leer.“; `.docx`-Inhalt (`PK…`) → „Die Datei ist kein PDF.“
    - `test_pdf_wird_nicht_gespeichert`: Anzahl Zeilen in `dokument` und Dateien unter dem Speicherordner der Testkonfiguration vor und nach Upload + Übernehmen gleich.
    - `test_vorschau_nur_fuer_eigenen_decoder_und_quelle`: PDF-Vorschau an Decoder A, `POST /decoder/<B>/funktionen/pdf/uebernehmen` → Meldung „Es liegt keine PDF-Vorschau vor. …“, B unverändert; CSV-Vorschau an A, `POST …/A/funktionen/pdf/uebernehmen` → gleiche Meldung, A unverändert.
    - `test_pdf_rechte`: Mitglied an fremdem Fahrzeug `POST …/funktionen/pdf` → 403; Funktionsseite für den Bearbeiter enthält „PDF-Import“ und `accept=".pdf,application/pdf"`, für das fremde Mitglied nicht.
    - Bestehende CSV-Tests unverändert grün.
- [ ] **Step 2:** `TEST_DB_PASSWORT=test vendor/bin/phpunit --filter 'PdfText|FunktionHttp'` → FAIL (Klasse fehlt).
- [ ] **Step 3: Implement** – zuerst die Abhängigkeit (Befehle oben, `git remote -v` vorher/nachher), dann `MiniPdf`, `PdfText`, Controller, `App`, Routen, Templates wie in Interfaces.
- [ ] **Step 4:** Filter → PASS; volle Suite grün; `git remote -v` unverändert; `git status --short` zeigt keine Dateien unter `vendor/`.
- [ ] **Step 5: Commit** „Funktionstasten aus PDF: Hochladen, Auslesen und Vorschau wie beim CSV-Import“ (mit `composer.json`, `composer.lock`).

### Task 3: README, Version 0.7.0 und Release-Prüfung

**Files:**
- Modify: `README.md`, `VERSION` → `0.7.0`
- Unverändert prüfen: `bin/release.sh` (installiert `vendor/` generisch per `composer install --no-dev` in `build/` und ersetzt aus Quellen geklonte Pakete durch `git archive`; keine Änderung nötig, solange die Prüfung in Step 2 passt)

**Interfaces:**
- README:
  - Einleitung (Zeile 3): „Decoder (Adressen, Funktionstasten, CVs)“ um „Funktionstasten auch aus Hersteller-PDFs“ ergänzen.
  - Rollentabelle, Zeile Mitglied: „Funktionstasten (Formular, CSV-Import, PDF-Import, Vorlagen)“.
  - Versionsabsatz nach 0.6.0: „Version 0.7.0 liest **Funktionstasten aus PDF-Anleitungen** (siehe „Funktionstasten aus PDF“) und erlaubt Tasten bis **F68** (bisher F31). Beim Update auf 0.7.0 **alle Dateien** hochladen, auch den Ordner `vendor/` – er enthält die neue Bibliothek `smalot/pdfparser`. Eine Datenbank-Änderung gibt es nicht.“
  - Voraussetzungen: in Task 2 ermittelte Pflicht-Erweiterungen der Bibliothek ergänzen (falls welche; meist bei jedem Hoster aktiv).
  - Neuer Abschnitt „## Funktionstasten aus PDF“ (nach „Etiketten“): Ort (Decoder → Funktionen → „PDF-Import“), erkannte Formen (`F0 Licht`, `F1 Sound an`, `F2 – Pfiff`, `F 3: Glocke`, zwei Spalten nebeneinander, `(M)`/`(Moment)` = Moment-Taste), was nicht übernommen wird (Bereiche wie „F5–F8 Ansagen“, doppelte Tasten – die erste gilt, Tasten über F68, Text ohne Taste vorn) und dass die Vorschau dafür Hinweise zeigt; Übernehmen ersetzt die Belegung wie beim CSV-Import, Rest danach von Hand ergänzen; gescannte PDFs ohne Text werden abgelehnt (keine Texterkennung); Grenzen 20 MB (bzw. kleiner, wenn der Webspace weniger erlaubt) und die ersten 150 Seiten; **das PDF wird nicht gespeichert** – wer die Anleitung aufheben will, lädt sie zusätzlich als Dokument am Fahrzeug hoch.
  - Entwicklung › Release bauen: Produktions-Abhängigkeiten jetzt „`chillerlan/php-qrcode` (mit `chillerlan/php-settings-container`) und `smalot/pdfparser` (mit seinen Abhängigkeiten, z. B. `symfony/polyfill-mbstring`)“ – genaue Liste aus `composer show --no-dev --name-only`.

- [ ] **Step 1:** README und `VERSION` wie oben.
- [ ] **Step 2: Verify**
  - `TEST_DB_PASSWORT=test vendor/bin/phpunit` → alles grün.
  - `git remote -v` notieren; `bash bin/release.sh` → „Fertig: dist/emc78-verwaltung-0.7.0.zip“; `git remote -v` unverändert, `git status --short` sauber (nur die eigenen Änderungen).
  - `unzip -l dist/emc78-verwaltung-0.7.0.zip` zeigt `src/Digital/PdfText.php`, `src/Digital/FunktionPdfErkennung.php`, `vendor/smalot/pdfparser/src/Smalot/PdfParser/Parser.php`, `vendor/chillerlan/php-qrcode/`, `vendor/autoload.php`; **nicht** `tests/Support/MiniPdf.php`, `tests/fixtures/pdf-text/`, `vendor/phpunit/`, irgendein `/.git/`.
  - Kurztest des Pakets: `php -r 'require "build/vendor/autoload.php"; var_dump(class_exists(Smalot\PdfParser\Parser::class));'` → `bool(true)`.
- [ ] **Step 3: Commit** „Stufe 2d: Funktionstasten aus PDF, README, Version 0.7.0“.
