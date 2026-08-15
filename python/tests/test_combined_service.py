"""Contract tests for the combined preprocessing/inference HTTP service."""

import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "services"))

import combined_service as service  # noqa: E402


def test_health_requires_both_stages_to_be_ready():
    pre_ready = service.preprocess_service._MODELS_READY.is_set()
    pre_failed = service.preprocess_service._WARMUP_FAILED.is_set()
    inf_ready = service.inference_service._MODELS_READY.is_set()
    inf_failed = service.inference_service._WARMUP_FAILED.is_set()

    try:
        service.preprocess_service._MODELS_READY.clear()
        service.preprocess_service._WARMUP_FAILED.clear()
        service.inference_service._MODELS_READY.set()
        service.inference_service._WARMUP_FAILED.clear()

        client = service.app.test_client()
        warming = client.get("/health").get_json()

        assert warming["status"] == "warming_up"
        assert warming["ready"] is False

        service.preprocess_service._MODELS_READY.set()
        ready = client.get("/health").get_json()

        assert ready["status"] == "ready"
        assert ready["ready"] is True
        assert ready["service"] == "osca-ml"
    finally:
        if pre_ready:
            service.preprocess_service._MODELS_READY.set()
        else:
            service.preprocess_service._MODELS_READY.clear()
        if pre_failed:
            service.preprocess_service._WARMUP_FAILED.set()
        else:
            service.preprocess_service._WARMUP_FAILED.clear()
        if inf_ready:
            service.inference_service._MODELS_READY.set()
        else:
            service.inference_service._MODELS_READY.clear()
        if inf_failed:
            service.inference_service._WARMUP_FAILED.set()
        else:
            service.inference_service._WARMUP_FAILED.clear()


def test_health_is_public_but_data_routes_require_the_internal_token(monkeypatch):
    monkeypatch.setattr(service, "EXPECTED_TOKEN", "test-token")
    client = service.app.test_client()

    assert client.get("/health").status_code == 200
    assert client.post("/preprocess", json={"age": 70}).status_code == 401


def test_preprocess_endpoint_preserves_deferred_reduction_contract(monkeypatch):
    calls = []

    def fake_preprocess(payload, *, compute_reduction=True):
        calls.append(compute_reduction)
        return {"status": "success", "feature_map": {}}

    monkeypatch.setattr(service, "EXPECTED_TOKEN", "")
    monkeypatch.setattr(service, "preprocess", fake_preprocess)
    client = service.app.test_client()

    response = client.post("/preprocess?defer_reduction=1", json={"age": 70})

    assert response.status_code == 200
    assert response.get_json()["status"] == "success"
    assert calls == [False]


def test_infer_endpoint_delegates_to_the_existing_inference_function(monkeypatch):
    received = []

    def fake_infer(payload):
        received.append(payload)
        return {"status": "success", "risk_levels": {"overall": "LOW"}}

    monkeypatch.setattr(service, "EXPECTED_TOKEN", "")
    monkeypatch.setattr(service, "infer", fake_infer)
    client = service.app.test_client()

    payload = {"feature_map": {"age": 70}}
    response = client.post("/infer", json=payload)

    assert response.status_code == 200
    assert response.get_json()["risk_levels"]["overall"] == "LOW"
    assert received == [payload]
