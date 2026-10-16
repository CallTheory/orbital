"""Hand the caller off to the client's outside live operator.

Laravel decides the number: POST /call-sessions/{key}/handoff records
the handoff (the operator's system then fetches the collected fields
through the handoff lookup API, by the caller's number) and returns the
SIP URI to REFER the caller to. That URI is a from-livekit dialplan
entry Asterisk only has for numbers configured on the client's flow,
so the LLM can't be talked into dialling anywhere else.
"""

from __future__ import annotations

import asyncio
import logging
from typing import Callable

import requests
from livekit.agents.job import get_job_context
from livekit.agents.llm import function_tool
from livekit.agents.voice.events import RunContext
from livekit.protocol.sip import TransferSIPParticipantRequest

logger = logging.getLogger("orbital-handoff")


def make_transfer_to_operator_tool(
    description: str,
    api_base_url: str,
    api_headers: dict[str, str],
    session_key: str | None,
    extension: str | None,
) -> Callable:
    @function_tool(name="transfer_to_operator", description=description)
    async def transfer_to_operator(ctx: RunContext) -> str:
        if not session_key:
            return "I can't transfer this call right now."

        try:
            resp = await asyncio.to_thread(
                requests.post,
                f"{api_base_url}/call-sessions/{session_key}/handoff",
                headers=api_headers,
                json={"extension": extension},
                timeout=5,
            )
        except requests.RequestException as e:
            logger.error(f"handoff request failed: {e}")
            return "Sorry, I couldn't reach the operator line. Offer to take a message instead."

        if resp.status_code != 200:
            logger.error(f"handoff refused ({resp.status_code}): {resp.text[:200]}")
            return "Sorry, there's no operator line set up. Offer to take a message instead."

        transfer_to = resp.json().get("data", {}).get("transfer_to")
        job_ctx = get_job_context()
        room = job_ctx.room
        sip_identity = next(
            (p.identity for p in room.remote_participants.values() if p.identity.startswith("sip_")),
            None,
        )
        if not transfer_to or not sip_identity:
            return "Sorry, I couldn't transfer this call."

        logger.info(f"handoff: REFER {sip_identity} to {transfer_to}")
        try:
            # Asterisk plays ringback while it dials the operator.
            await job_ctx.api.sip.transfer_sip_participant(
                TransferSIPParticipantRequest(
                    participant_identity=sip_identity,
                    room_name=room.name,
                    transfer_to=transfer_to,
                    play_dialtone=False,
                )
            )
        except Exception as e:
            logger.error(f"handoff REFER failed: {e}")
            return "Sorry, the transfer didn't go through. Offer to take a message instead."

        ctx.session.shutdown()
        return "Transferring to the live operator."

    return transfer_to_operator
