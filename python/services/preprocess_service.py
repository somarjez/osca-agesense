"""
OSCA Preprocessing Service
Accepts raw senior citizen profile + QoL survey data from Laravel
and returns scaled/encoded features ready for ML inference.

Usage:
    python preprocess_service.py
"""

import os
import json
import pickle
import warnings
import logging
from functools import lru_cache
from typing import Any, Dict, List, Optional

import numpy as np
from flask import Flask, request, jsonify

warnings.filterwarnings("ignore")
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = Flask(__name__)
BASE_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))


def _resolve_model_dir() -> str:
    env_model_dir = os.environ.get("ML_MODELS_PATH")
    if env_model_dir:
        return env_model_dir if os.path.isabs(env_model_dir) else os.path.join(BASE_DIR, env_model_dir)

    candidates = [
        os.path.join(BASE_DIR, "python", "models"),
        os.path.join(BASE_DIR, "storage", "app", "ml_models"),
        os.path.join(os.path.expanduser("~"), "AppData", "Local", "OSCA-System", "ml_models"),
        os.path.abspath(os.path.join(BASE_DIR, "..", "osca_output", "model")),
    ]
    for candidate in candidates:
        if os.path.isdir(candidate):
            return candidate
    return candidates[0]


MODEL_DIR = _resolve_model_dir()

# ── Asset / Income weighting tables ───────────────────────────────────────────
REAL_ASSET_WEIGHTS = {
    "house & lot": 1.00, "house and lot": 1.00, "house": 0.80,
    "apartment": 0.70, "apartment / rental": 0.70, "commercial building": 0.85,
    "agricultural land": 0.65, "agricultural": 0.65, "farm": 0.65, "lot": 0.60,
    "lot / farmland": 0.60, "fishpond": 0.55, "resort": 0.55,
    "no known assets": 0.00,
}

MOVABLE_ASSET_WEIGHTS = {
    "mobile phone": 0.70, "smartphone": 0.70, "mobile phone / smartphone": 0.70,
    "personal computer": 0.55, "laptop": 0.55,
    "tablet": 0.50, "automobile": 1.00, "motorcycle": 0.55,
    "bicycle": 0.25, "heavy equipment": 0.60,
    "appliances": 0.65, "refrigerator": 0.65, "tv": 0.40,
    "washing machine": 0.50, "no known assets": 0.00,
}

INCOME_SOURCE_WEIGHTS = {
    "own pension": 1.00, "spouse pension": 0.85, "own earnings": 0.75,
    "own earnings / salary": 0.75, "own earnings/salary": 0.75,
    "insurance": 0.70, "stocks": 0.65,
    "dividends": 0.65, "rentals": 0.65, "rentals/sharecrops": 0.60,
    "sharecrops": 0.55,
    "business": 0.60, "savings": 0.55, "spouse salary": 0.50,
    "livestock": 0.45, "livestock/farm": 0.45, "farm": 0.45,
    "orchard": 0.45, "fishing": 0.40,
    "dependent on children": 0.35, "dependent on children/relatives": 0.35,
    "dependent on relatives": 0.30,
}

COMMUNITY_WEIGHTS = {
    "community leader": 1.00,
    "health / wellness volunteer": 0.90,
    "health/wellness volunteer": 0.90,
    "disaster response volunteer": 0.85,
    "barangay volunteer": 0.80,
    "senior citizen association member": 0.75,
    "counseling / referral": 0.70,
    "counseling/referral": 0.70,
    "resource volunteer": 0.65,
    "friendly visits": 0.60,
    "religious": 0.55,
    "sponsorship": 0.55,
    "community beautification": 0.50,
}

SKILL_WEIGHTS = {
    "medical": 0.90,
    "dental": 0.85,
    "counseling": 0.80,
    "legal services": 0.80,
    "teaching": 0.75,
    "engineering": 0.75,
    "social service": 0.70,
    "administrative": 0.65,
    "small business": 0.60,
    "entrepreneurship": 0.60,
    "computer": 0.60,
    "digital skills": 0.60,
    "caregiving": 0.55,
    "farming": 0.45,
    "cooking": 0.45,
    "sewing": 0.45,
    "tailoring": 0.45,
    "arts": 0.40,
    "crafts": 0.40,
    "driving": 0.40,
    "fishing": 0.35,
    "carpenter": 0.40,
    "plumber": 0.40,
    "mason": 0.40,
    "barber": 0.35,
    "hairdresser": 0.35,
    "beautycare": 0.35,
    "housekeeping": 0.30,
    "factory worker": 0.30,
}

HOUSEHOLD_RISK_WEIGHTS = {
    "informal settler": 1.00, "no permanent house": 0.95,
    "high cost of rent": 0.80, "overcrowded": 0.70,
    "overcrowded in home": 0.70, "no privacy": 0.55,
    "longing for independent": 0.45, "longing for independent living": 0.45,
    "shared with relatives": 0.25,
    "government-provided": 0.20,
}

DISEASE_WEIGHTS = {
    "coronary heart disease": 1.00,
    "heart disease": 1.00,
    "stroke": 1.00,
    "cerebrovascular": 1.00,
    "cancer": 1.00,
    "tumor": 0.90,
    "dementia": 0.90,
    "alzheimer": 0.90,
    "parkinson": 0.85,
    "memory loss": 0.85,
    "diabetes": 0.85,
    "tuberculosis": 0.75,
    "tb": 0.70,
    "hypertension": 0.75,
    "high blood pressure": 0.75,
    "copd": 0.70,
    "depression": 0.70,
    "glaucoma": 0.70,
    "chronic kidney disease": 0.65,
    "kidney": 0.65,
    "renal": 0.65,
    "hypercholesterolemia": 0.65,
    "anxiety": 0.65,
    "hearing impairment": 0.65,
    "asthma": 0.65,
    "respiratory": 0.65,
    "mental": 0.60,
    "osteoporosis": 0.60,
    "cataract": 0.55,
    "arthritis": 0.55,
    "gout": 0.55,
    "gum disease": 0.55,
    "hypoglycemia": 0.50,
    "uti": 0.50,
    "urinary": 0.50,
    "ulcer": 0.50,
    "partial hearing": 0.45,
    "tooth loss": 0.45,
    "back pain": 0.45,
    "high cholesterol": 0.65,
    "blurred vision": 0.40,
    "eye": 0.40,
    "tooth decay": 0.40,
    "dental": 0.40,
    "physical disability": 0.75,
    "anemia": 0.55,
    "other chronic disease": 0.65,
    "__default__": 0.50,
}

DOMAIN_WEIGHTS = {
    "medical": 0.28,
    "financial": 0.18,
    "social": 0.14,
    "hc_access": 0.12,
    "housing": 0.10,
    "functional": 0.10,
    "sensory": 0.08,
}

INCOME_RISK = {
    1: 1.00, 2: 0.85, 3: 0.70, 4: 0.50, 5: 0.30,
    6: 0.20, 7: 0.15, 8: 0.10, 9: 0.05,
}

EDU_ORDER = [
    "Not Attended School", "Elementary Level", "Elementary Graduate",
    "High School Level", "High School Graduate", "Vocational",
    "College Level", "College Graduate", "Post Graduate", "Post-Graduate"
]

INCOME_ORDER = [
    "Below 1,000", "1,000 - 5,000", "5,000 - 10,000",
    "10,000 - 20,000", "20,000 - 30,000", "30,000 - 40,000",
    "40,000 - 50,000", "50,000 - 60,000", "60,000 and above"
]

SECTION_WEIGHTS = {
    "sec1_age_risk": 0.05,
    "sec2_family_support": 0.08,
    "sec3_hr_score": 0.07,
    "sec4_dependency_risk": 0.12,
    "sec5_eco_stability": 0.25,
    "sec6_health_score": 0.43,
}

REVERSE_COLS = ["phy_pain_r", "phy_health_limit_r", "psych_lonely_r", "env_income_limit_r"]

QOL_FEATURE_COLS = [
    "qol_enjoy_life", "qol_life_satisfaction", "qol_future_outlook", "qol_meaningfulness",
    "phy_energy", "phy_pain_r", "phy_health_limit_r", "phy_mobility_outside", "phy_mobility_indoor",
    "psych_happiness", "psych_peace", "psych_lonely_r", "psych_confidence",
    "func_independence", "func_autonomy", "func_control", "env_income_limit_r",
    "soc_social_support", "soc_close_friend", "soc_participation", "soc_opportunity", "soc_respect",
    "env_safe_home", "env_safe_neighborhood", "env_service_access", "env_home_comfort",
    "env_fin_medical", "env_fin_household", "env_fin_personal",
    "spi_belief_comfort", "spi_belief_practice",
]

HEALTHY_FLAGS = {
    "none", "physically healthy", "healthy eyes", "healthy hearing",
    "healthy teeth", "living in healthy environment",
    "living in a healthy environment", "", "n/a", "nan"
}

SOCIAL_EMOTIONAL_WEIGHTS = {
    "depressed":           0.80,
    "depression":          0.80,
    "anxiety":             0.70,
    "helplessness":        0.70,
    "worthlessness":       0.70,
    "neglect":             0.65,
    "rejection":           0.65,
    "loneliness":          0.60,
    "lonliness":           0.60,
    "isolation":           0.60,
    "lack social support": 0.55,
    "lack leisure":        0.45,
}

SOCIAL_EMOTIONAL_HEALTHY = {
    "living in healthy environment",
    "living in a healthy environment",
}

# Short section-weight keys used in asset_weights.json → internal key names
_SECTION_KEY_MAP: Dict[str, str] = {
    "sec1": "sec1_age_risk",
    "sec2": "sec2_family_support",
    "sec3": "sec3_hr_score",
    "sec4": "sec4_dependency_risk",
    "sec5": "sec5_eco_stability",
    "sec6": "sec6_health_score",
}


# ── Loaders ───────────────────────────────────────────────────────────────────
@lru_cache(maxsize=None)
def _load_json_if_exists(filename: str) -> Optional[Any]:
    path = os.path.join(MODEL_DIR, filename)
    if not os.path.exists(path):
        return None
    with open(path, "r", encoding="utf-8") as f:
        return json.load(f)


@lru_cache(maxsize=None)
def _load_pickle_if_exists(filename: str) -> Optional[Any]:
    path = os.path.join(MODEL_DIR, filename)
    if not os.path.exists(path):
        return None
    with open(path, "rb") as f:
        return pickle.load(f)


@lru_cache(maxsize=1)
def _runtime_weights() -> Dict[str, Any]:
    """
    Merge asset_weights.json from MODEL_DIR over the hardcoded defaults.
    Returns a unified weights namespace keyed by type. Cached after first call.
    Falls back silently to hardcoded defaults when the file is absent or malformed.
    """
    data = _load_json_if_exists("asset_weights.json")

    if not data or not isinstance(data, dict):
        logger.info("asset_weights.json missing or empty; using hardcoded defaults.")
        return {
            "real_asset":      dict(REAL_ASSET_WEIGHTS),
            "movable_asset":   dict(MOVABLE_ASSET_WEIGHTS),
            "income_source":   dict(INCOME_SOURCE_WEIGHTS),
            "community":       dict(COMMUNITY_WEIGHTS),
            "skill":           dict(SKILL_WEIGHTS),
            "household_risk":  dict(HOUSEHOLD_RISK_WEIGHTS),
            "disease":         dict(DISEASE_WEIGHTS),
            "domain":          dict(DOMAIN_WEIGHTS),
            "income_risk":     dict(INCOME_RISK),
            "section":         dict(SECTION_WEIGHTS),
            "social_emotional": dict(SOCIAL_EMOTIONAL_WEIGHTS),
        }

    def _merge(default: Dict[str, float], json_key: str) -> Dict[str, float]:
        raw = data.get(json_key) or {}
        if not isinstance(raw, dict):
            return dict(default)
        return {**default, **{str(k).lower(): float(v)
                               for k, v in raw.items()
                               if isinstance(v, (int, float))}}

    # Section weights: JSON uses short keys sec1..sec6; remap to internal names.
    json_sec = data.get("section_weights") or {}
    merged_section: Dict[str, float] = dict(SECTION_WEIGHTS)
    for json_k, internal_k in _SECTION_KEY_MAP.items():
        if json_k in json_sec:
            try:
                merged_section[internal_k] = float(json_sec[json_k])
            except (TypeError, ValueError):
                pass
    # Also accept full internal key names directly.
    for k in SECTION_WEIGHTS:
        if k in json_sec:
            try:
                merged_section[k] = float(json_sec[k])
            except (TypeError, ValueError):
                pass

    # income_risk maps integer band (1-9) → float risk score.
    raw_ir = data.get("income_risk")
    if isinstance(raw_ir, dict):
        merged_ir: Dict[int, float] = dict(INCOME_RISK)
        for k, v in raw_ir.items():
            try:
                merged_ir[int(k)] = float(v)
            except (TypeError, ValueError):
                pass
    else:
        merged_ir = dict(INCOME_RISK)

    logger.info("asset_weights.json loaded successfully.")
    return {
        "real_asset":      _merge(REAL_ASSET_WEIGHTS,     "real_assets"),
        "movable_asset":   _merge(MOVABLE_ASSET_WEIGHTS,  "movable_assets"),
        "income_source":   _merge(INCOME_SOURCE_WEIGHTS,  "income_sources"),
        "community":       _merge(COMMUNITY_WEIGHTS,       "community"),
        "skill":           _merge(SKILL_WEIGHTS,           "skills"),
        "household_risk":  _merge(HOUSEHOLD_RISK_WEIGHTS, "household_risk"),
        "disease":         _merge(DISEASE_WEIGHTS,         "disease_weights"),
        "domain":          _merge(DOMAIN_WEIGHTS,          "domain_weights"),
        "income_risk":     merged_ir,
        "section":         merged_section,
        "social_emotional": _merge(SOCIAL_EMOTIONAL_WEIGHTS, "social_emotional_weights"),
    }


# ── Normalizers ───────────────────────────────────────────────────────────────
def _as_list(value: Any) -> List[str]:
    if value is None:
        return []
    if isinstance(value, (list, tuple, set)):
        return [str(v).strip() for v in value if str(v).strip()]
    if isinstance(value, str):
        if not value.strip():
            return []
        return [v.strip() for v in value.split(",") if v.strip()]
    return [str(value).strip()]


def _as_text(value: Any) -> str:
    if value is None:
        return ""
    if isinstance(value, (list, tuple, set)):
        return ", ".join(str(v) for v in value if str(v).strip())
    return str(value).strip()


def _safe_int(value: Any, default: int = 0) -> int:
    try:
        if value is None or value == "":
            return default
        return int(value)
    except (TypeError, ValueError):
        return default



def _safe_bool(value: Any, default: bool = False) -> bool:
    if isinstance(value, bool):
        return value
    if value is None:
        return default
    if isinstance(value, (int, float)):
        return bool(value)

    text = str(value).strip().lower()
    if text in {"1", "true", "yes", "y", "on"}:
        return True
    if text in {"0", "false", "no", "n", "off", ""}:
        return False
    return default


# ── Scoring helpers ───────────────────────────────────────────────────────────
def _weighted_score(values: Any, weight_map: Dict[str, float]) -> float:
    items = [item.lower() for item in _as_list(values)]
    if not items:
        return 0.0

    matched: List[float] = []
    for item in items:
        for key, weight in weight_map.items():
            if key in item or item in key:
                matched.append(weight)
                break

    if not matched:
        return 0.0

    matched.sort(reverse=True)
    score = 0.0
    for idx, weight in enumerate(matched):
        score += weight * (0.5 ** idx)
    return round(min(score, 1.0), 4)


def _multiselect_count(values: Any) -> int:
    return len(_as_list(values))


def _score_household_risk(values: Any, hw: Optional[Dict[str, float]] = None) -> float:
    text = _as_text(values).lower()
    if not text:
        return 0.0
    weights = hw if hw is not None else HOUSEHOLD_RISK_WEIGHTS
    max_risk = 0.0
    for key, risk in weights.items():
        if key in text:
            max_risk = max(max_risk, risk)
    return round(max_risk, 4)


def _ordinal_encode(value: str, ordered_list: List[str]) -> int:
    try:
        return ordered_list.index(value) + 1
    except (ValueError, TypeError):
        return 0


def _disease_severity_score(concern_value: Any, dw: Optional[Dict[str, float]] = None) -> float:
    text = _as_text(concern_value).strip().lower()
    if text in HEALTHY_FLAGS:
        return 0.0
    weights = dw if dw is not None else DISEASE_WEIGHTS
    matched_weights = [
        weight
        for keyword, weight in weights.items()
        if keyword != "__default__" and keyword in text
    ]
    if not matched_weights:
        return weights.get("__default__", DISEASE_WEIGHTS["__default__"])

    matched_weights.sort(reverse=True)
    score = 0.0
    for idx, weight in enumerate(matched_weights):
        score += weight * (0.4 ** idx)
    return min(score, 1.0)


def _social_emotional_score(concern_value: Any, sw: Optional[Dict[str, float]] = None) -> float:
    if concern_value is None:
        return 0.0
    items = _as_list(concern_value)
    if not items:
        return 0.0
    weights = sw if sw is not None else SOCIAL_EMOTIONAL_WEIGHTS
    matched: List[float] = []
    for item in items:
        text = item.strip().lower()
        if text in SOCIAL_EMOTIONAL_HEALTHY or text in HEALTHY_FLAGS:
            continue
        for kw, w in weights.items():
            if kw in text:
                matched.append(w)
                break
        else:
            matched.append(0.30)

    if not matched:
        return 0.0

    matched.sort(reverse=True)
    score = 0.0
    for idx, w in enumerate(matched):
        score += w * (0.4 ** idx)
    return min(score, 1.0)


def _health_concern_count(raw: Dict[str, Any]) -> int:
    groups = [
        _as_list(raw.get("medical_concern")),
        _as_list(raw.get("social_emotional_concern")),
        _as_list(raw.get("dental_concern")),
        _as_list(raw.get("optical_concern")),
        _as_list(raw.get("hearing_concern")),
    ]

    count = 0
    for group in groups:
        for item in group:
            if item.strip().lower() not in HEALTHY_FLAGS:
                count += 1
    return count


def _domain_avg(enc: Dict[str, Any], keys: List[str]) -> float:
    vals = [enc.get(k) for k in keys if enc.get(k) is not None]
    return round(float(sum(vals) / len(vals)), 3) if vals else 3.0


# ── Rule-based risk engine ────────────────────────────────────────────────────
def _compute_rule_based_risk(
    raw: Dict[str, Any],
    enc: Dict[str, Any],
    section_scores: Dict[str, float],
    W: Optional[Dict[str, Any]] = None,
) -> Dict[str, Any]:
    dw  = W["disease"]         if W is not None else None
    sw  = W["social_emotional"] if W is not None else None
    income_risk_map = W["income_risk"] if W is not None else INCOME_RISK
    domain_wt       = W["domain"]      if W is not None else DOMAIN_WEIGHTS

    med_score = _disease_severity_score(raw.get("medical_concern", ""), dw)
    den_score = _disease_severity_score(raw.get("dental_concern", ""), dw)
    soc_emo   = _social_emotional_score(raw.get("social_emotional_concern", ""), sw)
    medical_domain = min(med_score * 0.65 + den_score * 0.15 + soc_emo * 0.20, 1.0)

    opt_score = _disease_severity_score(raw.get("optical_concern", ""))
    hear_score = _disease_severity_score(raw.get("hearing_concern", ""))
    sensory_domain = min(opt_score * 0.55 + hear_score * 0.45, 1.0)

    income_enc_val = int(min(max(enc.get("income_enc", 5), 1), 9))
    income_risk_val = income_risk_map.get(income_enc_val, 0.50)

    eco_stability = section_scores.get("sec5_eco_stability", 0.5)
    real_asset_s = section_scores.get("sec5_real_asset_score", 0.3)
    movable_asset_s = section_scores.get("sec5_movable_asset_score", 0.3)
    has_pension = enc.get("has_pension", 0)

    fin_medical = enc.get("env_fin_medical", 3)
    fin_household = enc.get("env_fin_household", 3)
    fin_hardship = (1 - fin_medical / 5) * 0.45 + (1 - fin_household / 5) * 0.55
    asset_buffer = (real_asset_s * 0.55 + movable_asset_s * 0.45) * 0.25
    pension_bonus = -0.15 if has_pension else 0.0

    financial_domain = min(max(
        income_risk_val * 0.40 +
        fin_hardship * 0.25 +
        (1 - eco_stability) * 0.20 +
        pension_bonus -
        asset_buffer,
        0.0,
    ), 1.0)

    lives_alone = section_scores.get("sec4_lives_alone", enc.get("lives_alone", 0))
    soc_support = enc.get("soc_social_support", 3)
    soc_friend = enc.get("soc_close_friend", 3)
    soc_participation = enc.get("soc_participation", 3)
    soc_opportunity = enc.get("soc_opportunity", 3)
    family_support = section_scores.get("sec2_family_support", 0.3)
    community_score = section_scores.get("sec3_community_score", 0.2)

    social_domain = min(
        lives_alone * 0.25 +
        (1 - soc_support / 5) * 0.25 +
        (1 - soc_friend / 5) * 0.15 +
        (1 - family_support) * 0.15 +
        (1 - community_score) * 0.10 +
        (1 - soc_participation / 5) * 0.05 +
        (1 - soc_opportunity / 5) * 0.05,
        1.0,
    )

    func_indep = enc.get("func_independence", 3)
    func_auto = enc.get("func_autonomy", 3)
    mob_outside = enc.get("phy_mobility_outside", 3)
    mob_indoor = enc.get("phy_mobility_indoor", 3)
    sec1_risk = section_scores.get("sec1_age_risk", 0.5)
    skill_s = section_scores.get("sec3_skill_score", 0.3)
    func_s = section_scores.get("sec6_func_score", 0.5)

    functional_domain = min(
        sec1_risk * 0.20 +
        (1 - func_indep / 5) * 0.25 +
        (1 - func_auto / 5) * 0.15 +
        (1 - mob_outside / 5) * 0.15 +
        (1 - mob_indoor / 5) * 0.10 +
        (1 - func_s) * 0.10 +
        (1 - skill_s) * 0.05,
        1.0,
    )

    env_safe_home = enc.get("env_safe_home", 3)
    home_comfort = enc.get("env_home_comfort", 3)
    household_risk = section_scores.get("sec4_household_risk", 0.0)
    real_asset_s2 = section_scores.get("sec5_real_asset_score", 0.3)

    housing_domain = min(
        household_risk * 0.40 +
        (1 - env_safe_home / 5) * 0.30 +
        (1 - home_comfort / 5) * 0.20 +
        (1 - real_asset_s2) * 0.10,
        1.0,
    )

    hc_diff = _as_text(raw.get("healthcare_difficulty", "")).lower()
    has_checkup = enc.get("checkup_enc", enc.get("has_medical_checkup", 0))
    service_access = enc.get("env_service_access", 3)
    cost_flag = 1 if "cost" in hc_diff or "expensive" in hc_diff else 0
    transport_flag = 1 if "transport" in hc_diff or "distance" in hc_diff else 0
    movable_asset_s2 = section_scores.get("sec5_movable_asset_score", 0.3)

    hc_access_domain = min(
        cost_flag * 0.30 +
        transport_flag * 0.25 +
        (1 - has_checkup) * 0.20 +
        (1 - service_access / 5) * 0.15 +
        (1 - movable_asset_s2) * 0.10,
        1.0,
    )

    composite = (
        medical_domain   * domain_wt["medical"] +
        financial_domain * domain_wt["financial"] +
        social_domain    * domain_wt["social"] +
        hc_access_domain * domain_wt["hc_access"] +
        housing_domain   * domain_wt["housing"] +
        functional_domain * domain_wt["functional"] +
        sensory_domain   * domain_wt["sensory"]
    )

    level = (
        "HIGH" if composite >= 0.50 else
        "MODERATE" if composite >= 0.30 else
        "LOW"
    )

    return {
        "risk_medical": round(medical_domain, 4),
        "risk_financial": round(financial_domain, 4),
        "risk_social": round(social_domain, 4),
        "risk_functional": round(functional_domain, 4),
        "risk_housing": round(housing_domain, 4),
        "risk_hc_access": round(hc_access_domain, 4),
        "risk_sensory": round(sensory_domain, 4),
        "rule_composite": round(composite, 4),
        "risk_level_rule": level,
    }


# ── Main preprocessing ────────────────────────────────────────────────────────
def preprocess(raw: Dict[str, Any]) -> Dict[str, Any]:
    W   = _runtime_weights()
    enc: Dict[str, Any] = {}

    # 1. Demographic
    age = _safe_int(raw.get("age"), 0)
    enc["age"] = age
    enc["age_risk"] = round(max(0.0, min((age - 60) / 40, 1.0)), 4)

    # Bug 4 fix: accept both field names Laravel may send; prefer saved encoders
    # so encoding matches training exactly.
    edu_raw = raw.get("education") or raw.get("educational_attainment") or ""
    inc_raw = raw.get("monthly_income_range", "")

    edu_encoder = _load_pickle_if_exists("edu_encoder.pkl")
    if edu_encoder is not None:
        try:
            val = int(edu_encoder.transform([[edu_raw]])[0][0])
            enc["education_enc"] = val + 1 if val >= 0 else _ordinal_encode(edu_raw, EDU_ORDER)
        except Exception:
            enc["education_enc"] = _ordinal_encode(edu_raw, EDU_ORDER)
    else:
        enc["education_enc"] = _ordinal_encode(edu_raw, EDU_ORDER)

    income_encoder = _load_pickle_if_exists("income_encoder.pkl")
    if income_encoder is not None:
        try:
            val = int(income_encoder.transform([[inc_raw]])[0][0])
            enc["income_enc"] = val + 1 if val >= 0 else _ordinal_encode(inc_raw, INCOME_ORDER)
        except Exception:
            enc["income_enc"] = _ordinal_encode(inc_raw, INCOME_ORDER)
    else:
        enc["income_enc"] = _ordinal_encode(inc_raw, INCOME_ORDER)

    gender_map = {"Male": 0, "Female": 1, "Prefer not to say": 2}
    enc["gender_enc"] = gender_map.get(raw.get("gender", ""), 2)

    marital_map = {
        "Single": 0, "Married": 1, "Widowed": 2,
        "Separated": 3, "Divorced": 4, "Annulled": 5
    }
    enc["marital_enc"] = marital_map.get(raw.get("marital_status", ""), 0)

    # 2. Family / Social
    enc["num_children"] = _safe_int(raw.get("num_children"), 0)
    enc["num_working_children"] = _safe_int(raw.get("num_working_children"), 0)
    enc["household_size"] = max(_safe_int(raw.get("household_size"), 1), 1)

    support_map = {"Yes": 1.0, "No": 0.0, "Occasional": 0.5, "N/A": 0.0}
    enc["child_support_enc"] = support_map.get(raw.get("child_financial_support", "No"), 0.0)

    spouse_map = {"Yes": 1.0, "No": 0.0, "Deceased": 0.0, "N/A": 0.0}
    enc["spouse_working_enc"] = spouse_map.get(raw.get("spouse_working", "No"), 0.0)

    # 3. Multi-select normalization
    income_sources = _as_list(raw.get("income_source"))
    real_assets = _as_list(raw.get("real_assets"))
    movable_assets = _as_list(raw.get("movable_assets"))
    living_with = _as_list(raw.get("living_with"))
    household_condition = _as_list(raw.get("household_condition"))
    community_service = _as_list(raw.get("community_service"))
    specialization = _as_list(raw.get("specialization"))

    enc["income_source_count"] = _multiselect_count(income_sources)
    enc["income_source_score"] = _weighted_score(income_sources, W["income_source"])
    enc["real_asset_score"]    = _weighted_score(real_assets,    W["real_asset"])
    enc["movable_asset_score"] = _weighted_score(movable_assets, W["movable_asset"])
    enc["living_with_count"]   = _multiselect_count(living_with)
    enc["household_risk_score"] = _score_household_risk(household_condition, W["household_risk"])
    enc["community_service_count"] = _multiselect_count(community_service)
    enc["specialization_count"] = _multiselect_count(specialization)

    enc["is_association_member"] = int(
        any("senior citizen association" in s.lower() for s in community_service)
    )
    enc["has_pension"] = int(
        any("pension" in s.lower() for s in income_sources)
    )
    enc["lives_alone"] = int(
        len(living_with) == 1 and "alone" in living_with[0].lower()
    )

    enc["health_concern_count"] = _health_concern_count(raw)
    enc["has_medical_checkup"] = int(_safe_bool(raw.get("has_medical_checkup", False), False))
    enc["checkup_enc"] = enc["has_medical_checkup"]

    # 4. QoL Survey Features
    qol = raw.get("qol_responses", {}) or {}
    for col in QOL_FEATURE_COLS:
        val = qol.get(col)
        if val is None or val == "":
            enc[col] = 3
        else:
            intval = _safe_int(val, 3)
            enc[col] = 6 - intval if col in REVERSE_COLS else intval

    # 5. Section Scores
    section_scores: Dict[str, float] = {}

    if age < 70:
        section_scores["sec1_age_risk"] = 0.20
    elif age < 80:
        section_scores["sec1_age_risk"] = 0.50
    else:
        section_scores["sec1_age_risk"] = 0.85

    household_size_norm = min(enc["household_size"], 10) / 10
    working_children = min(enc["num_working_children"], 5) / 5
    section_scores["sec2_family_support"] = round(
        working_children * 0.35 +
        enc["child_support_enc"] * 0.35 +
        enc["spouse_working_enc"] * 0.20 +
        household_size_norm * 0.10,
        4,
    )
    section_scores["sec2_family_size_norm"] = round(household_size_norm, 4)

    education_norm  = enc["education_enc"] / 9 if enc["education_enc"] else 0.0
    skill_score     = _weighted_score(specialization,    W["skill"])
    community_score = _weighted_score(community_service, W["community"])

    section_scores["sec3_education_norm"] = round(education_norm, 4)
    section_scores["sec3_skill_score"] = round(skill_score, 4)
    section_scores["sec3_community_score"] = round(community_score, 4)
    section_scores["sec3_hr_score"] = round(
        education_norm * 0.45 + skill_score * 0.30 + community_score * 0.25,
        4,
    )

    living_with_norm = min(enc["living_with_count"], 5) / 5
    section_scores["sec4_dependency_risk"] = round(
        enc["lives_alone"] * 0.40 +
        enc["household_risk_score"] * 0.45 +
        (1 - living_with_norm) * 0.15,
        4,
    )
    section_scores["sec4_lives_alone"] = enc["lives_alone"]
    section_scores["sec4_household_risk"] = enc["household_risk_score"]

    income_norm = (enc["income_enc"] - 1) / 8 if enc["income_enc"] else 0.0
    section_scores["sec5_eco_stability"] = round(
        income_norm * 0.30 +
        enc["real_asset_score"] * 0.25 +
        enc["income_source_score"] * 0.20 +
        enc["movable_asset_score"] * 0.10 +
        enc["has_pension"] * 0.10 +
        enc["child_support_enc"] * 0.05,
        4,
    )
    section_scores["sec5_income_norm"] = round(income_norm, 4)
    section_scores["sec5_real_asset_score"] = enc["real_asset_score"]
    section_scores["sec5_movable_asset_score"] = enc["movable_asset_score"]
    section_scores["sec5_income_source_score"] = enc["income_source_score"]

    checkup = float(enc["has_medical_checkup"])
    sec6_phy = np.mean([
        enc.get("phy_energy", 3), enc.get("phy_pain_r", 3),
        enc.get("phy_health_limit_r", 3), enc.get("phy_mobility_outside", 3),
        enc.get("phy_mobility_indoor", 3),
    ]) / 5
    sec6_psy = np.mean([
        enc.get("psych_happiness", 3), enc.get("psych_peace", 3),
        enc.get("psych_lonely_r", 3), enc.get("psych_confidence", 3),
    ]) / 5
    sec6_func = np.mean([
        enc.get("func_independence", 3), enc.get("func_autonomy", 3),
        enc.get("func_control", 3),
    ]) / 5

    section_scores["sec6_health_score"] = round(
        sec6_phy * 0.35 + sec6_psy * 0.30 + sec6_func * 0.25 + checkup * 0.10,
        4,
    )
    section_scores["sec6_phy_score"] = round(float(sec6_phy), 4)
    section_scores["sec6_psy_score"] = round(float(sec6_psy), 4)
    section_scores["sec6_func_score"] = round(float(sec6_func), 4)

    SW = W["section"]
    overall = (
        (1 - section_scores["sec1_age_risk"])      * SW["sec1_age_risk"] +
        section_scores["sec2_family_support"]       * SW["sec2_family_support"] +
        section_scores["sec3_hr_score"]             * SW["sec3_hr_score"] +
        (1 - section_scores["sec4_dependency_risk"]) * SW["sec4_dependency_risk"] +
        section_scores["sec5_eco_stability"]        * SW["sec5_eco_stability"] +
        section_scores["sec6_health_score"]         * SW["sec6_health_score"]
    )
    section_scores["overall_wellbeing"] = round(max(0.0, min(1.0, overall)), 4)

    # 6. Backward-compatible aliases used by WHO domain scoring and downstream models
    enc["checkup_enc"] = enc["has_medical_checkup"]
    enc["sec5_income_norm"] = section_scores["sec5_income_norm"]
    enc["sec5_real_asset_score"] = section_scores["sec5_real_asset_score"]
    enc["sec5_movable_asset_score"] = section_scores["sec5_movable_asset_score"]
    enc["sec5_income_source_score"] = section_scores["sec5_income_source_score"]
    enc["sec5_eco_stability"] = section_scores["sec5_eco_stability"]
    enc["sec4_household_risk"] = section_scores["sec4_household_risk"]
    enc["sec4_lives_alone"] = section_scores["sec4_lives_alone"]
    enc["sec4_dependency_risk"] = section_scores["sec4_dependency_risk"]
    enc["sec3_education_norm"] = section_scores["sec3_education_norm"]
    enc["sec3_skill_score"] = section_scores["sec3_skill_score"]
    enc["sec3_community_score"] = section_scores["sec3_community_score"]
    enc["sec3_hr_score"] = section_scores["sec3_hr_score"]
    enc["sec2_family_support"] = section_scores["sec2_family_support"]
    enc["sec2_family_size_norm"] = section_scores["sec2_family_size_norm"]
    enc["sec1_age_risk"] = section_scores["sec1_age_risk"]
    enc["sec6_phy_score"] = section_scores["sec6_phy_score"]
    enc["sec6_psy_score"] = section_scores["sec6_psy_score"]
    enc["sec6_func_score"] = section_scores["sec6_func_score"]
    enc["sec6_health_score"] = section_scores["sec6_health_score"]
    enc["overall_wellbeing"] = section_scores["overall_wellbeing"]

    # 7. WHO Domain Scores
    # Align WHO domain composition with notebook training pipeline.
    who_domain_scores = {
        "ic_score": _domain_avg(enc, [
            "phy_energy", "phy_pain_r", "phy_health_limit_r",
            "phy_mobility_outside", "phy_mobility_indoor",
            "psych_happiness", "psych_peace", "psych_lonely_r", "psych_confidence",
            "func_independence", "func_autonomy", "func_control",
        ]),
        "env_score": _domain_avg(enc, [
            # Only QoL items on the same 1-5 scale
            "env_income_limit_r", "env_fin_household", "env_fin_medical", "env_fin_personal",
            "env_safe_home", "env_safe_neighborhood", "env_home_comfort", "env_service_access",
            "soc_social_support", "soc_close_friend", "soc_participation", "soc_opportunity", "soc_respect",
        ]),
        "func_score": _domain_avg(enc, [
            # Only QoL items on the same 1-5 scale
            "func_independence", "func_autonomy", "func_control",
            "phy_mobility_outside", "phy_mobility_indoor",
            "soc_participation", "soc_opportunity",
        ]),
        "qol_score": _domain_avg(enc, [
            "qol_enjoy_life", "qol_life_satisfaction",
            "qol_future_outlook", "qol_meaningfulness",
            "spi_belief_comfort", "spi_belief_practice",
        ]),
    }

    enc["ic_score"] = who_domain_scores["ic_score"]
    enc["env_score"] = who_domain_scores["env_score"]
    enc["func_score"] = who_domain_scores["func_score"]
    enc["qol_score"] = who_domain_scores["qol_score"]

    # 8. Rule-based risk components
    rule_scores = _compute_rule_based_risk(raw, enc, section_scores, W)
    for key, value in rule_scores.items():
        enc[key] = value

    # 9. Feature vector
    feature_keys = [
        "age", "education_enc", "income_enc", "gender_enc", "marital_enc",
        "num_children", "num_working_children", "household_size",
        "child_support_enc", "spouse_working_enc",
        "income_source_count", "income_source_score", "real_asset_score",
        "movable_asset_score", "living_with_count", "household_risk_score",
        "community_service_count", "is_association_member", "has_pension",
        "lives_alone", "health_concern_count", "has_medical_checkup",
    ] + QOL_FEATURE_COLS

    # 10. Cluster vector
    # feature_list.json = 30 final clustering features (FINAL_CLUSTER_FEATURES from notebook)
    # The scaler is trained on all VIF features (scaler.feature_names_in_).
    # Notebook flow: scale the full VIF feature vector, then select the 30 final columns.
    feature_list_names = _load_json_if_exists("feature_list.json")
    vif_features_for_cluster = _load_json_if_exists("vif_retained_features.json")

    # 11. Scale features
    scaler = _load_pickle_if_exists("scaler.pkl")
    if scaler is not None and hasattr(scaler, "feature_names_in_"):
        # Use scaler's own feature list to build the input vector
        scaler_input_names = list(scaler.feature_names_in_)
        scaler_vector = [float(enc.get(k, 0.0)) for k in scaler_input_names]
        try:
            scaled_full = scaler.transform([scaler_vector])[0]
        except Exception:
            arr = np.array(scaler_vector, dtype=np.float64)
            std = float(arr.std()) or 1.0
            scaled_full = (arr - arr.mean()) / std
        # Select the 30 final clustering columns from the scaled output
        if isinstance(feature_list_names, list) and len(feature_list_names) > 0:
            cluster_feature_names = feature_list_names
            scaler_feat_idx = {f: i for i, f in enumerate(scaler_input_names)}
            scaled = [float(scaled_full[scaler_feat_idx[f]]) if f in scaler_feat_idx else 0.0
                      for f in cluster_feature_names]
        else:
            cluster_feature_names = scaler_input_names
            scaled = scaled_full.tolist()
    else:
        # Fallback: no scaler or no feature_names_in_
        cluster_feature_names = (
            feature_list_names
            or vif_features_for_cluster
            or feature_keys
        )
        cluster_vector = [float(enc.get(k, 0.0)) for k in cluster_feature_names]
        if scaler is not None:
            try:
                scaled = scaler.transform([cluster_vector])[0].tolist()
            except Exception:
                arr = np.array(cluster_vector, dtype=np.float64)
                std = float(arr.std()) or 1.0
                scaled = ((arr - arr.mean()) / std).tolist()
        else:
            arr = np.array(cluster_vector, dtype=np.float64)
            std = float(arr.std()) or 1.0
            scaled = ((arr - arr.mean()) / std).tolist()

    # 12. UMAP reduction
    # Skipped in batch mode (OSCA_BATCH_MODE=1) because infer() recalculates
    # reduced_features from scratch and ignores this value anyway. Skipping
    # avoids repeated numba JIT overhead for each senior in a batch loop.
    if not os.environ.get("OSCA_BATCH_MODE"):
        reducer = _load_pickle_if_exists("umap_nd.pkl") or _load_pickle_if_exists("umap_reducer.pkl")
        if reducer is not None:
            try:
                reduced = reducer.transform([scaled])[0].tolist()
            except Exception:
                reduced = scaled[:10]
        else:
            reduced = scaled[:10]
    else:
        reduced = scaled[:10]

    return {
        "status": "success",
        "identity": {
            "first_name": _as_text(raw.get("first_name")),
            "last_name": _as_text(raw.get("last_name")),
            "barangay": _as_text(raw.get("barangay")),
            "age": age,
        },
        "raw_context": {
            "medical_concern": raw.get("medical_concern", ""),
            "dental_concern": raw.get("dental_concern", ""),
            "optical_concern": raw.get("optical_concern", ""),
            "hearing_concern": raw.get("hearing_concern", ""),
            "social_emotional_concern": raw.get("social_emotional_concern", ""),
            "healthcare_difficulty": raw.get("healthcare_difficulty", ""),
            "household_condition": raw.get("household_condition", ""),
        },
        "feature_map": {
            k: float(v) if isinstance(v, (int, float, np.integer, np.floating)) else v
            for k, v in enc.items()
        },
        "feature_names": feature_keys,
        "scaled_features": scaled,
        "reduced_features": reduced,
        "cluster_feature_names": cluster_feature_names,
        "vif_feature_names": vif_features_for_cluster if isinstance(vif_features_for_cluster, list) else None,
        "section_scores": section_scores,
        "who_domain_scores": who_domain_scores,
        "rule_scores": rule_scores,
    }


# ── Flask API ─────────────────────────────────────────────────────────────────
@app.route("/health", methods=["GET"])
def health():
    return jsonify({"status": "ok", "service": "osca-preprocessor"})


@app.route("/batch_preprocess", methods=["POST"])
def batch_preprocess_endpoint():
    try:
        batch = request.get_json(force=True)
        if not isinstance(batch, list):
            return jsonify({"status": "error", "message": "Expected JSON array"}), 400

        results = []
        for idx, item in enumerate(batch):
            if not isinstance(item, dict):
                results.append({"status": "error", "message": f"Item {idx} is not an object"})
                continue
            try:
                results.append(preprocess(item))
            except Exception as exc:
                logger.exception("Batch preprocess error at index %d", idx)
                results.append({"status": "error", "message": str(exc)})

        return jsonify({"status": "success", "count": len(results), "results": results})
    except Exception as exc:
        logger.exception("Batch preprocessing error")
        return jsonify({"status": "error", "message": str(exc)}), 500


@app.route("/preprocess", methods=["POST"])
def preprocess_endpoint():
    try:
        raw = request.get_json(force=True)
        if not raw or not isinstance(raw, dict):
            return jsonify({"status": "error", "message": "Expected JSON object payload"}), 400

        result = preprocess(raw)
        return jsonify(result)
    except Exception as exc:
        logger.exception("Preprocessing error")
        return jsonify({"status": "error", "message": str(exc)}), 500


if __name__ == "__main__":
    port = int(os.environ.get("PREPROCESS_PORT", 5001))
    logger.info("Starting OSCA Preprocessing Service on port %s", port)
    app.run(host="0.0.0.0", port=port, debug=False)
