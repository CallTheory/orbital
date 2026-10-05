FROM python:3.14-slim

WORKDIR /app

# `curl` is needed by the docker HEALTHCHECK below to post heartbeats to
# the Laravel app, which the dashboard reads to mark the worker as alive.
RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential \
    curl \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

# The livekit-agents CLI requires a subcommand. `start` runs the worker in
# production mode, registering with the LiveKit server and waiting for
# dispatch.
CMD ["python", "agent.py", "start"]

# Heartbeat to Laravel every 30s so the dashboard's Agent Worker health
# check stays green. The endpoint is gated by ORBITAL_API_TOKEN.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS -X POST \
        -H "Authorization: Bearer ${ORBITAL_API_TOKEN}" \
        "${ORBITAL_API_URL%/}/agent-worker/heartbeat" >/dev/null || exit 1
