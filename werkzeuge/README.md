# Werkzeuge für Releases

| Datei | Zweck |
|---|---|
| `../bin/release.sh` | Baut `dist/emc78-verwaltung-<VERSION>.zip` (Installationspaket, Produktions-vendor, ohne Tests/Doku). |
| `anleitung/erzeuge_pdf.py` | Erzeugt `docs/EMC78-Lokschuppen-Integrationsanleitung-STRATO.pdf` (reportlab). Version/Datum im Skript anpassen (Suche „Version 0.“). |
| `anleitung/emc78-logo.png` | Vereinslogo für das Deckblatt. |
| `anleitung/config-testumgebung.php` | config-Vorlage für test.emc78.de, nur Platzhalter in `<…>` – niemals echte Zugangsdaten eintragen und committen. |
| `anleitung/LIES-MICH-vorlage.txt` | LIES-MICH für das Paket; Abschnitt „Neu in …“ je Release anpassen. |
| `paket-thomas.sh` | Schnürt `dist/EMC78-Lokschuppen-Paket-STRATO-<VERSION>.zip` (PDF, Installationspaket, config-Vorlage, LIES-MICH). |

Ablauf je Release: `VERSION` und README-Versionsabsatz anpassen → Tests grün → `bin/release.sh` → PDF-Skript anpassen und ausführen → LIES-MICH-Vorlage anpassen → `werkzeuge/paket-thomas.sh` → Paket an den Administrator, dazu eine kurze WhatsApp-Nachricht (Sicherung ziehen, ZIP drüberladen ohne config.php/storage zu löschen, Verwaltung › Update).

Unter Windows: Git Bash (bash, zip ggf. nachinstallieren oder 7-Zip) und Python 3 mit `pip install reportlab`.
