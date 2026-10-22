# Übergabe: Stand 22.10.2026 (Version 0.8.3)

Diese Notiz ist für die Weiterarbeit in einer neuen Claude-Sitzung auf dem PC des Nutzers (mit Zugriff auf das Vereins-OneDrive). Regeln und Technik: `CLAUDE.md`.

## Erledigt
| Stufe | Inhalt | Version |
|---|---|---|
| 1 / 1b | Grundgerüst, Fahrzeugkatalog, Decoder/Funktionen, OneDrive-Ablage | 0.1–0.3.1 |
| 2a | Rolle Fahrzeugwart, DSGVO-Löschung, Abstellgleis | 0.4.0 |
| 2b | Kennwort vergessen (eigener SMTP-Client), einfache Anlagenliste, Wartung mit Ampel | 0.8.0 |
| 2c | Reparaturen, Defekt melden, Codes (F/A/M/B) und Etiketten | 0.8.0 |
| 2d | Funktionstasten aus Decoder-PDF | 0.8.0 |
| 2e | Magazin, Modellbahnbörse mit Kasse, Abrechnung, öffentlichem Bereich (aus) | 0.8.0 |
| – | Fahrzeug-CSV-Import mit Kennzeichen „ungeprüft“ (Migration 020) | 0.8.1 |
| – | „Zugang anlegen“: neue Person direkt im Formular | 0.8.2 |
| – | manifest.webmanifest mit Dateityp und id, Sicherheits-Header in public/.htaccess | 0.8.3 |

Spezifikationen: `docs/superpowers/specs/`, Pläne: `docs/superpowers/plans/`. Hauptspezifikation: `docs/superpowers/specs/2026-10-18-emc78-fahrzeug-anlagen-verwaltung-design.md`.

## Ausgeliefert
- Paket 0.8.3 an den STRATO-Administrator übergeben bzw. zur Übergabe bereit (ersetzt 0.8.1/0.8.2). Live läuft mindestens 0.8.0.
- Nach dem Update auf 0.8.3 importiert der Nutzer selbst die Fahrzeugliste (CSV, 146 Zeilen → 139 Fahrzeuge, 7 Zugverbände übersprungen). Die CSV liegt beim Nutzer, nicht im Repo.

## Arbeitsweise bisher
- Pro Stufe: Brainstorming → Spezifikation (vom Nutzer freigegeben) → Plan → Umsetzung in Aufgaben mit Prüfung jeder Aufgabe und Endprüfung → Paket.
- Arbeitsnotizen je Stufe lagen in `.superpowers/sdd/<stufe>/` (nicht im Repo). Die Ergebnisse stecken in Code, Tests und Spezifikationen.

## Nächste Phase: Stufe 3 (Anlagen) – Brainstorming läuft
Laut Hauptspezifikation: Anlagen-Dossier, Module, Bauteile, Zubehöradressen, Anordnungen, Wartung/Reparatur an Modulen/Bauteilen, QR für Module; Plan-Editor (4.10) später.

Bisher geklärt:
- **Kein WinTrack-Import.** Im OneDrive gibt es keine .wtk/.wtc-Dateien, nur exportierte Bilder. WinTrack-Pläne dienen als Plan-Hintergrund (Bild); Bauteile werden in der App erfasst.
- **Module brauchen eine feste eigene Bezeichnung/Code** (QR am Modul). Die „Teil“-Nummer in WinTrack-Plänen hängt von der Aufstellung ab und ist nur die Position in einer Anordnung.
- **Nicht alle Module sind Rechtecke** (Kehrschleifen-Köpfe, Trapez-Eckmodule). Anordnungen deshalb über Übergänge mit freiem Winkel und Maßen in cm, nicht über ein starres 90°-Raster.
- **Übergänge** meist zweigleisig, teils eingleisig – beim Zusammenstecken prüfbar.
- Modulzustand braucht auch „im Bau“ (z. B. geplante Brückenmodule).
- Zusätzliche Dossier-Felder aus den Vereinsbeschreibungen: Bahnhofstyp, Bauzeit, Vorstellungstext; Beschreibungs-PDF als Dokument an der Anlage.
- Vorhandene Tabelle `anlage` (Migration 011, Code A<n>) wird erweitert, nicht ersetzt.

Anlagenkatalog (aus den Vereinsbeschreibungen im OneDrive):
| Anlage | Spur | Größe | Epoche | Steuerung | Aufbau |
|---|---|---|---|---|---|
| Schnellfahranlage | H0 | ca. 22 × 1,5 m gerade, 16,4 + 9,6 m über Eck | IV–VI | DCC, TrainController 9 Gold | gerade / über Eck; Module 1–10, B1–B3, A1, Kurve 1/2, 2 Wendeschleifen |
| Südharztrasse | TT | 6,6 bis 18,6 × 1,0 m | IV–V | DCC, OpenDCC, TrainController | I-Form / U-Form (WinTrack-Bilder 2,90×6,80, 3,00×8,00, 11,40×1,00, 12,60×1,00) |
| Hockeheeme | TT | 3,6 × 1,3 m | III–IV | DCC, DR5000, TrainController | fest (2 Rahmen) |
| Steigemann | H0 | 3,6 × 0,5 m | III–IV | DCC, Z21 | nur gerade |
| Stadtfeste | H0 | 1,8 × 1,1 m | III–V | analog, Faller Car-System | variabel |
Weitere (nur Kurzangaben 2022): Zahnradbahn, Wismut, Halloween, Überlandstraßenbahn, Wolkenstein.

Nächster Schritt:
1. `OneDrive\EMC78\Fahrzeugdatenbank\Anlagen-Uebersicht\Anlagen-Uebersicht.md` lesen (Modul-Angaben je Anlage).
2. Zuordnung der WinTrack-Bilder zur Südharztrasse beim Nutzer bestätigen lassen.
3. Aufteilung von Stufe 3 vorschlagen (etwa 3a Dossier/Module/Bauteile/Zubehöradressen/QR, 3b Anordnungen, 3c Plan-Editor), dann Spezifikation.

## Offene Kleinigkeiten (nicht blockierend)
- Migrationen 016–020 einmal auf MySQL 8 (STRATO) beobachten.
- DSGVO-Abgleich von Börsenanfragen: Telefonnummern +49 vs. 0 werden nicht gleichgesetzt.
- Fotos aus Börsenanfragen, die ins Magazin übernommen und danach per DSGVO gelöscht wurden, werden nicht für OneDrive vorgemerkt.
- Optional: Rückgängig-Funktion (Admin) für eine Übergabe ans Magazin.
- DSGVO-Bereinigung der Fahrzeugnotizen findet Namen nur in der Schreibweise einer angelegten Person.
- Android-Hinweis „für ältere Android-Version entwickelt“: vermutlich die von Samsung Internet erzeugte Web-App; Empfehlung an Nutzer: über Chrome „Zum Startbildschirm hinzufügen“. Nach 0.8.3 erneut prüfen.

## Einrichtung auf dem PC (für Tests)
- Repository klonen: `git clone https://github.com/brokermaler-ship-it/Kleine-Helfer-gro-e-Wirkung` und Branch `ccr-6e643ff9-9ep3ig` auschecken (dort liegt der aktuelle Stand).
- PHP 8.2+ mit pdo_mysql, mbstring, gd, iconv, zlib, zip; Composer; MariaDB oder MySQL mit Datenbank `emc_test`.
- `composer install --prefer-dist`, dann `TEST_DB_PASSWORT=<pw> vendor/bin/phpunit` (≈ 1338 Tests, alle grün).
- Für Pakete: Git Bash mit zip, Python 3 mit reportlab (`werkzeuge/README.md`).
