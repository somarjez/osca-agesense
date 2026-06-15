# GIS Senior Distribution and Accessibility Heatmap Changes

Branch: `gis-final-senior-distribution`  
Commit: `df9ebeb2575d3bb600fd2c19598a97675ea7dd3f`  
Commit message: `Finalize GIS senior accessibility heatmap`

> **Update (2026-06):** This is the original change record for the commit above. The
> visualization dropdown has since been consolidated. The current GIS page exposes
> **4** visualization modes — `Senior Population Overview`, `Risk Indicator Distribution`,
> `Cluster / Health Groups Heatmap`, and `Accessibility Heatmap`. The separate
> "Senior Distribution and Accessibility Heatmap" mode and the standalone "Accessibility
> Heatmap" were merged into the single `Accessibility Heatmap` mode (value
> `senior-distribution-accessibility-heatmap`), and the "Barangay Density View" mode was
> removed. The backend accessibility fields described below are still produced by
> `GisApiController`. See [GIS_FUNCTIONALITY_AND_MODIFIED_FILES.md](GIS_FUNCTIONALITY_AND_MODIFIED_FILES.md)
> for the current functional reference.

## Modified Files

1. `app/Http/Controllers/GisApiController.php`
2. `resources/views/reports/gis.blade.php`

No unrelated non-GIS files were modified in the committed change.

## Backend Changes

File: `app/Http/Controllers/GisApiController.php`

The GIS senior GeoJSON API now includes backend-generated accessibility concern fields for each senior feature:

- `accessibility_distance_m`
- `nearest_facility_distance_m`
- `accessibility_concern_score`
- `accessibility_surface_weight`
- `accessibility_level`
- `accessibility_group`

The backend calculates these values from real data:

- It prefers saved `SeniorAccessibilityMetric` distance fields when available.
- If saved metric distances are missing, it calculates nearest facility distance server-side using actual senior coordinates and active facility coordinates.

> **Note on distance basis.** This heatmap's distance grouping uses the metric's `distance_to_*_m` fields, which remain **straight-line** (haversine). The separate `accessibility_score` (the profile "Facility access %" / GIS proximity score) is computed from **road-network distance** (cached ORS, falling back to straight-line) — see `gis:score-proximity` in the GIS functionality reference.
- It filters facilities to senior-relevant services such as health centers, hospitals, pharmacies, markets, barangay halls, and senior centers.
- It calculates distance thresholds from the current senior dataset, then assigns accessibility groups:
  - `Nearest`
  - `Mid`
  - `Far`
  - `Farthest`

The backend concern score used by the heatmap is assigned from those data-driven groups:

| Group | Score | Heatmap Meaning |
|---|---:|---|
| `Nearest` | `0.05` | Near facilities / better access |
| `Mid` | `0.45` | Moderate access |
| `Far` | `0.68` | Far access |
| `Farthest` | `0.95` | Highest priority / farthest from facilities |

This keeps the heatmap backend/data-driven. The frontend does not create fake points, fake values, or fake accessibility groups.

## Frontend Changes

File: `resources/views/reports/gis.blade.php`

The GIS visualization dropdown now has exactly 6 categories:

1. `Senior Distribution Points`
2. `Accessibility Heatmap`
3. `Barangay Density View`
4. `Risk Indicator Distribution`
5. `Cluster / Health Groups Heatmap`
6. `Senior Distribution and Accessibility Heatmap`

The new `Senior Distribution and Accessibility Heatmap` category:

- Uses a KDE-style heatmap overlay.
- Uses backend-provided `accessibility_concern_score` first.
- Displays senior points on top of the heatmap when enabled.
- Keeps senior popups working.
- Uses real senior GIS records and existing backend API data.
- Does not add a separate seventh category.
- Does not include the Barangay Density View fill layer inside this combined mode, so barangay colors are not confused with senior-density colors.

## Heatmap Color Ramp

The heatmap uses a fixed visual color ramp, while the score that selects the color comes from backend data.

| Meaning | Hex | RGB |
|---|---:|---:|
| Near facilities / better access | `#22c55e` | `rgb(34, 197, 94)` |
| Light green / low concern | `#84cc16` | `rgb(132, 204, 22)` |
| Moderate access | `#facc15` | `rgb(250, 204, 21)` |
| Far access | `#fb923c` | `rgb(251, 146, 60)` |
| Farthest / priority concern | `#ef4444` | `rgb(239, 68, 68)` |
| Highest priority / deep red | `#991b1b` | `rgb(153, 27, 27)` |

Color interpretation:

- Green means nearer to facilities / better access.
- Yellow and orange mean middle-distance or moderate accessibility concern.
- Red means farthest from facilities / highest accessibility concern.

## Accuracy Notes

The color assignment is accurate for facility proximity when the database coordinates are accurate.

The output depends on:

- Senior latitude and longitude.
- Active facility latitude and longitude.
- Saved `SeniorAccessibilityMetric` data when available.
- Backend-calculated nearest facility distance when saved metrics are unavailable.

The fixed color ramp is not a hardcoded result. It is only the legend/display palette. The actual heatmap score and group are calculated from backend data.

## Validation Performed

The following checks passed before pushing the branch:

```bash
php -l app/Http/Controllers/GisApiController.php
php artisan cache:clear
php artisan view:clear
git diff --check
php artisan test --filter=GisApiCachingTest
```

`GisApiCachingTest` result:

- 4 tests passed
- 19 assertions passed

## Pushed Branch

Remote branch:

```bash
origin/gis-final-senior-distribution
```

Pull request URL:

```text
https://github.com/somarjez/osca-agesense/pull/new/gis-final-senior-distribution
```
