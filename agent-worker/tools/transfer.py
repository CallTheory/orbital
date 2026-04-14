"""Call transfer tool for Orbital agents."""

import logging
import os

from livekit.agents.job import get_job_context
from livekit.agents.llm import function_tool
from livekit.agents.voice.events import RunContext
from livekit.protocol.sip import TransferSIPParticipantRequest

logger = logging.getLogger("orbital-transfer")

ASTERISK_SIP_DOMAIN = os.environ.get("ASTERISK_SIP_DOMAIN", "asterisk")


@function_tool(
    name="transfer_call",
    description=(
        "Transfer the current caller to another extension. "
        "Use this when the caller asks to speak with a specific person, "
        "or when you need to redirect them to the right department. "
        "Say something like 'Let me transfer you now' before calling this tool."
    ),
)
async def transfer_call(ctx: RunContext, extension: str) -> str:
    """Transfer the caller to another extension.

    Args:
        extension: The extension number to transfer to (e.g. "1021", "1005")
    """
    job_ctx = get_job_context()
    room = job_ctx.room

    # Find the SIP participant (the caller)
    sip_identity = None
    for p in room.remote_participants.values():
        if p.identity.startswith("sip_"):
            sip_identity = p.identity
            break

    if not sip_identity:
        return "No caller found to transfer."

    transfer_to = f"sip:{extension}@{ASTERISK_SIP_DOMAIN}"
    logger.info(f"SIP REFER transferring {sip_identity} to {transfer_to}")

    try:
        await job_ctx.api.sip.transfer_sip_participant(
            TransferSIPParticipantRequest(
                participant_identity=sip_identity,
                room_name=room.name,
                transfer_to=transfer_to,
                play_dialtone=True,
            )
        )
        ctx.session.shutdown()
        return f"Transferring to extension {extension}."
    except Exception as e:
        logger.error(f"SIP REFER transfer failed: {e}")
        return f"Sorry, I wasn't able to reach extension {extension} right now."
