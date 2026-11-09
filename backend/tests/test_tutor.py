import json
from dataclasses import replace

import pytest
from fastapi.testclient import TestClient

from tutor.config import load_settings
from tutor.modules import ModuleError, load_modules, parse_module
from tutor.prompts import build_system_prompt
from tutor.server import create_app
from tutor.tools import TOPIC_KEY_POINT, TOPIC_PROGRESS, TOPIC_PROTOCOL, TOPIC_SOURCES, TutorSession, tool_specs


@pytest.fixture
def module():
    return load_modules(load_settings().content_dir)["phishing-melden"]


@pytest.fixture
def client(tmp_path):
    settings = replace(load_settings(), data_dir=tmp_path)
    return TestClient(create_app(settings))


def test_example_module_is_valid(module):
    assert [o.id for o in module.objectives] == ["erkennen", "melden", "notfall"]
    assert {q.objective for q in module.quiz} == {"erkennen", "melden", "notfall"}


def test_parse_module_rejects_bad_quiz_index():
    data = {
        "id": "m", "title": "T", "version": "1", "owner": "O",
        "learning_objectives": [{"id": "a", "text": "x"}],
        "sections": [{"id": "s", "title": "S", "text": "t"}],
        "quiz": [{"id": "q", "objective": "a", "question": "?", "options": ["x", "y"], "correct": 2}],
    }
    with pytest.raises(ModuleError, match="correct"):
        parse_module(data)
    data["quiz"][0].update(correct=0, objective="unbekannt")
    with pytest.raises(ModuleError, match="unbekanntes Lernziel"):
        parse_module(data)


def test_system_prompt_contains_full_material(module):
    prompt = build_system_prompt(module)
    for section in module.sections:
        assert section.text in prompt
    for objective in module.objectives:
        assert f"[{objective.id}]" in prompt
    assert build_system_prompt(module) == prompt  # stabil für Prompt-Caching


def test_tool_specs_are_strict_and_enumerate_ids(module):
    specs = {s["name"]: s for s in tool_specs(module)}
    assert set(specs) == {"show_key_point", "show_source", "mark_objective", "finish_session"}
    for spec in specs.values():
        schema = spec["input_schema"]
        assert schema["additionalProperties"] is False
        assert set(schema["required"]) == set(schema["properties"])
    assert specs["show_source"]["input_schema"]["properties"]["section_id"]["enum"] == ["s1", "s2", "s3"]


def test_session_tools_produce_events_and_protocol(module):
    session = TutorSession(module)

    _, events = session.handle("show_key_point", {"text": "Verdächtige E-Mails über die Schaltfläche melden."})
    assert events == [(TOPIC_KEY_POINT, {"type": "key_point", "text": "Verdächtige E-Mails über die Schaltfläche melden."})]

    _, events = session.handle("show_source", {"section_id": "s3"})
    assert events[0][0] == TOPIC_SOURCES
    assert "4444" in events[0][1]["sources"][0]["snippet"]

    result, events = session.handle("mark_objective", {"objective_id": "melden", "status": "verstanden", "note": ""})
    assert "Noch offen: erkennen, notfall" in result
    assert events[0][0] == TOPIC_PROGRESS
    session.handle("mark_objective", {"objective_id": "notfall", "status": "unsicher", "note": "15-Minuten-Ziel vergessen"})

    _, events = session.handle("finish_session", {"summary": "Gut gelaufen.", "open_questions": ["Gilt das auch am Handy?"]})
    topic, payload = events[0]
    assert topic == TOPIC_PROTOCOL and session.finished
    md = payload["markdown"]
    assert "**verstanden:** Den korrekten Meldeweg" in md
    assert "**noch unsicher:**" in md and "15-Minuten-Ziel vergessen" in md
    assert "**nicht behandelt:** Drei typische Merkmale" in md
    assert "Gilt das auch am Handy?" in md


def test_session_tools_reject_unknown_ids(module):
    session = TutorSession(module)
    assert session.handle("show_source", {"section_id": "s9"}) == ("Abschnitt 's9' gibt es nicht.", [])
    assert session.handle("mark_objective", {"objective_id": "x", "status": "verstanden", "note": ""})[1] == []
    assert session.handle("nope", {})[0].startswith("Unbekanntes Werkzeug")
    assert session.handle("show_key_point", {"falsch": 1})[0].startswith("Ungültige Argumente")


def test_quiz_hides_solutions_and_scores(client, tmp_path):
    questions = client.get("/api/modules/phishing-melden/quiz").json()
    assert questions and all("correct" not in q for q in questions)

    answers = {q["id"]: 1 for q in questions}
    res = client.post(
        "/api/modules/phishing-melden/quiz",
        json={"pilot_id": "P-001", "phase": "vorher", "answers": answers},
    ).json()
    assert res["total"] == 6 and res["score"] == 4

    lines = (tmp_path / "quiz_results.jsonl").read_text().splitlines()
    record = json.loads(lines[0])
    assert record["pilot_id"] == "P-001" and record["phase"] == "vorher"


def test_quiz_rejects_names_as_pilot_id(client):
    res = client.post(
        "/api/modules/phishing-melden/quiz",
        json={"pilot_id": "Max Mustermann", "phase": "vorher", "answers": {}},
    )
    assert res.status_code == 422


def test_pilot_summary_uses_latest_attempt_per_group(client, tmp_path):
    def submit(pilot, phase, answer, group=None):
        body = {"pilot_id": pilot, "phase": phase, "answers": {f"q{i}": answer for i in range(1, 7)}}
        if group:
            body["group"] = group
        assert client.post("/api/modules/phishing-melden/quiz", json=body).status_code == 200

    submit("P-001", "vorher", 0)   # 1 von 6
    submit("P-001", "nachher", 3)  # 0 von 6, wird überschrieben
    submit("P-001", "nachher", 1)  # 4 von 6
    submit("V-001", "nachher", 0, group="vergleich")  # 1 von 6
    # Datensatz aus der Zeit vor den Gruppen zählt zur Tutor-Gruppe
    with (tmp_path / "quiz_results.jsonl").open("a") as f:
        f.write(json.dumps({"pilot_id": "P-002", "module_id": "phishing-melden", "phase": "vorher", "score": 3, "total": 6}) + "\n")

    summary = client.get("/api/pilot/summary").json()["phishing-melden"]
    assert summary["tutor"]["vorher"] == {"teilnehmende": 2, "quote_richtig": round((1 / 6 + 3 / 6) / 2, 3)}
    assert summary["tutor"]["nachher"] == {"teilnehmende": 1, "quote_richtig": round(4 / 6, 3)}
    assert summary["vergleich"] == {"nachher": {"teilnehmende": 1, "quote_richtig": round(1 / 6, 3)}}

    bad = {"pilot_id": "P-003", "phase": "vorher", "group": "andere", "answers": {}}
    assert client.post("/api/modules/phishing-melden/quiz", json=bad).status_code == 422


def test_session_token_dispatches_agent_with_module(client):
    from livekit import api

    res = client.post("/api/session", json={"module_id": "phishing-melden", "pilot_id": "P-001"}).json()
    claims = api.TokenVerifier("devkey", "secret").verify(res["token"])
    assert claims.video.room == res["room"] and claims.video.room_join
    dispatch = claims.room_config.agents[0]
    assert dispatch.agent_name == "voice-tutor"
    assert json.loads(dispatch.metadata) == {"module_id": "phishing-melden", "pilot_id": "P-001"}

    assert client.post("/api/session", json={"module_id": "gibtsnicht", "pilot_id": "P-001"}).status_code == 404


def test_percentile_interpolates_like_numpy():
    from tutor.metrics import percentile

    assert percentile([], 50) is None
    assert percentile([1.0], 95) == 1.0
    assert percentile([4.0, 1.0, 3.0, 2.0], 50) == 2.5
    assert percentile([float(i) for i in range(1, 21)], 95) == pytest.approx(19.05)


def test_pilot_latency_separates_thinking_pauses(client, tmp_path):
    def session(finished, *pairs):
        turns = []
        for eot, e2e, ttft, ttfb in pairs:
            turns.append({"role": "user", "end_of_turn_delay": eot, "transcription_delay": 0.1})
            turns.append({"role": "assistant", "e2e_latency": e2e, "llm_node_ttft": ttft, "tts_node_ttfb": ttfb})
        return {"module_id": "phishing-melden", "finished": finished, "turns": turns}

    records = [
        # Begrüßung ohne vorherige Nachricht der Person, ohne e2e_latency
        {"module_id": "phishing-melden", "finished": True, "turns": [
            {"role": "assistant", "llm_node_ttft": 0.2, "tts_node_ttfb": 0.1},
            *session(True, (0.5, 0.8, 0.2, 0.1), (0.6, 0.9, 0.25, 0.1))["turns"],
        ]},
        session(False, (0.7, 1.2, 0.3, 0.15), (3.0, 3.6, 0.3, 0.1)),  # zweiter Wechsel: Nachdenkpause
    ]
    (tmp_path / "sessions.jsonl").write_text("\n".join(json.dumps(r) for r in records) + "\n")

    summary = client.get("/api/pilot/latency").json()["phishing-melden"]
    assert summary["sitzungen"] == 2 and summary["abschlussquote"] == 0.5
    assert summary["e2e_latency_ms"] == {"n": 3, "p50": 900, "p95": 1170}
    assert summary["reaktionszeit_ms"]["n"] == 4  # Begrüßung zählt mit, Nachdenkpause nicht
    assert summary["reaktionszeit_ms"]["p50"] == 325
    assert summary["nachdenkpausen"] == {"anzahl": 1, "e2e_latency_ms": {"n": 1, "p50": 3600, "p95": 3600}}
    assert summary["ziele_erreicht"] == {"e2e_p50": True, "e2e_p95": True, "reaktionszeit_p50": True}


def test_pilot_latency_without_sessions(client):
    assert client.get("/api/pilot/latency").json() == {}


def test_feedback_is_validated_and_summarized(client):
    questions = client.get("/api/feedback/questions").json()
    ids = [q["id"] for q in questions]
    assert "unterbrechungen" in ids and "verstaendlich" in ids

    def submit(pilot, value, comment=""):
        return client.post(
            "/api/modules/phishing-melden/feedback",
            json={"pilot_id": pilot, "answers": {i: value for i in ids}, "comment": comment},
        )

    assert submit("P-001", 0).status_code == 200
    assert submit("P-001", 1, "Gut verständlich.").status_code == 200  # ersetzt die erste Abgabe
    assert submit("P-002", 0).status_code == 200
    assert submit("P-003", 9).status_code == 422
    assert submit("Max Mustermann", 0).status_code == 422
    assert submit("P-004", 0, "x" * 501).status_code == 422
    missing = client.post("/api/modules/phishing-melden/feedback", json={"pilot_id": "P-005", "answers": {}})
    assert missing.status_code == 422

    summary = client.get("/api/pilot/feedback").json()["phishing-melden"]
    assert summary["teilnehmende"] == 2
    assert summary["fragen"]["unterbrechungen"]["antworten"] == {"nie": 1, "einmal": 1, "zweimal": 0, "dreimal oder öfter": 0}
    assert summary["fragen"]["verstaendlich"]["mittelwert"] == 1.5
    assert "mittelwert" not in summary["fragen"]["umgebung"]
    assert summary["kommentare"] == ["Gut verständlich."]
