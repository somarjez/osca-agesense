"""
Tests for the v2 recommendation engine in inference_service.py.

Validates:
1. No "4Ps" / "Botika ng Barangay" / "PCSO IMAP" in output
2. service_provider and evidence_source are populated
3. Social pension (FINANCIAL_SOCIAL_PENSION) triggers for no-pension + low-income
4. Welfare check (SOCIAL_WELFARE_CHECK) triggers for lives-alone seniors
5. Mental health referral (MENTAL_HEALTH_REFERRAL) uses referral language, not clinical diagnosis
6. recommendation_code is set for rule-library recommendations
7. _build_recommendations() produces globally-numbered priorities
8. Functional recommendations fire for mobility / age triggers
9. Social recommendations: participation (SOC-006), widowed (SOC-007), loneliness (MENTAL_HEALTH_REFERRAL)
10. Healthcare access: chronic + no checkup fires HCA-001
11. Household safety (HSF-001) fires for unsafe home
12. Category diversity: health capped at <=2 in top for non-urgent
13. apa_reference and source_type populated for every rule-coded recommendation
14. Section-score triggers for functional recommendations
15. Healthcare_access category name (not hc_access) for FNC/HSF/HCA rules
"""
import os
import sys

os.environ["ML_MODELS_PATH"] = os.path.abspath(
    os.path.join(os.path.dirname(__file__), "..", "models")
)
os.environ["ENABLE_NOTEBOOK_OVERRIDES"] = "false"
os.environ["ENABLE_DETERMINISTIC_CLUSTER"] = "false"
os.environ["NUMBA_THREADING_LAYER"] = "workqueue"
os.environ["NUMBA_NUM_THREADS"] = "1"
os.environ["OMP_NUM_THREADS"] = "1"

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "services"))

from inference_service import (
    _health_recs,
    _financial_recs,
    _social_recs,
    _functional_recs,
    _hc_access_recs,
    _household_safety_recs,
    _assistive_device_recs,
    _build_recommendations,
)

all_ok = True


def check(label: str, actual, expected):
    global all_ok
    ok = actual == expected
    if not ok:
        all_ok = False
    print(f"  [{'OK' if ok else 'FAIL'}] {label}: {actual!r}  (expected {expected!r})")
    return ok


def check_true(label: str, condition: bool, detail: str = ""):
    global all_ok
    if not condition:
        all_ok = False
    print(f"  [{'OK' if condition else 'FAIL'}] {label}" + (f": {detail}" if detail else ""))
    return condition


# ─────────────────────────────────────────────────────────────────────────────
print("=== 1. No banned strings in recommendation outputs ===")

# Low-income row to exercise financial_recs paths
LOW_INCOME_ROW = {
    "age": 72, "income_enc": 1.0, "sec5_eco_stability": 0.2,
    "has_pension": 0, "env_fin_medical": 2, "env_fin_household": 2,
    "func_independence": 3.0,
}
fin_recs = _financial_recs(LOW_INCOME_ROW, income_enc_val=1.0, eco_stability=0.2)
all_actions = " ".join(r["action"] for r in fin_recs)

check_true("no '4Ps' in financial_recs", "4Ps" not in all_actions,
           f"Found '4Ps' in: {all_actions[:120]}")
check_true("no 'Botika ng Barangay' in financial_recs",
           "Botika ng Barangay" not in all_actions,
           f"Found in: {all_actions[:120]}")
check_true("no 'PCSO IMAP' in financial_recs",
           "PCSO IMAP" not in all_actions and
           "PCSO Individual Medical Assistance Program (IMAP)" not in all_actions,
           f"Found in: {all_actions[:120]}")
print()

# ─────────────────────────────────────────────────────────────────────────────
print("=== 2. service_provider and evidence_source are populated ===")

for rec in fin_recs:
    if rec.get("recommendation_code"):   # rule-library recs always have these
        check_true(
            f"{rec['recommendation_code']} has service_provider",
            bool(rec.get("service_provider")),
            rec.get("service_provider"),
        )
        check_true(
            f"{rec['recommendation_code']} has evidence_source",
            bool(rec.get("evidence_source")),
            rec.get("evidence_source"),
        )

# Health recs with hypertension
HYPER_ROW = {
    "age": 70, "medical_concern": "hypertension",
    "checkup_enc": 0, "env_fin_medical": 3,
    "phy_mobility_outside": 3, "phy_mobility_indoor": 3,
}
health_recs = _health_recs(HYPER_ROW)
rule_recs = [r for r in health_recs if r.get("recommendation_code")]
check_true("hypertension row produces at least one rule-coded rec",
           len(rule_recs) > 0, str([r["recommendation_code"] for r in rule_recs]))
for rec in rule_recs:
    check_true(
        f"{rec['recommendation_code']} has service_provider",
        bool(rec.get("service_provider")),
    )
print()

# ─────────────────────────────────────────────────────────────────────────────
print("=== 3. Social pension (FINANCIAL_SOCIAL_PENSION) triggers for no-pension + low-income ===")

NO_PENSION_ROW = {
    "age": 68, "income_enc": 1.0, "sec5_eco_stability": 0.2,
    "has_pension": 0, "env_fin_medical": 3, "env_fin_household": 3,
    "func_independence": 3.0,
}
fin2 = _financial_recs(NO_PENSION_ROW, income_enc_val=1.0, eco_stability=0.2)
codes = [r.get("recommendation_code") for r in fin2]
check_true("FINANCIAL_SOCIAL_PENSION present when has_pension=0 AND low income",
           "FINANCIAL_SOCIAL_PENSION" in codes, f"codes: {codes}")

# Middle-income with pension — FINANCIAL_SOCIAL_PENSION should NOT appear
WITH_PENSION_ROW = {
    "age": 68, "income_enc": 6.0, "sec5_eco_stability": 0.6,
    "has_pension": 1, "env_fin_medical": 3, "env_fin_household": 3,
    "func_independence": 3.0,
}
fin3 = _financial_recs(WITH_PENSION_ROW, income_enc_val=6.0, eco_stability=0.6)
codes3 = [r.get("recommendation_code") for r in fin3]
check_true("FINANCIAL_SOCIAL_PENSION absent when has_pension=1 AND adequate income",
           "FINANCIAL_SOCIAL_PENSION" not in codes3, f"codes: {codes3}")
print()

# ─────────────────────────────────────────────────────────────────────────────
print("=== 4. Welfare check (SOCIAL_WELFARE_CHECK) triggers for lives-alone seniors ===")

LIVES_ALONE_ROW = {
    "sec4_lives_alone": 1,
    "soc_social_support": 3, "soc_close_friend": 3,
    "sec2_family_support": 0.5,
    "is_association_member": 0,
    "social_emotional_concern": "",
}
soc_recs = _social_recs(LIVES_ALONE_ROW)
soc_codes = [r.get("recommendation_code") for r in soc_recs]
check_true("SOCIAL_WELFARE_CHECK present when lives_alone=1",
           "SOCIAL_WELFARE_CHECK" in soc_codes, f"codes: {soc_codes}")

# Verify SOCIAL_WELFARE_CHECK action mentions welfare check or BHW
swc = next((r for r in soc_recs if r.get("recommendation_code") == "SOCIAL_WELFARE_CHECK"), None)
if swc:
    check_true("SOCIAL_WELFARE_CHECK action mentions 'welfare' or 'check'",
               "welfare" in swc["action"].lower() or "check" in swc["action"].lower(),
               swc["action"][:100])
    check_true("SOCIAL_WELFARE_CHECK has service_provider",
               bool(swc.get("service_provider")),
               swc.get("service_provider"))

# Not-alone senior — SOCIAL_WELFARE_CHECK should NOT appear
NOT_ALONE_ROW = {
    "sec4_lives_alone": 0, "lives_alone": 0,
    "soc_social_support": 4, "soc_close_friend": 4,
    "sec2_family_support": 0.8,
    "is_association_member": 1,
    "social_emotional_concern": "",
}
soc2 = _social_recs(NOT_ALONE_ROW)
soc_codes2 = [r.get("recommendation_code") for r in soc2]
check_true("SOCIAL_WELFARE_CHECK absent when not lives_alone",
           "SOCIAL_WELFARE_CHECK" not in soc_codes2, f"codes: {soc_codes2}")
print()

# ─────────────────────────────────────────────────────────────────────────────
print("=== 5. Mental health uses referral language, not clinical diagnosis ===")

MH_ROW = {
    "age": 70, "checkup_enc": 1,
    "phy_mobility_outside": 3, "phy_mobility_indoor": 3, "env_fin_medical": 3,
    "dental_concern": "none", "optical_concern": "healthy eyes",
    "hearing_concern": "healthy hearing",
    "medical_concern": "none",
    "social_emotional_concern": "depression and loneliness",
}
mh_health = _health_recs(MH_ROW)
mh_codes = [r.get("recommendation_code") for r in mh_health]
check_true("MENTAL_HEALTH_REFERRAL present for depression/emotional concern",
           "MENTAL_HEALTH_REFERRAL" in mh_codes, f"codes: {mh_codes}")

mhr = next((r for r in mh_health if r.get("recommendation_code") == "MENTAL_HEALTH_REFERRAL"), None)
if mhr:
    action_lower = mhr["action"].lower()
    # Must use referral language
    check_true("MENTAL_HEALTH_REFERRAL uses 'refer' language",
               "refer" in action_lower,
               mhr["action"][:120])
    # Must not use clinical diagnosis language
    forbidden = ["diagnosed with", "clinical diagnosis", "you have depression", "confirmed"]
    check_true("MENTAL_HEALTH_REFERRAL avoids clinical diagnosis language",
               not any(f in action_lower for f in forbidden),
               mhr["action"][:120])
    # Must mention mental health professional
    check_true("MENTAL_HEALTH_REFERRAL mentions mental health professional",
               "mental health" in action_lower or "ncmh" in action_lower,
               mhr["action"][:120])

# SOC recs with emotional concern → MENTAL_HEALTH_REFERRAL
soc_mh = _social_recs({
    "sec4_lives_alone": 0,
    "soc_social_support": 3, "soc_close_friend": 3,
    "sec2_family_support": 0.6,
    "is_association_member": 1,
    "social_emotional_concern": "feeling hopeless and anxious",
})
soc_mh_codes = [r.get("recommendation_code") for r in soc_mh]
check_true("MENTAL_HEALTH_REFERRAL in _social_recs for hopeless/anxious",
           "MENTAL_HEALTH_REFERRAL" in soc_mh_codes, f"codes: {soc_mh_codes}")
print()

# ─────────────────────────────────────────────────────────────────────────────
print("=== 6. recommendation_code set for rule-library recommendations ===")

rule_recs_fin = [r for r in fin_recs if r.get("recommendation_code")]
check_true("_financial_recs produces at least 2 coded recs",
           len(rule_recs_fin) >= 2, str(len(rule_recs_fin)))
for r in rule_recs_fin:
    check_true(f"code {r['recommendation_code']} is non-empty string",
               isinstance(r["recommendation_code"], str) and len(r["recommendation_code"]) > 0)
print()

# ─────────────────────────────────────────────────────────────────────────────
print("=== 7. _build_recommendations returns globally-numbered priorities ===")

FULL_ROW = {
    "age": 72, "income_enc": 2.0, "sec5_eco_stability": 0.3,
    "has_pension": 0, "env_fin_medical": 2, "env_fin_household": 2,
    "func_independence": 2.5,
    "sec4_lives_alone": 1,
    "soc_social_support": 2, "soc_close_friend": 2,
    "sec2_family_support": 0.2, "is_association_member": 0,
    "social_emotional_concern": "",
    "medical_concern": "hypertension",
    "dental_concern": "none", "optical_concern": "none",
    "hearing_concern": "none",
    "phy_mobility_outside": 2, "phy_mobility_indoor": 3,
    "checkup_enc": 0,
}
all_recs = _build_recommendations(
    named_id=1,
    overall_level="HIGH",
    feature_map=FULL_ROW,
    section_scores={},
    raw_context={},
    priority_flag="urgent",
)
priorities = [r["priority"] for r in all_recs]
check_true("priorities start at 1", priorities[0] == 1 if priorities else False)
check_true("priorities are consecutive 1..N",
           priorities == list(range(1, len(all_recs) + 1)),
           f"priorities: {priorities[:10]}...")
check_true("_build_recommendations produces 5+ recs for complex senior",
           len(all_recs) >= 5, str(len(all_recs)))

# Verify urgency=urgent propagated correctly for priority_flag=urgent + HIGH level
urgencies = set(r["urgency"] for r in all_recs)
check_true("all recs have urgency='urgent' when priority_flag=urgent",
           urgencies == {"urgent"}, f"found: {urgencies}")

# Verify all recs have required keys
required_keys = {
    "priority", "type", "domain", "category", "action",
    "urgency", "risk_level", "reason", "service_provider",
    "evidence_source", "recommendation_code", "requires_human_validation",
    "documents_needed",
}
for rec in all_recs:
    missing = required_keys - set(rec.keys())
    if missing:
        check_true(f"rec priority={rec['priority']} has all required keys",
                   False, f"missing: {missing}")
        break
else:
    check_true("all recs have required keys (v2 schema)", True)
print()

# ─────────────────────────────────────────────────────────────────────────────
print("=== 8. Functional recommendations fire for mobility / age triggers ===")

# Severe mobility impairment → HLT-008 (fall risk)
MOB_ROW = {
    "age": 73, "phy_mobility_outside": 2, "phy_mobility_indoor": 3,
    "func_independence": 3.0, "func_autonomy": 3.0, "func_control": 3.0,
    "phy_energy": 3.0,
}
func_recs_mob = _functional_recs(MOB_ROW)
func_codes_mob = [r.get("recommendation_code") for r in func_recs_mob]
check_true("HLT-008 fires when mob_outside=2 (severe)", "HLT-008" in func_codes_mob,
           f"codes: {func_codes_mob}")

# Age 80+ → FNC-002 geriatric assessment
ELDERLY_ROW = {
    "age": 82, "phy_mobility_outside": 4, "phy_mobility_indoor": 4,
    "func_independence": 3.0, "func_autonomy": 3.0, "func_control": 3.0,
    "phy_energy": 3.0,
}
func_recs_eld = _functional_recs(ELDERLY_ROW)
func_codes_eld = [r.get("recommendation_code") for r in func_recs_eld]
check_true("FNC-002 fires for age=82", "FNC-002" in func_codes_eld,
           f"codes: {func_codes_eld}")

# Moderate mobility + age 75 → FNC-006 fall-risk home assessment
MOD_MOB_ROW = {
    "age": 76, "phy_mobility_outside": 3, "phy_mobility_indoor": 3,
    "func_independence": 3.0, "func_autonomy": 3.0, "func_control": 3.0,
    "phy_energy": 3.0,
}
func_recs_mid = _functional_recs(MOD_MOB_ROW)
func_codes_mid = [r.get("recommendation_code") for r in func_recs_mid]
check_true("FNC-006 fires for age=76 + mob=3", "FNC-006" in func_codes_mid,
           f"codes: {func_codes_mid}")
check_true("FUNCTIONAL_ASSISTIVE_DEVICE fires for age=76 + mob=3", "FUNCTIONAL_ASSISTIVE_DEVICE" in func_codes_mid,
           f"codes: {func_codes_mid}")

# No triggers for young, mobile, independent senior
YOUNG_MOB_ROW = {
    "age": 65, "phy_mobility_outside": 5, "phy_mobility_indoor": 5,
    "func_independence": 4.0, "func_autonomy": 4.0, "func_control": 4.0,
    "phy_energy": 4.0,
}
func_recs_none = _functional_recs(YOUNG_MOB_ROW)
check_true("_functional_recs returns empty for healthy 65yo",
           len(func_recs_none) == 0, f"got {len(func_recs_none)} recs")
print()

# ─────────────────────────────────────────────────────────────────────────────
print("=== 9. Social recommendations: participation, widowed, loneliness ===")

# Low participation → SOC-006
LOW_PART_ROW = {
    "sec4_lives_alone": 0, "soc_social_support": 3, "soc_close_friend": 3,
    "soc_participation": 2, "soc_opportunity": 3, "soc_respect": 3,
    "psych_lonely_r": 3,
    "sec2_family_support": 0.6, "is_association_member": 1,
    "social_emotional_concern": "", "marital_status": "Married",
}
soc_part = _social_recs(LOW_PART_ROW)
soc_part_codes = [r.get("recommendation_code") for r in soc_part]
check_true("SOC-006 fires for soc_participation=2", "SOC-006" in soc_part_codes,
           f"codes: {soc_part_codes}")

# Widowed → SOC-007
WIDOWED_ROW = {
    "sec4_lives_alone": 0, "soc_social_support": 3, "soc_close_friend": 3,
    "soc_participation": 3, "soc_opportunity": 3, "soc_respect": 3,
    "psych_lonely_r": 3,
    "sec2_family_support": 0.6, "is_association_member": 1,
    "social_emotional_concern": "", "marital_status": "Widowed",
}
soc_wid = _social_recs(WIDOWED_ROW)
soc_wid_codes = [r.get("recommendation_code") for r in soc_wid]
check_true("SOC-007 fires for marital_status=Widowed", "SOC-007" in soc_wid_codes,
           f"codes: {soc_wid_codes}")

# Loneliness (psych_lonely_r ≤ 2) → SOC-004
LONELY_ROW = {
    "sec4_lives_alone": 0, "soc_social_support": 3, "soc_close_friend": 3,
    "soc_participation": 3, "soc_opportunity": 3, "soc_respect": 3,
    "psych_lonely_r": 2,
    "sec2_family_support": 0.6, "is_association_member": 1,
    "social_emotional_concern": "", "marital_status": "Single",
}
soc_lone = _social_recs(LONELY_ROW)
soc_lone_codes = [r.get("recommendation_code") for r in soc_lone]
check_true("MENTAL_HEALTH_REFERRAL fires for psych_lonely_r=2 (loneliness score)", "MENTAL_HEALTH_REFERRAL" in soc_lone_codes,
           f"codes: {soc_lone_codes}")
print()

# ─────────────────────────────────────────────────────────────────────────────
print("=== 10. Healthcare access: chronic + no checkup fires HCA-001 ===")

HCA_ROW = {
    "age": 70, "checkup_enc": 0,
    "medical_concern": "hypertension and diabetes",
    "healthcare_difficulty": "",
    "env_service_access": 3.0,
    "housing_concern": "",
}
hca_recs = _hc_access_recs(HCA_ROW)
hca_codes = [r.get("recommendation_code") for r in hca_recs]
check_true("HCA-001 fires for chronic+no_checkup", "HCA-001" in hca_codes,
           f"codes: {hca_codes}")

# Senior with checkup — HCA-001 should NOT fire
HCA_CHECKUP_ROW = {
    "age": 70, "checkup_enc": 1,
    "medical_concern": "hypertension",
    "healthcare_difficulty": "",
    "env_service_access": 4.0,
    "housing_concern": "",
}
hca_recs2 = _hc_access_recs(HCA_CHECKUP_ROW)
hca_codes2 = [r.get("recommendation_code") for r in hca_recs2]
check_true("HCA-001 absent when has_checkup=1", "HCA-001" not in hca_codes2,
           f"codes: {hca_codes2}")
print()

# ─────────────────────────────────────────────────────────────────────────────
print("=== 11. Household safety (HSF-001) fires for unsafe home ===")

UNSAFE_HOME_ROW = {
    "env_safe_home": 2, "household_condition": "poor and damaged",
    "housing_concern": "",
    "phy_mobility_outside": 4, "phy_mobility_indoor": 4, "age": 70,
}
hsf_recs = _household_safety_recs(UNSAFE_HOME_ROW)
hsf_codes = [r.get("recommendation_code") for r in hsf_recs]
check_true("HSF-001 fires for env_safe_home=2", "HSF-001" in hsf_codes,
           f"codes: {hsf_codes}")
check_true("HSF-002 fires for 'poor and damaged' household", "HSF-002" in hsf_codes,
           f"codes: {hsf_codes}")

# Safe home — no HSF rules
SAFE_HOME_ROW = {
    "env_safe_home": 5, "household_condition": "good",
    "housing_concern": "",
    "phy_mobility_outside": 4, "phy_mobility_indoor": 4, "age": 65,
}
hsf_recs2 = _household_safety_recs(SAFE_HOME_ROW)
hsf_codes2 = [r.get("recommendation_code") for r in hsf_recs2]
check_true("HSF rules absent for safe home", len(hsf_codes2) == 0,
           f"codes: {hsf_codes2}")
print()

# ─────────────────────────────────────────────────────────────────────────────
print("=== 12. Category diversity: health capped at <=2 in top for non-urgent ===")

# Build a senior with many health triggers → health should be capped
HEALTH_HEAVY_ROW = {
    "age": 70, "income_enc": 4.0, "sec5_eco_stability": 0.5,
    "has_pension": 1, "env_fin_medical": 3, "env_fin_household": 3,
    "func_independence": 4.0, "func_autonomy": 4.0, "func_control": 4.0,
    "phy_energy": 4.0, "phy_mobility_outside": 4, "phy_mobility_indoor": 4,
    "sec4_lives_alone": 0, "soc_social_support": 4, "soc_close_friend": 4,
    "soc_participation": 4, "soc_opportunity": 4, "soc_respect": 4,
    "psych_lonely_r": 4, "sec2_family_support": 0.8, "is_association_member": 1,
    "social_emotional_concern": "",
    "marital_status": "Married",
    # multiple medical concerns to fire many health rules
    "medical_concern": "hypertension, diabetes, arthritis",
    "dental_concern": "toothache", "optical_concern": "blurry vision",
    "hearing_concern": "hearing loss",
    "checkup_enc": 0,
    "healthcare_difficulty": "",
    "housing_concern": "",
    "env_service_access": 4.0,
    "env_safe_home": 4.0, "household_condition": "good",
}
diversity_recs = _build_recommendations(
    named_id=2,
    overall_level="MODERATE",
    feature_map=HEALTH_HEAVY_ROW,
    section_scores={},
    raw_context={},
    priority_flag="",
)
# Count health recs in top-5 positions
top5 = diversity_recs[:5]
top5_health = [r for r in top5 if r.get("category") == "health"]
top5_non_health = [r for r in top5 if r.get("category") != "health"]
check_true(
    "health recs capped at <=2 in top 5 positions (MODERATE/non-urgent)",
    len(top5_health) <= 2,
    f"health_in_top5={len(top5_health)}, categories={[r.get('category') for r in top5]}"
)
check_true(
    "at least 1 non-health rec in top 5",
    len(top5_non_health) >= 1,
    f"non_health_in_top5={len(top5_non_health)}, categories={[r.get('category') for r in top5]}"
)
check_true(
    "trigger_summary present on all recs",
    all(isinstance(r.get("trigger_summary"), dict) for r in diversity_recs),
    "trigger_summary missing from some recs"
)
print()

# ─────────────────────────────────────────────────────────────────────────────
print("=== 13. apa_reference and source_type populated for every rule-coded rec ===")

# Build a senior that triggers multiple domains
CITATION_ROW = {
    "age": 71, "income_enc": 2.0, "sec5_eco_stability": 0.3,
    "has_pension": 0, "env_fin_medical": 2, "env_fin_household": 2,
    "func_independence": 2.5, "func_autonomy": 3.0, "func_control": 3.0,
    "phy_energy": 3.0, "phy_mobility_outside": 2, "phy_mobility_indoor": 3,
    "sec4_lives_alone": 1,
    "soc_social_support": 2, "soc_close_friend": 3,
    "sec2_family_support": 0.3, "is_association_member": 0,
    "social_emotional_concern": "",
    "medical_concern": "hypertension",
    "dental_concern": "none", "optical_concern": "none", "hearing_concern": "none",
    "checkup_enc": 0,
    "healthcare_difficulty": "transport",
    "housing_concern": "",
    "env_service_access": 3.0, "env_safe_home": 4.0, "household_condition": "good",
    "marital_status": "Widowed",
    "soc_participation": 2, "soc_opportunity": 3, "soc_respect": 3,
    "psych_lonely_r": 3,
}
cite_recs = _build_recommendations(
    named_id=3,
    overall_level="HIGH",
    feature_map=CITATION_ROW,
    section_scores={},
    raw_context={},
    priority_flag="priority_action",
)
rule_coded_recs = [r for r in cite_recs if r.get("recommendation_code")]
check_true("citation test produces at least 5 rule-coded recs",
           len(rule_coded_recs) >= 5, str(len(rule_coded_recs)))
for r in rule_coded_recs:
    code = r["recommendation_code"]
    check_true(f"{code} has apa_reference", bool(r.get("apa_reference")),
               str(r.get("apa_reference", ""))[:80])
    check_true(f"{code} has source_type", bool(r.get("source_type")),
               str(r.get("source_type", ""))[:60])
print()

# ─────────────────────────────────────────────────────────────────────────────
print("=== 14. Section-score triggers for functional recommendations ===")

# Low sec6_func_score → FUNCTIONAL_ADL_SUPPORT
FUNC_SCORE_ROW = {
    "age": 70,
    "sec6_func_score": 0.40,      # below 0.45 threshold
    "risk_functional": 0.30,
    "sec4_dependency_risk": 0.20,
    "sec6_phy_score": 0.50,
    "sec4_lives_alone": 0,
    "phy_mobility_outside": 4, "phy_mobility_indoor": 4,
    "func_independence": 4.0, "func_autonomy": 4.0, "func_control": 4.0,
    "phy_energy": 4.0,
}
func_score_recs = _functional_recs(FUNC_SCORE_ROW)
func_score_codes = [r.get("recommendation_code") for r in func_score_recs]
check_true("FUNCTIONAL_ADL_SUPPORT fires when sec6_func_score=0.40 < 0.45",
           "FUNCTIONAL_ADL_SUPPORT" in func_score_codes,
           f"codes: {func_score_codes}")

# High risk_functional → FUNCTIONAL_ADL_SUPPORT
FUNC_RISK_ROW = {
    "age": 70,
    "sec6_func_score": 0.55,
    "risk_functional": 0.65,      # above 0.55 threshold
    "sec4_dependency_risk": 0.20,
    "sec6_phy_score": 0.50,
    "sec4_lives_alone": 0,
    "phy_mobility_outside": 4, "phy_mobility_indoor": 4,
    "func_independence": 4.0, "func_autonomy": 4.0, "func_control": 4.0,
    "phy_energy": 4.0,
}
func_risk_recs = _functional_recs(FUNC_RISK_ROW)
func_risk_codes = [r.get("recommendation_code") for r in func_risk_recs]
check_true("FUNCTIONAL_ADL_SUPPORT fires when risk_functional=0.65 > 0.55",
           "FUNCTIONAL_ADL_SUPPORT" in func_risk_codes,
           f"codes: {func_risk_codes}")

# High dependency risk → SOCIAL_WELFARE_CHECK + FUNCTIONAL_ADL_SUPPORT
DEP_RISK_ROW = {
    "age": 70,
    "sec6_func_score": 0.55,
    "risk_functional": 0.30,
    "sec4_dependency_risk": 0.70,  # above 0.60 threshold
    "sec6_phy_score": 0.50,
    "sec4_lives_alone": 0,
    "phy_mobility_outside": 4, "phy_mobility_indoor": 4,
    "func_independence": 4.0, "func_autonomy": 4.0, "func_control": 4.0,
    "phy_energy": 4.0,
}
dep_risk_recs = _functional_recs(DEP_RISK_ROW)
dep_risk_codes = [r.get("recommendation_code") for r in dep_risk_recs]
check_true("SOCIAL_WELFARE_CHECK fires when sec4_dependency_risk=0.70 > 0.60",
           "SOCIAL_WELFARE_CHECK" in dep_risk_codes,
           f"codes: {dep_risk_codes}")
check_true("FUNCTIONAL_ADL_SUPPORT fires when sec4_dependency_risk=0.70 > 0.60",
           "FUNCTIONAL_ADL_SUPPORT" in dep_risk_codes,
           f"codes: {dep_risk_codes}")

# Low phy_score → HLT-008
PHY_SCORE_ROW = {
    "age": 70,
    "sec6_func_score": 0.55,
    "risk_functional": 0.30,
    "sec4_dependency_risk": 0.20,
    "sec6_phy_score": 0.35,        # below 0.40 threshold
    "sec4_lives_alone": 0,
    "phy_mobility_outside": 4, "phy_mobility_indoor": 4,
    "func_independence": 4.0, "func_autonomy": 4.0, "func_control": 4.0,
    "phy_energy": 4.0,
}
phy_score_recs = _functional_recs(PHY_SCORE_ROW)
phy_score_codes = [r.get("recommendation_code") for r in phy_score_recs]
check_true("HLT-008 fires when sec6_phy_score=0.35 < 0.40",
           "HLT-008" in phy_score_codes,
           f"codes: {phy_score_codes}")
print()

# ─────────────────────────────────────────────────────────────────────────────
print("=== 15. healthcare_access category name (not hc_access) ===")

# FNC-003 (transport access) should use "healthcare_access"
TRANSPORT_ROW = {
    "age": 70, "checkup_enc": 0,
    "medical_concern": "",
    "healthcare_difficulty": "transport",
    "env_service_access": 3.0,
    "housing_concern": "",
}
transport_recs = _hc_access_recs(TRANSPORT_ROW)
at_rec = next((r for r in transport_recs if r.get("recommendation_code") == "ACCESS_TRANSPORT"), None)
if at_rec:
    check_true("ACCESS_TRANSPORT category='healthcare_access' (not hc_access)",
               at_rec.get("category") == "healthcare_access",
               f"category={at_rec.get('category')}")

# HCA-001 should use "healthcare_access"
hca_row_check = {
    "age": 70, "checkup_enc": 0,
    "medical_concern": "hypertension",
    "healthcare_difficulty": "",
    "env_service_access": 3.0,
    "housing_concern": "",
}
hca_recs_check = _hc_access_recs(hca_row_check)
hca001 = next((r for r in hca_recs_check if r.get("recommendation_code") == "HCA-001"), None)
if hca001:
    check_true("HCA-001 category='healthcare_access' (not hc_access)",
               hca001.get("category") == "healthcare_access",
               f"category={hca001.get('category')}")

# Ensure NO recommendation in the entire test suite has category="hc_access"
all_domain_recs = (
    _financial_recs(CITATION_ROW, income_enc_val=2.0, eco_stability=0.3)
    + _social_recs(CITATION_ROW)
    + _functional_recs(CITATION_ROW)
    + _hc_access_recs(CITATION_ROW)
    + _household_safety_recs(CITATION_ROW)
)
hc_access_old = [r.get("recommendation_code") for r in all_domain_recs if r.get("category") == "hc_access"]
check_true("No recommendation uses deprecated category 'hc_access'",
           len(hc_access_old) == 0,
           f"found hc_access on: {hc_access_old}")
print()

# ─────────────────────────────────────────────────────────────────────────────
print("=" * 50)
print("ALL CHECKS PASSED" if all_ok else "SOME CHECKS FAILED")
import sys; sys.exit(0 if all_ok else 1)
