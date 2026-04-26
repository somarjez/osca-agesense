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

from inference_service import infer
from preprocess_service import preprocess

MODES = {"preprocess", "infer", "combined", "batch"}


def run_combined(raw: dict) -> dict:
    """Preprocess + infer in one Python process. Avoids double cold-start."""
    preprocessed = preprocess(raw)
    return infer(preprocessed)


def run_batch(payloads: list) -> list:
    """
    Process many seniors in one Python process.
    Models are loaded once (lru_cache) and reused for all items.
    OSCA_BATCH_MODE=1 is set so UMAP is skipped inside preprocess/infer,
    which is the main bottleneck for large batches on Windows.
    Returns list of {success, data} or {success, error}.
    """
    os.environ["OSCA_BATCH_MODE"] = "1"
    results = []
    for item in payloads:
        try:
            preprocessed = preprocess(item)
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
