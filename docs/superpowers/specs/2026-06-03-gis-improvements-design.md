# Design: GIS Analytics Page Improvements

**Date:** 2026-06-03
**Branch context:** new branch from main

## Problem

The GIS Analytics page has four distinct issues that affect usability and scalability:

1. **Performance** — the page lags when many seniors are loaded. The seniors GeoJSON is rebuilt on every page load with no caching. A boundary lookup scan runs O(n × m) per senior. Filter changes destroy and recreate all Leaflet layers from scratch.

2. **Heatmap visual quality** — the Cluster / Health Groups heatmap uses a 10-stop rainbow gradient (deep blue → red) that looks harsh and weather-radar-like. Blur is too tight. Low-density areas vanish instead of fading gracefully.

3. **Facility coverage** — only 13 approximate facilities exist, covering 6 of Pagsanjan's 16 barangays. The Accessibility Heatmap shows the other 10 barangays as "no access" not because seniors lack access, but because no facilities were seeded there.

4. **Layer category clutter** — two visualization modes (`density-heatmap`, `risk-heatmap`) are redundant and should be removed. Two others need cleaner labels.

## Scope

Four areas, all within the GIS Analytics page and its supporting API and seeder. Designed for current dataset (~400 seniors) but with explicit scalability hooks for 1,000–2,000+ records.

---

## Section 1: Performance

### Root Causes

**No GeoJSON response caching.** `GisApiController::seniors()` rebuilds the full feature collection on every page load: DB query, relationship hydration, coordinate calculation, boundary lookup, and 400+ array iterations. Nothing is cached between requests.

**O(n × m) barangay boundary lookup.** For each senior, `barangayBoundaryFeature()` does a linear scan over all boundary features to find a name match. The boundary features are cached (24-hour TTL) but no lookup index is built. At 400 seniors × 16 boundaries = 6,400 comparisons per request.

**Full layer teardown on every filter change.** When barangay/risk/cluster filters change, `clearDynamicLayers()` destroys all Leaflet layer groups and `renderActiveView()` recreates 400+ `L.marker` objects from scratch. The GeoJSON data (`latestSeniorGeoJson`) is already cached in memory but the renderer does not reuse it.

### Fixes

**1. Cache the seniors GeoJSON API response**

Wrap the entire `seniors()` method body in `Cache::remember('gis.seniors_geojson', now()->addMinutes(5), ...)`.

Cache key: `'gis.seniors_geojson'` (no per-user variation needed — the endpoint is the same for all authenticated roles).

Cache bust: add `Cache::forget('gis.seniors_geojson')` in `ReportController` immediately after the `Artisan::queue('gis:geocode')` dispatch. Coordinates change after a geocode run, so the cached GeoJSON must be invalidated.

**2. Build a normalized barangay lookup map once per request**

In `seniors()`, replace the per-senior call to `barangayBoundaryFeature()` with a pre-built keyed map:

```php
$boundaryMap = collect($this->barangayBoundaryFeatures())
    ->keyBy(fn($f) => $this->normalizeBarangayName(
        (string) ($f['properties']['name']
            ?? $f['properties']['NAME']
            ?? $f['properties']['barangay']
            ?? $f['properties']['BARANGAY']
            ?? $f['properties']['brgy_name']
            ?? $f['properties']['BRGY_NAME']
            ?? $f['properties']['ADM4_EN']
            ?? $f['properties']['adm4_en']
            ?? '')
    ));

// Per senior: O(1) lookup instead of O(m) scan
$boundaryFeature = $boundaryMap[$this->normalizeBarangayName((string) $senior->barangay)] ?? null;
```

**3. Debounce client-side filter redraws**

Wrap the filter `change` event handlers in a 120 ms debounce. Rapid filter changes currently chain full layer teardowns. A 120 ms debounce coalesces them into one redraw.

```js
function debounce(fn, ms) {
    let timer;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), ms);
    };
}
// Applied to all four filter selects and KDE overlay checkboxes.
```

**4. Add optional `?barangay=` server-side filter parameter**

Add an optional `barangay` query parameter to `GisApiController::seniors()`. When present and not `"all"`, apply a `->where('barangay', $request->query('barangay'))` before fetching seniors.

This is the primary scalability lever: at 2,000 records, a single-barangay selection drops the JSON payload from ~800 KB to ~60 KB and the loop from 2,000 to ~120 iterations.

The client side already holds the full GeoJSON for "All Barangays". When the barangay filter changes to a specific barangay, the client filters locally (already done via `filteredFeatures()`). The server-side param is therefore an opt-in enhancement that the client can use when the dataset grows — it does not change current behavior.

---

## Section 2: Heatmap Visual Quality

### Root Causes

**`CLUSTER_HEATMAP_GRADIENT` is a 10-stop rainbow.** The all-clusters heatmap surface uses deep blue → teal → green → yellow → orange → red. This is perceptually non-uniform, clashes with the per-cluster identity colors shown on the point markers (C1=blue, C2=green, C3=amber, C4=red), and is the primary source of the "harsh" complaint.

**Cluster heatmap blur is too tight.** Blur ratio is `radius × 0.52`, producing sharp-edged blobs. Other heatmap modes use `radius × 0.65`, which looks smoother.

**No minimum opacity.** Low-density fringes render at near-zero opacity (~0.05–0.10) and effectively disappear rather than fading gracefully.

### Fixes

**1. Replace `CLUSTER_HEATMAP_GRADIENT`** with a neutral cool-to-warm sequential ramp that reads as "density" without implying cluster identity:

```js
const CLUSTER_HEATMAP_GRADIENT = {
    0.00: '#e8f4f8',
    0.25: '#74c2e8',
    0.50: '#f0e442',
    0.75: '#e67e22',
    1.00: '#c0392b',
};
```

The per-cluster color ramps (`CLUSTER_HEATMAP_RAMPS` for C1–C4) are unchanged — they already produce clean single-hue gradients.

**2. Increase cluster heatmap blur ratio** from `radius × 0.52` to `radius × 0.72`.

In `heatmapPixelOptions()`, for the `cluster-heatmap` branch:
```js
blur: Math.round(Math.max(4, Math.min(24, radius * 0.72))),
```

**3. Set `minOpacity: 0.30`** on all heatmap layer options so low-density areas retain a visible tint:
```js
// Applied in buildHeatmapLayer() / GisKdeHeatLayer options
minOpacity: 0.30,
```

**4. Increase cluster heatmap `maxRadius` cap** from `42` to `52` pixels for better spread at normal zoom:
```js
const radius = Math.round(Math.max(6, Math.min(52, rawRadius)));
```

---

## Section 3: Facility Coverage

### Current State

13 approximate prototype facilities exist, covering 6 of Pagsanjan's 16 barangays. The 10 unrepresented barangays show artificial "no access" scores in the Accessibility Heatmap.

### Target

~50 prototype facilities covering all 16 barangays. Every barangay gets a minimum set of 3 facility types. Poblacion barangays (I & II) retain existing entries and gain any missing types.

### Minimum per barangay (all 16)

- Barangay Hall
- Barangay Health Center / Health Post
- Chapel / Church

### Additional for Barangay I & II (Poblacion)

Already have: Municipal Hall, RHU, Hospital, Senior Center, Public Market, Pharmacy, Church, Transport Terminal.
Add: Barangay Health Center (distinct from the RHU), Community Clinic.

### Additional for Sabang, Pinagsanjan, Maulawin, Lambac

Add: Community Store / Public Market access point, Pharmacy / medicine access point.

### Facility Types After This Change

`Barangay Hall`, `Health Center`, `Church`, `Hospital`, `Clinic`, `Pharmacy`, `Senior Center`, `Public Market`, `Community Store`, `Transport Hub`, `Municipal Hall`

### Approximate Coordinates for the 10 Missing Barangays

All entries: `source = 'sample_prototype_approximate'`, `is_active = true`.

| Barangay | Lat | Lng |
|---|---|---|
| Anibong | 14.2782 | 121.4588 |
| Biñan | 14.2728 | 121.4468 |
| Buboy | 14.2742 | 121.4618 |
| Cabanbanan | 14.2648 | 121.4528 |
| Calusiche | 14.2694 | 121.4502 |
| Dingin | 14.2758 | 121.4544 |
| Layugan | 14.2638 | 121.4572 |
| Magdapio | 14.2802 | 121.4556 |
| Sampaloc | 14.2764 | 121.4558 |
| San Isidro | 14.2668 | 121.4612 |

The coordinates in the table are Barangay Hall centers. Health Centers and Churches added for each barangay should apply a small offset (~0.0003°) from the Hall coordinate to avoid stacking pins at the same point.

**Note for production:** Replace with verified facility data from the municipal health office or LGU records. Update `source` to `'verified'`.

---

## Section 4: Layer Category Cleanup

### Visualization Dropdown Changes

| Value | Current Label | Action |
|---|---|---|
| `markers` | Senior Distribution Points | Keep, no change |
| `density-heatmap` | Barangay-Level Senior Heatmap | **Remove** |
| `risk-heatmap` | Generalized Barangay-Based Heatmap | **Remove** |
| `accessibility-heatmap` | Senior Distribution and Accessibility Heatmap | **Rename** → "Accessibility Heatmap" |
| `barangay-density` | Barangay Density View | Keep, no change |
| `risk-indicator-heatmap` | Risk Indicator Distribution | Keep, no change |
| `cluster-heatmap` | Health Group Cluster Distribution | **Rename** → "Cluster / Health Groups Heatmap" |

### Downstream Removals (density-heatmap, risk-heatmap)

- Remove both values from `HEATMAP_MODES` set
- Remove their entries from `heatmapLabels`, `heatmapWeight()`, `heatmapGradient()`, `heatmapRadiusMeters()`, `heatmapPixelOptions()`, `heatmapNormalization()`
- Remove `RISK_HEATMAP_GRADIENT` — becomes dead code once `density-heatmap` and `risk-heatmap` are removed (the only remaining mode reaching `buildHeatmapLayer` is `accessibility-heatmap`, which has its own inline gradient in `heatmapGradient()`). Remove the dead fallback line from `heatmapGradient()` too.
- Keep `RISK_DISTRIBUTION_RAMP` — still used by `buildRiskDistributionRasterLayer()` for the `risk-indicator-heatmap` main mode and its KDE overlay.
- Update legend label map to match renamed modes

### KDE Overlay Checkboxes

The three overlay checkboxes (Risk Distribution, Health Group / Cluster, Accessibility / Facility Proximity) reference `risk-indicator-heatmap`, `cluster-heatmap`, `accessibility-heatmap`. These are unaffected by the cleanup.

---

## What Does Not Change

- `gis:geocode` artisan command and bulk geocode workflow — untouched
- Leaflet map engine, boundary rendering, popup logic — untouched
- MarkerCluster behavior in markers mode — untouched
- KDE overlay checkboxes — untouched
- Per-cluster color ramp constants (`CLUSTER_HEATMAP_RAMPS`) — untouched
- Route distance proxy endpoint and caching — untouched
- All other report pages — untouched

## Files Changed

| File | Change |
|---|---|
| `app/Http/Controllers/GisApiController.php` | Add GeoJSON caching, build boundary lookup map, add `?barangay=` filter param |
| `app/Http/Controllers/ReportController.php` | Add `Cache::forget('gis.seniors_geojson')` after geocode dispatch |
| `resources/views/reports/gis.blade.php` | Heatmap gradient/blur/opacity fixes; layer dropdown changes; debounce filters |
| `database/seeders/PagsanjanFacilitySeeder.php` | Expand to ~50 facilities covering all 16 barangays |
