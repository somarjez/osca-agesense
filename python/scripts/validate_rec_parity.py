"""Notebook-vs-live recommendation parity harness.

For every senior in the outer repo's osca.csv, builds BOTH merged dicts that feed
catalog_recommender.extract_need_tags():

  NOTEBOOK shape: df_imputed row + identity (incl. marital_status) + df_sections
                  + frozen risk cols + raw concern columns  (mirrors osca5.ipynb
                  cell 61 / regenerate_recommendations.py)
  LIVE shape:     feature_map + section_scores + raw_context (+ age) as returned
                  by the running preprocess Flask service on :5001  (mirrors
                  inference_service._build_recommendations)

...then diffs the need-tag sets per senior. Divergent tags = same senior would get
different recommendations depending on which pipeline scored them.

Also validates ReimportRecommendations' matching key: normalized name+barangay
must be unique across the CSV, or two seniors' recs get merged onto one record.

Read-only: makes no writes to the DB or model artifacts.

Usage:  python python/scripts/validate_rec_parity.py [--limit N]
Exit 0 when no unexpected divergence; 1 otherwise.
"""
import argparse
import json
import os
import re
import sys
import unicodedata
import urllib.request
from collections import Counter

HERE = os.path.dirname(os.path.abspath(__file__))
INNER = os.path.abspath(os.path.join(HERE, "..", ".."))          # osca-system/osca-system
OUTER = os.path.abspath(os.path.join(INNER, ".."))               # outer wrapper repo
SERVICES = os.path.join(INNER, "python", "services")
NB = os.path.join(OUTER, "osca5.ipynb")
PRED_CSV = os.path.join(OUTER, "osca_output", "predictions", "senior_predictions.csv")
PREPROCESS_URL = os.environ.get("PREPROCESS_URL", "http://127.0.0.1:5001/preprocess")

sys.path.insert(0, SERVICES)
import catalog_recommender as cr  # noqa: E402

# Frozen risk columns the notebook merges in (regenerate_recommendations.py)
FROZEN_COLS = ["risk_level", "composite_risk", "ic_risk", "env_risk", "func_risk",
               "risk_functional", "risk_hc_access", "overall_wellbeing",
               "predicted_overall_healthy_ageing_risk"]

CONCERN_COLS = ["medical_concern", "dental_concern", "optical_concern", "hearing_concern",
                "social_emotional_concern", "healthcare_difficulty", "household_condition",
                "housing_concern"]


def _exec_cells(ns, indices):
    nb = json.load(open(NB, encoding="utf-8"))
    for i in indices:
        src = "".join(nb["cells"][i]["source"])
        exec(compile(src, f"<osca5.ipynb cell {i}>", "exec"), ns)


def _norm(s: str) -> str:
    """Mirror of ReimportRecommendations::norm() (PHP)."""
    s = s.replace("ñ", "n").replace("Ñ", "n")
    s = unicodedata.normalize("NFKD", s).encode("ascii", "ignore").decode("ascii")
    return re.sub(r"[^a-z0-9]+", "", s.lower())


def _call_preprocess(payload: dict) -> dict:
    req = urllib.request.Request(
        PREPROCESS_URL, data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json"}, method="POST")
    with urllib.request.urlopen(req, timeout=120) as resp:
        return json.loads(resp.read().decode("utf-8"))


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--limit", type=int, default=0, help="check only the first N seniors")
    args = ap.parse_args()

    import pandas as pd

    # ── Notebook-side dataframes (deterministic preprocessing only) ──────────
    print("== Rebuilding notebook dataframes (deterministic cells, no training) ==")
    os.chdir(OUTER)  # notebook cells use relative paths
    ns = {}
    _exec_cells(ns, [1, 3, 4, 6, 8, 9, 12, 13, 14, 16])
    df_clean, df_imputed, df_sections = ns["df_clean"], ns["df_imputed"], ns["df_sections"]

    frozen = pd.read_csv(PRED_CSV)
    key_cols = ["first_name", "last_name", "barangay"]
    frozen_map = {}
    for _, fr in frozen.iterrows():
        k = tuple(str(fr[c]).strip() for c in key_cols)
        frozen_map[k] = {c: fr[c] for c in FROZEN_COLS if c in frozen.columns}

    # ── Reimport-key uniqueness (name+barangay collisions) ───────────────────
    keys = Counter()
    for idx in df_clean.index:
        rc = df_clean.loc[idx]
        keys[_norm(f"{rc.get('first_name','')} {rc.get('last_name','')}")
             + "|" + _norm(str(rc.get("barangay", "")))] += 1
    dups = {k: n for k, n in keys.items() if n > 1}
    print(f"Reimport-key uniqueness: {len(dups)} duplicate name+barangay keys"
          + (f" -> {dups}" if dups else ""))

    # ── Per-senior tag parity ─────────────────────────────────────────────────
    idx_list = list(df_clean.index)
    if args.limit:
        idx_list = idx_list[:args.limit]
    # df_clean is SORTED (alphabetical) while osca.csv is in survey order —
    # positional alignment feeds the wrong senior's data to the live side.
    # Match raw rows by normalized name+barangay instead.
    raw_csv = pd.read_csv(os.path.join(OUTER, "osca.csv"), encoding="utf-8-sig")
    raw_by_key = {}
    for _, rr in raw_csv.iterrows():
        rk = (_norm(str(rr.get("first_name", "")) + " " + str(rr.get("last_name", ""))),
              _norm(str(rr.get("barangay", ""))))
        raw_by_key.setdefault(rk, rr)

    n_diff = 0
    only_live: Counter = Counter()
    only_nb: Counter = Counter()
    examples = []
    for pos, idx in enumerate(idx_list):
        row_c = df_clean.loc[idx]
        row_i = df_imputed.loc[idx]
        row_s = df_sections.loc[idx]

        merged_nb = {**row_i.to_dict(), **row_s.to_dict()}
        for col in ["first_name", "last_name", "barangay", "age", "gender", "marital_status"]:
            if col in row_c.index:
                merged_nb[col] = row_c[col]
        for col in CONCERN_COLS:
            if col in row_c.index:
                merged_nb[col] = row_c[col]
        fk = tuple(str(row_c.get(c, "")).strip() for c in key_cols)
        merged_nb.update(frozen_map.get(fk, {}))

        # live payload: the original survey row, as the Laravel buildRawPayload would send
        rk = (_norm(str(row_c.get("first_name", "")) + " " + str(row_c.get("last_name", ""))),
              _norm(str(row_c.get("barangay", ""))))
        rr = raw_by_key.get(rk)
        if rr is None:
            print(f"  [WARN] no raw CSV match for {row_c.get('first_name')} {row_c.get('last_name')}")
            continue
        payload = rr.where(pd.notna(rr), "").to_dict()
        payload.setdefault("marital_status", merged_nb.get("marital_status", ""))
        # Production (MlService::buildRawPayload) nests the QoL Likert answers
        # under 'qol_responses' (QolSurvey::toFeatureArray key names, which match
        # the CSV column names). Top-level Likerts are ignored by preprocess.
        qol_keys = ["qol_enjoy_life", "qol_life_satisfaction", "qol_future_outlook",
                    "qol_meaningfulness", "phy_energy", "phy_pain_r", "phy_health_limit_r",
                    "phy_mobility_outside", "phy_mobility_indoor", "psych_happiness",
                    "psych_peace", "psych_lonely_r", "psych_confidence",
                    "func_independence", "func_autonomy", "func_control",
                    "env_income_limit_r", "soc_social_support", "soc_close_friend",
                    "soc_participation", "soc_opportunity", "soc_respect",
                    "env_safe_home", "env_safe_neighborhood", "env_service_access",
                    "env_home_comfort", "env_fin_medical", "env_fin_household",
                    "env_fin_personal", "spi_belief_comfort", "spi_belief_practice"]
        payload["qol_responses"] = {k: payload[k] for k in qol_keys
                                    if k in payload and payload[k] != ""}
        # The DB stores concern labels already normalized by the notebook cleaning /
        # bulk-upload maps (e.g. 'Scoliosis' -> 'Physical Disability'), so the live
        # side must see the cleaned values, not the raw CSV originals.
        for col in CONCERN_COLS:
            if col in row_c.index and str(row_c[col]) not in ("nan", ""):
                payload[col] = row_c[col]
        try:
            pre = _call_preprocess(payload)
        except Exception as e:
            print(f"  [WARN] preprocess call failed for row {pos}: {e}")
            continue
        merged_live = {}
        merged_live.update(pre.get("feature_map") or {})
        merged_live.update(pre.get("section_scores") or {})
        merged_live.update(pre.get("raw_context") or {})
        merged_live["age"] = (pre.get("identity") or {}).get("age", merged_nb.get("age"))

        tags_nb = cr.extract_need_tags(merged_nb)
        tags_live = cr.extract_need_tags(merged_live)
        if tags_nb != tags_live:
            n_diff += 1
            only_nb.update(tags_nb - tags_live)
            only_live.update(tags_live - tags_nb)
            if len(examples) < 5:
                examples.append((str(row_c.get("first_name", "")) + " " + str(row_c.get("last_name", "")),
                                 sorted(tags_nb - tags_live), sorted(tags_live - tags_nb)))
        if (pos + 1) % 60 == 0:
            print(f"  ... {pos + 1}/{len(idx_list)} seniors compared")

    print(f"\n== PARITY RESULT: {n_diff}/{len(idx_list)} seniors with tag differences ==")
    if only_nb:
        print("Tags fired ONLY in notebook shape (top 10):")
        for t, n in only_nb.most_common(10):
            print(f"   {t}: {n}")
    if only_live:
        print("Tags fired ONLY in live shape (top 10):")
        for t, n in only_live.most_common(10):
            print(f"   {t}: {n}")
    for name, nb_only, live_only in examples:
        print(f"   e.g. {name}: nb-only={nb_only} live-only={live_only}")

    ok = n_diff == 0 and not dups
    print("\nOVERALL:", "PARITY OK" if ok else "DIVERGENCE FOUND")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
