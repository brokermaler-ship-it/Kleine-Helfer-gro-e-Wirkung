"""Web-Server für den Prototyp: Browser-Client, Raum-Token und Wissenscheck.

    uvicorn tutor.server:app --port 8000

Ergebnisse des Wissenschecks landen pseudonym (nur mit der Pilot-Kennung) in
``data/quiz_results.jsonl``, getrennt nach Tutor- und Vergleichsgruppe.
``/api/pilot/summary`` wertet sie aus, ``/api/pilot/latency`` die Latenzen aus
``data/sessions.jsonl`` und ``/api/pilot/feedback`` den Fragebogen aus ``data/feedback.jsonl``.
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

from . import feedback
from .config import Settings, load_settings
from .metrics import latency_summary
from .modules import Module, load_modules

AGENT_NAME = "voice-tutor"
PILOT_ID_PATTERN = r"^[A-Za-z0-9_-]{3,32}$"

Phase = Literal["vorher", "nachher", "spaeter"]
# "vergleich": Teilnehmende, die statt des Tutors das bisherige E-Learning bearbeiten.
Group = Literal["tutor", "vergleich"]


class SessionRequest(BaseModel):
    module_id: str
    pilot_id: str = Field(pattern=PILOT_ID_PATTERN)


class QuizSubmission(BaseModel):
    pilot_id: str = Field(pattern=PILOT_ID_PATTERN)
    phase: Phase
    group: Group = "tutor"
    answers: dict[str, int]


class FeedbackSubmission(BaseModel):
    pilot_id: str = Field(pattern=PILOT_ID_PATTERN)
    answers: dict[str, int]
    comment: str = Field(default="", max_length=feedback.COMMENT_MAX_LENGTH)


def create_app(settings: Settings | None = None) -> FastAPI:
    settings = settings or load_settings()
    app = FastAPI(title="Voice-AI-Tutor Prototyp")

    def read_jsonl(name: str) -> list[dict]:
        path = settings.data_dir / name
        if not path.exists():
            return []
        return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines() if line.strip()]

    def append_jsonl(name: str, record: dict) -> None:
        settings.data_dir.mkdir(parents=True, exist_ok=True)
        with (settings.data_dir / name).open("a", encoding="utf-8") as f:
            f.write(json.dumps(record, ensure_ascii=False) + "\n")

    @lru_cache(maxsize=1)
    def modules() -> dict[str, Module]:
        return load_modules(settings.content_dir)

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
        room = f"tutor-{module.id}-{uuid.uuid4().hex[:8]}"
        metadata = json.dumps({"module_id": module.id, "pilot_id": req.pilot_id})
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
            "group": submission.group,
            "score": score,
            "total": len(results),
            "answers": {r["id"]: r["chosen"] for r in results},
        }
        append_jsonl("quiz_results.jsonl", record)
        return {"score": score, "total": len(results), "results": results}

    @app.get("/api/pilot/summary")
    def pilot_summary() -> dict:
        """Durchschnittliche Quote richtiger Antworten je Modul, Gruppe und Phase.

        Pro Person und Phase zählt nur der letzte Versuch. Ältere Datensätze ohne Gruppe
        gehören zur Tutor-Gruppe.
        """
        latest: dict[tuple[str, str, str, str], dict] = {}
        for r in read_jsonl("quiz_results.jsonl"):
            latest[(r["module_id"], r.get("group", "tutor"), r["phase"], r["pilot_id"])] = r
        grouped: dict[str, dict[str, dict[str, list[float]]]] = defaultdict(lambda: defaultdict(lambda: defaultdict(list)))
        for (module_id, group, phase, _), r in latest.items():
            grouped[module_id][group][phase].append(r["score"] / r["total"] if r["total"] else 0.0)
        return {
            module_id: {
                group: {
                    phase: {"teilnehmende": len(v), "quote_richtig": round(sum(v) / len(v), 3)}
                    for phase, v in phases.items()
                }
                for group, phases in groups.items()
            }
            for module_id, groups in grouped.items()
        }

    @app.get("/api/feedback/questions")
    def feedback_questions() -> list[dict]:
        return [{"id": q.id, "text": q.text, "options": list(q.options)} for q in feedback.QUESTIONS]

    @app.post("/api/modules/{module_id}/feedback")
    def submit_feedback(module_id: str, submission: FeedbackSubmission) -> dict:
        module = get_module(module_id)
        error = feedback.validate_answers(submission.answers)
        if error:
            raise HTTPException(422, error)
        record = {
            "timestamp": datetime.now().isoformat(),
            "pilot_id": submission.pilot_id,
            "module_id": module.id,
            "answers": submission.answers,
            "comment": submission.comment.strip(),
        }
        append_jsonl("feedback.jsonl", record)
        return {"ok": True}

    @app.get("/api/pilot/feedback")
    def pilot_feedback() -> dict:
        """Häufigkeiten und Mittelwerte des Fragebogens je Modul."""
        return feedback.summarize(read_jsonl("feedback.jsonl"))

    @app.get("/api/pilot/latency")
    def pilot_latency() -> dict:
        """P50/P95 der Antwortlatenz je Modul und Abgleich mit den Zielen (PROJECT.md, 1.1)."""
        return latency_summary(read_jsonl("sessions.jsonl"))

    if settings.frontend_dir.exists():
        app.mount("/", StaticFiles(directory=settings.frontend_dir, html=True), name="frontend")
    return app


app = create_app()
