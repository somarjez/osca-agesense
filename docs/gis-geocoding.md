# GIS Barangay-Level Geocoding

AgeSense GIS geocoding is privacy-safe barangay-level geocoding. It does not
represent exact household locations.

## Command

```bash
php artisan gis:geocode
```

The command assigns approximate coordinates to senior records that have missing
or invalid latitude/longitude values. It uses local Pagsanjan boundary files
only and does not call external online geocoding APIs.

## What It Stores

For eligible senior records, the command stores:

- `latitude`
- `longitude`
- `location_source = barangay_generalized` or `barangay_centroid`
- `location_accuracy = barangay_level` or `approximate`
- `location_verified_at = null`

Generated coordinates are not verified and must not be interpreted as household
pins. They exist only to support barangay-level visualization, accessibility
context, and planning workflows.

## Coordinate Method

The command uses this priority:

1. Generate a deterministic privacy-safe point inside the assigned barangay
   polygon when barangay boundaries are available.
2. Use a representative barangay polygon point or configured barangay anchor
   when needed.
3. Use the Pagsanjan municipal center only when barangay data is missing, and
   report that fallback in the command output.

The deterministic point is stable for the same senior record, but it is not the
senior's real home location.

## Safety Rules

The command never overwrites records that are already marked as verified/manual
or GPS captured:

- `location_source = manual_pin`
- `location_source = gps_capture`
- `location_accuracy` containing `verified` or `manual`

Generated points are validated against the Pagsanjan municipal boundary. When a
barangay polygon is available, generated points are also validated against the
assigned barangay polygon.

## Options

```bash
php artisan gis:geocode --dry-run
php artisan gis:geocode --barangay=Cabanbanan
php artisan gis:geocode --limit=25
php artisan gis:geocode --force
```

- `--dry-run` previews how many records would be updated.
- `--barangay=` processes one barangay.
- `--limit=` limits records for testing.
- `--force` rebuilds only non-verified/generated coordinates.

## Exact Coordinates

Exact coordinates require a future authorized manual pinning or GPS capture
workflow. Until then, GIS outputs should be described as barangay-level
approximations for planning and accessibility context.

Public reports should continue to use generalized or approximate locations and
must not expose household coordinates, full names, contact numbers, or exact
addresses.
