"""
python/tests/test_regenerate_model_manifest.py
Unit tests for regenerate_model_manifest.py (TC-ML-02 / TC-DEP-03 fix).

Run:
    python\\venv\\Scripts\\python.exe -m pytest python\\tests\\test_regenerate_model_manifest.py -v
"""
import hashlib
import json
import os
import sys

import pytest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "scripts"))
import regenerate_model_manifest as rmm  # noqa: E402

MODELS_DIR = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "models")


@pytest.fixture
def manifest():
    """Regenerate, then read back, the real manifest — restoring the
    original file afterward so this test never leaves the repo dirty."""
    manifest_path = os.path.join(MODELS_DIR, "model_manifest.json")
    with open(manifest_path, encoding="utf-8") as f:
        original = f.read()

    try:
        assert rmm.main() == 0
        with open(manifest_path, encoding="utf-8") as f:
            yield json.load(f)
    finally:
        with open(manifest_path, "w", encoding="utf-8") as f:
            f.write(original)


def test_manifest_has_model_version(manifest):
    assert manifest["model_version"] == rmm.MODEL_VERSION


def test_manifest_has_no_absolute_or_machine_specific_path(manifest):
    # TC-ML-02: the old manifest embedded a full Windows absolute path in a
    # model_dir key — must not reappear.
    assert "model_dir" not in manifest
    serialized = json.dumps(manifest)
    assert "C:\\" not in serialized
    assert os.path.abspath(MODELS_DIR) not in serialized


def test_manifest_covers_every_real_artifact_file(manifest):
    on_disk = {
        f for f in os.listdir(MODELS_DIR)
        if (f.endswith(".pkl") or f.endswith(".json")) and f != "model_manifest.json"
    }
    assert set(manifest["sha256"].keys()) == on_disk


def test_manifest_hashes_match_real_file_contents(manifest):
    for fname, expected_hash in manifest["sha256"].items():
        path = os.path.join(MODELS_DIR, fname)
        h = hashlib.sha256()
        with open(path, "rb") as f:
            for chunk in iter(lambda: f.read(65536), b""):
                h.update(chunk)
        assert h.hexdigest() == expected_hash, f"{fname}: manifest hash does not match file content"
