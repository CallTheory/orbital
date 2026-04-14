"""Configuration for the Orbital agent worker."""

import os


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
        return {
            "Authorization": f"Bearer {self.orbital_api_token}",
            "Accept": "application/json",
            "Content-Type": "application/json",
        }
