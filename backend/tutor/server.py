"""Web-Server für den Prototyp: Browser-Client, Raum-Token und Wissenscheck.

    uvicorn tutor.server:app --port 8000

Ergebnisse des Wissenschecks landen pseudonym (nur mit der Pilot-Kennung) in
``data/quiz_results.jsonl``. ``/api/pilot/summary`` wertet sie aus.
"""

from __future__ import annotations

import json
import uuid
from collections import defaultdict
from datetime import datetime
from functools import lru_cache
from typing import Literal

from fastapi import FastAPI, HTTPException
from fastapi.staticfiles import StaticFiles
from livekit import api
from pydantic import BaseModel, Field

from .config import DEFAULT_TUTOR, Settings, load_settings
from .modules import Module, load_modules

AGENT_NAME = "voice-tutor"
PILOT_ID_PATTERN = r"^[A-Za-z0-9_-]{3,32}$"

Phase = Literal["vorher", "nachher", "spaeter"]


class SessionRequest(BaseModel):
    module_id: str
    pilot_id: str = Field(pattern=PILOT_ID_PATTERN)
    tutor: str = DEFAULT_TUTOR


class QuizSubmission(BaseModel):
    pilot_id: str = Field(pattern=PILOT_ID_PATTERN)
    phase: Phase
    answers: dict[str, int]


def create_app(settings: Settings | None = None) -> FastAPI:
    settings = settings or load_settings()
    app = FastAPI(title="Voice-AI-Tutor Prototyp")

    @lru_cache(maxsize=1)
    def modules() -> dict[str, Module]:
        return load_modules(settings.content_dir, settings.intern_content_dir)

    def get_module(module_id: str) -> Module:
        module = modules().get(module_id)
        if module is None:
            raise HTTPException(404, f"Unbekanntes Modul '{module_id}'")
        return module

    @app.get("/api/modules")
    def list_modules() -> list[dict]:
        return [
            {
                "id": m.id,
                "title": m.title,
                "version": m.version,
                "estimated_minutes": m.estimated_minutes,
                "objectives": [{"id": o.id, "text": o.text} for o in m.objectives],
            }
            for m in modules().values()
        ]

    @app.post("/api/session")
    def create_session(req: SessionRequest) -> dict:
        module = get_module(req.module_id)
        if req.tutor not in settings.tutors:
            raise HTTPException(422, f"Unbekannter Tutor '{req.tutor}'")
        room = f"tutor-{module.id}-{uuid.uuid4().hex[:8]}"
        metadata = json.dumps({"module_id": module.id, "pilot_id": req.pilot_id, "tutor": req.tutor})
        token = (
            api.AccessToken(settings.livekit_api_key, settings.livekit_api_secret)
            .with_identity(f"lernende-{req.pilot_id}-{uuid.uuid4().hex[:4]}")
            .with_name(req.pilot_id)
            .with_grants(api.VideoGrants(room_join=True, room=room))
            .with_room_config(
                api.RoomConfiguration(
                    agents=[api.RoomAgentDispatch(agent_name=AGENT_NAME, metadata=metadata)]
                )
            )
            .to_jwt()
        )
        return {"url": settings.livekit_url, "token": token, "room": room}

    @app.get("/api/tutors")
    def list_tutors() -> list[dict]:
        return [{"id": t.id, "name": t.name} for t in settings.tutors.values()]

    @app.get("/api/modules/{module_id}/quiz")
    def get_quiz(module_id: str) -> list[dict]:
        module = get_module(module_id)
        return [{"id": q.id, "question": q.question, "options": list(q.options)} for q in module.quiz]

    @app.post("/api/modules/{module_id}/quiz")
    def submit_quiz(module_id: str, submission: QuizSubmission) -> dict:
        module = get_module(module_id)
        results = []
        for q in module.quiz:
            chosen = submission.answers.get(q.id)
            results.append(
                {
                    "id": q.id,
                    "objective": q.objective,
                    "chosen": chosen,
                    "correct_index": q.correct,
                    "correct": chosen == q.correct,
                    "explanation": q.explanation,
                }
            )
        score = sum(r["correct"] for r in results)
        record = {
            "timestamp": datetime.now().isoformat(),
            "pilot_id": submission.pilot_id,
            "module_id": module.id,
            "module_version": module.version,
            "phase": submission.phase,
            "score": score,
            "total": len(results),
            "answers": {r["id"]: r["chosen"] for r in results},
        }
        settings.data_dir.mkdir(parents=True, exist_ok=True)
        with (settings.data_dir / "quiz_results.jsonl").open("a", encoding="utf-8") as f:
            f.write(json.dumps(record, ensure_ascii=False) + "\n")
        return {"score": score, "total": len(results), "results": results}

    @app.get("/api/pilot/summary")
    def pilot_summary() -> dict:
        """Durchschnittliche Quote richtiger Antworten je Modul und Phase.

        Pro Person und Phase zählt nur der letzte Versuch.
        """
        path = settings.data_dir / "quiz_results.jsonl"
        latest: dict[tuple[str, str, str], dict] = {}
        if path.exists():
            for line in path.read_text(encoding="utf-8").splitlines():
                if line.strip():
                    r = json.loads(line)
                    latest[(r["module_id"], r["phase"], r["pilot_id"])] = r
        grouped: dict[str, dict[str, list[float]]] = defaultdict(lambda: defaultdict(list))
        for (module_id, phase, _), r in latest.items():
            grouped[module_id][phase].append(r["score"] / r["total"] if r["total"] else 0.0)
        return {
            module_id: {
                phase: {"teilnehmende": len(v), "quote_richtig": round(sum(v) / len(v), 3)}
                for phase, v in phases.items()
            }
            for module_id, phases in grouped.items()
        }

    if settings.frontend_dir.exists():
        app.mount("/", StaticFiles(directory=settings.frontend_dir, html=True), name="frontend")
    return app


app = create_app()
