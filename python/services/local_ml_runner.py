import json
import sys
import traceback

from inference_service import infer
from preprocess_service import preprocess


def main() -> int:
    if len(sys.argv) != 2 or sys.argv[1] not in {"preprocess", "infer"}:
        print("Usage: local_ml_runner.py [preprocess|infer]", file=sys.stderr)
        return 2

    try:
        payload = json.load(sys.stdin)
        result = preprocess(payload) if sys.argv[1] == "preprocess" else infer(payload)
        json.dump(result, sys.stdout)
        return 0
    except Exception:
        traceback.print_exc(file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
