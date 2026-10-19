"""Orbital Agent Worker — handles LiveKit AI agent sessions."""

import asyncio
import logging
import os
import re

import requests

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

import observability
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
    # Keys saved in Platform Settings; anything missing falls back to the
    # plugin's own environment variable.
    keys = envelope.get("provider_keys") or {}

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
    llm = _create_llm(llm_provider, llm_model, keys)
    tts = _create_tts(tts_provider, voice_id, keys)
    stt = _create_stt(stt_provider, keys)

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

    # Record who's calling and the DID they dialled; the handoff lookup
    # API finds this session by the caller's number.
    asyncio.create_task(_report_call_context(ctx, config, room_name, extension))

    # Brief pause so the SIP bridge audio path is fully established
    # before the greeting plays — without this the first few hundred
    # milliseconds of TTS get swallowed while RTP is still negotiating.
    await asyncio.sleep(0.8)

    # Kick off per-participant TrackEgress for the recording surface 2
    # path. We fire-and-forget — the LiveKit server emits an
    # `egress_ended` webhook to the Laravel app when each track file
    # finalizes, and `IngestLivekitEgressJob` writes the CallRecording
    # row from that. We don't await Egress completion (it runs for
    # the lifetime of the call), and we tolerate failure cleanly: if
    # Egress is misconfigured, the rtpengine SIP-side recording is
    # the still-OK fallback.
    try:
        await _start_track_egress(ctx, room_name)
    except Exception as e:
        logger.warning(f"egress start failed for {room_name}: {e}")

    await session.say(greeting)


async def _report_call_context(ctx: JobContext, config: Config, room_name: str, extension: str) -> None:
    """POST the caller's number and the dialled DID for this session.

    LiveKit's SIP participant carries the caller as `sip.phoneNumber`;
    the DID comes from the X-Orbital-DID header Asterisk adds (included
    as a `sip.h.*` attribute). `sip.trunkPhoneNumber` is only the AI
    extension Asterisk dialled, so it's the fallback.
    """
    try:
        participant = await asyncio.wait_for(ctx.wait_for_participant(), timeout=10)
    except Exception as e:
        logger.warning(f"no SIP participant to read call context from: {e}")
        return

    attrs = dict(participant.attributes or {})
    did = next((v for k, v in attrs.items() if k.lower().endswith("x-orbital-did")), None)
    payload = {
        "caller_number": attrs.get("sip.phoneNumber"),
        "called_number": did or attrs.get("sip.trunkPhoneNumber"),
        "extension": extension,
    }
    try:
        await asyncio.to_thread(
            requests.post,
            f"{config.orbital_api_url}/call-sessions/{room_name}/context",
            headers=config.api_headers,
            json=payload,
            timeout=5,
        )
    except requests.RequestException as e:
        logger.warning(f"call context report failed: {e}")


async def _start_track_egress(ctx: JobContext, room_name: str) -> None:
    """Start a TrackEgress per audio track in the room.

    LiveKit's `egress` API accepts the room name + per-participant
    track ID and writes one file per call to the configured S3
    output. The orbital.test webhook receives `egress_ended` when
    each file finalizes; from there `IngestLivekitEgressJob` creates
    the CallRecording row.

    Output target = SeaweedFS S3-compatible bucket. Bucket name +
    creds come from env (LIVEKIT_EGRESS_S3_*), set by docker-compose.
    """
    from livekit import api

    bucket = os.environ.get("LIVEKIT_EGRESS_S3_BUCKET", "orbital-recordings")
    endpoint = os.environ.get("LIVEKIT_EGRESS_S3_ENDPOINT", "")
    access_key = os.environ.get("LIVEKIT_EGRESS_S3_ACCESS_KEY", "")
    secret = os.environ.get("LIVEKIT_EGRESS_S3_SECRET", "")
    if not endpoint or not access_key:
        # Egress optional in dev; skip silently when storage isn't
        # configured. The rtpengine surface still records SIP calls.
        return

    lk = api.LiveKitAPI()
    try:
        # Filename pattern carries the room name (= sip_call_id) so
        # IngestLivekitEgressJob can correlate back to a CallLog row
        # without a side channel.
        request = api.RoomCompositeEgressRequest(
            room_name=room_name,
            file_outputs=[
                api.EncodedFileOutput(
                    file_type=api.EncodedFileType.OGG,
                    filepath=f"livekit/{room_name}/{{room_name}}-{{time}}.ogg",
                    s3=api.S3Upload(
                        bucket=bucket,
                        endpoint=endpoint,
                        access_key=access_key,
                        secret=secret,
                    ),
                ),
            ],
        )
        await lk.egress.start_room_composite_egress(request)
        logger.info(f"egress started for room {room_name}")
    finally:
        await lk.aclose()


def _key(keys: dict, provider: str) -> dict:
    """api_key kwarg for a plugin, or nothing so it reads its env var."""
    value = (keys or {}).get(provider)
    return {"api_key": value} if value else {}


def _create_llm(provider: str, model: str, keys: dict | None = None):
    """Create LLM instance based on provider configuration."""
    if provider == "anthropic":
        from livekit.plugins import anthropic
        return anthropic.LLM(model=model, _strict_tool_schema=False, **_key(keys, "anthropic"))
    elif provider in ("openai", "openrouter", "local"):
        from livekit.plugins import openai
        kwargs = {"model": model}
        if provider == "openrouter":
            kwargs["base_url"] = "https://openrouter.ai/api/v1"
            kwargs["api_key"] = (keys or {}).get("openrouter") or os.environ.get("OPENROUTER_API_KEY", "")
        elif provider == "local":
            kwargs["base_url"] = os.environ.get("LOCAL_LLM_URL", "http://localhost:11434/v1")
        else:
            kwargs.update(_key(keys, "openai"))
        return openai.LLM(**kwargs)
    else:
        from livekit.plugins import anthropic
        return anthropic.LLM(model=model, **_key(keys, "anthropic"))


def _create_tts(provider: str, voice_id: str | None = None, keys: dict | None = None):
    """Create TTS instance based on provider configuration."""
    if provider == "elevenlabs":
        from livekit.plugins import elevenlabs
        kwargs = {"model": "eleven_turbo_v2_5", **_key(keys, "elevenlabs")}
        if voice_id:
            kwargs["voice_id"] = voice_id
        return elevenlabs.TTS(**kwargs)
    elif provider == "openai":
        from livekit.plugins import openai
        return openai.TTS(voice=voice_id or "alloy", **_key(keys, "openai"))
    else:
        from livekit.plugins import elevenlabs
        return elevenlabs.TTS(model="eleven_turbo_v2_5", **_key(keys, "elevenlabs"))


def _create_stt(provider: str, keys: dict | None = None):
    """Create STT instance based on provider configuration."""
    if provider == "deepgram":
        from livekit.plugins import deepgram
        return deepgram.STT(**_key(keys, "deepgram"))
    from livekit.plugins import elevenlabs
    # Without server_vad, Scribe realtime waits for a manual commit that
    # AgentSession never sends, so callers' turns never end and the agent
    # goes silent after its greeting. Let ElevenLabs commit on silence.
    return elevenlabs.STT(
        model="scribe_v2_realtime",
        sample_rate=16000,
        server_vad={"vad_silence_threshold_secs": 0.8},
        **_key(keys, "elevenlabs"),
    )


if __name__ == "__main__":
    # Optional telemetry, before anything else so a crash during worker
    # startup is itself reportable. Both calls are no-ops without their
    # environment variables, and neither can prevent the worker from
    # starting — a voice agent that will not answer calls because its
    # monitoring is misconfigured is far worse than one with no
    # monitoring. See agent-worker/observability.py.
    observability.init_error_reporting()
    observability.init_tracing()

    cli.run_app(WorkerOptions(
        entrypoint_fnc=entrypoint,
        prewarm_fnc=prewarm,
        agent_name="orbital-agent",
    ))
