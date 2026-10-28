# Prüfliste Anbieter (Auftragsverarbeitung, Drittland, Aufbewahrung)

> Für Einkauf, Datenschutz und IT. Die Fragen sind bewusst offen formuliert: Bedingungen der
> Anbieter ändern sich. Jede Antwort mit Quelle (Vertrag, DPA, Trust Center) und Datum belegen.

## Fragen je Anbieter

| Frage | Anthropic | Deepgram | Cartesia | LiveKit Cloud (falls genutzt) |
| :--- | :--- | :--- | :--- | :--- |
| AVV / DPA nach Art. 28 DSGVO abschließbar? | | | | |
| Liste der Unterauftragsverarbeiter, Änderungsmitteilung? | | | | |
| Verarbeitung in der EU möglich (Region, Endpunkt)? | | | | |
| Zertifizierung unter dem EU-US Data Privacy Framework? | | | | |
| Standardvertragsklauseln (Modul 2/3) im DPA enthalten? | | | | |
| Werden Inhalte (Audio/Text) zum Training genutzt? Opt-out vertraglich? | | | | |
| Aufbewahrungsdauer von Eingaben/Ausgaben; Zero Data Retention verfügbar? | | | | |
| Werden Audio oder Texte in Logs gespeichert? Wie lange? | | | | |
| Sicherheitsnachweise (ISO 27001, SOC 2)? | | | | |
| Löschung auf Anforderung, Nachweis? | | | | |
| Meldung von Datenschutzverletzungen: Frist? | | | | |

## Festlegungen im Code

Was im Prototyp bereits datensparsam konfiguriert ist (`backend/tutor/agent.py`, `config.py`):

| Einstellung | Wirkung |
| :--- | :--- |
| `record=False` beim Start der Sitzung | keine Aufzeichnung über LiveKit |
| `inference.TurnDetector(version="v1-mini")` | Turn-Erkennung läuft lokal, kein Cloud-Aufruf |
| `interruption.mode = "vad"` | keine adaptive Unterbrechungserkennung über LiveKit Cloud |
| Kein Speichern von Transkripten | Transkript nur im Browser |
| `TUTOR_LLM_MODEL`, `TUTOR_STT_MODEL`, `TUTOR_TTS_MODEL` | Modelle per Umgebungsvariable austauschbar, z. B. für EU-Endpunkte |

Noch zu ergänzen, sobald die Antworten oben vorliegen:

*   EU-Endpunkte der Anbieter, falls verfügbar, als Konfiguration (Basis-URLs).
*   Für Anthropic: Zero Data Retention und gegebenenfalls Inferenz-Region klären. Der
    server-seitige Fallback bei Ablehnungen nutzt ein anderes Claude-Modell; prüfen, ob es
    unter denselben Bedingungen läuft, sonst Fallback abschalten.

## Rückfallebene ohne US-Anbieter

Falls einzelne Anbieter nicht freigegeben werden: Spracherkennung mit selbst gehostetem
`faster-whisper`, Sprachmodell über einen eigenen vLLM-Cluster, Sprachausgabe mit Piper oder
Kokoro (`PROJECT.md`, Abschnitt 6). Das erhöht Aufwand und Latenz deutlich und sollte vor
einer Entscheidung im Pilotumfang gemessen werden.
