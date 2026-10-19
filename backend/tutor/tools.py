"""Werkzeuge des Tutors und der Sitzungszustand.

Die Tool-Schemas und ihre Logik sind hier unabhängig von LiveKit definiert. Der
Sprach-Agent (``agent.py``) und der Textmodus (``textmode.py``) nutzen beide dieselbe
``TutorSession``; sie unterscheiden sich nur darin, wie die Events beim Client ankommen.
"""

from __future__ import annotations

import uuid
from dataclasses import dataclass, field
from datetime import datetime

from .modules import Module

# Data-Channel-Topics (siehe PROJECT.md, Abschnitt 3.1)
TOPIC_STATE = "agent_state"
TOPIC_SOURCES = "agent_sources"
TOPIC_KEY_POINT = "agent_key_point"
TOPIC_PROGRESS = "agent_progress"
TOPIC_PROTOCOL = "session_protocol"

STATUS_LABELS = {"verstanden": "verstanden", "unsicher": "noch unsicher"}

Event = tuple[str, dict]


def tool_specs(module: Module) -> list[dict]:
    """Tool-Definitionen im Anthropic-Format (name, description, input_schema)."""
    section_ids = [s.id for s in module.sections]
    objective_ids = [o.id for o in module.objectives]
    return [
        {
            "name": "show_key_point",
            "description": (
                "Zeigt eine Kernaussage als kurze Karte auf dem Bildschirm an, damit die "
                "Person sie sich merken kann. Nutze es für Fakten, Fristen und Handlungsschritte."
            ),
            "input_schema": {
                "type": "object",
                "properties": {
                    "text": {
                        "type": "string",
                        "description": "Die Kernaussage in einem kurzen Satz, höchstens 15 Wörter.",
                    }
                },
                "required": ["text"],
                "additionalProperties": False,
            },
        },
        {
            "name": "show_source",
            "description": (
                "Blendet den Abschnitt des Lernmaterials ein, auf den du dich gerade stützt."
            ),
            "input_schema": {
                "type": "object",
                "properties": {
                    "section_id": {"type": "string", "enum": section_ids},
                },
                "required": ["section_id"],
                "additionalProperties": False,
            },
        },
        {
            "name": "mark_objective",
            "description": (
                "Hält fest, wie gut die Person ein Lernziel beherrscht. Erst aufrufen, nachdem "
                "sie eine Anwendungsfrage dazu beantwortet hat."
            ),
            "input_schema": {
                "type": "object",
                "properties": {
                    "objective_id": {"type": "string", "enum": objective_ids},
                    "status": {"type": "string", "enum": list(STATUS_LABELS)},
                    "note": {
                        "type": "string",
                        "description": "Kurze Notiz fürs Lernprotokoll, etwa was noch unklar war.",
                    },
                },
                "required": ["objective_id", "status", "note"],
                "additionalProperties": False,
            },
        },
        {
            "name": "finish_session",
            "description": (
                "Beendet die Lerneinheit und erzeugt das Lernprotokoll. Aufrufen, wenn alle "
                "Lernziele bearbeitet sind oder die Person aufhören möchte."
            ),
            "input_schema": {
                "type": "object",
                "properties": {
                    "summary": {
                        "type": "string",
                        "description": "Zusammenfassung des Gesprächs in zwei bis vier Sätzen.",
                    },
                    "open_questions": {
                        "type": "array",
                        "items": {"type": "string"},
                        "description": "Fragen, die offen geblieben sind oder ans Fachteam gehen.",
                    },
                },
                "required": ["summary", "open_questions"],
                "additionalProperties": False,
            },
        },
    ]


@dataclass
class ObjectiveProgress:
    status: str
    note: str


@dataclass
class TutorSession:
    module: Module
    session_id: str = field(default_factory=lambda: f"sess_{uuid.uuid4().hex[:8]}")
    started_at: datetime = field(default_factory=datetime.now)
    key_points: list[str] = field(default_factory=list)
    progress: dict[str, ObjectiveProgress] = field(default_factory=dict)
    finished: bool = False
    protocol_markdown: str | None = None

    def handle(self, name: str, args: dict) -> tuple[str, list[Event]]:
        """Führt ein Tool aus. Gibt das Ergebnis für das LLM und die Client-Events zurück."""
        handler = getattr(self, f"_tool_{name}", None)
        if handler is None:
            return f"Unbekanntes Werkzeug: {name}", []
        try:
            return handler(**args)
        except TypeError as exc:
            return f"Ungültige Argumente für {name}: {exc}", []

    def _tool_show_key_point(self, text: str) -> tuple[str, list[Event]]:
        text = text.strip()
        if not text:
            return "Leere Kernaussage, nichts angezeigt.", []
        if text not in self.key_points:
            self.key_points.append(text)
        return "Kernaussage wird angezeigt.", [(TOPIC_KEY_POINT, {"type": "key_point", "text": text})]

    def _tool_show_source(self, section_id: str) -> tuple[str, list[Event]]:
        section = self.module.section(section_id)
        if section is None:
            return f"Abschnitt '{section_id}' gibt es nicht.", []
        payload = {
            "type": "sources",
            "sources": [
                {
                    "doc_id": self.module.id,
                    "section_id": section.id,
                    "title": f"{self.module.title}: {section.title}",
                    "version": self.module.version,
                    "snippet": section.text,
                }
            ],
        }
        return "Quelle wird angezeigt.", [(TOPIC_SOURCES, payload)]

    def _tool_mark_objective(self, objective_id: str, status: str, note: str = "") -> tuple[str, list[Event]]:
        if self.module.objective(objective_id) is None:
            return f"Lernziel '{objective_id}' gibt es nicht.", []
        if status not in STATUS_LABELS:
            return f"Unbekannter Status '{status}'.", []
        self.progress[objective_id] = ObjectiveProgress(status=status, note=note.strip())
        open_ids = [o.id for o in self.module.objectives if o.id not in self.progress]
        result = "Festgehalten."
        if open_ids:
            result += " Noch offen: " + ", ".join(open_ids) + "."
        else:
            result += " Alle Lernziele sind bearbeitet."
        return result, [(TOPIC_PROGRESS, self.progress_payload())]

    def _tool_finish_session(self, summary: str, open_questions: list[str] | None = None) -> tuple[str, list[Event]]:
        self.finished = True
        self.protocol_markdown = self.render_protocol(summary, open_questions or [])
        event = {"type": "session_protocol", "sessionId": self.session_id, "markdown": self.protocol_markdown}
        return (
            "Lernprotokoll erstellt und angezeigt. Verabschiede dich jetzt kurz und weise auf den "
            "Wissenscheck auf dem Bildschirm hin.",
            [(TOPIC_PROTOCOL, event)],
        )

    def progress_payload(self) -> dict:
        return {
            "type": "progress",
            "objectives": [
                {
                    "id": o.id,
                    "text": o.text,
                    "status": self.progress[o.id].status if o.id in self.progress else "offen",
                }
                for o in self.module.objectives
            ],
        }

    def render_protocol(self, summary: str, open_questions: list[str]) -> str:
        m = self.module
        lines = [
            f"# Lernprotokoll: {m.title}",
            "",
            f"Datum: {self.started_at:%d.%m.%Y, %H:%M} Uhr · Modulstand: {m.version} · Sitzung: {self.session_id}",
            "",
            "## Lernziele",
        ]
        for o in m.objectives:
            p = self.progress.get(o.id)
            status = STATUS_LABELS[p.status] if p else "nicht behandelt"
            line = f"- **{status}:** {o.text}"
            if p and p.note:
                line += f" ({p.note})"
            lines.append(line)
        if self.key_points:
            lines += ["", "## Das solltest du dir merken"]
            lines += [f"- {k}" for k in self.key_points]
        lines += ["", "## Zusammenfassung", summary.strip() or "(keine)"]
        questions = [q.strip() for q in open_questions if q.strip()]
        if questions:
            lines += ["", "## Offene Fragen"]
            lines += [f"- {q}" for q in questions]
            if m.owner_contact:
                lines.append(f"\nAnsprechpartner: {m.owner} ({m.owner_contact})")
        return "\n".join(lines) + "\n"
