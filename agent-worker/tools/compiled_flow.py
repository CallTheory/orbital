"""Dynamic tool builders for compiled intake flows.

The Laravel-side AgentFlowCompiler emits a list of `function_schemas` —
tool descriptors the LLM may call. This module turns that schema list
into live LiveKit `@function_tool`-decorated callables at worker startup.

The only tool with real upstream behavior in v1 is `search_knowledge`,
which calls POST /api/knowledge/search on the Orbital API. The rest
(set_field, advance_step, lookup_account, etc) are modeled as
conversation-state tools: the worker just logs them and returns a
short acknowledgement, and a follow-up pass will wire them into the
call-log persistence layer.
"""

from __future__ import annotations

import logging
from typing import Any, Callable, List

import requests
from livekit.agents.llm import function_tool
from livekit.agents.voice.events import RunContext

logger = logging.getLogger("orbital-compiled-flow")


def build_dynamic_tools(
    function_schemas: list[dict[str, Any]],
    available_stores: list[dict[str, Any]],
    api_base_url: str,
    api_headers: dict[str, str],
    session_key: str | None = None,
    extension: str | None = None,
) -> List[Callable]:
    """Construct LiveKit function tools from the compiled flow schema.

    Only a small set of well-known tool names get real implementations;
    unknown schemas are skipped with a warning so a future server-side
    tool doesn't crash the worker.

    `session_key` and `extension` are threaded through to set_field and
    advance_step so those tools can write their results to the shared
    call_session_states row that the operator UI also reads from. When
    the worker doesn't know the session_key yet, those tools become
    in-memory no-ops (still logged) so the LLM flow doesn't break.
    """
    tools: List[Callable] = []
    store_ids = [s.get("id") for s in available_stores if s.get("id")]

    for schema in function_schemas:
        name = schema.get("name")
        if not name:
            continue

        if name == "set_field":
            tools.append(_make_set_field_tool(schema, api_base_url, api_headers, session_key, extension))
        elif name == "advance_step":
            tools.append(_make_advance_step_tool(schema, api_base_url, api_headers, session_key, extension))
        elif name == "search_knowledge":
            if not store_ids:
                # Skip registering search_knowledge when there are no
                # reachable stores — the LLM has no way to use it.
                continue
            tools.append(_make_search_knowledge_tool(api_base_url, api_headers, store_ids))
        elif name == "lookup_account":
            tools.append(_make_lookup_account_tool(schema))
        elif name == "send_sms":
            tools.append(_make_send_sms_tool(schema))
        # transfer_call is handled by the static import in agent.py — skip here
        # to avoid double-registering.
        elif name == "transfer_call":
            continue
        else:
            logger.warning(f"Unknown tool schema name '{name}' — skipping")

    return tools


def _make_set_field_tool(
    schema: dict[str, Any],
    api_base_url: str,
    api_headers: dict[str, str],
    session_key: str | None,
    extension: str | None,
) -> Callable:
    description = schema.get(
        "description",
        "Record a collected data field. Use the exact key from the active step.",
    )

    @function_tool(name="set_field", description=description)
    async def set_field(ctx: RunContext, key: str, value: str) -> str:
        logger.info(f"[flow] set_field key={key} value={value!r} session={session_key}")

        # Write-through to Laravel so the operator UI sees captured
        # fields in real time. Non-blocking on failure: if the API is
        # down the LLM conversation continues with a local acknowledgement.
        if session_key:
            try:
                requests.post(
                    f"{api_base_url}/call-sessions/{session_key}/field",
                    headers=api_headers,
                    json={"key": key, "value": value, "extension": extension},
                    timeout=5,
                )
            except requests.RequestException as e:
                logger.warning(f"call-session field write failed: {e}")

        return f"Captured {key}."

    return set_field


def _make_advance_step_tool(
    schema: dict[str, Any],
    api_base_url: str,
    api_headers: dict[str, str],
    session_key: str | None,
    extension: str | None,
) -> Callable:
    description = schema.get(
        "description",
        "Advance to the next objective in the intake flow. Only call when the current objective is satisfied.",
    )

    @function_tool(name="advance_step", description=description)
    async def advance_step(ctx: RunContext, decision: bool | None = None, reason: str | None = None) -> str:
        logger.info(f"[flow] advance_step decision={decision} reason={reason!r} session={session_key}")

        if session_key:
            try:
                requests.post(
                    f"{api_base_url}/call-sessions/{session_key}/advance",
                    headers=api_headers,
                    json={"extension": extension},
                    timeout=5,
                )
            except requests.RequestException as e:
                logger.warning(f"call-session advance write failed: {e}")

        return "Advanced to next objective."

    return advance_step


def _make_search_knowledge_tool(
    api_base_url: str,
    api_headers: dict[str, str],
    store_ids: list[int],
) -> Callable:
    @function_tool(
        name="search_knowledge",
        description=(
            "Search the tenant's knowledge stores for an answer. Use this when the caller "
            "asks a factual question that might be answered from FAQ or policy documents. "
            "Only answer from what the search returns."
        ),
    )
    async def search_knowledge(ctx: RunContext, query: str, top_k: int = 5) -> str:
        logger.info(f"[flow] search_knowledge query={query!r} stores={store_ids}")

        try:
            response = requests.post(
                f"{api_base_url}/knowledge/search",
                headers=api_headers,
                json={
                    "store_ids": store_ids,
                    "query": query,
                    "top_k": max(1, min(int(top_k or 5), 20)),
                },
                timeout=30,
            )
        except requests.RequestException as e:
            logger.error(f"knowledge search failed: {e}")
            return "I couldn't reach the knowledge store right now."

        if response.status_code != 200:
            logger.error(f"knowledge search http {response.status_code}: {response.text}")
            return "I couldn't reach the knowledge store right now."

        results = response.json().get("results") or []
        if not results:
            return "I didn't find anything in the knowledge store for that."

        # Return a compact, citation-friendly blob the LLM can quote from.
        lines = []
        for i, r in enumerate(results, start=1):
            src = r.get("source_ref") or "unknown"
            content = (r.get("content") or "").strip()
            lines.append(f"[{i}] ({src}) {content}")
        return "\n".join(lines)

    return search_knowledge


def _make_lookup_account_tool(schema: dict[str, Any]) -> Callable:
    description = schema.get(
        "description",
        "Look up a customer account by name, phone, email, or account number.",
    )

    @function_tool(name="lookup_account", description=description)
    async def lookup_account(ctx: RunContext, lookup_value: str) -> str:
        logger.info(f"[flow] lookup_account value={lookup_value!r}")
        # Phase-D stub: real lookup wiring lands when the account store is built.
        return "Account lookup not yet wired into this tenant's system."

    return lookup_account


def _make_send_sms_tool(schema: dict[str, Any]) -> Callable:
    description = schema.get("description", "Send an SMS message to a phone number.")

    @function_tool(name="send_sms", description=description)
    async def send_sms(ctx: RunContext, to: str, body: str) -> str:
        logger.info(f"[flow] send_sms to={to!r} body={body!r}")
        return "SMS dispatch not yet wired into this tenant's system."

    return send_sms
