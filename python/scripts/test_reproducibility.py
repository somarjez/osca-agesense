"""
OSCA ML Reproducibility Test
==============================
Verifies that the inference pipeline returns bit-identical results when the
same senior citizen data is processed twice.

Tests:
  1. Single-call reproducibility  — same payload → same output (x2)
  2. Batch vs single consistency  — /batch_infer and /infer on the same record
                                    must produce identical scores and cluster

The script fetches one senior record directly from the database, runs it
through /preprocess then /infer (or /batch_infer), and compares results.
If the services are not running it will print a clear error and exit.

SAFE: Read-only.  Does not write to the database or filesystem.

Usage (from osca-system/ project root):
    python/venv/Scripts/python.exe python/scripts/test_reproducibility.py

    # Point at a specific senior:
    python/venv/Scripts/python.exe python/scripts/test_reproducibility.py --senior-id 42

    # Override service ports:
    python/venv/Scripts/python.exe python/scripts/test_reproducibility.py \
        --preprocess-port 5001 --infer-port 5002

Exit codes:
    0 - all checks PASS
    1 - one or more checks FAIL
    2 - infrastructure error (services down, DB unreachable, no seniors found)
"""

import argparse
import datetime
import decimal
import json
import os
import sys
import time

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

# ── Locate project root ────────────────────────────────────────────────────────
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_DIR   = os.path.dirname(os.path.dirname(SCRIPT_DIR))   # osca-system/

# ── Optional dependencies (hard errors with clear messages) ───────────────────
try:
    import requests
except ImportError:
    print("FATAL: 'requests' package not found.")
    print("       Install it: python/venv/Scripts/python.exe -m pip install requests")
    sys.exit(2)

try:
    import pymysql
except ImportError:
    print("FATAL: 'pymysql' package not found.")
    print("       Install it: python/venv/Scripts/python.exe -m pip install pymysql")
    sys.exit(2)


# ── .env reader ───────────────────────────────────────────────────────────────
def _read_dotenv(name: str):
    for candidate in [os.path.join(BASE_DIR, ".env"), os.path.join(BASE_DIR, "..", ".env")]:
        if os.path.exists(candidate):
            try:
                for line in open(candidate, encoding="utf-8"):
                    line = line.strip()
                    if line and not line.startswith("#") and "=" in line:
                        k, _, v = line.partition("=")
                        if k.strip() == name:
                            return v.strip().strip('"').strip("'")
            except Exception:
                pass
    return None


def _env(name: str, default: str = "") -> str:
    return os.environ.get(name) or _read_dotenv(name) or default


# ── DB helpers ────────────────────────────────────────────────────────────────
def _db_connect():
    try:
        return pymysql.connect(
            host=_env("DB_HOST", "127.0.0.1"),
            port=int(_env("DB_PORT", "3306")),
            user=_env("DB_USERNAME", "root"),
            password=_env("DB_PASSWORD", ""),
            database=_env("DB_DATABASE", "osca_db"),
            charset="utf8mb4",
            cursorclass=pymysql.cursors.DictCursor,
            connect_timeout=5,
        )
    except Exception as exc:
        print(f"FATAL: Cannot connect to database: {exc}")
        sys.exit(2)


def _fetch_senior_payload(conn, senior_id: int | None):
    """
    Fetch the raw payload that Laravel's MlService::buildRawPayload() would send
    to /preprocess for one senior.  Uses the actual column names from the DB.
    Returns (senior_id, payload_dict).
    """
    with conn.cursor() as cur:
        if senior_id:
            cur.execute(
                "SELECT id FROM senior_citizens WHERE id = %s LIMIT 1", (senior_id,)
            )
        else:
            cur.execute(
                "SELECT sc.id FROM senior_citizens sc "
                "JOIN ml_results mr ON mr.senior_citizen_id = sc.id "
                "ORDER BY sc.id LIMIT 1"
            )
        row = cur.fetchone()
        if not row:
            if senior_id:
                print(f"FATAL: Senior citizen id={senior_id} not found in database.")
            else:
                print("FATAL: No senior citizens with ml_results found in database.")
            sys.exit(2)
        sid = int(row["id"])

        # Pull senior profile — columns match actual senior_citizens schema
        cur.execute(
            """
            SELECT id, first_name, last_name, barangay, age, gender,
                   marital_status, educational_attainment, monthly_income_range,
                   num_children, num_working_children, household_size,
                   child_financial_support, spouse_working,
                   income_source, real_assets, movable_assets,
                   living_with, household_condition, community_service,
                   specialization, medical_concern, dental_concern,
                   optical_concern, hearing_concern, social_emotional_concern,
                   healthcare_difficulty, has_medical_checkup, checkup_schedule
            FROM senior_citizens WHERE id = %s
            """,
            (sid,),
        )
        profile = cur.fetchone() or {}

        # Pull most recent QoL survey — table is qol_surveys, not osca_qol_assessments
        cur.execute(
            """
            SELECT *
            FROM qol_surveys
            WHERE senior_citizen_id = %s
            ORDER BY id DESC LIMIT 1
            """,
            (sid,),
        )
        qol = cur.fetchone() or {}

        # Pull stored rule scores from ml_results for completeness
        cur.execute(
            """
            SELECT rule_composite, risk_medical, risk_financial, risk_social,
                   risk_functional, risk_housing, risk_hc_access, risk_sensory
            FROM ml_results
            WHERE senior_citizen_id = %s
            ORDER BY id DESC LIMIT 1
            """,
            (sid,),
        )
        cur.fetchone() or {}

    # Build payload matching MlService::buildRawPayload() exactly.
    # JSON-encoded array columns are stored as strings in the DB — decode them.
    def _arr(val):
        if val is None:
            return []
        if isinstance(val, list):
            return val
        try:
            import json as _j
            parsed = _j.loads(val)
            return parsed if isinstance(parsed, list) else [parsed]
        except Exception:
            return [val] if val else []

    full_name = f"{profile.get('first_name', '')} {profile.get('last_name', '')}".strip()

    payload = {
        # Top-level keys matching buildRawPayload()
        "senior_id":               sid,
        "first_name":              profile.get("first_name", ""),
        "last_name":               profile.get("last_name", ""),
        "barangay":                profile.get("barangay", ""),
        "age":                     profile.get("age", 70),
        "gender":                  profile.get("gender", ""),
        "marital_status":          profile.get("marital_status", ""),
        "educational_attainment":  profile.get("educational_attainment", ""),
        "monthly_income_range":    profile.get("monthly_income_range", ""),
        "num_children":            profile.get("num_children", 0),
        "num_working_children":    profile.get("num_working_children", 0),
        "household_size":          profile.get("household_size", 1),
        "child_financial_support": profile.get("child_financial_support", False),
        "spouse_working":          profile.get("spouse_working", False),
        "income_source":           _arr(profile.get("income_source")),
        "real_assets":             _arr(profile.get("real_assets")),
        "movable_assets":          _arr(profile.get("movable_assets")),
        "living_with":             _arr(profile.get("living_with")),
        "household_condition":     _arr(profile.get("household_condition")),
        "community_service":       _arr(profile.get("community_service")),
        "specialization":          _arr(profile.get("specialization")),
        "medical_concern":         _arr(profile.get("medical_concern")),
        "dental_concern":          _arr(profile.get("dental_concern")),
        "optical_concern":         _arr(profile.get("optical_concern")),
        "hearing_concern":         _arr(profile.get("hearing_concern")),
        "social_emotional_concern":_arr(profile.get("social_emotional_concern")),
        "healthcare_difficulty":   _arr(profile.get("healthcare_difficulty")),
        "has_medical_checkup":     bool(profile.get("has_medical_checkup"))
                                   and profile.get("checkup_schedule") != "No Follow-up",
        "qol_responses":           {k: v for k, v in qol.items()
                                    if k not in ("id", "senior_citizen_id",
                                                 "created_at", "updated_at",
                                                 "survey_version", "survey_date")},
        # Identity block for inference_service notebook_cache lookup
        "identity": {
            "senior_id": sid,
            "full_name": full_name,
            "barangay":  profile.get("barangay", ""),
        },
    }
    return sid, payload


# ── JSON serializer — handles MySQL Decimal / date types ──────────────────────
class _DBEncoder(json.JSONEncoder):
    def default(self, obj):
        if isinstance(obj, decimal.Decimal):
            return float(obj)
        if isinstance(obj, (datetime.date, datetime.datetime)):
            return obj.isoformat()
        return super().default(obj)


def _serialize(data) -> str:
    return json.dumps(data, cls=_DBEncoder)


# ── Service callers ───────────────────────────────────────────────────────────
def _post(url: str, data: dict, label: str) -> dict:
    try:
        resp = requests.post(
            url,
            data=_serialize(data),
            headers={"Content-Type": "application/json"},
            timeout=30,
        )
        resp.raise_for_status()
        return resp.json()
    except requests.exceptions.ConnectionError:
        print(f"FATAL: Cannot reach {label} at {url}")
        print("       Make sure the Flask service is running before running this test.")
        sys.exit(2)
    except Exception as exc:
        print(f"FATAL: {label} request failed: {exc}")
        sys.exit(2)


def _get(url: str, label: str) -> dict:
    try:
        resp = requests.get(url, timeout=5)
        resp.raise_for_status()
        return resp.json()
    except requests.exceptions.ConnectionError:
        print(f"FATAL: Cannot reach {label} at {url}")
        print("       Make sure the Flask service is running before running this test.")
        sys.exit(2)
    except Exception as exc:
        print(f"FATAL: {label} health check failed: {exc}")
        sys.exit(2)


# ── Comparison helpers ────────────────────────────────────────────────────────
_CHECKS: list[tuple[str, str]] = []   # (label, pass|fail|warn)
_FAILURES = 0


def _check(label: str, val_a, val_b, *, is_float: bool = False) -> bool:
    global _FAILURES
    if is_float:
        ok = abs(float(val_a) - float(val_b)) < 1e-9
    else:
        ok = val_a == val_b
    tag = "PASS" if ok else "FAIL"
    _CHECKS.append((label, tag, str(val_a), str(val_b)))
    if not ok:
        _FAILURES += 1
    return ok


def _section(title: str):
    print(f"\n{'─' * 60}")
    print(f"  {title}")
    print(f"{'─' * 60}")


def _compare_results(label_a: str, res_a: dict, label_b: str, res_b: dict):
    """Compare two infer() results and register checks."""

    def _g(d, *keys):
        v = d
        for k in keys:
            if not isinstance(v, dict):
                return None
            v = v.get(k)
        return v

    fields = [
        # (human label, path in result dict, is_float)
        ("cluster.raw_id",     ("cluster", "raw_id"),     False),
        ("cluster.named_id",   ("cluster", "named_id"),   False),
        ("cluster.name",       ("cluster", "name"),        False),
        ("ic_risk",            ("risk_scores", "ic_risk"),   True),
        ("env_risk",           ("risk_scores", "env_risk"),  True),
        ("func_risk",          ("risk_scores", "func_risk"), True),
        ("composite_risk",     ("risk_scores", "composite_risk"), True),
        ("risk_levels.overall",("risk_levels", "overall"),  False),
        ("risk_levels.ic",     ("risk_levels", "ic"),       False),
        ("risk_levels.env",    ("risk_levels", "env"),      False),
        ("risk_levels.func",   ("risk_levels", "func"),     False),
        ("priority_flag",      ("priority_flag",),          False),
    ]

    for human, path, as_float in fields:
        a = _g(res_a, *path)
        b = _g(res_b, *path)
        _check(f"{label_a} vs {label_b} — {human}", a, b, is_float=as_float)

    # Recommendations: compare count and first item text (order must be identical)
    recs_a = res_a.get("recommendations", [])
    recs_b = res_b.get("recommendations", [])
    _check(f"{label_a} vs {label_b} — recommendation count", len(recs_a), len(recs_b))
    if recs_a and recs_b:
        _check(
            f"{label_a} vs {label_b} — first recommendation text",
            recs_a[0].get("action", ""),
            recs_b[0].get("action", ""),
        )


# ── Main ──────────────────────────────────────────────────────────────────────
def main():
    parser = argparse.ArgumentParser(description="OSCA ML reproducibility test")
    parser.add_argument("--senior-id",       type=int, default=None)
    parser.add_argument("--preprocess-port", type=int, default=None)
    parser.add_argument("--infer-port",      type=int, default=None)
    args = parser.parse_args()

    preprocess_port = args.preprocess_port or int(_env("PYTHON_PREPROCESS_PORT", "5001"))
    infer_port      = args.infer_port      or int(_env("PYTHON_INFERENCE_PORT",  "5002"))

    preprocess_url = f"http://127.0.0.1:{preprocess_port}"
    infer_url      = f"http://127.0.0.1:{infer_port}"

    print("=" * 60)
    print("  OSCA ML Reproducibility Test")
    print("=" * 60)

    # ── 0. Health checks ──────────────────────────────────────────
    _section("0. Service Health Checks")
    h_pre  = _get(f"{preprocess_url}/health", "preprocess service")
    h_inf  = _get(f"{infer_url}/health",      "inference service")
    print(f"  Preprocess service: {h_pre.get('status','?')}")
    print(f"  Inference service:  {h_inf.get('status','?')}")
    print(f"  Model version:      {h_inf.get('model_version','?')}")
    print(f"  Model dir:          {h_inf.get('model_dir','?')}")
    print(f"  Notebook overrides: {h_inf.get('notebook_overrides_enabled','?')}")

    # ── 1. Fetch senior ───────────────────────────────────────────
    _section("1. Load Senior from Database")
    conn = _db_connect()
    senior_id, raw_payload = _fetch_senior_payload(conn, args.senior_id)
    conn.close()
    full_name = f"{raw_payload.get('first_name','')} {raw_payload.get('last_name','')}".strip()
    print(f"  Senior citizen id:  {senior_id}")
    print(f"  Name:               {full_name}")
    print(f"  Barangay:           {raw_payload.get('barangay','')}")
    nb_overrides = _env("ENABLE_NOTEBOOK_OVERRIDES", "false").lower() in ("1", "true", "yes", "on")
    print(f"  ENABLE_NOTEBOOK_OVERRIDES is {'true' if nb_overrides else 'false'}")
    print()
    print("  NOTE: If ENABLE_NOTEBOOK_OVERRIDES=true, the inference service returns")
    print("  the stored notebook_cache result from the DB, which is always identical.")
    print("  Set ENABLE_NOTEBOOK_OVERRIDES=false to test the live model path.")

    # ── 2. Preprocess (once, reused for both infer calls) ─────────
    _section("2. Preprocess Senior Data")
    preprocessed = _post(f"{preprocess_url}/preprocess", raw_payload, "preprocess service")
    if preprocessed.get("status") == "error":
        print(f"FATAL: Preprocess returned error: {preprocessed.get('message')}")
        sys.exit(2)
    print(f"  Preprocessing: OK  ({len(preprocessed.get('scaled_features', []))} scaled features)")

    # ── 3. Single-call reproducibility ────────────────────────────
    _section("3. Single-Call Reproducibility (run 1 vs run 2)")
    print("  Running inference twice with identical preprocessed payload...")

    t0 = time.perf_counter()
    result_1 = _post(f"{infer_url}/infer", preprocessed, "inference service (run 1)")
    t1 = time.perf_counter()
    result_2 = _post(f"{infer_url}/infer", preprocessed, "inference service (run 2)")
    t2 = time.perf_counter()

    print(f"  Run 1 latency: {(t1-t0)*1000:.1f} ms")
    print(f"  Run 2 latency: {(t2-t1)*1000:.1f} ms")

    if result_1.get("status") == "error":
        print(f"FATAL: Inference run 1 error: {result_1.get('message')}")
        sys.exit(2)
    if result_2.get("status") == "error":
        print(f"FATAL: Inference run 2 error: {result_2.get('message')}")
        sys.exit(2)

    # Print summary of run 1
    c = result_1.get("cluster", {})
    r = result_1.get("risk_scores", {})
    lv = result_1.get("risk_levels", {})
    pf = result_1.get("priority_flag", False)
    print("\n  Run 1 results:")
    print(f"    cluster_raw={c.get('raw_id')}  named_id={c.get('named_id')}  name={c.get('name')}")
    print(f"    ic_risk={r.get('ic_risk')}  env_risk={r.get('env_risk')}  func_risk={r.get('func_risk')}")
    print(f"    composite_risk={r.get('composite_risk')}  level={lv.get('overall')}  urgent={pf}")
    print(f"    prediction_source={result_1.get('model_metadata', {}).get('prediction_source','?')}")

    _compare_results("run1", result_1, "run2", result_2)

    # ── 4. Batch vs single consistency ────────────────────────────
    _section("4. Batch vs Single Consistency")
    print("  Running /batch_infer with single-item list...")
    batch_payload = [preprocessed]
    batch_response = _post(f"{infer_url}/batch_infer", batch_payload, "inference service (batch)")

    if batch_response.get("status") == "error":
        print(f"FATAL: Batch inference error: {batch_response.get('message')}")
        sys.exit(2)

    batch_results = batch_response.get("results", [])
    if not batch_results:
        print("FATAL: Batch inference returned empty results list.")
        sys.exit(2)

    result_batch = batch_results[0]
    if result_batch.get("status") == "error":
        print(f"FATAL: Batch item 0 error: {result_batch.get('message')}")
        sys.exit(2)

    _compare_results("single", result_1, "batch", result_batch)

    # ── 5. Results summary ────────────────────────────────────────
    _section("5. Results Summary")
    pass_count = sum(1 for _, tag, _, _ in _CHECKS if tag == "PASS")
    fail_count = sum(1 for _, tag, _, _ in _CHECKS if tag == "FAIL")

    col_w = 56
    for label, tag, val_a, val_b in _CHECKS:
        tag_str = f"[{tag}]"
        if tag == "FAIL":
            detail = f"  got={val_b!r}  expected={val_a!r}"
            print(f"  {tag_str:<8} {label:<{col_w}} {detail}")
        else:
            print(f"  {tag_str:<8} {label}")

    print()
    print(f"  Total: {pass_count} PASS  {fail_count} FAIL  out of {len(_CHECKS)} checks")
    print()

    if _FAILURES == 0:
        print("  RESULT: PASS — pipeline is deterministic and batch/single results are identical.")
        return 0
    else:
        print("  RESULT: FAIL — see failed checks above.")
        print()
        if h_inf.get("notebook_overrides_enabled"):
            print("  HINT: ENABLE_NOTEBOOK_OVERRIDES=true — results are read from the DB cache.")
            print("        Failures here indicate the DB cache has inconsistent rows.")
            print("        Run: php artisan ml:repair-notebook-cache to fix.")
        else:
            print("  HINT: Non-deterministic live model results indicate one of:")
            print("    - UMAP transform is not using fixed threading (check NUMBA_NUM_THREADS=1)")
            print("    - Artifact bundle mismatch (run validate_model_artifacts.py)")
            print("    - Different model dirs selected on consecutive calls (check _resolve_model_dir)")
        return 1


if __name__ == "__main__":
    sys.exit(main())
