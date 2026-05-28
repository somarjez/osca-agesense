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
