# Design: OSM Facility Importer

**Date:** 2026-06-03
**Branch context:** main

## Problem

The `facilities` table is populated with approximate prototype data (`source = 'sample_prototype_approximate'`) for all 16 Pagsanjan barangays. Coordinates are estimated barangay-center offsets and names are generic placeholders. The Accessibility Heatmap and proximity scoring use these records, so inaccurate coordinates degrade the quality of those features.

OpenStreetMap (OSM) has real, community-verified facility data for Pagsanjan — hospitals, health centers, churches, markets, pharmacies, barangay halls, and more — available free via the Overpass API. Importing this data gives the system accurate coordinates and proper facility names without requiring an API key or paid service.

## Decision

Build a one-shot artisan command `facilities:import-osm` that:
1. Queries the Overpass API for all relevant amenities within Pagsanjan's bounding box
2. Maps OSM tags to the existing facility type vocabulary
3. Upserts by OSM node/way ID so re-runs are safe
4. Deactivates matched approximate records that are superseded by real OSM data

Approximate records remain as fallback for barangays where OSM data is sparse or absent.

## Architecture

### Schema change

Add `osm_id VARCHAR(30) NULL UNIQUE` to the `facilities` table. String type (not bigint) to accommodate both OSM nodes (`node:12345678`) and way centroids (`way:12345678`). The unique index prevents duplicate imports.

### Command: `facilities:import-osm`

**Signature:**
```
facilities:import-osm
    {--dry-run : Preview changes without writing to database}
    {--force : Re-import facilities that already have an osm_id (updates name/coordinates)}
    {--no-supersede : Skip deactivating matched approximate facilities}
```

**Flow:**
1. POST the Overpass query to `https://overpass-api.de/api/interpreter` (configurable via `OVERPASS_API_URL` env var; no API key required)
2. Retry twice on failure with a 3 s delay; if still failing, warn and exit 0 — no data is deleted
3. Map each returned node/way to a `Facility` record using the tag mapping below
4. Assign barangay via point-in-polygon against `storage/app/gis/boundaries/pagsanjan_barangays.geojson` (reuses boundary file already present)
5. Upsert by `osm_id` — new records inserted, existing skipped (or updated if `--force`)
6. For each newly inserted record: deactivate matched approximate facilities within 50 m of the same type (unless `--no-supersede`)
7. Print a summary table and stats

### Overpass query

```
[out:json][timeout:30];
(
  node["amenity"~"hospital|clinic|doctors|health_centre|nursing_home|pharmacy|place_of_worship|marketplace|community_centre|social_facility|townhall|bus_station|taxi"](14.255,121.435,14.290,121.475);
  node["office"="government"](14.255,121.435,14.290,121.475);
  node["shop"~"chemist|supermarket|convenience|general|market"](14.255,121.435,14.290,121.475);
  node["highway"="bus_stop"](14.255,121.435,14.290,121.475);
  way["amenity"~"hospital|marketplace|community_centre|townhall"](14.255,121.435,14.290,121.475);
  way["shop"~"supermarket|market"](14.255,121.435,14.290,121.475);
)->.results;
.results out center tags;
```

Bounding box: `[14.255, 121.435, 14.290, 121.475]` (south, west, north, east — covers all of Pagsanjan municipality).

`out center tags` returns centroids for way polygons (e.g. hospital buildings) so every result has usable coordinates.

### Tag → facility type mapping

| OSM tag | Facility type |
|---|---|
| `amenity=hospital` | `Hospital` |
| `amenity=clinic`, `amenity=doctors`, `amenity=health_centre`, `amenity=nursing_home` | `Health Center` |
| `amenity=pharmacy`, `shop=chemist` | `Pharmacy` |
| `amenity=place_of_worship` | `Church` |
| `amenity=marketplace`, `shop=market` | `Public Market` |
| `shop=supermarket` | `Supermarket` |
| `shop=convenience`, `shop=general` | `Community Store` |
| `amenity=townhall` or `office=government` + name contains "barangay" (case-insensitive) | `Barangay Hall` |
| `amenity=townhall` or `office=government` + name contains "municipal" | `Municipal Hall` |
| `office=government` (other) | `Barangay Hall` |
| `amenity=community_centre` + name contains "senior" | `Senior Center` |
| `amenity=community_centre` (other), `amenity=social_facility` | `Community Store` |
| `amenity=bus_station` | `Transport Hub` |
| `highway=bus_stop` or `amenity=taxi` + name contains "jeepney" (case-insensitive) | `Jeepney Terminal` |
| `highway=bus_stop` (other) | `Transport Hub` |

Nodes with no mappable tag and no usable name are skipped and counted in the "skipped" total.

### Name resolution

1. `tags.name`
2. `tags['name:en']`
3. `tags.operator`
4. Generated placeholder: `"<Facility Type> — <barangay>"` (e.g. `"Health Center — Sabang"`)

### Address resolution

Combine `tags['addr:housenumber']` + `tags['addr:street']` if present. Otherwise: `"<barangay>, Pagsanjan, Laguna"`.

### Barangay assignment

Point-in-polygon check against `pagsanjan_barangays.geojson`. If no polygon matches (node outside all boundaries), `barangay = null`. The facility is still imported.

### Approximate supersession

For each newly inserted OSM facility (skipped if `--no-supersede`):
- Find all `facilities` records where `source = 'sample_prototype_approximate'` AND `type = <same type>` AND Haversine distance < 50 m
- Set `is_active = false`, `source = 'sample_prototype_approximate_superseded'`
- The approximate record remains in the database for recovery if needed

All matching approximate records within 50 m are deactivated (not just the closest one), since approximate facilities are often placed at small offsets around the same real point. 50 m is tight enough to avoid false matches between distinct same-type facilities in the same barangay.

### source field values after this change

| Value | Meaning |
|---|---|
| `sample_prototype_approximate` | Approximate seeded record, still active (no OSM match found) |
| `sample_prototype_approximate_superseded` | Approximate record deactivated because an OSM record now covers it |
| `openstreetmap` | Imported from OSM via this command |

### Re-run behaviour

| Scenario | Behaviour |
|---|---|
| `osm_id` not in DB | Insert new record |
| `osm_id` already in DB, no `--force` | Skip (count as "already imported") |
| `osm_id` already in DB, `--force` | Update `name`, `type`, `latitude`, `longitude`, `address`; leave `barangay`, `source` unchanged; never restore `is_active` if an admin set it to `false` |
| `--dry-run` | Log all planned changes, write nothing |

### Command output

```
Querying Overpass API for Pagsanjan amenities...
Fetched 47 nodes/ways from OpenStreetMap.

Importing...
  ✓  Pagsanjan Rural Health Unit II     Health Center   Barangay II (Poblacion)   [new]
  ✓  St. Isidore Parish Church          Church          Barangay I (Poblacion)    [new]
  ↻  Pagsanjan Public Market            Public Market   Barangay I (Poblacion)    [supersedes approximate]
  –  node:12345678 (no usable name)                                               [skipped]

Done.
  Fetched:    47
  Imported:   38
  Updated:     0  (use --force to update existing)
  Superseded:  6  approximate facilities deactivated
  Skipped:     9  (3 already imported, 6 no usable name/coordinates)
```

## Files Changed

| File | Change |
|---|---|
| `database/migrations/<timestamp>_add_osm_id_to_facilities_table.php` | Add `osm_id VARCHAR(30) NULL UNIQUE` |
| `app/Console/Commands/ImportOsmFacilities.php` | New artisan command |
| `tests/Feature/ImportOsmFacilitiesTest.php` | Feature test with mocked Overpass response |
| `app/Console/Kernel.php` | No change needed — Laravel 11 auto-discovers commands in `app/Console/Commands/` |

## What Does Not Change

- `PagsanjanFacilitySeeder` — untouched; approximate records remain as fallback
- `Facility` model — no new methods needed; `osm_id` is just a fillable column
- GIS API, heatmap, accessibility scoring — all read from `facilities` where `is_active = true`; superseded records are automatically excluded

## Out of Scope

- Scheduled auto-refresh of OSM data
- Admin UI for reviewing superseded records
- OSM data for barangays outside Pagsanjan
- Relations (OSM relation type) — nodes and ways only
