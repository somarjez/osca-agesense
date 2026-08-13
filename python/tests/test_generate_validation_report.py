"""
python/tests/test_generate_validation_report.py
Unit tests for generate_validation_report.py.
Tests load_model_metrics() and render_evidence_table() without a DB connection.

Run:
    python\\venv\\Scripts\\python.exe python\\tests\\test_generate_validation_report.py

TC-MLE-07 (audit finding) history: this file previously hardcoded v1.1.1-era
expected values (silhouette 0.4487, a 3-cluster/283-senior mock distribution,
notebook-match percentages computed against OLD _NB_* constants) that went
stale after the K=4/360-senior migration, causing 7 failures. The audit's own
hypothesis that render_evidence_table() ALSO had a genuine nan/mojibake
rendering defect did not hold up on investigation: the "nan" the old
test_no_placeholder_text caught was a false-positive substring match inside
the word "Financially" (F-I-N-A-N-cially), not an actual NaN value, and the
"mojibake" was a Windows-console codepage display artifact when printing a
correctly-UTF-8-encoded string — not a bug in the string content itself
(verified by writing the rendered table to a UTF-8 file and inspecting the
real characters). Fixed here by (1) reading cluster-quality expectations
dynamically from the same JSON file load_model_metrics() itself reads, so
they can't go stale on the next retrain, (2) updating the mock distribution
to a K=4/360-senior shape, and (3) replacing the naive substring check with
a word-boundary regex.
"""
import json
import os
import re
import sys
import unittest

# Make python/scripts importable
sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "scripts"))
from generate_validation_report import (
    _NB_CLUSTER_MATCH_N,
    _NB_CLUSTER_MATCH_TOT,
    _NB_MAX_DELTA,
    _NB_RISK_MATCH_N,
    _NB_RISK_MATCH_TOT,
    load_model_metrics,
    render_evidence_table,
)

MODELS_DIR = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "models")


def _real_cluster_eval_metrics() -> dict:
    """The same file load_model_metrics() reads — single source of truth so
    TestLoadModelMetrics's expectations move automatically with the next
    retrain instead of needing a hand-maintained hardcoded number.
    """
    with open(os.path.join(MODELS_DIR, "cluster_eval_metrics.json"), encoding="utf-8") as f:
        return json.load(f)


MOCK_METRICS = {
    "silhouette":            0.412,
    "davies_bouldin":        1.198,
    "calinski_harabasz":     84.3,
    "model_version":         "1.1.1",
    "baseline_locked_on":    "2026-05-28",
    "baseline_senior_count": 283,
}

# K=4/360-senior shape, matching the system's current clustering (see
# generate_validation_report.py's own _NB_* constants and their comment).
MOCK_DISTRIBUTION = {
    "risk":                {"HIGH": 65, "MODERATE": 220, "LOW": 75},
    "cluster":             {"1": 84, "2": 154, "3": 50, "4": 72},
    "urgent_count":        5,
    "total":               360,
    "regression_failures": 0,
}


class TestLoadModelMetrics(unittest.TestCase):

    def test_returns_required_keys(self):
        """load_model_metrics returns all six required keys."""
        metrics = load_model_metrics(MODELS_DIR)
        for key in ["silhouette", "davies_bouldin", "calinski_harabasz",
                    "model_version", "baseline_locked_on", "baseline_senior_count"]:
            self.assertIn(key, metrics, f"Missing key: {key}")

    def test_numeric_metric_types(self):
        """Cluster quality metrics are floats; senior_count is int."""
        metrics = load_model_metrics(MODELS_DIR)
        self.assertIsInstance(metrics["silhouette"],            float)
        self.assertIsInstance(metrics["davies_bouldin"],        float)
        self.assertIsInstance(metrics["calinski_harabasz"],     float)
        self.assertIsInstance(metrics["baseline_senior_count"], int)

    def test_known_silhouette_value(self):
        """Silhouette matches the committed cluster_eval_metrics.json — read
        directly rather than hardcoded, so this can't go stale on retrain
        (TC-MLE-07: the previous hardcoded 0.4487 was a v1.1.1-era number
        that no longer matched the K=4 file's real 0.5577)."""
        metrics = load_model_metrics(MODELS_DIR)
        self.assertAlmostEqual(metrics["silhouette"], _real_cluster_eval_metrics()["silhouette"], places=4)

    def test_known_davies_bouldin_value(self):
        metrics = load_model_metrics(MODELS_DIR)
        self.assertAlmostEqual(metrics["davies_bouldin"], _real_cluster_eval_metrics()["davies_bouldin"], places=4)

    def test_known_calinski_harabasz_value(self):
        metrics = load_model_metrics(MODELS_DIR)
        expected = _real_cluster_eval_metrics()["calinski_harabasz"]
        self.assertAlmostEqual(metrics["calinski_harabasz"], expected, places=1)

    def test_model_version_is_string(self):
        metrics = load_model_metrics(MODELS_DIR)
        self.assertIsInstance(metrics["model_version"], str)
        self.assertGreater(len(metrics["model_version"]), 0)

    def test_baseline_senior_count_positive(self):
        metrics = load_model_metrics(MODELS_DIR)
        self.assertGreater(metrics["baseline_senior_count"], 0)


class TestRenderEvidenceTable(unittest.TestCase):

    def setUp(self):
        self.table = render_evidence_table(MOCK_METRICS, MOCK_DISTRIBUTION)

    def test_contains_cluster_match_percentage(self):
        # Derived from the module's own _NB_* constants rather than
        # hardcoded — those are what actually drives this row, so this
        # can't go stale independently of them again (TC-MLE-07).
        expected = f"{100 * _NB_CLUSTER_MATCH_N / _NB_CLUSTER_MATCH_TOT:.1f}%"
        self.assertIn(expected, self.table)

    def test_contains_risk_match_percentage(self):
        expected = f"{100 * _NB_RISK_MATCH_N / _NB_RISK_MATCH_TOT:.1f}%"
        self.assertIn(expected, self.table)

    def test_contains_max_composite_delta(self):
        self.assertIn(str(_NB_MAX_DELTA), self.table)

    def test_contains_silhouette(self):
        self.assertIn("0.412", self.table)

    def test_contains_davies_bouldin(self):
        self.assertIn("1.198", self.table)

    def test_contains_calinski_harabasz(self):
        self.assertIn("84.3", self.table)

    def test_contains_model_version(self):
        self.assertIn("1.1.1", self.table)

    def test_contains_high_risk_count(self):
        self.assertIn("65", self.table)

    def test_contains_moderate_risk_count(self):
        self.assertIn("220", self.table)

    def test_contains_low_risk_count(self):
        self.assertIn("75", self.table)

    def test_contains_c1_cluster_count(self):
        self.assertIn("84 seniors", self.table)

    def test_contains_c2_cluster_count(self):
        self.assertIn("154 seniors", self.table)

    def test_contains_c3_cluster_count(self):
        self.assertIn("50 seniors", self.table)

    def test_contains_c4_cluster_count(self):
        # TC-MLE-07: the old 3-cluster mock omitted cluster 4 entirely
        # (a v1.1.1-era, pre-K=4-migration shape), which is what let the
        # renderer's genuinely-correct "0 seniors" default for a MISSING key
        # get mistaken for a bug during the audit — there was never a real
        # NaN or miscalculation, just a fixture that didn't represent a K=4
        # system at all.
        self.assertIn("72 seniors", self.table)

    def test_contains_zero_regression_failures(self):
        self.assertIn("0 failures", self.table)

    def test_contains_urgent_count(self):
        self.assertIn("**5 seniors**", self.table)

    def test_contains_baseline_date(self):
        self.assertIn("2026-05-28", self.table)

    def test_is_markdown_table_format(self):
        """Every data row contains pipe separators."""
        pipe_lines = [line for line in self.table.splitlines() if "|" in line and line.strip()]
        self.assertGreaterEqual(len(pipe_lines), 10,
            "Expected at least 10 pipe-separated rows in the evidence table")

    def test_no_placeholder_text(self):
        """Table must not contain 'TBD', 'TODO', 'unknown', 'None', or 'nan'
        as a standalone token. TC-MLE-07: a plain substring check here used
        to false-positive on 'nan' inside the word "Financially" (from the
        C3 cluster label, "Environmentally & Financially Vulnerable") — a
        word-boundary regex is required so a real NaN value (which DOES
        render as the standalone token 'nan') is still caught without
        flagging ordinary English words that happen to contain these
        letters.
        """
        for placeholder in ["TBD", "TODO", "unknown", "None", "nan"]:
            pattern = r"\b" + re.escape(placeholder) + r"\b"
            self.assertIsNone(re.search(pattern, self.table),
                f"Table contains placeholder text: '{placeholder}'")


if __name__ == "__main__":
    loader = unittest.TestLoader()
    suite  = unittest.TestSuite()
    suite.addTests(loader.loadTestsFromTestCase(TestLoadModelMetrics))
    suite.addTests(loader.loadTestsFromTestCase(TestRenderEvidenceTable))
    runner = unittest.TextTestRunner(verbosity=2)
    result = runner.run(suite)
    sys.exit(0 if result.wasSuccessful() else 1)
