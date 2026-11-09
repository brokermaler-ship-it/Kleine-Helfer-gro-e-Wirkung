"""Latenz-Auswertung der Sitzungen aus ``data/sessions.jsonl``.

Der Agent schreibt pro Sitzung die LiveKit-Metriken jedes Gesprächswechsels (in
Sekunden). Hier entstehen daraus P50/P95 je Modul und der Abgleich mit den Zielen aus
PROJECT.md, Abschnitt 1.1 und 7:

- ``e2e_latency``: Ende des Sprechens bis Beginn der Antwort, inklusive Turn-Erkennung.
  Ziel P50 < 1.000 ms, P95 < 1.500 ms.
- Reaktionszeit nach erkanntem Turn-Ende: ``llm_node_ttft`` + ``tts_node_ttfb``.
  Ziel P50 < 500 ms.

Gesprächswechsel, bei denen die Turn-Erkennung länger als 1,5 s gewartet hat, sind
gewollte Nachdenkpausen. Sie werden getrennt ausgewiesen und zählen nicht für die Ziele.
"""

from __future__ import annotations

import math
from collections import defaultdict

# Ab dieser Wartezeit der Turn-Erkennung gilt ein Gesprächswechsel als Nachdenkpause.
THINKING_PAUSE_S = 1.5

E2E_P50_TARGET_MS = 1000
E2E_P95_TARGET_MS = 1500
REACTION_P50_TARGET_MS = 500


def percentile(values: list[float], q: float) -> float | None:
    """Perzentil mit linearer Interpolation (wie ``numpy.percentile``), ``q`` in 0..100."""
    if not values:
        return None
    ordered = sorted(values)
    rank = (len(ordered) - 1) * q / 100
    low, high = math.floor(rank), math.ceil(rank)
    return ordered[low] + (ordered[high] - ordered[low]) * (rank - low)


def _distribution_ms(values_s: list[float]) -> dict:
    p50, p95 = percentile(values_s, 50), percentile(values_s, 95)
    return {
        "n": len(values_s),
        "p50": round(p50 * 1000) if p50 is not None else None,
        "p95": round(p95 * 1000) if p95 is not None else None,
    }


def _below(value: int | None, target: int) -> bool | None:
    return None if value is None else value < target


def response_turns(turns: list[dict]) -> list[dict]:
    """Antworten des Tutors mit der Wartezeit der Turn-Erkennung davor.

    LiveKit hängt ``end_of_turn_delay`` an die Nachricht der lernenden Person und
    ``e2e_latency`` an die Antwort. Die Wartezeit wird deshalb von der vorausgehenden
    Nachricht übernommen, falls die Antwort sie nicht selbst trägt.
    """
    result = []
    last_eot: float | None = None
    for turn in turns:
        if turn.get("role") == "user":
            last_eot = turn.get("end_of_turn_delay")
        elif turn.get("role") == "assistant":
            eot = turn.get("end_of_turn_delay", last_eot)
            result.append({**turn, "end_of_turn_delay": eot})
            last_eot = None
    return result


def latency_summary(records: list[dict]) -> dict:
    by_module: dict[str, list[dict]] = defaultdict(list)
    for record in records:
        by_module[record["module_id"]].append(record)

    summary = {}
    for module_id, sessions in by_module.items():
        e2e, reaction, pause_e2e = [], [], []
        for turn in (t for s in sessions for t in response_turns(s.get("turns", []))):
            eot = turn.get("end_of_turn_delay")
            if eot is not None and eot > THINKING_PAUSE_S:
                if "e2e_latency" in turn:
                    pause_e2e.append(turn["e2e_latency"])
                continue
            if "e2e_latency" in turn:
                e2e.append(turn["e2e_latency"])
            if "llm_node_ttft" in turn and "tts_node_ttfb" in turn:
                reaction.append(turn["llm_node_ttft"] + turn["tts_node_ttfb"])

        e2e_ms, reaction_ms = _distribution_ms(e2e), _distribution_ms(reaction)
        summary[module_id] = {
            "sitzungen": len(sessions),
            "abschlussquote": round(sum(bool(s.get("finished")) for s in sessions) / len(sessions), 3),
            "e2e_latency_ms": e2e_ms,
            "reaktionszeit_ms": reaction_ms,
            "nachdenkpausen": {"anzahl": len(pause_e2e), "e2e_latency_ms": _distribution_ms(pause_e2e)},
            "ziele_erreicht": {
                "e2e_p50": _below(e2e_ms["p50"], E2E_P50_TARGET_MS),
                "e2e_p95": _below(e2e_ms["p95"], E2E_P95_TARGET_MS),
                "reaktionszeit_p50": _below(reaction_ms["p50"], REACTION_P50_TARGET_MS),
            },
        }
    return summary

