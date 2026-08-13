"""
regenerate_model_manifest.py
=============================================================
Regenerates python/models/model_manifest.json from the actual files
currently present in that directory — SHA-256 per file, model_version, and
a generation timestamp. No absolute/machine-specific paths are recorded.

TC-ML-02 / TC-DEP-03 (audit finding): the previous manifest (a) covered only
5 of the ~20+ real artifact files (the .pkl files that actually produce
scores — the GBR/RFR risk models, KNN classifier, encoders — were entirely
unmanifested), (b) had no model_version key, and (c) embedded a
machine-specific absolute Windows path in model_dir, making the manifest
useless as a portable integrity check on any other machine (including
Render). This script fixes all three by deriving the manifest purely from
what's actually on disk, relative to this directory.

Does NOT copy, retrain, or modify any model artifact — read-only against
every file except model_manifest.json itself. Safe to re-run after any
retrain/artifact update; that's the intended workflow (this replaces
hand-editing the manifest).

Run from repo root:
    python\\venv\\Scripts\\python.exe python\\scripts\\regenerate_model_manifest.py
"""
import hashlib
import json
import os
import sys
from datetime import datetime, timezone

MODELS_DIR = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "models")

# Must match MODEL_VERSION in python/services/inference_service.py and
# App\Services\MlService::MODEL_VERSION (app/Services/MlService.php) — all
# three should be bumped together per those files' own comments.
MODEL_VERSION = "2.0.0"

MANIFEST_FILENAME = "model_manifest.json"


def _sha256(path: str) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()


def main() -> int:
    if not os.path.isdir(MODELS_DIR):
        print(f"[ERROR] Models directory not found: {MODELS_DIR}", file=sys.stderr)
        return 1

    checksums = {}
    for fname in sorted(os.listdir(MODELS_DIR)):
        if fname == MANIFEST_FILENAME:
            continue
        if not (fname.endswith(".pkl") or fname.endswith(".json")):
            continue
        checksums[fname] = _sha256(os.path.join(MODELS_DIR, fname))

    if not checksums:
        print(f"[ERROR] No .pkl/.json artifact files found in {MODELS_DIR}", file=sys.stderr)
        return 1

    manifest = {
        "model_version": MODEL_VERSION,
        "generated_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "sha256": checksums,
    }

    manifest_path = os.path.join(MODELS_DIR, MANIFEST_FILENAME)
    with open(manifest_path, "w", encoding="utf-8") as f:
        json.dump(manifest, f, indent=2)
        f.write("\n")

    print(f"[OK] {MANIFEST_FILENAME}: {len(checksums)} files hashed, model_version={MODEL_VERSION}")
    print(f"     Written to: {manifest_path}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
