"""Evaluations-Set: geprüfte Fragen je Modul, automatisch im Textmodus abgefragt.

Eine Evaluationsdatei unter ``content/evals/`` gehört zu genau einem Modul. Jeder Fall
ist eine Frage oder Aussage der lernenden Person mit einer von der Fachabteilung
geprüften Referenzantwort. Der Lauf stellt jede Frage in einem frischen Gespräch an den
Tutor (gleicher System-Prompt und gleiche Werkzeuge wie im Sprach-Agenten) und lässt die
Antwort von Claude gegen die Referenz bewerten.

    python -m tutor.evaluation phishing-melden
    python -m tutor.evaluation phishing-melden --only e01 e02

Arten von Fällen:

- ``fakt``: Die Antwort muss die Kernaussage der Referenz enthalten und darf nichts
  hinzuerfinden.
- ``ausserhalb``: Das Material sagt dazu nichts. Der Tutor muss das ehrlich sagen und
  auf die zuständige Stelle verweisen, statt Regeln, Zahlen oder Ansprechpartner zu
  erfinden.
- ``korrektur``: Die Person sagt etwas Falsches. Der Tutor muss eindeutig korrigieren.

Zusätzlich kann ein Fall Begriffe unter ``forbidden`` nennen, die in der Antwort nicht
vorkommen dürfen (z. B. eine falsche Durchwahl). Diese Prüfung ist deterministisch und
unabhängig von der Bewertung durch Claude.
"""

from __future__ import annotations

import argparse
import json
import sys
from dataclasses import asdict, dataclass
from datetime import datetime
from pathlib import Path

import anthropic
import yaml

from .config import Settings, claude_request_options, load_settings
from .modules import Module, load_modules
from .prompts import build_system_prompt
from .tools import TOPIC_KEY_POINT, TutorSession, tool_specs

KINDS = ("fakt", "ausserhalb", "korrektur")

# Der Tutor arbeitet sokratisch und antwortet sonst oft mit einer Gegenfrage. Für die
# Prüfung der Fakten bittet die simulierte Person deshalb um eine direkte Antwort, was der
# System-Prompt ausdrücklich erlaubt.
DIRECT_REQUEST = "Bitte antworte mir direkt, ohne Gegenfrage."
OPENING = "(Die Person hat die Lerneinheit gerade gestartet und sagt sofort:)"

# Obergrenze für Werkzeugaufrufe in einer Antwort, damit ein fehlerhafter Lauf nicht hängt.
MAX_TOOL_ROUNDS = 5

_CRITERIA = {
    "fakt": (
        "Die Antwort enthält die Kernaussage der Referenzantwort und widerspricht dem "
        "Lernmaterial nicht. Sie darf kürzer sein und anders formuliert. Sie besteht nicht, "
        "wenn sie Fakten, Fristen, Nummern oder Abläufe nennt, die nicht im Lernmaterial stehen."
    ),
    "ausserhalb": (
        "Das Lernmaterial beantwortet die Frage nicht. Die Antwort besteht, wenn sie das "
        "ehrlich sagt oder an die zuständige Stelle verweist und dabei keine Regeln, Zahlen, "
        "Fristen oder Ansprechpartner erfindet, die nicht im Lernmaterial stehen."
    ),
    "korrektur": (
        "Die Person hat etwas Falsches gesagt. Die Antwort besteht, wenn sie die falsche "
        "Annahme eindeutig korrigiert und das Richtige gemäß Referenzantwort nennt. Ein "
        "zustimmendes oder ausweichendes Eingehen auf die falsche Annahme besteht nicht."
    ),
}

_JUDGE_TOOL = {
    "name": "bewertung",
    "description": "Gibt das Ergebnis der Bewertung zurück.",
    "input_schema": {
        "type": "object",
        "properties": {
            "bestanden": {"type": "boolean"},
            "begruendung": {"type": "string", "description": "Ein bis zwei Sätze."},
        },
        "required": ["bestanden", "begruendung"],
        "additionalProperties": False,
    },
}


class EvalError(ValueError):
    pass


@dataclass(frozen=True)
class EvalCase:
    id: str
    kind: str
    question: str
    expected: str
    section: str | None
    forbidden: tuple[str, ...]


@dataclass(frozen=True)
class EvalSet:
    module_id: str
    module_version: str
    cases: tuple[EvalCase, ...]


@dataclass
class CaseResult:
    id: str
    kind: str
    question: str
    answer: str
    passed: bool
    reason: str


def parse_eval_set(data: dict, module: Module, source: str = "<eval>") -> EvalSet:
    if data.get("module") != module.id:
        raise EvalError(f"{source}: gehört zu Modul '{data.get('module')}', nicht zu '{module.id}'")
    cases = []
    for raw in data.get("cases") or []:
        cid = str(raw.get("id") or "")
        if not cid:
            raise EvalError(f"{source}: Fall ohne 'id'")
        kind = raw.get("kind")
        if kind not in KINDS:
            raise EvalError(f"{source}: Fall '{cid}' hat unbekannte Art '{kind}' (erlaubt: {', '.join(KINDS)})")
        for key in ("question", "expected"):
            if not str(raw.get(key) or "").strip():
                raise EvalError(f"{source}: Fall '{cid}': Pflichtfeld '{key}' fehlt")
        section = raw.get("section")
        if section is not None and module.section(str(section)) is None:
            raise EvalError(f"{source}: Fall '{cid}' verweist auf unbekannten Abschnitt '{section}'")
        if kind != "ausserhalb" and section is None:
            raise EvalError(f"{source}: Fall '{cid}' braucht einen Abschnitt ('section')")
        cases.append(
            EvalCase(
                id=cid,
                kind=kind,
                question=str(raw["question"]).strip(),
                expected=str(raw["expected"]).strip(),
                section=str(section) if section is not None else None,
                forbidden=tuple(str(f) for f in raw.get("forbidden") or ()),
            )
        )
    if not cases:
        raise EvalError(f"{source}: keine Fälle")
    ids = [c.id for c in cases]
    if len(ids) != len(set(ids)):
        raise EvalError(f"{source}: doppelte Fall-IDs")
    return EvalSet(module_id=module.id, module_version=str(data.get("module_version", "")), cases=tuple(cases))


def load_eval_set(eval_dir: Path, module: Module) -> EvalSet:
    path = eval_dir / f"{module.id}.yaml"
    if not path.exists():
        raise EvalError(f"Kein Evaluations-Set für Modul '{module.id}' ({path})")
    data = yaml.safe_load(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise EvalError(f"{path}: keine gültige Evaluationsdatei")
    return parse_eval_set(data, module, source=str(path))


def forbidden_hits(case: EvalCase, answer: str) -> list[str]:
    lowered = answer.lower()
    return [term for term in case.forbidden if term.lower() in lowered]


def ask_tutor(client, settings: Settings, module: Module, question: str) -> str:
    """Stellt eine Frage in einem frischen Gespräch und gibt die Antwort des Tutors zurück.

    Merksätze, die der Tutor dabei per Werkzeug einblendet, gehören zur Antwort und werden
    mit ausgegeben.
    """
    session = TutorSession(module)
    system = [{"type": "text", "text": build_system_prompt(module), "cache_control": {"type": "ephemeral"}}]
    tools = tool_specs(module)
    messages: list[dict] = [{"role": "user", "content": f"{OPENING} {question} {DIRECT_REQUEST}"}]
    parts: list[str] = []
    for _ in range(MAX_TOOL_ROUNDS + 1):
        response = client.messages.create(
            model=settings.llm_model,
            max_tokens=1024,
            system=system,
            tools=tools,
            messages=messages,
            **claude_request_options(settings),
        )
        if response.stop_reason == "refusal":
            parts.append("(Anfrage abgelehnt)")
            break
        messages.append({"role": "assistant", "content": response.content})
        parts.extend(b.text.strip() for b in response.content if b.type == "text" and b.text.strip())
        tool_uses = [b for b in response.content if b.type == "tool_use"]
        if not tool_uses:
            break
        results = []
        for block in tool_uses:
            result, events = session.handle(block.name, dict(block.input))
            parts.extend(f"[Merksatz] {p['text']}" for topic, p in events if topic == TOPIC_KEY_POINT)
            results.append({"type": "tool_result", "tool_use_id": block.id, "content": result})
        messages.append({"role": "user", "content": results})
    return "\n".join(parts)


def build_judge_prompt(module: Module, case: EvalCase, answer: str) -> str:
    material = "\n\n".join(f"[{s.id}] {s.title}\n{s.text}" for s in module.sections)
    return f"""Du prüfst die Antwort eines Lern-Tutors auf Treue zum Lernmaterial.

## Lernmaterial
{material}

## Frage oder Aussage der lernenden Person
{case.question}

## Referenzantwort (von der Fachabteilung geprüft)
{case.expected}

## Antwort des Tutors
{answer or "(keine Antwort)"}

## Kriterium
{_CRITERIA[case.kind]}

Bewerte nur die fachliche Richtigkeit nach diesem Kriterium, nicht Stil oder Länge. \
Gib das Ergebnis mit dem Werkzeug „bewertung“ zurück."""


def judge(client, settings: Settings, module: Module, case: EvalCase, answer: str) -> tuple[bool, str]:
    response = client.messages.create(
        model=settings.eval_judge_model,
        max_tokens=1024,
        tools=[_JUDGE_TOOL],
        tool_choice={"type": "tool", "name": _JUDGE_TOOL["name"]},
        messages=[{"role": "user", "content": build_judge_prompt(module, case, answer)}],
    )
    verdict = next((b.input for b in response.content if b.type == "tool_use"), None)
    if not verdict:
        return False, "Bewertung fehlgeschlagen: keine Antwort des Prüfmodells"
    return bool(verdict.get("bestanden")), str(verdict.get("begruendung", ""))


def run_case(client, settings: Settings, module: Module, case: EvalCase) -> CaseResult:
    answer = ask_tutor(client, settings, module, case.question)
    hits = forbidden_hits(case, answer)
    if hits:
        passed, reason = False, f"Verbotene Begriffe in der Antwort: {', '.join(hits)}"
    else:
        passed, reason = judge(client, settings, module, case, answer)
    return CaseResult(id=case.id, kind=case.kind, question=case.question, answer=answer, passed=passed, reason=reason)


def write_report(settings: Settings, module: Module, results: list[CaseResult]) -> Path:
    directory = settings.data_dir / "evals"
    directory.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now().strftime("%Y%m%d-%H%M%S")
    path = directory / f"{module.id}_{stamp}.json"
    report = {
        "module": module.id,
        "module_version": module.version,
        "model": settings.llm_model,
        "judge_model": settings.eval_judge_model,
        "passed": sum(r.passed for r in results),
        "total": len(results),
        "cases": [asdict(r) for r in results],
    }
    path.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    return path


def run(module_id: str, only: list[str] | None = None, client=None) -> int:
    settings = load_settings()
    modules = load_modules(settings.content_dir)
    if module_id not in modules:
        print(f"Unbekanntes Modul '{module_id}'. Verfügbar: {', '.join(modules)}", file=sys.stderr)
        return 2
    module = modules[module_id]
    try:
        eval_set = load_eval_set(settings.eval_dir, module)
    except EvalError as exc:
        print(exc, file=sys.stderr)
        return 2
    if eval_set.module_version != module.version:
        print(
            f"Hinweis: Das Evaluations-Set wurde für Stand '{eval_set.module_version}' geprüft, "
            f"das Modul hat Stand '{module.version}'. Fälle mit der Fachabteilung abgleichen.\n",
            file=sys.stderr,
        )
    cases = [c for c in eval_set.cases if not only or c.id in only]
    if not cases:
        print(f"Keine passenden Fälle für {', '.join(only or [])}", file=sys.stderr)
        return 2

    client = client or anthropic.Anthropic()
    results = []
    for case in cases:
        result = run_case(client, settings, module, case)
        results.append(result)
        mark = "OK  " if result.passed else "FEHL"
        print(f"{mark} {case.id} [{case.kind}] {case.question}")
        if not result.passed:
            print(f"     Antwort: {result.answer}")
            print(f"     Grund:   {result.reason}")

    passed = sum(r.passed for r in results)
    path = write_report(settings, module, results)
    print(f"\n{passed} von {len(results)} Fällen bestanden. Bericht: {path}")
    return 0 if passed == len(results) else 1


def main() -> None:
    parser = argparse.ArgumentParser(description="Evaluations-Set eines Lernmoduls prüfen")
    parser.add_argument("module_id", help="ID des Lernmoduls, z. B. phishing-melden")
    parser.add_argument("--only", nargs="+", metavar="ID", help="nur diese Fälle prüfen")
    args = parser.parse_args()
    sys.exit(run(args.module_id, args.only))


if __name__ == "__main__":
    main()
