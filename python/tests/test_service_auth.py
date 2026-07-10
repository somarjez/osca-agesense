"""
Flask API authentication tests for preprocess_service.py and inference_service.py.

Verifies the before_request X-Internal-Api-Key check added for Task 8:
  - /health stays open regardless of ML_SERVICE_TOKEN (polled by Laravel's
    MlService::startServices()/stopServices()/healthCheck() before/without
    auth context).
  - Data-bearing routes (preprocess/batch_preprocess/infer/batch_infer, and
    /model_insights) reject requests with a missing or wrong
    X-Internal-Api-Key once a token is configured.
  - An empty token disables enforcement entirely (local-dev default).

Uses Flask's test client (real request dispatch through before_request),
not a live server. Module-level EXPECTED_TOKEN is monkeypatched directly per
test rather than relying on import-time env vars, since other test files in
this directory (test_inference_e2e.py) may import the same modules first.
"""
import os
import sys

os.environ.setdefault(
    "ML_MODELS_PATH", os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "models"))
)
os.environ.setdefault("ENABLE_NOTEBOOK_OVERRIDES", "false")
os.environ.setdefault("NUMBA_THREADING_LAYER", "workqueue")
os.environ.setdefault("NUMBA_NUM_THREADS", "1")
os.environ.setdefault("OMP_NUM_THREADS", "1")

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "services"))

import inference_service as inference_mod  # noqa: E402
import preprocess_service as preprocess_mod  # noqa: E402

TEST_TOKEN = "test-secret-token"


def _set_token(module, token):
    """Swap module.EXPECTED_TOKEN for the duration of one test; returns prior value."""
    prior = module.EXPECTED_TOKEN
    module.EXPECTED_TOKEN = token
    return prior


# ── preprocess_service.py ───────────────────────────────────────────────────

def test_preprocess_health_open_regardless_of_token():
    prior = _set_token(preprocess_mod, TEST_TOKEN)
    try:
        client = preprocess_mod.app.test_client()
        assert client.get("/health").status_code == 200
    finally:
        preprocess_mod.EXPECTED_TOKEN = prior


def test_preprocess_rejects_missing_or_wrong_token():
    prior = _set_token(preprocess_mod, TEST_TOKEN)
    try:
        client = preprocess_mod.app.test_client()

        resp = client.post("/preprocess", json={"age": 70})
        assert resp.status_code == 401

        resp = client.post(
            "/preprocess", json={"age": 70}, headers={"X-Internal-Api-Key": "wrong-token"}
        )
        assert resp.status_code == 401

        resp = client.post(
            "/preprocess", json={"age": 70}, headers={"X-Internal-Api-Key": TEST_TOKEN}
        )
        assert resp.status_code != 401
    finally:
        preprocess_mod.EXPECTED_TOKEN = prior


def test_batch_preprocess_rejects_missing_token():
    prior = _set_token(preprocess_mod, TEST_TOKEN)
    try:
        client = preprocess_mod.app.test_client()
        resp = client.post("/batch_preprocess", json=[{"age": 70}])
        assert resp.status_code == 401
    finally:
        preprocess_mod.EXPECTED_TOKEN = prior


def test_preprocess_auth_disabled_when_token_empty():
    prior = _set_token(preprocess_mod, "")
    try:
        client = preprocess_mod.app.test_client()
        resp = client.post("/preprocess", json={"age": 70})
        assert resp.status_code != 401
    finally:
        preprocess_mod.EXPECTED_TOKEN = prior


# ── inference_service.py ────────────────────────────────────────────────────

def test_inference_health_open_regardless_of_token():
    prior = _set_token(inference_mod, TEST_TOKEN)
    try:
        client = inference_mod.app.test_client()
        assert client.get("/health").status_code == 200
    finally:
        inference_mod.EXPECTED_TOKEN = prior


def test_infer_rejects_missing_or_wrong_token():
    prior = _set_token(inference_mod, TEST_TOKEN)
    try:
        client = inference_mod.app.test_client()

        resp = client.post("/infer", json={"senior_id": 1})
        assert resp.status_code == 401

        resp = client.post(
            "/infer", json={"senior_id": 1}, headers={"X-Internal-Api-Key": "wrong-token"}
        )
        assert resp.status_code == 401

        resp = client.post(
            "/infer", json={"senior_id": 1}, headers={"X-Internal-Api-Key": TEST_TOKEN}
        )
        assert resp.status_code != 401
    finally:
        inference_mod.EXPECTED_TOKEN = prior


def test_batch_infer_rejects_missing_token():
    prior = _set_token(inference_mod, TEST_TOKEN)
    try:
        client = inference_mod.app.test_client()
        resp = client.post("/batch_infer", json=[{"senior_id": 1}])
        assert resp.status_code == 401
    finally:
        inference_mod.EXPECTED_TOKEN = prior


def test_model_insights_is_not_exempted_like_health():
    """/model_insights is a GET route but NOT /health — it must still be gated."""
    prior = _set_token(inference_mod, TEST_TOKEN)
    try:
        client = inference_mod.app.test_client()
        resp = client.get("/model_insights")
        assert resp.status_code == 401

        resp = client.get("/model_insights", headers={"X-Internal-Api-Key": TEST_TOKEN})
        assert resp.status_code != 401
    finally:
        inference_mod.EXPECTED_TOKEN = prior


def test_inference_auth_disabled_when_token_empty():
    prior = _set_token(inference_mod, "")
    try:
        client = inference_mod.app.test_client()
        resp = client.post("/infer", json={"senior_id": 1})
        assert resp.status_code != 401
    finally:
        inference_mod.EXPECTED_TOKEN = prior
