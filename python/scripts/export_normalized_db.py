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
    "dob", "age", "barangay", "sex", "civil_status", "education",
    "monthly_income_range", "income_source", "specialization",
    "real_assets", "movable_assets", "community_service", "living_with",
    "household_condition", "has_medical_checkup", "checkup_schedule",
    "medical_concern", "dental_concern", "optical_concern", "hearing_concern",
    "social_emotional_concern", "problems_needs",
    "healthcare_difficulty", "housing_concern",
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

    env_path = os.path.join(BASE_DIR, ".env")
    env = _read_env(env_path)

    conn = pymysql.connect(
        host=env.get("DB_HOST", "127.0.0.1"),
        port=int(env.get("DB_PORT", 3306)),
        user=env.get("DB_USER", "root"),
        password=env.get("DB_PASSWORD", ""),
        database=env.get("DB_NAME", "osca"),
        cursorclass=pymysql.cursors.DictCursor,
    )

    try:
        with conn.cursor() as cur:
            cur.execute("SELECT * FROM seniors ORDER BY id")
            rows = cur.fetchall()
    finally:
        conn.close()

    print(f"[INFO] Fetched {len(rows)} seniors from DB")

    with open(OUT_CSV, "w", newline="", encoding="utf-8") as fh:
        writer = csv.DictWriter(fh, fieldnames=CSV_COLUMNS, extrasaction="ignore")
        writer.writeheader()
        for row in rows:
            out: dict = {}
            for col in CSV_COLUMNS:
                val = row.get(col)
                if col == "timestamp":
                    out[col] = fmt_timestamp(val)
                elif col in ("dob",):
                    out[col] = fmt_date(val)
                elif col in JSON_FIELDS:
                    out[col] = json_to_csv_str(val)
                elif col in ("has_medical_checkup",):
                    out[col] = fmt_bool(val)
                else:
                    out[col] = "" if val is None else str(val)
            writer.writerow(out)

    print(f"[INFO] Wrote {OUT_CSV}")


if __name__ == "__main__":
    main()
