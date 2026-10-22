# Projekt-Spezifikation: Interner Voice-AI-Tutor

Dieses Dokument definiert die vollständige Architektur, Datenflüsse, Sicherheitsanforderungen, Schnittstellen und den Rollout-Plan für den unternehmensinternen Sprach-Tutor. Es dient als zentrale Referenz für Entwicklung, Betrieb und Produktmanagement.

**Stand:** Oktober 2026 · **Phase:** 0 abgeschlossen (Prototyp für ein Modul), Phase 1 (Test mit echten Sprachdiensten) offen. Offene Entscheidungen stehen in [Abschnitt 9](#9-offene-punkte--entscheidungen), benötigte Zugänge in [Abschnitt 10](#10-benötigte-accounts--zugänge).

---

## 1. Executive Summary & Zielsetzung

Der **Voice-AI-Tutor** ist ein unternehmensinternes Schulungs- und Wissenssystem, das Mitarbeitenden ermöglicht, Fachwissen, SOPs (Standard Operating Procedures), Compliance-Richtlinien und Onboarding-Inhalte über natürliche, latenzarme Sprachinteraktion zu erlernen.

*   **Problem:** Textbasierte Wikis und starre E-Learnings weisen geringe Interaktionsraten auf. Klassische Text-Chatbots führen zu passiver Textüberflutung statt aktivem Wissensaufbau.
*   **Lösung:** Ein browserbasierter Sprach-Tutor mit WebRTC-Audio, sokratischer Gesprächsführung, visueller Unterstützung (Merksätze, Quellen, Lernfortschritt) und automatischer Erstellung von Lernprotokollen.
*   **Kernhypothese:** Mitarbeitende behalten Inhalte nach einer Lerneinheit mit dem Sprach-Tutor messbar besser als mit dem bisherigen Format. Erst wenn der Pilot das bestätigt, wird in Skalierung (SSO, RAG, LMS, Kubernetes) investiert.

### 1.1 Erfolgsmetriken

| Metrik | Ziel | Messung |
| :--- | :--- | :--- |
| **Lernzuwachs** | Quote richtiger Antworten nachher mindestens 25 Prozentpunkte über vorher | Wissenscheck vorher/nachher (`/api/pilot/summary`) |
| **Behaltensleistung** | Nach 1–2 Wochen höchstens 10 Prozentpunkte unter „nachher“ und über dem bisherigen E-Learning | Wissenscheck „später“, Vergleichsgruppe mit bisherigem Format |
| **Antwortlatenz** | `e2e_latency` P50 < 1.000 ms, P95 < 1.500 ms | pro Gesprächswechsel in `data/sessions.jsonl` |
| **Reaktionszeit nach erkanntem Turn-Ende** | P50 < 500 ms | `llm_node_ttft` + `tts_node_ttfb` |
| **Session-Abschlussquote** | > 85 % | `finished` in `data/sessions.jsonl` |
| **Unterbrechungen beim Nachdenken** | < 1 pro Sitzung (subjektiv, Pilotbefragung) | Fragebogen nach dem Pilot |

> **Entscheidung (Okt. 2026):** Das ursprüngliche SLO „< 800 ms ohne RAG“ ist mit einem sokratischen Tutor nicht vereinbar. LiveKit misst `e2e_latency` ab dem Ende des Sprechens, also *inklusive* der Wartezeit, mit der die Turn-Erkennung Nachdenkpausen abwartet. Diese Wartezeit ist didaktisch gewollt. Deshalb gelten jetzt zwei Werte: die Gesamtlatenz (inkl. Turn-Erkennung) und die reine Reaktionszeit nach dem erkannten Turn-Ende.

---

## 2. Systemarchitektur & Komponenten

```text
[ Browser / Client ]
        │   ▲
        │   │  WebRTC (Opus Audio) + Data Channels (JSON Events) + Text Streams (Transkript)
        ▼   │
[ LiveKit SFU Server ]
        ▲
        │  WebRTC Transport, Agent-Dispatch mit Modul-ID
        ▼
[ Python Agent Worker (livekit-agents 1.8) ]
   ├── VAD: Silero VAD (lokal)
   ├── Turn-Erkennung: LiveKit Turn-Detector v1-mini (lokal) + dynamische Wartezeit
   ├── STT: Deepgram Nova-3 (Deutsch, Streaming)
   ├── LLM: Claude Sonnet 5.5 (Effort low, Prompt-Caching, Fallback bei Ablehnungen)
   ├── Wissen: ganzes Lernmodul im System-Prompt; RAG nur für freie Fragen (ab Phase 4)
   └── TTS: Cartesia Sonic-3 (Deutsch, PCM Streaming)

[ Web-Server (FastAPI) ]
   ├── Raum-Token mit Agent-Dispatch
   ├── Wissenscheck vorher / nachher / später
   └── Pilot-Auswertung
```

### 2.1 Technologie-Stack

| Domäne | Technologie | Zweck / Rationale |
| :--- | :--- | :--- |
| **Media Transport** | LiveKit (WebRTC SFU) | Sub-100ms Latenz, native Data Channels, Adaptive Bitrate |
| **Agent Framework** | `livekit-agents` 1.8 (Python 3.11+) | Async Pipeline, VAD-Integration, Turn-Handling, Latenzmetriken pro Gesprächswechsel |
| **VAD** | Silero VAD | Lokale Erkennung von Sprache vs. Stille ohne API-Latenz |
| **Turn-Erkennung** | LiveKit Turn-Detector `v1-mini` (lokal) | Erkennt am Satzinhalt, ob die Person fertig ist; lässt Nachdenkpausen zu |
| **STT** | Deepgram Nova-3 (`de`) | Streaming Speech-to-Text, geringe Wort-Fehlerrate |
| **Reasoning / LLM** | Claude Sonnet 5.5, Effort `low` | Schnelle erste Tokens, zuverlässiges Tool-Calling; Modell per Umgebungsvariable austauschbar |
| **Wissen** | Lernmodul (YAML) vollständig im Prompt | Keine Suchlatenz, das Modell sieht immer die ganze SOP |
| **Vektor-DB (ab Phase 4)** | Qdrant | Nur für freie Rückfragen außerhalb des aktuellen Moduls, mit Rollenfilter |
| **TTS** | Cartesia Sonic-3 (`de`) | Streaming-Audio mit niedriger Latenz bis zum ersten Chunk |
| **Frontend** | HTML/JS ohne Build-Schritt, `livekit-client` 2.22 | Für den Pilot ausreichend; Next.js erst bei Bedarf |

---

## 3. Datenfluss & Data-Channel-Spezifikation

Das System nutzt WebRTC Data Channels für die ereignisgesteuerte Synchronisation zwischen Agent und Client. Alle Payloads sind UTF-8-kodierte JSON-Strings. Das Gesprächstranskript läuft über LiveKit Text Streams (Topic `lk.transcription`).

```text
Nutzer spricht ──> VAD + Turn-Detector erkennen Turn-Ende ──> STT ──> LLM (Modul im Kontext)
                                                                          │
                                       ┌──────────────────────────────────┼─────────────────────────────┐
                                       ▼                                  ▼                             ▼
                              Antwort als TTS-Stream        Tool: show_key_point / show_source    Tool: mark_objective
                                                             → agent_key_point / agent_sources     → agent_progress
                                                                                                         │
                                                                                Tool: finish_session ────┘
                                                                                → session_protocol
```

### 3.1 Topic-Definitionen

#### 1. Topic: `agent_state`

Aktueller Zustand des Agenten für die Statusanzeige (`initializing`, `listening`, `thinking`, `speaking`).

```json
{ "status": "thinking" }
```

#### 2. Topic: `agent_key_point`

Kernaussage, die als Merksatz auf dem Bildschirm erscheint.

```json
{ "type": "key_point", "text": "Verdächtige E-Mails nur über „Phishing melden“ melden." }
```

#### 3. Topic: `agent_sources`

Abschnitt des Lernmaterials, auf den sich der Tutor gerade stützt.

```json
{
  "type": "sources",
  "sources": [
    {
      "doc_id": "phishing-melden",
      "section_id": "s2",
      "title": "Verdächtige E-Mails erkennen und melden: Richtig melden",
      "version": "2026-10-beispiel",
      "snippet": "Verdächtige E-Mails werden ausschließlich über die Schaltfläche ..."
    }
  ]
}
```

#### 4. Topic: `agent_progress`

Stand der Lernziele (`offen`, `unsicher`, `verstanden`).

```json
{
  "type": "progress",
  "objectives": [
    { "id": "erkennen", "text": "Drei typische Merkmale ...", "status": "verstanden" }
  ]
}
```

#### 5. Topic: `session_protocol`

Lernprotokoll am Ende der Lerneinheit. Es wird nur der lernenden Person angezeigt.

```json
{
  "type": "session_protocol",
  "sessionId": "sess_98374fa",
  "markdown": "# Lernprotokoll: ...\n\n## Lernziele\n..."
}
```

#### Reserviert: `agent_events`

Für clientseitige Audiosignale (Earcons), sobald RAG-Suchen Wartezeiten erzeugen (Phase 4).

---

## 4. Repository-Struktur

```text
├── PROJECT.md                     # diese Spezifikation
├── README.md                      # Einrichtung und Pilotablauf
├── content/modules/               # Lernmodule (YAML: Lernziele, SOP-Text, Wissenscheck)
├── backend/
│   ├── pyproject.toml
│   ├── tutor/
│   │   ├── agent.py               # LiveKit-Worker
│   │   ├── config.py              # Umgebungsvariablen
│   │   ├── modules.py             # Module laden und validieren
│   │   ├── prompts.py             # Sokratischer System-Prompt
│   │   ├── tools.py               # Tutor-Werkzeuge, Lernprotokoll, Topic-Namen
│   │   ├── server.py              # Token, Wissenscheck, Pilot-Auswertung, Frontend
│   │   └── textmode.py            # Tutor im Terminal
│   └── tests/
├── frontend/                      # Browser-Client (index.html, app.js, styles.css)
├── docker-compose.yml             # Lokaler LiveKit-Server
├── .env.example
└── .github/workflows/ci.yml       # Tests bei jedem Push
```

---

## 5. Phasenplan

```text
Phase 0 ✔        Phase 1          Phase 2              Phase 3               Gate       Phase 4              Phase 5
Prototyp  ──>  Echter Test  ──>  Freigaben  ──>  Pilot (20–30 Pers.) ──> Lerneffekt? ──> Skalierung  ──>  Rollout
1 Modul        mit Keys          BR, DSFA, AVV    vorher/nachher/später    ja / nein    SSO, RAG, LMS      K8s
```

### Phase 0: Prototyp (abgeschlossen)

*   Sprach-Agent mit Deepgram, Claude und Cartesia; ganzes Modul im Kontext.
*   Turn-Erkennung mit lokalem Modell und dynamischer Wartezeit.
*   Merksätze, Quellen, Lernfortschritt und Lernprotokoll im Browser.
*   Wissenscheck vorher/nachher/später mit pseudonymer Kennung; Latenz pro Gesprächswechsel.
*   Textmodus im Terminal; automatisierte Tests.

### Phase 1: Test mit echten Sprachdiensten (1 Woche)

*   Accounts anlegen (Abschnitt 10), Keys hinterlegen, deutsche Cartesia-Stimme wählen.
*   5–10 Testgespräche im Projektteam. Prüfen: Turn-Erkennung bei Nachdenkpausen, Natürlichkeit der Stimme, `e2e_latency`, Werkzeugaufrufe, Treue zum Material.
*   Endpointing-Parameter (`TUTOR_ENDPOINTING_MIN_DELAY`/`MAX_DELAY`) und Prompt anhand der Gespräche feinjustieren.
*   Evaluations-Set aufbauen: 20–30 geprüfte Fragen mit erwarteten Antworten je Modul, automatisiert im Textmodus abprüfbar.

### Phase 2: Freigaben und Inhalte (parallel zu Phase 1, 3–6 Wochen)

*   **Betriebsrat:** Unterrichtung (§ 80 Abs. 2, § 90 Abs. 1 Nr. 3 BetrVG) und Betriebsvereinbarung zu Wissenscheck, Lernprotokoll und Sitzungsdaten (§ 87 Abs. 1 Nr. 6, § 98 BetrVG). Ohne sie kein Pilot. Entwürfe: [`docs/compliance/`](docs/compliance/README.md).
*   **Datenschutz:** Datenschutz-Folgenabschätzung (Art. 35 DSGVO), Auftragsverarbeitungsverträge mit allen Anbietern, Prüfung der Drittlandübermittlung (USA).
*   **EU AI Act:** Einstufung dokumentieren. Solange keine Bewertung mit Folgen für Beschäftigte erfolgt, kein Hochrisiko-System.
*   **Inhalte:** 1–2 echte SOPs mit der Fachabteilung in das Modulformat überführen, Lernziele und Quizfragen freigeben lassen.

### Phase 3: Pilot (4 Wochen)

*   20–30 Teilnehmende mit pseudonymen Kennungen; Vergleichsgruppe mit dem bisherigen E-Learning.
*   Ablauf: Wissenscheck vorher → Lerneinheit → Wissenscheck nachher → nach 1–2 Wochen Wissenscheck später.
*   Kurzer Fragebogen: Verständlichkeit, Unterbrechungen, Nutzung im Arbeitsumfeld (Großraumbüro, Headset).
*   Hosting für den Pilot: eine VM im Unternehmensnetz oder LiveKit Cloud (EU-Region), Agent und Web-Server per Docker.
*   Vor dem Start die offenen Maßnahmen der DSFA umsetzen, insbesondere: Agent im Produktivmodus (`start`, keine Debug-Logs mit Transkripten), Betrieb nur im internen Netz oder hinter Anmeldung, TLS, Dateirechte auf `data/`, dokumentierte Löschung (`docs/compliance/04-dsfa.md`, M10–M13).

### Gate: Entscheidung über Skalierung

Weiter zu Phase 4 nur, wenn die Ziele aus Abschnitt 1.1 für Lernzuwachs und Behaltensleistung erreicht sind. Sonst: Didaktik überarbeiten oder Projekt beenden.

### Phase 4: Skalierung (6–8 Wochen)

*   SSO (OIDC über Azure AD / Okta) statt Pilot-Kennungen; Raum-Token nur für angemeldete Personen.
*   Mehrere Module; Modulauswahl nach Rolle.
*   RAG (Qdrant, Hybrid Search) nur für freie Rückfragen außerhalb des aktuellen Moduls; Rollenfilter (`allowed_roles`) im Payload; Earcons über `agent_events` bei Suchlatenz > 450 ms.
*   Textmodus im Browser (Barrierefreiheit, Großraumbüro).
*   Pflege-Workflow für Inhalte: Versionierung, Freigabe durch Fachverantwortliche, automatischer Evaluationslauf bei jeder Änderung.
*   Kostenmonitoring pro Session-Minute.

### Phase 5: Produktiver Rollout

*   Multi-Worker-Deployment über Kubernetes (Skalierung nach aktiven Räumen, z. B. KEDA).
*   LMS-Schnittstelle (xAPI / cmi5), um absolvierte Lerneinheiten zu quittieren, im Rahmen der Betriebsvereinbarung.
*   Betriebsübergabe: Monitoring, Alarmierung, Runbooks.

---

## 6. Sicherheit, Datenschutz & Compliance

Da der Tutor mit internen Unternehmensdaten und Mitarbeiter-Stimmen arbeitet, gelten strikte Compliance-Leitplanken:

*   **Audio-Speicherung:** Sprach-Streams werden flüchtig im Arbeitsspeicher verarbeitet. Keine Audioaufzeichnung (`record=False` im Agenten) und kein Transkript auf dem Server.
*   **Datensparsamkeit im Pilot:** Gespeichert werden nur Wissenscheck-Antworten, Lernziel-Status und Latenzwerte, verknüpft mit einer pseudonymen Pilot-Kennung. Klarnamen werden vom Server abgelehnt. Das Lernprotokoll sieht nur die lernende Person.
*   **Keine Leistungsbewertung:** Ergebnisse dienen der Bewertung des Formats, nicht der Personen. Auswertung nur aggregiert. Das ist mit dem Betriebsrat schriftlich festzuhalten.
*   **Betriebsrat (§ 87 Abs. 1 Nr. 6 BetrVG):** Wissenscheck, Lernprotokoll und eine spätere LMS-Anbindung sind technische Einrichtungen, die Verhalten oder Leistung erfassen können. Mitbestimmung vor dem Pilot.
*   **Datenschutz-Folgenabschätzung:** Sprachdaten von Beschäftigten bei mehreren externen Anbietern machen eine DSFA sehr wahrscheinlich erforderlich.
*   **Anbieterverträge:** Auftragsverarbeitungsverträge mit Anthropic, Deepgram, Cartesia und gegebenenfalls LiveKit. Vertraglich ausschließen, dass Daten zum Training genutzt werden; Zero Data Retention vereinbaren, wo angeboten. Drittlandübermittlung in die USA prüfen (EU-US Data Privacy Framework, Standardvertragsklauseln). EU-Regionen nutzen, wo verfügbar.
*   **EU AI Act:** Der Tutor vermittelt Wissen und bewertet keine Personen mit Folgen für ihr Arbeitsverhältnis. Sobald Ergebnisse über Zugang zu Schulungen oder Beförderungen mitentscheiden, ist die Einstufung als Hochrisiko-System neu zu prüfen.
*   **Keine Cloud-Zusatzdienste ohne Prüfung:** Die adaptive Unterbrechungserkennung von LiveKit Cloud ist abgeschaltet; Turn-Erkennung und VAD laufen lokal.
*   **On-Premise / Sovereign Fallback-Pfad:**
    *   STT: selbst gehostetes `faster-whisper`.
    *   LLM: Open-Weight-Modell über einen lokalen vLLM-Cluster.
    *   TTS: lokale Sprach-Engine (z. B. Piper oder Kokoro TTS).
*   **Zugriffskontrolle auf Dokumentenebene (ab Phase 4):** Jedes Dokument in Qdrant enthält im Payload `allowed_roles`. Der Agent filtert den Vektorabruf anhand der Rollen aus dem SSO-Token.

---

## 7. Latenz-Budget & Performance-SLOs

`e2e_latency` misst die Zeit vom Ende des Sprechens bis zum Beginn der Antwort, also inklusive Turn-Erkennung.

| Teilschritt | Ziel-Budget (P50) | Kritisches Limit (P95) | Optimierungsmaßnahme |
| :--- | :--- | :--- | :--- |
| Turn-Ende erkennen (Endpointing) | 500 ms | 800 ms | Turn-Detector; `min_delay` 0,5 s, `max_delay` 5 s für Nachdenkpausen |
| STT final (Deepgram, parallel zur Sprache) | 100 ms | 200 ms | Streaming, `no_delay` |
| LLM Time-to-First-Token | 250 ms | 450 ms | Effort `low`, Prompt-Caching des Moduls |
| TTS erster Audio-Chunk | 100 ms | 200 ms | Streaming PCM |
| Netzwerk / WebRTC | 50 ms | 100 ms | SFU-Region nahe den Nutzenden |
| **Gesamt `e2e_latency`** | **< 1.000 ms** | **< 1.500 ms** | |
| **Reaktionszeit nach Turn-Ende** | **< 500 ms** | **< 800 ms** | |

Wenn die Person nach einer Tutor-Frage nachdenkt, wartet der Agent bis zu `max_delay`. Diese Fälle sind gewollt und werden bei der Auswertung getrennt betrachtet (Turns mit `end_of_turn_delay` > 1,5 s).

---

## 8. Risikomatrix & Mitigation

| Risiko | Schweregrad | Wahrscheinlichkeit | Gegenmaßnahme |
| :--- | :--- | :--- | :--- |
| Falsche Fakten bei SOPs | Hoch | Mittel | Ganzes Modul im Kontext, Prompt-Regel „nur Material“, ehrliches „weiß ich nicht“, Evaluations-Set bei jeder Änderung |
| Tutor unterbricht beim Nachdenken | Hoch | Hoch | Turn-Detector, dynamische Wartezeit bis 5 s, Feinjustierung in Phase 1 |
| Betriebsrat-Verfahren verzögert Pilot | Hoch | Mittel | Frühzeitig einbinden (Phase 2 parallel zu Phase 1), Datensparsamkeit von Anfang an |
| Kein messbarer Lerneffekt | Hoch | Mittel | Gate vor Skalierung; Vergleichsgruppe; Didaktik iterieren |
| Veraltete oder ungeprüfte Inhalte | Hoch | Mittel | Fachverantwortliche pro Modul, Versionsstand im Lernprotokoll |
| Nutzer unterbricht Agenten (Barge-In) | Mittel | Hoch | VAD-basierte Unterbrechung, Wiederaufnahme bei Fehlalarm |
| Netzwerk-Jitter (WLAN/VPN) | Mittel | Mittel | Opus PLC, Adaptive Stream |
| Sprechen im Großraumbüro unangenehm | Mittel | Hoch | Headsets im Pilot, Textmodus ab Phase 4 |
| Kosten pro Session höher als erwartet | Mittel | Niedrig | Kosten pro Session-Minute messen, Prompt-Caching, Effort `low` |
| Anbieter nicht freigegeben (Datenschutz) | Hoch | Mittel | Sovereign-Fallback-Pfad (Abschnitt 6) |
| Überlastung bei Schulungswellen | Hoch | Niedrig | Horizontale Skalierung der Agent-Worker (Phase 5) |

---

## 9. Offene Punkte & Entscheidungen

| # | Thema | Verantwortlich | Status |
| :--- | :--- | :--- | :--- |
| 1 | Accounts für Anthropic, Deepgram, Cartesia anlegen, Keys hinterlegen | Projektleitung | offen |
| 2 | Deutsche Cartesia-Stimme auswählen | Projektteam | offen, nach #1 |
| 3 | Test mit echten Sprachdiensten, Endpointing feinjustieren | Entwicklung | offen, nach #1 |
| 4 | Betriebsrat informieren, Regelung für den Pilot | Projektleitung + HR | Entwürfe liegen vor: [`docs/compliance/`](docs/compliance/README.md) |
| 5 | DSFA und Auftragsverarbeitungsverträge | Datenschutzbeauftragte | DSFA-Entwurf und Anbieter-Prüfliste liegen vor: [`docs/compliance/`](docs/compliance/README.md) |
| 6 | Erste echte SOP auswählen und ins Modulformat überführen | Fachabteilung | offen |
| 7 | Pilot-Hosting: VM im Unternehmensnetz oder LiveKit Cloud (EU) | IT | offen |
| 8 | Vergleichsgruppe und Pilot-Teilnehmende festlegen | Personalentwicklung | offen |
| 9 | Kosten pro Session-Minute nach Phase 1 bewerten | Projektleitung | offen |
| ✔ | RAG vs. Modul im Kontext | – | entschieden: Modul im Kontext, RAG erst ab Phase 4 für freie Fragen |
| ✔ | Latenz-SLO inkl. Turn-Erkennung | – | entschieden: siehe Abschnitt 1.1 und 7 |
| ✔ | LLM | – | entschieden: Claude Sonnet 5.5, Effort `low`, austauschbar |

---

## 10. Benötigte Accounts & Zugänge

| Dienst | Wofür | Ab Phase | Umgebungsvariable |
| :--- | :--- | :--- | :--- |
| Anthropic (Claude Console) | LLM | 1 | `ANTHROPIC_API_KEY` |
| Deepgram | Spracherkennung | 1 | `DEEPGRAM_API_KEY` |
| Cartesia | Sprachausgabe, deutsche Stimme | 1 | `CARTESIA_API_KEY`, `CARTESIA_VOICE_ID` |
| LiveKit Cloud (optional) | gehosteter SFU statt eigenem Server | 3 | `LIVEKIT_URL`, `LIVEKIT_API_KEY`, `LIVEKIT_API_SECRET` |
| Hosting (VM oder Kubernetes) | Agent und Web-Server im Pilot | 3 | – |
| Identity Provider (Azure AD / Okta) | SSO | 4 | – |
| LMS | Quittierung absolvierter Einheiten | 5 | – |

Keys werden nie ins Repository eingecheckt (`.env` ist in `.gitignore`).
