"""LiveKit-Worker: der Sprach-Tutor.

    python -m tutor.agent dev

Der Token-Server (``server.py``) beauftragt den Agenten beim Raumbeitritt und gibt
Modul-ID und Pilot-Kennung als Dispatch-Metadaten mit. Der Agent lädt das Modul vollständig in den Kontext,
spricht über Deepgram (STT) und Cartesia (TTS) und schickt UI-Events über
Data Channels (Topics siehe ``tools.py``).
"""

from __future__ import annotations

import asyncio
import json
import logging
from datetime import datetime

from livekit import rtc
from livekit.agents import (
    Agent,
    AgentServer,
    AgentSession,
    AgentStateChangedEvent,
    ChatMessage,
    CloseEvent,
    ConversationItemAddedEvent,
    JobContext,
    JobProcess,
    cli,
    function_tool,
    inference,
)
from livekit.plugins import anthropic, cartesia, deepgram, silero

from .config import DEFAULT_TUTOR, Settings, Tutor, claude_request_options, load_settings
from .modules import Module, load_modules
from .prompts import build_system_prompt
from .tools import TOPIC_STATE, TutorSession, tool_specs

logger = logging.getLogger("tutor.agent")


class TutorLLM(anthropic.LLM):
    """Claude mit niedriger Effort-Stufe und server-seitigem Fallback bei jeder Anfrage."""

    def __init__(self, settings: Settings) -> None:
        super().__init__(model=settings.llm_model, caching="ephemeral", max_tokens=1024)
        self._request_options = claude_request_options(settings)

    def chat(self, *, extra_kwargs=None, **kwargs):  # type: ignore[override]
        merged = dict(self._request_options)
        if extra_kwargs:
            merged.update(extra_kwargs)
        return super().chat(extra_kwargs=merged, **kwargs)


class TutorAgent(Agent):
    def __init__(self, module: Module, tutor: Tutor, session_state: TutorSession, room: rtc.Room) -> None:
        self._state = session_state
        self._room = room
        self._tutor = tutor
        super().__init__(
            instructions=build_system_prompt(module, tutor.name),
            tools=[self._make_tool(spec) for spec in tool_specs(module)],
        )

    def _make_tool(self, spec: dict):
        name = spec["name"]

        async def handler(raw_arguments: dict[str, object]) -> str:
            result, events = self._state.handle(name, dict(raw_arguments))
            for topic, payload in events:
                await publish(self._room, topic, payload)
            return result

        return function_tool(
            handler,
            raw_schema={
                "name": name,
                "description": spec["description"],
                "parameters": spec["input_schema"],
            },
        )

    async def on_enter(self) -> None:
        self.session.generate_reply(
            instructions=f"Begrüße die Person kurz, stell dich als {self._tutor.name} vor und frag, was sie über das Thema schon weiß."
        )


async def publish(room: rtc.Room, topic: str, payload: dict) -> None:
    data = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    await room.local_participant.publish_data(data, reliable=True, topic=topic)


def prewarm(proc: JobProcess) -> None:
    proc.userdata["vad"] = silero.VAD.load()


server = AgentServer(setup_fnc=prewarm)


@server.rtc_session(agent_name="voice-tutor")
async def entrypoint(ctx: JobContext) -> None:
    settings = load_settings()
    modules = load_modules(settings.content_dir, settings.intern_content_dir)

    meta = json.loads(ctx.job.metadata or "{}")
    module = modules.get(meta.get("module_id", ""))
    await ctx.connect()
    if module is None:
        logger.error("Unbekanntes Modul in den Dispatch-Metadaten: %r", meta)
        ctx.shutdown(reason="unknown module")
        return

    tutor = settings.tutors.get(meta.get("tutor", DEFAULT_TUTOR), settings.tutors[DEFAULT_TUTOR])
    state = TutorSession(module)
    session = AgentSession(
        vad=ctx.proc.userdata["vad"],
        stt=deepgram.STT(model=settings.stt_model, language="de"),
        llm=TutorLLM(settings),
        tts=cartesia.TTS(
            model=settings.tts_model,
            language="de",
            voice=tutor.voice,
        ),
        turn_handling={
            # Lokales Modell, entscheidet anhand des Satzinhalts, ob die Person fertig ist.
            "turn_detection": inference.TurnDetector(version="v1-mini"),
            "endpointing": {
                "mode": "dynamic",
                "min_delay": settings.endpointing_min_delay,
                "max_delay": settings.endpointing_max_delay,
            },
            # VAD-basiert statt "adaptive", das einen LiveKit-Cloud-Dienst aufruft.
            "interruption": {"enabled": True, "mode": "vad", "resume_false_interruption": True},
        },
    )

    # Latenz pro Gesprächswechsel; e2e_latency entspricht dem SLO in PROJECT.md, Abschnitt 1.
    turns: list[dict] = []

    @session.on("conversation_item_added")
    def _on_item(ev: ConversationItemAddedEvent) -> None:
        item = ev.item
        if not isinstance(item, ChatMessage) or not item.metrics:
            return
        keys = ("e2e_latency", "end_of_turn_delay", "transcription_delay", "llm_node_ttft", "tts_node_ttfb")
        turns.append({"role": item.role, **{k: item.metrics[k] for k in keys if k in item.metrics}})

    # Diagnose ohne Inhalt: nur ob und wie viel Sprache erkannt wurde (kein Transkript im Log).
    @session.on("user_state_changed")
    def _on_user_state(ev) -> None:
        logger.info("user state: %s -> %s", ev.old_state, ev.new_state)

    @session.on("user_input_transcribed")
    def _on_transcribed(ev) -> None:
        if ev.is_final:
            logger.info("user transcript final: %d Zeichen", len(ev.transcript))

    @session.on("agent_state_changed")
    def _on_state(ev: AgentStateChangedEvent) -> None:
        logger.info("agent state: %s -> %s", ev.old_state, ev.new_state)
        asyncio.create_task(publish(ctx.room, TOPIC_STATE, {"status": ev.new_state}))

    def _write_session_record() -> None:
        settings.data_dir.mkdir(parents=True, exist_ok=True)
        record = {
            "session_id": state.session_id,
            "module_id": module.id,
            "module_version": module.version,
            "pilot_id": meta.get("pilot_id"),
            "tutor": tutor.id,
            "started_at": state.started_at.isoformat(),
            "ended_at": datetime.now().isoformat(),
            "finished": state.finished,
            "objectives": {k: v.status for k, v in state.progress.items()},
            "turns": turns,
        }
        with (settings.data_dir / "sessions.jsonl").open("a", encoding="utf-8") as f:
            f.write(json.dumps(record, ensure_ascii=False) + "\n")

    @session.on("close")
    def _on_close(ev: CloseEvent) -> None:
        _write_session_record()
        ctx.shutdown(reason=str(ev.reason))

    await ctx.wait_for_participant()
    # Keine Audioaufzeichnung (PROJECT.md, Abschnitt 6).
    await session.start(agent=TutorAgent(module, tutor, state, ctx.room), room=ctx.room, record=False)


def main() -> None:
    cli.run_app(server)


if __name__ == "__main__":
    main()
