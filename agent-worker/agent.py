"""Orbital Agent Worker — handles LiveKit AI agent sessions."""

import logging
import os
import re

from livekit.agents import (
    Agent,
    AgentSession,
    JobContext,
    JobProcess,
    WorkerOptions,
    cli,
)
from livekit.agents.beta.tools import EndCallTool
from livekit.plugins import silero

from config import Config
from personality_loader import PersonalityLoader
from tools.compiled_flow import build_dynamic_tools
from tools.transfer import transfer_call

logger = logging.getLogger("orbital-agent")

# Room names from dispatchRuleCallee look like: agent-1020_HGzYmojjfar2
ROOM_NAME_RE = re.compile(r"^agent-_?(\d+)_")

END_CALL_EXTRA = (
    "ONLY use this if the caller explicitly says goodbye, "
    "or if they are being extremely abusive and you've warned them. "
    "Do NOT end the call just because there's a pause or silence. "
    "Do NOT end the call after answering a question — wait for the caller to respond."
)

BREVITY_RULES = (
    "IMPORTANT RULES FOR THIS PHONE CALL:\n"
    "- Keep every response to 1-3 sentences MAX. This is a phone call, not an essay.\n"
    "- Talk like a real person on the phone — short, natural, conversational.\n"
    "- Pause and let the caller talk. Do not monologue.\n"
    "- Never use markdown, bullet points, numbered lists, or any text formatting.\n"
    "- If you need to explain something complex, break it into back-and-forth exchanges.\n"
    "- You have an end_call tool — use it to hang up if the caller is abusive, "
    "won't stop harassing you, or if the conversation is clearly over."
)


def prewarm(proc: JobProcess) -> None:
    proc.userdata["vad"] = silero.VAD.load()


def _is_outbound_call(ctx: JobContext) -> bool:
    """Check if this is an outbound call by looking for OUTBOUND marker."""
    for p in ctx.room.remote_participants.values():
        if p.name and "OUTBOUND" in p.name.upper():
            return True
    return False


async def entrypoint(ctx: JobContext) -> None:
    room_name = ctx.room.name
    config = Config()
    loader = PersonalityLoader(config)

    # Parse extension from room name
    match = ROOM_NAME_RE.match(room_name)
    extension = match.group(1) if match else None

    if not extension:
        logger.warning(f"Could not parse extension from room name: {room_name}")
        return

    # Fetch persona + compiled flow from Orbital API. The envelope looks
    # like { "persona": {...}, "compiled_flow": {...} } — the compiled
    # flow carries whichever intake_flow bound to this extension (or the
    # persona default) and was compiled server-side.
    envelope = await loader.fetch_persona_by_extension(extension)
    if not envelope:
        logger.warning(f"No persona found for extension {extension}")
        return

    persona = envelope.get("persona") or {}
    compiled_flow = envelope.get("compiled_flow") or {}

    # Build system prompt. The compiled flow's `llm_instructions` already
    # includes personality + system_prompt + any flow instructions, but
    # BREVITY_RULES (phone-call formatting constraints) live only on the
    # worker so we prepend them here unconditionally.
    flow_instructions = compiled_flow.get("llm_instructions") or ""
    if flow_instructions:
        instructions = BREVITY_RULES + "\n\n" + flow_instructions
    else:
        # Degenerate fallback — no flow, no persona instructions at all.
        instructions = BREVITY_RULES
        if persona.get("personality"):
            instructions += "\n\n" + persona["personality"]
        if persona.get("system_prompt"):
            instructions += "\n\n" + persona["system_prompt"]

    # Pick greeting based on call direction
    is_outbound = _is_outbound_call(ctx)
    greeting = persona.get("outbound_greeting") if is_outbound else persona.get("greeting")
    if not greeting:
        greeting = f"Hello, this is {persona.get('name', 'an agent')}. How can I help you?"

    # Configure providers based on persona settings
    llm_provider = persona.get("llm_provider", "anthropic")
    llm_model = persona.get("llm_model", "claude-sonnet-4-20250514")
    tts_provider = persona.get("tts_provider", "elevenlabs")
    stt_provider = persona.get("stt_provider", "elevenlabs")
    voice_id = persona.get("voice_id")

    # Import the appropriate providers
    llm = _create_llm(llm_provider, llm_model)
    tts = _create_tts(tts_provider, voice_id)
    stt = _create_stt(stt_provider)

    session = AgentSession(
        stt=stt,
        tts=tts,
        llm=llm,
        vad=ctx.proc.userdata["vad"],
    )

    # Build dynamic tools from the compiled flow's function_schemas
    # (set_field, advance_step, transfer_call, search_knowledge, etc).
    # These supplement the always-available EndCallTool.
    #
    # The room name doubles as the call session key so set_field /
    # advance_step write-through to call_session_states, and the
    # operator UI can watch captured fields materialize in real time.
    dynamic_tools = build_dynamic_tools(
        compiled_flow.get("function_schemas") or [],
        available_stores=compiled_flow.get("available_stores") or [],
        api_base_url=config.orbital_api_url,
        api_headers=config.api_headers,
        session_key=room_name,
        extension=extension,
    )

    agent = Agent(
        instructions=instructions,
        tools=[
            EndCallTool(extra_description=END_CALL_EXTRA),
            transfer_call,
            *dynamic_tools,
        ],
    )

    await session.start(agent, room=ctx.room)
    await session.say(greeting)


def _create_llm(provider: str, model: str):
    """Create LLM instance based on provider configuration."""
    if provider == "anthropic":
        from livekit.plugins import anthropic
        return anthropic.LLM(model=model)
    elif provider in ("openai", "openrouter", "local"):
        from livekit.plugins import openai
        kwargs = {"model": model}
        if provider == "openrouter":
            kwargs["base_url"] = "https://openrouter.ai/api/v1"
            kwargs["api_key"] = os.environ.get("OPENROUTER_API_KEY", "")
        elif provider == "local":
            kwargs["base_url"] = os.environ.get("LOCAL_LLM_URL", "http://localhost:11434/v1")
        return openai.LLM(**kwargs)
    else:
        from livekit.plugins import anthropic
        return anthropic.LLM(model=model)


def _create_tts(provider: str, voice_id: str | None = None):
    """Create TTS instance based on provider configuration."""
    if provider == "elevenlabs":
        from livekit.plugins import elevenlabs
        kwargs = {"model": "eleven_turbo_v2_5"}
        if voice_id:
            kwargs["voice_id"] = voice_id
        return elevenlabs.TTS(**kwargs)
    elif provider == "openai":
        from livekit.plugins import openai
        return openai.TTS(voice=voice_id or "alloy")
    else:
        from livekit.plugins import elevenlabs
        return elevenlabs.TTS(model="eleven_turbo_v2_5")


def _create_stt(provider: str):
    """Create STT instance based on provider configuration."""
    if provider == "elevenlabs":
        from livekit.plugins import elevenlabs
        return elevenlabs.STT(model_id="scribe_v2_realtime", sample_rate=16000)
    elif provider == "deepgram":
        from livekit.plugins import deepgram
        return deepgram.STT()
    else:
        from livekit.plugins import elevenlabs
        return elevenlabs.STT(model_id="scribe_v2_realtime", sample_rate=16000)


if __name__ == "__main__":
    cli.run_app(WorkerOptions(
        entrypoint_fnc=entrypoint,
        prewarm_fnc=prewarm,
        agent_name="orbital-agent",
    ))
