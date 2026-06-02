# Design: Remove Manual Location Pin from Profile Form

**Date:** 2026-06-03
**Branch context:** fix/gis-module-security-reviewed

## Problem

The senior profile edit form contains a "Verified Location Pin" section — a Leaflet map picker with editable latitude/longitude fields. This section exists to let admins manually set a verified household coordinate per senior.

However, `gis:geocode` already fills approximate barangay-level coordinates automatically for all seniors. These approximate values appear pre-filled in those inputs, misleading admins into thinking they are editing real household locations. The manual pin workflow is not ready for use: the future plan is to derive coordinates from an address-line field, which has not been built yet.

The lat/lng fields in the profile form are currently confusing and should be removed. The `gis:geocode` command and GIS analytics are unaffected.

## Decision

Remove the "Verified Location Pin" section from the profile form entirely — both the UI and all supporting backend code in the Livewire component. Delete `field-gps-workflow.md`. The `gis:geocode` command continues to run as a background artisan command for GIS analytics only.

## What Changes

### `resources/views/livewire/surveys/profile-survey.blade.php`

Remove the entire "Verified Location Pin" block inside the `@if ($senior)` guard (the map div, lat/lng inputs, hidden `locationPinTouched` input, and the surrounding section wrapper).

Remove the entire `@once @push('scripts') ... @endpush @endonce` block — the full self-executing JS function (≈315 lines) that drives the Leaflet location picker, boundary fetch, marker dragging, pin validation, and Livewire model sync. This JS block has no other purpose.

### `app/Livewire/Surveys/ProfileSurvey.php`

**Remove imports** (no longer used after pin methods are gone):
- `use Illuminate\Support\Facades\Storage;`
- `use Illuminate\Validation\ValidationException;`

**Remove public properties:**
- `public ?string $latitude = null;`
- `public ?string $longitude = null;`
- `public bool $locationPinTouched = false;`

**Remove from `fillFromSenior()`:** the `if ($this->hasValidCoordinatePair(...))` block that loads existing coordinates into `$latitude` and `$longitude`.

**Remove from `save()`:**
- The `$this->validateLocationPin();` call.
- The `if ($this->hasUsableLocationPin()) { ... } else { ... }` block that writes `latitude`, `longitude`, `location_source`, `location_accuracy`, and `location_verified_at` into the save data array. The profile save stops touching coordinate columns entirely.

**Remove private methods:**
- `validateLocationPin()`
- `hasUsableLocationPin()`
- `hasValidCoordinatePair()`
- `pointIsInsidePagsanjan()`
- `pointInPolygon()`
- `pointInRing()`

### `docs/field-gps-workflow.md`

Delete this file. It documents a GPS capture workflow that is not implemented and will not be implemented in this form — the future path is address-line → geocoding.

## What Does Not Change

- `app/Console/Commands/GeocodeSeniors.php` (`gis:geocode`) — unchanged. Continues to assign approximate barangay-level coordinates as a background artisan command.
- `resources/views/reports/gis.blade.php` — reads coordinates directly from the senior model; unaffected.
- All GIS analytics, heatmap, proximity scoring — unaffected. Coordinates at the database level continue to exist and be used.
- `gis-geocoding.md` — kept as-is; it describes the artisan command accurately.

## Out of Scope

The future address-line → geocoding feature is a separate development cycle and is not designed here. When that work begins, it will be a new spec.
