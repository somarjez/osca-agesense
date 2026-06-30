"""
cluster_comparison_search.py
============================
Targeted StandardScaler-only sub-search + classifier-vs-nearest-centroid comparison.

Approach
--------
Stage A (SS): Load the existing Stage A checkpoint from cluster_tuning_search.py and
              filter for StandardScaler results (240 of the 720 already evaluated).
              If no checkpoint exists, run the 240 configs fresh (~10 min).

Stage B (SS): Feature ablation under best StandardScaler config.

Comparison:   For both the MinMaxScaler winner and the best StandardScaler config,
              evaluate two deployment methods:
                A. Nearest-centroid (Euclidean in the scaler's scaled space)
                B. Classifiers trained on batch UMAP labels:
                     RandomForestClassifier
                     GradientBoostingClassifier
                     LogisticRegression
                     KNeighborsClassifier (k=5)

Output
------
  osca_output/cluster_tuning/ss_comparison_report.md
  osca_output/cluster_tuning/ss_comparison_results.json
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

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
except (AttributeError, io.UnsupportedOperation):
    pass

# ── Paths ──────────────────────────────────────────────────────────────────────
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_DIR   = os.path.dirname(os.path.dirname(SCRIPT_DIR))
OUTER_DIR  = os.path.dirname(BASE_DIR)
sys.path.insert(0, os.path.join(BASE_DIR, "python", "services"))

import numpy as np
import pandas as pd
import umap
from sklearn.cluster    import KMeans
from sklearn.metrics    import silhouette_score, davies_bouldin_score, calinski_harabasz_score
from sklearn.preprocessing import StandardScaler, RobustScaler, MinMaxScaler
from sklearn.ensemble   import RandomForestClassifier, GradientBoostingClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.neighbors  import KNeighborsClassifier
from sklearn.model_selection import StratifiedKFold, cross_val_score

OUT_DIR = os.path.join(OUTER_DIR, "osca_output", "cluster_tuning")
os.makedirs(OUT_DIR, exist_ok=True)
print(f"[comparison] Output dir: {OUT_DIR}")

# ── Config ─────────────────────────────────────────────────────────────────────
def _read_dotenv(name):
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
    feat_config = json.load(fh)

VIF_RETAINED = feat_config["vif_retained"]         # 36 features
FINAL_FEATS  = feat_config["final_clustering_features"]  # 31 features
RANDOM_STATE = 42
K_BEST       = 4
MIN_CLUSTER_FRAC = 0.05

BASELINE_SIL = 0.4321
BASELINE_DB  = 0.8586
BASELINE_CH  = 496.4

MINMAX_WINNER = {
    "scaler": "MinMaxScaler",
    "n_components": 10, "n_neighbors": 10, "min_dist": 0.0, "metric": "euclidean",
    "features": [f for f in FINAL_FEATS if f != "env_income_limit_r"],  # 30 features
    "sil_umap": 0.5697, "db_umap": 0.6170, "ch_umap": 8714.54,
    "sil_sc": 0.081, "db_sc": 2.2328,
}

SCALERS_MAP = {
    "StandardScaler": StandardScaler,
    "RobustScaler":   RobustScaler,
    "MinMaxScaler":   MinMaxScaler,
}

NC_GRID  = [8, 10, 12, 15]
NN_GRID  = [10, 15, 20, 30, 40]
MD_GRID  = [0.0, 0.05, 0.1, 0.2]
MET_GRID = ["euclidean", "manhattan", "cosine"]

W_SIL = 1.0; W_DB = 1.0; W_CH = 0.5; W_IMB = 0.5

FORBIDDEN_FEATURES = frozenset({
    "composite_risk", "rule_composite", "risk_level", "risk_level_rule",
    "overall_risk_level", "km_cluster", "cluster_id", "cluster_named_id",
    "cluster_name", "priority_flag",
})

print(f"[comparison] VIF retained: {len(VIF_RETAINED)}  |  Final: {len(FINAL_FEATS)}")

# ── DB + feature loading ────────────────────────────────────────────────────────
import pymysql
import pymysql.cursors
from preprocess_service import preprocess  # noqa: E402

def _db_connect():
    env = {}
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
        host=env.get("DB_HOST", "127.0.0.1"), port=int(env.get("DB_PORT", 3306)),
        user=env.get("DB_USERNAME", "root"), password=env.get("DB_PASSWORD", ""),
        database=env.get("DB_DATABASE", "osca_db"),
        cursorclass=pymysql.cursors.DictCursor,
    )

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
        mr.rule_composite, mr.overall_risk_level, mr.cluster_named_id
    FROM ml_results mr
    JOIN senior_citizens sc ON sc.id = mr.senior_citizen_id
    JOIN qol_surveys     qs ON qs.senior_citizen_id = sc.id
    WHERE mr.deleted_at IS NULL AND mr.is_stale = 0
      AND mr.id = (SELECT MAX(id2.id) FROM ml_results id2
                   WHERE id2.senior_citizen_id = sc.id
                     AND id2.deleted_at IS NULL AND id2.is_stale = 0)
      AND sc.deleted_at IS NULL
    ORDER BY sc.id
"""

def _parse_json_col(val):
    if val is None: return []
    if isinstance(val, (list, dict)): return val
    try: return json.loads(val)
    except Exception: return []

def _compute_age(dob):
    if dob is None: return 70
    if isinstance(dob, str): dob = datetime.strptime(dob[:10], "%Y-%m-%d").date()
    today = date.today()
    return today.year - dob.year - ((today.month, today.day) < (dob.month, dob.day))

print("[comparison] Connecting to DB...")
t0 = time.time()
conn = _db_connect()
with conn.cursor() as cur:
    cur.execute(QUERY)
    rows = cur.fetchall()
conn.close()
print(f"[comparison]   Loaded {len(rows)} seniors ({time.time()-t0:.1f}s)")

print("[comparison] Building feature matrix...")
t0 = time.time()
feature_rows    = []
rule_composites = []
risk_levels     = []
db_clusters     = []
skipped = 0

for row in rows:
    qol = {
        "qol_enjoy_life": row["a1_enjoy_life"], "qol_life_satisfaction": row["a2_life_satisfaction"],
        "qol_future_outlook": row["a3_future_outlook"], "qol_meaningfulness": row["a4_meaningfulness"],
        "phy_energy": row["b1_physical_energy"], "phy_pain_r": row["b2_pain_discomfort"],
        "phy_health_limit_r": row["b3_health_self_care"], "phy_mobility_outside": row["b4_health_outside"],
        "phy_mobility_indoor": row["b5_mobility"],
        "psych_happiness": row["c1_happiness"], "psych_peace": row["c2_calm_peace"],
        "psych_lonely_r": row["c3_loneliness"], "psych_confidence": row["c4_confidence"],
        "func_independence": row["d1_independence"], "func_autonomy": row["d2_time_control"],
        "func_control": row["d3_life_control"], "env_income_limit_r": row["d4_income_limits"],
        "soc_social_support": row["e1_social_support"], "soc_close_friend": row["e2_close_person"],
        "soc_participation": row["e4_participation"], "soc_opportunity": row["e3_community_opportunities"],
        "soc_respect": row["e5_respect"],
        "env_safe_home": row["f1_home_safety"], "env_safe_neighborhood": row["f2_neighborhood_safety"],
        "env_service_access": row["f3_service_access"], "env_home_comfort": row["f4_home_comfort"],
        "env_fin_medical": row["g2_medical_afford"], "env_fin_household": row["g1_household_expenses"],
        "env_fin_personal": row["g3_personal_wants"],
        "spi_belief_comfort": row["h1_belief_comfort"], "spi_belief_practice": row["h2_belief_practice"],
    }
    raw = {
        "senior_id": row["id"], "first_name": row["first_name"], "last_name": row["last_name"],
        "barangay": row["barangay"], "age": _compute_age(row["date_of_birth"]),
        "gender": row["gender"], "marital_status": row["marital_status"],
        "educational_attainment": row["educational_attainment"],
        "monthly_income_range": row["monthly_income_range"],
        "num_children": row["num_children"] or 0,
        "num_working_children": row["num_working_children"] or 0,
        "household_size": row["household_size"] or 1,
        "child_financial_support": row["child_financial_support"],
        "spouse_working": row["spouse_working"],
        "income_source": _parse_json_col(row["income_source"]),
        "real_assets": _parse_json_col(row["real_assets"]),
        "movable_assets": _parse_json_col(row["movable_assets"]),
        "living_with": _parse_json_col(row["living_with"]),
        "household_condition": _parse_json_col(row["household_condition"]),
        "community_service": _parse_json_col(row["community_service"]),
        "specialization": _parse_json_col(row["specialization"]),
        "medical_concern": _parse_json_col(row["medical_concern"]),
        "dental_concern": _parse_json_col(row["dental_concern"]),
        "optical_concern": _parse_json_col(row["optical_concern"]),
        "hearing_concern": _parse_json_col(row["hearing_concern"]),
        "social_emotional_concern": _parse_json_col(row["social_emotional_concern"]),
        "healthcare_difficulty": _parse_json_col(row["healthcare_difficulty"]),
        "has_medical_checkup": bool(row["has_medical_checkup"])
                               and row["checkup_schedule"] != "No Follow-up",
        "qol_responses": qol,
    }
    try:
        result = preprocess(raw)
        fm = result.get("feature_map") or {}
        feature_rows.append({f: float(fm.get(f, 0.0)) for f in VIF_RETAINED})
        rule_composites.append(float(row.get("rule_composite") or 0.0))
        risk_levels.append(str(row.get("overall_risk_level") or "MODERATE"))
        db_clusters.append(int(row.get("cluster_named_id") or 1))
    except Exception as exc:
        print(f"  WARN skipped id={row['id']}: {exc}")
        skipped += 1

N = len(feature_rows)
df_feat            = pd.DataFrame(feature_rows)
rule_composite_arr = np.array(rule_composites)
risk_level_arr     = np.array(risk_levels)
print(f"[comparison]   {N} feature vectors, {skipped} skipped ({time.time()-t0:.1f}s)")

# Domain groups
PHY_COLS   = [c for c in ["phy_energy","phy_pain_r","phy_health_limit_r","phy_mobility_outside","phy_mobility_indoor"] if c in df_feat.columns]
PSYCH_COLS = [c for c in ["psych_happiness","psych_peace","psych_lonely_r","psych_confidence"] if c in df_feat.columns]
FUNC_COLS  = [c for c in ["func_independence","func_autonomy","func_control"] if c in df_feat.columns]
ENV_COLS   = [c for c in ["env_safe_home","env_safe_neighborhood","env_home_comfort","env_service_access"] if c in df_feat.columns]
FIN_COLS   = [c for c in ["env_income_limit_r","env_fin_household","env_fin_medical","env_fin_personal"] if c in df_feat.columns]
SOC_COLS   = [c for c in ["soc_social_support","soc_close_friend","soc_opportunity","soc_respect"] if c in df_feat.columns]
WHO_DOMAINS = {"phy": PHY_COLS, "psych": PSYCH_COLS, "func": FUNC_COLS, "env": ENV_COLS, "fin": FIN_COLS, "soc": SOC_COLS}

# ── Helpers ────────────────────────────────────────────────────────────────────

def _domain_cluster_means(labels):
    dm = {}
    for domain, cols in WHO_DOMAINS.items():
        if not cols: continue
        dm[domain] = {}
        for k in range(K_BEST):
            mask = labels == k
            dm[domain][str(k)] = float(df_feat.loc[mask, cols].mean(axis=1).mean()) if mask.sum() > 0 else 0.0
    return dm

def _risk_dist(labels):
    rd = {}
    for k in range(K_BEST):
        mask = labels == k
        rd[str(k)] = {lvl: int((risk_level_arr[mask] == lvl).sum()) for lvl in ["LOW", "MODERATE", "HIGH"]}
    return rd

def interpretability_gate(labels, composite_per_k, domain_means, too_small):
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
    if not all(composites[i] <= composites[i+1] for i in range(3)):
        return False, {}
    profile_map = {k_order[i]: PROFILE_NAMES[i] for i in range(4)}
    def _dmean(k, domains):
        vals = [domain_means.get(d, {}).get(str(k), 0.5) for d in domains if d in domain_means]
        return float(np.mean(vals)) if vals else 0.5
    k_low = k_order[0]; k_high = k_order[3]
    if _dmean(k_low, ["phy", "func"]) <= _dmean(k_high, ["phy", "func"]):
        return False, profile_map
    return True, profile_map

def evaluate_config(feat_names, scaler_name, nc, nn, md, metric):
    try:
        X_raw    = df_feat[feat_names].values
        sc       = SCALERS_MAP[scaler_name]()
        X_scaled = sc.fit_transform(X_raw)
        reducer  = umap.UMAP(n_components=nc, n_neighbors=nn, min_dist=md,
                             metric=metric, random_state=RANDOM_STATE)
        X_umap   = reducer.fit_transform(X_scaled)
        km       = KMeans(n_clusters=K_BEST, init="k-means++", n_init=100,
                          max_iter=1000, random_state=RANDOM_STATE)
        labels   = km.fit_predict(X_umap)
        sil_u    = float(silhouette_score(X_umap, labels))
        db_u     = float(davies_bouldin_score(X_umap, labels))
        ch_u     = float(calinski_harabasz_score(X_umap, labels))
        sil_s    = float(silhouette_score(X_scaled, labels))
        db_s     = float(davies_bouldin_score(X_scaled, labels))
        ch_s     = float(calinski_harabasz_score(X_scaled, labels))
        counts   = np.bincount(labels, minlength=K_BEST).tolist()
        imb_cv   = float(np.std(counts) / np.mean(counts))
        too_sm   = int(min(counts)) < int(N * MIN_CLUSTER_FRAC)
        comp_k   = {str(k): float(rule_composite_arr[labels == k].mean())
                    for k in range(K_BEST) if (labels == k).sum() > 0}
        dom_m    = _domain_cluster_means(labels)
        interp, profile_map = interpretability_gate(labels, comp_k, dom_m, too_sm)
        return {
            "scaler": scaler_name, "n_components": nc, "n_neighbors": nn,
            "min_dist": md, "metric": metric, "n_features": len(feat_names),
            "sil_umap": round(sil_u, 4), "db_umap": round(db_u, 4), "ch_umap": round(ch_u, 2),
            "sil_sc":   round(sil_s, 4), "db_sc":   round(db_s, 4), "ch_sc":   round(ch_s, 2),
            "imbalance_cv": round(imb_cv, 4), "counts": counts, "too_small": too_sm,
            "interpretable": interp, "profile_map": profile_map,
            "composite_per_cluster": comp_k, "domain_means": dom_m, "risk_dist": _risk_dist(labels),
            "labels": labels, "X_scaled": X_scaled,
        }
    except Exception as exc:
        print(f"  WARN evaluate_config failed: {exc}")
        return None

def _compute_combined(results):
    if not results: return
    sils = np.array([r["sil_umap"]     for r in results])
    dbs  = np.array([r["db_umap"]      for r in results])
    chs  = np.array([r["ch_umap"]      for r in results])
    imbs = np.array([r["imbalance_cv"] for r in results])
    def _z(val, arr):
        s = arr.std()
        return (val - arr.mean()) / s if s > 1e-9 else 0.0
    for r in results:
        r["combined"] = round(
            W_SIL * _z(r["sil_umap"], sils) - W_DB * _z(r["db_umap"], dbs)
            + W_CH * _z(r["ch_umap"], chs)  - W_IMB * _z(r["imbalance_cv"], imbs), 6)

# ── Classifier comparison helpers ──────────────────────────────────────────────
CV_FOLDS = StratifiedKFold(n_splits=5, shuffle=True, random_state=42)

CLASSIFIERS = {
    "RandomForest": RandomForestClassifier(
        n_estimators=200, max_depth=None, class_weight="balanced",
        random_state=42, n_jobs=-1),
    "GradientBoosting": GradientBoostingClassifier(
        n_estimators=200, learning_rate=0.1, max_depth=4,
        random_state=42),
    "LogisticRegression": LogisticRegression(
        max_iter=1000, multi_class="multinomial", solver="lbfgs",
        C=1.0, random_state=42),
    "KNN_k5": KNeighborsClassifier(n_neighbors=5, metric="euclidean"),
}

def nearest_centroid_agreement(X_scaled, labels):
    """
    For each sample, find the nearest cluster centroid in scaled space
    and compare to the UMAP-generated batch label.
    Returns the fraction that agree.
    """
    centroids = np.array([X_scaled[labels == k].mean(axis=0) for k in range(K_BEST)])
    dists     = np.linalg.norm(X_scaled[:, None, :] - centroids[None, :, :], axis=2)
    nc_labels = dists.argmin(axis=1)
    # Map by cluster identity (label integers may differ from centroid index)
    # Since centroids are built from label k, centroid index == label value
    agree = float((nc_labels == labels).mean())
    return agree, nc_labels

def classifier_cv_scores(X_scaled, labels):
    """Returns dict of classifier_name -> mean 5-fold CV accuracy on batch labels."""
    scores = {}
    for name, clf in CLASSIFIERS.items():
        cv_acc = cross_val_score(clf, X_scaled, labels, cv=CV_FOLDS,
                                 scoring="accuracy", n_jobs=1)
        scores[name] = {"mean": round(float(cv_acc.mean()), 4),
                        "std":  round(float(cv_acc.std()),  4)}
        print(f"    {name:20s}  CV acc={scores[name]['mean']:.4f} +/- {scores[name]['std']:.4f}")
    return scores

def full_evaluation(name, feat_names, scaler_name, nc, nn, md, metric):
    """Run UMAP+KMeans, nearest-centroid, and classifier CV. Returns full result dict."""
    print(f"\n[eval] {name}: {scaler_name} nc={nc} nn={nn} md={md} {metric} ({len(feat_names)} features)")
    t = time.time()
    res = evaluate_config(feat_names, scaler_name, nc, nn, md, metric)
    if res is None:
        print("  FAILED")
        return None
    labels   = res.pop("labels")
    X_scaled = res.pop("X_scaled")

    print(f"  UMAP: Sil={res['sil_umap']}  DB={res['db_umap']}  CH={res['ch_umap']}")
    print(f"  Scaled: Sil={res['sil_sc']}  DB={res['db_sc']}  Imb={res['imbalance_cv']:.3f}")
    print(f"  Counts={res['counts']}  Interp={res['interpretable']}")

    print(f"  Nearest-centroid agreement:")
    nc_agree, nc_labels = nearest_centroid_agreement(X_scaled, labels)
    print(f"    {nc_agree*100:.1f}%")

    print(f"  Classifier CV (5-fold):")
    clf_scores = classifier_cv_scores(X_scaled, labels)

    res["nc_agreement"]  = round(nc_agree, 4)
    res["clf_scores"]    = clf_scores
    res["eval_time_s"]   = round(time.time() - t, 1)
    res["config_name"]   = name
    res["feat_names"]    = feat_names
    return res

# ═══════════════════════════════════════════════════════════════════════════════
# Stage A (SS) -- StandardScaler sub-search
# ═══════════════════════════════════════════════════════════════════════════════
SS_CHECKPOINT  = os.path.join(OUT_DIR, "_stage_ss_checkpoint.json")
FULL_CHECKPOINT = os.path.join(OUT_DIR, "_stage_a_checkpoint.json")

stage_ss = []

if os.path.exists(SS_CHECKPOINT):
    print(f"\n[Stage A-SS] Loading SS checkpoint: {SS_CHECKPOINT}")
    with open(SS_CHECKPOINT, encoding="utf-8") as fh:
        stage_ss_raw = json.load(fh)
    # Re-create without labels/X_scaled (not stored in checkpoint)
    stage_ss = stage_ss_raw
    print(f"[Stage A-SS]   Loaded {len(stage_ss)} StandardScaler results from checkpoint.")

elif os.path.exists(FULL_CHECKPOINT):
    print(f"\n[Stage A-SS] Full Stage A checkpoint found -- extracting StandardScaler results")
    with open(FULL_CHECKPOINT, encoding="utf-8") as fh:
        full_checkpoint = json.load(fh)
    stage_ss = [r for r in full_checkpoint if r.get("scaler") == "StandardScaler"]
    print(f"[Stage A-SS]   Extracted {len(stage_ss)} StandardScaler results from full checkpoint.")
    # Recompute combined within SS only
    _compute_combined(stage_ss)
    stage_ss.sort(key=lambda r: r["combined"], reverse=True)
    with open(SS_CHECKPOINT, "w", encoding="utf-8") as fh:
        json.dump(stage_ss, fh)
    print(f"[Stage A-SS]   SS checkpoint saved.")

else:
    print(f"\n[Stage A-SS] No checkpoint found -- running 240 StandardScaler configs")
    total_ss = len(NC_GRID) * len(NN_GRID) * len(MD_GRID) * len(MET_GRID)
    done_ss  = 0
    t_ss     = time.time()
    for nc in NC_GRID:
        for nn in NN_GRID:
            for md in MD_GRID:
                for met in MET_GRID:
                    done_ss += 1
                    res = evaluate_config(FINAL_FEATS, "StandardScaler", nc, nn, md, met)
                    if res is not None:
                        res["stage"] = "SS_A"
                        res.pop("labels", None); res.pop("X_scaled", None)
                        stage_ss.append(res)
                    if done_ss % 40 == 0 or done_ss == total_ss:
                        elapsed = time.time() - t_ss
                        eta     = elapsed / done_ss * (total_ss - done_ss)
                        best_s  = max((r["sil_umap"] for r in stage_ss), default=0.0)
                        print(f"  [{done_ss:>3}/{total_ss}] {elapsed:>5.0f}s  ETA {eta:.0f}s"
                              f"  best_sil={best_s:.4f}  ok={len(stage_ss)}")
    _compute_combined(stage_ss)
    stage_ss.sort(key=lambda r: r["combined"], reverse=True)
    with open(SS_CHECKPOINT, "w", encoding="utf-8") as fh:
        json.dump([{k: v for k, v in r.items() if k not in ("labels", "X_scaled")}
                   for r in stage_ss], fh)
    print(f"[Stage A-SS] Complete -- {len(stage_ss)} configs in {time.time()-t_ss:.1f}s")

best_ss_a = stage_ss[0]
print(f"\n[Stage A-SS] Best StandardScaler config:")
print(f"  nc={best_ss_a['n_components']} nn={best_ss_a['n_neighbors']} md={best_ss_a['min_dist']} met={best_ss_a['metric']}")
print(f"  UMAP: Sil={best_ss_a['sil_umap']}  DB={best_ss_a['db_umap']}  CH={best_ss_a['ch_umap']}")
print(f"  Scaled: Sil={best_ss_a['sil_sc']}  DB={best_ss_a['db_sc']}")
print(f"  Interp={best_ss_a['interpretable']}  Counts={best_ss_a['counts']}")

# Top-10 interpretable SS configs
interp_ss = [r for r in stage_ss if r.get("interpretable")]
print(f"\n[Stage A-SS] Interpretable SS configs: {len(interp_ss)} / {len(stage_ss)}")
for i, r in enumerate(interp_ss[:10], 1):
    print(f"  [{i:>2}] nc={r['n_components']} nn={r['n_neighbors']} md={r['min_dist']}"
          f" {r['metric'][:4]}  Sil={r['sil_umap']}  DB={r['db_umap']}"
          f"  Sil_s={r['sil_sc']}  DB_s={r['db_sc']}  Imb={r['imbalance_cv']:.3f}")

# ── Stage B (SS): feature ablation under best SS config ────────────────────────
print(f"\n[Stage B-SS] Feature ablation under best SS config -- {len(FINAL_FEATS)+1} configs")
t_b = time.time()
stage_ss_b = []

res_b0 = evaluate_config(
    FINAL_FEATS, best_ss_a["scaler"],
    best_ss_a["n_components"], best_ss_a["n_neighbors"],
    best_ss_a["min_dist"], best_ss_a["metric"],
)
if res_b0:
    res_b0["stage"] = "SS_B_baseline"
    res_b0.pop("labels", None); res_b0.pop("X_scaled", None)
    stage_ss_b.append(res_b0)
b0_sil = res_b0["sil_umap"] if res_b0 else best_ss_a["sil_umap"]
b0_db  = res_b0["db_umap"]  if res_b0 else best_ss_a["db_umap"]

for fi, feat in enumerate(FINAL_FEATS):
    ablated = [f for f in FINAL_FEATS if f != feat]
    res = evaluate_config(
        ablated, best_ss_a["scaler"],
        best_ss_a["n_components"], best_ss_a["n_neighbors"],
        best_ss_a["min_dist"], best_ss_a["metric"],
    )
    if res is not None:
        res["stage"] = "SS_B"; res["removed_feature"] = feat
        res.pop("labels", None); res.pop("X_scaled", None)
        stage_ss_b.append(res)

print(f"[Stage B-SS] Complete in {time.time()-t_b:.1f}s")

beneficial_ss = [
    r for r in stage_ss_b
    if r.get("removed_feature") and r["sil_umap"] > b0_sil
    and r["db_umap"] < b0_db and r["interpretable"]
]
print(f"  Baseline: Sil={b0_sil:.4f}  DB={b0_db:.4f}")
print(f"  Beneficial removals: {len(beneficial_ss)}")
for r in sorted(beneficial_ss, key=lambda x: x["sil_umap"], reverse=True)[:5]:
    print(f"    Remove '{r['removed_feature']}': Sil={r['sil_umap']:.4f} ({r['sil_umap']-b0_sil:+.4f})"
          f"  DB={r['db_umap']:.4f} ({r['db_umap']-b0_db:+.4f})")

if beneficial_ss:
    best_ss_b = max(beneficial_ss, key=lambda r: r["sil_umap"])
    best_ss_feats = [f for f in FINAL_FEATS if f != best_ss_b["removed_feature"]]
    print(f"  Winner removal: '{best_ss_b['removed_feature']}' -> {len(best_ss_feats)} features")
else:
    best_ss_b     = None
    best_ss_feats = FINAL_FEATS.copy()
    print("  No beneficial removal found. Keeping 31-feature set.")

# ═══════════════════════════════════════════════════════════════════════════════
# Full evaluation with nearest-centroid + classifiers
# ═══════════════════════════════════════════════════════════════════════════════

# Best interpretable SS config (with feature ablation if any)
best_interp_ss = interp_ss[0] if interp_ss else best_ss_a
ss_eval_feats  = best_ss_feats

print("\n" + "="*70)
print("FULL EVALUATION: StandardScaler best")
print("="*70)
ss_result = full_evaluation(
    "StandardScaler_best",
    ss_eval_feats,
    best_interp_ss["scaler"],
    best_interp_ss["n_components"],
    best_interp_ss["n_neighbors"],
    best_interp_ss["min_dist"],
    best_interp_ss["metric"],
)

print("\n" + "="*70)
print("FULL EVALUATION: MinMaxScaler winner (from cluster_tuning_search)")
print("="*70)
mm_result = full_evaluation(
    "MinMaxScaler_winner",
    MINMAX_WINNER["features"],
    MINMAX_WINNER["scaler"],
    MINMAX_WINNER["n_components"],
    MINMAX_WINNER["n_neighbors"],
    MINMAX_WINNER["min_dist"],
    MINMAX_WINNER["metric"],
)

# Also evaluate original baseline for reference
print("\n" + "="*70)
print("FULL EVALUATION: Original baseline (StandardScaler nc=10 nn=15 md=0.0 euclidean 31 features)")
print("="*70)
baseline_result = full_evaluation(
    "Original_baseline",
    FINAL_FEATS,
    "StandardScaler",
    10, 15, 0.0, "euclidean",
)

# ═══════════════════════════════════════════════════════════════════════════════
# Save outputs
# ═══════════════════════════════════════════════════════════════════════════════

def _strip_arrays(d):
    """Remove numpy arrays/lists of labels before serializing."""
    return {k: v for k, v in d.items() if k not in ("labels", "X_scaled", "feat_names")}

comparison_json = {
    "generated_at":     datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ"),
    "n_seniors":        N,
    "baseline_result":  _strip_arrays(baseline_result) if baseline_result else None,
    "ss_result":        _strip_arrays(ss_result)        if ss_result        else None,
    "mm_result":        _strip_arrays(mm_result)        if mm_result        else None,
    "ss_top10_interp":  [
        {k: v for k, v in r.items() if k not in ("domain_means","risk_dist","profile_map","composite_per_cluster","labels","X_scaled","combined")}
        for r in interp_ss[:10]
    ],
}

p_json   = os.path.join(OUT_DIR, "ss_comparison_results.json")
p_report = os.path.join(OUT_DIR, "ss_comparison_report.md")

with open(p_json, "w", encoding="utf-8") as fh:
    json.dump(comparison_json, fh, indent=2, default=str)
print(f"\n[comparison] Saved: {p_json}")

# ── Markdown report ────────────────────────────────────────────────────────────
def _pct(v):
    return f"{v*100:.1f}%" if v is not None else "--"

def _clf_table(clf_scores):
    lines = ["| Classifier | CV Accuracy (5-fold) | Std |",
             "|---|---|---|"]
    for name, s in clf_scores.items():
        lines.append(f"| {name} | {_pct(s['mean'])} | {_pct(s['std'])} |")
    return lines

def _profile_section(res):
    if not res or not res.get("profile_map"):
        return ["_No profile data_"]
    lines = []
    pm = res["profile_map"]
    comp = res.get("composite_per_cluster", {})
    cnts = res.get("counts", [])
    for k_str, pname in sorted(pm.items(), key=lambda x: float(comp.get(x[0], 0))):
        n = cnts[int(k_str)] if int(k_str) < len(cnts) else "?"
        c = comp.get(k_str, "?")
        lines.append(f"  - Cluster {k_str}: **{pname}** (n={n}, mean_composite={c:.3f})")
    return lines

def _domain_table(res):
    if not res or not res.get("domain_means"):
        return ["_No domain data_"]
    lines = ["| Domain | C0 | C1 | C2 | C3 |", "|---| --- | --- | --- | --- |"]
    for domain in ["phy", "psych", "func", "env", "fin", "soc"]:
        dm = res["domain_means"].get(domain, {})
        cells = [f"{dm.get(str(k), 0):.3f}" for k in range(K_BEST)]
        lines.append(f"| {domain} | " + " | ".join(cells) + " |")
    return lines

def _risk_table(res):
    if not res or not res.get("risk_dist"):
        return ["_No risk data_"]
    rd = res["risk_dist"]
    lines = ["| Cluster | Profile | LOW | MODERATE | HIGH |", "|---|---|---|---|---|"]
    pm = res.get("profile_map", {})
    comp = res.get("composite_per_cluster", {})
    for k_str in sorted(rd.keys(), key=lambda x: float(comp.get(x, 0))):
        pname = pm.get(k_str, f"Cluster {k_str}")
        r = rd[k_str]
        lines.append(f"| {k_str} | {pname[:30]} | {r.get('LOW',0)} | {r.get('MODERATE',0)} | {r.get('HIGH',0)} |")
    return lines

report_lines = [
    "# StandardScaler Sub-Search vs MinMaxScaler Winner",
    f"Generated: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}  | Seniors: {N}",
    "",
    "## 1. Summary Comparison",
    "",
    "| Config | UMAP Sil | UMAP DB | UMAP CH | Sil_scaled | DB_scaled | NC Agree | Best Classifier | Interp |",
    "|---|---|---|---|---|---|---|---|---|",
]

def _best_clf(clf_scores):
    if not clf_scores: return "--"
    best = max(clf_scores.items(), key=lambda x: x[1]["mean"])
    return f"{best[0]} {_pct(best[1]['mean'])}"

configs_to_compare = [
    ("Original baseline",       baseline_result),
    ("SS best (this search)",   ss_result),
    ("MinMaxScaler winner",     mm_result),
]
for label, res in configs_to_compare:
    if not res:
        report_lines.append(f"| {label} | -- | -- | -- | -- | -- | -- | -- | -- |")
        continue
    report_lines.append(
        f"| {label} | {res['sil_umap']} | {res['db_umap']} | {res['ch_umap']} "
        f"| {res['sil_sc']} | {res['db_sc']} | {_pct(res.get('nc_agreement'))} "
        f"| {_best_clf(res.get('clf_scores',{}))} | {res.get('interpretable','?')} |"
    )

report_lines += [
    "",
    "---",
    "",
    "## 2. Original Baseline (StandardScaler nc=10 nn=15 md=0.0 euclidean 31 features)",
    "",
    f"**Config:** StandardScaler | nc=10 | nn=15 | md=0.0 | euclidean | 31 features",
    f"**UMAP:** Sil={BASELINE_SIL}  DB={BASELINE_DB}  CH={BASELINE_CH}",
]
if baseline_result:
    report_lines += [
        f"**Scaled:** Sil={baseline_result['sil_sc']}  DB={baseline_result['db_sc']}",
        f"**NC Agreement:** {_pct(baseline_result.get('nc_agreement'))}",
        f"**Counts:** {baseline_result['counts']}  **ImbalanceCV:** {baseline_result['imbalance_cv']:.3f}",
        "",
        "**Classifiers (5-fold CV on batch labels):**",
    ] + _clf_table(baseline_result.get("clf_scores", {})) + [
        "",
        "**Profiles:**",
    ] + _profile_section(baseline_result)

report_lines += [
    "",
    "---",
    "",
    "## 3. StandardScaler Best Config (this sub-search)",
    "",
]
if ss_result:
    rem = best_ss_b["removed_feature"] if best_ss_b else "none"
    report_lines += [
        f"**Config:** StandardScaler | nc={best_interp_ss['n_components']} | nn={best_interp_ss['n_neighbors']}"
        f" | md={best_interp_ss['min_dist']} | {best_interp_ss['metric']} | {len(ss_eval_feats)} features"
        f" (removed: {rem})",
        f"**UMAP:** Sil={ss_result['sil_umap']}  DB={ss_result['db_umap']}  CH={ss_result['ch_umap']}",
        f"**Scaled:** Sil={ss_result['sil_sc']}  DB={ss_result['db_sc']}",
        f"**NC Agreement:** {_pct(ss_result.get('nc_agreement'))}",
        f"**Counts:** {ss_result['counts']}  **ImbalanceCV:** {ss_result['imbalance_cv']:.3f}",
        f"**Interpretable:** {ss_result['interpretable']}",
        "",
        "**Classifiers (5-fold CV on batch labels):**",
    ] + _clf_table(ss_result.get("clf_scores", {})) + [
        "",
        "**Profiles:**",
    ] + _profile_section(ss_result) + [
        "",
        "**Domain means:**",
    ] + _domain_table(ss_result) + [
        "",
        "**Risk distribution:**",
    ] + _risk_table(ss_result) + [
        "",
        "**Top 10 interpretable SS configs (Stage A-SS):**",
        "| # | nc | nn | md | metric | Sil_u | DB_u | CH_u | Sil_s | DB_s | ImbCV |",
        "|---|---|---|---|---|---|---|---|---|---|---|",
    ] + [
        f"| {i} | {r['n_components']} | {r['n_neighbors']} | {r['min_dist']} | {r['metric'][:4]}"
        f" | {r['sil_umap']} | {r['db_umap']} | {r['ch_umap']}"
        f" | {r['sil_sc']} | {r['db_sc']} | {r['imbalance_cv']:.3f} |"
        for i, r in enumerate(interp_ss[:10], 1)
    ]

report_lines += [
    "",
    "---",
    "",
    "## 4. MinMaxScaler Winner (from cluster_tuning_search)",
    "",
]
if mm_result:
    report_lines += [
        f"**Config:** MinMaxScaler | nc=10 | nn=10 | md=0.0 | euclidean | 30 features (removed: env_income_limit_r)",
        f"**UMAP:** Sil={mm_result['sil_umap']}  DB={mm_result['db_umap']}  CH={mm_result['ch_umap']}",
        f"**Scaled:** Sil={mm_result['sil_sc']}  DB={mm_result['db_sc']}",
        f"**NC Agreement:** {_pct(mm_result.get('nc_agreement'))}",
        f"**Counts:** {mm_result['counts']}  **ImbalanceCV:** {mm_result['imbalance_cv']:.3f}",
        f"**Interpretable:** {mm_result['interpretable']}",
        "",
        "**Classifiers (5-fold CV on batch labels):**",
    ] + _clf_table(mm_result.get("clf_scores", {})) + [
        "",
        "**Profiles:**",
    ] + _profile_section(mm_result) + [
        "",
        "**Domain means:**",
    ] + _domain_table(mm_result) + [
        "",
        "**Risk distribution:**",
    ] + _risk_table(mm_result)

# Final recommendation
report_lines += ["", "---", "", "## 5. RECOMMENDATION", ""]

# Decision logic
ss_nc = ss_result["nc_agreement"] if ss_result else 0
mm_nc = mm_result["nc_agreement"] if mm_result else 0

ss_best_clf = max(ss_result["clf_scores"].values(), key=lambda x: x["mean"])["mean"] if ss_result and ss_result.get("clf_scores") else 0
mm_best_clf = max(mm_result["clf_scores"].values(), key=lambda x: x["mean"])["mean"] if mm_result and mm_result.get("clf_scores") else 0

ss_sil = ss_result["sil_umap"] if ss_result else 0
mm_sil = mm_result["sil_umap"] if mm_result else 0

beats_target = lambda r: r["sil_umap"] > 0.44 and r["db_umap"] < 0.84 and r["ch_umap"] >= 496 if r else False

if ss_result and beats_target(ss_result) and ss_result["interpretable"]:
    if ss_nc >= mm_nc - 0.02 or ss_best_clf >= mm_best_clf - 0.02:
        report_lines += [
            "**RECOMMENDATION: Use StandardScaler best config for production.**",
            "",
            f"The best interpretable StandardScaler config achieves adequate UMAP metrics"
            f" (Sil={ss_result['sil_umap']}, DB={ss_result['db_umap']}) AND substantially"
            f" better scaled-space separation (Sil_s={ss_result['sil_sc']} vs"
            f" {mm_result['sil_sc'] if mm_result else '?'} for MinMaxScaler).",
            f"NC agreement {_pct(ss_nc)} vs {_pct(mm_nc)} for MinMaxScaler.",
            f"Best classifier CV {_pct(ss_best_clf)} vs {_pct(mm_best_clf)} for MinMaxScaler.",
            "",
            "The StandardScaler config provides more reliable live nearest-centroid and classifier"
            " assignment, trading some UMAP visualization quality for production consistency.",
            "",
            "To apply: update notebook cell 37 with the StandardScaler config shown above,",
            "then re-run from cell 37 to regenerate artifacts.",
        ]
    else:
        report_lines += [
            "**RECOMMENDATION: Use classifier-based deployment with MinMaxScaler batch labels.**",
            "",
            f"StandardScaler meets targets (Sil={ss_result['sil_umap']}) but classifier CV on"
            f" MinMaxScaler labels ({_pct(mm_best_clf)}) is considerably higher than on"
            f" StandardScaler labels ({_pct(ss_best_clf)}), and UMAP quality is substantially better.",
            "",
            "Best path: keep the MinMaxScaler notebook config (already applied), but replace"
            " nearest-centroid inference with a trained RandomForest/GradientBoosting classifier.",
            "This preserves the clean UMAP cluster structure while achieving reliable production assignment.",
        ]
else:
    report_lines += [
        "**RECOMMENDATION: Use classifier-based deployment with MinMaxScaler batch labels.**",
        "",
        "No interpretable StandardScaler config beats all three targets.",
        f"MinMaxScaler winner (Sil={MINMAX_WINNER['sil_umap']}, DB={MINMAX_WINNER['db_umap']})"
        " has excellent cluster quality and interpretability, but weak nearest-centroid consistency.",
        "",
        "The classifier approach bridges this gap:",
        f"  - Batch (notebook): MinMaxScaler UMAP+KMeans -- clean cluster structure",
        f"  - Inference: RandomForest/GradientBoosting trained on batch labels"
        f" -- CV accuracy {_pct(mm_best_clf)}",
        "",
        "This decouples cluster generation quality from deployment assignment accuracy.",
    ]

report_text = "\n".join(report_lines)
with open(p_report, "w", encoding="utf-8") as fh:
    fh.write(report_text)
print(f"[comparison] Saved: {p_report}")

print("\n[comparison] ALL DONE")
print(f"  Baseline NC agreement: {_pct(baseline_result.get('nc_agreement') if baseline_result else None)}")
print(f"  SS best NC agreement:  {_pct(ss_nc)}")
print(f"  MM winner NC agreement:{_pct(mm_nc)}")
print(f"  SS best clf accuracy:  {_pct(ss_best_clf)}")
print(f"  MM winner clf accuracy:{_pct(mm_best_clf)}")
