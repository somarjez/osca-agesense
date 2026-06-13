import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "..", "services"))

import catalog_recommender as cr  # noqa: E402


def test_load_catalog_parses_rows_and_tags():
    catalog = cr.load_catalog()
    assert len(catalog) == 157, f"expected 157 catalog rows, got {len(catalog)}"
    by_code = {r.code: r for r in catalog}
    htn = by_code["HLT_001"]
    assert "dx_hypertension" in htn.trigger_tags
    assert htn.priority_weight == 4
    assert htn.category == "health"
    assert htn.apa_reference, "apa_reference must be populated"
    assert htn.recommendation, "recommendation text must be populated"
    # governance row is dormant
    assert by_code["SAFE_001"].trigger_tags == frozenset()


def test_extract_tags_hypertension_and_diabetes_compound():
    tags = cr.extract_need_tags({"medical_concern": "hypertension and diabetes"})
    assert "dx_hypertension" in tags and "dx_diabetes" in tags
    assert "dx_htn_dm" in tags
    assert "chronic_disease" in tags


def test_extract_tags_frail_alone_functional():
    tags = cr.extract_need_tags({
        "func_independence": 2, "phy_mobility_outside": 2,
        "sec4_lives_alone": 1, "sec6_func_score": 0.30, "age": 82,
    })
    assert {"frailty", "adl_difficulty", "low_independence",
            "mobility_limited_outside", "lives_alone", "mobility_alone",
            "frail_80plus"} <= tags


def test_extract_tags_healthcare_access_and_financial():
    tags = cr.extract_need_tags({
        "healthcare_difficulty": "transport and cost", "has_medical_checkup": 0,
        "env_service_access": 2, "has_pension": 0, "env_fin_household": 2,
        "income_enc": 2,
    })
    assert {"transport_barrier", "medical_cost_strain", "no_checkup",
            "service_access_low", "no_pension", "financial_strain",
            "food_insecurity", "low_income"} <= tags


def test_extract_tags_healthy_senior_minimal():
    tags = cr.extract_need_tags({
        "age": 65, "medical_concern": "none", "func_independence": 5,
        "phy_mobility_outside": 5, "phy_mobility_indoor": 5, "has_pension": 1,
        "has_medical_checkup": 1, "is_association_member": 1, "income_enc": 7,
        "sec6_func_score": 0.8, "env_service_access": 5,
    })
    assert "frailty" not in tags and "adl_difficulty" not in tags
    assert "dx_hypertension" not in tags


if __name__ == "__main__":
    fails = 0
    for name, fn in sorted(globals().items()):
        if name.startswith("test_") and callable(fn):
            try:
                fn()
                print(f"PASS {name}")
            except AssertionError as e:
                fails += 1
                print(f"FAIL {name}: {e}")
    sys.exit(1 if fails else 0)
