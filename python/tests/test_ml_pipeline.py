"""
Lightweight validation script for the OSCA ML pipeline.
Run from the repository root or python/tests:
    python python/tests/test_ml_pipeline.py

Covers:
  1. Single-senior combined inference (preprocess + infer) still works.
  2. Batch inference returns real KMeans cluster IDs when model files exist.
  3. Batch inference does NOT warn "heuristic cluster assignment used" when
     KMeans/UMAP are available.
  4. Missing asset_weights.json does not crash preprocessing.
  5. Missing cluster_metadata.json does not crash inference.
  6. Modified cluster_metadata.json changes returned cluster names at runtime.
  7. Modified asset_weights.json changes relevant scoring output at runtime.
"""

import os
import sys
import json
import copy
import shutil
import tempfile

# Ensure python/services is on the path so module imports resolve correctly.
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "services"))

# Set numba env before any import that pulls in umap/numba.
os.environ.setdefault("NUMBA_THREADING_LAYER", "workqueue")
os.environ.setdefault("NUMBA_NUM_THREADS", "1")
os.environ.setdefault("OMP_NUM_THREADS", "1")

# ---------------------------------------------------------------------------
# Minimal synthetic senior payload
# ---------------------------------------------------------------------------
SAMPLE_SENIOR = {
    "first_name": "Test",
    "last_name": "Senior",
    "barangay": "Barangay 1",
    "age": 72,
    "gender": "Female",
    "marital_status": "Widowed",
    "educational_attainment": "High School Graduate",
    "monthly_income_range": "1,000 - 5,000",
    "num_children": 3,
    "num_working_children": 1,
    "household_size": 2,
    "child_financial_support": "Occasional",
    "spouse_working": "Deceased",
    "income_source": ["own pension", "dependent on children"],
    "real_assets": ["house & lot"],
    "movable_assets": ["mobile phone"],
    "living_with": ["children"],
    "household_condition": [],
    "community_service": ["senior citizen association member"],
    "specialization": ["cooking"],
    "has_medical_checkup": True,
    "medical_concern": "hypertension",
    "dental_concern": "tooth loss",
    "optical_concern": "cataract",
    "hearing_concern": "healthy hearing",
    "social_emotional_concern": "loneliness",
    "healthcare_difficulty": "cost",
    "qol_responses": {
        "qol_enjoy_life": 3, "qol_life_satisfaction": 3,
        "qol_future_outlook": 3, "qol_meaningfulness": 3,
        "phy_energy": 3, "phy_pain_r": 3, "phy_health_limit_r": 3,
        "phy_mobility_outside": 3, "phy_mobility_indoor": 4,
        "psych_happiness": 3, "psych_peace": 3,
        "psych_lonely_r": 2, "psych_confidence": 3,
        "func_independence": 4, "func_autonomy": 3, "func_control": 3,
        "env_income_limit_r": 2, "soc_social_support": 3,
        "soc_close_friend": 3, "soc_participation": 3,
        "soc_opportunity": 3, "soc_respect": 4,
        "env_safe_home": 4, "env_safe_neighborhood": 4,
        "env_service_access": 3, "env_home_comfort": 3,
        "env_fin_medical": 2, "env_fin_household": 2, "env_fin_personal": 2,
        "spi_belief_comfort": 4, "spi_belief_practice": 4,
    },
}

PASS = "\033[32mPASS\033[0m"
FAIL = "\033[31mFAIL\033[0m"
_failures: list = []


def _check(name: str, condition: bool, detail: str = "") -> None:
    tag = PASS if condition else FAIL
    msg = f"  [{tag}] {name}"
    if not condition and detail:
        msg += f"\n         → {detail}"
    print(msg)
    if not condition:
        _failures.append(name)


# ---------------------------------------------------------------------------
# Helpers to temporarily redirect MODEL_DIR
# ---------------------------------------------------------------------------
def _patch_model_dir(new_dir: str):
    import preprocess_service
    import inference_service
    preprocess_service.MODEL_DIR = new_dir
    inference_service.MODEL_DIR  = new_dir
    # Bust lru_caches that depend on MODEL_DIR
    preprocess_service._load_json_if_exists.cache_clear()
    preprocess_service._load_pickle_if_exists.cache_clear()
    preprocess_service._runtime_weights.cache_clear()
    inference_service._load_model.cache_clear()
    inference_service._load_json.cache_clear()
    inference_service._load_cluster_profiles.cache_clear()
    inference_service._load_cluster_mapping.cache_clear()
    inference_service._load_notebook_cluster_index.cache_clear()
    inference_service._load_notebook_recommendation_index.cache_clear()


def _original_model_dir():
    """Return the first existing candidate from inference_service's resolver."""
    import inference_service
    return inference_service._resolve_model_dir()


# ---------------------------------------------------------------------------
# Test 1 — single combined inference
# ---------------------------------------------------------------------------
def test_single_inference():
    print("\n[Test 1] Single combined inference")
    import preprocess_service
    import inference_service
    try:
        preprocessed = preprocess_service.preprocess(SAMPLE_SENIOR)
        result = inference_service.infer(preprocessed)
        _check("status == success",   result.get("status") == "success")
        _check("cluster present",     "cluster" in result)
        _check("named_id in [1,2,3]", result["cluster"]["named_id"] in (1, 2, 3))
        _check("risk_scores present", "risk_scores" in result)
        _check("composite_risk in [0,1]",
               0.0 <= result["risk_scores"]["composite_risk"] <= 1.0)
        _check("recommendations non-empty", len(result.get("recommendations", [])) > 0)
    except Exception as exc:
        _check("no exception raised", False, str(exc))


# ---------------------------------------------------------------------------
# Test 2 — batch inference uses real KMeans (not heuristic)
# ---------------------------------------------------------------------------
def test_batch_real_kmeans():
    print("\n[Test 2] Batch inference uses real KMeans when models are available")
    import preprocess_service
    import inference_service
    from local_ml_runner import run_batch

    # Clear OSCA_BATCH_MODE from a previous run
    os.environ.pop("OSCA_BATCH_MODE", None)

    payloads = [copy.deepcopy(SAMPLE_SENIOR) for _ in range(3)]
    # Give them slightly different ages so they are distinct records
    for i, p in enumerate(payloads):
        p["age"] = 70 + i

    results = run_batch(payloads)
    _check("all items succeeded", all(r.get("success") for r in results),
           str([r.get("error") for r in results if not r.get("success")]))

    for i, r in enumerate(results):
        if not r.get("success"):
            continue
        infer_result = r["data"]
        warns = infer_result.get("warnings", [])
        heuristic_used = any("heuristic cluster assignment used" in w for w in warns)
        _check(f"item {i}: no heuristic fallback", not heuristic_used,
               f"warnings={warns}")
        _check(f"item {i}: named_id in [1,2,3]",
               infer_result["cluster"]["named_id"] in (1, 2, 3))


# ---------------------------------------------------------------------------
# Test 3 — missing asset_weights.json does not crash
# ---------------------------------------------------------------------------
def test_missing_asset_weights():
    print("\n[Test 3] Missing asset_weights.json does not crash preprocessing")
    import preprocess_service

    orig_dir = _original_model_dir()
    tmp = tempfile.mkdtemp()
    try:
        # Copy all model files EXCEPT asset_weights.json (skip subdirectories)
        for fname in os.listdir(orig_dir):
            src = os.path.join(orig_dir, fname)
            if fname != "asset_weights.json" and os.path.isfile(src):
                shutil.copy2(src, os.path.join(tmp, fname))

        _patch_model_dir(tmp)
        try:
            result = preprocess_service.preprocess(SAMPLE_SENIOR)
            _check("no exception",        result.get("status") == "success")
            _check("scaled_features present", len(result.get("scaled_features", [])) > 0)
        except Exception as exc:
            _check("no exception", False, str(exc))
    finally:
        _patch_model_dir(orig_dir)
        shutil.rmtree(tmp, ignore_errors=True)


# ---------------------------------------------------------------------------
# Test 4 — missing cluster_metadata.json does not crash inference
# ---------------------------------------------------------------------------
def test_missing_cluster_metadata():
    print("\n[Test 4] Missing cluster_metadata.json does not crash inference")
    import preprocess_service
    import inference_service

    orig_dir = _original_model_dir()
    tmp = tempfile.mkdtemp()
    try:
        for fname in os.listdir(orig_dir):
            src = os.path.join(orig_dir, fname)
            if fname != "cluster_metadata.json" and os.path.isfile(src):
                shutil.copy2(src, os.path.join(tmp, fname))

        _patch_model_dir(tmp)
        try:
            preprocessed = preprocess_service.preprocess(SAMPLE_SENIOR)
            result = inference_service.infer(preprocessed)
            _check("no exception",        result.get("status") == "success")
            _check("cluster still returned", "cluster" in result)
        except Exception as exc:
            _check("no exception", False, str(exc))
    finally:
        _patch_model_dir(orig_dir)
        shutil.rmtree(tmp, ignore_errors=True)


# ---------------------------------------------------------------------------
# Test 5 — modified cluster_metadata.json changes cluster name at runtime
# ---------------------------------------------------------------------------
def test_cluster_metadata_dynamic():
    print("\n[Test 5] Modified cluster_metadata.json changes cluster name without code changes")
    import preprocess_service
    import inference_service

    orig_dir = _original_model_dir()
    tmp = tempfile.mkdtemp()
    try:
        for fname in os.listdir(orig_dir):
            src = os.path.join(orig_dir, fname)
            if os.path.isfile(src):
                shutil.copy2(src, os.path.join(tmp, fname))

        # Write a cluster_metadata.json with a custom name for cluster 1
        custom_meta = {
            "1": {
                "name": "CUSTOM_CLUSTER_NAME_TEST",
                "ic_level": "High", "env_level": "High", "func_level": "High",
                "interpretation": "Custom description.",
            },
            "2": {"name": "Cluster 2", "ic_level": "Moderate",
                  "env_level": "Moderate", "func_level": "Moderate",
                  "interpretation": "Moderate."},
            "3": {"name": "Cluster 3", "ic_level": "Low",
                  "env_level": "Low", "func_level": "Low",
                  "interpretation": "Low."},
        }
        with open(os.path.join(tmp, "cluster_metadata.json"), "w") as f:
            json.dump(custom_meta, f)

        _patch_model_dir(tmp)

        preprocessed = preprocess_service.preprocess(SAMPLE_SENIOR)
        result = inference_service.infer(preprocessed)

        if result["cluster"]["named_id"] == 1:
            _check("cluster name reflects JSON",
                   result["cluster"]["name"] == "CUSTOM_CLUSTER_NAME_TEST",
                   f"got: {result['cluster']['name']!r}")
        else:
            # Senior landed in cluster 2 or 3; verify those names are from JSON too
            cid = result["cluster"]["named_id"]
            expected = custom_meta[str(cid)]["name"]
            _check(f"cluster {cid} name reflects JSON",
                   result["cluster"]["name"] == expected,
                   f"got: {result['cluster']['name']!r}")
    except Exception as exc:
        _check("no exception", False, str(exc))
    finally:
        _patch_model_dir(orig_dir)
        shutil.rmtree(tmp, ignore_errors=True)


# ---------------------------------------------------------------------------
# Test 6 — modified asset_weights.json changes scoring at runtime
# ---------------------------------------------------------------------------
def test_asset_weights_dynamic():
    print("\n[Test 6] Modified asset_weights.json changes scoring without code changes")
    import preprocess_service

    orig_dir = _original_model_dir()
    tmp = tempfile.mkdtemp()
    try:
        for fname in os.listdir(orig_dir):
            src = os.path.join(orig_dir, fname)
            if os.path.isfile(src):
                shutil.copy2(src, os.path.join(tmp, fname))

        # Baseline score with original weights
        result_orig = preprocess_service.preprocess(SAMPLE_SENIOR)
        orig_real = result_orig["feature_map"].get("real_asset_score", 0.0)

        # Write modified asset_weights.json: set house & lot weight to 0.01
        orig_weights_path = os.path.join(orig_dir, "asset_weights.json")
        with open(orig_weights_path) as f:
            aw = json.load(f)
        aw_modified = copy.deepcopy(aw)
        aw_modified["real_assets"]["house & lot"] = 0.01
        aw_modified["real_assets"]["house and lot"] = 0.01

        modified_path = os.path.join(tmp, "asset_weights.json")
        with open(modified_path, "w") as f:
            json.dump(aw_modified, f)

        _patch_model_dir(tmp)
        result_mod = preprocess_service.preprocess(SAMPLE_SENIOR)
        mod_real = result_mod["feature_map"].get("real_asset_score", 0.0)

        _check("real_asset_score differs when weights change",
               abs(orig_real - mod_real) > 0.01,
               f"orig={orig_real:.4f}  modified={mod_real:.4f}")
        _check("modified weight is lower (house & lot -> 0.01)",
               mod_real < orig_real,
               f"expected mod_real < orig_real")
    except Exception as exc:
        _check("no exception", False, str(exc))
    finally:
        _patch_model_dir(orig_dir)
        shutil.rmtree(tmp, ignore_errors=True)


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
if __name__ == "__main__":
    print("=" * 60)
    print("OSCA ML Pipeline — validation tests")
    print("=" * 60)

    test_single_inference()
    test_batch_real_kmeans()
    test_missing_asset_weights()
    test_missing_cluster_metadata()
    test_cluster_metadata_dynamic()
    test_asset_weights_dynamic()

    print("\n" + "=" * 60)
    if _failures:
        print(f"RESULT: {len(_failures)} test(s) FAILED — {_failures}")
        sys.exit(1)
    else:
        print("RESULT: all tests PASSED")
        sys.exit(0)
