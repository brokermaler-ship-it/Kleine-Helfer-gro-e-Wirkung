# Voice-AI-Tutor – Prototyp

Erster lauffähiger Prototyp des internen Sprach-Tutors aus [`PROJECT.md`](PROJECT.md).
Er soll eine Frage beantworten, bevor die volle Plattform gebaut wird:
**Lernen Mitarbeitende mit dem Sprach-Tutor messbar besser als mit dem bisherigen Format?**

Dafür enthält er genau ein Lernmodul, den Sprach-Tutor und einen Wissenscheck vor und
nach der Lerneinheit (optional noch einmal ein bis zwei Wochen später).

## Was der Prototyp anders macht als die Spec

| Thema | Spec | Prototyp | Grund |
| :--- | :--- | :--- | :--- |
| Wissensabruf | RAG mit Qdrant | Ganzes Modul im Prompt (mit Prompt-Caching) | Keine Suchlatenz, das Modell sieht immer die vollständige SOP |
| Turn-Erkennung | VAD, 0,35 s Stille | Lokaler Turn-Detector + dynamische Wartezeit 0,5–5 s | Nachdenkpausen nach Tutor-Fragen sollen nicht unterbrochen werden |
| LLM | GPT-4o-mini / Claude 3.5 Sonnet | Claude Sonnet 5.5, Effort `low` | Aktuelles Modell, niedrige Effort-Stufe für kurze Antwortzeiten |
| Erfolgsmessung | Abschlussquote | Wissenscheck vorher/nachher/später + Latenz pro Antwort | Misst Lernerfolg statt nur Nutzung |
| Merkhilfe | – | Merksätze, Quelle und Lernziel-Fortschritt auf dem Bildschirm | Gehörtes allein behält man schlecht |

Claude-Anfragen nutzen den server-seitigen Fallback (`fallbacks: "default"`): Lehnt das
Modell eine Anfrage ab, beantwortet die API sie automatisch mit einem Ausweichmodell.

## Aufbau

```text
content/modules/          Lernmodule als YAML (Lernziele, SOP-Text, Wissenscheck)
backend/tutor/
  modules.py              Module laden und validieren
  prompts.py              System-Prompt (sokratisch, nur Fakten aus dem Modul)
  tools.py                Tutor-Werkzeuge und Lernprotokoll, unabhängig von LiveKit
  agent.py                LiveKit-Sprach-Agent (Deepgram → Claude → Cartesia)
  textmode.py             Derselbe Tutor im Terminal, nur mit Anthropic-Key
  server.py               Web-Server: Browser-Client, Raum-Token, Wissenscheck, Auswertung
frontend/                 Browser-Client (ohne Build-Schritt)
docker-compose.yml        Lokaler LiveKit-Server
```

Data-Channel-Topics vom Agenten zum Browser: `agent_state`, `agent_key_point`,
`agent_sources`, `agent_progress`, `session_protocol`.

## Einrichten

Voraussetzungen: Python 3.11+, Docker, API-Keys für Anthropic, Deepgram und Cartesia.

```bash
cp .env.example .env            # Keys eintragen, CARTESIA_VOICE_ID = deutsche Stimme
python -m venv .venv && source .venv/bin/activate
pip install -e "backend[dev]"
set -a && source .env && set +a

docker compose up -d livekit                  # LiveKit-Server (devkey/secret)
python -m tutor.agent download-files          # einmalig: VAD- und Turn-Detector-Modelle
python -m tutor.agent dev                     # Terminal 1: Sprach-Agent
uvicorn tutor.server:app --port 8000          # Terminal 2: Web-Server
```

Dann <http://localhost:8000> im Browser öffnen (Chrome oder Edge, Mikrofon erlauben).

Nur die Didaktik ausprobieren, ohne Sprache und LiveKit:

```bash
export ANTHROPIC_API_KEY=...
python -m tutor.textmode phishing-melden
```

Tests: `cd backend && pytest`

### Windows ohne Docker, Agent in LiveKit Cloud

So läuft der erste Test mit echten Sprachdiensten (Oktober 2026). Auf Firmenrechnern kann die
Windows-Anwendungssteuerung die Audio-Bibliothek PyAV blockieren; der Sprach-Agent läuft dann
nicht lokal, sondern in LiveKit Cloud (Region `eu-central`). Lokal läuft nur der Web-Server.

1. `start-textmode.ps1` ausführen: legt beim ersten Start `.env` aus `.env.example` an und öffnet
   sie zum Eintragen der Keys. Danach startet der Tutor im Textmodus.
2. In LiveKit Cloud ein Projekt anlegen und `LIVEKIT_URL` (`wss://…`), `LIVEKIT_API_KEY` und
   `LIVEKIT_API_SECRET` in `.env` eintragen.
3. `deploy-cloud.ps1` ausführen: installiert die LiveKit-CLI, baut den Agenten mit
   `deploy/livekit-cloud/Dockerfile` und lädt ihn mit den Keys aus `.env` als Secrets hoch
   (`LIVEKIT_*` setzt LiveKit Cloud selbst). Später erneut ausführen, um eine neue Version hochzuladen.
4. `start-voice.ps1` ausführen: startet den Web-Server und öffnet den Browser. Ohne `wss://`-URL
   startet es stattdessen alles lokal (LiveKit-Server als Windows-Programm, Agent, Web-Server).

Hört der Tutor nicht zu, im Browser das richtige Mikrofon wählen (Mikrofon-Symbol in der
Adresszeile). Ob Sprache ankommt, steht im Agent-Log (`lk agent logs`): `user state` und
`user transcript final` mit der Zeichenzahl, ohne Inhalt.

> Hinweis: Hinter einem HTTP-Proxy ignoriert der Agent beim Verbindungsaufbau zu LiveKit
> die `NO_PROXY`-Liste. Für einen lokalen LiveKit-Server dann `HTTPS_PROXY` für den
> Agent-Prozess entfernen.

## Ablauf im Pilot

1. Die Pilotleitung vergibt pseudonyme Kennungen (z. B. `P-017`). Namen werden abgelehnt.
2. Teilnehmende machen den Wissenscheck **vorher**, dann die Lerneinheit, dann den
   Wissenscheck **nachher** mit Auflösung.
3. Ein bis zwei Wochen später: Startseite → „Nur Wissenscheck (Wiederholung)“.
4. Auswertung: `GET /api/pilot/summary` liefert je Modul und Phase die Zahl der
   Teilnehmenden und die Quote richtiger Antworten (letzter Versuch pro Person).

Gespeichert wird in `data/` (nicht im Repository):

- `quiz_results.jsonl`: Antworten und Punktzahl pro Kennung und Phase
- `sessions.jsonl`: pro Sitzung Modulstand, bearbeitete Lernziele und Latenzen pro
  Gesprächswechsel (`e2e_latency`, `end_of_turn_delay`, `llm_node_ttft`, `tts_node_ttfb`)

Es gibt keine Audioaufzeichnung und kein Gesprächstranskript auf dem Server. Das
Lernprotokoll sieht nur die lernende Person; sie kann es als Markdown speichern.

## Eigenes Modul anlegen

Eine YAML-Datei nach dem Muster von `content/modules/phishing-melden.yaml` anlegen. Das
Beispielmodul ist **erfundener Inhalt** und muss vor einem echten Pilot durch eine
freigegebene SOP ersetzt werden. Beim Laden wird geprüft, dass jede Quizfrage auf ein
Lernziel verweist und einen gültigen Lösungsindex hat.

## Stand und Grenzen

Geprüft:

- Unit- und API-Tests (`pytest`)
- Durchstich mit lokalem LiveKit-Server und Browser mit simuliertem Mikrofon: Token,
  Agent-Zuteilung mit Modul-Metadaten, Raumbeitritt, Status-Events im Browser,
  Sitzungsdatensatz
- Browser-Ablauf Start → Wissenscheck → Gespräch → Lernprotokoll → Wissenscheck mit
  simulierten Agent-Events

Noch **nicht** geprüft, weil in der Entwicklungsumgebung keine API-Keys verfügbar waren:
ein echtes Gespräch mit Deepgram, Claude und Cartesia. Der erste Test mit echten Keys
sollte vor allem diese Punkte ansehen:

- Wartet der Tutor nach seinen Fragen lange genug? Sonst `TUTOR_ENDPOINTING_*` anpassen.
- Klingt die gewählte Cartesia-Stimme auf Deutsch natürlich?
- Liegt `e2e_latency` in `sessions.jsonl` im Median unter 1.000 ms (inklusive Turn-Erkennung, siehe PROJECT.md Abschnitt 7)?
- Hält sich der Tutor an das Material und ruft er die Werkzeuge sinnvoll auf?

Bewusst noch nicht enthalten: SSO, Rollenrechte, mehrere Module mit RAG,
LMS-Anbindung, Kubernetes. Das lohnt sich erst, wenn der Pilot einen Lerneffekt zeigt.
