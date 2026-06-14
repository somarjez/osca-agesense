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


def test_match_returns_rows_with_intersecting_tags():
    catalog = cr.load_catalog()
    fired = cr.match({"dx_hypertension"}, catalog)
    codes = {r.code for r in fired}
    assert "HLT_001" in codes
    assert "HLT_002" not in codes  # diabetes-only row must not fire


def test_select_caps_health_for_routine_senior():
    catalog = cr.load_catalog()
    # senior with many health triggers but also functional + access needs
    tags = {"dx_hypertension", "dx_diabetes", "dx_htn_dm", "dx_cardiac",
            "chronic_disease", "frailty", "adl_difficulty",
            "transport_barrier", "no_checkup"}
    fired = cr.match(tags, catalog)
    chosen = cr.select(fired, urgency="planned", risk_level="moderate")
    top3 = chosen[:3]
    assert sum(1 for r in chosen if r.category == "health") <= 2
    assert not all(r.category == "health" for r in top3), "top-3 must not be all health"
    assert any(r.category == "functional" for r in chosen)


def test_select_allows_three_health_for_urgent():
    catalog = cr.load_catalog()
    tags = {"dx_hypertension", "dx_diabetes", "dx_htn_dm", "dx_cardiac", "chronic_disease"}
    fired = cr.match(tags, catalog)
    chosen = cr.select(fired, urgency="urgent", risk_level="high")
    assert sum(1 for r in chosen if r.category == "health") <= 3


def test_select_caps_each_nonhealth_category():
    """Per-category cap: a tag that fans out to many rows in one category
    (e.g. medical_cost_strain -> 8 healthcare_access/financial rows) must not
    flood the output. No non-health category may exceed CATEGORY_CAP."""
    from collections import Counter
    catalog = cr.load_catalog()
    tags = {"medical_cost_strain", "no_checkup", "transport_barrier",
            "service_access_low", "low_income", "no_pension"}
    fired = cr.match(tags, catalog)
    # sanity: this setup genuinely fans out within healthcare_access pre-cap
    assert sum(1 for r in fired if r.category == "healthcare_access") > cr.CATEGORY_CAP
    chosen = cr.select(fired, urgency="planned", risk_level="moderate")
    per_cat = Counter(r.category for r in chosen)
    for cat, n in per_cat.items():
        if cat == "health":
            continue
        assert n <= cr.CATEGORY_CAP, f"{cat} has {n} recs, exceeds CATEGORY_CAP"


def test_build_recommendations_emits_full_schema():
    row = {"medical_concern": "hypertension", "func_independence": 2,
           "phy_mobility_outside": 2, "sec4_lives_alone": 1, "age": 82,
           "has_pension": 0, "income_enc": 2, "env_fin_household": 2}
    recs = cr.build_recommendations(row, urgency="planned", risk_level="moderate",
                                    cluster_id=3, overall_level="MODERATE", priority_flag="")
    assert recs, "expected at least one recommendation"
    required = {"priority", "type", "domain", "category", "action", "urgency",
                "risk_level", "reason", "service_provider", "evidence_source",
                "apa_reference", "source_type", "recommendation_code",
                "trigger_summary", "requires_human_validation", "documents_needed",
                "trigger_context"}
    for rec in recs:
        assert required <= set(rec), f"missing keys: {required - set(rec)}"
        assert rec["recommendation_code"], "code must be non-empty"
        assert rec["apa_reference"], "apa must be non-empty"
        assert rec["source_type"], "source_type must be non-empty"
        assert rec["domain"], "domain must be non-empty"
    assert [r["priority"] for r in recs] == list(range(1, len(recs) + 1))
    # health capped, not all-health top-3
    assert not all(r["category"] == "health" for r in recs[:3])


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
