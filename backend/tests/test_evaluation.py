from dataclasses import replace
from types import SimpleNamespace

import pytest

from tutor import evaluation
from tutor.config import load_settings
from tutor.evaluation import EvalError, load_eval_set, parse_eval_set, run_case
from tutor.modules import load_modules


@pytest.fixture
def settings(tmp_path):
    return replace(load_settings(), data_dir=tmp_path)


@pytest.fixture
def module(settings):
    return load_modules(settings.content_dir)["phishing-melden"]


def text(value):
    return SimpleNamespace(type="text", text=value)


def tool_use(name, args, block_id="tu_1"):
    return SimpleNamespace(type="tool_use", name=name, input=args, id=block_id)


def response(*blocks, stop_reason="end_turn"):
    return SimpleNamespace(content=list(blocks), stop_reason=stop_reason)


class FakeClient:
    """Gibt die vorbereiteten Antworten der Reihe nach zurück und merkt sich die Anfragen."""

    def __init__(self, *responses):
        self._responses = list(responses)
        self.requests = []
        self.messages = SimpleNamespace(create=self._create)

    def _create(self, **kwargs):
        self.requests.append(kwargs)
        return self._responses.pop(0)


def test_example_eval_set_is_valid_and_matches_module(settings, module):
    eval_set = load_eval_set(settings.eval_dir, module)
    assert 20 <= len(eval_set.cases) <= 30  # PROJECT.md, Phase 1
    assert eval_set.module_version == module.version
    assert {c.kind for c in eval_set.cases} == set(evaluation.KINDS)
    assert {c.section for c in eval_set.cases if c.section} == {s.id for s in module.sections}


def test_parse_eval_set_rejects_bad_cases(module):
    case = {"id": "e1", "kind": "fakt", "section": "s1", "question": "?", "expected": "!"}
    assert parse_eval_set({"module": module.id, "cases": [case]}, module).cases[0].id == "e1"
    with pytest.raises(EvalError, match="gehört zu Modul"):
        parse_eval_set({"module": "anderes", "cases": [case]}, module)
    with pytest.raises(EvalError, match="unbekannte Art"):
        parse_eval_set({"module": module.id, "cases": [{**case, "kind": "quiz"}]}, module)
    with pytest.raises(EvalError, match="unbekannten Abschnitt"):
        parse_eval_set({"module": module.id, "cases": [{**case, "section": "s9"}]}, module)
    with pytest.raises(EvalError, match="braucht einen Abschnitt"):
        parse_eval_set({"module": module.id, "cases": [{**case, "section": None}]}, module)
    with pytest.raises(EvalError, match="doppelte"):
        parse_eval_set({"module": module.id, "cases": [case, case]}, module)


def test_run_case_handles_tools_and_uses_judge(settings, module):
    case = load_eval_set(settings.eval_dir, module).cases[0]
    client = FakeClient(
        response(text("Achte auf Zeitdruck."), tool_use("show_key_point", {"text": "Zeitdruck ist ein Warnsignal."}),
                 stop_reason="tool_use"),
        response(text("Und auf unerwartete Anhänge.")),
        response(tool_use("bewertung", {"bestanden": True, "begruendung": "Kernaussage enthalten."})),
    )
    result = run_case(client, settings, module, case)

    assert result.passed and result.reason == "Kernaussage enthalten."
    assert "[Merksatz] Zeitdruck ist ein Warnsignal." in result.answer
    assert "unerwartete Anhänge" in result.answer
    tutor_request, follow_up, judge_request = client.requests
    assert evaluation.DIRECT_REQUEST in tutor_request["messages"][0]["content"]
    assert follow_up["messages"][2]["content"][0]["type"] == "tool_result"
    assert judge_request["model"] == settings.eval_judge_model
    assert judge_request["tool_choice"] == {"type": "tool", "name": "bewertung"}
    assert "Unerwartete Anhänge" in judge_request["messages"][0]["content"]  # Referenz im Prompt


def test_forbidden_terms_fail_without_judge(settings, module):
    case = next(c for c in load_eval_set(settings.eval_dir, module).cases if c.id == "e13")
    client = FakeClient(response(text("Ruf die Durchwahl 4445 an.")))
    result = run_case(client, settings, module, case)
    assert not result.passed and "4445" in result.reason
    assert len(client.requests) == 1


def test_run_writes_report_and_returns_exit_code(settings, module, monkeypatch, capsys):
    monkeypatch.setattr(evaluation, "load_settings", lambda: settings)
    client = FakeClient(
        response(text("Über die Schaltfläche „Phishing melden“ in Outlook.")),
        response(tool_use("bewertung", {"bestanden": True, "begruendung": "ok"})),
        response(text("Lösch sie einfach.")),
        response(tool_use("bewertung", {"bestanden": False, "begruendung": "falsch"})),
    )
    assert evaluation.run("phishing-melden", only=["e07", "e21"], client=client) == 1
    out = capsys.readouterr().out
    assert "1 von 2 Fällen bestanden" in out
    assert len(list((settings.data_dir / "evals").glob("phishing-melden_*.json"))) == 1
    assert evaluation.run("unbekannt", client=client) == 2
