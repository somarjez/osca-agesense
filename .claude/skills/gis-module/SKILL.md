---
name: gis-module
description: Use when working on anything GIS-related in the AgeSense OSCA system — understanding the module architecture, running the GIS artisan commands, debugging map issues, or modifying the GIS report. Covers data pipeline, safe command flags, API endpoints, and known gotchas.
---

# GIS Module — AgeSense OSCA

The GIS module gives OSCA staff an interactive Pagsanjan map showing senior distribution, risk heatmaps, facility accessibility, and route-distance context. It is privacy-safe: individual senior points are generalized to barangay level unless a field-verified GPS pin exists.

---

## Key Files

| File | Role |
|---|---|
| `app/Http/Controllers/GisApiController.php` | Serves all 5 `/api/gis/*` JSON endpoints |
| `app/Console/Commands/GeocodeSeniors.php` | Step 1 — assign barangay-level coordinates to seniors |
| `app/Console/Commands/ScoreGisProximity.php` | Step 2 — calculate accessibility scores from coordinates to nearby facilities |
| `app/Console/Commands/CacheGisRouteDistances.php` | Step 3 — precompute ORS road-network route distances for senior popups |
| `resources/views/reports/gis.blade.php` | The Leaflet map UI (~4800 lines, inline JS) |
| `app/Livewire/Surveys/ProfileSurvey.php` | GPS pin capture and manual location in the senior profile survey |
| `storage/app/gis/boundaries/` | GeoJSON boundary files (barangays + municipal boundary) |
| `storage/app/certs/cacert.pem` | Optional CA bundle for ORS SSL — only used if `OPENROUTESERVICE_CA_BUNDLE` is set |

---

## Data Pipeline (run in this order)

```
1. gis:geocode          → assigns lat/lng to seniors (barangay centroid)
2. gis:score-proximity  → writes SeniorAccessibilityMetric rows
3. gis:cache-route-distances → writes SeniorFacilityRouteDistance rows (requires ORS API key)
```

Each step is idempotent. Steps 1 and 2 are safe to re-run at any time. Step 3 calls an external API and burns quota — use `--dry-run` first.

---

## Running Commands Safely

### Step 1 — `gis:geocode`

Assigns barangay-level geocoordinates to all active seniors without GPS pins. Uses the local GeoJSON boundaries — no external API calls.

```bash
# Safe first run (preview only)
php artisan gis:geocode --dry-run

# Run for real
php artisan gis:geocode

# Re-geocode all (overwrite existing barangay-level coordinates, keep verified pins)
php artisan gis:geocode --force

# Single barangay
php artisan gis:geocode --barangay="Poblacion 1"

# Limit rows for testing
php artisan gis:geocode --dry-run --limit=10
```

Expected output columns: `Total seniors checked`, `Seniors updated`, `Skipped because already verified`, `Skipped because existing coordinates are present`.

Verify success:
```bash
php artisan gis:geocode --dry-run
# "Seniors that would be updated" should be 0 after a successful run
```

---

### Step 2 — `gis:score-proximity`

Calculates accessibility scores (0.0–1.0) from each geocoded senior to the 5 nearest facility categories. Writes to `senior_accessibility_metrics`. No external API calls.

```bash
# Preview scores without saving
php artisan gis:score-proximity --dry-run

# Score all seniors
php artisan gis:score-proximity

# Single senior (by DB id)
php artisan gis:score-proximity --senior-id=42

# Single barangay
php artisan gis:score-proximity --barangay="Barangay I (Pob.)"
```

Score interpretation: 1.0 = perfectly close to all facility types; 0.0 = at or beyond the distance cap for all. `null` = no facilities in DB (run `php artisan db:seed --class=PagsanjanFacilitySeeder` first).

---

### Step 3 — `gis:cache-route-distances`

Calls OpenRouteService (ORS) API to precompute road-network distances for senior popup accessibility panels. **Requires `OPENROUTESERVICE_API_KEY` in `.env`** (and `php artisan config:clear` after adding it).

```bash
# Always dry-run first to see how many ORS requests will be needed
php artisan gis:cache-route-distances --dry-run

# Limited run (test with 5 seniors)
php artisan gis:cache-route-distances --seniors=5 --sleep-ms=1000

# Production run (default: 1500 max requests, 2500ms sleep)
php artisan gis:cache-route-distances

# Re-cache everything (overwrites cached pairs)
php artisan gis:cache-route-distances --force
```

Key flags:
| Flag | Default | Meaning |
|---|---|---|
| `--seniors=N` | all | Max seniors to process |
| `--facilities=N` | 5 | Nearby facilities per senior |
| `--max-requests=N` | 1500 | ORS request cap for this run |
| `--stop-after-rate-limits=N` | 1 | Stop after N rate-limit (429) or auth (401/403) errors |
| `--sleep-ms=N` | 2500 | Delay between ORS requests (ms) |
| `--dry-run` | false | Count pairs without calling ORS |
| `--force` | false | Recalculate existing cached pairs |

ORS free tier: **2000 requests/day**. Check remaining quota before large runs.

---

## GIS API Endpoints

All 5 endpoints require authentication (`auth` + `role:admin,encoder,viewer`). They are served from `routes/web.php` (NOT `routes/api.php`) so session auth works from the browser. The `route-distance` endpoint is throttled to 60 req/min per user.

| Endpoint | Returns | Notes |
|---|---|---|
| `GET /api/gis/seniors` | GeoJSON FeatureCollection — one Feature per active senior with coordinates | Includes risk, cluster, accessibility score; coordinates are barangay-level unless manual pin |
| `GET /api/gis/facilities` | GeoJSON FeatureCollection — all active facilities | Typed: health center, hospital, pharmacy, market, barangay hall |
| `GET /api/gis/boundary/pagsanjan` | GeoJSON Polygon — municipal boundary | Loaded from `storage/app/gis/boundaries/pagsanjan_boundary.geojson` |
| `GET /api/gis/boundary/barangays` | GeoJSON FeatureCollection — 16 barangay polygons | Loaded from `storage/app/gis/boundaries/pagsanjan_barangays.geojson`; cached 24h |
| `GET /api/gis/route-distance` | JSON — road distance + duration in metres/seconds | Throttled: 60 req/min/user; results cached in `senior_facility_route_distances` |

`routeDistance` query parameters:
- `origin_lat`, `origin_lng`, `destination_lat`, `destination_lng` — required floats
- `senior_id`, `facility_id` — optional ints; when provided, results are cached to DB

---

## Key Models

| Model | Table | Key columns |
|---|---|---|
| `SeniorAccessibilityMetric` | `senior_accessibility_metrics` | `senior_citizen_id`, `accessibility_score` (0.0–1.0), `distance_to_*_m`, `nearest_*_id` |
| `SeniorFacilityRouteDistance` | `senior_facility_route_distances` | `senior_citizen_id`, `facility_id`, `route_distance_m`, `route_duration_s`, `provider` |
| `SeniorFacilityRouteFailure` | `senior_facility_route_failures` | `senior_citizen_id`, `facility_id`, `status_code`, `error_message` — permanent failures (no road route exists) |
| `Facility` | `facilities` | `name`, `type`, `latitude`, `longitude`, `is_active` — seeded via `PagsanjanFacilitySeeder` |

---

## Gotchas

**`config:cache` breaks ORS key**
After adding/changing `OPENROUTESERVICE_API_KEY` in `.env`, always run `php artisan config:clear` (or `php artisan config:cache`) to pick up the new value. Using `env()` directly in PHP code does not work after config caching.

**ORS free-tier quota**
The free tier allows ~2000 requests/day. One full run for all 283 seniors × 5 facilities = up to 1415 requests. Use `--dry-run` to count before running. Set `--max-requests=200` for partial runs.

**OSRM fallback is a demo server**
When ORS is unreachable, the command falls back to `router.project-osrm.org`. This is a public demo server — do not use it for production bulk runs. It has been known to rate-limit IPs.

**Barangay GeoJSON location**
Boundary files live in `storage/app/gis/boundaries/` (git-tracked — they are static data, not runtime artifacts). The `storage/app/gis/` path is gitignored for runtime files only.

**GeoJSON cached for 24 hours**
`/api/gis/boundary/barangays` results are cached via `Cache::remember('gis.barangay_boundary_features', 24h)`. If you update the GeoJSON file, run `php artisan cache:clear` to invalidate.

**Manual GPS pins are preserved**
`gis:geocode --force` overwrites barangay-level coordinates but never overwrites `location_source = 'manual_pin'` or `'gps_capture'`. Those are protected by the `isVerifiedCoordinate()` check in the command.

**Smoke test for GIS API**
The existing `smoke.ps1` already checks `GET /api/gis/seniors`. Run it to verify the module is working end-to-end:
```powershell
.\.claude\skills\run-osca-system\smoke.ps1 -Password "Admin@OSCA2026!"
```
