"""
Tests for the v2 recommendation engine in inference_service.py.

Validates:
1. No "4Ps" / "Botika ng Barangay" / "PCSO IMAP" in output
2. service_provider and evidence_source are populated
3. Social pension (FIN-001) triggers for no-pension + low-income
4. BHW welfare check (SOC-001) triggers for lives-alone seniors
5. Mental health referral uses appropriate referral language (not clinical diagnosis)
6. recommendation_code is set for rule-library recommendations
7. _build_recommendations() produces globally-numbered priorities
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
print("=== 3. Social pension (FIN-001) triggers for no-pension + low-income ===")

NO_PENSION_ROW = {
    "age": 68, "income_enc": 1.0, "sec5_eco_stability": 0.2,
    "has_pension": 0, "env_fin_medical": 3, "env_fin_household": 3,
    "func_independence": 3.0,
}
fin2 = _financial_recs(NO_PENSION_ROW, income_enc_val=1.0, eco_stability=0.2)
codes = [r.get("recommendation_code") for r in fin2]
check_true("FIN-001 present when has_pension=0 AND low income",
           "FIN-001" in codes, f"codes: {codes}")

# Middle-income with pension — FIN-001 should NOT appear
WITH_PENSION_ROW = {
    "age": 68, "income_enc": 6.0, "sec5_eco_stability": 0.6,
    "has_pension": 1, "env_fin_medical": 3, "env_fin_household": 3,
    "func_independence": 3.0,
}
fin3 = _financial_recs(WITH_PENSION_ROW, income_enc_val=6.0, eco_stability=0.6)
codes3 = [r.get("recommendation_code") for r in fin3]
check_true("FIN-001 absent when has_pension=1 AND adequate income",
           "FIN-001" not in codes3, f"codes: {codes3}")
print()

# ─────────────────────────────────────────────────────────────────────────────
print("=== 4. BHW welfare check (SOC-001) triggers for lives-alone seniors ===")

LIVES_ALONE_ROW = {
    "sec4_lives_alone": 1,
    "soc_social_support": 3, "soc_close_friend": 3,
    "sec2_family_support": 0.5,
    "is_association_member": 0,
    "social_emotional_concern": "",
}
soc_recs = _social_recs(LIVES_ALONE_ROW)
soc_codes = [r.get("recommendation_code") for r in soc_recs]
check_true("SOC-001 present when lives_alone=1",
           "SOC-001" in soc_codes, f"codes: {soc_codes}")

# Verify SOC-001 action mentions BHW or OSCA welfare check
soc001 = next((r for r in soc_recs if r.get("recommendation_code") == "SOC-001"), None)
if soc001:
    check_true("SOC-001 action mentions 'BHW' or 'welfare'",
               "BHW" in soc001["action"] or "welfare" in soc001["action"].lower(),
               soc001["action"][:100])
    check_true("SOC-001 service_provider mentions BHW",
               "BHW" in soc001.get("service_provider", ""),
               soc001.get("service_provider"))

# Not-alone senior — SOC-001 should NOT appear
NOT_ALONE_ROW = {
    "sec4_lives_alone": 0, "lives_alone": 0,
    "soc_social_support": 4, "soc_close_friend": 4,
    "sec2_family_support": 0.8,
    "is_association_member": 1,
    "social_emotional_concern": "",
}
soc2 = _social_recs(NOT_ALONE_ROW)
soc_codes2 = [r.get("recommendation_code") for r in soc2]
check_true("SOC-001 absent when not lives_alone",
           "SOC-001" not in soc_codes2, f"codes: {soc_codes2}")
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
check_true("SOC-004 present for depression/emotional concern",
           "SOC-004" in mh_codes, f"codes: {mh_codes}")

soc004 = next((r for r in mh_health if r.get("recommendation_code") == "SOC-004"), None)
if soc004:
    action_lower = soc004["action"].lower()
    # Must use referral language
    check_true("SOC-004 uses 'refer' language",
               "refer" in action_lower,
               soc004["action"][:120])
    # Must not use clinical diagnosis language
    forbidden = ["diagnosed with", "clinical diagnosis", "you have depression", "confirmed"]
    check_true("SOC-004 avoids clinical diagnosis language",
               not any(f in action_lower for f in forbidden),
               soc004["action"][:120])
    # Must mention NCMH or mental health professional
    check_true("SOC-004 mentions mental health professional or NCMH",
               "mental health" in action_lower or "ncmh" in action_lower,
               soc004["action"][:120])

# SOC recs with emotional concern
soc_mh = _social_recs({
    "sec4_lives_alone": 0,
    "soc_social_support": 3, "soc_close_friend": 3,
    "sec2_family_support": 0.6,
    "is_association_member": 1,
    "social_emotional_concern": "feeling hopeless and anxious",
})
soc_mh_codes = [r.get("recommendation_code") for r in soc_mh]
check_true("SOC-004 in _social_recs for hopeless/anxious",
           "SOC-004" in soc_mh_codes, f"codes: {soc_mh_codes}")
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
print("=" * 50)
print("ALL CHECKS PASSED" if all_ok else "SOME CHECKS FAILED")
import sys; sys.exit(0 if all_ok else 1)
