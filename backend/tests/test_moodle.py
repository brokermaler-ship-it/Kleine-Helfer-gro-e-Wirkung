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
