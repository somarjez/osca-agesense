"""
python/tests/test_generate_validation_report.py
Unit tests for generate_validation_report.py.
Tests load_model_metrics() and render_evidence_table() without a DB connection.

Run:
    python\\venv\\Scripts\\python.exe python\\tests\\test_generate_validation_report.py
"""
import sys, os, unittest

# Make python/scripts importable
sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "scripts"))
from generate_validation_report import load_model_metrics, render_evidence_table

MODELS_DIR = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "models")

MOCK_METRICS = {
    "silhouette":            0.412,
    "davies_bouldin":        1.198,
    "calinski_harabasz":     84.3,
    "model_version":         "1.1.1",
    "baseline_locked_on":    "2026-05-28",
    "baseline_senior_count": 283,
}

MOCK_DISTRIBUTION = {
    "risk":                {"HIGH": 54, "MODERATE": 191, "LOW": 38},
    "cluster":             {"1": 75, "2": 132, "3": 76},
    "urgent_count":        5,
    "total":               283,
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
        """Silhouette reads 0.412 from the committed cluster_eval_metrics.json."""
        metrics = load_model_metrics(MODELS_DIR)
        self.assertAlmostEqual(metrics["silhouette"], 0.412, places=3)

    def test_known_davies_bouldin_value(self):
        metrics = load_model_metrics(MODELS_DIR)
        self.assertAlmostEqual(metrics["davies_bouldin"], 1.198, places=3)

    def test_known_calinski_harabasz_value(self):
        metrics = load_model_metrics(MODELS_DIR)
        self.assertAlmostEqual(metrics["calinski_harabasz"], 84.3, places=1)

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
        self.assertIn("96.1%", self.table)

    def test_contains_risk_match_percentage(self):
        self.assertIn("99.6%", self.table)

    def test_contains_max_composite_delta(self):
        self.assertIn("0.0061", self.table)

    def test_contains_silhouette(self):
        self.assertIn("0.412", self.table)

    def test_contains_davies_bouldin(self):
        self.assertIn("1.198", self.table)

    def test_contains_calinski_harabasz(self):
        self.assertIn("84.3", self.table)

    def test_contains_model_version(self):
        self.assertIn("1.1.1", self.table)

    def test_contains_high_risk_count(self):
        self.assertIn("54", self.table)

    def test_contains_moderate_risk_count(self):
        self.assertIn("191", self.table)

    def test_contains_low_risk_count(self):
        self.assertIn("38", self.table)

    def test_contains_c1_cluster_count(self):
        self.assertIn("75", self.table)

    def test_contains_c2_cluster_count(self):
        self.assertIn("132", self.table)

    def test_contains_c3_cluster_count(self):
        self.assertIn("76", self.table)

    def test_contains_zero_regression_failures(self):
        self.assertIn("0 failures", self.table)

    def test_contains_urgent_count(self):
        self.assertIn("**5 seniors**", self.table)

    def test_contains_baseline_date(self):
        self.assertIn("2026-05-28", self.table)

    def test_is_markdown_table_format(self):
        """Every data row contains pipe separators."""
        pipe_lines = [l for l in self.table.splitlines() if "|" in l and l.strip()]
        self.assertGreaterEqual(len(pipe_lines), 10,
            "Expected at least 10 pipe-separated rows in the evidence table")

    def test_no_placeholder_text(self):
        """Table must not contain 'TBD', 'TODO', 'unknown', 'None', or 'nan'."""
        for placeholder in ["TBD", "TODO", "unknown", "None", "nan"]:
            self.assertNotIn(placeholder, self.table,
                f"Table contains placeholder text: '{placeholder}'")


if __name__ == "__main__":
    loader = unittest.TestLoader()
    suite  = unittest.TestSuite()
    suite.addTests(loader.loadTestsFromTestCase(TestLoadModelMetrics))
    suite.addTests(loader.loadTestsFromTestCase(TestRenderEvidenceTable))
    runner = unittest.TextTestRunner(verbosity=2)
    result = runner.run(suite)
    sys.exit(0 if result.wasSuccessful() else 1)
