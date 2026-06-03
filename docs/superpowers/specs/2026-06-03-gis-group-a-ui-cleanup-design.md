# GIS Group A — UI Cleanup Design Spec

**Date:** 2026-06-03
**File scope:** `resources/views/reports/gis.blade.php` (all changes)
**Issues addressed:** #1 duplicate accessibility mode, #2 merge distribution/density views, #3 KDE overlay removal + toggle fix, #4 map bounds + re-center

---

## Background

The GIS report page accumulated several UX problems across PRs #58–#65:
- Two accessibility heatmap options exist in the dropdown, confusing users
- "Senior Distribution Points" and "Barangay Density View" share the barangay fill layer and feel redundant
- The "KDE Heatmap Overlays" sidebar section duplicates options already in the visualization dropdown, and the cluster points toggle in that section does not respond to real-time changes
- The map can be panned outside Pagsanjan municipality with no hard boundary enforcement, and there is no re-center affordance

---

## Section 1 — Visualization Dropdown (Issues 1 & 2)

### Final dropdown — 4 options

| Display label | `value` attribute |
|---|---|
| Senior Population Overview | `markers` |
| Risk Indicator Distribution | `risk-indicator-heatmap` |
| Cluster / Health Groups Heatmap | `cluster-heatmap` |
| Accessibility Heatmap | `senior-distribution-accessibility-heatmap` |

### Removals

- Remove `<option value="accessibility-heatmap">Accessibility Heatmap</option>` from the HTML dropdown.
- Remove `<option value="barangay-density">Barangay Density View</option>` from the HTML dropdown.
- Rename `<option value="senior-distribution-accessibility-heatmap">` label from "Senior Distribution and Accessibility Heatmap" to "Accessibility Heatmap".
- Rename `<option value="markers">` label from "Senior Distribution Points" to "Senior Population Overview".

### JS constant/map updates

- Remove `'accessibility-heatmap'` from `HEATMAP_MODES` set.
- Remove `'accessibility-heatmap'` entry from `heatmapLabels` map.
- Update `heatmapLabels['senior-distribution-accessibility-heatmap']` label text to `'Accessibility Heatmap'`.
- Remove the `mode === 'barangay-density'` branch from `renderDataLayers` (including its `buildBarangayDensityLayer` and `renderKdeOverlayHeatmaps` calls).
- Remove `'accessibility-heatmap'` from the `selectedKdeOverlayModes` filter list (or remove the function entirely — see Section 2).

---

## Section 2 — Contextual Layer Options Panel (Issues 2 & 3)

### 2a. HTML — Layer Options panel

Add a new `<div id="gis-layer-options">` block directly below the visualization `<select>`, inside the same sidebar section. The block is hidden by default and shown/hidden via JS when the mode changes:

```html
<div id="gis-layer-options" class="mt-2 hidden">
    <!-- Senior Population Overview toggles -->
    <div id="gis-layer-options-markers" class="hidden flex flex-col gap-1.5 text-[12px] text-ink-600 dark:text-[#b0b5b2]">
        <label class="inline-flex items-center gap-2">
            <input id="gis-show-senior-points-toggle" type="checkbox"
                   class="rounded border-paper-rule text-forest-700 focus:ring-forest-700" checked>
            <span>Show senior points</span>
        </label>
        <label class="inline-flex items-center gap-2">
            <input id="gis-show-barangay-density-toggle" type="checkbox"
                   class="rounded border-paper-rule text-forest-700 focus:ring-forest-700" checked>
            <span>Show barangay density fill</span>
        </label>
    </div>

    <!-- Cluster heatmap toggle -->
    <div id="gis-layer-options-cluster" class="hidden flex flex-col gap-1.5 text-[12px] text-ink-600 dark:text-[#b0b5b2]">
        <label class="inline-flex items-center gap-2">
            <input id="gis-cluster-points-toggle" type="checkbox"
                   class="rounded border-paper-rule text-forest-700 focus:ring-forest-700" checked>
            <span>Show senior distribution points</span>
        </label>
    </div>
</div>
```

### 2b. JS constants

Add three new constants:

```js
const LAYER_OPTIONS_ID = 'gis-layer-options';
const SHOW_SENIOR_POINTS_TOGGLE_ID = 'gis-show-senior-points-toggle';
const SHOW_BARANGAY_DENSITY_TOGGLE_ID = 'gis-show-barangay-density-toggle';
```

`CLUSTER_POINTS_TOGGLE_ID` already exists — keep it, the element just moves.

### 2c. JS — syncLayerOptionsPanel()

Add a function called after every mode change that shows the correct sub-panel:

```js
function syncLayerOptionsPanel() {
    const mode = document.getElementById(MODE_ID)?.value ?? 'markers';
    const wrapper = document.getElementById(LAYER_OPTIONS_ID);
    const markersPanel = document.getElementById('gis-layer-options-markers');
    const clusterPanel = document.getElementById('gis-layer-options-cluster');

    if (!wrapper) return;

    const showMarkers = mode === 'markers';
    const showCluster = mode === 'cluster-heatmap';

    wrapper.classList.toggle('hidden', !showMarkers && !showCluster);
    markersPanel?.classList.toggle('hidden', !showMarkers);
    clusterPanel?.classList.toggle('hidden', !showCluster);
}
```

Call `syncLayerOptionsPanel()` inside `renderDataLayers` (after `updateLegend`) and on the mode `change` event.

### 2d. JS — gating the markers layer (Senior Population Overview)

In `renderDataLayers`, in the `mode === 'markers'` branch:

```js
// Senior points: gated by toggle
const showSeniorPoints = document.getElementById(SHOW_SENIOR_POINTS_TOGGLE_ID)?.checked !== false;
if (showSeniorPoints) {
    layers.seniors.addLayer(markerLayer);
}

// Barangay density fill: gated by toggle
const showDensityFill = document.getElementById(SHOW_BARANGAY_DENSITY_TOGGLE_ID)?.checked !== false;
if (showDensityFill) {
    layers.barangayDensity.addLayer(buildBarangayDensityLayer(activeFeatures));
}
```

Wire `change` events on both toggles to call `renderDataLayers(map, latestSeniorGeoJson, latestFacilityGeoJson)`.

### 2e. JS — cluster points toggle bug fix

The existing `CLUSTER_POINTS_TOGGLE_ID` toggle only takes effect on the next full render. Add a `change` event listener during map init:

```js
document.getElementById(CLUSTER_POINTS_TOGGLE_ID)?.addEventListener('change', () => {
    renderDataLayers(map, latestSeniorGeoJson, latestFacilityGeoJson);
});
```

### 2f. Remove KDE Heatmap Overlays section

- Delete the entire `<div class="border border-paper-rule ...">KDE Heatmap Overlays</div>` block from the HTML sidebar (currently lines ~138–155).
- Remove constant `KDE_OVERLAY_SELECTOR`.
- Remove function `selectedKdeOverlayModes()`.
- Remove function `renderKdeOverlayHeatmaps()` and `renderKdeOverlayHeatmap()`.
- Remove all call sites of `renderKdeOverlayHeatmaps()` from `renderDataLayers` (two locations: the `barangay-density` branch already being removed, and the `kdeOverlayResults` variable in the main flow).
- Remove function `clearKdeOverlayLayers()` and its call inside `clearDynamicLayers`.
- Remove the three KDE layer groups from `ensureLayerRegistry`: `kdeRiskHeatmap`, `kdeClusterHeatmap`, `kdeAccessibilityHeatmap`.

---

## Section 3 — Map Constraints & Re-center (Issue 4)

### 3a. Tighten zoom limits

Change:
```js
const MIN_ZOOM = 8;   // → 13
```

At zoom 13 the full Pagsanjan municipality is visible. `MAX_ZOOM` stays at 18.

### 3b. Tighten navigation bounds

```js
const NAVIGATION_BOUNDS_COORDS = [
    [14.2580, 121.4410],   // SW — tightened from [14.2555, 121.4395]
    [14.2840, 121.4700],   // NE — tightened from [14.2868, 121.4715]
];
```

Ensure `applyMapBoundaryConstraints(map)` is called during map initialization, after `applyMapFocus(map)`, so bounds are enforced before first user interaction.

### 3c. Re-center Leaflet control (on the map)

Add a custom `L.Control` in `topleft` position, below the zoom buttons:

```js
function createRecenterControl(map) {
    const RecenterControl = window.L.Control.extend({
        options: { position: 'topleft' },
        onAdd() {
            const btn = window.L.DomUtil.create('button',
                'leaflet-bar leaflet-control gis-recenter-control');
            btn.type = 'button';
            btn.title = 'Re-center map on Pagsanjan';
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/></svg>';
            window.L.DomEvent.on(btn, 'click', (e) => {
                window.L.DomEvent.stopPropagation(e);
                focusMapOnPagsanjan(map);
            });
            return btn;
        },
    });
    return new RecenterControl();
}
```

Add CSS for `.gis-recenter-control` in the existing `<style>` block — matches the Leaflet zoom button style (white background, border, hover state).

### 3d. Re-center button in sidebar

Add a small ghost button below the barangay filter `<label>`:

```html
<button id="gis-recenter-btn" type="button"
    class="mt-1 text-[11px] text-ink-500 dark:text-[#7a8580] hover:text-forest-700 dark:hover:text-forest-400 underline underline-offset-2 transition-colors">
    ↺ Re-center map
</button>
```

Wire click → `focusMapOnPagsanjan(map)` during map initialization.

---

## Change Summary

| # | Area | What changes |
|---|------|-------------|
| 1a | HTML dropdown | Remove `accessibility-heatmap` and `barangay-density` options; rename 2 labels |
| 1b | JS constants/maps | Remove `accessibility-heatmap` from `HEATMAP_MODES`, `heatmapLabels` |
| 2a | HTML sidebar | Remove KDE section; add `#gis-layer-options` panel with sub-panels |
| 2b | JS constants | Add `LAYER_OPTIONS_ID`, `SHOW_SENIOR_POINTS_TOGGLE_ID`, `SHOW_BARANGAY_DENSITY_TOGGLE_ID` |
| 2c | JS function | Add `syncLayerOptionsPanel()` |
| 2d | JS render | Gate markers/density layers behind toggles in `markers` branch |
| 2e | JS bug fix | Add `change` listener on cluster points toggle → triggers re-render |
| 2f | JS removal | Remove `selectedKdeOverlayModes`, `renderKdeOverlayHeatmaps`, `renderKdeOverlayHeatmap` |
| 3a | JS constant | `MIN_ZOOM` 8 → 13 |
| 3b | JS constant | Tighten `NAVIGATION_BOUNDS_COORDS` |
| 3c | JS/CSS | Add `createRecenterControl` Leaflet control |
| 3d | HTML/JS | Add sidebar re-center button |

All changes are in `resources/views/reports/gis.blade.php`. No PHP changes, no migrations, no new packages.
