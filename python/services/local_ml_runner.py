import os

# Must be set BEFORE importing anything that loads numba/UMAP.
# Prevents WinError 10106 (Winsock provider init failure) and
# asyncio/base_events corruption when UMAP.transform() is called
# multiple times in the same process on Windows.
os.environ.setdefault("NUMBA_THREADING_LAYER", "workqueue")
os.environ.setdefault("NUMBA_NUM_THREADS", "1")
os.environ.setdefault("OMP_NUM_THREADS", "1")

import json
import sys
import traceback

from inference_service import batch_cluster_assign, infer
from preprocess_service import preprocess

MODES = {"preprocess", "infer", "combined", "batch"}


def run_combined(raw: dict) -> dict:
    """Preprocess + infer in one Python process. Avoids double cold-start."""
    preprocessed = preprocess(raw)
    return infer(preprocessed)


def run_batch(payloads: list) -> list:
    """
    Process many seniors in one Python process.

    Pipeline:
      1. Preprocess all seniors with OSCA_BATCH_MODE=1 (skips per-senior UMAP).
      2. Run one batch UMAP + KMeans pass via batch_cluster_assign().
         Each preprocessed dict gets _precomputed_raw_cluster_id injected.
      3. Call infer() for each senior; it uses the precomputed cluster and
         skips UMAP/KMeans entirely.

    If batch UMAP/KMeans is unavailable or fails, infer() falls back to the
    wellbeing-heuristic path transparently.
    """
    os.environ["OSCA_BATCH_MODE"] = "1"

    # Step 1 — preprocess all seniors (UMAP skipped inside preprocess in batch mode)
    preprocessed_list: list = []
    for item in payloads:
        try:
            preprocessed_list.append(preprocess(item))
        except Exception as exc:
            preprocessed_list.append({"status": "error", "error": str(exc)})

    # Step 2 — one-shot batch UMAP + KMeans; injects _precomputed_raw_cluster_id
    cluster_warnings = batch_cluster_assign(preprocessed_list)
    for w in cluster_warnings:
        print(f"[batch_cluster_assign] {w}", file=sys.stderr)

    # Step 3 — infer for each senior using precomputed cluster assignment
    results: list = []
    for preprocessed in preprocessed_list:
        if isinstance(preprocessed, dict) and preprocessed.get("status") == "error":
            results.append({"success": False, "error": preprocessed.get("error", "preprocessing failed")})
            continue
        try:
            result = infer(preprocessed)
            results.append({"success": True, "data": result})
        except Exception as exc:
            results.append({"success": False, "error": str(exc)})

    return results


def main() -> int:
    if len(sys.argv) != 2 or sys.argv[1] not in MODES:
        print(f"Usage: local_ml_runner.py [{' | '.join(MODES)}]", file=sys.stderr)
        return 2

    mode = sys.argv[1]
    try:
        payload = json.load(sys.stdin)

        if mode == "preprocess":
            result = preprocess(payload)
        elif mode == "infer":
            result = infer(payload)
        elif mode == "combined":
            result = run_combined(payload)
        else:  # batch
            if not isinstance(payload, list):
                print("batch mode expects a JSON array", file=sys.stderr)
                return 2
            result = run_batch(payload)

        json.dump(result, sys.stdout)
        return 0
    except Exception:
        traceback.print_exc(file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
