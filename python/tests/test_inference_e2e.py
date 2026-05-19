"""
End-to-end inference test — runs preprocess + infer on a synthetic senior payload.
Validates: cluster assignment is in range, risk scores in [0,1], priority flag correct,
urgency consistent with priority flag, recommendations non-empty, and XAI fields present.
"""
import os
import sys

os.environ["ML_MODELS_PATH"] = os.path.abspath(
    os.path.join(os.path.dirname(__file__), "..", "models")
)
os.environ["ENABLE_NOTEBOOK_OVERRIDES"] = "false"
os.environ["OSCA_BATCH_MODE"] = "1"
os.environ["NUMBA_THREADING_LAYER"] = "workqueue"
os.environ["NUMBA_NUM_THREADS"] = "1"
os.environ["OMP_NUM_THREADS"] = "1"

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "services"))

from preprocess_service import preprocess
from inference_service import infer, batch_cluster_assign

SENIORS = [
    # Likely HIGH / urgent — old age, no assets, heavy disease burden, poor QoL
    {
        "label": "High-risk (urgent candidate)",
        "raw": {
            "age": 84, "gender": "Female", "marital_status": "Widowed",
            "educational_attainment": "Elementary Level",
            "monthly_income_range": "Below 1,000",
            "income_source": ["dependent on children"],
            "real_assets": ["no known assets"],
            "movable_assets": ["no known assets"],
            "living_with": [],
            "household_condition": ["informal settler"],
            "community_service": [],
            "specialization": [],
            "num_children": 3, "num_working_children": 1,
            "household_size": 1, "child_financial_support": False,
            "spouse_working": False,
            "medical_concern": "hypertension, diabetes, chronic kidney disease",
            "dental_concern": "tooth loss",
            "optical_concern": "cataract",
            "hearing_concern": "hearing impairment",
            "social_emotional_concern": "depression, loneliness",
            "healthcare_difficulty": ["cost", "distance"],
            "has_medical_checkup": False,
            "qol_responses": {k: 1 for k in [
                "qol_enjoy_life","qol_life_satisfaction","qol_future_outlook","qol_meaningfulness",
                "phy_energy","phy_pain_r","phy_health_limit_r","phy_mobility_outside","phy_mobility_indoor",
                "psych_happiness","psych_peace","psych_lonely_r","psych_confidence",
                "func_independence","func_autonomy","func_control","env_income_limit_r",
                "soc_social_support","soc_close_friend","soc_participation","soc_opportunity","soc_respect",
                "env_safe_home","env_safe_neighborhood","env_service_access","env_home_comfort",
                "env_fin_medical","env_fin_household","env_fin_personal",
                "spi_belief_comfort","spi_belief_practice",
            ]},
        },
        "expect_level": "HIGH",
        "expect_cluster": 3,
    },
    # Likely LOW — healthy, assets, pension, social engagement, good QoL
    {
        "label": "Low-risk (healthy candidate)",
        "raw": {
            "age": 68, "gender": "Male", "marital_status": "Married",
            "educational_attainment": "College Graduate",
            "monthly_income_range": "20,000 - 30,000",
            "income_source": ["own pension", "own earnings"],
            "real_assets": ["house & lot"],
            "movable_assets": ["automobile", "mobile phone"],
            "living_with": ["spouse", "children"],
            "household_condition": [],
            "community_service": ["senior citizen association member", "community leader"],
            "specialization": ["teaching", "medical"],
            "num_children": 4, "num_working_children": 3,
            "household_size": 5, "child_financial_support": True,
            "spouse_working": True,
            "medical_concern": "None",
            "dental_concern": "None",
            "optical_concern": "None",
            "hearing_concern": "None",
            "social_emotional_concern": "None",
            "healthcare_difficulty": [],
            "has_medical_checkup": True,
            "qol_responses": {k: 5 for k in [
                "qol_enjoy_life","qol_life_satisfaction","qol_future_outlook","qol_meaningfulness",
                "phy_energy","phy_pain_r","phy_health_limit_r","phy_mobility_outside","phy_mobility_indoor",
                "psych_happiness","psych_peace","psych_lonely_r","psych_confidence",
                "func_independence","func_autonomy","func_control","env_income_limit_r",
                "soc_social_support","soc_close_friend","soc_participation","soc_opportunity","soc_respect",
                "env_safe_home","env_safe_neighborhood","env_service_access","env_home_comfort",
                "env_fin_medical","env_fin_household","env_fin_personal",
                "spi_belief_comfort","spi_belief_practice",
            ]},
        },
        "expect_level": "LOW",
        "expect_cluster": 1,
    },
]


def run_tests():
    # Preprocess all seniors first
    preprocessed_list = []
    for s in SENIORS:
        try:
            preprocessed_list.append(preprocess(s["raw"]))
        except Exception as e:
            print(f"[FAIL] preprocess error for {s['label']}: {e}")
            preprocessed_list.append(None)

    # Batch cluster assign
    valid = [p for p in preprocessed_list if p is not None]
    if valid:
        warnings = batch_cluster_assign(valid)
        for w in warnings:
            print(f"  [batch] {w}")

    all_ok = True
    for i, s in enumerate(SENIORS):
        preprocessed = preprocessed_list[i]
        if preprocessed is None:
            all_ok = False
            continue

        try:
            result = infer(preprocessed)
        except Exception as e:
            print(f"[FAIL] infer error for {s['label']}: {e}")
            all_ok = False
            continue

        scores = result.get("risk_scores", {})
        levels = result.get("risk_levels", {})
        cluster = result.get("cluster", {})
        recs = result.get("recommendations", [])
        pflag = result.get("priority_flag", "")
        section_scores = result.get("section_scores", {})
        domain_risks = result.get("domain_risks", {})
        who_scores = result.get("who_scores", {})

        checks = [
            ("status=success",          result.get("status") == "success",                     True),
            ("cluster in [1,2,3]",      cluster.get("named_id") in {1, 2, 3},                 True),
            ("composite in [0,1]",      0.0 <= scores.get("composite_risk", -1) <= 1.0,        True),
            ("ic_risk in [0,1]",        0.0 <= scores.get("ic_risk", -1) <= 1.0,               True),
            ("env_risk in [0,1]",       0.0 <= scores.get("env_risk", -1) <= 1.0,              True),
            ("func_risk in [0,1]",      0.0 <= scores.get("func_risk", -1) <= 1.0,             True),
            ("risk_level valid",        levels.get("overall") in {"HIGH","MODERATE","LOW"},    True),
            ("priority_flag valid",     pflag in {"urgent","priority_action","planned_monitoring","maintenance"}, True),
            ("recs non-empty",          len(recs) > 0,                                          True),
            ("section_scores present",  len(section_scores) > 0,                               True),
            ("domain_risks present",    "risk_medical" in domain_risks,                        True),
            ("who_scores present",      "ic_score" in who_scores,                              True),
            # Urgency consistency: urgent pflag → urgent recs; non-urgent → no urgent recs
            ("urgency consistent",
                (pflag == "urgent" and any(r.get("urgency") == "urgent" for r in recs))
                or (pflag != "urgent" and not any(r.get("urgency") == "urgent" for r in recs)),
                True),
            # hc_access recommendations: if cost/distance difficulty → hc_access recs exist
            ("hc_recs when difficulty",
                not any(d in " ".join(s["raw"].get("healthcare_difficulty") or []).lower()
                        for d in ["cost", "distance"])
                or any(r.get("domain") == "hc_access" for r in recs),
                True),
            # Soft checks on expected level / cluster (heuristic path may differ)
            ("expected_level",          levels.get("overall") == s["expect_level"],            True),
            ("expected_cluster",        cluster.get("named_id") == s["expect_cluster"],        True),
        ]

        failed_checks = []
        print(f"\n{'='*60}")
        print(f"Senior: {s['label']}")
        print(f"  composite={scores.get('composite_risk'):.4f}  level={levels.get('overall')}  pflag={pflag}  cluster={cluster.get('named_id')} ({cluster.get('name')})")
        for name, actual, expected in checks:
            ok = actual == expected
            if not ok:
                failed_checks.append(name)
                all_ok = False
            print(f"  [{'OK' if ok else 'FAIL'}] {name}: {actual!r}")

        print(f"  Result: {'PASS' if not failed_checks else 'FAIL — ' + ', '.join(failed_checks)}")
        print(f"\n  WHO scores: IC={who_scores.get('ic_score'):.2f}  ENV={who_scores.get('env_score'):.2f}  FUNC={who_scores.get('func_score'):.2f}  QoL={who_scores.get('qol_score'):.2f}")
        print(f"  Domain risks: medical={domain_risks.get('risk_medical'):.3f}  financial={domain_risks.get('risk_financial'):.3f}  social={domain_risks.get('risk_social'):.3f}")
        print(f"  Recommendations: {len(recs)} items across domains: {sorted(set(r.get('domain','?') for r in recs))}")
        print(f"  Warnings: {result.get('warnings', [])}")

    print(f"\n{'='*60}")
    print("ALL CHECKS PASSED" if all_ok else "SOME CHECKS FAILED")
    return all_ok


if __name__ == "__main__":
    import sys
    ok = run_tests()
    sys.exit(0 if ok else 1)
