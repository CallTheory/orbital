"""
Orbital whisper.cpp multi-model HTTP wrapper.

Wraps `whisper-cli` so the CHOICE OF MODEL is per-request instead of
baked into a single long-running whisper-server process. Tenants
pick their model in the admin UI; the VoicemailTranscriber
includes it as a form field on each /inference POST.

All model files live on disk under MODELS_DIR. Selecting a model
that isn't bundled returns HTTP 400 with a clear error so the
operator knows they need to rebuild the image with that model
added to the MODELS build arg.

Concurrency: FastAPI + uvicorn handle the HTTP layer; each
transcription is a subprocess so two parallel requests load two
model instances into RAM simultaneously. For voicemail volume
that's fine — the spikes are tiny relative to a real telephony
load.
"""

from __future__ import annotations

import json
import os
import subprocess
import tempfile
import uuid
from pathlib import Path

from fastapi import FastAPI, Form, HTTPException, UploadFile, File
from fastapi.responses import JSONResponse

MODELS_DIR = Path(os.environ.get("WHISPER_MODELS_DIR", "/opt/whisper/models"))
WHISPER_BIN = os.environ.get("WHISPER_BIN", "/usr/local/bin/whisper-cli")

app = FastAPI(title="orbital-whisper-local", version="1.0")


def _model_path(model: str) -> Path:
    """
    Resolve a user-supplied model name (e.g. `base.en`) to an on-disk
    ggml file. Reject names that can't map to a bundled file so we
    never exec whisper-cli with a dangling path.
    """
    # Defensive against path traversal — model names must be bare
    # identifiers matching what whisper.cpp's download-ggml-model.sh
    # produces (tiny, tiny.en, base, base.en, small, small.en,
    # medium, medium.en, large-v1, large-v2, large-v3, large-v3-turbo).
    if not model.replace(".", "").replace("-", "").isalnum():
        raise HTTPException(status_code=400, detail=f"Invalid model name: {model!r}")

    candidate = MODELS_DIR / f"ggml-{model}.bin"
    if not candidate.is_file():
        available = sorted(p.stem.removeprefix("ggml-") for p in MODELS_DIR.glob("ggml-*.bin"))
        raise HTTPException(
            status_code=400,
            detail=f"Model {model!r} not bundled. Available: {', '.join(available) or 'none'}",
        )
    return candidate


@app.get("/")
def healthz() -> dict[str, object]:
    """
    Root liveness probe. Lists the models baked into this image so
    the admin UI can surface the real set to tenants without the
    backend having to second-guess build args.
    """
    available = sorted(p.stem.removeprefix("ggml-") for p in MODELS_DIR.glob("ggml-*.bin"))
    return {"ok": True, "models": available}


@app.post("/inference")
async def inference(
    file: UploadFile = File(...),
    model: str = Form("base.en"),
    language: str | None = Form(None),
) -> JSONResponse:
    """
    Transcribe an uploaded audio file with the chosen model.

    Request (multipart/form-data):
      - file     WAV / MP3 / M4A etc. — whatever ffmpeg can decode
      - model    bundled model id, e.g. "base.en" or "large-v3-turbo"
      - language optional ISO-639-1 hint for multilingual models;
                 leave unset to let whisper auto-detect

    Response (application/json):
      { "text": "transcript", "language": "en", "model": "..." }
    """
    model_file = _model_path(model)

    # Save upload to a scratch path. whisper-cli reads from disk
    # and writes its JSON result alongside the input file.
    scratch = Path(tempfile.gettempdir()) / f"orbital-whisper-{uuid.uuid4().hex}.wav"
    scratch.write_bytes(await file.read())

    cmd = [
        WHISPER_BIN,
        "-m", str(model_file),
        "-f", str(scratch),
        "--output-json-full",
        "--no-prints",
    ]
    if language:
        cmd.extend(["-l", language])

    try:
        proc = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=300,
        )
        if proc.returncode != 0:
            raise HTTPException(
                status_code=500,
                detail=f"whisper-cli exit {proc.returncode}: {proc.stderr[:400]}",
            )

        json_path = scratch.with_suffix(scratch.suffix + ".json")
        if not json_path.is_file():
            raise HTTPException(
                status_code=500,
                detail="whisper-cli did not produce a JSON transcript",
            )

        data = json.loads(json_path.read_text())

        # Shape varies between whisper.cpp versions — transcription
        # can live under either `transcription` (array of segments)
        # or `text` (single string). Normalise so drivers always
        # consume `text`.
        transcript = ""
        if isinstance(data.get("transcription"), list):
            transcript = "".join(seg.get("text", "") for seg in data["transcription"])
        elif isinstance(data.get("text"), str):
            transcript = data["text"]

        return JSONResponse(
            {
                "text": transcript.strip(),
                "language": data.get("params", {}).get("language") or data.get("language"),
                "model": model,
            }
        )
    finally:
        for p in (scratch, scratch.with_suffix(scratch.suffix + ".json")):
            try:
                p.unlink()
            except FileNotFoundError:
                pass
