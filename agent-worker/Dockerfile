# Production agent-worker image — bakes the Python code into the
# layer so the chart's pod doesn't need a bind-mount.
#
# Dev compose (`./vendor/bin/sail up -d`) still uses
# `docker/agent-worker/Dockerfile` with a bind-mount over /app for
# hot-reload. This file is for `docker build` from the agent-worker/
# context for releases pushed to Harbor.

FROM python:3.12-slim AS base

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential \
        curl \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Pull in deps first so layer caching saves time on code-only changes.
COPY requirements.txt /tmp/requirements.txt
RUN pip install --no-cache-dir -r /tmp/requirements.txt && rm /tmp/requirements.txt

# Bake every Python module into the image.
COPY . /app/

# Heartbeat keeps the Filament admin's Agent Worker health card green.
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS -X POST \
        -H "Authorization: Bearer ${ORBITAL_API_TOKEN}" \
        "${ORBITAL_API_URL%/}/api/agent-worker/heartbeat" >/dev/null || exit 1

CMD ["python", "agent.py", "start"]
