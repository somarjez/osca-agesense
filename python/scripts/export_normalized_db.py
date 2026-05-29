"""
export_normalized_db.py
=======================
Reads all seniors from the normalized MySQL DB and writes osca_normalized.csv
to the notebook directory (alongside osca.csv) in the exact column format
that osca5.ipynb expects.

Run from repo root:
    python\\venv\\Scripts\\python.exe python\\scripts\\export_normalized_db.py
"""

import os
import sys
import csv
import json
from datetime import date, datetime
from typing import Any

# ── Paths ──────────────────────────────────────────────────────────────────────
# __file__ = .../osca-system/osca-system/python/scripts/export_normalized_db.py
# Three dirname calls:
#   dirname(__file__)             → python/scripts/
#   dirname(dirname(__file__))    → python/
#   dirname * 3                   → repo root (osca-system/osca-system)  ← BASE_DIR
#   dirname(BASE_DIR)             → osca-system/                          ← NOTEBOOK_DIR (where osca.csv lives)
BASE_DIR     = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
NOTEBOOK_DIR = os.path.dirname(BASE_DIR)
OUT_CSV      = os.path.join(NOTEBOOK_DIR, "osca_normalized.csv")


# ── .env reader ────────────────────────────────────────────────────────────────
def _read_env(path: str) -> dict:
    env: dict = {}
    if not os.path.exists(path):
        return env
    with open(path, encoding="utf-8") as fh:
        for line in fh:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, _, v = line.partition("=")
            env[k.strip()] = v.strip().strip('"').strip("'")
    return env


# ── Helpers (unit-tested) ──────────────────────────────────────────────────────
JSON_FIELDS = [
    "medical_concern", "income_source", "real_assets", "movable_assets",
    "living_with", "community_service", "household_condition", "specialization",
    "social_emotional_concern", "problems_needs",
]

QOL_COLS = [
    "qol_enjoy_life", "qol_life_satisfaction", "qol_future_outlook", "qol_meaningfulness",
    "phy_energy", "phy_pain_r", "phy_health_limit_r", "phy_mobility_outside", "phy_mobility_indoor",
    "psych_happiness", "psych_peace", "psych_lonely_r", "psych_confidence",
    "func_independence", "func_autonomy", "func_control", "env_income_limit_r",
    "soc_social_support", "soc_close_friend", "soc_participation", "soc_opportunity", "soc_respect",
    "env_safe_home", "env_safe_neighborhood", "env_service_access", "env_home_comfort",
    "env_fin_medical", "env_fin_household", "env_fin_personal",
    "spi_belief_comfort", "spi_belief_practice",
]

CSV_COLUMNS = [
    "timestamp", "first_name", "last_name", "middle_name",
    "dob", "age", "barangay", "gender", "marital_status", "education",
    "monthly_income_range", "income_source", "specialization",
    "real_assets", "movable_assets", "community_service", "living_with",
    "household_condition", "has_medical_checkup", "checkup_schedule",
    "medical_concern", "dental_concern", "optical_concern", "hearing_concern",
    "social_emotional_concern", "problems_needs",
    "healthcare_difficulty", "housing_concern",
    "household_size", "num_children", "num_working_children",
    "child_financial_support", "spouse_working",
] + QOL_COLS


def json_to_csv_str(value: Any) -> str:
    """Convert a JSON array string or Python list to comma-delimited string."""
    if value is None:
        return ""
    if isinstance(value, (list, tuple)):
        return ", ".join(str(v) for v in value if str(v).strip())
    s = str(value).strip()
    if s.startswith("["):
        try:
            parsed = json.loads(s)
            return ", ".join(str(v) for v in parsed if str(v).strip())
        except json.JSONDecodeError as exc:
            print(f"  [WARN] JSON parse failed for {s!r}: {exc} — using raw string")
            return s
    return s


def fmt_date(value: Any) -> str:
    """Format a date/datetime object as m/d/Y (no zero-padding, cross-platform)."""
    if value is None:
        return ""
    if isinstance(value, datetime):
        d = value.date()
    elif isinstance(value, date):
        d = value
    else:
        return str(value)
    return f"{d.month}/{d.day}/{d.year}"


def fmt_timestamp(value: Any) -> str:
    """Format a date/datetime as m/d/Y H:MM (cross-platform, no zero-padding on month/day)."""
    if value is None:
        return ""
    if isinstance(value, datetime):
        return f"{value.month}/{value.day}/{value.year} {value.hour}:{value.minute:02d}"
    if isinstance(value, date):
        return f"{value.month}/{value.day}/{value.year} 0:00"
    return str(value)


def fmt_bool(value: Any) -> str:
    """Convert tinyint/bool/None to 'Yes' or 'No'."""
    if value is None:
        return "No"
    if isinstance(value, bool):
        return "Yes" if value else "No"
    try:
        return "Yes" if int(value) else "No"
    except (TypeError, ValueError):
        return "No"


def main() -> None:
    try:
        import pymysql
        import pymysql.cursors
    except ImportError:
        print("[ERROR] pymysql not installed. Run: python\\venv\\Scripts\\pip.exe install pymysql")
        sys.exit(1)

    # ── Load .env ──────────────────────────────────────────────────────────────
    env: dict = {}
    for cand in [os.path.join(BASE_DIR, ".env"), os.path.join(NOTEBOOK_DIR, ".env")]:
        if os.path.exists(cand):
            env = _read_env(cand)
            break
    if not env:
        print(f"[ERROR] .env file not found or contains no valid key=value pairs. Tried:\n  {BASE_DIR}\\.env\n  {NOTEBOOK_DIR}\\.env")
        sys.exit(1)

    # ── Connect ────────────────────────────────────────────────────────────────
    try:
        conn = pymysql.connect(
            host=env.get("DB_HOST", "127.0.0.1"),
            port=int(env.get("DB_PORT", 3306)),
            user=env.get("DB_USERNAME", "root"),
            password=env.get("DB_PASSWORD", ""),
            database=env.get("DB_DATABASE", "osca_db"),
            cursorclass=pymysql.cursors.DictCursor,
        )
    except Exception as exc:
        print(f"[ERROR] DB connection failed: {exc}")
        print(f"  Check: DB_HOST={env.get('DB_HOST')}  DB_PORT={env.get('DB_PORT')}"
              f"  DB_DATABASE={env.get('DB_DATABASE')}  DB_USERNAME={env.get('DB_USERNAME')}")
        sys.exit(1)

    # ── Query — latest QoL row per senior via MAX(id) subquery ─────────────────
    # DB column → CSV feature-name alias mapping (matches QolSurvey.toFeatureArray)
    QOL_DB_MAP = [
        ("a1_enjoy_life",           "qol_enjoy_life"),
        ("a2_life_satisfaction",    "qol_life_satisfaction"),
        ("a3_future_outlook",       "qol_future_outlook"),
        ("a4_meaningfulness",       "qol_meaningfulness"),
        ("b1_physical_energy",      "phy_energy"),
        ("b2_pain_discomfort",      "phy_pain_r"),
        ("b3_health_self_care",     "phy_health_limit_r"),
        ("b4_health_outside",       "phy_mobility_outside"),
        ("b5_mobility",             "phy_mobility_indoor"),
        ("c1_happiness",            "psych_happiness"),
        ("c2_calm_peace",           "psych_peace"),
        ("c3_loneliness",           "psych_lonely_r"),
        ("c4_confidence",           "psych_confidence"),
        ("d1_independence",         "func_independence"),
        ("d2_time_control",         "func_autonomy"),
        ("d3_life_control",         "func_control"),
        ("d4_income_limits",        "env_income_limit_r"),
        ("e1_social_support",       "soc_social_support"),
        ("e2_close_person",         "soc_close_friend"),
        ("e4_participation",        "soc_participation"),
        ("e3_community_opportunities", "soc_opportunity"),
        ("e5_respect",              "soc_respect"),
        ("f1_home_safety",          "env_safe_home"),
        ("f2_neighborhood_safety",  "env_safe_neighborhood"),
        ("f3_service_access",       "env_service_access"),
        ("f4_home_comfort",         "env_home_comfort"),
        ("g2_medical_afford",       "env_fin_medical"),
        ("g1_household_expenses",   "env_fin_household"),
        ("g3_personal_wants",       "env_fin_personal"),
        ("h1_belief_comfort",       "spi_belief_comfort"),
        ("h2_belief_practice",      "spi_belief_practice"),
    ]
    qol_select = ",\n            ".join(
        f"qs.{db_col} AS {feat_name}" for db_col, feat_name in QOL_DB_MAP
    )
    sql = f"""
        SELECT
            sc.id               AS senior_id,
            sc.first_name,
            sc.last_name,
            sc.middle_name,
            sc.date_of_birth,
            qs.survey_date,
            TIMESTAMPDIFF(YEAR, sc.date_of_birth, CURDATE()) AS age,
            sc.barangay,
            sc.gender,
            sc.marital_status,
            sc.educational_attainment,
            sc.monthly_income_range,
            sc.medical_concern,
            sc.income_source,
            sc.real_assets,
            sc.movable_assets,
            sc.living_with,
            sc.community_service,
            sc.household_condition,
            sc.specialization,
            sc.social_emotional_concern,
            sc.problems_needs,
            sc.dental_concern,
            sc.optical_concern,
            sc.hearing_concern,
            sc.has_medical_checkup,
            sc.checkup_schedule,
            sc.healthcare_difficulty,
            sc.household_size,
            sc.num_children,
            sc.num_working_children,
            sc.child_financial_support,
            sc.spouse_working,
            {qol_select}
        FROM senior_citizens sc
        LEFT JOIN (
            SELECT qs2.*
            FROM qol_surveys qs2
            INNER JOIN (
                SELECT senior_citizen_id, MAX(id) AS max_id
                FROM qol_surveys
                WHERE deleted_at IS NULL
                GROUP BY senior_citizen_id
            ) latest ON qs2.id = latest.max_id
        ) qs ON qs.senior_citizen_id = sc.id
        WHERE sc.deleted_at IS NULL
        ORDER BY sc.last_name, sc.first_name
    """

    try:
        with conn.cursor() as cur:
            cur.execute(sql)
            rows = cur.fetchall()
    finally:
        conn.close()

    print(f"Fetched {len(rows)} seniors from DB.")
    if len(rows) == 0:
        print("[ERROR] No seniors returned — check DB connection and that seeder has run.")
        sys.exit(1)

    # ── Write CSV ──────────────────────────────────────────────────────────────
    no_qol_seniors: list = []

    with open(OUT_CSV, "w", newline="", encoding="utf-8-sig") as fh:
        writer = csv.DictWriter(fh, fieldnames=CSV_COLUMNS)
        writer.writeheader()

        for row in rows:
            has_qol = row.get("survey_date") is not None
            if not has_qol:
                name = f"{row.get('first_name', '')} {row.get('last_name', '')}".strip()
                no_qol_seniors.append(f"  {name} (id={row.get('senior_id')})")

            # Build output row — DB column names → CSV column names
            out: dict = {
                "timestamp":             fmt_timestamp(row.get("survey_date")),
                "first_name":            row.get("first_name", "") or "",
                "last_name":             row.get("last_name", "") or "",
                "middle_name":           row.get("middle_name", "") or "",
                "dob":                   fmt_date(row.get("date_of_birth")),
                "age":                   row.get("age", "") if row.get("age") is not None else "",
                "barangay":              row.get("barangay", "") or "",
                "gender":                row.get("gender", "") or "",
                "marital_status":        row.get("marital_status", "") or "",
                "education":             row.get("educational_attainment", "") or "",
                "monthly_income_range":  row.get("monthly_income_range", "") or "",
                "has_medical_checkup":   fmt_bool(row.get("has_medical_checkup")),
                "checkup_schedule":      row.get("checkup_schedule", "") or "",
                "dental_concern":        json_to_csv_str(row.get("dental_concern")),
                "optical_concern":       json_to_csv_str(row.get("optical_concern")),
                "hearing_concern":       json_to_csv_str(row.get("hearing_concern")),
                "healthcare_difficulty": json_to_csv_str(row.get("healthcare_difficulty")),
                "housing_concern":       "",
                "household_size":        row.get("household_size") if row.get("household_size") is not None else "",
                "num_children":          row.get("num_children") if row.get("num_children") is not None else "",
                "num_working_children":  row.get("num_working_children") if row.get("num_working_children") is not None else "",
                "child_financial_support": str(row.get("child_financial_support") or ""),
                "spouse_working":        str(row.get("spouse_working") or ""),
            }

            # JSON multiselect fields → comma-delimited strings
            for field in JSON_FIELDS:
                out[field] = json_to_csv_str(row.get(field))

            # QoL numeric columns — blank string if NULL (senior has no survey row)
            for col in QOL_COLS:
                val = row.get(col)
                out[col] = "" if val is None else val

            writer.writerow(out)

    # ── Summary ────────────────────────────────────────────────────────────────
    print(f"\n{'='*60}")
    print(f"Export complete -> {OUT_CSV}")
    print(f"  Total seniors exported:   {len(rows)}")
    print(f"  With QoL data:            {len(rows) - len(no_qol_seniors)}")
    print(f"  Missing QoL (blanks):     {len(no_qol_seniors)}")
    if no_qol_seniors:
        for s in no_qol_seniors:
            print(s)
    print(f"{'='*60}")
    print("\nNext steps:")
    print("  1. Open osca5.ipynb in Jupyter")
    print('  2. Change: df_raw = pd.read_csv("osca.csv")')
    print('         to: df_raw = pd.read_csv("osca_normalized.csv")')
    print("  3. Kernel -> Restart & Run All")


if __name__ == "__main__":
    main()
