# Production agent-worker image — bakes the Python code into the
# layer so the chart's pod doesn't need a bind-mount.
#
# Dev compose (`./vendor/bin/sail up -d`) still uses
# `docker/agent-worker/Dockerfile` with a bind-mount over /app for
# hot-reload. This file is for `docker build` from the agent-worker/
# context for releases pushed to Harbor.

# ---------- Stage 1: wheel / venv builder ----------
# build-essential lives here only — needed for any livekit/silero/
# onnxruntime dep that ships only an sdist for the target arch.
# Keeping it out of the runtime stage drops the Trivy hit count
# significantly (gcc, libc6-dev, binutils, etc. are all gone from
# the final image).
FROM python:3.14-slim AS builder

ENV PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        build-essential \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Isolated venv so the runtime stage can copy a single tree and skip
# any pip / setuptools metadata under /usr/local/lib/python3.12.
# --upgrade-deps bootstraps the venv with the latest pip + setuptools
# from PyPI rather than the bundled-in-Python ensurepip versions, which
# trail PyPI by several months and trip Trivy on pip CVEs.
RUN python -m venv --upgrade-deps /opt/venv
ENV PATH="/opt/venv/bin:$PATH"

COPY requirements.txt /tmp/requirements.txt
RUN pip install -r /tmp/requirements.txt

# ---------- Stage 2: runtime ----------
FROM python:3.14-slim AS runtime

WORKDIR /app

# `apt-get upgrade -y` picks up base-image patches that landed since
# python:3.12-slim was last republished. curl is used by the
# HEALTHCHECK below; ca-certificates for TLS to the Laravel
# heartbeat endpoint + the LiveKit / Anthropic / OpenAI control
# planes. The trailing `pip install --upgrade pip` does the same job
# for python:slim's bundled pip — Trivy scans the base layer's copy
# at /usr/local/lib/python3.12 separately from the venv's, so both
# need to be current. We never invoke base pip at runtime, but it
# still ships in the image.
RUN apt-get update \
    && apt-get upgrade -y \
    && apt-get install -y --no-install-recommends \
        curl \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && pip install --no-cache-dir --upgrade pip

COPY --from=builder /opt/venv /opt/venv
ENV PATH="/opt/venv/bin:$PATH" \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

# Bake every Python module into the image.
COPY . /app/

# Drop privileges. The agent worker only needs outbound network +
# read access to /app, so a plain non-root user with no shell is
# enough. uid 1000 matches the contract used by the laravel image.
RUN useradd --system --uid 1000 --no-create-home --shell /usr/sbin/nologin agent \
    && chown -R agent:agent /app
USER agent

# Heartbeat keeps the Filament admin's Agent Worker health card green.
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS -X POST \
        -H "Authorization: Bearer ${ORBITAL_API_TOKEN}" \
        "${ORBITAL_API_URL%/}/api/agent-worker/heartbeat" >/dev/null || exit 1

CMD ["python", "agent.py", "start"]
