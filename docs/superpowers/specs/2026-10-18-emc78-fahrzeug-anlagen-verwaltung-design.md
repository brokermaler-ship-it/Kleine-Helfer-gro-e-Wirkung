# EMC 78 Halle e.V. – Fahrzeug- und Anlagenverwaltung

**Stand:** 18.10.2026 · **Status:** Entwurf zur Prüfung

## 1. Ziel

Eine vereinsinterne Web-Anwendung für den EMC 78 Halle e.V. (Modellbahn), die

- alle **Fahrzeuge** (Vereinseigentum und Fahrzeuge der Mitglieder) mit ihren **Digitaladressen**, Decodern, Funktionstasten und CVs katalogisiert,
- **Wartungspläne** und **Reparaturen** für Fahrzeuge **und Anlagen** verwaltet,
- **Anlagen-Dossiers** mit Modulen, Bauteilen und Zubehöradressen führt,
- einen **Veranstaltungskalender** bietet, an dem Fahrzeuge an Anlagen angemeldet und **Betriebsstunden** automatisch gezählt werden,
- **Transportboxen und Packlisten** je Anlage und Veranstaltung erzeugt und mobil abhaken lässt,
- **Dokumente, 3D-Modelle und Druckdateien** zentral speichert und anzeigt.

**Erfolgskriterien**

1. Vor einer Ausstellung sieht man auf einen Blick, ob angemeldete Fahrzeuge doppelte Adressen haben, defekt oder überfällig in der Wartung sind.
2. Beim Packen und Zurückpacken geht nichts verloren; jedes Mitgliederfahrzeug ist nachweislich zurückgegeben.
3. Defekte (Fahrzeug oder Weiche) können von jedem Mitglied in unter einer Minute am Handy gemeldet werden.
4. Die Anwendung läuft auf dem Vereins-Webspace und kann vom Vereinsverantwortlichen zusammen mit Claude gepflegt werden.

## 2. Rahmenbedingungen

| Thema | Festlegung |
|---|---|
| Betrieb | Vorhandener Vereins-Webspace/Server. Annahme: PHP ≥ 8.2 und MySQL ≥ 8 / MariaDB ≥ 10.6 (bei Inbetriebnahme prüfen) |
| Oberfläche | Responsive (PC, Tablet, Handy), deutschsprachig |
| Pflege | Durch einen Vereinsverantwortlichen gemeinsam mit Claude → einfache Technik, kein Build-Prozess auf dem Server, Deployment per FTP |
| Stromsystem | Standard 2-Leiter; 3-Leiter wählbar |
| Protokoll | Standard DCC; Motorola, mfx, Selectrix wählbar |

## 3. Benutzer und Rechte

| Rolle | Darf |
|---|---|
| **Mitglied** | Alles lesen · Defekte melden (Fahrzeug, Anlage, Modul, Bauteil) · Wartungen an **eigenen** Fahrzeugen eintragen |
| **Bearbeiter** (z. B. Fahrzeugwart) | Zusätzlich: Fahrzeuge, Wartung, Reparaturen, Anlagen, Veranstaltungen, Boxen, Dateien anlegen und ändern |
| **Administrator** | Zusätzlich: Benutzer und Rollen verwalten, Stammlisten (Spurweiten, Wartungsarten …), Datensicherung |

- Personen (Mitglieder) werden einmal erfasst; ein Login-Konto ist optional (auch Eigentümer ohne Login möglich).
- Alle Änderungen werden in einem **Änderungsprotokoll** festgehalten (wer, wann, was).

## 4. Fachliche Bereiche

### 4.1 Fahrzeugkatalog

**Fahrzeug:** Inventarnummer (automatisch, `EMC-0001`), Bezeichnung, Baureihe, Bahnverwaltung, Epoche, Gattung (Lok, Triebwagen, Wagen, Sonderfahrzeug), Hersteller, Artikelnummer, Spurweite, Stromsystem, **Eigentümer** (Verein oder Mitglied – Mitgliederfahrzeuge als „extern“ gekennzeichnet), Status (einsatzbereit, in Wartung, defekt, ausgeliehen, ausgemustert), Fotos (Titelbild), Notizen, Betriebsstunden.

**Decoder** (ein Fahrzeug kann mehrere haben, z. B. Triebzug): Hersteller, Typ, Firmware, Sound ja/nein, Protokoll, Adresse (kurz/lang).

- Plausibilitätsprüfung: DCC kurz 1–127, lang 128–10239; Motorola 1–255; mfx ohne Pflichtadresse.

**Funktionstasten** F0–F31: Beschreibung, Schaltart (Dauer/Moment).

- **CSV-Import** nach Vorlage `Taste;Beschreibung;Schaltart` (Vorlage in der App herunterladbar).
- **PDF-Import**: Text der Hersteller-PDF wird ausgelesen, Zeilen wie „F3 … Bremsenquietschen“ werden erkannt und in einer **Vorschau zum Korrigieren** angezeigt; gespeichert wird erst nach Bestätigung. Die PDF bleibt als Dokument am Fahrzeug. Gescannte PDFs (ohne Text) werden nicht erkannt.
- **Funktionsvorlagen**: Belegung eines Decoders als Vorlage speichern und auf andere Fahrzeuge übertragen.

**CV-Liste:** CV-Nummer, Wert, Kommentar (zur Wiederherstellung nach Decoder-Reset).

**Adressprüfung Fahrzeuge**

- Eindeutig im **ganzen Verein je Spurweite** und Protokoll.
- Bei Konflikt: **Warnung** mit Link auf das andere Fahrzeug; Speichern bleibt möglich.
- Adressübersicht je Spurweite mit Markierung der Doppelungen.

### 4.2 Wartung und Reparatur

Gilt gleichermaßen für **Fahrzeuge, Anlagen, Module und Bauteile** (auch Mitgliederfahrzeuge).

**Wartungsarten** (Stammliste, erweiterbar): z. B. Räder/Radschleifer reinigen, Getriebe schmieren, Haftreifen tauschen, Kohlen prüfen, Gleise reinigen. Intervall nach **Monaten** und/oder **Betriebsstunden**.

**Wartungsplan:** Zuordnung Wartungsart → Objekt; Zuordnung auf mehrere Objekte gleichzeitig möglich (z. B. alle Dampfloks).

**Fälligkeit:** Ab letzter durchgeführter Wartung dieser Art; fällig ist, was zuerst erreicht wird (Zeit oder Stunden). Ampel: 🔴 überfällig · 🟡 bald fällig (≤ 1 Monat bzw. ≤ 10 % des Stundenintervalls) · 🟢 in Ordnung. Übersicht filterbar nach Anlage, Spurweite, Eigentümer (Verein/Mitglied).

**Wartungsprotokoll:** Datum, ausgeführt von, Arbeiten, Notizen, Fotos, Betriebsstundenstand.

**Reparatur:** Objekt (Fahrzeug, Anlage, Modul oder Bauteil), Fehlerbeschreibung, gemeldet von, Priorität, zuständig, geplanter Termin, Status `gemeldet → geplant → in Arbeit → wartet auf Teile → erledigt`, Ersatzteile (Bezeichnung, Artikelnummer, Bezugsquelle, Kosten, bestellt; optional Verweis auf eine 3D-Druckdatei), Fotos, Kosten gesamt.

- Offene Reparatur setzt den Fahrzeugstatus auf „defekt“ bzw. „in Wartung“; nach Erledigung zurück auf „einsatzbereit“.

**Defekt melden** (alle Mitglieder, mobil): Objekt wählen (oder per QR-Code direkt) → Beschreibung → optional Foto → erzeugt Reparatur mit Status „gemeldet“. Ist das Bauteil unbekannt, genügt Anlage/Modul plus Freitext.

### 4.3 Anlagen-Dossier

**Anlage:** Name, Art (Clubanlage, Ausstellungsanlage, Modulanlage), Spurweite, Thema/Epoche, Maße, Gewicht, verantwortliche Person, **Anordnungsmodus** (fest / feste Varianten / variabel).

**Technik:** Digitalzentrale, Booster, Steuerungssoftware, Stromversorgung (Netzteile, Leistung, Absicherung), Rückmelder.

**Aufbau:** Anleitung Schritt für Schritt mit Fotos, Aufbauzeit, benötigte Personen, Platzbedarf, Stromanschluss.

**Modul:** Bezeichnung, Nummer, Maße, Anschlüsse, Zustand, Fotos, QR-Code, Wartung/Reparatur.

**Bauteil:** Art (Weiche, Weichenantrieb, Signal, Entkupplungsgleis, Beleuchtung, Rückmelder, Decoder, Sonstiges), Bezeichnung (z. B. W12), Typ/Hersteller, **Zubehöradresse**, Modul, Zustand, Foto, Reparaturhistorie.

- Zubehöradressen werden **je Anlage** auf Eindeutigkeit geprüft (Warnung wie bei Fahrzeugen).

**Anordnungen** (Modulplan):

- Eine Anordnung gehört zu einer Anlage, hat Namen und **Rastermaß** und enthält die Platzierung der Module (Rasterposition x/y, Drehung).
- Modus *fest*: genau eine Anordnung. *Feste Varianten*: mehrere benannte Anordnungen, jeweils eigenes Rastermaß. *Variabel*: Anordnung je Veranstaltung, als Kopie einer Vorlage oder früheren Anordnung.

**Zuordnungen:** Zugehörige bzw. zugelassene Fahrzeuge, Dokumente, 3D-Modelle.

### 4.4 Veranstaltungskalender

**Veranstaltung:** Name, Ort, Zeitraum, Ansprechpartner, Art (Ausstellung, Fahrtag im Clubraum, Sonstiges), Status (geplant, läuft, abgeschlossen).

- **Tage** mit Betriebszeit je Tag (Standard **8 h**, je Tag änderbar, z. B. Aufbautag 0 h).
- **Anlagen** der Veranstaltung, jeweils mit gewählter Anordnung.
- Ansicht: Monatskalender und Liste.

**Anmeldung Fahrzeug → Anlage** (pro Veranstaltung): an allen oder ausgewählten Tagen; Transport *Vereinsbox* (Box, optional Fach) oder *Eigentransport durch Mitglied*.

Prüfungen bei Anmeldung (Warnungen, kein Verbot):

- doppelte Adresse unter den an **dieser Anlage bei dieser Veranstaltung** angemeldeten Fahrzeugen,
- Fahrzeug defekt oder Wartung überfällig,
- Fahrzeug im selben Zeitraum bei einer anderen Veranstaltung angemeldet.

**Betriebsstunden:** Beim Abschließen der Veranstaltung wird je Fahrzeug *Summe der Betriebszeit der angemeldeten Tage* vorgeschlagen, kann korrigiert werden (z. B. Ausfall) und wird dann gutgeschrieben. Zusätzlich manuelle Stundenbuchung möglich.

### 4.5 Transportboxen und Packlisten

**Box:** Nummer, QR-Code, Bezeichnung, zugehörige Anlage, Maße, Gewicht, Lagerort, Packfoto.

**Standardinhalt je Box:** Module, Fahrzeuge, Zubehör/Material mit Stückzahl (frei erfassbare Positionen).

**Packliste je Veranstaltung** (automatisch): alle Boxen der eingetragenen Anlagen + angemeldete Fahrzeuge (in Box oder Liste „Mitgebracht von …“). Ladeliste mit Boxanzahl und Gesamtgewicht.

**Abhaken (mobil):** QR-Code der Box scannen → deren Checkliste. Status je Position: `gepackt → angekommen → wieder gepackt → zurück im Verein`. Fortschrittsanzeige, Warnung bei Fehlendem. **Rückgabe** jedes Mitgliederfahrzeugs mit Name und Datum bestätigt.

**Drucken:** Inhaltszettel je Box mit QR-Code; Packliste der Veranstaltung als PDF.

### 4.6 QR-Codes

Für Fahrzeuge, Module, Boxen (und optional Bauteile) erzeugt die App druckbare QR-Codes (Einzeln und als Etikettenbogen). Der Code enthält einen Link zur App; gescannt wird mit der normalen Handy-Kamera. Ziel je nach Objekt: Detailseite mit Schaltfläche „Defekt melden“ bzw. Checkliste der Box. Ohne Anmeldung führt der Link zuerst zum Login.

### 4.7 Dokumente und Dateien

Einheitlich für alle Objekte; eine Datei kann mehreren Objekten zugeordnet sein. Felder: Titel, Kategorie (Anleitung, Schaltplan, Gleisplan, Rechnung, Foto, 3D-Modell, Druckdatei, Sonstiges), hochgeladen von/am.

| Dateiart | Behandlung |
|---|---|
| PDF | Anzeige im Browser; Text für Volltextsuche ausgelesen |
| JPG/JPEG, PNG, WebP | Galerie mit Vorschaubildern; große Bilder werden verkleinert, Original bleibt |
| GIF, BMP, TIFF, HEIC | In anzeigbares Format umgewandelt, soweit der Webspace es unterstützt; Original bleibt |
| DOCX, XLSX, ODT, ODS, TXT, CSV | Download (TXT/CSV auch Ansicht) |
| MP4 | Abspielen im Browser |
| ZIP | Download |
| STL, OBJ, 3MF, glTF/GLB | 3D-Ansicht (4.8) |
| WinTrack, AnyRail, SCARM | Download; Import siehe 4.9 |
| Rocrail-Plan (XML), JMRI-Fahrzeugliste (XML) | Download; Import als späterer Ausbauschritt |

**Volltextsuche** über Fahrzeuge, Anlagen, Bauteile, Reparaturen und PDF-Inhalte.

**Sicherheit:** Positivliste erlaubter Dateitypen (Endung und Inhalt geprüft), hochgeladene Dateien werden nie ausgeführt, Ablage außerhalb des öffentlichen Webverzeichnisses bzw. durch `.htaccess` gesperrt und nur über die App mit Anmeldung abrufbar. Upload-Größe richtet sich nach dem Webspace (typisch 64–128 MB).

### 4.8 3D-Datenbank

- **3D-Ansicht** (three.js): drehen, zoomen, verschieben, auch mobil; Zuordnung zu Anlage, Modul, Bauteil oder Fahrzeug.
- **Druckdatei-Bibliothek:** Kategorie (Ersatzteil, Gebäude, Zubehör, Hilfsmittel), Verwendungszweck mit Verknüpfung zu Fahrzeug/Bauteil, Maßstab, Material, Druckeinstellungen, Fotos des Drucks, **Versionen**, **Quelle und Lizenz**.
- **Modulplan Stufe 1 (2D):** Anordnung (4.3) als Draufsicht im Raster; Klick auf Modul öffnet Dossier; offene Defekte als rote Markierung.
- **Modulplan Stufe 2 (3D):** Liegen 3D-Modelle der Module vor, werden sie anhand der Anordnung zu einer begehbaren, anklickbaren 3D-Szene zusammengesetzt.

### 4.9 WinTrack-Import (wird geprüft)

WinTrack speichert in einem proprietären, nicht dokumentierten Format. Vor einer Zusage erfolgt ein **Probelauf** mit einer echten Vereinsdatei und den in WinTrack verfügbaren Exporten (Stückliste, Bild, ggf. 3D-Export). Ziel wäre, aus der Stückliste automatisch Bauteile (Weichen, Signale) einer Anlage anzulegen. Ist das Format nicht lesbar, wird mit den Exporten gearbeitet.

## 5. Technik

**Aufbau**

- PHP 8.2+ ohne großes Framework; klare Ordnerstruktur: `public/` (einziger öffentlicher Ordner, Einstieg `index.php`), `src/` (Fachlogik, je Bereich ein Unterordner), `templates/` (HTML-Vorlagen), `migrations/` (Datenbank-Änderungen), `storage/` (Uploads, außerhalb von `public/`), `tests/`.
- Wenige, bewährte Bibliotheken per Composer; `vendor/` wird im Release-Paket mitgeliefert, damit auf dem Webspace nichts installiert werden muss. Vorgesehen: PDF-Textauslese (smalot/pdfparser), QR-Codes (chillerlan/php-qrcode), PDF-Erzeugung für Drucklisten (dompdf), PHPUnit für Tests.
- Oberfläche serverseitig gerendert, Bootstrap 5 (lokal eingebunden, kein CDN) und wenig eigenes JavaScript; three.js lokal für 3D.
- Datenbank MySQL/MariaDB über PDO mit vorbereiteten Abfragen.

**Datenmodell (Übersicht der Tabellen)**

`person`, `benutzer`, `spurweite`, `fahrzeug`, `decoder`, `funktion`, `funktionsvorlage`, `cv_wert`, `anlage`, `modul`, `bauteil`, `anordnung`, `anordnung_modul`, `wartungsart`, `wartungsplan`, `wartung`, `reparatur`, `ersatzteil`, `veranstaltung`, `veranstaltung_tag`, `veranstaltung_anlage`, `anmeldung`, `anmeldung_tag`, `box`, `box_inhalt`, `packposition` (Status je Veranstaltung), `dokument`, `dokument_zuordnung`, `druckdatei`, `aenderungsprotokoll`.

Objektübergreifende Bezüge (Wartungsplan, Reparatur, Dokumentzuordnung, QR-Code) verwenden das Paar `objekt_typ` + `objekt_id` (Typen: fahrzeug, anlage, modul, bauteil, box, veranstaltung).

**Sicherheit**

- Login mit E-Mail und Passwort (`password_hash`), Sperre nach wiederholten Fehlversuchen, Passwort-Zurücksetzen per E-Mail.
- CSRF-Schutz für alle Formulare, sichere Sitzungs-Cookies, HTTPS vorausgesetzt.
- Rechteprüfung serverseitig bei jeder Aktion.

**Betrieb und Pflege**

- **Deployment:** Release-Paket (ZIP) per FTP hochladen. Konfiguration (Datenbank-Zugang) in einer Datei `config.php`, die nicht im Repository liegt.
- **Datenbank-Updates:** Migrationen werden nach einem Update vom Administrator mit einem Klick ausgeführt; die App zeigt an, wenn Migrationen ausstehen.
- **Datensicherung:** Administrator lädt per Knopfdruck ein ZIP mit Datenbank-Export und allen Dateien herunter; Hinweis in der App, wenn die letzte Sicherung älter als 30 Tage ist. Zusätzlich die Sicherung des Hosters nutzen.
- **Dokumentation:** `README.md` (Installation, Update, Sicherung) im Repository.

**Datenschutz**

- Personenbezogene Daten auf das Nötige beschränkt (Name, Kontakt, Eigentum an Fahrzeugen).
- Kein öffentlicher Zugriff; alle Inhalte nur nach Anmeldung.
- Hosting in der EU; Mitglieder werden über die Verarbeitung informiert (Text liefert der Verein).

**Fehlerbehandlung**

- Unterscheidung **Fehler** (verhindert Speichern, z. B. Pflichtfeld fehlt, ungültige Adresse) und **Warnung** (Speichern möglich, z. B. doppelte Adresse, überfällige Wartung).
- Verständliche deutsche Meldungen am betroffenen Feld; Uploads mit klarer Meldung bei falschem Typ oder zu großer Datei.
- Technische Fehler werden protokolliert, Benutzer sehen eine allgemeine Meldung.

**Tests**

- PHPUnit-Tests für die Fachlogik: Adressprüfung (Spurweite, Protokoll, Anlage, Veranstaltung), Wartungsfälligkeit, Betriebsstundenberechnung, Rechteprüfung, CSV- und PDF-Erkennung der Funktionstasten.
- Integrationstests gegen eine Test-Datenbank (MariaDB) für Speichern/Laden.
- Manuelle Prüfliste je Ausbaustufe auf PC und Handy.

## 6. Ausbaustufen

Jede Stufe ist für sich nutzbar und bekommt einen eigenen Umsetzungsplan.

| Stufe | Inhalt |
|---|---|
| **1 – Grundgerüst & Fahrzeugkatalog** | Login, Rollen, Personen, Spurweiten, Fahrzeuge mit Decoder, Funktionen, CVs, Fotos und Dokumenten (Grundfunktion), Adressprüfung, CSV-Import Funktionen, Funktionsvorlagen, Datensicherung, Migrationen |
| **2 – Wartung & Reparatur** | Wartungsarten, -pläne, -protokoll, Fälligkeitsübersicht, Reparaturen, Defekt melden, QR-Codes für Fahrzeuge, PDF-Import Funktionen |
| **3 – Anlagen** | Anlagen-Dossier, Module, Bauteile, Zubehöradressen, Anordnungen, Wartung/Reparatur an Anlagen, QR-Codes für Module |
| **4 – Veranstaltungen** | Kalender, Anlagen mit Anordnung, Fahrzeuganmeldung mit Prüfungen, Betriebsstunden |
| **5 – Boxen & Packlisten** | Boxen, Standardinhalt, Packliste je Veranstaltung, mobiles Abhaken, Rückgabe, Druck, QR-Codes für Boxen |
| **6 – Dateien & 3D** | Erweiterte Dateiverwaltung (Umwandlung, Video), Volltextsuche, 3D-Ansicht, Druckdatei-Bibliothek |
| **7 – Modulplan** | 2D-Modulplan, danach 3D-Szene |
| **8 – Importe** | JMRI- und Rocrail-Import; WinTrack-Import nach erfolgreichem Probelauf (Probelauf kann jederzeit vorgezogen werden) |

## 7. Offene Punkte

1. **Webspace prüfen:** Anbieter, PHP-Version, Datenbank, Upload-Grenze, verfügbare Bildbibliothek (GD/Imagick), E-Mail-Versand.
2. **WinTrack-Probelauf:** Beispieldatei und Exporte einer Vereinsanlage bereitstellen.
3. **Stammlisten:** Welche Spurweiten und Wartungsarten nutzt der Verein (Startbestand)?
4. **Gestaltung:** Vereinslogo und -farben, falls vorhanden.

## 8. Nicht Bestandteil

- Direktes Auslesen oder Programmieren von Decodern (dafür weiter JMRI DecoderPro o. ä.).
- Mitgliederverwaltung mit Beiträgen, Buchhaltung.
- Öffentliche Ansicht für die Vereinswebseite (kann später ergänzt werden).
- Texterkennung (OCR) für gescannte PDFs.
