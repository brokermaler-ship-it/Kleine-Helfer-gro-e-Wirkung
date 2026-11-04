# Unterrichtung des Betriebsrats: Pilot „Voice-AI-Tutor“

> Entwurf. Vor Versand durch HR/Rechtsabteilung prüfen und anpassen.

**An:** Betriebsrat der [Unternehmen], z. Hd. [Vorsitz]
**Von:** [Projektleitung], [Abteilung]
**Datum:** [Datum]
**Betreff:** Unterrichtung über die geplante Erprobung eines KI-gestützten Sprach-Tutors für interne Schulungen

Sehr geehrte Damen und Herren,

wir möchten Sie frühzeitig über ein Vorhaben informieren, bevor Verträge geschlossen oder
Beschäftigte einbezogen werden. Wir verstehen dieses Schreiben als Unterrichtung nach
§ 80 Abs. 2 und § 90 Abs. 1 Nr. 3 BetrVG. Aus unserer Sicht berührt das Vorhaben außerdem
Ihre Mitbestimmungsrechte nach § 87 Abs. 1 Nr. 6 BetrVG (technische Einrichtung, die
objektiv geeignet ist, Verhalten oder Leistung zu erfassen) und nach § 98 BetrVG
(Durchführung betrieblicher Bildungsmaßnahmen). Wir möchten den Pilot deshalb erst nach
Abschluss einer Vereinbarung mit Ihnen starten. Einen Entwurf fügen wir bei.

Da es sich um ein KI-System handelt, gilt die Hinzuziehung eines Sachverständigen nach
§ 80 Abs. 3 Satz 2 BetrVG als erforderlich. Wir unterstützen das.

## 1. Worum es geht

Wir wollen erproben, ob Beschäftigte Inhalte von Arbeitsanweisungen und Richtlinien besser
behalten, wenn sie sie im Gespräch mit einem KI-Tutor erarbeiten statt über das bisherige
E-Learning. Der Tutor stellt Fragen, gibt Hinweise und erklärt; die Person antwortet per
Sprache im Browser. Auf dem Bildschirm erscheinen Merksätze, die zugrunde liegende Textstelle
und der Fortschritt bei den Lernzielen.

**Ziel des Pilots ist die Bewertung des Lernformats, nicht die Bewertung von Personen.**

## 2. Umfang des Pilots

| Punkt | Planung |
| :--- | :--- |
| Teilnehmende | 20–30 Beschäftigte, freiwillig, aus [Bereiche]; zusätzlich eine Vergleichsgruppe mit dem bisherigen E-Learning |
| Inhalte | 1–2 bestehende Arbeitsanweisungen, z. B. [Thema], fachlich freigegeben |
| Dauer | 4 Wochen; je Person eine Lerneinheit von ca. 10–15 Minuten und drei kurze Wissenschecks |
| Arbeitszeit | Teilnahme gilt als Arbeitszeit |
| Ort | Am Arbeitsplatz oder in einem ruhigen Raum, mit Headset |

## 3. Ablauf für Teilnehmende

1. Die Person erhält von [Pilotleitung] eine Kennung (z. B. `P-017`) statt ihres Namens.
2. Kurzer Wissenscheck mit Auswahlfragen („vorher“).
3. Lerngespräch mit dem Tutor (ca. 10 Minuten).
4. Derselbe Wissenscheck („nachher“), mit Auflösung.
5. Nach 1–2 Wochen noch einmal der Wissenscheck („später“).
6. Kurzer, anonymer Fragebogen zur Erfahrung.

## 4. Technik in Kürze

| Baustein | Anbieter | Was dort verarbeitet wird |
| :--- | :--- | :--- |
| Browser-Anwendung und Server | eigener Betrieb ([Hosting]) | Kennung, Wissenscheck-Antworten, Sitzungsdaten |
| Audio-Übertragung (LiveKit) | eigener Betrieb oder LiveKit Cloud | Sprach-Audio in Echtzeit, nicht gespeichert |
| Spracherkennung | Deepgram (USA) | Sprach-Audio → Text, in Echtzeit |
| Sprachmodell | Anthropic, Claude (USA) | Gesprächstext und Lerninhalt |
| Sprachausgabe | Cartesia (USA) | Antworttext des Tutors → Audio |

Mit allen externen Anbietern werden Auftragsverarbeitungsverträge geschlossen. Die
Datenschutzbeauftragte führt eine Datenschutz-Folgenabschätzung durch.

## 5. Welche Daten entstehen und was mit ihnen geschieht

| Daten | Gespeichert? | Wer sieht sie? | Löschung |
| :--- | :--- | :--- | :--- |
| Sprach-Audio | **Nein**, nur Echtzeitverarbeitung | niemand | sofort nach Verarbeitung (bei Anbietern gemäß AVV) |
| Gesprächstext (Transkript) | **Nein**, nur im Browser der Person sichtbar | die Person selbst | beim Schließen des Browsers |
| Lernprotokoll | **Nein**, nur im Browser; Person kann es selbst speichern | die Person selbst | – |
| Wissenscheck-Antworten und Punktzahl | Ja, mit Kennung | Projektteam, nur aggregiert ausgewertet | [3 Monate] nach Pilotende |
| Sitzungsdaten (Beginn/Ende, abgeschlossen ja/nein, Lernziel-Status laut Tutor, technische Antwortzeiten) | Ja, mit Kennung | Projektteam, nur aggregiert ausgewertet | [3 Monate] nach Pilotende |
| Zuordnung Kennung ↔ Name | Ja, getrennt | nur [benannte Person, z. B. Personalentwicklung] | [1 Monat] nach dem letzten Wissenscheck |

## 6. Was ausdrücklich nicht geschieht

*   Keine Aufzeichnung von Audio, kein gespeichertes Gesprächstranskript.
*   Keine Weitergabe individueller Ergebnisse an Vorgesetzte oder an die Personalakte.
*   Keine Verwendung der Daten für Beurteilungen, arbeitsrechtliche Maßnahmen oder
    Entscheidungen über Beförderung oder Weiterbildungszugang.
*   Keine Auswertung einzelner Personen; Auswertungen nur für Gruppen ab [5] Personen.
*   Keine Nachteile bei Nichtteilnahme oder Abbruch.
*   Keine Nutzung der Daten durch die Anbieter zum Training ihrer Modelle (vertraglich auszuschließen).

## 7. Weiteres Vorgehen

Wir schlagen ein erstes Gespräch in den nächsten [2] Wochen vor. Dabei können wir den
Prototyp live vorführen. Beigefügt:

*   Entwurf einer Betriebsvereinbarung für den Pilot
*   Projekt-Spezifikation (`PROJECT.md`, Abschnitte 1, 5 und 6)
*   nach Fertigstellung: Datenschutz-Folgenabschätzung und Datenschutzhinweise

Über eine spätere Ausweitung nach dem Pilot (z. B. Anmeldung über Firmenkonto, Anbindung an
das Lernmanagementsystem) würden wir Sie gesondert und rechtzeitig unterrichten.

Mit freundlichen Grüßen
[Name]
