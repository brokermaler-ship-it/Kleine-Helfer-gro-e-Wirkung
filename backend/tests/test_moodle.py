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
    monkeypatch.setattr(builder, "synthesize", lambda text, voice, model, target, language="de": (calls.append(text), target.write_bytes(b"mp3")))
    archive = builder.build(ROOT / "content" / "modules" / "phishing-melden.yaml", "http://localhost:8000", tmp_path, True)
    with zipfile.ZipFile(archive) as zf:
        names = zf.namelist()
        manifest = zf.read("imsmanifest.xml").decode()
        index = zf.read("index.html").decode()
    assert len(calls) == 6 and calls[0].startswith("Modul 1: Typische Merkmale.")
    assert "audio/modul1-luisa.mp3" in names and 'href="audio/modul3-lukas.mp3"' in manifest
    assert index.count('<audio controls preload="none"') == 6


def test_scorm_package_english(tmp_path):
    builder = load_builder()
    source = (ROOT / "content" / "modules" / "phishing-melden.yaml").read_text(encoding="utf-8")
    module = tmp_path / "phishing-en.yaml"
    module.write_text(source.replace("id: phishing-melden", "id: phishing-en") + "\nlanguage: en\n", encoding="utf-8")
    archive = builder.build(module, "https://tutor.example/", tmp_path / "out")
    with zipfile.ZipFile(archive) as zf:
        index = zf.read("index.html").decode()
    assert '<html lang="en"' in index and "Knowledge check, module 1" in index
    assert "data-tutor=" not in index and "Lerngespräch" not in index
