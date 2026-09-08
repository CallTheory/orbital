"""Optional error reporting and tracing for the agent worker.

Both integrations are off unless their environment variables are set, and
both are written so that a missing package, a bad endpoint, or an
unreachable backend degrades to "no telemetry" rather than to a worker
that will not start. A voice agent that fails to answer a call because
its monitoring is misconfigured is a far worse outcome than one with no
monitoring.

Configuration is environment-only, and that is a real constraint rather
than an oversight: `config.py` reads `os.environ` and the worker has no
path for fetching *infrastructure* settings from the Orbital API (it
fetches per-call persona and flow config, which is a different thing).
Values set in Settings -> Platform reach Laravel and Horizon; they do not
reach this process. See docs/ENVIRONMENT.md.

The same PII rule as the PHP side applies here and matters more, because
this process is the one holding the conversation: spans and error reports
carry identifiers - room, call, persona - never caller names, transcripts,
or what anybody said.
"""

import logging
import os

logger = logging.getLogger("orbital-observability")

_tracer = None


def _flag(name: str, default: bool = False) -> bool:
    raw = os.environ.get(name)

    if raw is None:
        return default

    return raw.strip().lower() in ("1", "true", "yes", "on")


def init_error_reporting() -> bool:
    """Wire GlitchTip/Sentry, if a DSN was supplied.

    Returns whether it was enabled, so the caller can log the fact once
    at startup rather than leaving an operator guessing.
    """
    dsn = os.environ.get("ERROR_REPORTING_DSN", "").strip()

    if not dsn or not _flag("ERROR_REPORTING_ENABLED"):
        return False

    try:
        import sentry_sdk
    except ImportError:
        logger.warning("error reporting requested but sentry-sdk is not installed")
        return False

    try:
        sentry_sdk.init(
            dsn=dsn,
            environment=os.environ.get("ERROR_REPORTING_ENVIRONMENT") or os.environ.get("APP_ENV", "production"),
            release=os.environ.get("ORBITAL_VERSION") or None,
            # Never. This process handles live calls; default PII would
            # attach request bodies and user context from the middle of
            # somebody's conversation.
            send_default_pii=False,
            # Traces go to OTLP, and only to OTLP. Two half-populated
            # trace backends is worse than one complete one.
            traces_sample_rate=0.0,
        )
    except Exception as exc:  # noqa: BLE001 - never fail startup over telemetry
        logger.warning("error reporting could not be initialised: %s", exc)
        return False

    logger.info("error reporting enabled")
    return True


def init_tracing():
    """Wire OTLP tracing, if an endpoint was supplied.

    Returns a tracer, or None. Callers must handle None rather than
    assuming a tracer exists - that is what keeps tracing optional.
    """
    global _tracer

    endpoint = os.environ.get("TRACING_ENDPOINT", "").strip()

    if not endpoint or not _flag("TRACING_ENABLED"):
        return None

    try:
        from opentelemetry import trace
        from opentelemetry.exporter.otlp.proto.http.trace_exporter import OTLPSpanExporter
        from opentelemetry.sdk.resources import Resource
        from opentelemetry.sdk.trace import TracerProvider
        from opentelemetry.sdk.trace.export import BatchSpanProcessor
        from opentelemetry.sdk.trace.sampling import ParentBasedTraceIdRatio
    except ImportError:
        logger.warning("tracing requested but the opentelemetry packages are not installed")
        return None

    try:
        resource = Resource.create(
            {
                # A DIFFERENT service name from the Laravel app on
                # purpose. They are separate processes with separate
                # failure modes, and a trace that spans both should say
                # which half was slow.
                "service.name": os.environ.get("TRACING_SERVICE_NAME", "orbital-agent-worker"),
                "service.version": os.environ.get("ORBITAL_VERSION", "dev"),
                "deployment.environment.name": os.environ.get("APP_ENV", "production"),
            }
        )

        provider = TracerProvider(
            resource=resource,
            # Parent-based, matching the PHP side. A call that Laravel
            # decided to trace keeps its worker spans; sampling
            # independently here would put holes in exactly the traces
            # somebody went looking for.
            sampler=ParentBasedTraceIdRatio(float(os.environ.get("TRACING_SAMPLE_RATIO", "0.05"))),
        )
        provider.add_span_processor(
            BatchSpanProcessor(
                OTLPSpanExporter(
                    endpoint=endpoint,
                    headers=_auth_headers(),
                    timeout=int(float(os.environ.get("TRACING_TIMEOUT", "5"))),
                )
            )
        )

        trace.set_tracer_provider(provider)
        _tracer = trace.get_tracer("orbital-agent-worker")
    except Exception as exc:  # noqa: BLE001 - never fail startup over telemetry
        logger.warning("tracing could not be initialised: %s", exc)
        return None

    logger.info("tracing enabled, exporting to %s", endpoint)
    return _tracer


def _auth_headers() -> dict[str, str]:
    """Mirror the PHP side's three auth modes.

    Self-hosted Tempo on a private network usually needs nothing;
    Grafana Cloud uses basic with the numeric instance ID as the
    username; a token-proxied setup uses a bearer.
    """
    import base64

    secret = os.environ.get("TRACING_AUTH_SECRET", "").strip()

    if not secret:
        return {}

    mode = os.environ.get("TRACING_AUTH_MODE", "none").strip().lower()

    if mode == "basic":
        username = os.environ.get("TRACING_AUTH_USERNAME", "")
        encoded = base64.b64encode(f"{username}:{secret}".encode()).decode()
        return {"Authorization": f"Basic {encoded}"}

    if mode == "bearer":
        return {"Authorization": f"Bearer {secret}"}

    return {}


def traceparent() -> str | None:
    """The current span as a W3C traceparent, for outbound API calls.

    This one header is what turns two independent span streams into one
    call story: Laravel's TraceRequest middleware reads it off the
    inbound request and continues the worker's trace instead of starting
    an orphan.
    """
    if _tracer is None:
        return None

    try:
        from opentelemetry import trace
        from opentelemetry.propagate import inject

        if not trace.get_current_span().get_span_context().is_valid:
            return None

        carrier: dict[str, str] = {}
        inject(carrier)

        return carrier.get("traceparent")
    except Exception:  # noqa: BLE001
        return None
