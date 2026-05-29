# DB / Notebook Alignment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Set `ENABLE_NOTEBOOK_OVERRIDES=false` so the live model runs independently, export normalized DB data to CSV, re-run `osca5.ipynb` on clean data, then validate live model alignment against notebook predictions.

**Architecture:** A new export script reads MySQL (normalized values post-seeder-fix) and writes `osca_normalized.csv` alongside `osca.csv`. The notebook is pointed at this file instead. The live Flask pipeline is the sole inference source; the notebook predictions become a comparison baseline only.

**Tech Stack:** Python 3, pymysql, csv, pytest, Flask (preprocess_service + inference_service), Jupyter Notebook, Laravel `.env`

---

### Task 1: Disable notebook overrides in `.env` and restart Flask

**Files:**
- Modify: `.env` (repo root: `C:\Users\jramo\OneDrive\Desktop\02. AgeSense\osca-system\osca-system\.env`)

- [ ] **Step 1: Check current `.env` for the key**

Open `.env` and search for `ENABLE_NOTEBOOK_OVERRIDES`. It may not exist yet (the code defaults to `true` when absent).

- [ ] **Step 2: Add or update the key**

Open `.env` in a text editor and add this line (or change existing value to `false`):

```
ENABLE_NOTEBOOK_OVERRIDES=false
```

Place it near other `ML_*` or `ENABLE_*` lines if any exist, otherwise append at the end of the file.

- [ ] **Step 3: Restart both Flask services**

Stop any running instances of `preprocess_service.py` and `inference_service.py` (use Task Manager → find `python.exe` processes, or close the terminal windows they run in).

Restart them from the repo root:

```bat
start "Preprocess" python\venv\Scripts\python.exe python\services\preprocess_service.py
start "Inference"  python\venv\Scripts\python.exe python\services\inference_service.py
```

Wait 5 seconds for Flask to initialise.

- [ ] **Step 4: Verify the flag is active**

```bat
python\venv\Scripts\python.exe -c "import sys; sys.path.insert(0, 'python/services'); from inference_service import ENABLE_NOTEBOOK_OVERRIDES; print('ENABLE_NOTEBOOK_OVERRIDES =', ENABLE_NOTEBOOK_OVERRIDES)"
```

Expected output:
```
ENABLE_NOTEBOOK_OVERRIDES = False
```

If it still shows `True`, the `.env` file was not saved correctly — re-check the file and the path.

- [ ] **Step 5: Commit**

```bat
git add .env
git commit -m "config: disable notebook overrides so live model runs independently"
```

---

### Task 2: Write helper function tests for the export script

**Files:**
- Create: `python/scripts/tests/test_export_helpers.py`

- [ ] **Step 1: Create the test file**

Create `python\scripts\tests\test_export_helpers.py` with this content:

```python
"""
Unit tests for export_normalized_db helper functions.
Run: python\venv\Scripts\python.exe -m pytest python\scripts\tests\test_export_helpers.py -v
"""
import sys, os
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from datetime import date, datetime

# ── json_to_csv_str ───────────────────────────────────────────────────────────
def test_json_array_string_converts_to_comma_string():
    from export_normalized_db import json_to_csv_str
    result = json_to_csv_str('["Hypertension", "Diabetes"]')
    assert result == "Hypertension, Diabetes"

def test_python_list_converts_to_comma_string():
    from export_normalized_db import json_to_csv_str
    result = json_to_csv_str(["Hypertension", "Diabetes"])
    assert result == "Hypertension, Diabetes"

def test_empty_json_array_returns_empty_string():
    from export_normalized_db import json_to_csv_str
    assert json_to_csv_str("[]") == ""

def test_none_returns_empty_string():
    from export_normalized_db import json_to_csv_str
    assert json_to_csv_str(None) == ""

def test_plain_string_returned_as_is():
    from export_normalized_db import json_to_csv_str
    assert json_to_csv_str("Hypertension") == "Hypertension"

def test_malformed_json_returned_as_raw_string():
    from export_normalized_db import json_to_csv_str
    result = json_to_csv_str("[broken")
    assert result == "[broken"

# ── fmt_date ──────────────────────────────────────────────────────────────────
def test_date_object_formats_as_m_d_Y():
    from export_normalized_db import fmt_date
    assert fmt_date(date(1950, 5, 24)) == "5/24/1950"

def test_date_single_digit_month_no_zero_pad():
    from export_normalized_db import fmt_date
    assert fmt_date(date(1947, 3, 7)) == "3/7/1947"

def test_date_none_returns_empty():
    from export_normalized_db import fmt_date
    assert fmt_date(None) == ""

# ── fmt_timestamp ─────────────────────────────────────────────────────────────
def test_datetime_formats_as_m_d_Y_H_MM():
    from export_normalized_db import fmt_timestamp
    assert fmt_timestamp(datetime(2024, 3, 15, 9, 5)) == "3/15/2024 9:05"

def test_date_only_appends_0_00():
    from export_normalized_db import fmt_timestamp
    assert fmt_timestamp(date(2024, 3, 15)) == "3/15/2024 0:00"

def test_timestamp_none_returns_empty():
    from export_normalized_db import fmt_timestamp
    assert fmt_timestamp(None) == ""

# ── fmt_bool ──────────────────────────────────────────────────────────────────
def test_truthy_int_returns_yes():
    from export_normalized_db import fmt_bool
    assert fmt_bool(1) == "Yes"

def test_zero_returns_no():
    from export_normalized_db import fmt_bool
    assert fmt_bool(0) == "No"

def test_none_returns_no():
    from export_normalized_db import fmt_bool
    assert fmt_bool(None) == "No"

def test_true_bool_returns_yes():
    from export_normalized_db import fmt_bool
    assert fmt_bool(True) == "Yes"
```

- [ ] **Step 2: Run tests — confirm they fail (module not found)**

```bat
python\venv\Scripts\python.exe -m pytest python\scripts\tests\test_export_helpers.py -v
```

Expected: All tests FAIL with `ModuleNotFoundError: No module named 'export_normalized_db'`

---

### Task 3: Implement export helper functions

**Files:**
- Create: `python/scripts/export_normalized_db.py` (helpers section only)

- [ ] **Step 1: Create the script with helpers**

Create `python\scripts\export_normalized_db.py`:

```python
"""
export_normalized_db.py
=======================
Reads all seniors from the normalized MySQL DB and writes osca_normalized.csv
to the notebook directory (alongside osca.csv) in the exact column format
that osca5.ipynb expects.

Run from repo root:
    python\venv\Scripts\python.exe python\scripts\export_normalized_db.py
"""

import os
import sys
import csv
import json
from datetime import date, datetime
from typing import Any

try:
    import pymysql
    import pymysql.cursors
except ImportError:
    print("[ERROR] pymysql not installed. Run: python\\venv\\Scripts\\pip.exe install pymysql")
    sys.exit(1)

# ── Paths ──────────────────────────────────────────────────────────────────────
# Script is at: python/scripts/export_normalized_db.py
# BASE_DIR  = repo root (osca-system/osca-system)
# NOTEBOOK_DIR = parent dir where osca.csv and osca5.ipynb live
BASE_DIR     = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
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
```

- [ ] **Step 2: Run tests — confirm they pass**

```bat
python\venv\Scripts\python.exe -m pytest python\scripts\tests\test_export_helpers.py -v
```

Expected output (all green):
```
PASSED test_json_array_string_converts_to_comma_string
PASSED test_python_list_converts_to_comma_string
PASSED test_empty_json_array_returns_empty_string
PASSED test_none_returns_empty_string
PASSED test_plain_string_returned_as_is
PASSED test_malformed_json_returned_as_raw_string
PASSED test_date_object_formats_as_m_d_Y
PASSED test_date_single_digit_month_no_zero_pad
PASSED test_date_none_returns_empty
PASSED test_datetime_formats_as_m_d_Y_H_MM
PASSED test_date_only_appends_0_00
PASSED test_timestamp_none_returns_empty
PASSED test_truthy_int_returns_yes
PASSED test_zero_returns_no
PASSED test_none_returns_no
PASSED test_true_bool_returns_yes
```

- [ ] **Step 3: Commit helpers**

```bat
git add python\scripts\export_normalized_db.py python\scripts\tests\test_export_helpers.py
git commit -m "feat: add export_normalized_db helpers with tests"
```

---

### Task 4: Complete the export script — DB query and CSV writing

**Files:**
- Modify: `python/scripts/export_normalized_db.py` (append `main()` block)

- [ ] **Step 1: Append the DB query and main export block**

Open `python\scripts\export_normalized_db.py` and append this after the helper functions:

```python
# ── Main export ────────────────────────────────────────────────────────────────
def main() -> None:
    # Load .env
    env: dict = {}
    for cand in [os.path.join(BASE_DIR, ".env"), os.path.join(NOTEBOOK_DIR, ".env")]:
        if os.path.exists(cand):
            env = _read_env(cand)
            break
    if not env:
        print(f"[ERROR] .env not found. Tried:\n  {BASE_DIR}\\.env\n  {NOTEBOOK_DIR}\\.env")
        sys.exit(1)

    # Connect
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

    # Query — latest QoL row per senior via MAX(id) subquery
    qol_select = ",\n            ".join(f"qs.{c}" for c in QOL_COLS)
    sql = f"""
        SELECT
            sc.id               AS senior_id,
            sc.first_name,
            sc.last_name,
            sc.middle_name,
            sc.date_of_birth,
            sc.survey_date,
            TIMESTAMPDIFF(YEAR, sc.date_of_birth, CURDATE()) AS age,
            sc.barangay,
            sc.sex,
            sc.civil_status,
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
            sc.housing_concern,
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

    with conn.cursor() as cur:
        cur.execute(sql)
        rows = cur.fetchall()
    conn.close()

    print(f"Fetched {len(rows)} seniors from DB.")
    if len(rows) == 0:
        print("[ERROR] No seniors returned — check DB connection and that seeder has run.")
        sys.exit(1)

    # Write CSV
    no_qol_seniors: list = []

    with open(OUT_CSV, "w", newline="", encoding="utf-8-sig") as fh:
        writer = csv.DictWriter(fh, fieldnames=CSV_COLUMNS)
        writer.writeheader()

        for row in rows:
            has_qol = row.get("qol_enjoy_life") is not None
            if not has_qol:
                name = f"{row.get('first_name', '')} {row.get('last_name', '')}".strip()
                no_qol_seniors.append(f"  {name} (id={row.get('senior_id')})")

            out: dict = {
                "timestamp":            fmt_timestamp(row.get("survey_date")),
                "first_name":           row.get("first_name", "") or "",
                "last_name":            row.get("last_name", "") or "",
                "middle_name":          row.get("middle_name", "") or "",
                "dob":                  fmt_date(row.get("date_of_birth")),
                "age":                  row.get("age", "") if row.get("age") is not None else "",
                "barangay":             row.get("barangay", "") or "",
                "sex":                  row.get("sex", "") or "",
                "civil_status":         row.get("civil_status", "") or "",
                "education":            row.get("educational_attainment", "") or "",
                "monthly_income_range": row.get("monthly_income_range", "") or "",
                "has_medical_checkup":  fmt_bool(row.get("has_medical_checkup")),
                "checkup_schedule":     row.get("checkup_schedule", "") or "",
                "dental_concern":       row.get("dental_concern", "") or "",
                "optical_concern":      row.get("optical_concern", "") or "",
                "hearing_concern":      row.get("hearing_concern", "") or "",
                "healthcare_difficulty": row.get("healthcare_difficulty", "") or "",
                "housing_concern":      row.get("housing_concern", "") or "",
            }

            # JSON → comma string
            for field in JSON_FIELDS:
                out[field] = json_to_csv_str(row.get(field))

            # QoL numeric columns (blank string if NULL)
            for col in QOL_COLS:
                val = row.get(col)
                out[col] = "" if val is None else val

            writer.writerow(out)

    # Summary
    print(f"\n{'='*60}")
    print(f"Export complete → {OUT_CSV}")
    print(f"  Total seniors exported:   {len(rows)}")
    print(f"  With QoL data:            {len(rows) - len(no_qol_seniors)}")
    print(f"  Missing QoL (blanks):     {len(no_qol_seniors)}")
    if no_qol_seniors:
        for s in no_qol_seniors:
            print(s)
    print(f"{'='*60}")
    print("\nNext steps:")
    print("  1. Open osca5.ipynb in Jupyter")
    print("  2. Change: df_raw = pd.read_csv(\"osca.csv\")")
    print("         to: df_raw = pd.read_csv(\"osca_normalized.csv\")")
    print("  3. Kernel → Restart & Run All")


if __name__ == "__main__":
    main()
```

- [ ] **Step 2: Run the export script**

```bat
python\venv\Scripts\python.exe python\scripts\export_normalized_db.py
```

Expected output:
```
Fetched 283 seniors from DB.

============================================================
Export complete → C:\Users\jramo\OneDrive\Desktop\02. AgeSense\osca-system\osca_normalized.csv
  Total seniors exported:   283
  With QoL data:            283
  Missing QoL (blanks):     0
============================================================
```

If count differs from 283, stop and investigate before continuing.

- [ ] **Step 3: Spot-check the output CSV**

Open `osca_normalized.csv` in Excel or a text editor. Verify:
- Row 1 is the header with all expected column names
- `dob` column shows dates like `5/24/1950` (no time suffix, no zero-padding)
- `timestamp` column shows dates like `3/15/2024 0:00`
- `medical_concern` column shows comma-delimited strings, not JSON arrays
- `has_medical_checkup` column shows `Yes` or `No`
- QoL columns (e.g. `qol_enjoy_life`) contain numeric values 1–5

- [ ] **Step 4: Commit**

```bat
git add python\scripts\export_normalized_db.py
git commit -m "feat: complete export_normalized_db — writes osca_normalized.csv from MySQL"
```

---

### Task 5: Adapt `osca5.ipynb` and re-run notebook

**Files:**
- Modify: `C:\Users\jramo\OneDrive\Desktop\02. AgeSense\osca-system\osca5.ipynb`

- [ ] **Step 1: Open the notebook in Jupyter**

From the notebook directory:
```bat
cd "C:\Users\jramo\OneDrive\Desktop\02. AgeSense\osca-system"
jupyter notebook osca5.ipynb
```

Or open Jupyter Lab and navigate to `osca5.ipynb`.

- [ ] **Step 2: Find and change the CSV path**

In the notebook, find the cell containing:
```python
df_raw = pd.read_csv("osca.csv")
```

Change it to:
```python
df_raw = pd.read_csv("osca_normalized.csv")
```

This is the only edit needed in the notebook.

- [ ] **Step 3: Re-run all cells**

In Jupyter: **Kernel → Restart & Run All**

Wait for all cells to complete (may take several minutes if the notebook retrains models). Watch for any red exception cells and fix if any appear.

- [ ] **Step 4: Confirm `senior_predictions.csv` was written**

After the notebook finishes, confirm the predictions file was updated. The notebook writes to `osca_output/predictions/` or directly to `python/models/predictions/`. Check both:

```bat
dir "C:\Users\jramo\OneDrive\Desktop\02. AgeSense\osca-system\osca_output\predictions\senior_predictions.csv"
dir "C:\Users\jramo\OneDrive\Desktop\02. AgeSense\osca-system\osca-system\python\models\predictions\senior_predictions.csv"
```

The modified timestamp should be from today (2026-05-28).

- [ ] **Step 5: Copy predictions into repo if needed**

If the notebook wrote to `osca_output/predictions/` but NOT to `python/models/predictions/`, copy it:

```bat
copy "C:\Users\jramo\OneDrive\Desktop\02. AgeSense\osca-system\osca_output\predictions\senior_predictions.csv" ^
     "C:\Users\jramo\OneDrive\Desktop\02. AgeSense\osca-system\osca-system\python\models\predictions\senior_predictions.csv"

copy "C:\Users\jramo\OneDrive\Desktop\02. AgeSense\osca-system\osca_output\predictions\senior_recommendations_flat.csv" ^
     "C:\Users\jramo\OneDrive\Desktop\02. AgeSense\osca-system\osca-system\python\models\predictions\senior_recommendations_flat.csv"
```

Note: Since `ENABLE_NOTEBOOK_OVERRIDES=false`, these files are used for **comparison only** — not as live overrides. No Flask restart is required after copying.

---

### Task 6: Validate live model alignment

**Files:**
- Read-only: `python/scripts/compare_notebook_vs_live.py`
- Output: `python/scripts/audit_output/notebook_vs_live_report.txt`

- [ ] **Step 1: Ensure `audit_output` directory exists**

```bat
mkdir python\scripts\audit_output 2>nul
echo Directory ready.
```

- [ ] **Step 2: Run the comparison script**

```bat
python\venv\Scripts\python.exe python\scripts\compare_notebook_vs_live.py
```

Expected output structure:
```
Notebook CSV: 283 seniors
DB live results: 283 seniors

======================================================================
NOTEBOOK CSV vs LIVE MODEL COMPARISON
======================================================================
  Seniors matched:  283 / 283 from CSV
  Cluster match:    XXX / 283  (XX.X%)
  Risk level match: XXX / 283  (XX.X%)
  Composite delta:  avg=X.XXXX  max=X.XXXX

======================================================================
CLUSTER DISTRIBUTION
======================================================================
  Cluster                                Notebook    Live    Diff  Status
  -----------------------------------------------------------------
  C1 · High Functioning                        75    XXX      +XX  OK/MISMATCH
  C2 · Moderate / Mixed Needs                 132    XXX      +XX  OK/MISMATCH
  C3 · Low Functioning/Multi-Risk              76    XXX      +XX  OK/MISMATCH

[PASS] / [WARN] / [FAIL]
```

**Interpret results:**
- `[PASS]` (cluster match ≥98%, risk match ≥98%): Live model reliably reproduces notebook predictions. Done.
- `[WARN]` (cluster match ≥95%): Minor borderline differences — acceptable. Document mismatches in `audit_output/notebook_vs_live_report.txt` for future reference.
- `[FAIL]` (cluster match <95%): Significant divergence. Stop and investigate the specific seniors listed in CLUSTER MISMATCHES before proceeding. Check their normalized DB values vs notebook CSV values.

- [ ] **Step 3: End-to-end UI check**

Open the Laravel application in a browser. Navigate to any senior's profile page. Confirm:
- Risk level is displayed (LOW / MODERATE / HIGH)
- No 500 errors appear
- Recommendations are listed

- [ ] **Step 4: Commit test output reference**

```bat
git add python\scripts\tests\
git commit -m "test: add audit_output and final validation artifacts"
```

---

### Task 7: Update `.gitignore` for generated files

**Files:**
- Modify: `.gitignore` (repo root)

- [ ] **Step 1: Confirm `osca_normalized.csv` is excluded**

Check `.gitignore` for an entry covering `osca_normalized.csv` (it should not be committed — it contains PII from the DB).

```bat
findstr /i "osca_normalized" .gitignore
```

If no match found, add the entry:

- [ ] **Step 2: Add to `.gitignore` if missing**

Open `.gitignore` and add:
```
# Normalized DB export — PII data, do not commit
osca_normalized.csv
```

- [ ] **Step 3: Commit**

```bat
git add .gitignore
git commit -m "chore: gitignore osca_normalized.csv (PII)"
```

---

## Self-Review

**Spec coverage check:**

| Spec requirement | Covered by task |
|---|---|
| Set `ENABLE_NOTEBOOK_OVERRIDES=false` | Task 1 |
| Restart Flask services (pick up env var) | Task 1, Step 3 |
| Verify flag is active | Task 1, Step 4 |
| Create `export_normalized_db.py` | Tasks 3 + 4 |
| All 30+ CSV column mappings from spec | Task 4 SQL query + out dict |
| JSON array → comma string conversion | Task 3 `json_to_csv_str` |
| Date formatting (`m/d/Y` no zero-pad) | Task 3 `fmt_date` |
| Timestamp formatting | Task 3 `fmt_timestamp` |
| `has_medical_checkup` → `Yes`/`No` | Task 3 `fmt_bool` |
| Seniors with no QoL → blanks + warning | Task 4 `no_qol_seniors` list |
| Zero-row guard | Task 4 `sys.exit(1)` if `len(rows) == 0` |
| Adapt `osca5.ipynb` CSV path | Task 5 |
| Copy predictions to `python/models/predictions/` | Task 5, Step 5 |
| Run `compare_notebook_vs_live.py` | Task 6 |
| End-to-end UI check | Task 6, Step 3 |
| `.gitignore` for PII CSV | Task 7 |

All spec requirements covered. ✅
