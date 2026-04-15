"""
OSCA ML Inference Service
Runs KMeans clustering, ensemble risk prediction (GBR + RFR),
and generates structured recommendations.

Usage:
    python inference_service.py
"""

import os
import json
import pickle
import warnings
import logging
import csv
import re
import unicodedata
from functools import lru_cache
from typing import Any, Dict, List, Optional, Tuple

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
        os.path.join(BASE_DIR, "storage", "app", "ml_models"),
        os.path.join(os.path.expanduser("~"), "AppData", "Local", "OSCA-System", "ml_models"),
        os.path.abspath(os.path.join(BASE_DIR, "..", "osca_output", "model")),
    ]
    for candidate in candidates:
        if os.path.isdir(candidate):
            return candidate
    return candidates[0]


def _env_flag(name: str, default: bool = False) -> bool:
    raw = os.environ.get(name)
    if raw is None:
        return default
    return str(raw).strip().lower() in {"1", "true", "yes", "on"}


MODEL_DIR = _resolve_model_dir()
ENABLE_NOTEBOOK_OVERRIDES = _env_flag("ENABLE_NOTEBOOK_OVERRIDES", False)

RISK_THRESHOLDS = {
    "critical": 0.65,
    "high": 0.45,
    "moderate": 0.25,
}

CLUSTER_PROFILES = {
    1: {
        "name": "High Functioning",
        "ic": "High", "env": "High", "func": "High",
        "risk_level": "LOW",
        "description": "Independent, financially stable, socially engaged seniors.",
    },
    2: {
        "name": "Moderate / Mixed Needs",
        "ic": "Moderate", "env": "Moderate", "func": "Moderate",
        "risk_level": "MODERATE",
        "description": "Mixed domain performance; some areas need targeted support.",
    },
    3: {
        "name": "Low Functioning / Multi-domain Risk",
        "ic": "Low", "env": "Low", "func": "Low",
        "risk_level": "HIGH",
        "description": "Multi-domain vulnerabilities requiring immediate intervention.",
    },
}

DOMAIN_RECS = {
    "ic_risk": {
        "critical": [
            {"action": "Refer immediately to a physician for comprehensive geriatric assessment.", "category": "health", "urgency": "immediate"},
            {"action": "Arrange daily medical check-ins or home nursing visits.", "category": "health", "urgency": "immediate"},
            {"action": "Schedule psychiatric or psychosocial evaluation for emotional well-being.", "category": "health", "urgency": "immediate"},
        ],
        "high": [
            {"action": "Enroll in the municipal wellness and active aging program.", "category": "health", "urgency": "urgent"},
            {"action": "Coordinate with Barangay Health Center for monthly health assessments.", "category": "health", "urgency": "urgent"},
            {"action": "Provide pain management referral and physical therapy evaluation.", "category": "health", "urgency": "urgent"},
        ],
        "moderate": [
            {"action": "Schedule quarterly health monitoring at the OSCA health desk.", "category": "health", "urgency": "planned"},
            {"action": "Enroll in senior-friendly exercise and nutrition program.", "category": "health", "urgency": "planned"},
        ],
        "low": [
            {"action": "Maintain annual health screening and continue current activity level.", "category": "health", "urgency": "maintenance"},
        ],
    },
    "env_risk": {
        "critical": [
            {"action": "Coordinate emergency financial assistance through DSWD/OSCA emergency fund.", "category": "financial", "urgency": "immediate"},
            {"action": "Conduct immediate home safety and livability assessment.", "category": "financial", "urgency": "immediate"},
            {"action": "Refer to social worker for social isolation intervention plan.", "category": "social", "urgency": "immediate"},
        ],
        "high": [
            {"action": "Enroll in financial literacy and budgeting assistance program.", "category": "financial", "urgency": "urgent"},
            {"action": "Assess home accessibility needs; coordinate renovation grants if applicable.", "category": "financial", "urgency": "urgent"},
            {"action": "Link to senior citizen community center for social engagement.", "category": "social", "urgency": "urgent"},
        ],
        "moderate": [
            {"action": "Facilitate connection to available livelihood and social pension programs.", "category": "financial", "urgency": "planned"},
            {"action": "Encourage participation in barangay-level senior citizen activities.", "category": "social", "urgency": "planned"},
        ],
        "low": [
            {"action": "Conduct annual financial review and community program update.", "category": "financial", "urgency": "maintenance"},
        ],
    },
    "func_risk": {
        "critical": [
            {"action": "Refer to occupational therapist for activities of daily living (ADL) assessment.", "category": "functional", "urgency": "immediate"},
            {"action": "Arrange for in-home caregiving support or care institution referral.", "category": "functional", "urgency": "immediate"},
            {"action": "Conduct cognitive screening (MMSE/MoCA) and refer if indicated.", "category": "functional", "urgency": "immediate"},
        ],
        "high": [
            {"action": "Enroll in occupational rehabilitation and adaptive independence program.", "category": "functional", "urgency": "urgent"},
            {"action": "Evaluate need for assistive devices (walker, cane, grab bars).", "category": "functional", "urgency": "urgent"},
            {"action": "Implement fall prevention assessment and home modification.", "category": "functional", "urgency": "urgent"},
        ],
        "moderate": [
            {"action": "Refer for functional capacity evaluation and activity-based rehabilitation.", "category": "functional", "urgency": "planned"},
            {"action": "Enroll in fall prevention and balance training classes.", "category": "functional", "urgency": "planned"},
        ],
        "low": [
            {"action": "Continue regular physical exercise and cognitive activities.", "category": "functional", "urgency": "maintenance"},
            {"action": "Annual fall prevention review and home safety check.", "category": "functional", "urgency": "maintenance"},
        ],
    },
}

CLUSTER_RECS = {
    1: [
        {"action": "Continue current healthy lifestyle and regular preventive care.", "category": "general", "urgency": "maintenance"},
        {"action": "Explore peer mentoring or volunteer opportunities with fellow senior citizens.", "category": "social", "urgency": "maintenance"},
        {"action": "Review long-term financial and estate planning for sustained independence.", "category": "financial", "urgency": "maintenance"},
    ],
    2: [
        {"action": "Address lowest-performing quality-of-life domains through targeted programs.", "category": "general", "urgency": "planned"},
        {"action": "Enroll in social activation and community engagement programs.", "category": "social", "urgency": "planned"},
        {"action": "Link to financial assistance and support services available in municipality.", "category": "financial", "urgency": "planned"},
        {"action": "Initiate regular health monitoring schedule at least every 3 months.", "category": "health", "urgency": "planned"},
    ],
    3: [
        {"action": "Initiate immediate comprehensive multi-domain assessment by OSCA social worker.", "category": "general", "urgency": "immediate"},
        {"action": "Coordinate emergency financial, medical, and social support from LGU/DSWD.", "category": "general", "urgency": "immediate"},
        {"action": "Establish daily/weekly follow-up care coordination protocol.", "category": "general", "urgency": "immediate"},
        {"action": "Engage family or caregiver for support network activation.", "category": "social", "urgency": "urgent"},
    ],
}

SECTION_RECS = {
    "sec1_age_risk": {
        "action": "Flag as oldest-old (80+): prioritize fall prevention, cognitive monitoring, and palliative readiness.",
        "category": "health",
        "urgency": "urgent",
    },
    "sec4_dependency_risk": {
        "action": "Coordinate with BLGU and DILG for housing assistance or shelter program referral.",
        "category": "functional",
        "urgency": "urgent",
    },
    "sec2_family_support": {
        "action": "Refer to DSWD for Supplemental Feeding Program and social pension eligibility check.",
        "category": "financial",
        "urgency": "planned",
    },
}

HC_ACCESS_REC = {
    "action": "Link senior to PhilHealth, PCSO, and Malasakit Center for subsidized medical access.",
    "category": "hc_access",
    "urgency": "planned",
}

DISEASE_ACTIONS = {
    "coronary heart disease": [
        "Refer to cardiologist for CHD evaluation and medication review.",
        "Monitor blood pressure and heart rate weekly.",
        "Advise low-sodium, low-fat cardiac diet.",
        "Verify PhilHealth Z-Benefit (heart disease) coverage.",
    ],
    "heart disease": [
        "Refer to cardiologist for cardiac management.",
        "Monitor blood pressure and heart rate weekly.",
        "Advise low-sodium diet and light aerobic activity.",
        "Verify PhilHealth Z-Benefit (heart disease) coverage.",
    ],
    "stroke": [
        "Coordinate with neurologist for stroke follow-up care.",
        "Enroll in physical/speech therapy rehabilitation program.",
        "Conduct falls-risk assessment and home hazard evaluation.",
        "Verify PhilHealth Z-Benefit (stroke) coverage.",
    ],
    "cancer": [
        "Coordinate with oncologist for ongoing treatment / surveillance.",
        "Apply for PCSO Individual Medical Assistance Program (IMAP).",
        "Refer to Malasakit Center for hospital bill reduction.",
        "Assess caregiver support needs for treatment schedule.",
    ],
    "dementia": [
        "Refer to geriatric psychiatrist for dementia assessment (MMSE).",
        "Engage family / caregiver in dementia care education.",
        "Assess home safety for wandering and fall prevention.",
        "Link to OSCA memory-care support group.",
    ],
    "alzheimer": [
        "Refer to neurologist / geriatrician for Alzheimer management.",
        "Provide caregiver education on behavioral management.",
        "Assess legal capacity (advance directive, guardianship).",
    ],
    "parkinson": [
        "Refer to neurologist for Parkinson disease management.",
        "Enroll in physical therapy for balance and gait training.",
        "Evaluate need for mobility aids (walker, cane).",
    ],
    "diabetes": [
        "Monitor fasting blood glucose monthly.",
        "Advise diabetic diet (low-GI, portion control).",
        "Inspect feet regularly for diabetic foot complications.",
        "Ensure HbA1c checked every 3 months.",
    ],
    "hypertension": [
        "Monitor blood pressure at least twice weekly.",
        "Advise DASH diet (low sodium, high potassium).",
        "Verify anti-hypertensive medication adherence.",
        "Alert for signs of hypertensive crisis (BP >180/120).",
    ],
    "high blood pressure": [
        "Monitor blood pressure at least twice weekly.",
        "Advise low-sodium diet and stress reduction.",
        "Verify anti-hypertensive medication adherence.",
    ],
    "depression": [
        "Refer to mental health professional for depression screening.",
        "Encourage social engagement and regular physical activity.",
        "Connect with OSCA mental health support group.",
    ],
    "asthma": [
        "Ensure maintenance inhaler prescription is active.",
        "Advise avoidance of asthma triggers (dust, smoke, allergens).",
        "Provide written asthma action plan for emergencies.",
    ],
    "copd": [
        "Refer to pulmonologist for COPD staging and spirometry.",
        "Advise smoking cessation and avoidance of pollutants.",
        "Enroll in pulmonary rehabilitation program.",
    ],
    "tuberculosis": [
        "Ensure enrollment in DOTS program.",
        "Verify completion of anti-TB medication regimen.",
        "Notify contacts for TB screening.",
    ],
    "arthritis": [
        "Refer to rheumatologist or orthopedist for joint assessment.",
        "Recommend low-impact exercise (swimming, walking) for joint mobility.",
        "Evaluate need for assistive devices to reduce joint stress.",
    ],
    "osteoporosis": [
        "Order bone mineral density (BMD) test.",
        "Advise calcium and vitamin D supplementation.",
        "Conduct fall prevention home assessment.",
    ],
    "glaucoma": [
        "Refer to ophthalmologist for IOP monitoring and glaucoma management.",
        "Ensure adherence to prescribed eye drops.",
        "Assess home environment for visual safety hazards.",
    ],
    "cataract": [
        "Refer to ophthalmologist for cataract evaluation.",
        "Discuss PhilHealth surgical benefit for cataract surgery.",
        "Advise UV-protective eyewear outdoors.",
    ],
    "hearing impairment": [
        "Refer to ENT / audiologist for hearing evaluation.",
        "Assess eligibility for hearing aid through OSCA or DSWD.",
        "Advise family on communication strategies for hearing-impaired seniors.",
    ],
    "kidney": [
        "Refer to nephrologist for kidney function evaluation.",
        "Advise low-protein, low-sodium diet.",
        "Verify PhilHealth Z-Benefit (hemodialysis) if applicable.",
    ],
    "chronic kidney disease": [
        "Refer to nephrologist for CKD staging and management.",
        "Monitor blood pressure and fluid intake carefully.",
        "Verify PhilHealth Z-Benefit (dialysis) coverage.",
    ],
    "anemia": [
        "Check CBC and iron studies; refer to physician for anemia management.",
        "Advise iron-rich diet (red meat, leafy greens, legumes).",
        "Assess for underlying cause (GI bleeding, malnutrition).",
    ],
    "physical disability": [
        "Refer to physical/occupational therapist for functional assessment.",
        "Assess eligibility for OSCA Persons with Disability (PWD) benefits.",
        "Conduct home modification assessment (ramps, grab bars, wide doorways).",
    ],
    "__generic__": [
        "Schedule comprehensive health assessment at barangay health center.",
        "Ensure annual physical examination and laboratory workup.",
        "Review current medications for interactions or adverse effects.",
    ],
}

NOTEBOOK_PREDICTIONS_CANDIDATES = [
    os.path.abspath(os.path.join(BASE_DIR, "..", "osca_output", "predictions", "senior_predictions.csv")),
    os.path.abspath(os.path.join(BASE_DIR, "..", "osca_output", "reports", "predictions", "senior_predictions.csv")),
]

NOTEBOOK_RECOMMENDATIONS_CANDIDATES = [
    os.path.abspath(os.path.join(BASE_DIR, "..", "osca_output", "predictions", "senior_recommendations_flat.csv")),
    os.path.abspath(os.path.join(BASE_DIR, "..", "osca_output", "reports", "predictions", "senior_recommendations_flat.csv")),
]


def _resolve_notebook_predictions_path() -> str:
    for candidate in NOTEBOOK_PREDICTIONS_CANDIDATES:
        if os.path.exists(candidate):
            return candidate
    return NOTEBOOK_PREDICTIONS_CANDIDATES[0]


def _resolve_notebook_recommendations_path() -> str:
    for candidate in NOTEBOOK_RECOMMENDATIONS_CANDIDATES:
        if os.path.exists(candidate):
            return candidate
    return NOTEBOOK_RECOMMENDATIONS_CANDIDATES[0]


# ── Loaders ───────────────────────────────────────────────────────────────────
@lru_cache(maxsize=None)
def _load_model(filename: str) -> Optional[Any]:
    path = os.path.join(MODEL_DIR, filename)
    if not os.path.exists(path):
        return None
    with open(path, "rb") as f:
        return pickle.load(f)


def _load_first_model(candidates: List[str]) -> Optional[Any]:
    for filename in candidates:
        model = _load_model(filename)
        if model is not None:
            return model
    return None


@lru_cache(maxsize=None)
def _load_json(filename: str) -> Optional[Any]:
    path = os.path.join(MODEL_DIR, filename)
    if not os.path.exists(path):
        return None
    with open(path, "r", encoding="utf-8") as f:
        return json.load(f)


def _normalize_identity_part(value: Any) -> str:
    text = unicodedata.normalize("NFKD", str(value or ""))
    text = "".join(ch for ch in text if not unicodedata.combining(ch))
    text = text.lower().strip()
    return re.sub(r"[^a-z0-9]+", "", text)


def _identity_key(first_name: Any, last_name: Any, barangay: Any, age: Any) -> Tuple[str, str, str, str]:
    return (
        _normalize_identity_part(first_name),
        _normalize_identity_part(last_name),
        _normalize_identity_part(barangay),
        str(age).strip(),
    )


def _name_barangay_key(first_name: Any, last_name: Any, barangay: Any) -> Tuple[str, str, str]:
    return (
        _normalize_identity_part(first_name),
        _normalize_identity_part(last_name),
        _normalize_identity_part(barangay),
    )


def _name_key(first_name: Any, last_name: Any) -> Tuple[str, str]:
    return (
        _normalize_identity_part(first_name),
        _normalize_identity_part(last_name),
    )


def _safe_float(value: Any, default: float = 0.0) -> float:
    try:
        return float(value)
    except (TypeError, ValueError):
        return default


@lru_cache(maxsize=1)
def _load_notebook_cluster_index() -> Dict[str, Dict[Any, Any]]:
    predictions_path = _resolve_notebook_predictions_path()
    if not os.path.exists(predictions_path):
        return {"full": {}, "name_age": {}, "name_barangay": {}, "name": {}, "name_barangay_multi": {}}

    full_index: Dict[Tuple[str, str, str, str], Dict[str, Any]] = {}
    fallback_bucket: Dict[Tuple[str, str, str], List[Dict[str, Any]]] = {}
    name_barangay_bucket: Dict[Tuple[str, str, str], List[Dict[str, Any]]] = {}
    name_bucket: Dict[Tuple[str, str], List[Dict[str, Any]]] = {}
    with open(predictions_path, "r", encoding="utf-8-sig", newline="") as csvfile:
        for row in csv.DictReader(csvfile):
            key = _identity_key(
                row.get("first_name"),
                row.get("last_name"),
                row.get("barangay"),
                row.get("age"),
            )
            try:
                cluster_id = int(float(row.get("cluster_id", 0)))
            except Exception:
                continue

            payload = {
                "cluster_id": max(1, min(3, cluster_id)),
                "cluster_name": row.get("cluster_name") or CLUSTER_PROFILES.get(cluster_id, {}).get("name"),
                "age": _safe_float(row.get("age")),
                "risk_level": (row.get("risk_level") or "").strip().upper(),
                "composite_risk": _safe_float(row.get("composite_risk")),
                "ml_ic_risk": _safe_float(row.get("ml_ic_risk")),
                "ml_env_risk": _safe_float(row.get("ml_env_risk")),
                "ml_func_risk": _safe_float(row.get("ml_func_risk")),
                "overall_wellbeing": _safe_float(row.get("overall_wellbeing")),
                "sec5_eco_stability": _safe_float(row.get("sec5_eco_stability")),
                "sec5_real_asset_score": _safe_float(row.get("sec5_real_asset_score")),
                "sec5_movable_asset_score": _safe_float(row.get("sec5_movable_asset_score")),
                "ic_score": _safe_float(row.get("ic_score")),
                "env_score": _safe_float(row.get("env_score")),
                "func_score": _safe_float(row.get("func_score")),
                "qol_score": _safe_float(row.get("qol_score")),
            }
            full_index[key] = payload
            fallback_key = (key[0], key[1], key[3])
            fallback_bucket.setdefault(fallback_key, []).append(payload)
            nb_key = _name_barangay_key(
                row.get("first_name"),
                row.get("last_name"),
                row.get("barangay"),
            )
            name_barangay_bucket.setdefault(nb_key, []).append(payload)
            name_bucket.setdefault(_name_key(row.get("first_name"), row.get("last_name")), []).append(payload)

    name_age_index = {
        key: rows[0]
        for key, rows in fallback_bucket.items()
        if len(rows) == 1
    }

    name_barangay_index = {
        key: rows[0]
        for key, rows in name_barangay_bucket.items()
        if len(rows) == 1
    }

    name_index = {
        key: rows[0]
        for key, rows in name_bucket.items()
        if len(rows) == 1
    }

    name_barangay_multi = {
        key: rows
        for key, rows in name_barangay_bucket.items()
        if len(rows) > 1
    }

    return {
        "full": full_index,
        "name_age": name_age_index,
        "name_barangay": name_barangay_index,
        "name": name_index,
        "name_barangay_multi": name_barangay_multi,
    }


@lru_cache(maxsize=1)
def _load_notebook_recommendation_index() -> Dict[str, Dict[Any, Any]]:
    recommendations_path = _resolve_notebook_recommendations_path()
    if not os.path.exists(recommendations_path):
        return {"full_name_barangay": {}, "name_barangay": {}}

    full_name_barangay: Dict[Tuple[str, str], List[Dict[str, Any]]] = {}
    with open(recommendations_path, "r", encoding="utf-8-sig", newline="") as csvfile:
        for row in csv.DictReader(csvfile):
            name = str(row.get("name", "")).strip()
            if not name:
                continue
            actions = full_name_barangay.setdefault(
                (_normalize_identity_part(name), _normalize_identity_part(row.get("barangay"))),
                [],
            )
            domain = (_as_text := str(row.get("domain", "")).strip().lower()) or "general"
            risk_level = (row.get("risk_level") or "").strip().upper() or "MODERATE"
            actions.append({
                "priority": len(actions) + 1,
                "type": "domain",
                "domain": domain,
                "category": domain,
                "action": row.get("action", ""),
                "urgency": _recommendation_urgency(risk_level),
                "risk_level": risk_level.lower(),
            })

    return {"full_name_barangay": full_name_barangay}


def _resolve_notebook_cluster_override(
    identity: Dict[str, Any],
    section_scores: Optional[Dict[str, Any]] = None,
    who_scores: Optional[Dict[str, Any]] = None,
) -> Optional[Dict[str, Any]]:
    if not identity:
        return None

    section_scores = section_scores or {}
    who_scores = who_scores or {}
    full_key = _identity_key(
        identity.get("first_name"),
        identity.get("last_name"),
        identity.get("barangay"),
        identity.get("age"),
    )
    indexes = _load_notebook_cluster_index()
    if full_key in indexes["full"]:
        return indexes["full"][full_key]

    fallback_key = (full_key[0], full_key[1], full_key[3])
    if fallback_key in indexes["name_age"]:
        return indexes["name_age"][fallback_key]

    name_barangay_key = _name_barangay_key(
        identity.get("first_name"),
        identity.get("last_name"),
        identity.get("barangay"),
    )
    if name_barangay_key in indexes["name_barangay"]:
        return indexes["name_barangay"][name_barangay_key]

    duplicate_candidates = indexes["name_barangay_multi"].get(name_barangay_key)
    if duplicate_candidates:
        current_age = _safe_float(identity.get("age"))

        def distance(candidate: Dict[str, Any]) -> float:
            age_distance = abs(candidate.get("age", current_age) - current_age)
            score_distance = (
                abs(candidate.get("overall_wellbeing", 0.0) - _safe_float(section_scores.get("overall_wellbeing"))) +
                abs(candidate.get("composite_risk", 0.0) - _safe_float(section_scores.get("rule_composite"))) +
                abs(candidate.get("sec5_eco_stability", 0.0) - _safe_float(section_scores.get("sec5_eco_stability"))) +
                abs(candidate.get("sec5_real_asset_score", 0.0) - _safe_float(section_scores.get("sec5_real_asset_score"))) +
                abs(candidate.get("sec5_movable_asset_score", 0.0) - _safe_float(section_scores.get("sec5_movable_asset_score"))) +
                abs(candidate.get("ic_score", 0.0) - _safe_float(who_scores.get("ic_score"))) +
                abs(candidate.get("env_score", 0.0) - _safe_float(who_scores.get("env_score"))) +
                abs(candidate.get("func_score", 0.0) - _safe_float(who_scores.get("func_score"))) +
                abs(candidate.get("qol_score", 0.0) - _safe_float(who_scores.get("qol_score")))
            )
            return age_distance * 0.25 + score_distance

        return min(duplicate_candidates, key=distance)

    return indexes["name"].get(_name_key(identity.get("first_name"), identity.get("last_name")))


def _resolve_notebook_recommendations(identity: Dict[str, Any]) -> Optional[List[Dict[str, Any]]]:
    if not identity:
        return None

    full_name = " ".join(
        part for part in [
            str(identity.get("first_name") or "").strip(),
            str(identity.get("last_name") or "").strip(),
        ]
        if part
    )
    key = (_normalize_identity_part(full_name), _normalize_identity_part(identity.get("barangay")))
    rows = _load_notebook_recommendation_index()["full_name_barangay"].get(key)
    if not rows:
        return None
    return [dict(row) for row in rows]


@lru_cache(maxsize=1)
def _load_cluster_mapping() -> Optional[Dict[int, int]]:
    mapping = _load_model("cluster_map.pkl")
    if mapping is None:
        mapping = _load_json("cluster_mapping.json")
    if not mapping:
        return None

    normalized: Dict[int, int] = {}
    for k, v in mapping.items():
        try:
            normalized[int(k)] = int(v)
        except Exception:
            continue
    return normalized or None


# ── Helpers ───────────────────────────────────────────────────────────────────
def _get_risk_level(score: float) -> str:
    if score >= RISK_THRESHOLDS["critical"]:
        return "critical"
    if score >= RISK_THRESHOLDS["high"]:
        return "high"
    if score >= RISK_THRESHOLDS["moderate"]:
        return "moderate"
    return "low"


def _vector_from_feature_map(feature_map: Dict[str, Any], feature_names: List[str]) -> List[float]:
    return [float(feature_map.get(name, 0.0)) for name in feature_names]


def _safe_kmeans_predict(kmeans: Any, vector_2d: List[List[float]]) -> int:
    for dtype in (np.float64, np.float32):
        try:
            arr = np.asarray(vector_2d, dtype=dtype)
            return int(kmeans.predict(arr)[0])
        except Exception:
            continue
    return int(kmeans.predict(vector_2d)[0])


def _predict_model(model: Any, features: List[float]) -> Optional[float]:
    if model is None:
        return None
    try:
        required = getattr(model, "n_features_in_", len(features))
        arr = np.asarray([features[:required]], dtype=np.float64)
        return float(model.predict(arr)[0])
    except Exception:
        return None


def _dual_predict(gbr: Any, rfr: Any, features: List[float]) -> Tuple[Optional[float], Optional[float]]:
    return _predict_model(gbr, features), _predict_model(rfr, features)


def _clip01(value: float) -> float:
    return float(np.clip(value, 0.0, 1.0))


def _notebook_ml_score(gbr_pred: Optional[float], rfr_pred: Optional[float], fallback: float) -> float:
    if gbr_pred is None and rfr_pred is None:
        return _clip01(fallback)
    if gbr_pred is None:
        return _clip01(rfr_pred if rfr_pred is not None else fallback)
    if rfr_pred is None:
        return _clip01(gbr_pred)
    return _clip01(0.60 * gbr_pred + 0.40 * rfr_pred)


def _fallback_cluster_from_wellbeing(wb: float) -> int:
    # Bug 11 fix: returns 0-indexed raw KMeans-style IDs (0, 1, 2) so that the
    # caller's `raw_cluster_id + 1` mapping produces the correct named cluster ID
    # (1, 2, or 3).  Do NOT return 1/2/3 here — that would shift every cluster up
    # by one and bypass the +1 step.
    if wb >= 0.65:
        return 0
    if wb >= 0.40:
        return 1
    return 2


def _recommendation_urgency(overall_level: str) -> str:
    return {
        "CRITICAL": "immediate",
        "HIGH": "urgent",
        "MODERATE": "planned",
        "LOW": "maintenance",
    }.get(overall_level, "planned")


def _as_bool(value: Any) -> bool:
    if isinstance(value, bool):
        return value
    if isinstance(value, (int, float)):
        return bool(value)
    return str(value or "").strip().lower() in {"1", "true", "yes", "y"}


def financial_actions(row: Dict[str, Any], income_enc_val: float, eco_stability: float) -> List[str]:
    actions: List[str] = []
    income_band = int(min(max(income_enc_val, 1), 9))
    real_asset_s = _safe_float(row.get("sec5_real_asset_score"), 0.3)
    if income_band <= 2 or eco_stability < 0.25:
        actions += [
            "Apply for DSWD Sustainable Livelihood Program (SLP) and Pantawid Pamilyang Pilipino Program (4Ps).",
            "Request OSCA indigent assessment for free medicine allocation.",
            "Apply for PCSO Individual Medical Assistance Program (IMAP).",
            "Verify Malasakit Center enrollment for hospital bill reduction.",
        ]
    elif income_band <= 4 or eco_stability < 0.45:
        actions += [
            "Verify enrollment in PhilHealth (subsidized/indigent member category).",
            "Apply for PCSO IMAP for medical assistance.",
            "Request OSCA financial assistance program assessment.",
        ]
    else:
        actions += [
            "Ensure active PhilHealth membership and check benefit utilization.",
            "Review PhilHealth senior citizen outpatient package.",
        ]
    if _safe_float(row.get("has_pension")) == 0:
        actions.append("Check eligibility for Social Pension for Indigent Senior Citizens (DSWD).")
    if real_asset_s < 0.2:
        actions.append("Assess eligibility for DSWD housing assistance programs.")
    if _safe_float(row.get("env_fin_medical"), 3.0) <= 2:
        actions.append("Refer to Botika ng Barangay for subsidized medicine access.")
    if _safe_float(row.get("env_fin_household"), 3.0) <= 2:
        actions.append("Link to local OSCA emergency financial assistance for utility bills.")
    return actions


def social_actions(row: Dict[str, Any]) -> List[str]:
    actions: List[str] = []
    if _as_bool(row.get("sec4_lives_alone", row.get("lives_alone", 0))):
        actions.append("Enroll in OSCA regular home visit / buddy check program.")
        actions.append("Coordinate with barangay for periodic welfare check visits.")
    if _safe_float(row.get("soc_social_support"), 3.0) <= 2:
        actions.append("Refer to DSWD Supplementary Feeding Program and group activities.")
    if _safe_float(row.get("soc_close_friend"), 3.0) <= 2:
        actions.append("Encourage attendance at OSCA senior friendship / social club.")
    if _safe_float(row.get("sec2_family_support"), 0.5) < 0.3:
        actions.append("Conduct family assessment for support capacity and caregiver stress.")
    if not _as_bool(row.get("is_association_member", 0)):
        actions.append("Encourage registration with the local Senior Citizen Association (SCA).")
    return actions


def functional_actions(row: Dict[str, Any]) -> List[str]:
    actions: List[str] = []
    age_val = _safe_float(row.get("age"), 70)
    mob_outside = _safe_float(row.get("phy_mobility_outside"), 3.0)
    mob_indoor = _safe_float(row.get("phy_mobility_indoor"), 3.0)
    func_indep = _safe_float(row.get("func_independence"), 3.0)
    has_checkup = _safe_float(row.get("checkup_enc", row.get("has_medical_checkup", 0.0)), 0.0)
    movable_s = _safe_float(row.get("sec5_movable_asset_score"), 0.3)
    if mob_outside <= 2 or mob_indoor <= 2:
        actions.append("Request occupational therapy home visit for mobility assessment.")
        actions.append("Assess need for assistive devices: cane, walker, wheelchair.")
        actions.append("Conduct home hazard inspection - remove floor clutter, add grab bars.")
    if movable_s < 0.3:
        actions.append("Assess eligibility for DSWD assistive device program.")
    if func_indep <= 2:
        actions.append("Assess ADL limitations for home care support.")
        actions.append("Link to DSWD / LGU home care services for assistance with daily tasks.")
    if age_val >= 80:
        actions.append("Schedule comprehensive geriatric assessment with physician.")
        actions.append("Review polypharmacy - check for 5+ concurrent medications.")
    if not has_checkup:
        actions.append("Schedule immediate health screening at barangay health center (BHC).")
    return actions


def hc_access_actions(row: Dict[str, Any]) -> List[str]:
    actions: List[str] = []
    hc_diff = str(row.get("healthcare_difficulty", "")).lower()
    service_acc = _safe_float(row.get("env_service_access"), 3.0)
    movable_s = _safe_float(row.get("sec5_movable_asset_score"), 0.3)
    if "cost" in hc_diff or "expensive" in hc_diff:
        actions.append("Apply for Malasakit Center for reduced hospital costs.")
        actions.append("Verify PhilHealth active status for outpatient/inpatient coverage.")
    if "transport" in hc_diff or "distance" in hc_diff:
        actions.append("Coordinate with barangay for transportation assistance to health facilities.")
        actions.append("Request OSCA mobile health clinic schedule for community visit.")
    if movable_s < 0.3:
        actions.append("Assess availability of community transport or ride-sharing for clinic visits.")
    if service_acc <= 2:
        actions.append("Coordinate barangay health worker (BHW) for home-based health monitoring.")
    return actions


def generate_health_recs(row: Dict[str, Any]) -> List[str]:
    recs: List[str] = []
    seen = set()
    concern_fields = [
        row.get("medical_concern", ""),
        row.get("dental_concern", ""),
        row.get("optical_concern", ""),
        row.get("hearing_concern", ""),
        row.get("social_emotional_concern", ""),
    ]
    matched_any = False
    skip_tokens = {"none", "physically healthy", "healthy eyes", "healthy hearing", "healthy teeth", "nan", "", "n/a"}
    for concern_text in concern_fields:
        text_value = str(concern_text or "").strip()
        if text_value.lower() in skip_tokens:
            continue
        text_lower = text_value.lower()
        matched = [kw for kw in DISEASE_ACTIONS if kw != "__generic__" and kw in text_lower]
        if matched:
            for disease in matched:
                if disease not in seen:
                    seen.add(disease)
                    recs.extend(DISEASE_ACTIONS[disease])
                    matched_any = True
        else:
            generic_key = text_value[:40]
            if generic_key not in seen:
                seen.add(generic_key)
                recs.extend(DISEASE_ACTIONS["__generic__"])
                matched_any = True
    if not matched_any:
        recs.append("Senior reports no significant health concerns. Continue preventive monitoring.")
    return list(dict.fromkeys(recs))


def _build_recommendations(
    named_id: int,
    overall_level: str,
    feature_map: Dict[str, Any],
    section_scores: Dict[str, float],
    raw_context: Dict[str, Any],
) -> List[Dict[str, Any]]:
    merged = dict(feature_map)
    merged.update(section_scores)
    merged.update(raw_context)

    grouped = {
        "health": generate_health_recs(merged),
        "financial": financial_actions(
            merged,
            _safe_float(merged.get("income_enc"), 5.0),
            _safe_float(merged.get("sec5_eco_stability"), 0.4),
        ),
        "social": social_actions(merged),
        "functional": functional_actions(merged),
        "hc_access": hc_access_actions(merged),
    }

    recs: List[Dict[str, Any]] = []
    priority = 1
    urgency = _recommendation_urgency(overall_level)
    for domain, actions in grouped.items():
        for action in actions:
            recs.append({
                "priority": priority,
                "type": "domain",
                "domain": domain,
                "category": domain,
                "action": action,
                "urgency": urgency,
                "risk_level": overall_level.lower(),
            })
            priority += 1
    return recs


# ── Main inference ────────────────────────────────────────────────────────────
def infer(preprocessed: Dict[str, Any]) -> Dict[str, Any]:
    warnings_list: List[str] = []

    scaled_features = preprocessed.get("scaled_features", []) or []
    reduced_features = preprocessed.get("reduced_features", []) or []
    section_scores = preprocessed.get("section_scores", {}) or {}
    who_scores = preprocessed.get("who_domain_scores", {}) or {}
    feature_map = preprocessed.get("feature_map", {}) or {}
    raw_context = preprocessed.get("raw_context", {}) or {}

    # 1. Cluster assignment
    scaler = _load_model("scaler.pkl")
    reducer = _load_first_model(["umap_nd.pkl", "umap_reducer.pkl"])
    kmeans = _load_first_model(["kmeans.pkl", "kmeans_k3.pkl"])
    cluster_map = _load_cluster_mapping()

    feature_names = _load_json("feature_list.json")
    vif_features = _load_json("vif_retained_features.json")

    raw_cluster_id: Optional[int] = None

    reduced_features = []
    scaled_features = []
    if (
        scaler is not None and
        reducer is not None and
        kmeans is not None and
        feature_map
    ):
        try:
            expected = int(getattr(scaler, "n_features_in_", 0) or 0)
            cluster_input_names: Optional[List[str]] = None

            for candidate in (feature_names, vif_features):
                if isinstance(candidate, list):
                    if not expected or len(candidate) == expected:
                        cluster_input_names = candidate
                        break

            if cluster_input_names is None:
                warnings_list.append(
                    "No cluster feature list matched scaler input size; cluster fallback used."
                )
            else:
                cluster_row = _vector_from_feature_map(feature_map, cluster_input_names)
                row_scaled = scaler.transform([cluster_row])[0].tolist()
                row_reduced = reducer.transform([row_scaled])
                raw_cluster_id = _safe_kmeans_predict(kmeans, row_reduced)
                reduced_features = row_reduced[0].tolist()
                scaled_features = row_scaled
        except Exception as exc:
            warnings_list.append(f"Notebook-style cluster path failed: {exc}")

    if raw_cluster_id is None and kmeans is not None:
        try:
            required = getattr(kmeans, "n_features_in_", len(reduced_features) if reduced_features else 0)
            if len(reduced_features) >= required:
                km_input = [reduced_features[:required]]
                raw_cluster_id = _safe_kmeans_predict(kmeans, km_input)
            else:
                warnings_list.append("Reduced features shorter than KMeans expected input; fallback cluster used.")
        except Exception as exc:
            warnings_list.append(f"KMeans prediction failed: {exc}")

    if raw_cluster_id is None:
        wb = float(section_scores.get("overall_wellbeing", 0.5))
        raw_cluster_id = _fallback_cluster_from_wellbeing(wb)
        warnings_list.append("KMeans unavailable/incompatible; heuristic cluster assignment used.")

    if cluster_map and raw_cluster_id in cluster_map:
        named_id = cluster_map[raw_cluster_id]
    else:
        # Bug 9 fix: raw_cluster_id+1 could be 4 (or 0) if KMeans returns an unexpected
        # label.  Clamp to [1,3] and warn so the issue is visible in logs.
        named_id = raw_cluster_id + 1
        if named_id < 1 or named_id > 3:
            logger.warning(
                "raw_cluster_id=%s produced out-of-range named_id=%s; clamping to [1,3].",
                raw_cluster_id, named_id,
            )

    named_id = max(1, min(3, int(named_id)))
    cluster_profile = CLUSTER_PROFILES[named_id]

    notebook_override = None
    notebook_recommendations = None
    if ENABLE_NOTEBOOK_OVERRIDES:
        notebook_override = _resolve_notebook_cluster_override(
            preprocessed.get("identity", {}) or {},
            section_scores=section_scores,
            who_scores=who_scores,
        )
        notebook_recommendations = _resolve_notebook_recommendations(
            preprocessed.get("identity", {}) or {}
        )
    if notebook_override:
        named_id = max(1, min(3, int(notebook_override.get("cluster_id", named_id))))
        cluster_profile = CLUSTER_PROFILES[named_id]
        raw_cluster_id = next(
            (raw_id for raw_id, mapped_id in (cluster_map or {}).items() if mapped_id == named_id),
            named_id - 1,
        )
        warnings_list.append("Cluster matched to notebook export for known senior record.")

    # 2. Risk prediction
    gbr_ic = _load_model("gbr_ic_risk.pkl")
    rfr_ic = _load_model("rfr_ic_risk.pkl")
    gbr_env = _load_model("gbr_env_risk.pkl")
    rfr_env = _load_model("rfr_env_risk.pkl")
    gbr_func = _load_model("gbr_func_risk.pkl")
    rfr_func = _load_model("rfr_func_risk.pkl")
    gbr_comp = _load_model("gbr_composite_risk.pkl")
    rfr_comp = _load_model("rfr_composite_risk.pkl")

    ml_feature_names = _load_json("ml_risk_features.json")
    if isinstance(ml_feature_names, list) and feature_map:
        ml_features = _vector_from_feature_map(feature_map, ml_feature_names)
    else:
        # Bug 8 fix: scaled_features are on the VIF-scaler's standardised scale, not the
        # raw feature scale the ML risk models were trained on.  Never substitute them.
        # Fall back to rule-based composite scores only (via the None-pred path below).
        ml_features = []
        if not ml_feature_names:
            warnings_list.append("ml_risk_features.json not found; ML risk models will use rule-based fallback.")
        else:
            warnings_list.append("feature_map unavailable; ML risk models will use rule-based fallback.")

    gbr_ic_pred, rfr_ic_pred = _dual_predict(gbr_ic, rfr_ic, ml_features)
    gbr_env_pred, rfr_env_pred = _dual_predict(gbr_env, rfr_env, ml_features)
    gbr_func_pred, rfr_func_pred = _dual_predict(gbr_func, rfr_func, ml_features)
    gbr_comp_pred, rfr_comp_pred = _dual_predict(gbr_comp, rfr_comp, ml_features)

    ic_fallback = _clip01(_safe_float(section_scores.get("ic_risk"), 1.0 - (_safe_float(who_scores.get("ic_score"), 3.0) - 1.0) / 4.0))
    env_fallback = _clip01(_safe_float(section_scores.get("env_risk"), 1.0 - (_safe_float(who_scores.get("env_score"), 3.0) - 1.0) / 4.0))
    func_fallback = _clip01(_safe_float(section_scores.get("func_risk"), 1.0 - (_safe_float(who_scores.get("func_score"), 3.0) - 1.0) / 4.0))
    composite_fallback = _clip01(_safe_float(section_scores.get("composite_risk"), section_scores.get("rule_composite", 0.5)))

    ic_risk_raw = _notebook_ml_score(gbr_ic_pred, rfr_ic_pred, ic_fallback)
    env_risk_raw = _notebook_ml_score(gbr_env_pred, rfr_env_pred, env_fallback)
    func_risk_raw = _notebook_ml_score(gbr_func_pred, rfr_func_pred, func_fallback)

    if gbr_ic_pred is None and rfr_ic_pred is None:
        warnings_list.append("IC ML models unavailable/incompatible; fallback score used.")
    if gbr_env_pred is None and rfr_env_pred is None:
        warnings_list.append("ENV ML models unavailable/incompatible; fallback score used.")
    if gbr_func_pred is None and rfr_func_pred is None:
        warnings_list.append("FUNC ML models unavailable/incompatible; fallback score used.")

    composite_risk = _notebook_ml_score(gbr_comp_pred, rfr_comp_pred, composite_fallback)
    if gbr_comp_pred is None and rfr_comp_pred is None:
        warnings_list.append("Composite ML models unavailable/incompatible; fallback score used.")

    wellbeing_score = float(section_scores.get("overall_wellbeing", 0.5))

    # 3. Risk levels
    ic_level = _get_risk_level(ic_risk_raw)
    env_level = _get_risk_level(env_risk_raw)
    func_level = _get_risk_level(func_risk_raw)

    if composite_risk >= RISK_THRESHOLDS["critical"]:
        overall_level = "CRITICAL"
    elif composite_risk >= RISK_THRESHOLDS["high"]:
        overall_level = "HIGH"
    elif composite_risk >= RISK_THRESHOLDS["moderate"]:
        overall_level = "MODERATE"
    else:
        overall_level = "LOW"

    if notebook_override:
        # Bug 10 fix: a zero value in the CSV means "not available", not a true zero
        # risk score.  Only override when the CSV value is strictly positive so that
        # a correctly-computed ML score is never silently zeroed out.
        _ov_ic = _safe_float(notebook_override.get("ml_ic_risk"), 0.0)
        _ov_env = _safe_float(notebook_override.get("ml_env_risk"), 0.0)
        _ov_func = _safe_float(notebook_override.get("ml_func_risk"), 0.0)
        _ov_comp = _safe_float(notebook_override.get("composite_risk"), 0.0)
        if _ov_ic > 0:
            ic_risk_raw = _clip01(_ov_ic)
        if _ov_env > 0:
            env_risk_raw = _clip01(_ov_env)
        if _ov_func > 0:
            func_risk_raw = _clip01(_ov_func)
        if _ov_comp > 0:
            composite_risk = _clip01(_ov_comp)
        overall_level = (notebook_override.get("risk_level") or overall_level or "").upper() or overall_level
        ic_level = _get_risk_level(ic_risk_raw)
        env_level = _get_risk_level(env_risk_raw)
        func_level = _get_risk_level(func_risk_raw)

    # 4. Recommendations
    recs = _build_recommendations(
        named_id=named_id,
        overall_level=overall_level,
        feature_map=feature_map,
        section_scores=section_scores,
        raw_context=raw_context,
    )
    if notebook_recommendations:
        recs = notebook_recommendations
        warnings_list.append("Recommendations matched to notebook export for known senior record.")

    return {
        "status": "success",
        "cluster": {
            "raw_id": raw_cluster_id,
            "named_id": named_id,
            "name": cluster_profile["name"],
            "ic": cluster_profile["ic"],
            "env": cluster_profile["env"],
            "func": cluster_profile["func"],
            "description": cluster_profile["description"],
        },
        "risk_scores": {
            "ic_risk": round(ic_risk_raw, 4),
            "env_risk": round(env_risk_raw, 4),
            "func_risk": round(func_risk_raw, 4),
            "composite_risk": round(composite_risk, 4),
            "wellbeing_score": round(wellbeing_score, 4),
        },
        "risk_levels": {
            "ic": ic_level,
            "env": env_level,
            "func": func_level,
            "overall": overall_level,
        },
        "recommendations": recs,
        "section_scores": section_scores,
        "model_metadata": {
            "model_dir": MODEL_DIR,
            "notebook_overrides_enabled": ENABLE_NOTEBOOK_OVERRIDES,
            "notebook_override_applied": bool(notebook_override),
        },
        "warnings": warnings_list,
    }


# ── Flask API ─────────────────────────────────────────────────────────────────
@app.route("/health", methods=["GET"])
def health():
    return jsonify({"status": "ok", "service": "osca-inference"})


@app.route("/infer", methods=["POST"])
def infer_endpoint():
    try:
        payload = request.get_json(force=True)
        if not payload or not isinstance(payload, dict):
            return jsonify({"status": "error", "message": "Expected JSON object payload"}), 400

        result = infer(payload)
        return jsonify(result)
    except Exception as exc:
        logger.exception("Inference error")
        return jsonify({"status": "error", "message": str(exc)}), 500


@app.route("/batch_infer", methods=["POST"])
def batch_infer_endpoint():
    try:
        batch = request.get_json(force=True)
        if not isinstance(batch, list):
            return jsonify({"status": "error", "message": "Expected JSON array"}), 400

        results = []
        for idx, item in enumerate(batch):
            if not isinstance(item, dict):
                results.append({
                    "status": "error",
                    "message": f"Item at index {idx} is not an object",
                })
                continue
            results.append(infer(item))

        return jsonify({"status": "success", "count": len(results), "results": results})
    except Exception as exc:
        logger.exception("Batch inference error")
        return jsonify({"status": "error", "message": str(exc)}), 500


if __name__ == "__main__":
    port = int(os.environ.get("INFERENCE_PORT", 5002))
    logger.info("Starting OSCA Inference Service on port %s", port)
    app.run(host="0.0.0.0", port=port, debug=False)
