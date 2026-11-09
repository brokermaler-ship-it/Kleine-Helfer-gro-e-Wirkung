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
content/evals/            Evaluations-Set je Modul (geprüfte Fragen mit Referenzantworten)
backend/tutor/
  modules.py              Module laden und validieren
  prompts.py              System-Prompt (sokratisch, nur Fakten aus dem Modul)
  tools.py                Tutor-Werkzeuge und Lernprotokoll, unabhängig von LiveKit
  agent.py                LiveKit-Sprach-Agent (Deepgram → Claude → Cartesia)
  textmode.py             Derselbe Tutor im Terminal, nur mit Anthropic-Key
  evaluation.py           Prüflauf des Evaluations-Sets im Textmodus
  server.py               Web-Server: Browser-Client, Raum-Token, Wissenscheck, Auswertung
  metrics.py              Latenz-Auswertung (P50/P95)
  feedback.py             Fragebogen nach der Lerneinheit
frontend/                 Browser-Client (ohne Build-Schritt)
Dockerfile                Ein Image für Sprach-Agent und Web-Server
docker-compose.yml        LiveKit-Server, Agent und Web-Server für den Pilot
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

Alles in Containern (Keys aus `.env`, Daten im Volume `tutor-data`):

```bash
docker compose up -d --build
```

Der Agent erreicht LiveKit im Compose-Netz unter `ws://livekit:7880`. Der Browser bekommt
`PUBLIC_LIVEKIT_URL` (Standard `ws://localhost:7880`); auf einer Pilot-VM muss dort die von
den Rechnern der Teilnehmenden erreichbare Adresse stehen.

Nur die Didaktik ausprobieren, ohne Sprache und LiveKit:

```bash
export ANTHROPIC_API_KEY=...
python -m tutor.textmode phishing-melden
```

Tests: `cd backend && pytest`

## Evaluations-Set

Zu jedem Modul gehört unter `content/evals/` ein Satz geprüfter Fragen mit
Referenzantworten (20–30 Fälle). Der Prüflauf stellt jede Frage in einem frischen
Gespräch an den Tutor im Textmodus und lässt die Antwort von Claude gegen die Referenz
bewerten. Er braucht nur den Anthropic-Key:

```bash
python -m tutor.evaluation phishing-melden            # alle Fälle
python -m tutor.evaluation phishing-melden --only e12 # einzelne Fälle
```

Es gibt drei Arten von Fällen: `fakt` (Kernaussage stimmt, nichts dazuerfunden),
`ausserhalb` (Material sagt nichts dazu, Tutor bleibt ehrlich) und `korrektur` (falsche
Annahme wird eindeutig korrigiert). Unter `forbidden` stehen Begriffe, die nie in der
Antwort vorkommen dürfen, etwa eine falsche Durchwahl. Der Lauf endet mit Exit-Code 1,
sobald ein Fall scheitert, und schreibt einen Bericht nach `data/evals/`. Das
Prüfmodell lässt sich mit `TUTOR_EVAL_JUDGE_MODEL` wechseln.

Die Fälle zum Beispielmodul sind ebenfalls Beispielinhalt. Bei einer echten SOP gibt die
Fachabteilung die Fälle zusammen mit dem Modul frei; `module_version` im Evaluations-Set
muss zum Modulstand passen.

> Hinweis: Hinter einem HTTP-Proxy ignoriert der Agent beim Verbindungsaufbau zu LiveKit
> die `NO_PROXY`-Liste. Für einen lokalen LiveKit-Server dann `HTTPS_PROXY` für den
> Agent-Prozess entfernen.

## Ablauf im Pilot

1. Die Pilotleitung vergibt pseudonyme Kennungen (z. B. `P-017`). Namen werden abgelehnt.
2. Teilnehmende machen den Wissenscheck **vorher**, dann die Lerneinheit, dann den
   Wissenscheck **nachher** mit Auflösung und zum Schluss einen kurzen Fragebogen
   (Verständlichkeit, Unterbrechungen beim Nachdenken, Arbeitsumfeld und Headset).
   Die **Vergleichsgruppe** bekommt den Link mit `?gruppe=vergleich`, zum Beispiel
   `http://localhost:8000/?gruppe=vergleich`. Sie macht denselben Wissenscheck, bearbeitet
   dazwischen aber das bisherige E-Learning statt des Tutors und bekommt keinen Fragebogen.
3. Ein bis zwei Wochen später: Startseite → „Nur Wissenscheck (Wiederholung)“.
4. Auswertung: `GET /api/pilot/summary` liefert je Modul, Gruppe (`tutor`, `vergleich`)
   und Phase die Zahl der Teilnehmenden und die Quote richtiger Antworten (letzter
   Versuch pro Person).
5. Latenz: `GET /api/pilot/latency` liefert je Modul P50/P95 von `e2e_latency` und der
   Reaktionszeit nach Turn-Ende in Millisekunden, die Abschlussquote und ob die Ziele aus
   PROJECT.md erreicht sind. Nachdenkpausen (Turn-Erkennung wartet länger als 1,5 s)
   werden getrennt ausgewiesen und zählen nicht für die Ziele.
6. Fragebogen: `GET /api/pilot/feedback` liefert je Modul die Häufigkeit jeder Antwort,
   bei Skalenfragen den Mittelwert, und die freiwilligen Kommentare ohne Pilot-Kennung.

Gespeichert wird in `data/` (nicht im Repository):

- `quiz_results.jsonl`: Antworten und Punktzahl pro Kennung, Gruppe und Phase
- `feedback.jsonl`: Antworten auf den Fragebogen pro Kennung
- `sessions.jsonl`: pro Sitzung Modulstand, bearbeitete Lernziele und Latenzen pro
  Gesprächswechsel (`e2e_latency`, `end_of_turn_delay`, `llm_node_ttft`, `tts_node_ttfb`)

Es gibt keine Audioaufzeichnung und kein Gesprächstranskript auf dem Server.

> **Im Pilot den Agenten mit `python -m tutor.agent start` betreiben, nicht mit `dev`.** Im
> Entwicklungsmodus protokolliert LiveKit die Transkripte der Nutzenden auf Debug-Ebene.
> Außerdem sind Token-Ausgabe und Auswertung im Prototyp ohne Anmeldung erreichbar: nur im
> internen Netz oder hinter einem Reverse Proxy mit Anmeldung betreiben. Weitere Maßnahmen
> und die Unterlagen für Betriebsrat und Datenschutz stehen in [`docs/compliance/`](docs/compliance/README.md). Das
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
- Liegt `e2e_latency` im Median unter 1.000 ms (inklusive Turn-Erkennung, siehe PROJECT.md Abschnitt 7)? `GET /api/pilot/latency` zeigt es direkt.
- Hält sich der Tutor an das Material und ruft er die Werkzeuge sinnvoll auf?

Bewusst noch nicht enthalten: SSO, Rollenrechte, mehrere Module mit RAG,
LMS-Anbindung, Kubernetes. Das lohnt sich erst, wenn der Pilot einen Lerneffekt zeigt.
