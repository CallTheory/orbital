# Multi-model whisper.cpp inference service.
#
# Bundles a curated set of ggml models and exposes a thin Python
# HTTP wrapper (server.py) that spawns `whisper-cli` per request
# with the caller's chosen model + optional language. Swapping
# models between calls is a subprocess start — fine for voicemail
# volume where a transcription already takes 10–30 seconds.
#
# The WHISPER_MODELS build arg is a comma-separated list. Default
# set covers the common English + multilingual needs without
# blowing the image past ~1.5GB:
#
#   tiny.en          75MB   fast English-only
#   base.en         142MB   better English-only
#   small           466MB   multilingual, decent accuracy
#   large-v3-turbo  809MB   multilingual, best accuracy/size ratio
#
# Opt into medium (1.5GB) or large-v3 (3GB) by passing them in
# the build arg, e.g.
#   docker compose build --build-arg WHISPER_MODELS=base.en,medium whisper-local

FROM debian:bookworm-slim AS builder

# v1.7.5 is the first release that ships the example binaries with
# the `whisper-` prefix (whisper-cli, whisper-server, ...). Older
# 1.7.x tags still build them as `main`. The post-build rename step
# in this stage handles both names so bumping or pinning the
# version down doesn't break anyone.
ARG WHISPER_VERSION=v1.7.5
ARG WHISPER_MODELS=tiny.en,base.en,small,large-v3-turbo

RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential \
        cmake \
        git \
        ca-certificates \
        curl \
    && rm -rf /var/lib/apt/lists/*

RUN git clone --depth 1 --branch ${WHISPER_VERSION} \
        https://github.com/ggerganov/whisper.cpp.git /build/whisper

WORKDIR /build/whisper

# Build everything. The CLI binary name varied across whisper.cpp
# releases (`main` until ~v1.7.4, then `whisper-cli`), so a
# no-target build is the safest way to produce something usable
# without pinning too tightly. The extra built examples don't ship
# to the final image — we only COPY the one binary we need.
RUN cmake -B build -DCMAKE_BUILD_TYPE=Release \
    && cmake --build build --config Release -j $(nproc)

# Normalise the binary name at /out/whisper-cli regardless of
# which whisper.cpp release we built. Fails loud if neither name
# landed so a broken build doesn't silently ship.
RUN mkdir -p /out \
    && if [ -f build/bin/whisper-cli ]; then \
           cp build/bin/whisper-cli /out/whisper-cli; \
       elif [ -f build/bin/main ]; then \
           cp build/bin/main /out/whisper-cli; \
       else \
           echo "[whisper-local] no whisper-cli or main binary in build/bin/" >&2; \
           ls -la build/bin/ >&2; \
           exit 1; \
       fi

# Download every model from the build arg list. `download-ggml-model.sh`
# writes into ./models/ as ggml-<name>.bin. Iterating in shell keeps
# the layer one step regardless of how many models we pull.
RUN set -e; \
    IFS=','; for MODEL in $WHISPER_MODELS; do \
        echo "[whisper-local] pulling $MODEL"; \
        ./models/download-ggml-model.sh "$MODEL"; \
    done

FROM debian:bookworm-slim

# Runtime deps: libgomp for whisper's OpenMP, ffmpeg for non-WAV
# audio decoding, ca-certificates for any upstream fetches, tini
# to reap the Python + whisper-cli subprocess trees cleanly.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libgomp1 \
        ffmpeg \
        ca-certificates \
        tini \
        curl \
        python3 \
        python3-pip \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --system --no-create-home --shell /usr/sbin/nologin whisper

# Install the Python HTTP wrapper's deps into the system
# interpreter. --break-system-packages avoids the Debian-bookworm
# PEP-668 guard for containers where we don't share Python with
# anything else.
RUN pip3 install --no-cache-dir --break-system-packages \
        'fastapi==0.115.0' \
        'uvicorn[standard]==0.30.6' \
        'python-multipart==0.0.12'

COPY --from=builder /out/whisper-cli /usr/local/bin/whisper-cli
# Copy ALL bundled model files. The wildcard expands over every
# ggml-*.bin the builder stage downloaded.
RUN mkdir -p /opt/whisper/models
COPY --from=builder /build/whisper/models/ggml-*.bin /opt/whisper/models/

COPY server.py /opt/whisper/server.py

ENV WHISPER_MODELS_DIR=/opt/whisper/models \
    WHISPER_BIN=/usr/local/bin/whisper-cli

USER whisper
EXPOSE 9700

ENTRYPOINT ["tini", "--"]
CMD ["python3", "-m", "uvicorn", "--app-dir", "/opt/whisper", "server:app", "--host", "0.0.0.0", "--port", "9700"]
