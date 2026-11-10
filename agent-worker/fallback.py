"""When the AI can't take or carry on a call, don't leave the caller in
silence: REFER them to Asterisk's `ai-unavailable` extension (from-livekit),
which plays a pre-rendered "we're having trouble, please call back later"
message and hangs up. Asterisk plays it, so it works even when TTS is what
failed.

If even the transfer fails, the room is deleted, which hangs up the call:
an abrupt end is still better than two minutes of dead air until the SIP
media timeout.
"""
from __future__ import annotations

import asyncio
import logging

from livekit import api, rtc
from livekit.agents import JobContext
from livekit.protocol.sip import TransferSIPParticipantRequest

logger = logging.getLogger("orbital-fallback")

# How long to wait for the caller's SIP participant to show up in the room
# when the failure happens before the session has started.
PARTICIPANT_WAIT_SECONDS = 10.0


class CallFallback:
    """Sends one call to the fallback message, at most once."""

    def __init__(self, ctx: JobContext, sip_domain: str) -> None:
        self._ctx = ctx
        self._transfer_to = f"sip:ai-unavailable@{sip_domain}"
        self._triggered = False

    @property
    def triggered(self) -> bool:
        return self._triggered

    async def trigger(self, reason: str) -> None:
        if self._triggered:
            return
        self._triggered = True
        logger.error(f"AI unavailable for room {self._ctx.room.name}: {reason}; playing the fallback message")

        try:
            await self._ctx.connect()  # no-op once connected
        except Exception as e:  # noqa: BLE001 - try to hang up anyway
            logger.warning(f"fallback: couldn't join the room: {e}")

        identity = await self._sip_identity()
        if identity is None:
            logger.warning("fallback: no caller in the room (they may have hung up)")
            await self._hang_up()
            return

        try:
            await self._ctx.api.sip.transfer_sip_participant(
                TransferSIPParticipantRequest(
                    participant_identity=identity,
                    room_name=self._ctx.room.name,
                    transfer_to=self._transfer_to,
                    play_dialtone=False,
                )
            )
            logger.info(f"fallback: REFERred {identity} to {self._transfer_to}")
        except Exception as e:  # noqa: BLE001
            logger.error(f"fallback: REFER failed ({e}); hanging up instead")
            await self._hang_up()

    async def _sip_identity(self) -> str | None:
        """The caller's participant identity, waiting briefly if needed."""
        loop = asyncio.get_running_loop()
        deadline = loop.time() + PARTICIPANT_WAIT_SECONDS
        while True:
            for p in self._ctx.room.remote_participants.values():
                if p.kind == rtc.ParticipantKind.PARTICIPANT_KIND_SIP or p.identity.startswith("sip_"):
                    return p.identity
            if loop.time() >= deadline:
                return None
            await asyncio.sleep(0.25)

    async def _hang_up(self) -> None:
        try:
            await self._ctx.api.room.delete_room(api.DeleteRoomRequest(room=self._ctx.room.name))
        except Exception as e:  # noqa: BLE001
            logger.error(f"fallback: couldn't end the call: {e}")
