import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "..", "services"))

import catalog_recommender as cr  # noqa: E402


def test_load_catalog_parses_rows_and_tags():
    catalog = cr.load_catalog()
    assert len(catalog) == 170, f"expected 170 catalog rows, got {len(catalog)}"
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


def test_extract_tags_healthy_senior_list_valued_no_concern():
    """Laravel exports concern fields as JSON arrays, not strings. A senior who
    explicitly checks every "no concern" checkbox must not trigger any concern
    tags, even though the raw values arrive as single-element lists."""
    tags = cr.extract_need_tags({
        "age": 65,
        "medical_concern": ["Physically Healthy"],
        "dental_concern": ["Healthy Teeth"],
        "optical_concern": ["Healthy Eyes"],
        "hearing_concern": ["Healthy Hearing"],
        "social_emotional_concern": ["Living in a healthy environment"],
        "healthcare_difficulty": ["Healthcare is accessible"],
        "func_independence": 5, "phy_mobility_outside": 5, "phy_mobility_indoor": 5,
        "has_pension": 1, "has_medical_checkup": 1, "is_association_member": 1,
        "income_enc": 7, "sec6_func_score": 0.8, "env_service_access": 5,
    })
    unexpected = {"dental_concern", "vision_concern", "hearing_concern",
                  "sensory_barrier", "medical_concern_present", "emotional_concern",
                  "healthcare_difficulty", "assistive_device_need"}
    assert not (unexpected & tags), f"false concern tags fired: {unexpected & tags}"


def test_extract_tags_list_valued_genuine_concern_still_fires():
    """Guard against over-correcting into false negatives: a genuine concern
    arriving as a list value (matching Laravel's export shape) must still fire."""
    tags = cr.extract_need_tags({"optical_concern": ["Cataract"]})
    assert "vision_concern" in tags


def test_extract_tags_no_concern_label_variant_from_raw_csv():
    """The raw survey CSV uses 'Living in healthy environment' (no 'a') for 146
    seniors — the notebook batch reads this shape directly. Both label variants
    must be recognized as no-concern."""
    tags = cr.extract_need_tags({
        "social_emotional_concern": "Living in healthy environment",
    })
    assert "emotional_concern" not in tags


def test_extract_tags_multiselect_all_no_concern_parts():
    """A comma-joined multi-select whose parts are ALL no-concern tokens must
    not fire (part-wise matching, not whole-string)."""
    tags = cr.extract_need_tags({
        "healthcare_difficulty": "Healthcare is accessible, none",
    })
    assert "healthcare_difficulty" not in tags


def test_extract_tags_mixed_concern_and_no_concern_parts_still_fires():
    """Real CSV shape: 'High cost of medicine, Healthcare is accessible'
    (5 seniors) — a real concern mixed with a no-concern token must still fire."""
    tags = cr.extract_need_tags({
        "healthcare_difficulty": "High cost of medicine, Healthcare is accessible",
    })
    assert "healthcare_difficulty" in tags
    assert "medical_cost_strain" in tags


def test_extract_tags_low_wellbeing_fires_via_overall_wellbeing_key():
    """Both pipelines carry the wellbeing value as 'overall_wellbeing' (the
    section-scores key), not 'wellbeing_score' — the low_wellbeing trigger must
    accept either key or it is dead in production."""
    tags = cr.extract_need_tags({"overall_wellbeing": 0.30})
    assert "low_wellbeing" in tags
    tags_healthy = cr.extract_need_tags({"overall_wellbeing": 0.90})
    assert "low_wellbeing" not in tags_healthy


def test_extract_tags_pneumonia_maps_to_respiratory():
    """'Pneumonia' exists in production data (bulk-upload batch) but matched no
    DISEASE_TAG_MAP keyword — it must map to dx_respiratory like asthma/COPD."""
    tags = cr.extract_need_tags({"medical_concern": ["Pneumonia"]})
    assert "dx_respiratory" in tags
    assert "chronic_disease" in tags


def test_extract_tags_informal_settler_housing_fires_unsafe_home():
    """Real household_condition survey options 'Informal settler',
    'No permanent house', 'Overcrowded in home' must fire unsafe_home even when
    the numeric env_safe_home score is unavailable."""
    for value in ("Informal settler", "No permanent house", "Overcrowded in home"):
        tags = cr.extract_need_tags({"household_condition": [value], "env_safe_home": 4})
        assert "unsafe_home" in tags, f"{value!r} did not fire unsafe_home"


def test_extract_tags_float_valued_booleans():
    """Pandas row access upcasts int columns to float64 when the row has mixed
    dtypes, and the live feature_map serializes ints as floats — so boolean
    fields arrive as 1.0/0.0. _as_bool must treat numeric non-zero as True:
    lives_alone=1.0 must fire, and is_association_member=1.0 must NOT
    false-fire not_association_member."""
    tags = cr.extract_need_tags({"sec4_lives_alone": 1.0})
    assert "lives_alone" in tags
    tags = cr.extract_need_tags({"is_association_member": 1.0})
    assert "not_association_member" not in tags
    tags = cr.extract_need_tags({"is_association_member": 0.0})
    assert "not_association_member" in tags


def test_extract_tags_helplessness_fires_emotional_distress():
    """'Feeling Helplessness/Worthlessness' is a real survey option; it must
    sub-classify as emotional_distress, not just generic emotional_concern."""
    tags = cr.extract_need_tags({
        "social_emotional_concern": ["Feeling Helplessness/Worthlessness"],
    })
    assert "emotional_concern" in tags
    assert "emotional_distress" in tags


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


# ── financial threshold calibration + composite eligibility tags ──────────────

def test_extract_tags_band4_supported_senior_not_low_income():
    """Reported case: senior with '10,000 - 20,000' income (band 4), own pension,
    spouse salary/pension, 3 working children and child financial support was
    tagged low_income and referred for Social Pension / AICS. Band 4 with a
    stable economic buffer must NOT read as low income."""
    tags = cr.extract_need_tags({
        "age": 68, "income_enc": 4, "has_pension": 1,
        "sec5_eco_stability": 0.47, "sec2_family_support": 0.79,
        "env_fin_household": 4, "env_fin_medical": 4,
    })
    assert "low_income" not in tags
    assert "financial_crisis" not in tags
    assert "wants_livelihood" not in tags, "livelihood need must follow calibrated low_income"


def test_extract_tags_band4_weak_buffer_still_low_income():
    """Band 4 (10-20k) with a genuinely weak buffer (no pension, no assets,
    no support -> low eco stability) must still flag low_income."""
    tags = cr.extract_need_tags({
        "income_enc": 4, "has_pension": 0, "sec5_eco_stability": 0.30,
    })
    assert "low_income" in tags
    assert "financial_crisis" not in tags


def test_extract_tags_band3_still_low_income():
    tags = cr.extract_need_tags({
        "income_enc": 3, "has_pension": 1, "sec5_eco_stability": 0.60,
    })
    assert "low_income" in tags
    assert "financial_crisis" not in tags


def test_extract_tags_high_band_low_eco_not_flagged():
    """The eco-stability fallback must not override a clearly adequate income:
    a 40-50k/month senior with no assets/pension must not read as low_income,
    let alone financial_crisis."""
    tags = cr.extract_need_tags({"income_enc": 7, "sec5_eco_stability": 0.20})
    assert "low_income" not in tags
    assert "financial_crisis" not in tags


def test_extract_tags_socpen_candidate_requires_full_indigence_profile():
    """DSWD Social Pension eligibility is conjunctive: low income AND no pension
    AND frail/sickly/disabled AND no regular family support. Dropping any leg
    must drop the tag."""
    base = {
        "income_enc": 2, "has_pension": 0,
        "sec2_family_support": 0.10, "sec6_func_score": 0.30,
    }
    assert "socpen_candidate" in cr.extract_need_tags(base)
    assert "socpen_candidate" not in cr.extract_need_tags({**base, "has_pension": 1})
    assert "socpen_candidate" not in cr.extract_need_tags(
        {**base, "income_enc": 6, "sec5_eco_stability": 0.70})
    assert "socpen_candidate" not in cr.extract_need_tags(
        {**base, "sec2_family_support": 0.80})
    healthy = {**base, "sec6_func_score": 0.80, "func_independence": 5,
               "phy_mobility_outside": 5, "phy_mobility_indoor": 5}
    assert "socpen_candidate" not in cr.extract_need_tags(healthy)


def test_extract_tags_pensionless_poor_composite():
    """'No permanent source of income' (FIN_002/FIN_019) needs BOTH no pension
    and low income — a salaried senior without a pension is not SocPen-poor."""
    tags = cr.extract_need_tags({"has_pension": 0, "income_enc": 2})
    assert "pensionless_poor" in tags
    tags = cr.extract_need_tags(
        {"has_pension": 0, "income_enc": 6, "sec5_eco_stability": 0.70})
    assert "no_pension" in tags
    assert "pensionless_poor" not in tags


def test_extract_tags_income_hh_strain_composite():
    tags = cr.extract_need_tags({"income_enc": 2, "env_fin_household": 2})
    assert "income_hh_strain" in tags
    tags = cr.extract_need_tags(
        {"income_enc": 6, "sec5_eco_stability": 0.70, "env_fin_household": 2})
    assert "financial_strain" in tags
    assert "income_hh_strain" not in tags


def test_extract_tags_caregiver_needed_for_unsupported_frail_senior():
    """caregiver_needed appears on 4 catalog rows (FUNC_002/010/019, SOC_015)
    but was never emitted by the extractor — those rows were dead. A frail
    senior with no support network must emit it; a well-supported frail senior
    must not."""
    tags = cr.extract_need_tags({"sec6_func_score": 0.30, "sec4_lives_alone": 1})
    assert "caregiver_needed" in tags
    tags = cr.extract_need_tags({
        "sec6_func_score": 0.30, "sec4_lives_alone": 0, "sec2_family_support": 0.80,
    })
    assert "caregiver_needed" not in tags


def test_catalog_socpen_aics_rows_use_calibrated_tags():
    """The six financial/social-protection rows whose trigger summaries are
    conjunctive must not fire on a single broad tag."""
    by_code = {r.code: r for r in cr.load_catalog()}
    assert by_code["FIN_001"].trigger_tags == frozenset({"socpen_candidate"})
    assert by_code["FIN_003"].trigger_tags == frozenset({"socpen_candidate"})
    assert by_code["FIN_002"].trigger_tags == frozenset({"pensionless_poor"})
    assert by_code["FIN_019"].trigger_tags == frozenset({"pensionless_poor"})
    assert by_code["FIN_008"].trigger_tags == frozenset({"financial_strain", "financial_crisis"})
    assert by_code["FIN_018"].trigger_tags == frozenset({"income_hh_strain"})


def test_build_recommendations_supported_band4_gets_no_socpen_or_aics():
    """End-to-end guard for the reported case: no Social Pension / AICS /
    case-assessment referral may surface for a supported band-4 senior."""
    row = {
        "age": 68, "income_enc": 4, "has_pension": 1,
        "sec5_eco_stability": 0.47, "sec2_family_support": 0.79,
        "env_fin_household": 4, "env_fin_medical": 4,
        "func_independence": 4, "phy_mobility_outside": 4, "phy_mobility_indoor": 4,
        "sec6_func_score": 0.80, "has_medical_checkup": 1, "is_association_member": 1,
        "env_service_access": 4, "medical_concern": ["Physically Healthy"],
    }
    recs = cr.build_recommendations(row, urgency="routine", risk_level="low")
    codes = {r["recommendation_code"] for r in recs}
    banned = {"FIN_001", "FIN_002", "FIN_003", "FIN_008", "FIN_018", "FIN_019"}
    assert not (codes & banned), f"financial-protection recs fired: {codes & banned}"
    assert all("low_income" not in r["reason"] for r in recs)


def test_match_indigent_senior_still_fires_socpen_ladder():
    """Guard against over-correcting: a genuinely indigent senior (band 1, no
    pension, frail, unsupported) must still reach the full SocPen pathway."""
    tags = cr.extract_need_tags({
        "income_enc": 1, "has_pension": 0, "sec5_eco_stability": 0.10,
        "sec2_family_support": 0.10, "sec6_func_score": 0.30,
        "env_fin_household": 2,
    })
    codes = {r.code for r in cr.match(tags)}
    assert {"FIN_001", "FIN_002", "FIN_003", "FIN_004", "FIN_008", "FIN_018"} <= codes


def test_derive_context_tags_emits_access_high_risk():
    """ACCESS_011 is tagged access_high_risk in the catalog but the tag was
    never derived anywhere — the row was dead. High risk + an access barrier
    must derive it, and the row must fire on it."""
    derived = cr.derive_context_tags(
        {"transport_barrier", "healthcare_difficulty"},
        urgency="urgent", risk_level="high", overall_level="HIGH")
    assert "access_high_risk" in derived
    low = cr.derive_context_tags(
        {"transport_barrier", "healthcare_difficulty"},
        urgency="routine", risk_level="low", overall_level="LOW")
    assert "access_high_risk" not in low
    codes = {r.code for r in cr.match({"access_high_risk"})}
    assert "ACCESS_011" in codes


def test_match_caregiver_needed_fires_homecare_rows():
    codes = {r.code for r in cr.match({"caregiver_needed"})}
    assert {"FUNC_002", "FUNC_010", "FUNC_019", "SOC_015"} <= codes


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
