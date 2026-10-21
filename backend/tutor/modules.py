"""Lernmodule laden und validieren.

Ein Modul ist eine YAML-Datei unter ``content/modules/``. Es enthält Lernziele, den
vollständigen SOP-Text (in Abschnitte gegliedert) und einen Wissenscheck. Der Tutor
bekommt den gesamten Text in den Kontext; es gibt bewusst kein RAG.
"""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

import yaml


class ModuleError(ValueError):
    pass


@dataclass(frozen=True)
class Objective:
    id: str
    text: str


@dataclass(frozen=True)
class Section:
    id: str
    title: str
    text: str


@dataclass(frozen=True)
class QuizQuestion:
    id: str
    objective: str
    question: str
    options: tuple[str, ...]
    correct: int
    explanation: str


@dataclass(frozen=True)
class Module:
    id: str
    title: str
    version: str
    owner: str
    owner_contact: str
    estimated_minutes: int
    objectives: tuple[Objective, ...]
    sections: tuple[Section, ...]
    quiz: tuple[QuizQuestion, ...]

    def objective(self, objective_id: str) -> Objective | None:
        return next((o for o in self.objectives if o.id == objective_id), None)

    def section(self, section_id: str) -> Section | None:
        return next((s for s in self.sections if s.id == section_id), None)


def _require(data: dict, key: str, source: str):
    if key not in data or data[key] in (None, ""):
        raise ModuleError(f"{source}: Pflichtfeld '{key}' fehlt")
    return data[key]


def parse_module(data: dict, source: str = "<modul>") -> Module:
    objectives = tuple(
        Objective(id=str(_require(o, "id", source)), text=str(_require(o, "text", source)).strip())
        for o in _require(data, "learning_objectives", source)
    )
    sections = tuple(
        Section(
            id=str(_require(s, "id", source)),
            title=str(_require(s, "title", source)),
            text=str(_require(s, "text", source)).strip(),
        )
        for s in _require(data, "sections", source)
    )
    objective_ids = {o.id for o in objectives}
    quiz = []
    for q in data.get("quiz") or []:
        qid = str(_require(q, "id", source))
        options = tuple(str(o) for o in _require(q, "options", source))
        correct = q.get("correct")
        if not isinstance(correct, int) or not 0 <= correct < len(options):
            raise ModuleError(f"{source}: Frage '{qid}' hat keinen gültigen Index für 'correct'")
        objective = str(_require(q, "objective", source))
        if objective not in objective_ids:
            raise ModuleError(f"{source}: Frage '{qid}' verweist auf unbekanntes Lernziel '{objective}'")
        quiz.append(
            QuizQuestion(
                id=qid,
                objective=objective,
                question=str(_require(q, "question", source)),
                options=options,
                correct=correct,
                explanation=str(q.get("explanation", "")),
            )
        )

    for kind, items in (("Lernziel", objectives), ("Abschnitt", sections), ("Frage", quiz)):
        ids = [i.id for i in items]
        if len(ids) != len(set(ids)):
            raise ModuleError(f"{source}: doppelte {kind}-IDs")

    return Module(
        id=str(_require(data, "id", source)),
        title=str(_require(data, "title", source)),
        version=str(_require(data, "version", source)),
        owner=str(_require(data, "owner", source)),
        owner_contact=str(data.get("owner_contact", "")),
        estimated_minutes=int(data.get("estimated_minutes", 10)),
        objectives=objectives,
        sections=sections,
        quiz=tuple(quiz),
    )


def load_module(path: Path) -> Module:
    data = yaml.safe_load(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise ModuleError(f"{path}: keine gültige Moduldatei")
    return parse_module(data, source=str(path))


def load_modules(*directories: Path) -> dict[str, Module]:
    """Lädt alle Module; Ordner, die es nicht gibt (z. B. ``content-intern``), werden übersprungen."""
    modules: dict[str, Module] = {}
    paths = [p for d in directories if d.is_dir() for p in sorted(d.glob("*.yaml"))]
    for path in paths:
        module = load_module(path)
        if module.id in modules:
            raise ModuleError(f"{path}: Modul-ID '{module.id}' ist doppelt vergeben")
        modules[module.id] = module
    return modules
