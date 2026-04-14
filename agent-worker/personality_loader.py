"""Loads agent persona configuration from the Orbital API."""

import logging

import requests

from config import Config

logger = logging.getLogger("orbital-personality")


class PersonalityLoader:
    def __init__(self, config: Config):
        self.config = config

    async def fetch_persona_by_extension(self, extension: str) -> dict | None:
        """Fetch persona + compiled intake flow from the Orbital API by
        extension number.

        The response is now a two-part envelope:
          {
            "data": { ...persona fields... },
            "compiled_flow": { llm_instructions, function_schemas, ... }
          }

        This method returns the envelope as-is so callers can reach into
        either half. The `compiled_flow` block is always present — when
        no flow is bound, the compiler still fills `llm_instructions`
        with the persona's baseline prompt so the worker has something
        to build a session from.
        """
        try:
            response = requests.get(
                f"{self.config.orbital_api_url}/agent-personas/by-extension/{extension}",
                headers=self.config.api_headers,
                timeout=10,
            )

            if response.status_code == 200:
                body = response.json()
                return {
                    "persona": body.get("data") or {},
                    "compiled_flow": body.get("compiled_flow") or {},
                }
            elif response.status_code == 404:
                logger.warning(f"No persona found for extension {extension}")
                return None
            else:
                logger.error(f"API error {response.status_code}: {response.text}")
                return None

        except requests.RequestException as e:
            logger.error(f"Failed to fetch persona for extension {extension}: {e}")
            return None

    async def fetch_persona(self, persona_id: int) -> dict | None:
        """Fetch persona configuration by ID."""
        try:
            response = requests.get(
                f"{self.config.orbital_api_url}/agent-personas/{persona_id}",
                headers=self.config.api_headers,
                timeout=10,
            )

            if response.status_code == 200:
                return response.json().get("data")
            else:
                logger.error(f"API error {response.status_code}: {response.text}")
                return None

        except requests.RequestException as e:
            logger.error(f"Failed to fetch persona {persona_id}: {e}")
            return None

    async def fetch_coworker_directory(self, exclude_extension: str | None = None) -> list[dict]:
        """Fetch directory of all agent extensions for coworker awareness."""
        try:
            response = requests.get(
                f"{self.config.orbital_api_url}/extensions",
                headers=self.config.api_headers,
                params={"type": "ai_agent"},
                timeout=10,
            )

            if response.status_code == 200:
                extensions = response.json().get("data", [])
                if exclude_extension:
                    extensions = [e for e in extensions if e.get("number") != exclude_extension]
                return extensions
            else:
                return []

        except requests.RequestException:
            return []
