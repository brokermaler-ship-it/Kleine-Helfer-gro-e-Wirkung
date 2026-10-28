# Verzeichnis von Verarbeitungstätigkeiten (Art. 30 Abs. 1 DSGVO)

> Entwurf für den Eintrag im Verarbeitungsverzeichnis. Von der Datenschutzbeauftragten zu prüfen.

| Feld | Angabe |
| :--- | :--- |
| **Bezeichnung** | Pilot „Voice-AI-Tutor“: Erprobung eines KI-gestützten Sprach-Tutors für interne Schulungen |
| **Verantwortlicher** | [Unternehmen, Anschrift, Vertretung] |
| **Datenschutzbeauftragte/r** | [Name, Kontakt] |
| **Fachlich verantwortlich** | [Projektleitung, Abteilung] |
| **Zwecke** | Bewertung, ob das Lernformat den Lernerfolg gegenüber dem bisherigen Format verbessert; Durchführung der Lerneinheit; technische Qualitätsmessung (Antwortzeiten) |
| **Rechtsgrundlage** | Betriebsvereinbarung (Art. 88 DSGVO i. V. m. § 26 Abs. 4 BDSG) **[offen]**; ergänzend Art. 6 Abs. 1 lit. f DSGVO |
| **Betroffene** | Teilnehmende Beschäftigte (Pilot- und Vergleichsgruppe) |
| **Empfänger** | Projektteam (nur aggregiert); Auftragsverarbeiter siehe unten |
| **Drittlandübermittlung** | USA (Anthropic, Deepgram, Cartesia; ggf. LiveKit). Garantien: **[EU-US Data Privacy Framework / Standardvertragsklauseln je Anbieter prüfen, siehe `06-anbieterpruefung.md`]** |
| **Löschfristen** | siehe Tabelle unten |
| **TOM** | siehe `04-dsfa.md`, Abschnitt 6 |

## Datenkategorien

Die Angaben entsprechen dem Code-Stand (`backend/tutor/server.py`, `backend/tutor/agent.py`).

| Datenkategorie | Inhalt | Wo | Speicherung | Löschfrist |
| :--- | :--- | :--- | :--- | :--- |
| Pilot-Kennung | z. B. `P-017`; Klarnamen werden vom Server abgelehnt | Server | ja | mit den Ergebnisdaten |
| Zuordnungsliste | Kennung ↔ Name, E-Mail | getrennt, bei [benannter Stelle] | ja | [1 Monat] nach letztem Wissenscheck |
| Wissenscheck | Zeitpunkt, Kennung, Modul und Modulstand, Phase, gewählte Antworten, Punktzahl | `data/quiz_results.jsonl` | ja | [3 Monate] nach Pilotende |
| Sitzungsdaten | Sitzungs-ID, Kennung, Modul, Beginn/Ende, abgeschlossen ja/nein, Lernziel-Status laut Tutor (`verstanden`/`unsicher`), Antwortzeiten je Gesprächswechsel | `data/sessions.jsonl` | ja | [3 Monate] nach Pilotende |
| Sprach-Audio | Stimme der Person während des Gesprächs | LiveKit, Deepgram | nein (Echtzeit) | sofort; bei Anbietern gemäß AVV |
| Gesprächstext | Transkript der Äußerungen und Antworten des Tutors | Anthropic (Verarbeitung), Cartesia (nur Tutor-Antworten), Browser | nicht auf dem eigenen Server | bei Anbietern gemäß AVV |
| Lernprotokoll | Zusammenfassung, Lernziel-Status, Merksätze | nur Browser der Person | nein | – |
| Technische Verbindungsdaten | IP-Adresse, Browser, Raumname, Teilnehmer-ID mit Kennung | LiveKit, Server-Logs | Logs | [14 Tage] |

## Auftragsverarbeiter

| Anbieter | Leistung | Datenkategorien | Sitz / Verarbeitungsort |
| :--- | :--- | :--- | :--- |
| Anthropic | Sprachmodell (Claude) | Gesprächstext, Lerninhalt | USA **[EU-Region prüfen]** |
| Deepgram | Spracherkennung | Sprach-Audio | USA **[EU-Region prüfen]** |
| Cartesia | Sprachausgabe | Antworttexte des Tutors | USA **[prüfen]** |
| LiveKit Cloud (nur falls nicht selbst betrieben) | Audio-Übertragung | Sprach-Audio, Verbindungsdaten | **[Region wählbar, prüfen]** |
| [Hoster] | Server-Betrieb | alle gespeicherten Daten | [Ort] |
