"""
fix_cluster_distribution.py

Re-runs the full ML pipeline (preprocess -> UMAP -> KMeans -> infer) for ALL seniors
in one batch, using the same flow as the notebook, and updates ml_results in the DB.

Run from the project root:
    python python/fix_cluster_distribution.py

This ensures the cluster distribution matches the notebook exactly by using
the saved UMAP+KMeans models on the full senior population in a single transform call
(same as the notebook did), eliminating per-senior UMAP non-determinism.
"""

import os
import sys
import json
import traceback
import pymysql

# Set before any numba/umap import
os.environ.setdefault("NUMBA_THREADING_LAYER", "workqueue")
os.environ.setdefault("NUMBA_NUM_THREADS", "1")
os.environ.setdefault("OMP_NUM_THREADS", "1")

import numpy as np

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
SERVICES_DIR = os.path.join(BASE_DIR, "services")
sys.path.insert(0, SERVICES_DIR)

from preprocess_service import preprocess
from inference_service import (
    batch_cluster_assign, infer,
    _db_connect, _load_model, _load_first_model, _load_json,
    _safe_float, _clip01, _get_risk_level, _priority_flag,
    _load_cluster_mapping,
)


# ── DB helpers ────────────────────────────────────────────────────────────────

def read_env():
    env = {}
    for candidate in [
        os.path.join(BASE_DIR, ".env"),
        os.path.join(os.path.dirname(BASE_DIR), ".env"),
    ]:
        if os.path.exists(candidate):
            for line in open(candidate, encoding="utf-8"):
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                k, _, v = line.partition("=")
                env[k.strip()] = v.strip().strip('"').strip("'")
            break
    return env


def db_connect():
    env = read_env()
    return pymysql.connect(
        host=env.get("DB_HOST", "127.0.0.1"),
        port=int(env.get("DB_PORT", 3306)),
        user=env.get("DB_USERNAME", "root"),
        password=env.get("DB_PASSWORD", ""),
        database=env.get("DB_DATABASE", "osca_db"),
        autocommit=True,
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
    )


def fetch_all_seniors(conn):
    with conn.cursor() as cur:
        cur.execute("""
            SELECT
                sc.id, sc.first_name, sc.last_name, sc.barangay, sc.age,
                sc.gender, sc.marital_status, sc.educational_attainment,
                sc.monthly_income_range, sc.num_children, sc.num_working_children,
                sc.household_size, sc.child_financial_support, sc.spouse_working,
                sc.income_source, sc.real_assets, sc.movable_assets,
                sc.living_with, sc.household_condition, sc.community_service,
                sc.specialization, sc.medical_concern, sc.dental_concern,
                sc.optical_concern, sc.hearing_concern, sc.social_emotional_concern,
                sc.healthcare_difficulty, sc.has_medical_checkup, sc.checkup_schedule,
                qs.id as qol_id,
                qs.a1_enjoy_life,qs.a2_life_satisfaction,qs.a3_future_outlook,qs.a4_meaningfulness,
                qs.b1_physical_energy,qs.b2_pain_discomfort,qs.b3_health_self_care,qs.b4_health_outside,qs.b5_mobility,
                qs.c1_happiness,qs.c2_calm_peace,qs.c3_loneliness,qs.c4_confidence,
                qs.d1_independence,qs.d2_time_control,qs.d3_life_control,qs.d4_income_limits,
                qs.e1_social_support,qs.e2_close_person,qs.e3_community_opportunities,qs.e4_participation,qs.e5_respect,
                qs.f1_home_safety,qs.f2_neighborhood_safety,qs.f3_service_access,qs.f4_home_comfort,
                qs.g1_household_expenses,qs.g2_medical_afford,qs.g3_personal_wants,
                qs.h1_belief_comfort,qs.h2_belief_practice,
                mr.id as ml_result_id
            FROM senior_citizens sc
            JOIN (
                SELECT senior_citizen_id, MAX(id) as id FROM qol_surveys GROUP BY senior_citizen_id
            ) latest_qs ON latest_qs.senior_citizen_id = sc.id
            JOIN qol_surveys qs ON qs.id = latest_qs.id
            LEFT JOIN (
                SELECT senior_citizen_id, MAX(id) as id FROM ml_results GROUP BY senior_citizen_id
            ) latest_mr ON latest_mr.senior_citizen_id = sc.id
            LEFT JOIN ml_results mr ON mr.id = latest_mr.id
            WHERE (sc.status = 'active' OR sc.status IS NULL)
              AND sc.deleted_at IS NULL
            ORDER BY sc.id
        """)
        return cur.fetchall()


def parse_json_field(val):
    if val is None:
        return []
    if isinstance(val, (list, dict)):
        return val
    try:
        return json.loads(val)
    except Exception:
        return []


def build_payload(row):
    def q(col): return row[col] if row.get(col) is not None else 0
    qol_responses = {
        "qol_enjoy_life":        q("a1_enjoy_life"),
        "qol_life_satisfaction": q("a2_life_satisfaction"),
        "qol_future_outlook":    q("a3_future_outlook"),
        "qol_meaningfulness":    q("a4_meaningfulness"),
        "phy_energy":            q("b1_physical_energy"),
        "phy_pain_r":            q("b2_pain_discomfort"),
        "phy_health_limit_r":    q("b3_health_self_care"),
        "phy_mobility_outside":  q("b4_health_outside"),
        "phy_mobility_indoor":   q("b5_mobility"),
        "psych_happiness":       q("c1_happiness"),
        "psych_peace":           q("c2_calm_peace"),
        "psych_lonely_r":        q("c3_loneliness"),
        "psych_confidence":      q("c4_confidence"),
        "func_independence":     q("d1_independence"),
        "func_autonomy":         q("d2_time_control"),
        "func_control":          q("d3_life_control"),
        "env_income_limit_r":    q("d4_income_limits"),
        "soc_social_support":    q("e1_social_support"),
        "soc_close_friend":      q("e2_close_person"),
        "soc_participation":     q("e4_participation"),
        "soc_opportunity":       q("e3_community_opportunities"),
        "soc_respect":           q("e5_respect"),
        "env_safe_home":         q("f1_home_safety"),
        "env_safe_neighborhood": q("f2_neighborhood_safety"),
        "env_service_access":    q("f3_service_access"),
        "env_home_comfort":      q("f4_home_comfort"),
        "env_fin_medical":       q("g2_medical_afford"),
        "env_fin_household":     q("g1_household_expenses"),
        "env_fin_personal":      q("g3_personal_wants"),
        "spi_belief_comfort":    q("h1_belief_comfort"),
        "spi_belief_practice":   q("h2_belief_practice"),
    }
    has_checkup = bool(row["has_medical_checkup"]) and row.get("checkup_schedule") != "No Follow-up"
    return {
        "senior_id":               row["id"],
        "first_name":              row["first_name"],
        "last_name":               row["last_name"],
        "barangay":                row["barangay"],
        "age":                     row["age"],
        "gender":                  row["gender"],
        "marital_status":          row["marital_status"],
        "educational_attainment":  row["educational_attainment"],
        "monthly_income_range":    row["monthly_income_range"],
        "num_children":            row["num_children"] or 0,
        "num_working_children":    row["num_working_children"] or 0,
        "household_size":          row["household_size"] or 1,
        "child_financial_support": row["child_financial_support"],
        "spouse_working":          row["spouse_working"],
        "income_source":           parse_json_field(row["income_source"]),
        "real_assets":             parse_json_field(row["real_assets"]),
        "movable_assets":          parse_json_field(row["movable_assets"]),
        "living_with":             parse_json_field(row["living_with"]),
        "household_condition":     parse_json_field(row["household_condition"]),
        "community_service":       parse_json_field(row["community_service"]),
        "specialization":          parse_json_field(row["specialization"]),
        "medical_concern":         parse_json_field(row["medical_concern"]),
        "dental_concern":          parse_json_field(row["dental_concern"]),
        "optical_concern":         parse_json_field(row["optical_concern"]),
        "hearing_concern":         parse_json_field(row["hearing_concern"]),
        "social_emotional_concern":parse_json_field(row["social_emotional_concern"]),
        "healthcare_difficulty":   parse_json_field(row["healthcare_difficulty"]),
        "has_medical_checkup":     has_checkup,
        "qol_responses":           qol_responses,
    }


def update_ml_result(conn, ml_result_id, senior_id, result):
    cluster = result.get("cluster", {})
    scores  = result.get("risk_scores", {})
    levels  = result.get("risk_levels", {})
    domain  = result.get("domain_risks", {})
    who     = result.get("who_scores", {})
    recs    = result.get("recommendations", [])
    section = result.get("section_scores", {})

    with conn.cursor() as cur:
        if ml_result_id:
            cur.execute("""
                UPDATE ml_results SET
                    cluster_id        = %s,
                    cluster_named_id  = %s,
                    cluster_name      = %s,
                    composite_risk    = %s,
                    ic_risk           = %s,
                    env_risk          = %s,
                    func_risk         = %s,
                    wellbeing_score   = %s,
                    overall_risk_level = %s,
                    ic_risk_level     = %s,
                    env_risk_level    = %s,
                    func_risk_level   = %s,
                    priority_flag     = %s,
                    risk_medical      = %s,
                    risk_financial    = %s,
                    risk_social       = %s,
                    risk_functional   = %s,
                    risk_housing      = %s,
                    risk_hc_access    = %s,
                    risk_sensory      = %s,
                    rule_composite    = %s,
                    ic_score          = %s,
                    env_score         = %s,
                    func_score        = %s,
                    qol_score         = %s,
                    section_scores    = %s,
                    raw_output        = %s,
                    processed_at      = NOW()
                WHERE id = %s
            """, (
                cluster.get("raw_id"),
                cluster.get("named_id"),
                cluster.get("name"),
                scores.get("composite_risk"),
                scores.get("ic_risk"),
                scores.get("env_risk"),
                scores.get("func_risk"),
                scores.get("wellbeing_score"),
                levels.get("overall"),
                levels.get("ic"),
                levels.get("env"),
                levels.get("func"),
                result.get("priority_flag"),
                domain.get("risk_medical"),
                domain.get("risk_financial"),
                domain.get("risk_social"),
                domain.get("risk_functional"),
                domain.get("risk_housing"),
                domain.get("risk_hc_access"),
                domain.get("risk_sensory"),
                domain.get("rule_composite"),
                who.get("ic_score"),
                who.get("env_score"),
                who.get("func_score"),
                who.get("qol_score"),
                json.dumps(section),
                json.dumps(result),
                ml_result_id,
            ))
            # Refresh recommendations
            cur.execute("DELETE FROM recommendations WHERE ml_result_id = %s", (ml_result_id,))
            if recs:
                cur.executemany("""
                    INSERT INTO recommendations
                        (ml_result_id, senior_citizen_id, priority, type, domain, category, action, urgency, risk_level, notes, created_at, updated_at)
                    VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,NOW(),NOW())
                """, [
                    (ml_result_id, senior_id,
                     r.get("priority"), r.get("type"), r.get("domain"), r.get("category"),
                     r.get("action",""), r.get("urgency"), r.get("risk_level"),
                     r.get("reason"))
                    for r in recs
                ])
        return cur.rowcount


# ── Main ─────────────────────────────────────────────────────────────────────

def main():
    os.environ["OSCA_BATCH_MODE"] = "1"
    # Do NOT force ENABLE_NOTEBOOK_OVERRIDES here — let it be read from .env
    # so this script uses the same override setting as the Flask service.
    np.random.seed(42)

    # Pre-flight: warn if notebook overrides are off (scores will not match notebook)
    env = read_env()
    overrides_on = env.get("ENABLE_NOTEBOOK_OVERRIDES", "false").lower() in {"1", "true", "yes", "on"}
    csv_path = os.path.join(BASE_DIR, "models", "predictions", "senior_predictions.csv")
    csv_exists = os.path.exists(csv_path)
    if not overrides_on or not csv_exists:
        print("\n[WARNING] -------------------------------------------------------")
        if not overrides_on:
            print("  ENABLE_NOTEBOOK_OVERRIDES is not set to true in .env")
            print("  Scores will be computed by the live model (may not match notebook)")
        if not csv_exists:
            print(f"  senior_predictions.csv not found at:")
            print(f"  {csv_path}")
            print("  Copy it from osca_output/predictions/ before running this script")
        print("  To fix: set ENABLE_NOTEBOOK_OVERRIDES=true in .env and copy the CSV")
        print("-----------------------------------------------------------------\n")
        confirm = input("Continue anyway? (yes/no): ").strip().lower()
        if confirm not in {"yes", "y"}:
            print("Aborted.")
            sys.exit(0)
    else:
        print("[OK] ENABLE_NOTEBOOK_OVERRIDES=true and senior_predictions.csv found.")

    print("Connecting to database...")
    conn = db_connect()

    print("Fetching all active seniors with QoL surveys...")
    seniors = fetch_all_seniors(conn)
    print(f"Found {len(seniors)} seniors.")

    print("\nStep 1: Preprocessing all seniors...")
    payloads = []
    valid_seniors = []
    for row in seniors:
        try:
            payload = build_payload(row)
            preprocessed = preprocess(payload)
            preprocessed["senior_id"] = row["id"]
            payloads.append(preprocessed)
            valid_seniors.append(row)
        except Exception as e:
            print(f"  [SKIP] senior {row['id']} {row['first_name']} {row['last_name']}: {e}")

    print(f"Successfully preprocessed: {len(payloads)}")

    print("\nStep 2: Batch UMAP + KMeans (single transform for all seniors)...")
    try:
        warnings = batch_cluster_assign(payloads)
    except Exception as e:
        print(f"  [batch FATAL] {e}")
        traceback.print_exc()
        warnings = [f"batch KMeans path failed ({e}); heuristic fallback will be used."]
    for w in warnings:
        print(f"  [batch] {w}")

    # Step 3: Auto-correct the raw->named cluster mapping for this device.
    # UMAP.transform() can flip raw cluster ID orientation between devices.
    #
    # Calibration uses two signals in order:
    #   1. Cluster SIZE — the notebook always produces one large middle cluster (~134)
    #      flanked by two smaller clusters (~77-78).  The largest raw cluster = C2 (Moderate).
    #   2. Mean overall_wellbeing among the two small clusters — the higher-wellbeing
    #      small cluster = C1 (High Functioning), the lower = C3 (Low Functioning).
    #
    # This is more reliable than QoL survey means, which have a narrow spread between clusters.
    print("\nStep 3: Auto-calibrating cluster mapping for this device...")

    raw_sizes = {}
    raw_wellbeing_totals = {}
    for preprocessed in payloads:
        raw_id = preprocessed.get("_precomputed_raw_cluster_id")
        if raw_id is None:
            continue
        raw_sizes[raw_id] = raw_sizes.get(raw_id, 0) + 1
        ss = preprocessed.get("section_scores") or {}
        wb = ss.get("overall_wellbeing")
        if wb is not None:
            raw_wellbeing_totals.setdefault(raw_id, []).append(float(wb))

    model_dir = os.path.join(BASE_DIR, "models")
    mapping_path = os.path.join(model_dir, "cluster_mapping.json")

    print(f"  Raw cluster sizes: {raw_sizes}")

    if len(raw_sizes) == 3:
        # Largest cluster → named C2 (Moderate / Mixed Needs)
        c2_raw = max(raw_sizes, key=lambda k: raw_sizes[k])
        small_raws = [k for k in raw_sizes if k != c2_raw]

        # Among the two small clusters, higher mean wellbeing → C1, lower → C3
        mean_wb = {
            raw_id: (sum(raw_wellbeing_totals[raw_id]) / len(raw_wellbeing_totals[raw_id]))
            if raw_id in raw_wellbeing_totals else 0.0
            for raw_id in small_raws
        }
        print(f"  Small cluster mean wellbeing: { {k: round(v, 4) for k, v in mean_wb.items()} }")
        c1_raw = max(small_raws, key=lambda k: mean_wb[k])
        c3_raw = min(small_raws, key=lambda k: mean_wb[k])

        corrected_map = {c1_raw: 1, c2_raw: 2, c3_raw: 3}
        print(f"  Anchor: C2(Moderate)=raw{c2_raw}, C1(HighFunc)=raw{c1_raw}, C3(LowFunc)=raw{c3_raw}")
        print(f"  Corrected raw->named mapping: {corrected_map}")

        with open(mapping_path, "w") as f:
            json.dump({str(k): v for k, v in corrected_map.items()}, f, indent=2)
        print(f"  [ OK ] cluster_mapping.json updated.")
        # Clear lru_cache so subsequent infer() calls without _precomputed_named_id
        # read the corrected file instead of the stale startup-time cache.
        _load_cluster_mapping.cache_clear()
        # Inject named_id directly into each payload (bypasses mapping lookup entirely).
        for preprocessed in payloads:
            raw_id = preprocessed.get("_precomputed_raw_cluster_id")
            if raw_id is not None and raw_id in corrected_map:
                preprocessed["_precomputed_named_id"] = corrected_map[raw_id]
    else:
        print(f"  [WARN] Could not auto-calibrate (raw clusters found: {list(raw_sizes.keys())}). Using existing mapping.")

    print("\nStep 4: Running inference and updating DB...")
    cluster_counts = {1: 0, 2: 0, 3: 0}
    risk_counts = {"LOW": 0, "MODERATE": 0, "HIGH": 0}
    updated = 0
    skipped = 0

    for i, (preprocessed, row) in enumerate(zip(payloads, valid_seniors)):
        try:
            result = infer(preprocessed)
            named_id = result.get("cluster", {}).get("named_id", 0)
            risk = result.get("risk_levels", {}).get("overall", "")
            cluster_counts[named_id] = cluster_counts.get(named_id, 0) + 1
            risk_counts[risk] = risk_counts.get(risk, 0) + 1

            if row["ml_result_id"]:
                update_ml_result(conn, row["ml_result_id"], row["id"], result)
                updated += 1
            else:
                skipped += 1
                print(f"  [NO ML RESULT] senior {row['id']} — skipped (run batch analysis first)")

            if (i + 1) % 50 == 0:
                print(f"  Processed {i+1}/{len(payloads)}...")
        except Exception as e:
            print(f"  [ERROR] senior {row['id']}: {e}")
            skipped += 1

    conn.close()

    print(f"\n{'='*50}")
    print(f"Done. Updated: {updated}  Skipped: {skipped}")
    print(f"\nNew cluster distribution:")
    for c, n in sorted(cluster_counts.items()):
        print(f"  C{c}: {n}")
    print(f"\nNew risk distribution:")
    for r, n in sorted(risk_counts.items()):
        print(f"  {r}: {n}")


if __name__ == "__main__":
    main()
