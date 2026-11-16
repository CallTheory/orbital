"""The call's conversation as plain text, for Call History.

Sent to Orbital when the AI session ends (agent.py's shutdown callback).
Only what was said: system prompts, tool calls and their results stay
out.
"""
from __future__ import annotations

from typing import Any, Iterable

SPEAKERS = {"user": "Caller", "assistant": "Agent"}


def format_transcript(items: Iterable[Any]) -> str:
    lines: list[str] = []
    for item in items:
        if getattr(item, "type", None) != "message":
            continue
        speaker = SPEAKERS.get(getattr(item, "role", ""))
        if speaker is None:
            continue
        text = (getattr(item, "text_content", None) or "").strip()
        if not text:
            continue
        if getattr(item, "interrupted", False):
            text += " [interrupted]"
        lines.append(f"{speaker}: {text}")
    return "\n".join(lines)
