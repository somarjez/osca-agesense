# Catalog-Driven Recommendation Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `recommendations_list.csv` (157 cited entries) the single authoritative catalog for recommendations, matched to seniors by a tag-based trigger layer, shared by the notebook export and the live Flask engine, with health capped and functional/healthcare-access guaranteed to surface.

**Architecture:** A new `catalog_recommender.py` loads the catalog, turns a senior's already-computed features into need-tags (logic ported verbatim from the existing `_*_recs` thresholds), fires catalog rows whose `trigger_tags` intersect those tags, and applies a capped + needs-first selection policy. `inference_service._build_recommendations` becomes a thin delegator to it; the notebook calls the same path. The `recommendation` text is emitted verbatim from the catalog (no machine-generated clinical prose).

**Tech Stack:** Python 3.12 (stdlib `csv`, `dataclasses`), pytest-free plain-assert test scripts (exit 0/1) matching the repo's existing `tests/test_recommendation_engine.py` convention, Jupyter notebook (`osca5.ipynb`), Laravel artisan (`ml:batch-analyze`).

**Spec:** `docs/superpowers/specs/2026-06-13-catalog-driven-recommendations-design.md`

---

## File Structure

| File | Repo | Responsibility |
|---|---|---|
| `recommendations_list.csv` | outer root + copy in inner `python/services/` | Catalog: content + new `trigger_tags`, `priority_weight` columns |
| `python/services/author_trigger_tags.py` | inner | One-shot script that writes `trigger_tags`/`priority_weight` onto the CSV from an explicit code→tags map |
| `python/services/catalog_recommender.py` | inner | NEW. `load_catalog`, `extract_need_tags`, `match`, `select`, `build_recommendations` |
| `python/services/inference_service.py` | inner | MODIFY `_build_recommendations` to delegate; retire `_*_recs` text path |
| `python/tests/test_catalog_recommender.py` | inner | NEW. Unit tests for the matcher |
| `python/tests/test_recommendation_engine.py` | inner | MODIFY. Catalog-trace + balance assertions |
| `osca5.ipynb` cell #59 | outer | Retire `DISEASE_ACTIONS`; keep delegation to `_build_recommendations` |
| `regenerate_recommendations.py` | outer | MODIFY audit to assert catalog-trace + balance |

**Path resolution:** `load_catalog` resolves the catalog like the existing `_resolve_notebook_*` helpers: inner `python/services/recommendations_list.csv` (primary, shipped by Task 6) → outer repo-root `recommendations_list.csv` (fallback). The outer root CSV is the editable master; Task 6 copies it inward.

---

## Tag vocabulary (canonical)

A catalog row **fires when its `trigger_tags` set intersects the senior's need-tag set** (ANY-match). `extract_need_tags` emits the senior's tags; `author_trigger_tags.py` assigns each catalog row its tags. Both sides use this exact vocabulary. Compound tags (e.g. `dx_htn_dm`, `mobility_alone`) are emitted by `extract_need_tags` only when their joint condition holds, giving AND-semantics without a condition language.

---

### Task 1: Enrich the catalog CSV with `trigger_tags` + `priority_weight`

**Files:**
- Create: `osca-system/python/services/author_trigger_tags.py`
- Modify (output): `recommendations_list.csv` (outer repo root)

- [ ] **Step 1: Write the authoring script**

Create `python/services/author_trigger_tags.py`. `CODE_TAGS` maps every catalog `recommendation_code` to `(pipe-separated tags, priority_weight)`. Empty tags = intentionally dormant (e.g. governance rows, niche rows with no machine signal).

```python
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
```

- [ ] **Step 2: Run the authoring script**

Run: `python osca-system/python/services/author_trigger_tags.py`
Expected: `OK wrote 157 rows (NNN with tags) -> ...recommendations_list.csv` (NNN ≈ 135–140; governance/dormant rows blank).

- [ ] **Step 3: Verify the columns landed**

Run:
```bash
python -c "import csv;r=list(csv.DictReader(open('recommendations_list.csv',encoding='utf-8-sig')));print('cols ok',{'trigger_tags','priority_weight'} <= set(r[0]));print('blank tags',sum(1 for x in r if not x['trigger_tags']))"
```
Expected: `cols ok True` and `blank tags` between 17 and 22.

- [ ] **Step 4: Commit**

```bash
git add osca-system/python/services/author_trigger_tags.py recommendations_list.csv
git commit -m "feat(recommendations): add trigger_tags + priority_weight to catalog"
```

---

### Task 2: `catalog_recommender` — constants + `load_catalog`

**Files:**
- Create: `python/services/catalog_recommender.py`
- Test: `python/tests/test_catalog_recommender.py`

- [ ] **Step 1: Write the failing test**

Create `python/tests/test_catalog_recommender.py`:

```python
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "..", "services"))

import catalog_recommender as cr  # noqa: E402


def test_load_catalog_parses_rows_and_tags():
    catalog = cr.load_catalog()
    assert len(catalog) == 157, f"expected 157 catalog rows, got {len(catalog)}"
    by_code = {r.code: r for r in catalog}
    htn = by_code["HLT_001"]
    assert "dx_hypertension" in htn.trigger_tags
    assert htn.priority_weight == 4
    assert htn.category == "health"
    assert htn.apa_reference, "apa_reference must be populated"
    assert htn.recommendation, "recommendation text must be populated"
    # governance row is dormant
    assert by_code["SAFE_001"].trigger_tags == frozenset()


if __name__ == "__main__":
    fails = 0
    for name, fn in sorted(globals().items()):
        if name.startswith("test_") and callable(fn):
            try:
                fn()
                print(f"PASS {name}")
            except AssertionError as e:
                fails += 1
                print(f"FAIL {name}: {e}")
    sys.exit(1 if fails else 0)
```

- [ ] **Step 2: Run it to verify it fails**

Run: `python osca-system/python/tests/test_catalog_recommender.py`
Expected: FAIL — `ModuleNotFoundError: No module named 'catalog_recommender'`.

- [ ] **Step 3: Write `catalog_recommender.py` (constants + loader)**

Create `python/services/catalog_recommender.py`:

```python
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
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `python osca-system/python/tests/test_catalog_recommender.py`
Expected: `PASS test_load_catalog_parses_rows_and_tags` and exit 0.

- [ ] **Step 5: Commit**

```bash
git add osca-system/python/services/catalog_recommender.py osca-system/python/tests/test_catalog_recommender.py
git commit -m "feat(recommendations): catalog loader for catalog_recommender"
```

---

### Task 3: `catalog_recommender.extract_need_tags`

Ports the exact thresholds from `inference_service._health_recs / _financial_recs / _social_recs / _functional_recs / _hc_access_recs / _household_safety_recs / _assistive_device_recs`, emitting tags instead of rule codes.

**Files:**
- Modify: `python/services/catalog_recommender.py`
- Test: `python/tests/test_catalog_recommender.py`

- [ ] **Step 1: Add failing tests**

Append to `test_catalog_recommender.py` (before the `__main__` block):

```python
def test_extract_tags_hypertension_and_diabetes_compound():
    tags = cr.extract_need_tags({"medical_concern": "hypertension and diabetes"})
    assert "dx_hypertension" in tags and "dx_diabetes" in tags
    assert "dx_htn_dm" in tags
    assert "chronic_disease" in tags


def test_extract_tags_frail_alone_functional():
    tags = cr.extract_need_tags({
        "func_independence": 2, "phy_mobility_outside": 2,
        "sec4_lives_alone": 1, "sec6_func_score": 0.30, "age": 82,
    })
    assert {"frailty", "adl_difficulty", "low_independence",
            "mobility_limited_outside", "lives_alone", "mobility_alone",
            "frail_80plus"} <= tags


def test_extract_tags_healthcare_access_and_financial():
    tags = cr.extract_need_tags({
        "healthcare_difficulty": "transport and cost", "has_medical_checkup": 0,
        "env_service_access": 2, "has_pension": 0, "env_fin_household": 2,
        "income_enc": 2,
    })
    assert {"transport_barrier", "medical_cost_strain", "no_checkup",
            "service_access_low", "no_pension", "financial_strain",
            "food_insecurity", "low_income"} <= tags


def test_extract_tags_healthy_senior_minimal():
    tags = cr.extract_need_tags({
        "age": 65, "medical_concern": "none", "func_independence": 5,
        "phy_mobility_outside": 5, "phy_mobility_indoor": 5, "has_pension": 1,
        "has_medical_checkup": 1, "is_association_member": 1, "income_enc": 7,
        "sec6_func_score": 0.8, "env_service_access": 5,
    })
    assert "frailty" not in tags and "adl_difficulty" not in tags
    assert "dx_hypertension" not in tags
```

- [ ] **Step 2: Run to verify failure**

Run: `python osca-system/python/tests/test_catalog_recommender.py`
Expected: FAIL — `AttributeError: module 'catalog_recommender' has no attribute 'extract_need_tags'`.

- [ ] **Step 3: Implement `extract_need_tags`**

Append to `catalog_recommender.py`:

```python
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `python osca-system/python/tests/test_catalog_recommender.py`
Expected: all 5 `test_*` PASS, exit 0.

- [ ] **Step 5: Commit**

```bash
git add osca-system/python/services/catalog_recommender.py osca-system/python/tests/test_catalog_recommender.py
git commit -m "feat(recommendations): extract_need_tags (ported thresholds)"
```

---

### Task 4: `catalog_recommender.match` + `select` (capped + needs-first)

`select` ports the proven interleave/cap from `inference_service._build_recommendations` (lines ~1979–2035) and adds `priority_weight` ordering.

**Files:**
- Modify: `python/services/catalog_recommender.py`
- Test: `python/tests/test_catalog_recommender.py`

- [ ] **Step 1: Add failing tests**

Append before `__main__`:

```python
def test_match_returns_rows_with_intersecting_tags():
    catalog = cr.load_catalog()
    fired = cr.match({"dx_hypertension"}, catalog)
    codes = {r.code for r in fired}
    assert "HLT_001" in codes
    assert "HLT_002" not in codes  # diabetes-only row must not fire


def test_select_caps_health_for_routine_senior():
    catalog = cr.load_catalog()
    # senior with many health triggers but also functional + access needs
    tags = {"dx_hypertension", "dx_diabetes", "dx_htn_dm", "dx_cardiac",
            "chronic_disease", "frailty", "adl_difficulty",
            "transport_barrier", "no_checkup"}
    fired = cr.match(tags, catalog)
    chosen = cr.select(fired, urgency="planned", risk_level="moderate")
    top3 = chosen[:3]
    assert sum(1 for r in chosen if r.category == "health") <= 2
    assert not all(r.category == "health" for r in top3), "top-3 must not be all health"
    assert any(r.category == "functional" for r in chosen)


def test_select_allows_three_health_for_urgent():
    catalog = cr.load_catalog()
    tags = {"dx_hypertension", "dx_diabetes", "dx_htn_dm", "dx_cardiac", "chronic_disease"}
    fired = cr.match(tags, catalog)
    chosen = cr.select(fired, urgency="urgent", risk_level="high")
    assert sum(1 for r in chosen if r.category == "health") <= 3
```

- [ ] **Step 2: Run to verify failure**

Run: `python osca-system/python/tests/test_catalog_recommender.py`
Expected: FAIL — `module 'catalog_recommender' has no attribute 'match'`.

- [ ] **Step 3: Implement `match` + `select`**

Append to `catalog_recommender.py`:

```python
_PRIORITY_CATS = [
    "functional", "healthcare_access", "social", "financial",
    "mental_health", "assistive_device", "household_safety",
    "elder_protection", "livelihood", "benefits", "other",
]


def match(senior_tags: Set[str], catalog: Optional[List[CatalogRow]] = None) -> List[CatalogRow]:
    catalog = catalog if catalog is not None else load_catalog()
    tags = set(senior_tags)
    return [r for r in catalog if r.trigger_tags and (r.trigger_tags & tags)]


def select(fired: List[CatalogRow], urgency: str = "planned",
           risk_level: str = "moderate") -> List[CatalogRow]:
    """Capped + needs-first ordering. Governance rows are excluded (they surface
    as the requires_human_validation flag, not as ranked items)."""
    rows = [r for r in fired if r.category != GOVERNANCE_CATEGORY]

    is_high = urgency in ("urgent", "immediate") or risk_level.lower() == "high"
    max_health = 3 if is_high else 2

    def sort_key(r: CatalogRow):
        return (-r.priority_weight, r.code)

    health = sorted([r for r in rows if r.category == HEALTH_CATEGORY], key=sort_key)
    non_health = [r for r in rows if r.category != HEALTH_CATEGORY]

    # group non-health by category, each sorted by weight
    groups: Dict[str, List[CatalogRow]] = {}
    for r in non_health:
        groups.setdefault(r.category, []).append(r)
    for c in groups:
        groups[c].sort(key=sort_key)

    ordered_cats = [c for c in _PRIORITY_CATS if c in groups]
    ordered_cats += sorted(c for c in groups if c not in _PRIORITY_CATS)
    queues = [groups[c] for c in ordered_cats]

    interleaved: List[CatalogRow] = []
    while any(queues):
        for q in queues:
            if q:
                interleaved.append(q.pop(0))
        queues = [q for q in queues if q]

    priority_health = health[:max_health]
    remaining_health = health[max_health:]
    health_leads = is_high

    out: List[CatalogRow] = []
    ph, nh = list(priority_health), list(interleaved)
    while ph or nh:
        first, second = (ph, nh) if health_leads else (nh, ph)
        if first:
            out.append(first.pop(0))
        if second:
            out.append(second.pop(0))
    out += remaining_health
    return out[:TOTAL_REC_CAP]
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `python osca-system/python/tests/test_catalog_recommender.py`
Expected: all PASS, exit 0.

- [ ] **Step 5: Commit**

```bash
git add osca-system/python/services/catalog_recommender.py osca-system/python/tests/test_catalog_recommender.py
git commit -m "feat(recommendations): match + capped/needs-first select"
```

---

### Task 5: `catalog_recommender.build_recommendations` (export dicts)

Emits dicts with the **exact keys** `recommendation_rules.build_rec_from_rule` produces, so it is a drop-in for the engine. Adds derived tags (`high_risk`, `multiple_unmet_needs`) that depend on urgency/match-breadth.

**Files:**
- Modify: `python/services/catalog_recommender.py`
- Test: `python/tests/test_catalog_recommender.py`

- [ ] **Step 1: Add failing test**

Append before `__main__`:

```python
def test_build_recommendations_emits_full_schema():
    row = {"medical_concern": "hypertension", "func_independence": 2,
           "phy_mobility_outside": 2, "sec4_lives_alone": 1, "age": 82,
           "has_pension": 0, "income_enc": 2, "env_fin_household": 2}
    recs = cr.build_recommendations(row, urgency="planned", risk_level="moderate",
                                    cluster_id=3, overall_level="MODERATE", priority_flag="")
    assert recs, "expected at least one recommendation"
    required = {"priority", "type", "domain", "category", "action", "urgency",
                "risk_level", "reason", "service_provider", "evidence_source",
                "apa_reference", "source_type", "recommendation_code",
                "trigger_summary", "requires_human_validation", "documents_needed",
                "trigger_context"}
    for rec in recs:
        assert required <= set(rec), f"missing keys: {required - set(rec)}"
        assert rec["recommendation_code"], "code must be non-empty"
        assert rec["apa_reference"], "apa must be non-empty"
        assert rec["source_type"], "source_type must be non-empty"
        assert rec["domain"], "domain must be non-empty"
    assert [r["priority"] for r in recs] == list(range(1, len(recs) + 1))
    # health capped, not all-health top-3
    assert not all(r["category"] == "health" for r in recs[:3])
```

- [ ] **Step 2: Run to verify failure**

Run: `python osca-system/python/tests/test_catalog_recommender.py`
Expected: FAIL — `no attribute 'build_recommendations'`.

- [ ] **Step 3: Implement `build_recommendations`**

Append to `catalog_recommender.py`:

```python
def _row_to_rec(r: CatalogRow, priority: int, urgency: str, risk_level: str,
                matched_tags: Set[str], trigger_context: Dict[str, Any]) -> Dict[str, Any]:
    fired = sorted(r.trigger_tags & matched_tags)
    reason = r.trigger_summary
    if fired:
        reason = f"{r.trigger_summary} (matched: {', '.join(fired)})"
    return {
        "priority": priority,
        "type": "domain",
        "domain": r.who_domain or r.category or "general",
        "category": r.category or "general",
        "action": r.recommendation,                 # verbatim catalog text
        "urgency": urgency,
        "risk_level": risk_level,
        "reason": reason,
        "service_provider": r.service_provider,
        "evidence_source": r.source,
        "apa_reference": r.apa_reference,
        "source_type": r.source_type,
        "recommendation_code": r.code,
        "trigger_summary": r.trigger_summary,
        "requires_human_validation": r.requires_human_validation,
        "documents_needed": None,
        "key_program_tag": r.key_program_tag,
        "implementation_note": r.implementation_note,
        "trigger_context": dict(trigger_context),
    }


def build_recommendations(row: Dict[str, Any], urgency: str = "planned",
                          risk_level: str = "moderate", cluster_id: Any = None,
                          overall_level: str = "", priority_flag: str = "",
                          catalog: Optional[List[CatalogRow]] = None) -> List[Dict[str, Any]]:
    catalog = catalog if catalog is not None else load_catalog()
    tags = extract_need_tags(row)

    # derived tags that depend on urgency / breadth of need
    is_high = urgency in ("urgent", "immediate") or str(overall_level).upper() == "HIGH" \
        or risk_level.lower() == "high"
    if is_high:
        tags.add("high_risk")
    # categories triggered so far (excluding the broad osca/benefits proxies)
    pre_fired = match(tags, catalog)
    distinct_cats = {r.category for r in pre_fired if r.category not in ("benefits", "governance")}
    if len(distinct_cats) >= 3:
        tags.add("multiple_unmet_needs")
    if "no_checkup" in tags or "low_income" in tags or len(distinct_cats) >= 3:
        tags.add("osca_navigation")
    if is_high or "multiple_unmet_needs" in tags:
        tags.add("benefits_unaware")

    fired = match(tags, catalog)
    chosen = select(fired, urgency=urgency, risk_level=risk_level)

    trigger_context = {
        "cluster_id": cluster_id,
        "risk_level": overall_level or risk_level,
        "urgency": urgency,
        "priority_flag": priority_flag or "",
    }
    return [
        _row_to_rec(r, i, urgency, risk_level, tags, trigger_context)
        for i, r in enumerate(chosen, start=1)
    ]
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `python osca-system/python/tests/test_catalog_recommender.py`
Expected: all PASS, exit 0.

- [ ] **Step 5: Commit**

```bash
git add osca-system/python/services/catalog_recommender.py osca-system/python/tests/test_catalog_recommender.py
git commit -m "feat(recommendations): build_recommendations orchestrator (catalog dicts)"
```

---

### Task 6: Wire `inference_service._build_recommendations` to the catalog + ship catalog copy

**Files:**
- Modify: `python/services/inference_service.py:1927-2057` (`_build_recommendations` body)
- Create: `python/services/recommendations_list.csv` (copy of the enriched outer-root master)

- [ ] **Step 1: Ship the catalog copy into services (primary path)**

Run:
```bash
cp recommendations_list.csv osca-system/python/services/recommendations_list.csv
```
Expected: file exists. (This is the primary path `catalog_recommender._candidate_paths()` resolves.)

- [ ] **Step 2: Replace the body of `_build_recommendations`**

In `inference_service.py`, replace the entire body of `_build_recommendations` (lines 1935–2057, from `merged = dict(feature_map)` through `return diverse`) with delegation. Keep the signature unchanged:

```python
    merged = dict(feature_map)
    merged.update(section_scores)
    merged.update(raw_context)

    urgency = _recommendation_urgency(overall_level, priority_flag)
    risk_level_str = overall_level.lower()

    import catalog_recommender as _cr
    return _cr.build_recommendations(
        merged,
        urgency=urgency,
        risk_level=risk_level_str,
        cluster_id=named_id,
        overall_level=overall_level,
        priority_flag=priority_flag or "",
    )
```

Leave the `_health_recs … _assistive_device_recs` functions and `build_rec_from_rule` in place for now (the test suite still imports some); they are simply no longer called by `_build_recommendations`. The legacy `DISEASE_RULE_MAP` constant also stays (unused by the new path). Removing dead code is a separate cleanup, out of scope here.

- [ ] **Step 3: Smoke-test the engine end to end**

Run:
```bash
python -c "import sys; sys.path.insert(0,'osca-system/python/services'); import inference_service as s; r=s._build_recommendations(3,'MODERATE',{'medical_concern':'hypertension','func_independence':2,'phy_mobility_outside':2,'sec4_lives_alone':1,'age':82,'has_pension':0,'income_enc':2,'env_fin_household':2},{},{}); print('n',len(r)); print('codes',[x['recommendation_code'] for x in r]); print('cats',[x['category'] for x in r]); assert all(x['apa_reference'] and x['recommendation_code'] for x in r)"
```
Expected: prints `n N` (N between 4 and 10), catalog codes (`HLT_001`, `FUNC_*`, etc.), mixed categories, no assertion error.

- [ ] **Step 4: Commit**

```bash
git add osca-system/python/services/inference_service.py osca-system/python/services/recommendations_list.csv
git commit -m "feat(recommendations): delegate _build_recommendations to catalog engine"
```

---

### Task 7: Defense audit in tests + regenerate script

**Files:**
- Modify: `python/tests/test_recommendation_engine.py`
- Modify: `regenerate_recommendations.py` (outer)

- [ ] **Step 1: Add a cohort-level audit test**

Append a new test to `python/tests/test_recommendation_engine.py` that runs the engine over the frozen prediction cohort and asserts balance + completeness. Add near the other tests:

```python
def test_catalog_cohort_balance_and_completeness():
    """Cohort-level defense audit: no null citation fields, health not dominant,
    functional + healthcare_access present, every action is verbatim catalog text."""
    import os, sys, csv
    here = os.path.dirname(os.path.abspath(__file__))
    sys.path.insert(0, os.path.join(here, "..", "services"))
    import catalog_recommender as cr

    catalog = cr.load_catalog()
    catalog_texts = {r.recommendation for r in catalog}

    pred_candidates = [
        os.path.abspath(os.path.join(here, "..", "..", "..", "osca_output",
                                     "predictions", "senior_predictions.csv")),
        os.path.abspath(os.path.join(here, "..", "models", "predictions",
                                     "senior_predictions.csv")),
    ]
    pred_path = next((p for p in pred_candidates if os.path.exists(p)), None)
    if not pred_path:
        print("SKIP cohort audit: senior_predictions.csv not found")
        return

    with open(pred_path, encoding="utf-8-sig", newline="") as fh:
        seniors = list(csv.DictReader(fh))

    cats = {}
    total = 0
    funcs = access = 0
    for s in seniors:
        recs = cr.build_recommendations(
            s, urgency="planned", risk_level=str(s.get("risk_level", "moderate")).lower(),
            cluster_id=s.get("cluster_id"), overall_level=str(s.get("risk_level", "MODERATE")),
        )
        for rec in recs:
            total += 1
            cats[rec["category"]] = cats.get(rec["category"], 0) + 1
            assert rec["recommendation_code"], "null code"
            assert rec["apa_reference"], "null apa"
            assert rec["source_type"], "null source_type"
            assert rec["domain"], "null domain"
            assert rec["action"] in catalog_texts, "non-catalog (clinical) text emitted"
        if any(r["category"] == "functional" for r in recs):
            funcs += 1
        if any(r["category"] == "healthcare_access" for r in recs):
            access += 1

    assert total > 0, "no recommendations generated"
    health_pct = 100.0 * cats.get("health", 0) / total
    print(f"AUDIT total={total} health={health_pct:.1f}% funcs_seniors={funcs} access_seniors={access}")
    assert health_pct <= 35.0, f"health still dominant: {health_pct:.1f}%"
    assert cats.get("functional", 0) > 0, "functional absent"
    assert cats.get("healthcare_access", 0) > 0, "healthcare_access absent"
```

- [ ] **Step 2: Run the engine test suite**

Run: `python osca-system/python/tests/test_recommendation_engine.py`
Expected: existing tests pass; new `test_catalog_cohort_balance_and_completeness` prints an `AUDIT …` line with `health <= 35%`, functional/access present, exit 0. If health > 35% or a category is absent, tune `CODE_TAGS` weights/tags in `author_trigger_tags.py`, re-run Task 1 Step 2, copy the CSV inward (Task 6 Step 1), and re-run.

- [ ] **Step 3: Update the regenerate audit**

In `regenerate_recommendations.py`, the audit section already counts categories and citations from the flat export. Add an assertion block after the audit print that fails loudly if the catalog invariants break. Locate the audit/print section and append:

```python
    # ── catalog-driven invariants (defense gate) ──
    _flat_path = os.path.join(ROOT, "osca_output", "predictions",
                              "senior_recommendations_flat.csv")
    with open(_flat_path, encoding="utf-8-sig", newline="") as _fh:
        _rows = list(csv.DictReader(_fh))
    _n = len(_rows)
    _health = sum(1 for r in _rows if r.get("category") == "health")
    _null_code = sum(1 for r in _rows if not (r.get("recommendation_code") or "").strip())
    _null_apa = sum(1 for r in _rows if not (r.get("apa_reference") or "").strip())
    _has_func = any(r.get("category") == "functional" for r in _rows)
    _has_access = any(r.get("category") == "healthcare_access" for r in _rows)
    print(f"[catalog-audit] total={_n} health={100*_health/_n:.1f}% "
          f"null_code={_null_code} null_apa={_null_apa} "
          f"functional={_has_func} healthcare_access={_has_access}")
    assert _null_code == 0, f"{_null_code} recs with no code"
    assert _null_apa == 0, f"{_null_apa} recs with no APA"
    assert _has_func and _has_access, "functional/healthcare_access must be present"
    assert 100 * _health / _n <= 35.0, "health dominant in export"
```

- [ ] **Step 4: Run the regenerate script**

Run: `python regenerate_recommendations.py`
Expected: rebuilds `osca_output/reports/senior_recommendations.json`, `osca_output/predictions/senior_recommendations_flat.csv`, `osca_output/reports/recommendation_summary.csv`; prints `[catalog-audit] … health=<=35% null_code=0 null_apa=0 functional=True healthcare_access=True`; engine test suite exits 0.

- [ ] **Step 5: Commit**

```bash
git add osca-system/python/tests/test_recommendation_engine.py regenerate_recommendations.py
git commit -m "test(recommendations): catalog-trace + balance defense audit"
```

---

### Task 8: Retire `DISEASE_ACTIONS` in the notebook

**Files:**
- Modify: `osca5.ipynb` cell #59 (outer)

> Use the `NotebookEdit` tool (deferred — fetch via ToolSearch `select:NotebookEdit`). Do NOT hand-edit the JSON. Cell index #59 contains the `DISEASE_ACTIONS` table plus `from inference_service import _build_recommendations`; cell #60 has `build_recommendation_v2`. The delegation already routes through `_build_recommendations`, which now uses the catalog — so the only change is removing the dead `DISEASE_ACTIONS` definition and any reference to it.

- [ ] **Step 1: Inspect cell #59 to find the DISEASE_ACTIONS block**

Run:
```bash
python -c "import json; nb=json.loads(open('osca5.ipynb','rb').read().decode('utf-8','replace')); print(''.join(nb['cells'][59]['source']))"
```
Expected: prints cell source; locate the `DISEASE_ACTIONS = {…}` dict and any use of it.

- [ ] **Step 2: Remove the dead `DISEASE_ACTIONS` definition**

Using `NotebookEdit` (cell_id = index 59, edit_mode = replace), rewrite the cell source with the `DISEASE_ACTIONS = {…}` block deleted and any remaining references removed, keeping the `from inference_service import _build_recommendations` import and the rest of the cell intact. If `DISEASE_ACTIONS` is only defined and never read after delegation, deleting the definition is sufficient.

- [ ] **Step 3: Verify no live reference remains**

Run:
```bash
python -c "import json; nb=json.loads(open('osca5.ipynb','rb').read().decode('utf-8','replace')); hits=[i for i,c in enumerate(nb['cells']) if c['cell_type']=='code' and 'DISEASE_ACTIONS' in ''.join(c['source'])]; print('DISEASE_ACTIONS cells:', hits)"
```
Expected: `DISEASE_ACTIONS cells: []`.

- [ ] **Step 4: Commit**

```bash
git add osca5.ipynb
git commit -m "refactor(notebook): retire dead DISEASE_ACTIONS; recs are catalog-driven"
```

---

### Task 9: Re-score the live system + verify single-source parity

This flushes the stale live DB and applies catalog-driven recs while keeping the live engine on the MODELS (`ENABLE_NOTEBOOK_OVERRIDES=false`).

**Files:** none (operational). Run from `osca-system/osca-system` (inner Laravel root).

- [ ] **Step 1: Confirm MySQL is up and overrides are off**

Run (inner root):
```bash
grep ENABLE_NOTEBOOK_OVERRIDES .env
```
Expected: `ENABLE_NOTEBOOK_OVERRIDES=false`. Ensure MySQL (`osca_db`) is running (start XAMPP/MySQL service if the earlier connection was refused).

- [ ] **Step 2: Restart the Flask services so they load the new engine + catalog**

Run (inner root, PowerShell): `./python/start_services.ps1`
Then verify health: `curl http://127.0.0.1:5002/health`
Expected: inference service healthy, `notebook_overrides_enabled` is `false`.

- [ ] **Step 3: Re-score all seniors with the live model**

Run (inner root): `php artisan ml:batch-analyze --force`
Expected: completes; all seniors become `prediction_source=live_model` with catalog-driven recommendations.

- [ ] **Step 4: Audit the live DB matches the notebook export**

Run (inner root):
```bash
php -r '$p=new PDO("mysql:host=127.0.0.1;port=3306;dbname=osca_db","root","");$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$t=$p->query("SELECT COUNT(*) c FROM recommendations WHERE deleted_at IS NULL")->fetch()["c"];
$nc=$p->query("SELECT COUNT(*) c FROM recommendations WHERE deleted_at IS NULL AND (recommendation_code IS NULL OR recommendation_code=\"\")")->fetch()["c"];
$h=$p->query("SELECT COUNT(*) c FROM recommendations WHERE deleted_at IS NULL AND category=\"health\"")->fetch()["c"];
$f=$p->query("SELECT COUNT(*) c FROM recommendations WHERE deleted_at IS NULL AND category=\"functional\"")->fetch()["c"];
$a=$p->query("SELECT COUNT(*) c FROM recommendations WHERE deleted_at IS NULL AND category=\"healthcare_access\"")->fetch()["c"];
printf("total=%d null_code=%d health=%.1f%% functional=%d access=%d\n",$t,$nc,100*$h/$t,$f,$a);'
```
Expected: `null_code=0`, `health <= ~35%`, `functional > 0`, `access > 0` — matching the notebook audit from Task 7.

- [ ] **Step 5: Spot-check the UI**

Open a senior profile + the recommendations view and confirm each rec shows a code, WHO domain, `trigger_summary`, APA reference, and non-clinical text. Confirm a senior with functional/access needs shows those (not all-health).

- [ ] **Step 6: Final completion check**

Run the full engine test suite once more: `python osca-system/python/tests/test_recommendation_engine.py` → exit 0. The branch is ready for PR.

---

## Self-Review

**Spec coverage:**
- Catalog as single source (content + triggering) → Tasks 1, 2, 6. ✓
- `trigger_tags` + `priority_weight` columns → Task 1. ✓
- Reuse existing feature extraction (ported thresholds) → Task 3. ✓
- Tag-intersection matcher → Task 4 (`match`). ✓
- Capped + needs-first selection, governance excluded, total cap → Task 4 (`select`). ✓
- Verbatim catalog text (kills clinical tone) → Task 5 (`_row_to_rec` `action`), asserted Task 7. ✓
- All citation/domain fields non-null from catalog → Task 5, asserted Tasks 7 & 9. ✓
- Notebook regenerates catalog-driven recs; retire `DISEASE_ACTIONS` → Tasks 6, 7, 8. ✓
- Live on models, `ENABLE_NOTEBOOK_OVERRIDES=false`, re-score → Task 9. ✓
- Single-source parity notebook vs live → Tasks 6 (shared module/CSV) + 9 Step 4. ✓
- Defense audit: 0 null, health ≤ threshold, functional + access present → Tasks 7 & 9. ✓

**Placeholder scan:** No TBD/TODO. Every code step shows full code; the one non-coded step (Task 8 cell edit) is a tool action with explicit before/after verification.

**Type consistency:** `CatalogRow` fields used consistently (`r.code`, `r.category`, `r.trigger_tags`, `r.priority_weight`, `r.recommendation`, `r.who_domain`, `r.apa_reference`, `r.source`, `r.source_type`, `r.service_provider`, `r.requires_human_validation`). `load_catalog` / `extract_need_tags` / `match` / `select` / `build_recommendations` signatures match their call sites in tests and in `inference_service`. Emitted rec dict keys match `build_rec_from_rule` output (`action`, `domain`, `category`, `recommendation_code`, `evidence_source`, `apa_reference`, `source_type`, `trigger_summary`, `requires_human_validation`, `documents_needed`, `reason`, `priority`, `type`, `urgency`, `risk_level`) plus `trigger_context`.

**Open tuning note:** Task 7 Step 2 is the calibration gate — if the cohort audit shows a category absent or health > 35%, adjust `CODE_TAGS` (Task 1) and re-run. This is expected iteration, not a plan gap.
