import importlib.util
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def load_builder():
    spec = importlib.util.spec_from_file_location("build_scorm", ROOT / "moodle" / "build_scorm.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def test_scorm_package(tmp_path):
    builder = load_builder()
    archive = builder.build(ROOT / "content" / "modules" / "phishing-melden.yaml", "https://tutor.example/", tmp_path)
    with zipfile.ZipFile(archive) as zf:
        names = zf.namelist()
        manifest = zf.read("imsmanifest.xml").decode()
        index = zf.read("index.html").decode()
    assert "imsmanifest.xml" in names and all("/" not in n for n in names)
    assert all(f'href="{n}"' in manifest for n in names if n != "imsmanifest.xml")
    assert 'data-tutor-url="https://tutor.example"' in index
    assert 'data-tutor="lukas"' in index and 'data-tutor="luisa"' in index
    assert index.count('class="ls-module"') == 3 and index.count('class="ls-qz"') == 6


def test_scorm_package_with_audio(tmp_path, monkeypatch):
    builder = load_builder()
    calls = []
    monkeypatch.setattr(builder, "synthesize", lambda text, voice, model, target: (calls.append(text), target.write_bytes(b"mp3")))
    archive = builder.build(ROOT / "content" / "modules" / "phishing-melden.yaml", "http://localhost:8000", tmp_path, True)
    with zipfile.ZipFile(archive) as zf:
        names = zf.namelist()
        manifest = zf.read("imsmanifest.xml").decode()
        index = zf.read("index.html").decode()
    assert len(calls) == 6 and calls[0].startswith("Modul 1: Typische Merkmale.")
    assert "audio/modul1-luisa.mp3" in names and 'href="audio/modul3-lukas.mp3"' in manifest
    assert index.count('<audio controls preload="none"') == 6


def test_quiz_answers_are_shuffled_and_balanced():
    builder = load_builder()
    from tutor.modules import load_module

    module = load_module(ROOT / "content" / "modules" / "phishing-melden.yaml")
    order = builder.shuffled_quiz(module)
    assert order == builder.shuffled_quiz(module)  # gleicher Build, gleiche Reihenfolge
    positions = [correct for _, correct in order.values()]
    assert max(positions.count(p) for p in range(4)) - min(positions.count(p) for p in range(4)) <= 1
    for q in module.quiz:
        options, correct = order[q.id]
        assert sorted(options) == sorted(q.options) and options[correct] == q.options[q.correct]


def test_quiz_warnings_detect_longest_answer():
    builder = load_builder()
    from tutor.modules import parse_module

    quiz = [
        {"id": f"q{i}", "objective": "o", "question": "Was tun?", "options": ["Nein", "Ja, sofort und ausführlich melden", "Egal", "Später"],
         "correct": 1, "explanation": "Melden."}
        for i in range(4)
    ]
    module = parse_module({
        "id": "test", "title": "Test", "version": "1", "owner": "Team", "estimated_minutes": 5,
        "learning_objectives": [{"id": "o", "text": "Ziel"}],
        "sections": [{"id": "s1", "title": "Abschnitt", "text": "Text"}],
        "quiz": quiz,
    })
    warnings = builder.quiz_warnings(module)
    assert "4 von 4" in warnings[0] and len(warnings) == 5
