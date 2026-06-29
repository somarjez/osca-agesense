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
| `app/Console/Commands/GeocodeSeniors.php` | Step 1 — assign barangay-level coordinates; auto-chains Steps 2 & 3 when coordinates change |
| `app/Console/Commands/ScoreGisProximity.php` | Step 2 — accessibility scores using **road-network distance** (cached ORS, falling back to straight-line) |
| `app/Console/Commands/CacheGisRouteDistances.php` | Step 3 — precompute ORS road-network distances for the **nearest facility per type** per senior |
| `resources/views/reports/gis.blade.php` | The Leaflet map UI (~4800 lines, inline JS) |
| `app/Http/Controllers/SeniorCitizenController.php` | `locationPanel()` builds the profile "Location & Accessibility" card (nearest facility per type, cached ORS routes) |
| `resources/views/seniors/show.blade.php` | Senior profile incl. the full-width Location & Accessibility card + mini-map |
| `storage/app/gis/boundaries/` | GeoJSON boundary files (barangays + municipal boundary) |
| `storage/app/certs/cacert.pem` | Optional CA bundle for ORS SSL — only used if `OPENROUTESERVICE_CA_BUNDLE` is set |

---

## Data Pipeline (run in this order)

```
1. gis:geocode          → assigns lat/lng to seniors (barangay centroid)
2. gis:score-proximity  → writes SeniorAccessibilityMetric rows (road-distance aware)
3. gis:cache-route-distances → writes SeniorFacilityRouteDistance rows (requires ORS API key)
```

Each step is idempotent. Steps 1 and 2 are safe to re-run at any time. Step 3 calls an external API and burns quota — use `--dry-run` first.

**Auto-chaining:** `gis:geocode` now runs Steps 2 and 3 itself whenever it changes any coordinates — it runs `gis:score-proximity` inline (local, fast) and **queues** `gis:cache-route-distances` (the throttled ORS part). So a geocode keeps the accessibility data aligned automatically. Opt out with `gis:geocode --skip-recompute`. **A queue worker must be running** for the queued route recompute to execute, and the worker must be **restarted after code changes** (`php artisan queue:restart`) or it runs stale code (see Gotchas).

**Where computation happens:**
- *Profile page* — never calls ORS live. It ranks the nearest facility per senior-relevant type by local haversine, then shows the **cached** ORS road route where fresh (road distance as the primary number + the "X min drive", matching the map popup), else straight-line.
- *Map popup* — for the nearest ~5 it calls the `route-distance` endpoint (cache-first; live ORS on a miss, then persists); the rest of the listed types show straight-line.
- *Bulk precompute* (`gis:cache-route-distances`) — the only step that fills road routes for everyone.

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

# Geocode only, skip the proximity/route recompute chain
php artisan gis:geocode --skip-recompute
```

When it changes any coordinates (and not `--dry-run` / `--skip-recompute`), it then runs `gis:score-proximity` inline and queues `gis:cache-route-distances` so accessibility data follows the new coordinates. If 0 seniors needed coordinates, nothing is recomputed.

Expected output columns: `Total seniors checked`, `Seniors updated`, `Skipped because already verified`, `Skipped because existing coordinates are present`.

Verify success:
```bash
php artisan gis:geocode --dry-run
# "Seniors that would be updated" should be 0 after a successful run
```

---

### Step 2 — `gis:score-proximity`

Calculates accessibility scores (0.0–1.0) from each geocoded senior to the 5 facility categories (health center, hospital, pharmacy, market, barangay hall). Writes to `senior_accessibility_metrics`. No external API calls of its own — it **reads the cached ORS road-network distance** for each category's nearest facility (coordinate-fresh) and uses that in the score, **falling back to straight-line** where a route isn't cached yet. So the % reflects real travel distance and stays consistent with the routes shown on the profile/map. (The `distance_to_*_m` columns still record the straight-line distance.) Run it again after the route cache grows to upgrade more seniors from straight-line to road-based.

**Detour guard + cap recalibration** (in `ScoreGisProximity.php`): barangay-centroid origins that don't snap to the OSM graph can yield absurd ORS routes (observed up to ~16×). A cached road distance is trusted only when its detour over straight-line is ≤ `MAX_TRUSTED_DETOUR` (3.0×); above that the score falls back to straight-line. The per-category caps were calibrated for straight-line, so they are widened by `ROAD_DETOUR_FACTOR` (1.4×, the observed median detour) before scoring. Without these, whole rural barangays scored near 0%.

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

Calls OpenRouteService (ORS) API to precompute road-network distances. It caches the **nearest facility of each type** per senior (the same set the profile panel and map popup display, so the cached pairs match what's shown), ordered by distance. **Requires `OPENROUTESERVICE_API_KEY` in `.env`** (and `php artisan config:clear` after adding it).

The skip-check is **freshness-aware**: a cached route counts as present only if its stored endpoints still match the senior's and facility's current coordinates. So after a geocode moves a senior, its stale routes are recomputed automatically — no `--force` needed. The run resumes where it left off (re-run to continue after hitting a cap/quota).

```bash
# Always dry-run first to see how many ORS requests will be needed
php artisan gis:cache-route-distances --dry-run

# Limited run (test with 5 seniors)
php artisan gis:cache-route-distances --seniors=5 --sleep-ms=1000

# Production run (default: --facilities=12, 1500 max requests, 2500ms sleep)
php artisan gis:cache-route-distances --facilities=12

# Recompute one senior (used by the geocode auto-chain)
php artisan gis:cache-route-distances --senior-id=42

# Re-cache everything (overwrites cached pairs)
php artisan gis:cache-route-distances --force
```

Key flags:
| Flag | Default | Meaning |
|---|---|---|
| `--seniors=N` | all | Max seniors to process |
| `--senior-id=N` | — | Cache a single senior only (post-geocode recompute) |
| `--facilities=N` | 12 | Nearest facilities (per type) to cache per senior |
| `--max-requests=N` | 1500 | ORS request cap for this run |
| `--stop-after-rate-limits=N` | 1 | Stop after N rate-limit (429) or auth (401/403) errors |
| `--sleep-ms=N` | 2500 | Delay between ORS requests (ms) |
| `--dry-run` | false | Count pairs without calling ORS |
| `--force` | false | Recalculate existing cached pairs |
| `--osrm-on-quota` | false | On an ORS quota/rate-limit error (403/429), fall back to public OSRM instead of stopping (still road-network distance). Use to finish coverage when the ORS free-tier quota is exhausted. |

The batch uses its own **patient ORS timeouts** (`services.openrouteservice.batch_*`: 15s connect / 40s / 2 retries) so the free tier's variable latency doesn't time out into the OSRM fallback; the live popup keeps short timeouts. ORS free tier: **2000 requests/day**. Full coverage (≈283 seniors × ~9 types ≈ 2,500 pairs) exceeds the daily quota, so it takes a few re-runs (it resumes automatically).

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

**Map rendering is already performance-optimized — don't redo it**
The heavy client-side render costs in `reports/gis.blade.php` have already been
fixed and shipped. Don't reintroduce the slow patterns or "re-optimize" from
scratch. Already in place:
- **Canvas markers** — seniors render via `L.circleMarker` on a shared
  `L.canvas()` renderer (`getCanvasRenderer(map)`), not per-marker DivIcons.
- **Boundary mask** — heatmap clipping uses a rasterized inside/outside bitmap
  (`buildBoundaryMask` / `getRasterBoundaryMask`), not per-pixel point-in-polygon
  ray-casting.
- **Pan vs. zoom** — heat layers reposition on pan and only repaint on
  zoom/resize; raster resolution is capped; per-senior boundary validation is
  memoized once per data load (`prevalidateAllFeatures`).

If the map feels slow, profile first (DevTools Performance) and confirm which of
these regressed before adding new machinery. Note the system-wide render work
(Chart.js double-load, dashboard chart animations, CSS motion) lives **outside**
this file — see the `osca-performance` skill.

**`config:cache` breaks ORS key**
After adding/changing `OPENROUTESERVICE_API_KEY` in `.env`, always run `php artisan config:clear` (or `php artisan config:cache`) to pick up the new value. Using `env()` directly in PHP code does not work after config caching.

**ORS free-tier quota**
The free tier allows ~2000 requests/day. A full run for all 283 seniors × ~9 senior-relevant types ≈ 2,500 requests — more than one day's quota, so it stops on a 429 and must be re-run (it resumes, skipping fresh-cached pairs). Use `--dry-run` to count before running.

**Queue worker runs stale code (recompute won't fire)**
The geocode → score/route recompute chain runs through the queue. A long-running `php artisan queue:work` process loads code at boot and does **not** pick up changes until restarted. After deploying/pulling, run `php artisan queue:restart`, or newly-geocoded seniors will get coordinates but no accessibility score/routes. Quick manual fix: `php artisan gis:score-proximity`.

**Front-end assets are gitignored — rebuild after pulling**
`public/build/` is not committed. GIS/profile UI changes add Tailwind classes that only exist after `npm run build`. After pulling GIS UI changes, run `npm run build` (no migration is needed for the GIS feature work — it reuses existing tables).

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
