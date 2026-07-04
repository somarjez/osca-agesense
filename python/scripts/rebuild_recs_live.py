"""Worker for `php artisan recommendations:rebuild-live`.

Reads a JSON file of seniors ({senior_id, payload, risk_level, priority_flag,
cluster_id}), runs each payload through the PRODUCTION preprocess pipeline
(preprocess_service.preprocess, in-process — same code the Flask service runs),
merges feature_map + section_scores + raw_context exactly like
inference_service._build_recommendations, and calls
catalog_recommender.build_recommendations with the senior's FROZEN risk level /
priority flag (cluster and risk scores are never recomputed here).

Writes JSON {senior_id: [rec, ...]} to the output path.

Usage: python rebuild_recs_live.py <input.json> <output.json>
"""
import json
import os
import sys

os.environ.setdefault("OSCA_BATCH_MODE", "1")  # skip per-senior UMAP transform

HERE = os.path.dirname(os.path.abspath(__file__))
SERVICES = os.path.join(HERE, "..", "services")
sys.path.insert(0, SERVICES)

import catalog_recommender  # noqa: E402
from preprocess_service import preprocess  # noqa: E402


def _urgency(overall_level: str, priority_flag: str) -> str:
    # Mirrors inference_service._recommendation_urgency
    if overall_level == "CRITICAL" or priority_flag == "urgent":
        return "urgent"
    return {"HIGH": "planned", "MODERATE": "planned", "LOW": "maintenance"}.get(
        overall_level, "planned")


def main() -> int:
    in_path, out_path = sys.argv[1], sys.argv[2]
    with open(in_path, encoding="utf-8") as fh:
        seniors = json.load(fh)

    out = {}
    errors = {}
    for item in seniors:
        sid = item["senior_id"]
        try:
            pre = preprocess(item["payload"])
            merged = {}
            merged.update(pre.get("feature_map") or {})
            merged.update(pre.get("section_scores") or {})
            merged.update(pre.get("raw_context") or {})
            merged["age"] = (pre.get("identity") or {}).get("age")

            overall = str(item.get("risk_level") or "MODERATE").upper()
            pflag = str(item.get("priority_flag") or "")
            urgency = _urgency(overall, pflag)

            out[str(sid)] = catalog_recommender.build_recommendations(
                merged,
                urgency=urgency,
                risk_level=overall.lower(),
                cluster_id=item.get("cluster_id"),
                overall_level=overall,
                priority_flag=pflag,
            )
        except Exception as e:  # keep going; report per-senior failures
            errors[str(sid)] = f"{type(e).__name__}: {e}"

    with open(out_path, "w", encoding="utf-8") as fh:
        json.dump({"recommendations": out, "errors": errors}, fh, ensure_ascii=False)
    print(f"rebuilt={len(out)} errors={len(errors)}")
    return 0 if not errors else 1


if __name__ == "__main__":
    sys.exit(main())
