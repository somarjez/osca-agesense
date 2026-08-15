"""Combined OSCA preprocessing and inference HTTP service.

This module preserves the public contracts of the original separate Flask
services while running both stages in one Render process. The underlying
preprocess_service and inference_service functions remain unchanged.
"""

import logging
import os
import threading

# Configure these before importing inference_service, which imports UMAP/numba.
os.environ.setdefault("NUMBA_THREADING_LAYER", "workqueue")
os.environ.setdefault("NUMBA_NUM_THREADS", "1")
os.environ.setdefault("OMP_NUM_THREADS", "1")

import inference_service  # noqa: E402
import preprocess_service  # noqa: E402
from flask import Flask, jsonify, request  # noqa: E402

app = Flask(__name__)
app.config["MAX_CONTENT_LENGTH"] = 5 * 1024 * 1024

logger = logging.getLogger(__name__)
EXPECTED_TOKEN = os.environ.get("ML_SERVICE_TOKEN", "")

# These aliases are deliberately kept at module scope so the wrapper can be
# tested and so the endpoint behavior remains easy to compare with the
# original services.
preprocess = preprocess_service.preprocess
infer = inference_service.infer


@app.before_request
def _check_internal_token():
    if request.path == "/health":
        return
    if EXPECTED_TOKEN and request.headers.get("X-Internal-Api-Key") != EXPECTED_TOKEN:
        return jsonify({"status": "error", "message": "Unauthorized"}), 401


@app.route("/health", methods=["GET"])
def health():
    pre_failed = preprocess_service._WARMUP_FAILED.is_set()
    inf_failed = inference_service._WARMUP_FAILED.is_set()
    ready = (
        preprocess_service._MODELS_READY.is_set()
        and inference_service._MODELS_READY.is_set()
        and not pre_failed
        and not inf_failed
    )
    return jsonify({
        "status": "error" if pre_failed or inf_failed else ("ready" if ready else "warming_up"),
        "service": "osca-ml",
        "ready": ready,
        "models_ready": ready,
        "preprocess_ready": preprocess_service._MODELS_READY.is_set() and not pre_failed,
        "inference_ready": inference_service._MODELS_READY.is_set() and not inf_failed,
    })


@app.route("/preprocess", methods=["POST"])
def preprocess_endpoint():
    try:
        raw = request.get_json(force=True)
        if not raw or not isinstance(raw, dict):
            return jsonify({"status": "error", "message": "Expected JSON object payload"}), 400

        logger.info("Preprocess request: senior_id=%s", raw.get("senior_id"))
        defer_reduction = request.args.get("defer_reduction", "").strip().lower() in {
            "1", "true", "yes",
        }
        result = preprocess(raw, compute_reduction=not defer_reduction)
        return jsonify(result)
    except Exception as exc:
        logger.exception("Preprocessing error")
        return jsonify({"status": "error", "message": str(exc)}), 500


@app.route("/batch_preprocess", methods=["POST"])
def batch_preprocess_endpoint():
    try:
        batch = request.get_json(force=True)
        if not isinstance(batch, list):
            return jsonify({"status": "error", "message": "Expected JSON array"}), 400

        logger.info("Batch preprocess request: %d items", len(batch))
        results = []
        for idx, item in enumerate(batch):
            if not isinstance(item, dict):
                results.append({"status": "error", "message": f"Item {idx} is not an object"})
                continue
            try:
                results.append(preprocess(item, compute_reduction=False))
            except Exception as exc:
                logger.exception("Batch preprocess error at index %d", idx)
                results.append({"status": "error", "message": str(exc)})

        return jsonify({"status": "success", "count": len(results), "results": results})
    except Exception as exc:
        logger.exception("Batch preprocessing error")
        return jsonify({"status": "error", "message": str(exc)}), 500


@app.route("/infer", methods=["POST"])
def infer_endpoint():
    try:
        payload = request.get_json(force=True)
        if not payload or not isinstance(payload, dict):
            return jsonify({"status": "error", "message": "Expected JSON object payload"}), 400

        logger.info("Infer request: senior_id=%s", payload.get("senior_id"))
        return jsonify(infer(payload))
    except Exception as exc:
        logger.exception("Inference error")
        return jsonify({"status": "error", "message": str(exc)}), 500


@app.route("/batch_infer", methods=["POST"])
def batch_infer_endpoint():
    try:
        batch = request.get_json(force=True)
        if not isinstance(batch, list):
            return jsonify({"status": "error", "message": "Expected JSON array"}), 400

        logger.info("Batch infer request: %d items", len(batch))
        results = []
        for idx, item in enumerate(batch):
            if not isinstance(item, dict):
                results.append({"status": "error", "message": f"Item at index {idx} is not an object"})
                continue
            try:
                results.append(infer(item))
            except Exception as exc:
                logger.exception("Batch infer error at index %d", idx)
                results.append({"status": "error", "message": str(exc)})

        return jsonify({"status": "success", "count": len(results), "results": results})
    except Exception as exc:
        logger.exception("Batch inference error")
        return jsonify({"status": "error", "message": str(exc)}), 500


@app.route("/model_insights", methods=["GET"])
def model_insights():
    try:
        return jsonify(inference_service._build_model_insights())
    except Exception as exc:
        logger.exception("model_insights error")
        return jsonify({"error": str(exc)}), 500


def _warm_up_models() -> None:
    """Warm both existing service caches before reporting combined readiness."""
    threads = [
        threading.Thread(
            target=preprocess_service._warm_up_models,
            name="preprocess-warmup",
        ),
        threading.Thread(
            target=inference_service._warm_up_models,
            name="inference-warmup",
        ),
    ]
    for thread in threads:
        thread.start()
    for thread in threads:
        thread.join()


if __name__ == "__main__":
    port = int(os.environ.get("PORT", "10000"))
    logger.info("Starting OSCA ML combined service on port %s", port)
    inference_service._validate_artifacts_at_startup()
    threading.Thread(target=_warm_up_models, daemon=True, name="combined-warmup").start()
    try:
        from waitress import serve
        logger.info("Serving via waitress on port %s", port)
        serve(app, host="0.0.0.0", port=port, threads=8)
    except ImportError:
        logger.warning("waitress not installed; falling back to Flask development server")
        app.run(host="0.0.0.0", port=port, debug=False, use_reloader=False)
