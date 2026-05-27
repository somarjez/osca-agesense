---
name: run-osca-system
description: Run, start, build, test, screenshot, or verify the AgeSense OSCA system (Laravel + Python ML). Use this skill when asked to run the app, smoke-test it, check that a PR works, verify ML services, or take a screenshot of a page.
---

# Run: AgeSense OSCA System

Laravel 11 + Livewire web app with two Python Flask ML services (ports 5001/5002).
**Agent path:** run `smoke.ps1` (in this directory) against an already-running system.
**Human path:** double-click `start.bat` in the project root.

All paths below are relative to the project root:
`C:\Users\jramo\OneDrive\Desktop\02. AgeSense\osca-system\osca-system\`

---

## Prerequisites

Already installed on this machine:
- PHP 8.3 (Laragon)
- MySQL 8.4 (Laragon, auto-started by `start.ps1`)
- Node.js 18+ / npm
- Python 3.12 (venv at `python\venv\`)
- All npm, Composer, and pip deps installed

To verify the venv exists:
```powershell
Test-Path python\venv\Scripts\python.exe   # must be True
```

---

## Start the system

```powershell
# Human path - double-click or run:
.\start.bat

# Or PowerShell directly (starts MySQL, ML services, queue worker, Laravel):
.\start.ps1
```

`start.ps1` starts:
- Python ML preprocess service on **:5001**
- Python ML inference service on **:5002**
- Laravel queue worker (background)
- Laravel task scheduler (background)
- Laravel dev server on **:8000** (foreground, Ctrl+C to stop)

**Wait 30-60 s** after first start for Python models to finish loading.

---

## Run (agent path) -- smoke.ps1

Run against an already-running system:

```powershell
# From the project root:
.\.claude\skills\run-osca-system\smoke.ps1 -Password "Admin@OSCA2026!"

# Or set env var to avoid passing it each time:
$env:OSCA_ADMIN_PASSWORD = "Admin@OSCA2026!"
.\.claude\skills\run-osca-system\smoke.ps1

# Boot the system then immediately test it:
.\.claude\skills\run-osca-system\smoke.ps1 -Password "Admin@OSCA2026!" -StartFirst
```

Expected output (all 14 checks):

```
=== ML Services ===
PASS  Preprocess :5001/health                      200  (46 bytes)
PASS  Inference  :5002/health                      200  (207 bytes)

=== Authentication ===
PASS  GET /login                                   200  (8626 bytes)
PASS  POST /login -> dashboard                     200  (75219 bytes)

=== Authenticated Pages ===
PASS  GET /dashboard                               200  (75219 bytes)
PASS  GET /ml/status                               200  (41015 bytes)
PASS  GET /seniors                                 200  (323574 bytes)
PASS  GET /reports/cluster                         200  (141169 bytes)
PASS  GET /reports/risk                            200  (188442 bytes)
PASS  GET /reports/gis                             200  (68922 bytes)
PASS  GET /reports/barangay                        200  (38210 bytes)
PASS  GET /help                                    200  (60858 bytes)
PASS  GET /activity-log                            200  (87059 bytes)

=== GIS API ===
PASS  GET /api/gis/seniors                         200  (0 features bytes)

-------------------------------------------------------------
  ALL PASS  14/14 checks OK
-------------------------------------------------------------
```

`smoke.ps1` exits 0 on all-pass, 1 on any failure.

---

## Run (human path)

Double-click `start.bat`. Browser opens at http://127.0.0.1:8000.

Login: `admin@osca.local` / `Admin@OSCA2026!`

Stop: double-click `stop.bat` (kills PHP, Python services, and PS scheduler).
**Ctrl+C only stops Laravel; Python services keep running on :5001/:5002.**

---

## ML pipeline validation

After changing Python ML code or model files, run these three validators:

```powershell
# Quick: model files load and forward-pass succeeds (seconds)
python\venv\Scripts\python.exe python\tests\test_inference_paths.py
# Expected: ALL CHECKS PASSED

# Full: 51-check artifact consistency suite
python\venv\Scripts\python.exe python\scripts\validate_model_artifacts.py
# Expected: TOTAL: 51 checks | PASS: 51 | FAIL: 0 | WARN: 0

# Reproducibility: identical results across runs
python\venv\Scripts\python.exe python\scripts\test_reproducibility.py
# Expected: PASS: 28 | FAIL: 0
```

---

## PHP test suite

```powershell
php artisan test
```

---

## Check service ports

```powershell
Get-NetTCPConnection -LocalPort 8000,5001,5002 -State Listen -ErrorAction SilentlyContinue
```

---

## Gotchas

**Login 419 (CSRF mismatch):** You must GET `/login` first to receive the session cookie and CSRF token, then POST both in the same session. Using two separate `Invoke-WebRequest` calls without sharing the `-SessionVariable` / `-WebSession` causes 419. The smoke.ps1 driver handles this correctly.

**Windows PowerShell 5.1 limitations:** No `??` (null-coalescing), no `?.`, no ternary `?:`. All scripts targeting this machine must use explicit `if/else`. PS 7+ syntax silently causes parse errors on 5.1.

**`Invoke-WebRequest` pipeline noise:** Without `[void]` or `| Out-Null`, the response object (all headers + content preview) is dumped to stdout. Always suppress with `[void](Invoke-WebRequest ...)` when you don't need the return value.

**ML services take 30-60 s to load on first start:** Health endpoints return `{"status":"ok"}` after models are loaded. The nav bar shows ML status as offline for up to 5 minutes while the cache warms (5-minute offline TTL). Go to `/ml/status` to see real-time status.

**Batch status returns 422:** `GET /ml/batch/status` returns 422 when no batch job is in progress. This is normal -- it means no job ID is in session. Not a bug.

**GIS API returns 0 features:** `/api/gis/seniors` returns a valid GeoJSON FeatureCollection but with 0 features if no seniors have GPS coordinates recorded. This is expected for this dataset.

**`php artisan tinker` is absent:** The intl PHP extension is not loaded (`RuntimeException: "intl" extension required`). Use raw MySQL for ad-hoc DB queries:
```powershell
& "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -u root -e "SELECT email FROM osca_db.users;"
```

**`ENABLE_NOTEBOOK_OVERRIDES=true` (default):** The inference service reads composite_risk, cluster_id, and risk_level from `python\models\predictions\senior_predictions.csv` instead of computing live. If that CSV is missing, results fall back to live ML output, which may differ from notebook-validated values.

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| `smoke.ps1 FAIL POST /login (419)` | GET /login first in same session (CSRF cookie required) |
| `FAIL Preprocess :5001` | Services not started yet; run `.\start.bat` and wait 60 s |
| `FAIL Inference :5002` | Same as above; check `storage\logs\python-inference.log` |
| Pages stuck loading (first open) | ML health check blocks 2 s x 2 services on cold start; wait 90 s then refresh |
| `FAIL GET /reports/cluster-analysis` | Wrong URL; correct route is `/reports/cluster` |
| `FAIL GET /ml/batch/status` (422) | Normal when no batch job running; not a bug |
| Python services still up after Ctrl+C | Run `.\stop.bat` to kill orphaned processes on :5001/:5002 |
| `senior_predictions.csv not found` warning | Place CSV at `python\models\predictions\senior_predictions.csv` (gitignored, share privately) |
