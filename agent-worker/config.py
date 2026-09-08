"""Configuration for the Orbital agent worker."""

import os

import observability


class Config:
    def __init__(self):
        self.orbital_api_url = os.environ.get("ORBITAL_API_URL", "http://orbital.test/api")
        self.orbital_api_token = os.environ.get("ORBITAL_API_TOKEN", "")
        self.livekit_url = os.environ.get("LIVEKIT_URL", "ws://livekit:7880")
        self.livekit_api_key = os.environ.get("LIVEKIT_API_KEY", "")
        self.livekit_api_secret = os.environ.get("LIVEKIT_API_SECRET", "")
        self.asterisk_sip_domain = os.environ.get("ASTERISK_SIP_DOMAIN", "asterisk")

    @property
    def api_headers(self) -> dict[str, str]:
        headers = {
            "Authorization": f"Bearer {self.orbital_api_token}",
            "Accept": "application/json",
            "Content-Type": "application/json",
        }

        # Carry the current trace across the process boundary. Laravel's
        # TraceRequest middleware reads this off the inbound request and
        # continues our trace rather than starting an orphan, which is
        # the difference between one story about a call and two
        # unrelated ones. Absent entirely when tracing is off.
        traceparent = observability.traceparent()

        if traceparent:
            headers["traceparent"] = traceparent

        return headers
