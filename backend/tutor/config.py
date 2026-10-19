"""Konfiguration aus Umgebungsvariablen (siehe ``.env.example``)."""

from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]

# Server-seitiger Fallback, falls das Modell eine Anfrage ablehnt (Claude API).
FALLBACK_BETA = "server-side-fallback-2026-07-01"


def _float(name: str, default: float) -> float:
    value = os.environ.get(name)
    return float(value) if value else default


@dataclass(frozen=True)
class Settings:
    content_dir: Path
    data_dir: Path
    frontend_dir: Path
    llm_model: str
    llm_effort: str
    stt_model: str
    tts_model: str
    tts_voice: str | None
    # Turn-Erkennung: Der Tutor wartet nach Sprechpausen bewusst länger als ein
    # normaler Assistent, weil die Person nach einer Frage nachdenken soll.
    endpointing_min_delay: float
    endpointing_max_delay: float
    livekit_url: str
    livekit_api_key: str
    livekit_api_secret: str


def load_settings() -> Settings:
    return Settings(
        content_dir=Path(os.environ.get("TUTOR_CONTENT_DIR", REPO_ROOT / "content" / "modules")),
        data_dir=Path(os.environ.get("TUTOR_DATA_DIR", REPO_ROOT / "data")),
        frontend_dir=REPO_ROOT / "frontend",
        llm_model=os.environ.get("TUTOR_LLM_MODEL", "claude-sonnet-5-5"),
        llm_effort=os.environ.get("TUTOR_LLM_EFFORT", "low"),
        stt_model=os.environ.get("TUTOR_STT_MODEL", "nova-3"),
        tts_model=os.environ.get("TUTOR_TTS_MODEL", "sonic-3"),
        tts_voice=os.environ.get("CARTESIA_VOICE_ID") or None,
        endpointing_min_delay=_float("TUTOR_ENDPOINTING_MIN_DELAY", 0.5),
        endpointing_max_delay=_float("TUTOR_ENDPOINTING_MAX_DELAY", 5.0),
        livekit_url=os.environ.get("LIVEKIT_URL", "ws://localhost:7880"),
        livekit_api_key=os.environ.get("LIVEKIT_API_KEY", "devkey"),
        livekit_api_secret=os.environ.get("LIVEKIT_API_SECRET", "secret"),
    )


def claude_request_options(settings: Settings) -> dict:
    """Zusätzliche Parameter für jede Claude-Anfrage.

    Niedrige Effort-Stufe für kurze Antwortzeiten im Gespräch und der server-seitige
    Fallback bei Ablehnungen. Wird als Keyword-Argumente an ``messages.create`` übergeben.
    """
    return {
        "output_config": {"effort": settings.llm_effort},
        "extra_headers": {"anthropic-beta": FALLBACK_BETA},
        "extra_body": {"fallbacks": "default"},
    }
