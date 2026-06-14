# GIS Functionality and Modified Files

## Overview

The GIS module gives AgeSense a spatial view of senior citizen distribution, community facility access, and health-risk patterns across Pagsanjan, Laguna. It is designed for OSCA planning and monitoring, not household-level surveillance. Senior locations are displayed as privacy-safe generalized barangay-level points (the manual household-pin capture in the profile form was removed — see §6).

The module supports:

- Interactive senior distribution mapping.
- Barangay and municipal boundary overlays.
- Public facility overlays.
- Risk, cluster, density, and accessibility heatmaps.
- Bulk barangay-level geocoding for records without coordinates.
- GIS proximity scoring against important community facilities.
- Road-network route distance lookup and caching.
- OpenStreetMap facility import to replace approximate seeded facilities with real coordinates.
- Privacy-safe GIS export for authorized administrators.

## Main GIS Functions

### 1. Interactive GIS Analytics Page

The GIS page is available at:

```text
/reports/gis
```

It displays a Leaflet-based map centered on Pagsanjan. The page shows KPI cards for mapped seniors, high-risk seniors, barangays covered, and GIS data source status. It also includes a bulk geocode status panel so administrators can see whether senior records already have coordinates, approximate coordinates, verified/manual coordinates, or missing GIS data.

The map supports four visualization modes (selected from the **Visualization** dropdown):

- **Senior Population Overview** — individual senior distribution points.
- **Risk Indicator Distribution** — KDE risk surface weighted by composite risk score.
- **Cluster / Health Groups Heatmap** — KDE surface colored by assigned health-group/cluster.
- **Accessibility Heatmap** — KDE surface driven by backend accessibility/proximity data, with senior distribution points shown on top.

Users can filter the map by barangay, risk level, and health group / cluster. The heatmap modes render as KDE overlays clipped to Pagsanjan.

### Recent Health Cluster Heatmap UI Changes

The Cluster / Health Groups Heatmap was adjusted to make the map easier to read during GIS review:

- The cluster point toggle now reads `Show senior distribution points`.
- The senior points shown on top of the cluster heatmap use the same point behavior as the senior/accessibility heatmap point layer, including clustered point display when zoomed out.
- Those points still use health group / cluster colors so they remain consistent with the cluster heatmap.
- Cluster heatmap contours were made clearer with fewer contour levels, whiter line color, and less blur.
- Cluster heatmap rendering was tuned for smoother blob edges, softer group boundaries, and better performance during pan/zoom.

### 2. Senior GIS GeoJSON API

Endpoint:

```text
GET /api/gis/seniors
```

This returns active senior records as a GeoJSON FeatureCollection. Each senior feature includes spatial coordinates and planning metadata such as:

- OSCA/anonymized identifier.
- Age.
- Barangay.
- Risk level.
- Composite risk score.
- Cluster / health group.
- GIS proximity score.
- Accessibility status.
- Coordinate mode.
- Location source and accuracy.

The API protects privacy by supporting generalized barangay-level coordinates. If a senior has verified coordinates, the system can use those. If not, it can use deterministic points inside the senior's barangay boundary so records can still be visualized without exposing household addresses.

### 3. Facility GIS GeoJSON API

Endpoint:

```text
GET /api/gis/facilities
```

This returns active facilities as GeoJSON points. Facilities include health centers, hospitals, pharmacies, markets, barangay halls, and other public/community reference points used for accessibility analysis.

Each facility feature includes:

- Facility ID.
- Facility name.
- Facility type.
- Barangay.
- Source.
- Latitude and longitude.

### 4. Boundary APIs

Endpoints:

```text
GET /api/gis/boundary/pagsanjan
GET /api/gis/boundary/barangays
```

These endpoints serve local GeoJSON boundary files for the Pagsanjan municipal boundary and barangay boundaries. They are used by both the GIS map and the profile form location picker.

Boundary data supports:

- Clipping map overlays to Pagsanjan.
- Generating barangay-level approximate points.
- Grouping and labeling barangay-level map data.

### 5. Bulk Barangay-Level Geocoding

Command:

```text
php artisan gis:geocode
```

Admin page action:

```text
POST /reports/gis/geocode
```

Bulk geocoding assigns privacy-safe approximate coordinates to seniors who do not have usable GIS coordinates. The command searches for the senior's barangay polygon and places a deterministic point inside it. This means the same senior will receive a stable approximate point, but the point does not represent the senior's actual home.

Supported command options:

```text
php artisan gis:geocode --dry-run
php artisan gis:geocode --barangay=Cabanbanan
php artisan gis:geocode --limit=25
php artisan gis:geocode --force
```

Important behavior:

- Verified manual/GPS coordinates are not overwritten.
- Existing generated coordinates are skipped unless `--force` is used.
- Points are validated against barangay and municipal boundaries.
- A status file is written to `storage/app/gis/geocode_status.json`.
- Generated locations are marked as approximate, not verified.

### 6. Manual Location Pin in Profile Survey — Removed

> **Removed.** The profile survey previously included a map-based "Verified Location Pin" picker that wrote `location_source = manual_pin` / `location_accuracy = verified/manual`. It was removed because `gis:geocode` already pre-fills approximate barangay-level coordinates, which made the editable lat/lng fields misleading (they looked like real household pins). All senior coordinates now come from `gis:geocode` only.
>
> The `latitude` / `longitude` / `location_source` / `location_accuracy` / `location_verified_at` columns remain on `senior_citizens`, and `gis:geocode` still refuses to overwrite any pre-existing `manual_pin` / `gps_capture` rows, but **no part of the UI writes verified pins anymore**. A future address-line-based capture workflow may reintroduce verified coordinates.

### 7. GIS Proximity Scoring

Command:

```text
php artisan gis:score-proximity
```

This command calculates accessibility metrics for seniors with valid coordinates. It finds the nearest relevant facilities and saves distances to the `senior_accessibility_metrics` table.

Facility categories used in scoring:

- Health center.
- Hospital.
- Pharmacy.
- Market.
- Barangay hall.

The score is stored as a 0 to 1 accessibility score. Higher values indicate better access based on proximity to nearby facilities. The GIS page displays this as a percentage-style GIS proximity score.

Supported options:

```text
php artisan gis:score-proximity --dry-run
php artisan gis:score-proximity --senior-id=123
php artisan gis:score-proximity --barangay=Cabanbanan
```

### 8. Road-Network Route Distance Lookup

Endpoint:

```text
GET /api/gis/route-distance
```

This endpoint accepts an origin point and destination point, then returns a driving route distance and duration using OpenRouteService when available.

It supports caching by senior and facility pair:

- If a cached route exists and coordinates still match, the cached value is returned.
- If a previous permanent route failure exists, the failure is reused instead of repeatedly calling the provider.
- If no cached record exists, the system calls OpenRouteService.

This improves map popup performance and reduces repeated external API calls.

### 9. Route Distance Pre-Caching

Command:

```text
php artisan gis:cache-route-distances
```

This command precomputes road-network route distances from senior points to nearby senior-relevant facilities.

Useful options:

```text
php artisan gis:cache-route-distances --dry-run
php artisan gis:cache-route-distances --seniors=50
php artisan gis:cache-route-distances --facilities=5
php artisan gis:cache-route-distances --max-requests=1500
php artisan gis:cache-route-distances --force
```

The command includes safeguards for external API use:

- Request limits.
- Sleep delay between requests.
- Rate-limit stopping behavior.
- Cached route skipping.
- Permanent route failure storage.

### 10. GIS Export

Endpoint:

```text
GET /reports/gis/export
```

This provides an admin-only CSV export for GIS and accessibility planning. It can export senior GIS records with:

- Anonymized senior ID.
- Barangay.
- Latitude and longitude.
- Location source and accuracy.
- Nearest facility distances.
- GIS proximity score.
- Cluster label.
- Risk indicator.

The export is privacy-oriented and intended for planning, reporting, and accessibility review.

### 11. Committed Facility Dataset Sync

The application ships with a reviewed 155-record facility dataset at
`database/gis/facilities/pagsanjan_facilities_thesis_final_cleaned.geojson`.
Synchronize it into the database with:

```text
php artisan facilities:sync-geojson
php artisan gis:score-proximity
```

The sync is local and does not call OSM, ORS, or Google Maps. It upserts records
by `osm_id`, preserves matching database IDs, and deactivates obsolete managed
prototype records instead of deleting them. Use `--dry-run` to validate the
dataset without writing, or `--keep-existing` to skip stale-record deactivation.

`PagsanjanFacilitySeeder` uses the same sync service, so
`php artisan db:seed --class=PagsanjanFacilitySeeder` produces the same database
state.

### 12. OpenStreetMap Facility Import

Command:

```text
php artisan facilities:import-osm
```

This command queries the OpenStreetMap Overpass API for Pagsanjan amenities (health centers, hospitals, pharmacies, markets, barangay halls) and imports them as `Facility` records with real coordinates and an `osm_id`. When an imported facility falls within ~50 m of an existing approximate/seeded facility, the seeded one is deactivated (superseded) so accessibility scoring uses the real location.

Supported options:

```text
php artisan facilities:import-osm --dry-run
php artisan facilities:import-osm --force
php artisan facilities:import-osm --no-supersede
```

- `--dry-run` previews fetched/imported/superseded counts without writing.
- `--force` re-imports facilities that already have an `osm_id`.
- `--no-supersede` keeps matched approximate facilities active instead of deactivating them.

After importing, re-run `php artisan gis:score-proximity` so accessibility metrics reflect the updated facility coordinates.

## Privacy and Safety Design

The GIS module intentionally avoids treating every senior map point as an exact household location. Records can be displayed using generalized barangay-level points, and the UI clearly states that approximate points are not exact homes.

Key privacy protections:

- Generalized points are used for records without verified coordinates.
- Bulk geocoding does not mark generated points as verified.
- Verified/manual coordinates are not overwritten by the geocoder.
- GIS exports use anonymized IDs.
- Boundaries are used to keep generated/manual points inside Pagsanjan.
- Route distances are cached by senior/facility pair to reduce repeated external requests.

## API and Route Summary

| Route | Purpose |
| --- | --- |
| `GET /reports/gis` | GIS analytics page |
| `GET /reports/gis/export` | Admin GIS accessibility CSV export |
| `POST /reports/gis/geocode` | Admin action to run bulk geocoding |
| `GET /api/gis/seniors` | Senior GeoJSON data |
| `GET /api/gis/facilities` | Facility GeoJSON data |
| `GET /api/gis/boundary/pagsanjan` | Pagsanjan municipal boundary GeoJSON |
| `GET /api/gis/boundary/barangays` | Barangay boundary GeoJSON |
| `GET /api/gis/route-distance` | Road-network route distance lookup |

## Artisan Command Summary

| Command | Purpose |
| --- | --- |
| `php artisan gis:geocode` | Assign approximate barangay-level coordinates |
| `php artisan gis:score-proximity` | Calculate nearest facility distances and accessibility scores |
| `php artisan gis:cache-route-distances` | Precompute road-route distances to nearby facilities |
| `php artisan facilities:sync-geojson` | Synchronize the committed 155-record facility dataset into the database |
| `php artisan facilities:import-osm` | Import real facility coordinates from OpenStreetMap and supersede approximate seeded facilities |

## Modified and Added Files

The following files were changed on the GIS branch compared with `origin/main`.

### Environment and Configuration

| File | Change Description |
| --- | --- |
| `.env.example` | Added OpenRouteService-related environment variables and GIS route request settings. |
| `config/services.php` | Added OpenRouteService configuration such as base URL, timeouts, retry behavior, SSL options, and route request parameters. |

### Console Commands

| File | Change Description |
| --- | --- |
| `app/Console/Commands/GeocodeSeniors.php` | Added privacy-safe barangay-level geocoding command for seniors missing coordinates. |
| `app/Console/Commands/ScoreGisProximity.php` | Added command to calculate nearest facility distances and GIS accessibility scores. |
| `app/Console/Commands/CacheGisRouteDistances.php` | Added command to precompute OpenRouteService route distances for senior/facility pairs. |
| `app/Console/Commands/ImportOsmFacilities.php` | Added command to import real facility coordinates from the OpenStreetMap Overpass API and supersede approximate seeded facilities. |

### Controllers and Routes

| File | Change Description |
| --- | --- |
| `app/Http/Controllers/GisApiController.php` | Expanded GIS API to serve senior/facility GeoJSON, boundary data, route distances, route cache lookup, route failure handling, generalized barangay points, accessibility metadata, and GIS sample fallback data. |
| `app/Http/Controllers/ReportController.php` | Added GIS page status data, admin bulk geocode action, geocode status calculation, and GIS accessibility CSV export. |
| `routes/web.php` | Added the GIS API routes (seniors, facilities, boundaries, route distance) **inside the authenticated session group** so browser `fetch` calls carry session auth. `routes/api.php` deliberately contains no GIS routes — it only points to `web.php`. |
| `routes/reports.php` | Added admin GIS export and bulk geocode routes. |

### Models

| File | Change Description |
| --- | --- |
| `app/Models/Facility.php` | Updated facility model relationships/casts to support GIS accessibility and route distance use. |
| `app/Models/SeniorAccessibilityMetric.php` | Updated fillable/cast fields for expanded accessibility metrics including hospital and pharmacy distances. |
| `app/Models/SeniorCitizen.php` | Added relationship support for latest accessibility metric and route/accessibility GIS behavior. |
| `app/Models/SeniorFacilityRouteDistance.php` | Added model for cached senior-to-facility road route distances. |
| `app/Models/SeniorFacilityRouteFailure.php` | Added model for cached route lookup failures to avoid repeated failed provider calls. |

### Database

| File | Change Description |
| --- | --- |
| `database/migrations/2026_05_26_000001_add_hospital_and_pharmacy_to_accessibility_metrics.php` | Added hospital and pharmacy nearest facility references and distance fields to accessibility metrics. |
| `database/migrations/2026_05_27_000001_create_senior_facility_route_distances_table.php` | Added route distance and route failure cache tables. |
| `database/migrations/2026_06_03_000001_add_osm_id_to_facilities_table.php` | Added `osm_id` to facilities so OpenStreetMap-imported facilities can be matched and deduplicated. |
| `database/gis/facilities/pagsanjan_facilities_thesis_final_cleaned.geojson` | Reviewed 155-record facility source dataset used for reproducible database synchronization. |
| `database/seeders/PagsanjanFacilitySeeder.php` | Synchronizes the committed facility GeoJSON into the database through the shared dataset service. |

### Frontend and Views

| File | Change Description |
| --- | --- |
| `resources/views/reports/gis.blade.php` | Major GIS page implementation with Leaflet map, filters, heatmaps, boundary overlays, facilities, cluster/risk/accessibility visualization, route distance popup behavior, geocode status controls, refined health-cluster heatmap contours, and senior distribution point display for cluster heatmap review. |
| `resources/views/livewire/surveys/profile-survey.blade.php` | Added (then later **removed**) the manual location pin UI, map interaction, boundary validation, and coordinate capture fields. The picker is no longer part of the form. |
| `resources/js/app.js` | Updated frontend bootstrap/import behavior to support GIS page assets/plugins. |

### Documentation

| File | Change Description |
| --- | --- |
| `docs/gis-geocoding.md` | Added operational documentation for bulk barangay-level geocoding. |
| `docs/GIS_FUNCTIONALITY_AND_MODIFIED_FILES.md` | Added this consolidated GIS functionality and modified-files reference. |

### GIS Data and Runtime Files

| File | Change Description |
| --- | --- |
| `storage/app/gis/boundaries/pagsanjan_barangays.geojson` | Added barangay boundary GeoJSON used by GIS map, geocoder, and pin validation. |
| `storage/app/gis/boundaries/pagsanjan_boundary.geojson` | Added Pagsanjan municipal boundary GeoJSON. |
| `storage/app/gis/geocode_status.json` | Added geocode status tracking file written/read by GIS status features. |
| `storage/app/certs/cacert.pem` | Added local certificate bundle for OpenRouteService SSL verification in environments that need it. |

## Recommended Demo Script

1. Open `/reports/gis`.
2. Point out the KPI cards and the bulk geocode status panel.
3. Explain that approximate points are barangay-level only and do not represent exact homes.
4. Switch between Senior Population Overview, Risk Indicator Distribution, Cluster / Health Groups Heatmap, and Accessibility Heatmap.
5. Filter by barangay, risk level, and health group.
6. Show facility markers and route/accessibility context in senior popups.
7. Demonstrate the admin-only Run Bulk Geocode button.
8. Explain that proximity scoring can be recalculated with `php artisan gis:score-proximity`.
9. Explain that road-route distances can be cached with `php artisan gis:cache-route-distances`.

## Suggested One-Sentence Description

The GIS module provides a privacy-safe spatial analytics layer for OSCA Pagsanjan by mapping generalized senior distribution, health risk, cluster groupings, facility accessibility, and route-distance context for planning and field service prioritization.
