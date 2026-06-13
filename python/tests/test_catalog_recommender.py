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
