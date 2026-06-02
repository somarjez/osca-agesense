# GIS Functionality and Modified Files

## Overview

The GIS module gives AgeSense a spatial view of senior citizen distribution, community facility access, and health-risk patterns across Pagsanjan, Laguna. It is designed for OSCA planning and monitoring, not household-level surveillance. Senior locations are displayed either as verified/manual points or as privacy-safe generalized barangay-level points.

The module supports:

- Interactive senior distribution mapping.
- Barangay and municipal boundary overlays.
- Public facility overlays.
- Risk, cluster, density, and accessibility heatmaps.
- Bulk barangay-level geocoding for records without coordinates.
- Manual location pin capture during senior profiling.
- GIS proximity scoring against important community facilities.
- Road-network route distance lookup and caching.
- Privacy-safe GIS export for authorized administrators.

## Main GIS Functions

### 1. Interactive GIS Analytics Page

The GIS page is available at:

```text
/reports/gis
```

It displays a Leaflet-based map centered on Pagsanjan. The page shows KPI cards for mapped seniors, high-risk seniors, barangays covered, and GIS data source status. It also includes a bulk geocode status panel so administrators can see whether senior records already have coordinates, approximate coordinates, verified/manual coordinates, or missing GIS data.

The map supports several visualization modes:

- Senior distribution points.
- Barangay-level senior heatmap.
- Generalized barangay-based risk heatmap.
- Senior distribution and accessibility heatmap.
- Barangay density view.
- Risk indicator distribution.
- Health group / cluster distribution.

Users can filter the map by barangay, risk level, and health group / cluster. The map also supports optional KDE heatmap overlays for risk, cluster, and accessibility analysis.

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
- Validating manual pins.
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

### 6. Manual Location Pin in Profile Survey

The senior profile survey now includes map-based location capture. Encoders/admins can set a manual pin inside Pagsanjan while creating or editing a senior profile.

The form validates:

- Latitude and longitude numeric ranges.
- Whether the selected point is inside the Pagsanjan municipal boundary.
- Whether the coordinate pair is usable.

When a valid manual pin is saved, the senior record is updated with:

- Latitude.
- Longitude.
- `location_source = manual_pin`.
- `location_accuracy = verified/manual`.
- `location_verified_at`.

This provides a pathway to gradually improve GIS accuracy as field staff collect more reliable coordinates.

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

### Controllers and Routes

| File | Change Description |
| --- | --- |
| `app/Http/Controllers/GisApiController.php` | Expanded GIS API to serve senior/facility GeoJSON, boundary data, route distances, route cache lookup, route failure handling, generalized barangay points, accessibility metadata, and GIS sample fallback data. |
| `app/Http/Controllers/ReportController.php` | Added GIS page status data, admin bulk geocode action, geocode status calculation, and GIS accessibility CSV export. |
| `routes/api.php` | Added GIS API routes for seniors, facilities, boundaries, and route distance lookup. |
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
| `database/seeders/PagsanjanFacilitySeeder.php` | Updated seeded Pagsanjan facility data used by accessibility scoring and map overlays. |

### Frontend and Views

| File | Change Description |
| --- | --- |
| `resources/views/reports/gis.blade.php` | Major GIS page implementation with Leaflet map, filters, heatmaps, boundary overlays, facilities, cluster/risk/accessibility visualization, route distance popup behavior, and geocode status controls. |
| `resources/views/reports/gis.blade.backup.php` | Backup copy of the GIS Blade view from development. |
| `resources/views/livewire/surveys/profile-survey.blade.php` | Added manual location pin UI, map interaction, boundary validation, and coordinate capture fields. |
| `resources/js/app.js` | Updated frontend bootstrap/import behavior to support GIS page assets/plugins. |

### Documentation

| File | Change Description |
| --- | --- |
| `docs/gis-geocoding.md` | Added operational documentation for bulk barangay-level geocoding. |
| `docs/field-gps-workflow.md` | Added field workflow documentation for capturing verified/manual GPS pins. |
| `docs/GIS_FUNCTIONALITY_AND_MODIFIED_FILES.md` | Added this consolidated GIS functionality and modified-files reference. |

### GIS Data and Runtime Files

| File | Change Description |
| --- | --- |
| `storage/app/gis/boundaries/pagsanjan_barangays.geojson` | Added barangay boundary GeoJSON used by GIS map, geocoder, and pin validation. |
| `storage/app/gis/boundaries/pagsanjan_boundary.geojson` | Added Pagsanjan municipal boundary GeoJSON. |
| `storage/app/gis/geocode_status.json` | Added geocode status tracking file written/read by GIS status features. |
| `storage/app/certs/cacert.pem` | Added local certificate bundle for OpenRouteService SSL verification in environments that need it. |

### Development Backup Files

| File | Change Description |
| --- | --- |
| `backup-before-codex-continue.patch` | Development backup patch file. |
| `backup-current-codex-changes.patch` | Development backup patch file. |

## Recommended Demo Script

1. Open `/reports/gis`.
2. Point out the KPI cards and the bulk geocode status panel.
3. Explain that approximate points are barangay-level only and do not represent exact homes.
4. Switch between Senior Distribution Points, Risk Indicator Distribution, Cluster Distribution, and Accessibility Heatmap.
5. Filter by barangay, risk level, and health group.
6. Show facility markers and route/accessibility context in senior popups.
7. Demonstrate the admin-only Run Bulk Geocode button.
8. Open a senior profile and show the manual location pin workflow.
9. Explain that proximity scoring can be recalculated with `php artisan gis:score-proximity`.
10. Explain that road-route distances can be cached with `php artisan gis:cache-route-distances`.

## Suggested One-Sentence Description

The GIS module provides a privacy-safe spatial analytics layer for OSCA Pagsanjan by mapping generalized senior distribution, health risk, cluster groupings, facility accessibility, and route-distance context for planning and field service prioritization.
