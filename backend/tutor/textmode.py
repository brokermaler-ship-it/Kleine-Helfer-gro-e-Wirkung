"""Textmodus: derselbe Tutor im Terminal, ohne LiveKit, STT und TTS.

Gedacht zum Erproben der Didaktik und der Lerninhalte mit nur einem Anthropic-API-Key,
und als Grundlage für einen späteren Textmodus (Barrierefreiheit, Großraumbüro).

    python -m tutor.textmode phishing-melden
"""

from __future__ import annotations

import argparse
import sys

import anthropic

from .config import claude_request_options, load_settings
from .modules import load_modules
from .prompts import build_system_prompt
from .tools import TOPIC_KEY_POINT, TOPIC_PROGRESS, TOPIC_PROTOCOL, TOPIC_SOURCES, TutorSession, tool_specs

START_MESSAGE = "(Die Person hat die Lerneinheit gerade gestartet.)"


def print_event(topic: str, payload: dict) -> None:
    if topic == TOPIC_KEY_POINT:
        print(f"  [Merksatz] {payload['text']}")
    elif topic == TOPIC_SOURCES:
        print(f"  [Quelle] {payload['sources'][0]['title']}")
    elif topic == TOPIC_PROGRESS:
        status = ", ".join(f"{o['id']}={o['status']}" for o in payload["objectives"])
        print(f"  [Fortschritt] {status}")
    elif topic == TOPIC_PROTOCOL:
        print("\n" + payload["markdown"])


def run(module_id: str) -> int:
    settings = load_settings()
    modules = load_modules(settings.content_dir)
    if module_id not in modules:
        print(f"Unbekanntes Modul '{module_id}'. Verfügbar: {', '.join(modules)}", file=sys.stderr)
        return 2
    module = modules[module_id]
    session = TutorSession(module)
    client = anthropic.Anthropic()
    system = [{"type": "text", "text": build_system_prompt(module), "cache_control": {"type": "ephemeral"}}]
    tools = tool_specs(module)
    messages: list[dict] = [{"role": "user", "content": START_MESSAGE}]

    print(f"Modul: {module.title} (Stand {module.version}). Beenden mit Strg+D.\n")
    while True:
        response = client.messages.create(
            model=settings.llm_model,
            max_tokens=4096,
            system=system,
            tools=tools,
            messages=messages,
            **claude_request_options(settings),
        )
        if response.stop_reason == "refusal":
            print("Tutor: Dazu kann ich leider nichts sagen. Lass uns beim Thema bleiben.")
            # Die abgelehnte Eingabe verwerfen, damit das Gespräch weitergehen kann.
            if len(messages) < 2 or not isinstance(messages[-1]["content"], str):
                return 1
            messages.pop()
        else:
            messages.append({"role": "assistant", "content": response.content})
            for block in response.content:
                if block.type == "text" and block.text.strip():
                    print(f"Tutor: {block.text.strip()}")

            tool_uses = [b for b in response.content if b.type == "tool_use"]
            if tool_uses:
                results = []
                for block in tool_uses:
                    result, events = session.handle(block.name, dict(block.input))
                    for topic, payload in events:
                        print_event(topic, payload)
                    results.append({"type": "tool_result", "tool_use_id": block.id, "content": result})
                messages.append({"role": "user", "content": results})
                continue

        if session.finished:
            return 0
        try:
            user_input = input("\nDu: ").strip()
        except EOFError:
            print()
            return 0
        messages.append({"role": "user", "content": user_input or "(schweigt)"})


def main() -> None:
    parser = argparse.ArgumentParser(description="Voice-AI-Tutor im Textmodus")
    parser.add_argument("module_id", help="ID des Lernmoduls, z. B. phishing-melden")
    sys.exit(run(parser.parse_args().module_id))


if __name__ == "__main__":
    main()
