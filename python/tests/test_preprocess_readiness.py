"""Readiness and deferred-reduction regression tests for preprocess_service."""

import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "services"))

import preprocess_service as service  # noqa: E402


def test_health_distinguishes_warming_up_from_ready():
    client = service.app.test_client()
    was_ready = service._MODELS_READY.is_set()
    had_failed = service._WARMUP_FAILED.is_set()

    try:
        service._MODELS_READY.clear()
        service._WARMUP_FAILED.clear()
        warming = client.get("/health").get_json()
        assert warming["status"] == "warming_up"
        assert warming["ready"] is False

        service._MODELS_READY.set()
        ready = client.get("/health").get_json()
        assert ready["status"] == "ready"
        assert ready["ready"] is True

        service._WARMUP_FAILED.set()
        failed = client.get("/health").get_json()
        assert failed["status"] == "error"
        assert failed["ready"] is False
    finally:
        if was_ready:
            service._MODELS_READY.set()
        else:
            service._MODELS_READY.clear()
        if had_failed:
            service._WARMUP_FAILED.set()
        else:
            service._WARMUP_FAILED.clear()


def test_mandatory_warmup_does_not_deserialize_the_lazy_umap_artifact(monkeypatch):
    loaded_pickles = []

    monkeypatch.setattr(service, "_runtime_weights", lambda: {})
    monkeypatch.setattr(service, "_load_json_if_exists", lambda filename: ["feature"])
    monkeypatch.setattr(
        service,
        "_load_pickle_if_exists",
        lambda filename: loaded_pickles.append(filename) or object(),
    )

    service._MODELS_READY.clear()
    service._warm_up_models()

    assert service._MODELS_READY.is_set()
    assert "scaler.pkl" in loaded_pickles
    assert "umap_nd.pkl" not in loaded_pickles
    assert "umap_reducer.pkl" not in loaded_pickles


def test_failed_mandatory_warmup_reports_error_instead_of_ready(monkeypatch):
    monkeypatch.setattr(
        service,
        "_runtime_weights",
        lambda: (_ for _ in ()).throw(RuntimeError("broken artifact")),
    )

    service._warm_up_models()

    assert service._MODELS_READY.is_set() is False
    assert service._WARMUP_FAILED.is_set() is True


def test_missing_mandatory_artifact_reports_error_instead_of_ready(monkeypatch):
    monkeypatch.setattr(service, "_runtime_weights", lambda: {})
    monkeypatch.setattr(service, "_load_json_if_exists", lambda filename: ["feature"])
    monkeypatch.setattr(
        service,
        "_load_pickle_if_exists",
        lambda filename: None if filename == "scaler.pkl" else object(),
    )

    service._warm_up_models()

    assert service._MODELS_READY.is_set() is False
    assert service._WARMUP_FAILED.is_set() is True


def test_single_endpoint_can_defer_reduction_for_the_inference_service(monkeypatch):
    calls = []

    def fake_preprocess(payload, *, compute_reduction=True):
        calls.append(compute_reduction)
        return {"status": "success", "reduced_features": [1.0]}

    monkeypatch.setattr(service, "preprocess", fake_preprocess)
    client = service.app.test_client()

    response = client.post("/preprocess?defer_reduction=1", json={"age": 70})

    assert response.status_code == 200
    assert calls == [False]


def test_batch_endpoint_always_defers_per_item_reduction(monkeypatch):
    calls = []

    def fake_preprocess(payload, *, compute_reduction=True):
        calls.append(compute_reduction)
        return {"status": "success"}

    monkeypatch.setattr(service, "preprocess", fake_preprocess)
    client = service.app.test_client()

    response = client.post("/batch_preprocess", json=[{"age": 70}, {"age": 71}])

    assert response.status_code == 200
    assert calls == [False, False]
