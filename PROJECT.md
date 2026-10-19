# Projekt-Spezifikation: Interner Voice-AI-Tutor

Dieses Dokument definiert die vollständige Architektur, Datenflüsse, Sicherheitsanforderungen, Schnittstellen und den Rollout-Plan für den unternehmensinternen Sprach-Tutor. Es dient als zentrale Referenz für Entwicklung, Betrieb und Produktmanagement.

---

## 1. Executive Summary & Zielsetzung

Der **Voice-AI-Tutor** ist ein unternehmensinternes Schulungs- und Wissenssystem, das Mitarbeitenden ermöglicht, Fachwissen, SOPs (Standard Operating Procedures), Compliance-Richtlinien und Onboarding-Inhalte über natürliche, latenzarme Sprachinteraktion zu erlernen.

*   **Problem:** Textbasierte Wikis und starre E-Learnings weisen geringe Interaktionsraten auf. Klassische Text-Chatbots führen zu passiver Textüberflutung statt aktivem Wissensaufbau.
*   **Lösung:** Ein browserbasierter Sprach-Tutor mit WebRTC-Audio, sokratischer Gesprächsführung, latenzfreier visueller Synchronisation und automatischer Erstellung von Lernprotokollen.
*   **Kern-Metriken (SLOs):**
    *   End-to-End-Sprachlatenz (ohne RAG): < 800 ms
    *   End-to-End-Sprachlatenz (mit RAG): < 1.400 ms (durch Chimes/Filler akustisch überbrückt)
    *   Session-Abschlussquote: > 85 %

---

## 2. Systemarchitektur & Komponenten

```text
[ Browser / Client ]
        │   ▲
        │   │  WebRTC (Opus Audio) + Data Channels (JSON Events)
        ▼   │
[ LiveKit SFU Server ]
        ▲
        │  WebRTC Transport
        ▼
[ Python Agent Worker (livekit-agents) ]
   ├── VAD: Silero VAD (20ms Frames)
   ├── STT: Deepgram Nova-2 (Modell: German / Streaming WebSocket)
   ├── Orchestrierung & Didaktik: VoiceAssistant Pipeline
   ├── LLM: OpenAI GPT-4o-mini / Anthropic Claude 3.5 Sonnet
   ├── RAG-Engine: Qdrant Vector DB + Hybrid Search (Dense + Sparse)
   └── TTS: Cartesia Sonic (Multilingual / PCM Streaming)
```

### 2.1 Technologie-Stack

| Domäne | Technologie | Zweck / Rationale |
| :--- | :--- | :--- |
| **Media Transport** | LiveKit (WebRTC SFU) | Sub-100ms Latenz, native Data Channels, Adaptive Bitrate |
| **Agent Framework** | `livekit-agents` (Python 3.11+) | Async Pipeline, VAD-Integration, automatisches Turn-Taking |
| **VAD** | Silero VAD | Lokale Erkennung von Sprache vs. Stille ohne API-Latenz |
| **STT** | Deepgram Nova-2 (German) | Streaming Speech-to-Text, minimale Wort-Fehlerrate (WER) |
| **Reasoning / LLM** | GPT-4o-mini / Claude 3.5 Sonnet | Schnelles TTFT (Time-To-First-Token), zuverlässiges Function Calling |
| **Vektor-DB (RAG)** | Qdrant (Self-hosted / Cloud) | HNSW-Indexierung, Payload-Filterung nach Rollenberechtigungen |
| **Embeddings** | `text-embedding-3-small` | 1536 Dimensionen, balanciert Abrufgenauigkeit und Generierungszeit |
| **TTS** | Cartesia Sonic | Streaming-Audio mit < 120 ms Latenz bis zum ersten Chunk |
| **Frontend** | Next.js / React 19 + TailwindCSS | `livekit-client`, Web Audio API, animierte Status-Komponenten |

---

## 3. Datenfluss & Data-Channel-Spezifikation

Das System nutzt WebRTC Data Channels für die ereignisgesteuerte Synchronisation zwischen Agent und Client. Alle Payloads sind UTF-8-kodierte JSON-Strings.

```text
Nutzer spricht ──> VAD erkennt Stille ──> STT Stream ──> LLM entscheidet: RAG nötig?
                                                                  │
                         ┌────────────────────────────────────────┴───────────────────┐
                         ▼ Nein                                                       ▼ Ja
           Direkte Antwort (TTS Stream)        ├── DataChannel Topic 'agent_state'   {"status": "searching"}
                                               ├── DataChannel Topic 'agent_events'  {"action": "play_earcon"}
                                               ├── Vektorsuche Qdrant + Re-Ranking (< 600 ms)
                                               ├── DataChannel Topic 'agent_sources' {"sources": [...]}
                                               └── LLM Didaktik-Antwort via TTS Stream
```

### 3.1 Topic-Definitionen

#### 1. Topic: `agent_state`

Übermittelt den aktuellen Berechnungsstatus des Agenten für UI-Indikatoren.

```json
{
  "status": "searching",
  "detail": "Durchsuche IT-Sicherheitsrichtlinien..."
}
```

#### 2. Topic: `agent_events`

Löst clientseitige Aktionen aus, um Serverbandbreite zu sparen (z. B. Audiosignale).

```json
{
  "action": "play_earcon",
  "sound": "chime"
}
```

#### 3. Topic: `agent_sources`

Liefert Metadaten der referenzierten RAG-Abschnitte für klickbare Nachweise im Client.

```json
{
  "type": "rag_sources",
  "sources": [
    {
      "doc_id": "sec-guideline-2026",
      "title": "IT-Sicherheitsrichtlinie 2026",
      "url": "https://wiki.intern/docs/sec-2026",
      "page": 4,
      "snippet": "MFA ist für alle externen Zugänge zwingend erforderlich."
    }
  ]
}
```

#### 4. Topic: `session_protocol`

Übermittelt die aggregierte Zusammenfassung beim Beenden der Lerneinheit.

```json
{
  "type": "session_protocol",
  "sessionId": "sess_98374fa",
  "markdown": "# Lernprotokoll: IT-Sicherheit\n\n## Behandelte Kernkompetenzen\n..."
}
```

---

## 4. Repository-Struktur

```text
voice-tutor/
├── backend/
│   ├── src/
│   │   ├── __init__.py
│   │   ├── agent.py               # LiveKit Worker Entrypoint
│   │   ├── config.py              # Umgebungsvariablen & Secrets
│   │   ├── didactics/
│   │   │   ├── prompts.py         # Sokratische System-Prompts
│   │   │   └── protocol.py        # Generierung des Lernprotokolls
│   │   ├── rag/
│   │   │   ├── client.py          # Qdrant Verbindung & Retriever
│   │   │   └── tools.py           # Function Calling Context für LLM
│   │   └── utils/
│   │       └── telemetry.py       # OpenTelemetry / Latenz-Tracking
│   ├── tests/
│   ├── Dockerfile
│   └── requirements.txt
├── rag_pipeline/
│   ├── ingestion/
│   │   ├── chunker.py             # Markdown/PDF Chunker (150-250 Tokens)
│   │   └── embedder.py            # Batch-Embedding-Generator
│   └── data/
│       └── raw_docs/              # Markdown, SOPs, Schulungsmaterial
├── frontend/
│   ├── src/
│   │   ├── components/
│   │   │   ├── TutorVoiceRoom.tsx # LiveKit Room Provider & Audio-Renderer
│   │   │   ├── TutorStatus.tsx    # Radar-Animation & Statusanzeige
│   │   │   ├── SourcesPanel.tsx   # Klickbare Dokumentkarten
│   │   │   └── ProtocolModal.tsx  # Zusammenfassung & Export (PDF/MD)
│   │   ├── hooks/
│   │   │   ├── useDataChannel.ts  # Typed Data-Channel Listener
│   │   │   └── useAudioCue.ts     # Clientseitiger Earcon Player
│   │   └── pages/
│   ├── public/sounds/chime.mp3    # Lokaler Cache für UI-Sounds
│   ├── package.json
│   └── tailwind.config.js
├── deploy/
│   ├── docker-compose.yml         # Lokales Setup (LiveKit + Qdrant + Agent)
│   ├── helm/                      # Kubernetes Deployment Charts
│   └── caddy/Caddyfile            # TLS Termination & Reverse Proxy
├── .env.example
├── LICENSE
└── PROJECT.md
```

---

## 5. Phasenplan (Roadmap bis zur Produktion)

```text
Woche 1-2: Foundation     Woche 3-4: RAG & Events     Woche 5-6: UI & Protocol    Woche 7-8: Hardening & Scale
[ Core Pipeline PoC ] ──> [ Qdrant + DataChannel ] ──> [ React UI + Export ] ──> [ Pilot, SLOs, Launch ]
```

### Phase 1: Core Pipeline & WebRTC Foundation (Woche 1–2)

*   Bereitstellung des LiveKit SFU Servers (lokal via Docker / LiveKit Cloud).
*   Implementierung der minimalen Voice-Pipeline in Python (Silero VAD + Deepgram + GPT-4o-mini + Cartesia).
*   Feinjustierung der VAD-Parameter: `min_speech_duration: 0.1s`, `min_silence_duration: 0.35s` gegen unflüssiges Sprechen.

### Phase 2: RAG, Tool-Calling & State Channels (Woche 3–4)

*   Aufsetzen der Qdrant-Instanz und Aufbau der Chunking-Pipeline (150–250 Tokens pro Chunk, semantischer Overlap: 30 Tokens).
*   Implementierung des Function Calling Tools `search_internal_docs`.
*   Einbindung der Data-Channel-Events (`agent_state`, `agent_events`, `agent_sources`).
*   Implementierung von Smart Delay (Filler/Chimes nur bei DB-Latenzen > 450 ms).

### Phase 3: Frontend-Entwicklung & Didaktik-Engine (Woche 5–6)

*   Erstellung des browserbasierten WebRTC-Clients mit React und TailwindCSS.
*   Implementierung des lokalen Earcon-Soundplayers (Web Audio API) bei Signalempfang.
*   Entwicklung des interaktiven `DocumentSourcesPanel` mit Direkt-Links ins Intranet.
*   Fertigstellung des Prompts für sokratische Didaktik und der automatischen Lernprotokoll-Generierung (`finish_session`).

### Phase 4: Unternehmensintegration, Sicherheit & Pilotbetrieb (Woche 7–8)

*   SSO-Anbindung (OIDC/SAML via Azure AD / Okta) für Raum-Tokens.
*   Implementierung von Rollenfiltern (RBAC) in den Vektorabfragen (z. B. IT-Inhalte nur für IT-Berechtigte).
*   Interner Pilotbetrieb mit einer Testgruppe (20–30 Nutzer) zur Ermittlung didaktischer Schwachstellen.
*   Latenz- und Audio-Feinabstimmung (Messung von TTFT, Jitter und Abbruchraten).

### Phase 5: Produktiver Rollout & Skalierung (Woche 9+)

*   Multi-Worker Deployment über Kubernetes (HPA basierend auf CPU und aktiven WebRTC-Rooms).
*   LMS-Schnittstelle (xAPI / cmi5 / Webhook), um absolvierte Lerneinheiten automatisch im Personalentwicklungssystem zu quittieren.

---

## 6. Sicherheit, Datenschutz & Compliance (DSGVO)

Da der Tutor mit internen Unternehmensdaten und Mitarbeiter-Stimmen arbeitet, gelten strikte Compliance-Leitplanken:

*   **Audio-Speicherung:** Sprach-Streams werden flüchtig im Arbeitsspeicher verarbeitet. Es erfolgt keine dauerhafte Audioaufzeichnung auf Disk, sofern der Mitarbeitende nicht explizit für Coaching-Zwecke einwilligt.
*   **API-Verträge (Zero Data Retention):** Verträge mit Cloud-Anbietern (Deepgram, OpenAI, Cartesia) müssen ZDR-Zusatzvereinbarungen (Data Processing Agreements) enthalten, die ein Training der Anbieter-Modelle auf internen Daten ausschließen.
*   **On-Premise / Sovereign Fallback-Pfad:**
    *   STT: Wechsel von Deepgram Cloud auf selbst gehostetes `faster-whisper` (Triton Inference Server / vLLM).
    *   LLM: Wechsel auf Llama-3.1-70B-Instruct oder Mistral-Large via lokalem vLLM-Cluster.
    *   TTS: Wechsel auf lokale Sprach-Engines (z. B. Piper oder Kokoro TTS).
*   **Zugriffskontrolle auf Dokumentenebene (RBAC):** Jedes Dokument in Qdrant enthält im Payload ein Attribut `allowed_roles: ["finance", "all"]`. Der Agent filtert den Vektorabruf anhand des JWT-Tokens des angemeldeten Benutzers.

---

## 7. Latenz-Budget & Performance-SLOs

Um eine menschenähnliche Konversationsdynamik ohne unnatürliche Pausen zu gewährleisten, darf die Pipeline folgende Teil-Latenzen nicht überschreiten:

| Teilschritt | Ziel-Budget (P50) | Kritisches Limit (P95) | Optimierungsmaßnahme |
| :--- | :--- | :--- | :--- |
| VAD (Turn End Detection) | 250 ms | 400 ms | Silero VAD Parameteranpassung |
| STT (Deepgram Streaming) | 120 ms | 200 ms | Websocket-Stream parallel zur Sprache |
| RAG Lookup (Vektor + Rerank) | 350 ms | 700 ms | Chimes/Earcon überbrücken die Zeitspanne |
| LLM Time-to-First-Token (TTFT) | 200 ms | 400 ms | Nutzung kompakter Modelle (gpt-4o-mini) |
| TTS First Audio Chunk (Cartesia) | 90 ms | 180 ms | Streaming PCM Chunking (20ms Frames) |
| Netzwerk / WebRTC RTT | 30 ms | 80 ms | Dezentrale SFU Edge Instanzen |
| **Gesamtlatenz (ohne RAG)** | **~690 ms** | **< 1.100 ms** | Exzellente Gesprächsdynamik |
| **Gesamtlatenz (mit RAG)** | **~1.040 ms** | **< 1.600 ms** | Durch Earcon/Filler maskiert |

---

## 8. Risikomatrix & Mitigation

| Risiko | Schweregrad | Wahrscheinlichkeit | Gegenmaßnahme |
| :--- | :--- | :--- | :--- |
| Halluzinationen bei SOPs | Hoch | Mittel | Strikte System-Prompts, Tool-Zwang für Fakten (`temperature=0.1`), automatischer Abgleich gegen Quellen-Snippets. |
| Nutzer unterbricht Agenten (Barge-In Desync) | Mittel | Hoch | LiveKit native Interrupt-Handler stoppen TTS und LLM-Stream sofort; UI-Status wird via VAD-Event unverzüglich auf `idle` gesetzt. |
| Netzwerk-Jitter beim Nutzer (WLAN/VPN) | Mittel | Mittel | LiveKit Adaptive Stream Management (Opus PLC / Packet Loss Concealment), automatischer Puffer-Ausgleich. |
| Überlastung bei Schulungswellen | Hoch | Niedrig | Horizontale Skalierung der Agent-Worker via Kubernetes KEDA basierend auf der LiveKit-Room-Warteschlange. |
