# Stufe 2 – DSGVO-Löschung, Wartung, Reparatur, Codes

Ergänzung zur Hauptspezifikation `2026-10-18-emc78-fahrzeug-anlagen-verwaltung-design.md` (Abschnitte 3, 4.2, 4.6 und 6). Wo diese Ergänzung genauer ist oder abweicht, gilt sie.

Stand: 20.10.2026 · abgestimmt mit dem Vorstand im Gespräch.

## 1. Ziel und Erfolgskriterien

- Ein ausgetretenes Mitglied lässt sich **vollständig nach DSGVO löschen**, ohne die Vereinsdaten (Vereinsfahrzeuge, Reparaturhistorie) zu beschädigen.
- Fällige Wartungen an Fahrzeugen **und Anlagen** sind auf einen Blick sichtbar (Ampel).
- Jedes Mitglied meldet einen Defekt am Handy in **unter einer Minute**; der Fahrzeugwart sieht ihn sofort in der App.
- Fahrzeuge und Anlagen lassen sich über einen **kurzen Code** (oder die Digitaladresse) sofort aufrufen.
- Funktionstasten lassen sich zusätzlich zu CSV aus **Hersteller-PDFs** übernehmen.

## 2. Umfang und Reihenfolge

Stufe 2 wird in vier Teilen umgesetzt; jeder Teil bekommt einen eigenen Umsetzungsplan und ist für sich nutzbar.

| Teil | Inhalt |
|---|---|
| **2a** | Rolle **Fahrzeugwart** · **DSGVO-Löschung** einer Person · **Abstellgleis** für ausgemusterte Fahrzeuge |
| **2b** | **Einfache Anlagenliste** · Wartungsarten (Startbestand) · Wartungspläne mit Ausnahmen · Wartungsprotokoll · Fälligkeit/Ampel |
| **2c** | Reparaturen · **Defekt melden** · Hinweise für Fahrzeugwart/Bearbeiter · Feld **„Code“** · Etiketten (Fahrzeug-Klebchen, Anlagen-QR) |
| **2d** | Funktionstasten aus **PDF** auslesen |
| **2e** | **Magazin** (Vereinsinventar mit Eingang/Ausgang) und **Modellbahnbörse** (Angebote, Kasse, Abrechnung, öffentlicher Bereich) – eigene Spezifikation `2026-10-20-magazin-modellbahnboerse-design.md` |

Nicht Teil von Stufe 2: E-Mail-Benachrichtigungen, Texterkennung (OCR) für gescannte PDFs, Module/Bauteile/Anordnungen (Stufe 3), automatische Betriebsstunden aus Veranstaltungen (Stufe 4).

## 3. Rollen (ersetzt die Tabelle in Hauptspezifikation Abschnitt 3)

Der Fahrzeugwart ist eine **eigene Rolle** zwischen Mitglied und Bearbeiter (vom Vorstand bestätigt, 20.10.2026).

Die Rollen bauen aufeinander auf; jede höhere Rolle darf alles, was die darunter darf.

| Rolle | Darf (zusätzlich zur Rolle darunter) |
|---|---|
| **Mitglied** | Alles lesen · eigene Fahrzeuge anlegen und **selbst angelegte** bearbeiten (Löschen nicht) · Defekte melden (Fahrzeug, Anlage) · Wartungen und Reparaturen an **eigenen** Fahrzeugen eintragen und abschließen |
| **Fahrzeugwart** (neu) | **Alles rund um alle Fahrzeuge, auch die privaten:** anlegen, bearbeiten, **löschen**, Decoder, Funktionen, CVs, Fotos und Dokumente an Fahrzeugen · Wartungen eintragen · Ausnahmen der Wartungspläne · **Wartungsarten pflegen** · Reparaturen planen, bearbeiten, abschließen, verwerfen · Etiketten drucken |
| **Bearbeiter** | Personen · Dateien allgemein · Anlagen (ab 2b) · später Veranstaltungen und Boxen |
| **Administrator** | Benutzer und Rollen · übrige Stammlisten (Spurweiten …) · Datensicherung, Update, OneDrive · **DSGVO-Löschung** |

**„Eigene Fahrzeuge“ eines Mitglieds** sind die, deren Eigentümer seine Person ist, oder die es selbst angelegt hat.

Technik: Rang 1 Mitglied, 2 Fahrzeugwart, 3 Bearbeiter, 4 Admin; `benutzer.rolle` erhält den Wert `fahrzeugwart`. Die Rechte an Fotos und Dokumenten **eines Fahrzeugs** richten sich nach dem Recht, dieses Fahrzeug zu bearbeiten. Bestehende Konten behalten ihre Rolle.

## 4. Teil 2a – DSGVO-Löschung

**Wer:** nur Administratoren. Schaltfläche „Person endgültig löschen (DSGVO)“ auf der Personenseite.

**Vorschau** (vor dem Löschen, zeigt konkrete Anzahlen und Namen der betroffenen Fahrzeuge):

| Daten | Ergebnis |
|---|---|
| Person (Name, E-Mail, Telefon, Notiz) und ihr Benutzerkonto | gelöscht |
| **Eigene Fahrzeuge** (Eigentümer = Person) | **mitgelöscht** samt Fotos/Dokumenten (Webspace und OneDrive), Decoder, Funktionen, CVs, Wartungen, Reparaturen |
| … davon in der Vorschau angehakt „an den Verein übergeben“ | bleiben erhalten, Eigentümer wird der Verein |
| Bezüge in Vereinsdaten (angelegt von, Wartung ausgeführt von, Reparatur gemeldet von/zuständig) | Bezug entfernt, Anzeige **„ehemaliges Mitglied“** |
| Änderungsprotokoll | Einträge zum Objekt Person/Benutzer der Person entfernt; in allen übrigen Einträgen Vor-/Nachname, E-Mail, Telefon der Person durch „ehemaliges Mitglied“ ersetzt und `benutzer_id` geleert |

**Bestätigung:** Eingabe des Nachnamens. **Ablauf:** Datenbankteil in einer Transaktion (alles oder nichts), danach Dateien vom Webspace entfernen und OneDrive-Lösch-Aufträge anlegen. **Nachweis:** genau ein Protokolleintrag „Person Nr. N nach DSGVO gelöscht“ mit dem ausführenden Admin, ohne personenbezogene Daten.

**Grenzen:** Ältere Datensicherungen enthalten die Person weiterhin. Die Vorschau weist darauf hin; das README empfiehlt, Sicherungen nach 12 Monaten zu vernichten.

Die bisherige einfache Löschung (Person ohne Fahrzeuge und ohne Konto) bleibt bestehen.

## 5. Teil 2b – Anlagenliste und Wartung

### 5.1 Einfache Anlagenliste (Vorstufe zu Stufe 3)

**Anlage:** Name, Spurweite, Code `A<n>` (automatisch, fortlaufend), Kurzbeschreibung, Standort, Status (`in_ordnung`, `defekt`, `in_arbeit`), Fotos und Dokumente. Anlegen/Bearbeiten: Bearbeiter; lesen und Defekte melden: alle. Stufe 3 erweitert diese Tabelle (Module, Bauteile …); vorhandene Daten bleiben erhalten.

### 5.2 Wartungsarten

Stammliste mit Name, **gilt für** (`fahrzeug_alle`, `fahrzeug_antrieb` = Lok und Triebwagen, `fahrzeug_wagen`, `anlage`), Intervall Monate und/oder Betriebsstunden (mindestens eines), aktiv. Pflege: Fahrzeugwart und höher.

**Startbestand:**

| Wartungsart | gilt für | Intervall |
|---|---|---|
| Räder und Radschleifer reinigen | Fahrzeuge (alle) | 6 Monate oder 20 h |
| Getriebe und Lager schmieren | Lok/Triebwagen | 12 Monate oder 50 h |
| Haftreifen prüfen/tauschen | Lok/Triebwagen | 12 Monate oder 50 h |
| Motor/Kohlen prüfen | Lok/Triebwagen | 24 Monate oder 100 h |
| Kupplungen prüfen | Fahrzeuge (alle) | 12 Monate |
| Gleise reinigen | Anlagen | 3 Monate |
| Weichen und Antriebe prüfen | Anlagen | 12 Monate |
| Verkabelung und Stecker prüfen | Anlagen | 12 Monate |

### 5.3 Wartungsplan

Eine Wartungsart gilt **automatisch** für alle passenden Objekte; ausgemusterte Fahrzeuge zählen nicht. **Ausnahmen je Objekt** (Fahrzeugwart; bei Anlagen Bearbeiter): Wartungsart abschalten oder eigenes Intervall setzen.

### 5.4 Wartungsprotokoll

Datum, eine oder mehrere Wartungsarten, Betriebsstundenstand (optional), ausgeführt von (Person), Notiz, Fotos. Ein angegebener Betriebsstundenstand, der höher ist als der bisherige, aktualisiert `fahrzeug.betriebsstunden`. Eintragen: Fahrzeugwart an allen Fahrzeugen, Bearbeiter an Anlagen, Mitglieder an eigenen Fahrzeugen. Ein Wartungseintrag ändert den Fahrzeugstatus nicht.

### 5.5 Fälligkeit und Ampel

Ab der letzten Wartung dieser Art am Objekt; fällig ist, was zuerst erreicht wird (Zeit oder Stunden). 🔴 überfällig · 🟡 bald fällig (≤ 1 Monat bzw. ≤ 10 % des Stundenintervalls) **oder noch nie erfasst** · 🟢 in Ordnung.

**Anzeigen:** Seite „Wartung“ (alle Fälligkeiten, Filter Fahrzeug/Anlage, Spurweite, Verein/Mitglied, sortiert nach Dringlichkeit) · Kachel auf der Startseite · Wartungsblock mit Ampel und Verlauf auf jeder Fahrzeug- und Anlagenseite.

## 6. Teil 2c – Reparaturen, Defekt melden, Codes

### 6.1 Defekt melden (alle Mitglieder, mobil)

1. Objekt wählen: Feld „Code“, QR-Code einer Anlage oder Schaltfläche „Defekt melden“ auf Liste/Detailseite.
2. Beschreibung (Pflicht); bei Anlagen zusätzlich „Wo/Bauteil“ (Freitext, z. B. „W12 Antrieb brummt“).
3. Optional Fotos (direkt aus der Kamera) und Einstufung „fährt nicht mehr“ (= dringend) oder „fährt, aber …“ (= normal).
4. Absenden → Reparatur mit Status „gemeldet“; Bestätigung; Übersicht „Meine Meldungen“.

### 6.2 Reparatur

Objekt (Fahrzeug oder Anlage), Beschreibung, Wo/Bauteil, gemeldet von, gemeldet am, Priorität (`dringend`, `normal`, `niedrig`), zuständig (Person), geplanter Termin, Status `gemeldet → geplant → in Arbeit → wartet auf Teile → erledigt` sowie `verworfen`, Ersatzteile (Bezeichnung, Artikelnummer, Bezugsquelle, Kosten, bestellt), **Kosten gesamt** (Summe), Fotos, Notizen, beim Erledigen „Was wurde gemacht“. Bearbeiten: Fahrzeugwart (Fahrzeuge), Bearbeiter (Anlagen), Mitglieder an eigenen Fahrzeugen. **Reparaturen werden nicht gelöscht**, nur verworfen (Ausnahme: DSGVO-Löschung eigener Fahrzeuge).

### 6.3 Automatischer Status

- Offene Reparatur mit Priorität „dringend“ → Fahrzeug **„defekt“**; Status „in Arbeit“ → **„in Wartung“** (Anlage entsprechend `defekt` / `in_arbeit`). „Defekt“ hat Vorrang.
- Ist die letzte offene Reparatur erledigt oder verworfen, wird der Status auf „einsatzbereit“ bzw. `in_ordnung` zurückgesetzt – **nur**, wenn er zuletzt durch eine Reparatur gesetzt wurde. Von Hand gesetzte Status (z. B. „ausgeliehen“, „ausgemustert“) bleiben unangetastet; die App merkt sich dafür, ob der aktuelle Status automatisch gesetzt wurde.

### 6.4 Hinweise in der App (keine E-Mail)

Fahrzeugwart, Bearbeiter und Admin sehen oben „N neue Defektmeldungen“ (für die Objekte, die sie bearbeiten dürfen). Eine Meldung gilt als nicht mehr neu, sobald jemand mit diesen Rechten sie geöffnet hat oder ihr Status nicht mehr „gemeldet“ ist. Startseite: Kachel „Offene Defekte“ (dringende zuerst). Seite „Reparaturen“ mit Filtern Status, Objekt, zuständig.

### 6.5 Codes und Feld „Code“

- Fahrzeuge: `F<n>`, wobei `n` die Zahl der Inventarnummer ohne führende Nullen ist (`EMC-0012` → `F12`). Anlagen: `A<n>`.
- Feld „Code“ in der Kopfzeile jeder Seite: `F12`/`A3` (Groß-/Kleinschreibung und Leerzeichen egal) öffnet direkt. Eine **reine Zahl** wird als **Digitaladresse** gesucht: ein Treffer öffnet das Fahrzeug, mehrere zeigen eine Auswahl mit Foto, Spurweite, Bezeichnung. Alles andere wird als Suche über Bezeichnung, Baureihe und Inventarnummer behandelt.
- Kurzlink `/c/<Code>` (z. B. `/c/A3`); ohne Anmeldung zuerst Login, danach direkt zum Objekt.

### 6.6 Etiketten (Druck aus dem Browser)

Auswahl: einzeln, nach Spurweite, alle Loks, „noch nicht gedruckt“; Startposition auf dem Bogen wählbar.

- **Fahrzeug-Klebchen:** nur `F12`, fett, ca. 3 mm hoch, Raster 25,4 × 10 mm (189 je A4-Bogen); Variante für Verpackungen mit `F12` und Bezeichnung. Für Spur N ist vorgesehen, das Klebchen auf die Verpackung zu kleben; die Lok selbst wird über ihre Digitaladresse gefunden.
- **Anlagen-Etikett:** 70 × 37 mm (24 je Bogen) mit QR-Code (Kurzlink), Name und `A3`.

QR-Codes werden serverseitig als SVG erzeugt (Bibliothek per Composer, im Release enthalten).

## 7. Teil 2d – Funktionstasten aus PDF

Am Decoder kann neben CSV auch ein **PDF** hochgeladen werden. Die App liest den Text aus (Bibliothek per Composer) und erkennt Zeilen der Form `F0 Licht`, `F1 Sound an`, `F2 – Pfiff`, `F 3: Glocke` (F0–F68). Erkannte Tasten erscheinen in derselben **Vorschau wie beim CSV-Import** zum Prüfen und Übernehmen. Enthält das PDF keinen Text (Scan), meldet die App das; OCR ist nicht vorgesehen. Unbekannte Tabellenaufbauten werden teilweise erkannt, der Rest von Hand ergänzt.

## 8. Datenmodell (Überblick)

Neue Tabellen: `anlage`, `wartungsart`, `wartungsplan_ausnahme` (objekt_typ, objekt_id, wartungsart_id, aus, eigenes Intervall), `wartung` und `wartung_art` (n:m), `reparatur`, `ersatzteil`, `reparatur_gesehen` (benutzer_id, reparatur_id). Änderungen: `benutzer.rolle` um `fahrzeugwart`; `fahrzeug.status_automatisch` (bool) und entsprechend bei `anlage`; `fahrzeug.etikett_gedruckt_am`, `anlage.etikett_gedruckt_am`. Personenbezüge (`ausgefuehrt_von`, `gemeldet_von`, `zuständig`) sind `NULL`-bar mit `ON DELETE SET NULL`, angezeigt als „ehemaliges Mitglied“. Objektbezüge über `objekt_typ` + `objekt_id` (Typen `fahrzeug`, `anlage`), Fotos über die bestehende `dokument_zuordnung`.

## 9. Fehlerfälle

- Code nicht gefunden → Meldung „Kein Fahrzeug und keine Anlage mit diesem Code“, Suchergebnis als Ausweichen.
- DSGVO-Löschung scheitert in der Datenbank → nichts gelöscht, Fehlermeldung; Dateien werden erst nach erfolgreicher Transaktion entfernt.
- PDF ohne Text oder ohne erkannte Tasten → verständliche Meldung, nichts übernommen.
- Gleichzeitige Bearbeitung einer Reparatur → bestehender Schutz gegen verlorene Änderungen (Stand-Prüfung) wie bei Fahrzeugen.

## 10. Tests

PHPUnit für: Rangfolge und Rechte (inkl. Fahrzeugwart an privaten Fahrzeugen), DSGVO-Löschung (Vorschau, Übergabe an Verein, Protokollbereinigung, Transaktion, OneDrive-Aufträge), Fälligkeitsberechnung (Monate, Stunden, „zuerst erreicht“, noch nie erfasst, Ausnahmen), automatischer Status (Setzen, Vorrang, Zurücksetzen nur bei automatisch gesetztem Status), Code-Auflösung (F/A, Zahl als Adresse, mehrdeutig), PDF-Erkennung (Beispieltexte verschiedener Hersteller, PDF ohne Text), Migration auf bestehenden Daten.
