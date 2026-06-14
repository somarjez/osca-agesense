"""One-shot: write trigger_tags + priority_weight columns onto recommendations_list.csv.

Run from repo root or python/services. Idempotent: re-running overwrites the two
columns from CODE_TAGS below. Every catalog code MUST appear in CODE_TAGS (the
script asserts full coverage), so reviewers can audit triggering in one place.
"""
import csv
import os
import sys

# code -> (tags, priority_weight). "" tags = dormant (never auto-fires).
CODE_TAGS = {
    # ── benefits / OSCA navigation (kept deliberately modest to avoid benefits dominance) ──
    "OSCA_001": ("osca_navigation", 2),
    "OSCA_002": ("benefits_unaware", 1),
    "OSCA_003": ("", 1),
    "OSCA_004": ("", 1),
    "OSCA_005": ("", 1),
    "OSCA_006": ("multiple_unmet_needs", 2),
    "OSCA_007": ("low_participation", 1),
    "OSCA_008": ("", 1),
    "OSCA_009": ("high_risk", 2),
    "OSCA_010": ("not_association_member", 1),
    # ── PhilHealth / UHC (healthcare_access) ──
    "UHC_001": ("philhealth_gap", 2),
    "UHC_002": ("philhealth_gap", 1),
    "UHC_003": ("no_checkup", 1),
    "UHC_004": ("no_checkup", 2),
    "UHC_005": ("access_gap_chronic", 3),
    "UHC_006": ("no_checkup", 1),
    "UHC_007": ("medical_cost_strain", 2),
    "UHC_008": ("", 1),
    "UHC_009": ("medical_concern_present", 1),
    "UHC_010": ("multiple_unmet_needs", 1),
    # ── health ──
    "HLT_001": ("dx_hypertension", 4),
    "HLT_002": ("dx_diabetes", 4),
    "HLT_003": ("dx_htn_dm", 4),
    "HLT_004": ("preventive_due", 1),
    "HLT_005": ("medical_cost_strain", 3),
    "HLT_006": ("medical_cost_strain", 2),
    "HLT_007": ("", 1),
    "HLT_008": ("preventive_due", 1),
    "HLT_009": ("dental_concern", 3),
    "HLT_010": ("vision_concern", 3),
    "HLT_011": ("vision_concern", 2),
    "HLT_012": ("hearing_concern", 3),
    "HLT_013": ("dx_tb", 4),
    "HLT_014": ("dx_kidney", 4),     # category healthcare_access (dialysis)
    "HLT_015": ("dx_kidney", 3),
    "HLT_016": ("dx_cardiac", 4),
    "HLT_017": ("dx_cardiac", 2),    # category healthcare_access
    "HLT_018": ("dx_cancer", 2),
    "HLT_019": ("chronic_disease", 1),
    "HLT_020": ("medical_concern_present", 2),
    # ── Medical Financial Assistance (healthcare_access) ──
    "MED_001": ("medical_cost_strain", 3),
    "MED_002": ("medical_cost_strain", 3),
    "MED_003": ("medical_cost_strain", 3),
    "MED_004": ("", 1),
    "MED_005": ("medical_cost_strain", 2),
    "MED_006": ("transport_barrier", 2),
    "MED_007": ("dx_cancer", 3),
    "MED_008": ("medical_cost_strain", 1),
    "MED_009": ("assistive_device_need", 2),
    "MED_010": ("", 1),
    "MED_011": ("high_risk", 2),
    "MED_012": ("dx_tb", 3),
    # ── Financial / Social Protection ──
    "FIN_001": ("low_income", 3),
    "FIN_002": ("no_pension", 3),
    "FIN_003": ("frailty", 3),
    "FIN_004": ("financial_crisis", 3),
    "FIN_005": ("food_insecurity", 3),
    "FIN_006": ("transport_barrier", 2),
    "FIN_007": ("", 1),
    "FIN_008": ("low_income", 2),
    "FIN_009": ("age_80", 2),        # category benefits (centenarian milestones)
    "FIN_010": ("age_85", 2),
    "FIN_011": ("age_90", 2),
    "FIN_012": ("age_95", 2),
    "FIN_013": ("age_100", 3),
    "FIN_014": ("food_insecurity", 2),
    "FIN_015": ("has_disability", 1),
    "FIN_016": ("medical_cost_strain", 1),
    "FIN_017": ("", 1),
    "FIN_018": ("low_income", 2),
    "FIN_019": ("no_pension", 2),
    "FIN_020": ("", 1),
    # ── Functional / Home Care ──
    "FUNC_001": ("frailty", 4),
    "FUNC_002": ("caregiver_needed", 2),
    "FUNC_003": ("frailty", 3),
    "FUNC_004": ("adl_difficulty", 4),
    "FUNC_005": ("mobility_alone", 3),
    "FUNC_006": ("frail_80plus", 3),
    "FUNC_007": ("low_independence", 2),
    "FUNC_008": ("dependency_high", 3),
    "FUNC_009": ("mobility_limited_outside", 2),
    "FUNC_010": ("caregiver_needed", 3),
    "FUNC_011": ("unsafe_home", 3),  # category household_safety
    "FUNC_012": ("unsafe_home", 3),  # category household_safety
    "FUNC_013": ("", 1),             # category household_safety (disaster-prone: no signal)
    "FUNC_014": ("abandoned", 5),
    "FUNC_015": ("abandoned", 4),
    "FUNC_016": ("frailty", 2),
    "FUNC_017": ("mobility_limited_outside", 2),
    "FUNC_018": ("func_chronic", 3),
    "FUNC_019": ("caregiver_needed", 2),
    "FUNC_020": ("frailty", 2),
    # ── Assistive Devices ──
    "ASSIST_001": ("mobility_limited_outside", 3),
    "ASSIST_002": ("vision_concern", 3),
    "ASSIST_003": ("hearing_concern", 3),
    "ASSIST_004": ("dental_concern", 2),
    "ASSIST_005": ("has_disability", 3),
    "ASSIST_006": ("has_disability", 2),
    "ASSIST_007": ("assistive_device_need", 1),
    "ASSIST_008": ("assistive_device_need", 2),
    "ASSIST_009": ("fall_risk", 2),
    "ASSIST_010": ("mobility_limited_outside", 1),
    # ── Social Participation / Community ──
    "SOC_001": ("lives_alone", 2),
    "SOC_002": ("low_family_support", 2),
    "SOC_003": ("low_social_support", 2),
    "SOC_004": ("not_association_member", 1),
    "SOC_005": ("low_participation", 2),
    "SOC_006": ("lonely", 3),
    "SOC_007": ("abuse_risk", 5),    # category elder_protection
    "SOC_008": ("abuse_risk", 5),    # category elder_protection
    "SOC_009": ("abandoned", 5),     # category elder_protection
    "SOC_010": ("abuse_risk", 4),    # category elder_protection
    "SOC_011": ("mobility_limited_outside", 1),
    "SOC_012": ("sensory_barrier", 1),
    "SOC_013": ("high_risk", 1),
    "SOC_014": ("low_participation", 1),
    "SOC_015": ("caregiver_needed", 1),
    "SOC_016": ("abuse_risk", 4),    # category elder_protection
    "SOC_017": ("isolated", 1),
    "SOC_018": ("widowed", 2),
    # ── Mental Health / Psychosocial ──
    "MH_001": ("emotional_distress", 4),
    "MH_002": ("mh_crisis", 5),
    "MH_003": ("lonely_distress", 3),
    "MH_004": ("emotional_concern", 1),
    "MH_005": ("bereavement", 2),
    "MH_006": ("isolated", 2),
    "MH_007": ("emotional_alone", 3),
    "MH_008": ("abuse_risk", 3),
    "MH_009": ("emotional_concern", 1),
    "MH_010": ("low_wellbeing", 2),
    # ── Healthcare Access / Transport ──
    "ACCESS_001": ("healthcare_difficulty", 2),
    "ACCESS_002": ("transport_barrier", 2),
    "ACCESS_003": ("service_access_low", 2),
    "ACCESS_004": ("access_gap_chronic", 3),
    "ACCESS_005": ("access_barrier_nocheckup", 2),
    "ACCESS_006": ("transport_barrier", 1),
    "ACCESS_007": ("sensory_barrier", 1),
    "ACCESS_008": ("", 1),
    "ACCESS_009": ("", 1),
    "ACCESS_010": ("hc_alone", 2),
    "ACCESS_011": ("access_high_risk", 2),
    "ACCESS_012": ("multiple_unmet_needs", 1),
    # ── Livelihood ──
    "LIV_001": ("wants_livelihood", 2),
    "LIV_002": ("wants_livelihood", 2),
    "LIV_003": ("wants_livelihood", 1),
    "LIV_004": ("", 1),
    "LIV_005": ("productive_capable", 1),
    "LIV_006": ("", 1),
    "LIV_007": ("wants_livelihood", 1),
    "LIV_008": ("", 1),
    "LIV_009": ("productive_capable", 1),
    "LIV_010": ("", 1),
    # ── Governance / Human Validation (never per-senior items) ──
    "SAFE_001": ("", 1),
    "SAFE_002": ("", 1),
    "SAFE_003": ("", 1),
    "SAFE_004": ("", 1),
    "SAFE_005": ("", 1),
}


def _resolve_csv() -> str:
    here = os.path.dirname(os.path.abspath(__file__))
    candidates = [
        os.path.abspath(os.path.join(here, "..", "..", "..", "recommendations_list.csv")),
        os.path.abspath(os.path.join(here, "recommendations_list.csv")),
        os.path.abspath(os.path.join(os.getcwd(), "recommendations_list.csv")),
    ]
    for c in candidates:
        if os.path.exists(c):
            return c
    raise SystemExit("recommendations_list.csv not found in: " + ", ".join(candidates))


def main() -> int:
    path = _resolve_csv()
    with open(path, encoding="utf-8-sig", newline="") as fh:
        rows = list(csv.DictReader(fh))
    codes = {r["recommendation_code"] for r in rows}
    missing = codes - set(CODE_TAGS)
    if missing:
        raise SystemExit(f"CODE_TAGS missing entries for: {sorted(missing)}")
    fieldnames = list(rows[0].keys())
    for col in ("trigger_tags", "priority_weight"):
        if col not in fieldnames:
            fieldnames.append(col)
    for r in rows:
        tags, weight = CODE_TAGS[r["recommendation_code"]]
        r["trigger_tags"] = tags
        r["priority_weight"] = str(weight)
    with open(path, "w", encoding="utf-8-sig", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=fieldnames)
        w.writeheader()
        w.writerows(rows)
    fired = sum(1 for r in rows if r["trigger_tags"])
    print(f"OK wrote {len(rows)} rows ({fired} with tags) -> {path}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
