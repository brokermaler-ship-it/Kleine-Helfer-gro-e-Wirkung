"""Kurzer Fragebogen nach der Lerneinheit (PROJECT.md, Phase 3).

Fragt Verständlichkeit, Unterbrechungen beim Nachdenken und das Arbeitsumfeld ab.
Jede Frage hat feste Antwortoptionen; gespeichert wird der Index der gewählten Option.
Die Auswertung ist nur aggregiert, wie beim Wissenscheck.
"""

from __future__ import annotations

from collections import Counter
from dataclasses import dataclass


@dataclass(frozen=True)
class FeedbackQuestion:
    id: str
    text: str
    options: tuple[str, ...]
    # Bei einer Skala ist der Mittelwert der Optionsnummern (1 bis n) aussagekräftig.
    scale: bool = False


_SCALE = ("1 – trifft gar nicht zu", "2 – eher nicht", "3 – teils, teils", "4 – eher ja", "5 – trifft voll zu")

QUESTIONS: tuple[FeedbackQuestion, ...] = (
    FeedbackQuestion("verstaendlich", "Der Tutor war gut zu verstehen.", _SCALE, scale=True),
    FeedbackQuestion(
        "unterbrechungen",
        "Wie oft hat dich der Tutor unterbrochen, während du noch nachgedacht hast?",
        ("nie", "einmal", "zweimal", "dreimal oder öfter"),
    ),
    FeedbackQuestion(
        "umgebung",
        "Wo hast du die Lerneinheit gemacht?",
        ("Einzelbüro", "Großraumbüro", "Homeoffice", "anderswo"),
    ),
    FeedbackQuestion("headset", "Hast du ein Headset benutzt?", ("ja", "nein")),
    FeedbackQuestion("sprechen_angenehm", "Es war mir angenehm, dort laut mit dem Tutor zu sprechen.", _SCALE, scale=True),
    FeedbackQuestion("wieder", "Ich würde so wieder lernen wollen.", _SCALE, scale=True),
)

COMMENT_MAX_LENGTH = 500


def validate_answers(answers: dict[str, int]) -> str | None:
    """Gibt eine Fehlermeldung zurück oder ``None``, wenn alle Fragen gültig beantwortet sind."""
    for q in QUESTIONS:
        value = answers.get(q.id)
        if not isinstance(value, int) or not 0 <= value < len(q.options):
            return f"Frage '{q.id}' fehlt oder hat keinen gültigen Wert"
    unknown = set(answers) - {q.id for q in QUESTIONS}
    if unknown:
        return f"Unbekannte Fragen: {', '.join(sorted(unknown))}"
    return None


def summarize(records: list[dict]) -> dict:
    """Häufigkeiten je Option und bei Skalen der Mittelwert, je Modul.

    Pro Person und Modul zählt nur die letzte Abgabe.
    """
    latest: dict[tuple[str, str], dict] = {}
    for r in records:
        latest[(r["module_id"], r["pilot_id"])] = r
    by_module: dict[str, list[dict]] = {}
    for (module_id, _), r in latest.items():
        by_module.setdefault(module_id, []).append(r)

    summary = {}
    for module_id, items in by_module.items():
        result: dict = {"teilnehmende": len(items), "fragen": {}}
        for q in QUESTIONS:
            values = [r["answers"][q.id] for r in items if q.id in r["answers"]]
            counts = Counter(values)
            entry: dict = {"text": q.text, "antworten": {opt: counts.get(i, 0) for i, opt in enumerate(q.options)}}
            if q.scale and values:
                entry["mittelwert"] = round(sum(v + 1 for v in values) / len(values), 2)
            result["fragen"][q.id] = entry
        # Ohne Pilot-Kennung, damit Kommentare nicht einzelnen Personen zugeordnet werden.
        result["kommentare"] = [r["comment"] for r in items if r.get("comment")]
        summary[module_id] = result
    return summary
