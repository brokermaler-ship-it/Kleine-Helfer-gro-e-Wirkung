"""Baut aus einer Moduldatei (YAML) ein SCORM-1.2-Lernpaket für Moodle.

    python moodle/build_scorm.py content/modules/phishing-melden.yaml --tutor-url http://localhost:8000

Lernziel n, Abschnitt n und die Fragen zu Lernziel n bilden zusammen Modul n des Lernskripts.
Modul 1 ist offen, jedes weitere wird ab 70 % im Quiz des Vormoduls freigeschaltet.
Am Ende steht das Lerngespräch: ein Knopf je Tutor, der den Voice-Tutor in einem neuen
Fenster öffnet (``?kennung=…&modul=…&tutor=…&direkt=1``).

Ergebnis: ``moodle/dist/<modul-id>/`` und ``moodle/dist/<modul-id>_SCORM.zip``, alle Dateien
im ZIP-Wurzelverzeichnis. ``hsl-ci.css`` und ``logo-hsl.jpeg`` (Firmen-CI) werden nur
übernommen, wenn sie lokal in ``moodle/template/`` liegen; sie gehören nicht ins Repository.

Die Antwortoptionen werden beim Build gemischt: Die richtige Antwort landet gleichmäßig
verteilt auf a, b, c, d (fest pro Modul, ein neuer Build ergibt dieselbe Reihenfolge).
Außerdem warnt der Build, wenn die richtige Antwort auffällig oft die längste ist, weil
man die Fragen dann ohne Wissen erraten kann. Mit ``--streng`` bricht er dann ab.

Mit ``--audio`` liest jeder Tutor jedes Modul einmal über Cartesia ein (``CARTESIA_API_KEY``
nötig). Die MP3-Dateien landen unter ``audio/`` im Paket, jedes Modul bekommt einen Player
zum Vorlesenlassen, auch ohne laufenden Tutor-Server.
"""

from __future__ import annotations

import argparse
import hashlib
import html
import json
import os
import random
import re
import urllib.request
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
# Ab diesem Anteil "richtige Antwort ist die längste" warnt der Build (Zufall wäre 1/Optionen).
LONGEST_LIMIT = 0.4
# Eine einzelne richtige Antwort, die so viel länger ist als die längste falsche, verrät sich.
LONGER_FACTOR = 1.5


def esc(text: str) -> str:
    return html.escape(text, quote=True)


def paragraphs(text: str) -> str:
    parts = [" ".join(p.split()) for p in re.split(r"\n\s*\n", text) if p.strip()]
    return "\n".join(f"<p>{esc(p)}</p>" for p in parts)


def shuffled_quiz(module: Module) -> dict[str, tuple[list[str], int]]:
    """Optionen je Frage mischen, die richtige Antwort gleichmäßig auf alle Positionen verteilen.

    Ergebnis: Fragen-ID -> (Optionen in neuer Reihenfolge, Index der richtigen Antwort).
    Der Zufall hängt nur an der Modul-ID, damit jeder Build dasselbe Paket ergibt.
    """
    rng = random.Random(hashlib.sha256(module.id.encode("utf-8")).hexdigest())
    width = min(len(q.options) for q in module.quiz) if module.quiz else 0
    targets: list[int] = []
    result = {}
    for q in module.quiz:
        if not targets:
            targets = list(range(width))
            rng.shuffle(targets)
        target = targets.pop()
        wrong = [o for i, o in enumerate(q.options) if i != q.correct]
        rng.shuffle(wrong)
        result[q.id] = (wrong[:target] + [q.options[q.correct]] + wrong[target:], target)
    return result


def quiz_warnings(module: Module) -> list[str]:
    """Hinweise auf ratbare Fragen: die richtige Antwort ist auffällig oft oder deutlich die längste."""
    if not module.quiz:
        return []
    warnings = []
    longest = [q for q in module.quiz if max(range(len(q.options)), key=lambda i: len(q.options[i])) == q.correct]
    share = len(longest) / len(module.quiz)
    if len(longest) >= 3 and share > LONGEST_LIMIT:
        warnings.append(
            f"Die richtige Antwort ist in {len(longest)} von {len(module.quiz)} Fragen die längste ({share:.0%}). "
            "Falsche Antworten ähnlich ausführlich formulieren."
        )
    for q in module.quiz:
        right = len(q.options[q.correct])
        wrong = max(len(o) for i, o in enumerate(q.options) if i != q.correct)
        if right > LONGER_FACTOR * wrong:
            warnings.append(f"Frage {q.id}: Die richtige Antwort ist deutlich länger als alle falschen ({right} zu {wrong} Zeichen).")
    return warnings


def quiz_html(module: Module, objective_id: str, n: int, order: dict[str, tuple[list[str], int]]) -> str:
    questions = [q for q in module.quiz if q.objective == objective_id]
    if not questions:
        return ""
    items = []
    for q in questions:
        opts, correct = order[q.id]
        options = "\n".join(
            f'<label><input type="radio" name="m{n}-{esc(q.id)}" value="{LETTERS[i]}"> {esc(o)}</label>'
            for i, o in enumerate(opts)
        )
        items.append(
            f'<div class="ls-qz" data-answer="{LETTERS[correct]}" data-explain="{esc(q.explanation)}">'
            f'<p class="ls-q">{esc(q.question)}</p>{options}</div>'
        )
    return (
        f'<div class="ls-quiz"><h3>Lernerfolgskontrolle Modul {n}</h3>{"".join(items)}'
        '<div class="ls-quiz-actions"><button type="button" class="ls-btn primary" data-quiz-check>Auswerten</button>'
        '<button type="button" class="ls-btn" data-quiz-reset>Zurücksetzen</button></div>'
        "<div data-quiz-result></div>"
        f'<p class="ls-hint">Ab 70 % richtig wird {"das nächste Modul" if n < len(module.objectives) else "das Lerngespräch empfohlen"} freigeschaltet.</p></div>'
    )


def spoken_text(module: Module, n: int) -> str:
    objective, section = module.objectives[n - 1], module.sections[n - 1]
    body = "\n\n".join(" ".join(p.split()) for p in re.split(r"\n\s*\n", section.text) if p.strip())
    return f"Modul {n}: {section.title}.\n\nLernziel: {objective.text}\n\n{body}"


def synthesize(text: str, voice: str, model: str, target: Path) -> None:
    request = urllib.request.Request(
        "https://api.cartesia.ai/tts/bytes",
        data=json.dumps(
            {
                "model_id": model,
                "transcript": text,
                "voice": {"mode": "id", "id": voice},
                "language": "de",
                "output_format": {"container": "mp3", "sample_rate": 44100, "bit_rate": 128000},
            }
        ).encode("utf-8"),
        headers={
            "Authorization": f"Bearer {os.environ['CARTESIA_API_KEY']}",
            "Cartesia-Version": "2025-04-16",
            "Content-Type": "application/json",
        },
    )
    with urllib.request.urlopen(request, timeout=300) as response:
        target.write_bytes(response.read())


def cached_audio(text: str, voice: str, model: str, cache: Path) -> Path:
    """Vertonungen werden zwischengespeichert, damit ein neuer Build kein Cartesia-Guthaben kostet."""
    key = hashlib.sha256(f"{model}|{voice}|{text}".encode("utf-8")).hexdigest()[:20]
    path = cache / f"{key}.mp3"
    if not path.exists():
        cache.mkdir(parents=True, exist_ok=True)
        synthesize(text, voice, model, path)
    return path


def read_aloud_html(players: list[tuple[str, str]]) -> str:
    if not players:
        return ""
    items = "".join(
        f'<figure><figcaption>{esc(name)} liest vor</figcaption>'
        f'<audio controls preload="none" src="{esc(src)}"></audio></figure>'
        for name, src in players
    )
    return f'<div class="ls-read" role="group" aria-label="Modul vorlesen lassen"><strong>Vorlesen lassen</strong>{items}</div>'


def build_index(
    module: Module,
    tutors: list[tuple[str, str]],
    tutor_url: str,
    has_logo: bool,
    audio: dict[int, list[tuple[str, str]]] | None = None,
) -> str:
    audio = audio or {}
    order = shuffled_quiz(module)
    blocks, toc = [], []
    for n, (objective, section) in enumerate(zip(module.objectives, module.sections), start=1):
        toc.append(f'<li><a href="#modul-{n}">Modul {n}: {esc(section.title)}</a></li>')
        blocks.append(
            f'<section class="ls-module" id="modul-{n}" data-module="{n}">'
            f'<button type="button" class="ls-mod-head" data-mod-toggle>'
            f'<span class="ls-mod-kicker">Modul {n}</span><span class="ls-mod-title">{esc(section.title)}</span>'
            f'<span class="ls-mod-state" data-mod-state></span><span class="ls-mod-arrow" data-mod-arrow>+</span></button>'
            f'<div class="ls-mod-body" data-mod-body>{read_aloud_html(audio.get(n, []))}'
            f'<div class="hsl-note"><strong>Lernziel:</strong> {esc(objective.text)}</div>'
            f"{paragraphs(section.text)}{quiz_html(module, objective.id, n, order)}</div></section>"
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


class QuizQualityError(ValueError):
    pass


def build(module_path: Path, tutor_url: str, out_dir: Path, with_audio: bool = False, strict: bool = False) -> Path:
    module = load_module(module_path)
    warnings = quiz_warnings(module)
    for warning in warnings:
        print(f"Warnung: {warning}", file=sys.stderr)
    if warnings and strict:
        raise QuizQualityError(f"{module.id}: {len(warnings)} Warnung(en) zur Ratbarkeit der Fragen")
    settings = load_settings()
    tutors = [(t.id, t.name) for t in settings.tutors.values()]
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

    audio: dict[int, list[tuple[str, str]]] = {}
    if with_audio:
        (target / "audio").mkdir()
        for n in range(1, min(len(module.objectives), len(module.sections)) + 1):
            for tutor in settings.tutors.values():
                name = f"audio/modul{n}-{tutor.id}.mp3"
                print(f"Vertone {name} …", flush=True)
                mp3 = cached_audio(spoken_text(module, n), tutor.voice, settings.tts_model, out_dir / "audio-cache")
                shutil.copy(mp3, target / name)
                audio.setdefault(n, []).append((tutor.name, name))
                files.append(name)
    index = build_index(module, tutors, tutor_url.rstrip("/"), has_logo, audio)
    (target / "index.html").write_text(index, encoding="utf-8")
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
    parser.add_argument("--audio", action="store_true", help="Module mit den Tutor-Stimmen vertonen (Cartesia)")
    parser.add_argument("--streng", action="store_true", help="Bei Warnungen zur Ratbarkeit der Fragen abbrechen")
    args = parser.parse_args()
    try:
        print(build(args.module, args.tutor_url, args.out, args.audio, args.streng))
    except QuizQualityError as e:
        sys.exit(str(e))


if __name__ == "__main__":
    main()
