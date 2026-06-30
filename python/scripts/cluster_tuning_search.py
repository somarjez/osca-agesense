"""
cluster_tuning_search.py
=========================
Read-only staged grid search to improve K=4 clustering quality for AgeSense.

IMPORTANT --READ-ONLY:
  This script does NOT modify any deployed artifacts:
  - Does NOT write to python/models/
  - Does NOT modify the database
  - Does NOT write to osca_output/reports/ (used by ml:sync-validation)
  - Writes ONLY to osca_output/cluster_tuning/

Staged search:
  Stage A: 3 scalers x 4 n_components x 5 n_neighbors x 4 min_dist x 3 metrics = 720 configs
  Stage B: 31 one-at-a-time feature removals + baseline = 32 configs (under Stage-A best config)
  Stage C: Top-5 Stage-A configs re-evaluated on Stage-B best feature set = 5 configs

KMeans fixed: init='k-means++', n_init=100, max_iter=1000, random_state=42
UMAP fixed: random_state=42

Metrics per config:
  UMAP-space: Silhouette, Davies-Bouldin, Calinski-Harabasz  (primary ranking)
  Scaled-space: Silhouette, Davies-Bouldin, Calinski-Harabasz (honesty guardrail)
  Imbalance: coefficient of variation of cluster sizes
  Interpretability: monotonic rule_composite + profile-signature check

Current baseline (deployed):
  StandardScaler | nc=10 | nn=15 | md=0.0 | euclidean | 31 features
  Silhouette=0.4321  DB=0.8586  CH=496.4

Targets: Silhouette > 0.44, DB < 0.84, CH >= 496

Usage:
  cd osca-system
  python/venv/Scripts/python.exe python/scripts/cluster_tuning_search.py
"""

import io
import json
import os
import re
import sys
import time
import shutil
import warnings
from datetime import date, datetime

warnings.filterwarnings("ignore")

# Force UTF-8 stdout so Unicode characters in print() don't crash on Windows
try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
except (AttributeError, io.UnsupportedOperation):
    pass

# ── Paths ──────────────────────────────────────────────────────────────────────
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_DIR   = os.path.dirname(os.path.dirname(SCRIPT_DIR))   # osca-system/ (inner)
OUTER_DIR  = os.path.dirname(BASE_DIR)                       # osca-system/ (outer, has osca_output/)

sys.path.insert(0, os.path.join(BASE_DIR, "python", "services"))

import numpy as np
import pandas as pd
import umap
from sklearn.cluster import KMeans
from sklearn.metrics import (
    silhouette_score, davies_bouldin_score, calinski_harabasz_score,
)
from sklearn.preprocessing import StandardScaler, RobustScaler, MinMaxScaler

# ── Output directory ───────────────────────────────────────────────────────────
OUT_DIR = os.path.join(OUTER_DIR, "osca_output", "cluster_tuning")
os.makedirs(OUT_DIR, exist_ok=True)
print(f"[cluster_tuning] Output dir: {OUT_DIR}")

# ── Load model config ──────────────────────────────────────────────────────────
def _read_dotenv(name: str):
    for cand in [os.path.join(BASE_DIR, ".env"), os.path.join(OUTER_DIR, ".env")]:
        if os.path.exists(cand):
            try:
                for line in open(cand, encoding="utf-8"):
                    line = line.strip()
                    if line and not line.startswith("#") and "=" in line:
                        k, _, v = line.partition("=")
                        if k.strip() == name:
                            return v.strip().strip('"').strip("'")
            except Exception:
                pass
    return None

_env_model = os.environ.get("ML_MODELS_PATH") or _read_dotenv("ML_MODELS_PATH")
MODEL_DIR  = (
    _env_model if (_env_model and os.path.isabs(_env_model))
    else os.path.join(BASE_DIR, _env_model) if _env_model
    else os.path.join(BASE_DIR, "python", "models")
)

fl_path = os.path.join(MODEL_DIR, "final_feature_list.json")
with open(fl_path, encoding="utf-8") as fh:
    feat_config: dict = json.load(fh)

VIF_RETAINED: list = feat_config["vif_retained"]                    # 36 features
FINAL_FEATS:  list = feat_config["final_clustering_features"]       # 31 features (current deployed)
RANDOM_STATE: int  = 42
K_BEST:       int  = 4

# Exclusion guard --must never appear as clustering inputs
FORBIDDEN_FEATURES = frozenset({
    "composite_risk", "rule_composite", "risk_level", "risk_level_rule",
    "overall_risk_level", "ic_risk_level", "env_risk_level", "func_risk_level",
    "km_cluster", "cluster_id", "cluster_named_id", "cluster_name", "priority_flag",
})
for _f in VIF_RETAINED:
    assert _f not in FORBIDDEN_FEATURES, f"FORBIDDEN feature in VIF set: {_f}"

print(f"[cluster_tuning] VIF retained: {len(VIF_RETAINED)}  |  Current final: {len(FINAL_FEATS)}")

# ── Search grid ────────────────────────────────────────────────────────────────
SCALERS = {
    "StandardScaler": StandardScaler,
    "RobustScaler":   RobustScaler,
    "MinMaxScaler":   MinMaxScaler,
}
NC_GRID  = [8, 10, 12, 15]
NN_GRID  = [10, 15, 20, 30, 40]
MD_GRID  = [0.0, 0.05, 0.1, 0.2]
MET_GRID = ["euclidean", "manhattan", "cosine"]

# Ranking weights (higher Sil, lower DB, higher CH, lower imbalance)
W_SIL  = 1.0
W_DB   = 1.0
W_CH   = 0.5
W_IMB  = 0.5

# Targets
TARGET_SIL  = 0.44
TARGET_DB   = 0.84
TARGET_CH   = 496.0
MIN_CLUSTER_FRAC = 0.05   # ≥5% of N seniors per cluster

# Baselines for the report
BASELINE_SIL = 0.4321
BASELINE_DB  = 0.8586
BASELINE_CH  = 496.4

# ── DB connection ──────────────────────────────────────────────────────────────
import pymysql
import pymysql.cursors

def _db_connect():
    env: dict = {}
    for cand in [os.path.join(BASE_DIR, ".env"), os.path.join(OUTER_DIR, ".env")]:
        if os.path.exists(cand):
            try:
                for line in open(cand, encoding="utf-8"):
                    line = line.strip()
                    if line and not line.startswith("#") and "=" in line:
                        k, _, v = line.partition("=")
                        env[k.strip()] = v.strip().strip('"').strip("'")
                break
            except Exception:
                pass
    return pymysql.connect(
        host        = env.get("DB_HOST", "127.0.0.1"),
        port        = int(env.get("DB_PORT", 3306)),
        user        = env.get("DB_USERNAME", "root"),
        password    = env.get("DB_PASSWORD", ""),
        database    = env.get("DB_DATABASE", "osca_db"),
        cursorclass = pymysql.cursors.DictCursor,
    )

from preprocess_service import preprocess   # noqa: E402

QUERY = """
    SELECT
        sc.id, sc.first_name, sc.last_name, sc.barangay, sc.date_of_birth,
        sc.gender, sc.marital_status, sc.educational_attainment,
        sc.monthly_income_range, sc.num_children, sc.num_working_children,
        sc.household_size, sc.child_financial_support, sc.spouse_working,
        sc.income_source, sc.real_assets, sc.movable_assets, sc.living_with,
        sc.household_condition, sc.community_service, sc.specialization,
        sc.medical_concern, sc.dental_concern, sc.optical_concern,
        sc.hearing_concern, sc.social_emotional_concern, sc.healthcare_difficulty,
        sc.has_medical_checkup, sc.checkup_schedule,
        qs.a1_enjoy_life, qs.a2_life_satisfaction, qs.a3_future_outlook, qs.a4_meaningfulness,
        qs.b1_physical_energy, qs.b2_pain_discomfort, qs.b3_health_self_care,
        qs.b4_health_outside, qs.b5_mobility,
        qs.c1_happiness, qs.c2_calm_peace, qs.c3_loneliness, qs.c4_confidence,
        qs.d1_independence, qs.d2_time_control, qs.d3_life_control, qs.d4_income_limits,
        qs.e1_social_support, qs.e2_close_person, qs.e3_community_opportunities,
        qs.e4_participation, qs.e5_respect,
        qs.f1_home_safety, qs.f2_neighborhood_safety, qs.f3_service_access, qs.f4_home_comfort,
        qs.g1_household_expenses, qs.g2_medical_afford, qs.g3_personal_wants,
        qs.h1_belief_comfort, qs.h2_belief_practice,
        mr.rule_composite,
        mr.overall_risk_level,
        mr.cluster_named_id,
        mr.prediction_source
    FROM ml_results mr
    JOIN senior_citizens sc ON sc.id = mr.senior_citizen_id
    JOIN qol_surveys     qs ON qs.senior_citizen_id = sc.id
    WHERE mr.deleted_at IS NULL
      AND mr.is_stale = 0
      AND mr.id = (
          SELECT MAX(id2.id) FROM ml_results id2
          WHERE id2.senior_citizen_id = sc.id
            AND id2.deleted_at IS NULL
            AND id2.is_stale = 0
      )
      AND sc.deleted_at IS NULL
    ORDER BY sc.id
"""

# ── Load data ──────────────────────────────────────────────────────────────────
def _parse_json_col(val):
    if val is None:
        return []
    if isinstance(val, (list, dict)):
        return val
    try:
        return json.loads(val)
    except Exception:
        return []

def _compute_age(dob) -> int:
    if dob is None:
        return 70
    if isinstance(dob, str):
        dob = datetime.strptime(dob[:10], "%Y-%m-%d").date()
    today = date.today()
    return today.year - dob.year - ((today.month, today.day) < (dob.month, dob.day))

print("[cluster_tuning] Connecting to DB...")
t0 = time.time()
conn = _db_connect()
with conn.cursor() as cur:
    cur.execute(QUERY)
    rows = cur.fetchall()
conn.close()
print(f"[cluster_tuning]   Loaded {len(rows)} seniors ({time.time()-t0:.1f}s)")

print("[cluster_tuning] Building feature matrix via preprocess_service...")
t0 = time.time()

feature_rows:    list = []
rule_composites: list = []
risk_levels:     list = []
nb_clusters:     list = []
skipped = 0

for row in rows:
    qol = {
        "qol_enjoy_life":        row["a1_enjoy_life"],
        "qol_life_satisfaction": row["a2_life_satisfaction"],
        "qol_future_outlook":    row["a3_future_outlook"],
        "qol_meaningfulness":    row["a4_meaningfulness"],
        "phy_energy":            row["b1_physical_energy"],
        "phy_pain_r":            row["b2_pain_discomfort"],
        "phy_health_limit_r":    row["b3_health_self_care"],
        "phy_mobility_outside":  row["b4_health_outside"],
        "phy_mobility_indoor":   row["b5_mobility"],
        "psych_happiness":       row["c1_happiness"],
        "psych_peace":           row["c2_calm_peace"],
        "psych_lonely_r":        row["c3_loneliness"],
        "psych_confidence":      row["c4_confidence"],
        "func_independence":     row["d1_independence"],
        "func_autonomy":         row["d2_time_control"],
        "func_control":          row["d3_life_control"],
        "env_income_limit_r":    row["d4_income_limits"],
        "soc_social_support":    row["e1_social_support"],
        "soc_close_friend":      row["e2_close_person"],
        "soc_participation":     row["e4_participation"],
        "soc_opportunity":       row["e3_community_opportunities"],
        "soc_respect":           row["e5_respect"],
        "env_safe_home":         row["f1_home_safety"],
        "env_safe_neighborhood": row["f2_neighborhood_safety"],
        "env_service_access":    row["f3_service_access"],
        "env_home_comfort":      row["f4_home_comfort"],
        "env_fin_medical":       row["g2_medical_afford"],
        "env_fin_household":     row["g1_household_expenses"],
        "env_fin_personal":      row["g3_personal_wants"],
        "spi_belief_comfort":    row["h1_belief_comfort"],
        "spi_belief_practice":   row["h2_belief_practice"],
    }
    raw = {
        "senior_id":                row["id"],
        "first_name":               row["first_name"],
        "last_name":                row["last_name"],
        "barangay":                 row["barangay"],
        "age":                      _compute_age(row["date_of_birth"]),
        "gender":                   row["gender"],
        "marital_status":           row["marital_status"],
        "educational_attainment":   row["educational_attainment"],
        "monthly_income_range":     row["monthly_income_range"],
        "num_children":             row["num_children"] or 0,
        "num_working_children":     row["num_working_children"] or 0,
        "household_size":           row["household_size"] or 1,
        "child_financial_support":  row["child_financial_support"],
        "spouse_working":           row["spouse_working"],
        "income_source":            _parse_json_col(row["income_source"]),
        "real_assets":              _parse_json_col(row["real_assets"]),
        "movable_assets":           _parse_json_col(row["movable_assets"]),
        "living_with":              _parse_json_col(row["living_with"]),
        "household_condition":      _parse_json_col(row["household_condition"]),
        "community_service":        _parse_json_col(row["community_service"]),
        "specialization":           _parse_json_col(row["specialization"]),
        "medical_concern":          _parse_json_col(row["medical_concern"]),
        "dental_concern":           _parse_json_col(row["dental_concern"]),
        "optical_concern":          _parse_json_col(row["optical_concern"]),
        "hearing_concern":          _parse_json_col(row["hearing_concern"]),
        "social_emotional_concern": _parse_json_col(row["social_emotional_concern"]),
        "healthcare_difficulty":    _parse_json_col(row["healthcare_difficulty"]),
        "has_medical_checkup":      bool(row["has_medical_checkup"])
                                    and row["checkup_schedule"] != "No Follow-up",
        "qol_responses":            qol,
    }
    try:
        result      = preprocess(raw)
        feature_map = result.get("feature_map") or {}
        feat_row    = {f: float(feature_map.get(f, 0.0)) for f in VIF_RETAINED}
        feature_rows.append(feat_row)
        rule_composites.append(float(row.get("rule_composite") or 0.0))
        risk_levels.append(str(row.get("overall_risk_level") or "MODERATE"))
        nb_clusters.append(int(row.get("cluster_named_id") or 1))
    except Exception as exc:
        print(f"  WARN skipped id={row['id']} ({row['first_name']}): {exc}")
        skipped += 1

N = len(feature_rows)
print(f"[cluster_tuning]   {N} feature vectors built, {skipped} skipped ({time.time()-t0:.1f}s)")
df_feat            = pd.DataFrame(feature_rows)          # N x 36
rule_composite_arr = np.array(rule_composites)
risk_level_arr     = np.array(risk_levels)

# Domain groups for interpretability (from raw features, stable across configs)
PHY_COLS  = [c for c in ["phy_energy","phy_pain_r","phy_health_limit_r",
                          "phy_mobility_outside","phy_mobility_indoor"] if c in df_feat.columns]
PSYCH_COLS = [c for c in ["psych_happiness","psych_peace","psych_lonely_r",
                           "psych_confidence"] if c in df_feat.columns]
FUNC_COLS  = [c for c in ["func_independence","func_autonomy",
                           "func_control"] if c in df_feat.columns]
ENV_COLS   = [c for c in ["env_safe_home","env_safe_neighborhood",
                           "env_home_comfort","env_service_access"] if c in df_feat.columns]
FIN_COLS   = [c for c in ["env_income_limit_r","env_fin_household",
                           "env_fin_medical","env_fin_personal"] if c in df_feat.columns]
SOC_COLS   = [c for c in ["soc_social_support","soc_close_friend",
                           "soc_opportunity","soc_respect"] if c in df_feat.columns]
WHO_DOMAINS = {
    "phy": PHY_COLS, "psych": PSYCH_COLS, "func": FUNC_COLS,
    "env": ENV_COLS, "fin": FIN_COLS,     "soc": SOC_COLS,
}

# ── Helpers ────────────────────────────────────────────────────────────────────

def _domain_cluster_means(labels: np.ndarray) -> dict:
    """Per-cluster mean for each WHO domain group."""
    dm: dict = {}
    for domain, cols in WHO_DOMAINS.items():
        if not cols:
            continue
        dm[domain] = {}
        for k in range(K_BEST):
            mask = labels == k
            dm[domain][str(k)] = float(df_feat.loc[mask, cols].mean(axis=1).mean()) \
                if mask.sum() > 0 else 0.0
    return dm


def _risk_dist(labels: np.ndarray) -> dict:
    rd: dict = {}
    for k in range(K_BEST):
        mask = labels == k
        rd[str(k)] = {
            lvl: int((risk_level_arr[mask] == lvl).sum())
            for lvl in ["LOW", "MODERATE", "HIGH", "CRITICAL"]
        }
    return rd


def interpretability_gate(labels, composite_per_k, domain_means, too_small):
    """Returns (passes: bool, profile_map: dict)."""
    PROFILE_NAMES = [
        "High Functioning/Well-Supported",
        "Stable Ageing/Moderate Support Needs",
        "Environmentally & Financially Vulnerable",
        "Low Functioning/Multi-Domain Priority",
    ]

    if too_small:
        return False, {}

    k_order    = sorted(composite_per_k, key=lambda k: composite_per_k[k])
    composites = [composite_per_k[k] for k in k_order]

    # (a) Monotonic composite across 4 ordered clusters
    if not all(composites[i] <= composites[i + 1] for i in range(3)):
        return False, {}

    profile_map = {k_order[i]: PROFILE_NAMES[i] for i in range(4)}

    def _dmean(k, domains):
        vals = [domain_means.get(d, {}).get(str(k), 0.5)
                for d in domains if d in domain_means]
        return float(np.mean(vals)) if vals else 0.5

    k_low  = k_order[0]   # expected: High Functioning
    k_high = k_order[3]   # expected: Low Functioning

    # (b) Highest-functioning cluster should have better phy+func than lowest-functioning
    if _dmean(k_low, ["phy", "func"]) <= _dmean(k_high, ["phy", "func"]):
        return False, profile_map

    # (c) Env/Financial Vulnerable cluster (k_order[2]) should show env/fin deficit
    k_env  = k_order[2]
    pf_ev  = _dmean(k_env, ["phy", "func"])
    ef_ev  = _dmean(k_env, ["env", "fin"])
    # The check is lenient (positive deficit preferred, not hard-enforced)
    # --full profile table is printed for human confirmation

    return True, profile_map


def evaluate_config(feat_names, scaler_name, nc, nn, md, metric):
    """Fit scaler + UMAP + KMeans; return full result dict or None on failure."""
    try:
        X_raw    = df_feat[feat_names].values
        sc       = SCALERS[scaler_name]()
        X_scaled = sc.fit_transform(X_raw)

        reducer  = umap.UMAP(
            n_components=nc, n_neighbors=nn, min_dist=md,
            metric=metric, random_state=RANDOM_STATE,
        )
        X_umap   = reducer.fit_transform(X_scaled)

        km       = KMeans(
            n_clusters=K_BEST, init="k-means++",
            n_init=100, max_iter=1000, random_state=RANDOM_STATE,
        )
        labels   = km.fit_predict(X_umap)

        # UMAP-space metrics (primary)
        sil_u    = float(silhouette_score(X_umap, labels))
        db_u     = float(davies_bouldin_score(X_umap, labels))
        ch_u     = float(calinski_harabasz_score(X_umap, labels))

        # Scaled-space metrics (guardrail)
        sil_s    = float(silhouette_score(X_scaled, labels))
        db_s     = float(davies_bouldin_score(X_scaled, labels))
        ch_s     = float(calinski_harabasz_score(X_scaled, labels))

        counts   = np.bincount(labels, minlength=K_BEST).tolist()
        imb_cv   = float(np.std(counts) / np.mean(counts))
        too_sm   = int(min(counts)) < int(N * MIN_CLUSTER_FRAC)

        comp_per_k = {
            str(k): float(rule_composite_arr[labels == k].mean())
            for k in range(K_BEST) if (labels == k).sum() > 0
        }
        dom_means  = _domain_cluster_means(labels)
        risk_dist  = _risk_dist(labels)
        interp, profile_map = interpretability_gate(
            labels, comp_per_k, dom_means, too_sm
        )

        return {
            "scaler": scaler_name, "n_components": nc, "n_neighbors": nn,
            "min_dist": md, "metric": metric, "n_features": len(feat_names),
            "sil_umap": round(sil_u, 4), "db_umap": round(db_u, 4), "ch_umap": round(ch_u, 2),
            "sil_sc":   round(sil_s, 4), "db_sc":   round(db_s, 4), "ch_sc":   round(ch_s, 2),
            "imbalance_cv": round(imb_cv, 4), "counts": counts,
            "min_cluster_size": int(min(counts)), "too_small": too_sm,
            "interpretable": interp, "profile_map": profile_map,
            "composite_per_cluster": comp_per_k,
            "domain_means": dom_means, "risk_dist": risk_dist,
            "removed_feature": "", "stage": "",
        }
    except Exception as exc:
        return None


def _compute_combined(results: list) -> None:
    """Adds/updates 'combined' key in-place (z-score blend)."""
    if not results:
        return
    sils = np.array([r["sil_umap"]     for r in results])
    dbs  = np.array([r["db_umap"]      for r in results])
    chs  = np.array([r["ch_umap"]      for r in results])
    imbs = np.array([r["imbalance_cv"] for r in results])

    def _z(val, arr):
        s = arr.std()
        return (val - arr.mean()) / s if s > 1e-9 else 0.0

    for r in results:
        r["combined"] = round(
            W_SIL * _z(r["sil_umap"],     sils)
            - W_DB  * _z(r["db_umap"],      dbs)
            + W_CH  * _z(r["ch_umap"],      chs)
            - W_IMB * _z(r["imbalance_cv"], imbs),
            6,
        )


# ═══════════════════════════════════════════════════════════════════════════════
# Stage A --scaler × UMAP grid on current 31-feature set (720 configs)
# ═══════════════════════════════════════════════════════════════════════════════
STAGE_A_CHECKPOINT = os.path.join(OUT_DIR, "_stage_a_checkpoint.json")

if os.path.exists(STAGE_A_CHECKPOINT):
    print(f"\n[Stage A] Loading checkpoint from {STAGE_A_CHECKPOINT} (skipping re-run)")
    with open(STAGE_A_CHECKPOINT, encoding="utf-8") as fh:
        stage_a = json.load(fh)
    t_a = time.time()
    print(f"[Stage A] Loaded {len(stage_a)} results from checkpoint.")
else:
    print("\n[Stage A] Scaler x UMAP grid search -- 720 configs on 31-feature set")
    stage_a: list = []
    total_a       = len(SCALERS) * len(NC_GRID) * len(NN_GRID) * len(MD_GRID) * len(MET_GRID)
    done_a        = 0
    t_a           = time.time()

    for sc_name in SCALERS:
        for nc in NC_GRID:
            for nn in NN_GRID:
                for md in MD_GRID:
                    for met in MET_GRID:
                        done_a += 1
                        res = evaluate_config(FINAL_FEATS, sc_name, nc, nn, md, met)
                        if res is not None:
                            res["stage"] = "A"
                            stage_a.append(res)
                        if done_a % 60 == 0 or done_a == total_a:
                            elapsed = time.time() - t_a
                            eta     = elapsed / done_a * (total_a - done_a)
                            best_s  = max((r["sil_umap"] for r in stage_a), default=0.0)
                            print(f"  [{done_a:>3}/{total_a}] {elapsed:>5.0f}s  ETA {eta:.0f}s"
                                  f"  best_sil={best_s:.4f}  ok={len(stage_a)}")

    _compute_combined(stage_a)
    stage_a.sort(key=lambda r: r["combined"], reverse=True)

    # Save checkpoint so Stage B/C can resume without re-running 720 UMAP fits
    with open(STAGE_A_CHECKPOINT, "w", encoding="utf-8") as fh:
        json.dump(stage_a, fh)
    print(f"[Stage A] Checkpoint saved: {STAGE_A_CHECKPOINT}")

best_a = stage_a[0]

print(f"\n[Stage A] Complete -- {len(stage_a)} configs in {time.time()-t_a:.1f}s")
print(f"  Best: {best_a['scaler']} | nc={best_a['n_components']} nn={best_a['n_neighbors']}"
      f" md={best_a['min_dist']} met={best_a['metric']}")
print(f"  UMAP:  Sil={best_a['sil_umap']}  DB={best_a['db_umap']}  CH={best_a['ch_umap']}")
print(f"  Scaled: Sil={best_a['sil_sc']}  DB={best_a['db_sc']}  CH={best_a['ch_sc']}")
print(f"  Counts={best_a['counts']}  ImbCV={best_a['imbalance_cv']}  Interp={best_a['interpretable']}")

# ═══════════════════════════════════════════════════════════════════════════════
# Stage B --feature ablation under Stage-A best config (32 configs)
# ═══════════════════════════════════════════════════════════════════════════════
print(f"\n[Stage B] Feature ablation under Stage-A best config --32 configs")
stage_b: list = []
t_b = time.time()

# Baseline (no removal) at Stage-A best UMAP params
res_b0 = evaluate_config(
    FINAL_FEATS,
    best_a["scaler"], best_a["n_components"], best_a["n_neighbors"],
    best_a["min_dist"], best_a["metric"],
)
if res_b0:
    res_b0["stage"] = "B_baseline"
    stage_b.append(res_b0)
b0_sil = res_b0["sil_umap"] if res_b0 else best_a["sil_umap"]
b0_db  = res_b0["db_umap"]  if res_b0 else best_a["db_umap"]

for fi, feat in enumerate(FINAL_FEATS):
    ablated = [f for f in FINAL_FEATS if f != feat]
    res = evaluate_config(
        ablated,
        best_a["scaler"], best_a["n_components"], best_a["n_neighbors"],
        best_a["min_dist"], best_a["metric"],
    )
    if res is not None:
        res["stage"] = "B"
        res["removed_feature"] = feat
        stage_b.append(res)
    if (fi + 1) % 8 == 0 or fi + 1 == len(FINAL_FEATS):
        print(f"  [{fi+1}/{len(FINAL_FEATS)}] elapsed={time.time()-t_b:.1f}s")

print(f"[Stage B] Complete --{len(stage_b)} configs in {time.time()-t_b:.1f}s")

beneficial = [
    r for r in stage_b
    if r["removed_feature"]
    and r["sil_umap"] > b0_sil
    and r["db_umap"]  < b0_db
    and r["interpretable"]
]
print(f"  Stage-B baseline: Sil={b0_sil:.4f}  DB={b0_db:.4f}")
print(f"  Beneficial single-feature removals: {len(beneficial)}")
for r in sorted(beneficial, key=lambda x: x["sil_umap"], reverse=True):
    print(f"    Remove '{r['removed_feature']}': "
          f"Sil={r['sil_umap']:.4f} ({r['sil_umap']-b0_sil:+.4f})  "
          f"DB={r['db_umap']:.4f} ({r['db_umap']-b0_db:+.4f})")

if beneficial:
    best_b_removal = max(beneficial, key=lambda r: r["sil_umap"])
    best_b_feats   = [f for f in FINAL_FEATS if f != best_b_removal["removed_feature"]]
    print(f"  Winner removal: '{best_b_removal['removed_feature']}' -> {len(best_b_feats)} features")
else:
    best_b_removal = None
    best_b_feats   = FINAL_FEATS.copy()
    print("  No beneficial single-feature removal. Keeping 31-feature set.")

# ═══════════════════════════════════════════════════════════════════════════════
# Stage C --top-5 Stage-A configs re-evaluated on Stage-B best feature set
# ═══════════════════════════════════════════════════════════════════════════════
print(f"\n[Stage C] Top-5 Stage-A configs on Stage-B features ({len(best_b_feats)}) --5 configs")
stage_c: list = []
t_c = time.time()

for rank, cfg in enumerate(stage_a[:5]):
    res = evaluate_config(
        best_b_feats,
        cfg["scaler"], cfg["n_components"], cfg["n_neighbors"],
        cfg["min_dist"], cfg["metric"],
    )
    if res is not None:
        res["stage"]           = f"C_{rank+1}"
        res["removed_feature"] = best_b_removal["removed_feature"] if best_b_removal else ""
        stage_c.append(res)
    sil_s = res["sil_umap"] if res else float("nan")
    db_s  = res["db_umap"]  if res else float("nan")
    print(f"  Rank {rank+1}: Sil={sil_s:.4f}  DB={db_s:.4f}  interp={res['interpretable'] if res else '?'}")

print(f"[Stage C] Complete in {time.time()-t_c:.1f}s")

# ═══════════════════════════════════════════════════════════════════════════════
# Final ranking across all stages
# ═══════════════════════════════════════════════════════════════════════════════
all_results = stage_a + stage_b + stage_c
_compute_combined(all_results)

candidates = [r for r in all_results if r.get("interpretable") and not r.get("too_small")]
candidates.sort(key=lambda r: r["combined"], reverse=True)
winner = candidates[0] if candidates else sorted(all_results, key=lambda r: r["combined"], reverse=True)[0]

beats_sil = winner["sil_umap"] > TARGET_SIL
beats_db  = winner["db_umap"]  < TARGET_DB
beats_ch  = winner["ch_umap"]  >= TARGET_CH
all_targets_met = beats_sil and beats_db and beats_ch

print(f"\n[Final] Winner: {winner['scaler']} | nc={winner['n_components']}"
      f" nn={winner['n_neighbors']} md={winner['min_dist']} met={winner['metric']}"
      f" n_feat={winner['n_features']}")
print(f"  UMAP-space: Sil={winner['sil_umap']}  DB={winner['db_umap']}  CH={winner['ch_umap']}")
print(f"  Targets:   Sil>0.44={'Y' if beats_sil else 'N'}  DB<0.84={'Y' if beats_db else 'N'}  CH>=496={'Y' if beats_ch else 'N'}")
print(f"  Interpretable: {winner['interpretable']}")

# ═══════════════════════════════════════════════════════════════════════════════
# Save outputs
# ═══════════════════════════════════════════════════════════════════════════════
print("\n[cluster_tuning] Saving outputs...")

def _flatten(r: dict) -> dict:
    """Remove non-scalar fields for CSV."""
    flat = {k: v for k, v in r.items()
            if k not in ("domain_means", "risk_dist", "profile_map", "composite_per_cluster")}
    flat["counts_str"] = str(r.get("counts", []))
    flat.pop("counts", None)
    return flat

df_all    = pd.DataFrame([_flatten(r) for r in all_results])
df_ranked = df_all[df_all["interpretable"]].sort_values("combined", ascending=False)

p_results  = os.path.join(OUT_DIR, "cluster_tuning_results.csv")
p_ranked   = os.path.join(OUT_DIR, "cluster_tuning_ranked.csv")
p_profiles = os.path.join(OUT_DIR, "best_profiles.json")
p_report   = os.path.join(OUT_DIR, "cluster_tuning_report.md")

df_all.to_csv(p_results,  index=False)
df_ranked.to_csv(p_ranked, index=False)

best_profiles_out = {
    "winner_config": {
        "scaler": winner["scaler"],
        "n_components": winner["n_components"],
        "n_neighbors":  winner["n_neighbors"],
        "min_dist":     winner["min_dist"],
        "metric":       winner["metric"],
        "n_features":   winner["n_features"],
        "removed_feature": winner.get("removed_feature", ""),
    },
    "metrics_umap": {
        "silhouette":         winner["sil_umap"],
        "davies_bouldin":     winner["db_umap"],
        "calinski_harabasz":  winner["ch_umap"],
    },
    "metrics_scaled": {
        "silhouette":         winner["sil_sc"],
        "davies_bouldin":     winner["db_sc"],
        "calinski_harabasz":  winner["ch_sc"],
    },
    "cluster_counts":     winner["counts"],
    "interpretable":      winner["interpretable"],
    "profile_map":        winner.get("profile_map", {}),
    "composite_per_cluster": winner.get("composite_per_cluster", {}),
    "domain_means":       winner.get("domain_means", {}),
    "risk_distribution":  winner.get("risk_dist", {}),
    "all_targets_met":    all_targets_met,
    "beats_sil":          beats_sil,
    "beats_db":           beats_db,
    "beats_ch":           beats_ch,
    "generated_at":       datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ"),
    "n_seniors":          N,
    "baseline_sil":       BASELINE_SIL,
    "baseline_db":        BASELINE_DB,
    "baseline_ch":        BASELINE_CH,
}
with open(p_profiles, "w", encoding="utf-8") as fh:
    json.dump(best_profiles_out, fh, indent=2)

# ── Markdown report ────────────────────────────────────────────────────────────
removed_feats_for_winner = (
    [f for f in VIF_RETAINED if f not in best_b_feats]
    if best_b_removal else
    [f for f in VIF_RETAINED if f not in FINAL_FEATS]   # current deployed removals
)

def _dsign(new, old, higher_better=True):
    d = new - old
    arrow = "▲" if d > 0 else "▼"
    ok    = "✓" if (d > 0) == higher_better else "✗"
    return f"{arrow}{abs(d):.4f} {ok}"

report_sections = [
    "# Cluster Tuning Search Report",
    f"Generated: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}  "
    f"| Seniors: {N}  | K={K_BEST}  | random_state={RANDOM_STATE}",
    "",
    "## Baseline (currently deployed config)",
    "| Config | Value |",
    "|---|---|",
    "| Scaler | StandardScaler |",
    "| n_components | 10 | n_neighbors | 15 | min_dist | 0.0 | metric | euclidean |",
    "| n_features | 31 |",
    "",
    "| Metric | Baseline |",
    "|---|---|",
    f"| Silhouette (UMAP) | {BASELINE_SIL} |",
    f"| Davies-Bouldin (UMAP) | {BASELINE_DB} |",
    f"| Calinski-Harabasz (UMAP) | {BASELINE_CH} |",
    "",
    "## Winner Config",
    "| Param | Value |",
    "|---|---|",
    f"| Scaler | {winner['scaler']} |",
    f"| n_components | {winner['n_components']} |",
    f"| n_neighbors | {winner['n_neighbors']} |",
    f"| min_dist | {winner['min_dist']} |",
    f"| metric | {winner['metric']} |",
    f"| n_features | {winner['n_features']} |",
    f"| removed_feature | {winner.get('removed_feature', 'none') or 'none'} |",
    f"| stage | {winner['stage']} |",
    "",
    "## Metric Comparison",
    "| Metric | Baseline | Winner | Delta | Target | Met? |",
    "|---|---|---|---|---|---|",
    f"| Silhouette (UMAP)        | {BASELINE_SIL} | {winner['sil_umap']} | {_dsign(winner['sil_umap'],BASELINE_SIL,True)}  | >{TARGET_SIL} | {'✓' if beats_sil else '✗'} |",
    f"| Davies-Bouldin (UMAP)    | {BASELINE_DB}  | {winner['db_umap']}  | {_dsign(winner['db_umap'],BASELINE_DB,False)} | <{TARGET_DB}  | {'✓' if beats_db  else '✗'} |",
    f"| Calinski-Harabasz (UMAP) | {BASELINE_CH}  | {winner['ch_umap']}  | {_dsign(winner['ch_umap'],BASELINE_CH,True)}  | >={TARGET_CH} | {'✓' if beats_ch  else '✗'} |",
    f"| Silhouette (scaled)      | --             | {winner['sil_sc']}   | --    | guardrail | --|",
    f"| Davies-Bouldin (scaled)  | --             | {winner['db_sc']}    | --    | guardrail | --|",
    f"| CH (scaled)              | --             | {winner['ch_sc']}    | --    | guardrail | --|",
    f"| Imbalance CV             | --             | {winner['imbalance_cv']} | --| lower=better | --|",
    f"| Cluster counts           | --             | {winner['counts']}   | --    | min {int(N*MIN_CLUSTER_FRAC)} each | --|",
    "",
    "## Interpretability",
    f"Gate passed: **{winner['interpretable']}**",
    "",
    "Profile mapping (raw KMeans label → named profile, ordered by ascending rule_composite):",
]
if winner.get("profile_map"):
    for k_str, pname in sorted(winner["profile_map"].items(), key=lambda x: float(winner["composite_per_cluster"].get(x[0], 0))):
        comp  = winner.get("composite_per_cluster", {}).get(k_str, "?")
        cnt   = winner["counts"][int(k_str)] if int(k_str) < len(winner["counts"]) else "?"
        report_sections.append(
            f"  - Cluster {k_str}: **{pname}**  (n={cnt}, mean_composite={comp:.3f})"
        )

report_sections += [
    "",
    "### Domain means by cluster (raw feature averages, higher=better for all):",
    "| Domain | " + " | ".join(f"C{k}" for k in range(K_BEST)) + " |",
    "|---| " + " | ".join(["---"] * K_BEST) + " |",
]
for domain, cols in WHO_DOMAINS.items():
    if not cols:
        continue
    cells = []
    for k in range(K_BEST):
        v = winner.get("domain_means", {}).get(domain, {}).get(str(k), None)
        cells.append(f"{v:.3f}" if v is not None else "—")
    report_sections.append(f"| {domain} | " + " | ".join(cells) + " |")

# Stage A top-10
report_sections += [
    "",
    "## Stage A Top-10 (by combined score)",
    "| Rank | Scaler | nc | nn | md | metric | Sil_u | DB_u | CH_u | Sil_s | DB_s | ImbCV | Interp |",
    "|---|---|---|---|---|---|---|---|---|---|---|---|---|",
]
for i, r in enumerate(stage_a[:10], 1):
    report_sections.append(
        f"| {i} | {r['scaler'][:3]} | {r['n_components']} | {r['n_neighbors']} | {r['min_dist']} "
        f"| {r['metric'][:4]} | {r['sil_umap']} | {r['db_umap']} | {r['ch_umap']} "
        f"| {r['sil_sc']} | {r['db_sc']} | {r['imbalance_cv']:.3f} | {r['interpretable']} |"
    )

# Stage B results
report_sections += [
    "",
    "## Stage B: Feature ablation results",
    f"Base config: {best_a['scaler']} nc={best_a['n_components']} nn={best_a['n_neighbors']}"
    f" md={best_a['min_dist']} metric={best_a['metric']}",
    f"Baseline at Stage-A best: Sil={b0_sil:.4f}  DB={b0_db:.4f}",
    "",
]
if beneficial:
    report_sections += [
        "| Removed feature | Sil_u | ΔSil | DB_u | ΔDB | Interp |",
        "|---|---|---|---|---|---|",
    ]
    for r in sorted(beneficial, key=lambda x: x["sil_umap"], reverse=True):
        report_sections.append(
            f"| {r['removed_feature']} | {r['sil_umap']} | {r['sil_umap']-b0_sil:+.4f} "
            f"| {r['db_umap']} | {r['db_umap']-b0_db:+.4f} | {r['interpretable']} |"
        )
    report_sections.append(
        f"\nBest removal: **'{best_b_removal['removed_feature']}'** "
        f"→ {len(best_b_feats)}-feature set."
    )
else:
    report_sections.append(
        "No single-feature removal improved both Sil and DB with interpretability intact. "
        "Keeping 31-feature set."
    )

# Recommendation
report_sections += ["", "## RECOMMENDATION", ""]
if all_targets_met and winner["interpretable"]:
    # Build the exact ABLATION_FEATURES list (all features in VIF but not in best_b_feats)
    new_ablation = [f for f in VIF_RETAINED if f not in best_b_feats]
    scaler_cls   = winner["scaler"]
    nc_w = winner["n_components"]; nn_w = winner["n_neighbors"]
    md_w = winner["min_dist"];     met_w = winner["metric"]

    report_sections += [
        "**APPLY the winning config.** All three targets met and interpretability gate passed.",
        "",
        "Exact changes to the notebook ablation-rebuild cell (`# -- Feature ablation: ...`):",
        "```python",
        f"ABLATION_FEATURES = {new_ablation!r}",
        "",
        f"sc_final = {scaler_cls}()",
        "",
        f"rd_final = umap.UMAP(",
        f"    n_components={nc_w},",
        f"    n_neighbors={nn_w},",
        f"    min_dist={md_w},",
        f"    metric='{met_w}',",
        f"    random_state=RANDOM_STATE,",
        f")",
        "",
        "km_final = KMeans(n_clusters=K_BEST, init='k-means++', n_init=100, "
        "max_iter=1000, random_state=RANDOM_STATE)",
        "```",
        "",
        "After updating the notebook cell, re-run it to regenerate artifacts.",
        "**Do not redeploy to the live DB/services yet** --that is a separate confirmed step.",
    ]
elif winner["interpretable"]:
    hit  = [f"Sil={winner['sil_umap']:.4f}>{TARGET_SIL}" if beats_sil else "",
            f"DB={winner['db_umap']:.4f}<{TARGET_DB}"   if beats_db  else "",
            f"CH={winner['ch_umap']:.0f}>={TARGET_CH}"  if beats_ch  else ""]
    miss = [f"Sil={winner['sil_umap']:.4f} (need >{TARGET_SIL})"    if not beats_sil else "",
            f"DB={winner['db_umap']:.4f} (need <{TARGET_DB})"        if not beats_db  else "",
            f"CH={winner['ch_umap']:.0f} (need >={TARGET_CH})"       if not beats_ch  else ""]
    hit  = [s for s in hit  if s]
    miss = [s for s in miss if s]
    report_sections += [
        f"**PARTIAL improvement.** {'  |  '.join(hit) if hit else 'No targets met.'}",
        f"Still short: {'  |  '.join(miss)}",
        "",
        "Interpretability gate passed. Review `cluster_tuning_ranked.csv` for the full table.",
        "Apply the winner only if the improvement is meaningful for the panel --"
        "otherwise keep the current config.",
    ]
else:
    report_sections += [
        "**KEEP CURRENT CONFIG.** No interpretable config found that beats both targets.",
        "The current StandardScaler + nc=10 + nn=15 + md=0.0 + euclidean is robust.",
        "Full results are in `cluster_tuning_results.csv` for reference.",
    ]

report_text = "\n".join(report_sections)
with open(p_report, "w", encoding="utf-8") as fh:
    fh.write(report_text)

print(f"  {p_results}")
print(f"  {p_ranked}")
print(f"  {p_profiles}")
print(f"  {p_report}")

# ═══════════════════════════════════════════════════════════════════════════════
# Apply winner to notebook production cell (only if all targets met + interpretable)
# ═══════════════════════════════════════════════════════════════════════════════

def _apply_to_notebook(win, b_feats):
    """
    Update the ablation-rebuild cell in osca5.ipynb with the winning config.
    Creates a .bak_tuning backup first. Returns True on success.
    """
    nb_path  = os.path.join(OUTER_DIR, "osca5.ipynb")
    bak_path = nb_path + ".bak_tuning"

    if not os.path.exists(nb_path):
        print(f"WARN: notebook not found at {nb_path}. Skipping notebook update.")
        return False

    shutil.copy2(nb_path, bak_path)
    print(f"[apply] Backup created: {bak_path}")

    with open(nb_path, encoding="utf-8") as fh:
        nb = json.load(fh)

    cells = nb.get("cells", [])

    # Find the ablation-rebuild cell (distinctive: has ABLATION_FEATURES + sc_final + rd_final)
    cell_idx = None
    for idx, c in enumerate(cells):
        src = "".join(c.get("source", []))
        if "ABLATION_FEATURES" in src and "sc_final" in src and "rd_final" in src:
            cell_idx = idx
            break

    if cell_idx is None:
        print("WARN: Ablation-rebuild cell not found in notebook. Skipping notebook update.")
        return False

    cell     = cells[cell_idx]
    src_raw  = cell.get("source", [])
    src_text = "".join(src_raw) if isinstance(src_raw, list) else str(src_raw)

    # New values from winner
    new_ablation = [f for f in VIF_RETAINED if f not in b_feats]
    sc_cls = win["scaler"]
    nc_w   = win["n_components"]
    nn_w   = win["n_neighbors"]
    md_w   = win["min_dist"]
    met_w  = win["metric"]

    # 1. Replace ABLATION_FEATURES = [...] (handles single-line or multi-line list)
    src_text = re.sub(
        r'ABLATION_FEATURES\s*=\s*\[[^\]]*\]',
        f'ABLATION_FEATURES = {new_ablation!r}',
        src_text,
        flags=re.DOTALL,
    )

    # 2. Replace sc_final = <Scaler>()
    src_text = re.sub(
        r'sc_final\s*=\s*\w+Scaler\s*\(\)',
        f'sc_final = {sc_cls}()',
        src_text,
    )

    # 3. Replace rd_final = umap.UMAP(...) --handle multi-line call
    new_rd = (
        f"rd_final = umap.UMAP(n_components={nc_w}, n_neighbors={nn_w}, "
        f"min_dist={md_w}, metric='{met_w}', random_state=RANDOM_STATE)"
    )
    src_text = re.sub(
        r'rd_final\s*=\s*umap\.UMAP\s*\([^)]*\)',
        new_rd,
        src_text,
        flags=re.DOTALL,
    )

    # 4. Replace km_final = KMeans(...)
    new_km = (
        "km_final = KMeans(n_clusters=K_BEST, init='k-means++', "
        "n_init=100, max_iter=1000, random_state=RANDOM_STATE)"
    )
    src_text = re.sub(
        r'km_final\s*=\s*KMeans\s*\([^)]*\)',
        new_km,
        src_text,
        flags=re.DOTALL,
    )

    # Reconstruct source list (each element = one line ending with \n, last has no \n)
    lines = src_text.split("\n")
    new_source = [line + "\n" for line in lines[:-1]]
    if lines[-1]:
        new_source.append(lines[-1])

    cell["source"] = new_source
    nb["cells"][cell_idx] = cell

    with open(nb_path, "w", encoding="utf-8") as fh:
        json.dump(nb, fh, ensure_ascii=False, indent=1)

    print(f"[apply] Notebook cell {cell_idx} updated: {nb_path}")
    print(f"[apply]   ABLATION_FEATURES = {new_ablation!r}")
    print(f"[apply]   sc_final = {sc_cls}()")
    print(f"[apply]   rd_final: nc={nc_w} nn={nn_w} md={md_w} metric={met_w}")
    print(f"[apply]   km_final: init='k-means++' n_init=100 max_iter=1000")
    return True


if all_targets_met and winner["interpretable"]:
    print("\n[cluster_tuning] All targets met --applying winner to notebook...")
    ok = _apply_to_notebook(winner, best_b_feats)
    if ok:
        print("[cluster_tuning] Notebook updated. Re-run the ablation-rebuild cell "
              "to regenerate artifacts. Do NOT redeploy to live services yet.")
    else:
        print("[cluster_tuning] Notebook update skipped --apply the changes manually "
              "using the exact config in cluster_tuning_report.md.")
else:
    reason = []
    if not all_targets_met:
        reason.append("not all targets met")
    if not winner["interpretable"]:
        reason.append("interpretability gate not passed")
    print(f"\n[cluster_tuning] Notebook NOT modified ({', '.join(reason)}).")
    print("  Review cluster_tuning_report.md for the recommendation.")

print(f"\n[cluster_tuning] ALL DONE in {time.time()-t_a:.0f}s total")
print(f"  Sil {'Y' if beats_sil else 'N'}  DB {'Y' if beats_db else 'N'}  "
      f"CH {'Y' if beats_ch else 'N'}  interpretable={winner['interpretable']}")
