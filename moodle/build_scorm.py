"""Baut aus einer Moduldatei (YAML) ein SCORM-1.2-Lernpaket für Moodle.

    python moodle/build_scorm.py content/modules/phishing-melden.yaml --tutor-url http://localhost:8000

Lernziel n, Abschnitt n und die Fragen zu Lernziel n bilden zusammen Modul n des Lernskripts.
Modul 1 ist offen, jedes weitere wird ab 70 % im Quiz des Vormoduls freigeschaltet.
Am Ende steht das Lerngespräch: ein Knopf je Tutor, der den Voice-Tutor in einem neuen
Fenster öffnet (``?kennung=…&modul=…&tutor=…&direkt=1``).

Ergebnis: ``moodle/dist/<modul-id>/`` und ``moodle/dist/<modul-id>_SCORM.zip``, alle Dateien
im ZIP-Wurzelverzeichnis. ``hsl-ci.css`` und ``logo-hsl.jpeg`` (Firmen-CI) werden nur
übernommen, wenn sie lokal in ``moodle/template/`` liegen; sie gehören nicht ins Repository.
"""

from __future__ import annotations

import argparse
import html
import re
import shutil
import sys
import zipfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent / "backend"))

from tutor.config import load_settings  # noqa: E402
from tutor.modules import Module, load_module  # noqa: E402

TEMPLATE = HERE / "template"
SCRIPTS = ("scorm.js", "quiz.js", "modules.js")
LETTERS = "abcdefgh"


def esc(text: str) -> str:
    return html.escape(text, quote=True)


def paragraphs(text: str) -> str:
    parts = [" ".join(p.split()) for p in re.split(r"\n\s*\n", text) if p.strip()]
    return "\n".join(f"<p>{esc(p)}</p>" for p in parts)


def quiz_html(module: Module, objective_id: str, n: int) -> str:
    questions = [q for q in module.quiz if q.objective == objective_id]
    if not questions:
        return ""
    items = []
    for q in questions:
        options = "\n".join(
            f'<label><input type="radio" name="m{n}-{esc(q.id)}" value="{LETTERS[i]}"> {esc(o)}</label>'
            for i, o in enumerate(q.options)
        )
        items.append(
            f'<div class="ls-qz" data-answer="{LETTERS[q.correct]}" data-explain="{esc(q.explanation)}">'
            f'<p class="ls-q">{esc(q.question)}</p>{options}</div>'
        )
    return (
        f'<div class="ls-quiz"><h3>Lernerfolgskontrolle Modul {n}</h3>{"".join(items)}'
        '<div class="ls-quiz-actions"><button type="button" class="ls-btn primary" data-quiz-check>Auswerten</button>'
        '<button type="button" class="ls-btn" data-quiz-reset>Zurücksetzen</button></div>'
        "<div data-quiz-result></div>"
        f'<p class="ls-hint">Ab 70 % richtig wird {"das nächste Modul" if n < len(module.objectives) else "das Lerngespräch empfohlen"} freigeschaltet.</p></div>'
    )


def build_index(module: Module, tutors: list[tuple[str, str]], tutor_url: str, has_logo: bool) -> str:
    blocks, toc = [], []
    for n, (objective, section) in enumerate(zip(module.objectives, module.sections), start=1):
        toc.append(f'<li><a href="#modul-{n}">Modul {n}: {esc(section.title)}</a></li>')
        blocks.append(
            f'<section class="ls-module" id="modul-{n}" data-module="{n}">'
            f'<button type="button" class="ls-mod-head" data-mod-toggle>'
            f'<span class="ls-mod-kicker">Modul {n}</span><span class="ls-mod-title">{esc(section.title)}</span>'
            f'<span class="ls-mod-state" data-mod-state></span><span class="ls-mod-arrow" data-mod-arrow>+</span></button>'
            f'<div class="ls-mod-body" data-mod-body>'
            f'<div class="hsl-note"><strong>Lernziel:</strong> {esc(objective.text)}</div>'
            f"{paragraphs(section.text)}{quiz_html(module, objective.id, n)}</div></section>"
        )
    buttons = "".join(
        f'<button type="button" class="ls-btn primary" data-tutor="{esc(tid)}">Mit {esc(name)} sprechen</button>'
        for tid, name in tutors
    )
    names = " oder ".join(name for _, name in tutors)
    logo = '<img class="hsl-head-logo" src="logo-hsl.jpeg" alt="HSL LOGISTIK">' if has_logo else ""
    n_questions = len(module.quiz)
    return f"""<!doctype html>
<html lang="de" data-course="{esc(module.id)}" data-module-id="{esc(module.id)}" data-tutor-url="{esc(tutor_url)}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{esc(module.title)}</title>
<link rel="stylesheet" href="styles.css">
<link rel="stylesheet" href="hsl-ci.css">
</head>
<body>
<main class="ls-page">
<header class="hsl-head">
  <div class="hsl-head-title">
    <p class="ls-kicker">Lernmodul · {esc(module.owner)}</p>
    <h1>{esc(module.title)}</h1>
    <p class="ls-meta">Bearbeitungsdauer ca. {module.estimated_minutes} Minuten · {len(module.objectives)} Module · {n_questions} Fragen · Bestehensgrenze 70 %</p>
  </div>
  {logo}
</header>

<div class="hsl-field">
<p>So funktioniert das Lernmodul: Lies ein Modul und beantworte die Fragen am Ende. Ab 70 % richtig
wird das nächste Modul freigeschaltet. Zum Schluss kannst du das Gelernte im Gespräch mit unserem
Sprach-Tutor {esc(names)} vertiefen.</p>
</div>

<nav class="ls-toc"><h2>Inhalt</h2><ol>{"".join(toc)}<li><a href="#lerngespraech">Lerngespräch mit {esc(names)}</a></li></ol></nav>

{"".join(blocks)}

<section class="ls-talk" id="lerngespraech">
  <div class="hsl-band-dark">Lerngespräch</div>
  <div class="ls-talk-body">
    <p>Im Gespräch prüft der Tutor mit dir, ob du die Inhalte anwenden kannst, und erklärt, was noch
    unklar ist. Du sprichst einfach über dein Mikrofon. Das Gespräch öffnet sich in einem neuen
    Fenster; danach kommst du hierher zurück.</p>
    <p class="ls-hint">Wähle, mit wem du lernen möchtest. Das Gespräch wird nicht aufgezeichnet.
    Übertragen wird nur eine pseudonyme Kennung, nicht dein Name.</p>
    <div class="ls-talk-actions">{buttons}</div>
    <p class="ls-hint" data-tutor-status></p>
  </div>
</section>

<section class="ls-end">
  <h2>Abschluss</h2>
  <p data-total>Beantworte die Fragen aller Module, dann erscheint hier dein Gesamtergebnis.</p>
  <p class="ls-hint">Das Ergebnis wird automatisch an Moodle übermittelt.</p>
</section>

<footer class="hsl-foot"><span>{esc(module.owner)}</span><span>{esc(module.title)}</span><span>Stand {esc(module.version)}</span></footer>
</main>
<script src="scorm.js"></script>
<script src="quiz.js"></script>
<script src="modules.js"></script>
<script src="tutor.js"></script>
</body>
</html>
"""


def build_manifest(module: Module, files: list[str]) -> str:
    resources = "\n".join(f'      <file href="{esc(f)}"/>' for f in files)
    return f"""<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="{esc(module.id)}" version="1.0"
  xmlns="http://www.imsproject.org/xsd/imscp_rootv1p1p2"
  xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_rootv1p2"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="http://www.imsproject.org/xsd/imscp_rootv1p1p2 imscp_rootv1p1p2.xsd http://www.imsglobal.org/xsd/imsmd_rootv1p2p1 imsmd_rootv1p2p1.xsd http://www.adlnet.org/xsd/adlcp_rootv1p2 adlcp_rootv1p2.xsd">
  <metadata><schema>ADL SCORM</schema><schemaversion>1.2</schemaversion></metadata>
  <organizations default="org-{esc(module.id)}">
    <organization identifier="org-{esc(module.id)}">
      <title>{esc(module.title)}</title>
      <item identifier="item-1" identifierref="res-1">
        <title>{esc(module.title)}</title>
        <adlcp:masteryscore>70</adlcp:masteryscore>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="res-1" type="webcontent" adlcp:scormtype="sco" href="index.html">
{resources}
    </resource>
  </resources>
</manifest>
"""


def build(module_path: Path, tutor_url: str, out_dir: Path) -> Path:
    module = load_module(module_path)
    tutors = [(t.id, t.name) for t in load_settings().tutors.values()]
    target = out_dir / module.id
    if target.exists():
        shutil.rmtree(target)
    target.mkdir(parents=True)

    # Firmen-CI und Logo liegen nicht im Repository; fehlen sie, bleibt das Grundlayout.
    files = ["index.html", "styles.css", *SCRIPTS, "tutor.js"]
    files += [name for name in ("hsl-ci.css", "logo-hsl.jpeg") if (TEMPLATE / name).exists()]
    for name in files[1:]:
        shutil.copy(TEMPLATE / name, target / name)
    has_logo = "logo-hsl.jpeg" in files
    (target / "index.html").write_text(build_index(module, tutors, tutor_url.rstrip("/"), has_logo), encoding="utf-8")
    (target / "imsmanifest.xml").write_text(build_manifest(module, files), encoding="utf-8")

    archive = out_dir / f"{module.id}_SCORM.zip"
    with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as zf:
        for name in ["imsmanifest.xml", *files]:
            zf.write(target / name, arcname=name)
    return archive


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("module", type=Path, help="Moduldatei (YAML)")
    parser.add_argument("--tutor-url", default="http://localhost:8000", help="Adresse des Voice-Tutors")
    parser.add_argument("--out", type=Path, default=HERE / "dist")
    args = parser.parse_args()
    print(build(args.module, args.tutor_url, args.out))


if __name__ == "__main__":
    main()
