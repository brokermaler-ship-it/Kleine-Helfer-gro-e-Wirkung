# Datenschutz-Folgenabschätzung (Art. 35 DSGVO): Pilot „Voice-AI-Tutor“

> Arbeitsentwurf zur Vorlage bei der Datenschutzbeauftragten. Bewertungen sind Vorschläge
> des Projektteams und von der Datenschutzbeauftragten zu bestätigen oder zu ändern.

| Feld | Angabe |
| :--- | :--- |
| Verantwortlicher | [Unternehmen] |
| Erstellt von | [Projektleitung] unter Beteiligung von [DSB], [IT-Sicherheit], [Betriebsrat] |
| Stand | [Datum], Systemstand: Prototyp Phase 0 (Repository, Commit [hash]) |
| Nächste Überprüfung | vor Ende des Pilots und vor jeder Ausweitung |

## 1. Schwellwertanalyse: Ist eine DSFA erforderlich?

Kriterien nach den Leitlinien der Artikel-29-Gruppe (WP 248), von der DSK übernommen:

| Kriterium | Erfüllt? | Begründung |
| :--- | :--- | :--- |
| Bewertung oder Einstufung | **teilweise** | Der Tutor schätzt pro Lernziel ein, ob die Person es „verstanden“ hat; Wissenscheck mit Punktzahl |
| Automatisierte Entscheidung mit Rechtswirkung | nein | Ergebnisse haben keine Folgen für die Person (BV § 5) |
| Systematische Überwachung | nein | keine fortlaufende Erfassung, nur einzelne Lerneinheiten |
| Sensible Daten | **möglich** | Personen könnten im Gespräch unaufgefordert Gesundheitsdaten o. Ä. äußern; Stimme wird nicht zur Identifizierung genutzt |
| Schutzbedürftige Betroffene | **ja** | Beschäftigte (Abhängigkeitsverhältnis) |
| Innovative Technologie | **ja** | Sprach-KI und großes Sprachmodell |
| Übermittlung in Drittländer | **ja** | USA (kein WP-248-Kriterium, erhöht aber das Risiko) |

**Ergebnis:** Mindestens zwei Kriterien sind erfüllt. Eine DSFA wird durchgeführt.

## 2. Systematische Beschreibung der Verarbeitung

**Zweck:** Erprobung, ob ein KI-Sprach-Tutor den Lernerfolg gegenüber dem bisherigen Format verbessert.

**Ablauf:**

1. Teilnehmende melden sich im Browser mit einer Pilot-Kennung an (Klarnamen werden technisch abgelehnt).
2. Wissenscheck „vorher“ (Auswahlfragen). Antworten und Punktzahl werden mit Kennung gespeichert.
3. Lerngespräch:
   - Mikrofon-Audio geht per WebRTC an den LiveKit-Server und von dort an Deepgram (Spracherkennung).
   - Der erkannte Text geht zusammen mit dem Lerninhalt an Anthropic (Claude).
   - Die Antwort des Tutors geht an Cartesia (Sprachausgabe) und als Audio zurück in den Browser.
   - Merksätze, Quellen und Lernziel-Status werden über WebRTC-Datenkanäle an den Browser gesendet.
4. Am Ende entsteht ein Lernprotokoll, das nur im Browser der Person angezeigt wird.
5. Wissenscheck „nachher“ und nach 1–2 Wochen „später“.
6. Auswertung: Quote richtiger Antworten je Modul und Phase, aggregiert.

**Datenkategorien, Empfänger, Fristen:** siehe `03-verarbeitungsverzeichnis.md`.

**Nicht verarbeitet:** keine Audioaufzeichnung (`record=False`), kein gespeichertes Transkript
auf eigenen Servern, keine Stimm- oder Emotionsanalyse, keine Verknüpfung mit Personal- oder
HR-Systemen.

## 3. Notwendigkeit und Verhältnismäßigkeit

| Grundsatz | Umsetzung |
| :--- | :--- |
| Zweckbindung | Nur Bewertung des Formats; Leistungs- und Verhaltenskontrolle per Betriebsvereinbarung ausgeschlossen |
| Datenminimierung | Kennung statt Name; Audio und Transkript nicht gespeichert; Lernprotokoll nur lokal; Auswertung nur aggregiert |
| Erforderlichkeit der Personenbeziehbarkeit | Die Kennung ist nötig, um vorher/nachher/später derselben Person zuzuordnen. Ohne sie ist der Lerneffekt nicht messbar. |
| Erforderlichkeit externer KI-Dienste | Für eine natürliche Gesprächsführung in Echtzeit derzeit erforderlich. Ein On-Premise-Pfad ist beschrieben (`PROJECT.md`, Abschnitt 6), für den Pilot aber unverhältnismäßig aufwendig. **[DSB bewerten]** |
| Speicherbegrenzung | Löschfristen siehe Verarbeitungsverzeichnis |
| Transparenz | Datenschutzhinweise vor Teilnahme; Hinweis auf KI-Interaktion im Browser |
| Freiwilligkeit | Teilnahme freiwillig, Abbruch jederzeit, keine Nachteile |
| Betroffenenrechte | Auskunft und Löschung über die Kennung möglich (die benannte Stelle hält die Zuordnung) |

## 4. Risiken für die Rechte und Freiheiten der Betroffenen

Eintrittswahrscheinlichkeit (W) und Schwere (S) jeweils: gering / mittel / hoch.
Bewertet wird **vor** den zusätzlichen Maßnahmen aus Abschnitt 5.

| Nr. | Risiko | W | S | Bewertung |
| :--- | :--- | :--- | :--- | :--- |
| R1 | Zweckentfremdung: Ergebnisse oder Lernziel-Status werden zur Beurteilung einzelner Personen genutzt | mittel | hoch | **hoch** |
| R2 | Re-Identifizierung: Projektteam oder Vorgesetzte ordnen Kennungen Personen zu | mittel | mittel | mittel |
| R3 | Zugriff von US-Behörden auf Audio und Gesprächstexte bei US-Anbietern | gering | mittel | mittel |
| R4 | Anbieter speichern Audio oder Texte länger als nötig oder nutzen sie zum Training | mittel | mittel | mittel |
| R5 | Personen äußern unaufgefordert sensible Informationen (Gesundheit, private Umstände), die an Anbieter gehen | mittel | mittel | mittel |
| R6 | Transkripte landen in Server-Logs (der Agent protokolliert im Entwicklungsmodus Transkripte auf Debug-Ebene) | hoch, wenn Entwicklungsmodus genutzt wird | mittel | **hoch** |
| R7 | Unbefugter Zugriff auf Server oder Endpunkte (Auswertung, Token-Ausgabe sind im Prototyp ohne Anmeldung erreichbar) | mittel | mittel | **hoch** im offenen Netz, mittel im internen Netz |
| R8 | Faktisch eingeschränkte Freiwilligkeit (Gruppendruck, Erwartung der Führungskraft) | mittel | mittel | mittel |
| R9 | Falsche Inhalte des Tutors führen zu falschem Verhalten (z. B. falscher Meldeweg) | gering | mittel | mittel (kein Datenschutzrisiko im engeren Sinn, aber Schutzinteresse der Beschäftigten) |
| R10 | Mithören im Großraumbüro | mittel | gering | gering |

## 5. Abhilfemaßnahmen

| Nr. | Maßnahme | Gegen | Status |
| :--- | :--- | :--- | :--- |
| M1 | Betriebsvereinbarung mit Zweckbindung, Verbot der Leistungskontrolle und Verwertungsverbot | R1, R8 | Entwurf liegt vor (`02-…`) |
| M2 | Auswertung nur aggregiert ab [5] Personen; nur `/api/pilot/summary` statt Rohdaten für das Projektteam | R1, R2 | Auswertung aggregiert umgesetzt; Mindestgruppe **offen** |
| M3 | Zuordnungsliste bei einer Stelle außerhalb der Führungslinie, getrennt gespeichert | R2 | organisatorisch **offen** |
| M4 | Server verweigert Kennungen, die wie Namen aussehen (nur `A–Z a–z 0–9 _ -`, 3–32 Zeichen) | R2 | umgesetzt |
| M5 | Entscheidung E3: Lernziel-Status ohne Kennung speichern oder nur aggregiert auswerten | R1 | **offen** |
| M6 | AVV mit allen Anbietern; Ausschluss von Training; Zero Data Retention bzw. kürzestmögliche Aufbewahrung; EU-Region, wo verfügbar | R3, R4 | **offen**, siehe `06-…` |
| M7 | Garantien für die Drittlandübermittlung (DPF-Zertifizierung bzw. Standardvertragsklauseln, Transfer Impact Assessment) | R3 | **offen** |
| M8 | Hinweis in Datenschutzhinweisen und Einführung: keine privaten oder gesundheitlichen Details nennen | R5 | Text liegt vor (`05-…`) |
| M9 | System-Prompt hält das Gespräch beim Lernthema | R5 | umgesetzt (`backend/tutor/prompts.py`) |
| M10 | Agent im Pilot nur im Produktivmodus (`python -m tutor.agent start`, Log-Level INFO), keine Debug-Logs; Log-Rotation [14 Tage] | R6 | **vor Pilot umzusetzen** |
| M11 | Betrieb nur im internen Netz oder hinter Anmeldung (Reverse Proxy mit SSO/Basic Auth); TLS für Web und LiveKit | R7 | **vor Pilot umzusetzen** |
| M12 | Zugriff auf `data/` nur für das Projektteam (Dateirechte), keine Kopien auf private Geräte | R1, R7 | **vor Pilot umzusetzen** |
| M13 | Dokumentierte Löschung nach Fristablauf (Skript oder Checkliste) | alle | **offen** |
| M14 | Keine Audioaufzeichnung, kein serverseitiges Transkript, Lernprotokoll nur im Browser | R1, R4 | umgesetzt |
| M15 | Turn-Erkennung und VAD lokal; Cloud-Unterbrechungserkennung abgeschaltet | R4 | umgesetzt |
| M16 | Inhalte nur aus fachlich freigegebenen Arbeitsanweisungen; Tutor sagt ehrlich, wenn das Material etwas nicht abdeckt; Evaluations-Set vor Pilot | R9 | Prompt umgesetzt; Freigabe und Evaluation **offen** |
| M17 | Headsets und ruhige Räume anbieten | R10 | organisatorisch **offen** |
| M18 | Hinweis „KI-Tutor“ und Einweisung (Art. 50, Art. 4 KI-VO) | Transparenz | Hinweis im Browser umgesetzt; Einweisung **offen** |

## 6. Technische und organisatorische Maßnahmen (Übersicht)

*   **Pseudonymisierung:** Pilot-Kennungen, getrennte Zuordnungsliste (M3, M4)
*   **Vertraulichkeit:** internes Netz bzw. Anmeldung, TLS, Dateirechte (M11, M12)
*   **Integrität:** Versionierter Code und Lerninhalte im Repository; Modulstand wird mit jedem Ergebnis gespeichert
*   **Verfügbarkeit:** für den Pilot unkritisch; keine Datensicherung der Rohdaten über die Löschfrist hinaus
*   **Löschung:** Fristen und Nachweis (M13)
*   **Auftragsverarbeitung:** AVV und Prüfung der Anbieter (M6, M7)

## 7. Restrisiko und Ergebnis

Nach Umsetzung der Maßnahmen M1–M18 schätzt das Projektteam das Restrisiko als **gering bis
mittel** ein. Am höchsten bleibt die Drittlandübermittlung (R3). Sie ist mit den Garantien
aus M7 vertretbar, solange keine besonderen Kategorien personenbezogener Daten gezielt
verarbeitet werden. **[Bewertung durch DSB]**

Eine Konsultation der Aufsichtsbehörde nach Art. 36 DSGVO ist nach Einschätzung des
Projektteams nicht erforderlich, wenn die Maßnahmen umgesetzt sind. **[DSB bestätigen]**

## 8. Einschätzung nach KI-Verordnung (EU) 2024/1689

*   **Transparenzpflicht (Art. 50):** Teilnehmende werden darauf hingewiesen, dass sie mit
    einem KI-System sprechen. Umgesetzt im Browser („KI-Tutor“) und in den Datenschutzhinweisen.
*   **KI-Kompetenz (Art. 4):** Einweisung der Teilnehmenden und des Projektteams vorsehen.
*   **Hochrisiko (Anhang III Nr. 3 und 4):** Erfasst sind u. a. KI-Systeme zur Bewertung von
    Lernergebnissen und Systeme, die Entscheidungen im Beschäftigungsverhältnis beeinflussen.
    Der Pilot nutzt die Lernziel-Einschätzung des Tutors nicht für Entscheidungen über
    Personen; die Wissenschecks werden ohne KI ausgewertet. Nach Einschätzung des Projektteams
    liegt daher **kein Hochrisiko-Einsatz** vor. Diese Einschätzung ist neu zu prüfen, sobald
    Ergebnisse über Zugang zu Schulungen, Zertifizierungen oder Beförderungen mitentscheiden
    (z. B. bei einer LMS-Anbindung mit Bestanden/Nicht-bestanden). **[Recht/DSB bestätigen]**

## 9. Freigabe

| Rolle | Name | Datum | Ergebnis |
| :--- | :--- | :--- | :--- |
| Datenschutzbeauftragte/r | | | |
| Projektleitung | | | |
| IT-Sicherheit | | | |
| Kenntnisnahme Betriebsrat | | | |
