"""Catalog-driven recommendation matcher.

Single source of truth = recommendations_list.csv (content + trigger_tags +
priority_weight). A catalog row fires when its trigger_tags intersect the
senior's need-tags (see extract_need_tags). Selection caps health and orders
non-health first for routine seniors. Recommendation TEXT is emitted verbatim
from the catalog (no machine-generated clinical prose).
"""
from __future__ import annotations

import csv
import os
from dataclasses import dataclass, field
from typing import Any, Dict, List, Optional, Set

HEALTH_CATEGORY = "health"
GOVERNANCE_CATEGORY = "governance"
TOTAL_REC_CAP = 10
SKIP_TOKENS = {"none", "nan", "", "n/a", "no concern", "no concerns",
               "physically healthy", "healthy eyes", "healthy hearing", "healthy teeth"}

_CATALOG_CACHE: Optional[List["CatalogRow"]] = None


@dataclass(frozen=True)
class CatalogRow:
    code: str
    section: str
    category: str
    who_domain: str
    trigger_summary: str
    recommendation: str
    service_provider: str
    source: str
    apa_reference: str
    source_type: str
    source_link: str
    requires_human_validation: bool
    key_program_tag: str
    implementation_note: str
    trigger_tags: frozenset
    priority_weight: int


def _candidate_paths() -> List[str]:
    here = os.path.dirname(os.path.abspath(__file__))
    return [
        os.path.join(here, "recommendations_list.csv"),                              # shipped copy (primary)
        os.path.abspath(os.path.join(here, "..", "..", "..", "recommendations_list.csv")),  # outer repo root
        os.path.abspath(os.path.join(os.getcwd(), "recommendations_list.csv")),
    ]


def _resolve_path(path: Optional[str]) -> str:
    if path:
        return path
    for c in _candidate_paths():
        if os.path.exists(c):
            return c
    raise FileNotFoundError("recommendations_list.csv not found: " + ", ".join(_candidate_paths()))


def _as_bool(v: Any) -> bool:
    return str(v).strip().lower() in {"1", "true", "yes", "y"}


def load_catalog(path: Optional[str] = None, force: bool = False) -> List[CatalogRow]:
    global _CATALOG_CACHE
    if _CATALOG_CACHE is not None and not force and path is None:
        return _CATALOG_CACHE
    resolved = _resolve_path(path)
    rows: List[CatalogRow] = []
    with open(resolved, encoding="utf-8-sig", newline="") as fh:
        for r in csv.DictReader(fh):
            tags = frozenset(
                t.strip() for t in str(r.get("trigger_tags", "") or "").split("|") if t.strip()
            )
            try:
                weight = int(float(r.get("priority_weight", "1") or "1"))
            except (TypeError, ValueError):
                weight = 1
            rows.append(CatalogRow(
                code=r["recommendation_code"].strip(),
                section=r.get("section", ""),
                category=r.get("category", "").strip(),
                who_domain=r.get("WHO_domain", ""),
                trigger_summary=r.get("trigger_summary", ""),
                recommendation=r.get("recommendation", ""),
                service_provider=r.get("service_provider", ""),
                source=r.get("source", ""),
                apa_reference=r.get("apa_reference", ""),
                source_type=r.get("source_type", ""),
                source_link=r.get("source_link", ""),
                requires_human_validation=_as_bool(r.get("requires_human_validation", "TRUE")),
                key_program_tag=r.get("key_program_tag", ""),
                implementation_note=r.get("implementation_note", ""),
                trigger_tags=tags,
                priority_weight=weight,
            ))
    if path is None:
        _CATALOG_CACHE = rows
    return rows


# keyword (lowercase, in medical_concern text) -> disease tag
DISEASE_TAG_MAP = {
    "hypertension": "dx_hypertension", "high blood pressure": "dx_hypertension",
    "diabetes": "dx_diabetes", "blood sugar": "dx_diabetes",
    "coronary heart disease": "dx_cardiac", "heart disease": "dx_cardiac", "heart": "dx_cardiac",
    "stroke": "dx_stroke",
    "dementia": "dx_dementia", "alzheimer": "dx_dementia", "parkinson": "dx_dementia",
    "cancer": "dx_cancer",
    "asthma": "dx_respiratory", "copd": "dx_respiratory",
    "tuberculosis": "dx_tb", "tb": "dx_tb",
    "arthritis": "dx_arthritis", "osteoporosis": "dx_arthritis",
    "kidney": "dx_kidney", "chronic kidney disease": "dx_kidney", "dialysis": "dx_kidney",
    "glaucoma": "vision_concern", "cataract": "vision_concern", "eye": "vision_concern",
    "hearing impairment": "hearing_concern",
    "anemia": "chronic_disease",
    "physical disability": "has_disability",
    "depression": "dx_mental", "anxiety": "dx_mental",
    "other chronic disease": "chronic_disease",
}


def _sf(v: Any, default: float = 0.0) -> float:
    try:
        if v is None or (isinstance(v, str) and v.strip() == ""):
            return default
        return float(v)
    except (TypeError, ValueError):
        return default


def _present(v: Any) -> bool:
    s = str(v or "").strip().lower()
    return bool(s) and s not in SKIP_TOKENS


def extract_need_tags(row: Dict[str, Any]) -> Set[str]:
    """Turn a merged senior feature dict into the senior's need-tags.

    Thresholds are ported verbatim from inference_service._*_recs so triggering
    behaviour matches the validated engine; only the OUTPUT (a tag set) differs.
    """
    t: Set[str] = set()
    age = _sf(row.get("age"), 70)

    # ── disease (medical_concern keyword scan) ──
    med = str(row.get("medical_concern", "") or "").lower()
    for kw, tag in DISEASE_TAG_MAP.items():
        if kw in med:
            t.add(tag)
            if tag.startswith("dx_") or tag == "chronic_disease":
                t.add("chronic_disease")
    if "dx_hypertension" in t and "dx_diabetes" in t:
        t.add("dx_htn_dm")
    if "dx_mental" in t:
        t.add("emotional_concern")

    # ── sensory / specialty concern fields ──
    if _present(row.get("optical_concern")):
        t |= {"vision_concern", "assistive_device_need"}
    if _present(row.get("hearing_concern")):
        t |= {"hearing_concern", "assistive_device_need"}
    if _present(row.get("dental_concern")):
        t.add("dental_concern")
    if _present(row.get("medical_concern")):
        t.add("medical_concern_present")
    if "vision_concern" in t or "hearing_concern" in t:
        t.add("sensory_barrier")

    # ── functional (ported from _functional_recs / _assistive_device_recs) ──
    func_indep = _sf(row.get("func_independence"), 3.0)
    func_auto = _sf(row.get("func_autonomy"), 3.0)
    func_ctrl = _sf(row.get("func_control"), 3.0)
    mob_out = _sf(row.get("phy_mobility_outside"), 3.0)
    mob_in = _sf(row.get("phy_mobility_indoor"), 3.0)
    func_score = _sf(row.get("sec6_func_score"), 0.5)
    risk_func = _sf(row.get("risk_functional"), 0.0)
    phy_score = _sf(row.get("sec6_phy_score"), 0.5)
    dep_risk = _sf(row.get("sec4_dependency_risk"), 0.0)
    phy_energy = _sf(row.get("phy_energy"), 3.0)
    lives_alone = _as_bool(row.get("sec4_lives_alone", row.get("lives_alone", 0)))

    if func_score < 0.45 or risk_func > 0.55 or phy_score < 0.40:
        t.add("frailty")
    if func_indep <= 2 or func_score < 0.45:
        t.add("adl_difficulty")
    if func_indep <= 3:
        t.add("low_independence")
    if func_auto <= 2 or func_ctrl <= 2:
        t.add("low_autonomy")
    if mob_out <= 3:
        t.add("mobility_limited_outside")
    if mob_in <= 2:
        t.add("mobility_limited_indoor")
    if mob_out <= 3 or mob_in <= 3 or func_indep <= 3:
        t.add("assistive_device_need")
    if dep_risk > 0.60:
        t.add("dependency_high")
    if phy_score < 0.40 or (phy_energy <= 2 and age >= 70):
        t.add("fall_risk")
    if lives_alone:
        t.add("lives_alone")
    if ("mobility_limited_outside" in t or "mobility_limited_indoor" in t) and lives_alone:
        t.add("mobility_alone")
    if age >= 80 and ("low_independence" in t or "frailty" in t):
        t.add("frail_80plus")
    if ("frailty" in t or "low_independence" in t) and "chronic_disease" in t:
        t.add("func_chronic")

    # ── age milestones (Expanded Centenarians Act; exact-age, matches existing engine) ──
    iage = int(age)
    if iage == 80:
        t.add("age_80")
    elif iage == 85:
        t.add("age_85")
    elif iage == 90:
        t.add("age_90")
    elif iage == 95:
        t.add("age_95")
    elif iage >= 100:
        t.add("age_100")
    if age >= 70 or not _sf(row.get("checkup_enc", row.get("has_medical_checkup", 0.0)), 0.0):
        t.add("preventive_due")

    # ── abandonment / abuse (keyword scan over free-text fields) ──
    blob = " ".join(str(row.get(k, "") or "") for k in (
        "medical_concern", "social_emotional_concern", "housing_concern", "household_condition",
    )).lower()
    if any(k in blob for k in ("abandon", "neglect", "homeless", "unattached")):
        t |= {"abandoned", "abuse_risk"}
    if any(k in blob for k in ("abuse", "exploit", "maltreat", "coerc")):
        t.add("abuse_risk")

    # ── disability ──
    if _sf(row.get("sec4_has_disability", row.get("has_disability", 0))) == 1:
        t |= {"has_disability", "assistive_device_need"}

    # ── household safety (ported from _household_safety_recs) ──
    env_safe = _sf(row.get("env_safe_home"), 3.0)
    hh = str(row.get("household_condition", "") or "").lower()
    housing = str(row.get("housing_concern", "") or "").strip().lower()
    unsafe_kw = ("poor", "damaged", "unsafe", "dilapidated", "makeshift", "needs repair")
    if env_safe <= 2 or any(k in hh for k in unsafe_kw) or (housing and housing not in {"none", "nan", ""}):
        t.add("unsafe_home")
    if (mob_out <= 3 or mob_in <= 3) and env_safe <= 3 and age >= 70:
        t.add("fall_risk")

    # ── social (ported from _social_recs) ──
    soc_support = _sf(row.get("soc_social_support"), 3.0)
    soc_friend = _sf(row.get("soc_close_friend"), 3.0)
    soc_part = _sf(row.get("soc_participation"), 3.0)
    soc_opp = _sf(row.get("soc_opportunity"), 3.0)
    soc_resp = _sf(row.get("soc_respect"), 3.0)
    lonely_r = _sf(row.get("psych_lonely_r"), 3.0)
    fam_support = _sf(row.get("sec2_family_support"), 0.5)
    is_member = _as_bool(row.get("is_association_member", 0))
    marital = str(row.get("marital_status", "") or "").strip()

    if soc_support <= 2 or soc_friend <= 2:
        t.add("low_social_support")
    if soc_part <= 2 or (soc_part <= 3 and (soc_opp <= 2 or soc_resp <= 2)):
        t.add("low_participation")
    if lonely_r <= 2:
        t.add("lonely")
    if fam_support < 0.30:
        t.add("low_family_support")
    if not is_member:
        t.add("not_association_member")
    if marital in ("Widowed", "Separated"):
        t |= {"widowed", "bereavement"}
    if lives_alone and soc_support <= 3:
        t.add("isolated")

    # ── mental health (emotional concern free-text) ──
    emo = str(row.get("social_emotional_concern", "") or "").strip().lower()
    if emo and emo not in {"none", "nan", "", "n/a"}:
        t.add("emotional_concern")
        if any(k in emo for k in ("depression", "anxiety", "hopeless", "sad", "grief",
                                  "stress", "trauma", "withdrawn", "isolation")):
            t.add("emotional_distress")
        if any(k in emo for k in ("grief", "bereave", "loss of")):
            t.add("bereavement")
        if any(k in emo for k in ("suicid", "self-harm", "self harm", "crisis", "harm myself")):
            t.add("mh_crisis")
    if "lonely" in t and ("emotional_concern" in t or "emotional_distress" in t):
        t.add("lonely_distress")
    if "emotional_concern" in t and lives_alone:
        t.add("emotional_alone")
    if _sf(row.get("wellbeing_score"), 1.0) < 0.50:
        t.add("low_wellbeing")

    # ── healthcare access (ported from _hc_access_recs) ──
    hc = str(row.get("healthcare_difficulty", "") or "").lower()
    service_acc = _sf(row.get("env_service_access"), 3.0)
    has_checkup = _sf(row.get("checkup_enc", row.get("has_medical_checkup", 0.0)), 0.0)
    if not has_checkup:
        t.add("no_checkup")
    if hc and hc not in {"none", "nan", ""}:
        t.add("healthcare_difficulty")
    if "cost" in hc or "expensive" in hc:
        t.add("medical_cost_strain")
    if "transport" in hc or "distance" in hc:
        t.add("transport_barrier")
    if service_acc <= 2 or (service_acc <= 3 and age >= 75):
        t.add("service_access_low")
    if "chronic_disease" in t and not has_checkup:
        t.add("access_gap_chronic")
    if not has_checkup and ("transport_barrier" in t or "service_access_low" in t or "healthcare_difficulty" in t):
        t.add("access_barrier_nocheckup")
    if lives_alone and ("healthcare_difficulty" in t or "service_access_low" in t):
        t.add("hc_alone")

    # ── financial (ported from _financial_recs) ──
    income_enc = _sf(row.get("income_enc"), 5.0)
    eco = _sf(row.get("sec5_eco_stability"), 0.4)
    env_fin_med = _sf(row.get("env_fin_medical"), 3.0)
    env_fin_hh = _sf(row.get("env_fin_household"), 3.0)
    income_band = int(min(max(income_enc, 1), 9))
    if income_band <= 2 or eco < 0.25:
        t |= {"low_income", "financial_crisis"}
    elif income_band <= 4 or eco < 0.45:
        t.add("low_income")
    if _sf(row.get("has_pension")) == 0:
        t.add("no_pension")
    if env_fin_med <= 2:
        t.add("medical_cost_strain")
    if env_fin_hh <= 2:
        t |= {"financial_strain", "food_insecurity"}

    # ── livelihood (only mobile, non-frail seniors with income need) ──
    if income_band <= 4 and func_indep >= 3 and "frailty" not in t:
        t |= {"wants_livelihood", "productive_capable"}

    # ── healthcare coverage proxy ──
    if "no_checkup" in t or "chronic_disease" in t or "medical_cost_strain" in t:
        t.add("philhealth_gap")

    return t
