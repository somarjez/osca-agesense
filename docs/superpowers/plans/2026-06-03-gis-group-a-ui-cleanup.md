# GIS Group A — UI Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Clean up the GIS report page by consolidating redundant visualization modes, removing the KDE overlay system, adding contextual layer toggles, and enforcing map boundaries with a re-center control.

**Architecture:** All changes are in a single Blade/JS file (`resources/views/reports/gis.blade.php`). Tasks proceed in dependency order: HTML cleanup first, then JS logic, then KDE system removal, then map constraints. No PHP changes.

**Tech Stack:** Laravel 11 Blade, Leaflet.js, inline JavaScript.

---

## File Map

| File | What changes |
|---|---|
| `resources/views/reports/gis.blade.php` | All 8 tasks — dropdown, HTML panel, JS constants, markers gating, change handler, KDE removal, map bounds, re-center |

---

## Task 1: Dropdown rename/cleanup + JS constant maps

**Files:**
- Modify: `resources/views/reports/gis.blade.php:106-113` (dropdown HTML)
- Modify: `resources/views/reports/gis.blade.php:229-234` (HEATMAP_MODES)
- Modify: `resources/views/reports/gis.blade.php:637-642` (heatmapLabels)
- Modify: `resources/views/reports/gis.blade.php:3727` (heatmapFeaturesForMode)

- [ ] **Step 1: Confirm current dropdown HTML**

  ```bash
  sed -n '106,113p' resources/views/reports/gis.blade.php
  ```

  Expected:
  ```html
  <select id="gis-visualization-mode" class="form-select">
      <option value="markers">Senior Distribution Points</option>
      <option value="accessibility-heatmap">Accessibility Heatmap</option>
      <option value="barangay-density">Barangay Density View</option>
      <option value="risk-indicator-heatmap">Risk Indicator Distribution</option>
      <option value="cluster-heatmap">Cluster / Health Groups Heatmap</option>
      <option value="senior-distribution-accessibility-heatmap">Senior Distribution and Accessibility Heatmap</option>
  </select>
  ```

- [ ] **Step 2: Update dropdown to 4 options**

  In `resources/views/reports/gis.blade.php`, replace the `<select>` block (lines 106-113) with:

  ```html
  <select id="gis-visualization-mode" class="form-select">
      <option value="markers">Senior Population Overview</option>
      <option value="risk-indicator-heatmap">Risk Indicator Distribution</option>
      <option value="cluster-heatmap">Cluster / Health Groups Heatmap</option>
      <option value="senior-distribution-accessibility-heatmap">Accessibility Heatmap</option>
  </select>
  ```

- [ ] **Step 3: Remove `accessibility-heatmap` from HEATMAP_MODES**

  Find `HEATMAP_MODES` (around line 229). Change:
  ```js
  const HEATMAP_MODES = new Set([
      'accessibility-heatmap',
      'senior-distribution-accessibility-heatmap',
      'risk-indicator-heatmap',
      'cluster-heatmap',
  ]);
  ```
  to:
  ```js
  const HEATMAP_MODES = new Set([
      'senior-distribution-accessibility-heatmap',
      'risk-indicator-heatmap',
      'cluster-heatmap',
  ]);
  ```

- [ ] **Step 4: Update heatmapLabels**

  Find `heatmapLabels` (around line 637). Change:
  ```js
  const heatmapLabels = {
      'accessibility-heatmap': ['Accessibility Heatmap', 'Better access', 'Greater access need'],
      'senior-distribution-accessibility-heatmap': ['Senior Distribution and Accessibility Heatmap', 'Better access', 'Greater access need'],
      'risk-indicator-heatmap': ['Risk Indicator Distribution', 'Lower risk indicator', 'Higher risk indicator'],
      'cluster-heatmap': ['Cluster / Health Groups Heatmap', 'Assigned group color', 'Stronger local concentration'],
  };
  ```
  to:
  ```js
  const heatmapLabels = {
      'senior-distribution-accessibility-heatmap': ['Accessibility Heatmap', 'Better access', 'Greater access need'],
      'risk-indicator-heatmap': ['Risk Indicator Distribution', 'Lower risk indicator', 'Higher risk indicator'],
      'cluster-heatmap': ['Cluster / Health Groups Heatmap', 'Assigned group color', 'Stronger local concentration'],
  };
  ```

- [ ] **Step 5: Remove dead `accessibility-heatmap` check from heatmapFeaturesForMode**

  Find `heatmapFeaturesForMode` (around line 3727). Change:
  ```js
  if (mode === 'accessibility-heatmap' || mode === 'senior-distribution-accessibility-heatmap') {
  ```
  to:
  ```js
  if (mode === 'senior-distribution-accessibility-heatmap') {
  ```

- [ ] **Step 6: Verify**

  ```bash
  grep -n "accessibility-heatmap" resources/views/reports/gis.blade.php | grep -v "senior-distribution-accessibility-heatmap"
  ```

  Expected: results only from the KDE section HTML (lines ~142-152) and `selectedKdeOverlayModes` (line ~840) — both will be removed in Task 5. No other standalone references.

- [ ] **Step 7: Commit**

  ```bash
  git add resources/views/reports/gis.blade.php
  git commit -m "feat(gis): consolidate visualization dropdown to 4 modes, rename labels"
  ```

---

## Task 2: Replace KDE section HTML with contextual layer options panel

**Files:**
- Modify: `resources/views/reports/gis.blade.php:138-158` (KDE section → new panel)

- [ ] **Step 1: Confirm the KDE section bounds**

  ```bash
  sed -n '138,158p' resources/views/reports/gis.blade.php
  ```

  Expected: opening `<div class="border border-paper-rule...">KDE Heatmap Overlays</div>` block containing three `data-gis-kde-overlay` checkboxes and the `gis-cluster-points-toggle`.

- [ ] **Step 2: Replace the KDE section with the layer options panel**

  Replace lines 138-158 (the entire KDE `<div>` block) with:

  ```html
  <div id="gis-layer-options" class="hidden">
      <div id="gis-layer-options-markers" class="hidden border border-paper-rule dark:border-[#2b3530] rounded-lg px-3 py-2">
          <div class="eyebrow mb-2">Layer Options</div>
          <div class="flex flex-wrap gap-x-4 gap-y-2 text-[12px] text-ink-600 dark:text-[#b0b5b2]">
              <label class="inline-flex items-center gap-2">
                  <input id="gis-show-senior-points-toggle" type="checkbox" class="rounded border-paper-rule text-forest-700 focus:ring-forest-700" checked>
                  <span>Show senior points</span>
              </label>
              <label class="inline-flex items-center gap-2">
                  <input id="gis-show-barangay-density-toggle" type="checkbox" class="rounded border-paper-rule text-forest-700 focus:ring-forest-700" checked>
                  <span>Show barangay density fill</span>
              </label>
          </div>
      </div>
      <div id="gis-layer-options-cluster" class="hidden border border-paper-rule dark:border-[#2b3530] rounded-lg px-3 py-2">
          <div class="eyebrow mb-2">Layer Options</div>
          <div class="flex flex-wrap gap-x-4 gap-y-2 text-[12px] text-ink-600 dark:text-[#b0b5b2]">
              <label class="inline-flex items-center gap-2">
                  <input id="gis-cluster-points-toggle" type="checkbox" class="rounded border-paper-rule text-forest-700 focus:ring-forest-700" checked>
                  <span>Show senior distribution points</span>
              </label>
          </div>
      </div>
  </div>
  ```

- [ ] **Step 3: Verify no KDE overlay checkboxes remain**

  ```bash
  grep -n "data-gis-kde-overlay" resources/views/reports/gis.blade.php
  ```

  Expected: no results.

- [ ] **Step 4: Verify new panel IDs exist**

  ```bash
  grep -n "gis-layer-options\|gis-show-senior-points-toggle\|gis-show-barangay-density-toggle\|gis-cluster-points-toggle" resources/views/reports/gis.blade.php | head -10
  ```

  Expected: 4–6 results — the outer wrapper, two sub-panels, and three toggle inputs.

- [ ] **Step 5: Commit**

  ```bash
  git add resources/views/reports/gis.blade.php
  git commit -m "feat(gis): replace KDE overlay section with contextual layer options panel"
  ```

---

## Task 3: JS constants + `syncLayerOptionsPanel` function

**Files:**
- Modify: `resources/views/reports/gis.blade.php:199-204` (constants block)
- Modify: `resources/views/reports/gis.blade.php` — add `syncLayerOptionsPanel` near other `sync*` functions (~line 829)
- Modify: `resources/views/reports/gis.blade.php:5028-5029` (renderDataLayers — call syncLayerOptionsPanel)

- [ ] **Step 1: Add three new constants after existing constants**

  Find the constants block (around line 201–204):
  ```js
  const CLUSTER_POINTS_TOGGLE_ID = 'gis-cluster-points-toggle';
  const SHOW_HEATMAP_SENIOR_POINTS_ID = 'gis-show-heatmap-senior-points';
  const ACCESSIBILITY_POINT_DISPLAY_ID = 'gis-accessibility-point-display';
  const KDE_OVERLAY_SELECTOR = '[data-gis-kde-overlay]';
  ```

  Add three new constants after `CLUSTER_POINTS_TOGGLE_ID`:
  ```js
  const CLUSTER_POINTS_TOGGLE_ID = 'gis-cluster-points-toggle';
  const SHOW_SENIOR_POINTS_TOGGLE_ID = 'gis-show-senior-points-toggle';
  const SHOW_BARANGAY_DENSITY_TOGGLE_ID = 'gis-show-barangay-density-toggle';
  const LAYER_OPTIONS_ID = 'gis-layer-options';
  const SHOW_HEATMAP_SENIOR_POINTS_ID = 'gis-show-heatmap-senior-points';
  const ACCESSIBILITY_POINT_DISPLAY_ID = 'gis-accessibility-point-display';
  const KDE_OVERLAY_SELECTOR = '[data-gis-kde-overlay]';
  ```

  (`KDE_OVERLAY_SELECTOR` stays for now — removed in Task 6.)

- [ ] **Step 2: Add `syncLayerOptionsPanel` function**

  Find `function syncAccessibilityPointDisplay()` (around line 829). Insert the new function **before** it:

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

- [ ] **Step 3: Call `syncLayerOptionsPanel()` inside `renderDataLayers`**

  Find `syncAccessibilityPointDisplay();` inside `renderDataLayers` (around line 5028). Add the call immediately after:
  ```js
  syncAccessibilityPointDisplay();
  syncLayerOptionsPanel();
  ```

- [ ] **Step 4: Verify**

  ```bash
  grep -n "syncLayerOptionsPanel\|SHOW_SENIOR_POINTS_TOGGLE_ID\|SHOW_BARANGAY_DENSITY_TOGGLE_ID\|LAYER_OPTIONS_ID" resources/views/reports/gis.blade.php
  ```

  Expected: definition of `syncLayerOptionsPanel`, three new constant lines, and at least one call site.

- [ ] **Step 5: Commit**

  ```bash
  git add resources/views/reports/gis.blade.php
  git commit -m "feat(gis): add syncLayerOptionsPanel and new toggle constants"
  ```

---

## Task 4: Gate `markers` mode layers behind new toggles

**Files:**
- Modify: `resources/views/reports/gis.blade.php:5043-5045` (markers mode density layer)
- Modify: `resources/views/reports/gis.blade.php:5096-5114` (markers mode senior points layer)

- [ ] **Step 1: Confirm current markers-mode density layer block**

  ```bash
  sed -n '5043,5045p' resources/views/reports/gis.blade.php
  ```

  Expected:
  ```js
  if (mode === 'markers') {
      layers.barangayDensity.addLayer(buildBarangayDensityLayer(activeFeatures));
  }
  ```

- [ ] **Step 2: Gate density layer behind toggle**

  Change the block at lines 5043–5045 to:
  ```js
  if (mode === 'markers') {
      const showDensityFill = document.getElementById(SHOW_BARANGAY_DENSITY_TOGGLE_ID)?.checked !== false;
      if (showDensityFill) {
          layers.barangayDensity.addLayer(buildBarangayDensityLayer(activeFeatures));
      }
  }
  ```

- [ ] **Step 3: Confirm current markers-mode senior points block**

  ```bash
  sed -n '5095,5120p' resources/views/reports/gis.blade.php
  ```

  Expected: `if (shouldClusterMarkers()) { ... markerClusterLayer ... } else { layers.seniors.addLayer(markerLayer); }`

- [ ] **Step 4: Gate senior points layer behind toggle**

  Find the block inside `if (mode === 'markers') {` that adds to `layers.seniors` (the `shouldClusterMarkers()` if/else block). Wrap it:

  ```js
  const showSeniorPoints = document.getElementById(SHOW_SENIOR_POINTS_TOGGLE_ID)?.checked !== false;
  if (showSeniorPoints) {
      if (shouldClusterMarkers()) {
          const markerClusterLayer = window.L.markerClusterGroup({
              showCoverageOnHover: false,
              spiderfyOnMaxZoom: true,
              disableClusteringAtZoom: 16,
              maxClusterRadius: 26,
              iconCreateFunction(cluster) {
                  const markers = cluster.getAllChildMarkers();
                  const tone = clusterTone(markers);
                  return makeClusterDivIcon(tone, cluster.getChildCount());
              },
          });
          markerClusterLayer.addLayer(markerLayer);
          layers.seniors.addLayer(markerClusterLayer);
      } else {
          layers.seniors.addLayer(markerLayer);
      }
  }
  ```

- [ ] **Step 5: Commit**

  ```bash
  git add resources/views/reports/gis.blade.php
  git commit -m "feat(gis): gate senior points and barangay density layers behind layer option toggles"
  ```

---

## Task 5: Update change event handler + remove barangay-density branch

**Files:**
- Modify: `resources/views/reports/gis.blade.php:5329-5333` (change event listener)
- Modify: `resources/views/reports/gis.blade.php:5047-5054` (barangay-density renderDataLayers branch)
- Modify: `resources/views/reports/gis.blade.php:5068-5070` (kdeOverlayResults in renderDataLayers)
- Modify: `resources/views/reports/gis.blade.php:5117-5118` (overlayText in markers status)

- [ ] **Step 1: Confirm change event handler**

  ```bash
  sed -n '5329,5334p' resources/views/reports/gis.blade.php
  ```

  Expected: `[MODE_ID, BARANGAY_FILTER_ID, RISK_FILTER_ID, CLUSTER_FILTER_ID, CLUSTER_POINTS_TOGGLE_ID, SHOW_HEATMAP_SENIOR_POINTS_ID].includes(event.target?.id) || event.target?.matches?.(KDE_OVERLAY_SELECTOR)`

- [ ] **Step 2: Add new toggle IDs to change handler, remove KDE_OVERLAY_SELECTOR**

  Change the handler condition to:
  ```js
  document.addEventListener('change', function (event) {
      if ([MODE_ID, BARANGAY_FILTER_ID, RISK_FILTER_ID, CLUSTER_FILTER_ID, CLUSTER_POINTS_TOGGLE_ID, SHOW_HEATMAP_SENIOR_POINTS_ID, SHOW_SENIOR_POINTS_TOGGLE_ID, SHOW_BARANGAY_DENSITY_TOGGLE_ID].includes(event.target?.id)) {
          debouncedRefresh();
      }
  });
  ```

- [ ] **Step 3: Remove the `barangay-density` branch from `renderDataLayers`**

  Find and delete (lines ~5047–5054):
  ```js
  if (mode === 'barangay-density') {
      layers.barangayDensity.addLayer(buildBarangayDensityLayer(activeFeatures));
      const kdeOverlayResults = renderKdeOverlayHeatmaps(map, markerStats.visible);
      focusMapOnActiveLayer(map, markerStats.visible.length ? markerStats.visible : activeFeatures);
      const overlayText = kdeOverlayResults.length ? ` ${kdeOverlayResults.length} KDE heatmap overlay(s) active.` : '';
      setStatus(`${validationStatusText(activeFeatures.length, markerStats)} Barangay density uses backend senior counts.${overlayText}`, 'success');
      return;
  }
  ```

- [ ] **Step 4: Remove `kdeOverlayResults` variable from renderDataLayers**

  Find (lines ~5068–5070):
  ```js
  const kdeOverlayResults = mode === 'cluster-heatmap'
      ? []
      : renderKdeOverlayHeatmaps(map, markerStats.visible);
  ```
  Delete these 3 lines entirely.

- [ ] **Step 5: Remove `overlayText` from markers mode status message**

  Find the markers-mode `setStatus` call (around line ~5117):
  ```js
  const overlayText = kdeOverlayResults.length ? ` ${kdeOverlayResults.length} KDE heatmap overlay(s) active.` : '';
  setStatus(`${validationStatusText(activeFeatures.length, markerStats)}${overlayText}`, 'success');
  ```
  Replace with:
  ```js
  setStatus(validationStatusText(activeFeatures.length, markerStats), 'success');
  ```

- [ ] **Step 6: Remove all remaining `kdeOverlayResults` and `overlayText` references**

  After steps 4 and 5, search for any remaining references:
  ```bash
  grep -n "kdeOverlayResults\|overlayText" resources/views/reports/gis.blade.php
  ```

  For each result found, remove the reference. The pattern is always one of:
  - `const overlayText = kdeOverlayResults.length ? ... : '';` → delete the line
  - `setStatus(\`...\${overlayText}\`, 'success')` → remove `${overlayText}` from the template literal
  - Any standalone `overlayText` or `kdeOverlayResults` usage → delete the line

  Re-run the grep until it returns no results.

- [ ] **Step 7: Verify no barangay-density references remain**

  ```bash
  grep -n "barangay-density" resources/views/reports/gis.blade.php
  ```

  Expected: no results.

- [ ] **Step 8: Commit**

  ```bash
  git add resources/views/reports/gis.blade.php
  git commit -m "feat(gis): wire new toggle change events, remove barangay-density mode branch"
  ```

---

## Task 6: Remove the KDE overlay system

**Files:**
- Modify: `resources/views/reports/gis.blade.php` — delete 7 functions + update 3 functions + remove 1 constant

This task removes the KDE overlay infrastructure: `selectedKdeOverlayModes`, `kdeLayerForMode`, `clearKdeOverlayLayers`, `setKdeOverlayContext`, `renderKdeOverlayHeatmap`, `renderKdeOverlayHeatmaps`, `refreshKdeOverlayHeatmaps`, and the three KDE layer groups from `ensureLayerRegistry`.

- [ ] **Step 1: Confirm function locations**

  ```bash
  grep -n "function selectedKdeOverlayModes\|function kdeLayerForMode\|function clearKdeOverlayLayers\|function setKdeOverlayContext\|function renderKdeOverlayHeatmap\b\|function renderKdeOverlayHeatmaps\|function refreshKdeOverlayHeatmaps" resources/views/reports/gis.blade.php
  ```

  Expected: 7 lines showing the function definitions.

- [ ] **Step 2: Delete `selectedKdeOverlayModes` function**

  Find and delete (around line 837):
  ```js
  function selectedKdeOverlayModes() {
      return [...document.querySelectorAll(`${KDE_OVERLAY_SELECTOR}:checked`)]
          .map((input) => input.value)
          .filter((mode) => ['risk-indicator-heatmap', 'cluster-heatmap', 'accessibility-heatmap'].includes(mode));
  }
  ```

- [ ] **Step 3: Delete `kdeLayerForMode` function**

  Find and delete (around line 3692):
  ```js
  function kdeLayerForMode(map, mode) {
      const layers = ensureLayerRegistry(map);

      if (mode === 'risk-indicator-heatmap') {
          return layers.kdeRiskHeatmap;
      }

      if (mode === 'cluster-heatmap') {
          return layers.kdeClusterHeatmap;
      }

      if (mode === 'accessibility-heatmap') {
          return layers.kdeAccessibilityHeatmap;
      }

      return null;
  }
  ```

- [ ] **Step 4: Delete `clearKdeOverlayLayers` function**

  Find and delete (around line 3710):
  ```js
  function clearKdeOverlayLayers(map) {
      const layers = ensureLayerRegistry(map);
      map._gisKdeOverlayContexts = {};
      layers.kdeRiskHeatmap.clearLayers();
      layers.kdeClusterHeatmap.clearLayers();
      layers.kdeAccessibilityHeatmap.clearLayers();
  }
  ```

- [ ] **Step 5: Remove `clearKdeOverlayLayers` call from `clearDynamicLayers`**

  Find inside `clearDynamicLayers` (around line 3678):
  ```js
  layers.heatmap.clearLayers();
  clearKdeOverlayLayers(map);
  layers.barangayDensity.clearLayers();
  ```
  Change to:
  ```js
  layers.heatmap.clearLayers();
  layers.barangayDensity.clearLayers();
  ```

- [ ] **Step 6: Remove KDE layer groups from `ensureLayerRegistry`**

  Find `ensureLayerRegistry` (around line 3655). Delete these three lines:
  ```js
  kdeRiskHeatmap: window.L.layerGroup().addTo(map),
  kdeClusterHeatmap: window.L.layerGroup().addTo(map),
  kdeAccessibilityHeatmap: window.L.layerGroup().addTo(map),
  ```

- [ ] **Step 7: Delete `setKdeOverlayContext` function**

  Find and delete (around line 4511):
  ```js
  function setKdeOverlayContext(map, mode, features, options = {}) {
      map._gisKdeOverlayContexts = map._gisKdeOverlayContexts || {};
      map._gisKdeOverlayContexts[mode] = {
          mode,
          features: [...features],
          radiusMeters: options.radiusMeters,
          colorScaleMax: options.colorScaleMax,
      };
  }
  ```

- [ ] **Step 8: Delete `renderKdeOverlayHeatmap` function**

  Find and delete the entire `function renderKdeOverlayHeatmap(map, mode, features) { ... }` block (around lines 4521–4576, approximately 56 lines).

- [ ] **Step 9: Delete `renderKdeOverlayHeatmaps` function**

  Find and delete:
  ```js
  function renderKdeOverlayHeatmaps(map, features) {
      clearKdeOverlayLayers(map);

      const modes = selectedKdeOverlayModes();
      const results = modes
          .map((mode) => renderKdeOverlayHeatmap(map, mode, features))
          .filter(Boolean);

      return results;
  }
  ```

- [ ] **Step 10: Delete `refreshKdeOverlayHeatmaps` function**

  Find and delete the entire `function refreshKdeOverlayHeatmaps(map) { ... }` block (around lines 4589–4615).

- [ ] **Step 11: Remove `refreshKdeOverlayHeatmaps` call from `refreshHeatmapLayersForZoom`**

  Find `refreshHeatmapLayersForZoom` (around line 4618):
  ```js
  function refreshHeatmapLayersForZoom(map) {
      refreshActiveHeatmapRadius(map);
      refreshKdeOverlayHeatmaps(map);
  }
  ```
  Change to:
  ```js
  function refreshHeatmapLayersForZoom(map) {
      refreshActiveHeatmapRadius(map);
  }
  ```

- [ ] **Step 12: Remove `KDE_OVERLAY_SELECTOR` constant**

  Find and delete the line (around line 204):
  ```js
  const KDE_OVERLAY_SELECTOR = '[data-gis-kde-overlay]';
  ```

- [ ] **Step 13: Verify no dead KDE references remain**

  ```bash
  grep -n "KDE_OVERLAY_SELECTOR\|kdeOverlay\|clearKdeOverlayLayers\|renderKdeOverlayHeatmap\|selectedKdeOverlayModes\|kdeLayerForMode\|setKdeOverlayContext\|refreshKdeOverlayHeatmaps\|kdeRiskHeatmap\|kdeClusterHeatmap\|kdeAccessibilityHeatmap" resources/views/reports/gis.blade.php
  ```

  Expected: no results.

- [ ] **Step 14: Run PHP tests**

  ```bash
  php artisan test --filter=Gis
  ```

  Expected: all tests pass.

- [ ] **Step 15: Commit**

  ```bash
  git add resources/views/reports/gis.blade.php
  git commit -m "refactor(gis): remove KDE overlay system (selectedKdeOverlayModes, renderKdeOverlayHeatmap/s, refreshKdeOverlayHeatmaps, layer groups)"
  ```

---

## Task 7: Tighten map bounds and zoom limits

**Files:**
- Modify: `resources/views/reports/gis.blade.php:212` (MIN_ZOOM)
- Modify: `resources/views/reports/gis.blade.php:218-221` (NAVIGATION_BOUNDS_COORDS)

- [ ] **Step 1: Confirm current constants**

  ```bash
  grep -n "MIN_ZOOM\|NAVIGATION_BOUNDS_COORDS" resources/views/reports/gis.blade.php | head -10
  ```

  Expected: `MIN_ZOOM = 8` and the NAVIGATION_BOUNDS_COORDS array with `[14.2555, 121.4395]` and `[14.2868, 121.4715]`.

- [ ] **Step 2: Change MIN_ZOOM to 13**

  Find line 212:
  ```js
  const MIN_ZOOM = 8;
  ```
  Change to:
  ```js
  const MIN_ZOOM = 13;
  ```

- [ ] **Step 3: Tighten navigation bounds**

  Find `NAVIGATION_BOUNDS_COORDS` (around line 218):
  ```js
  const NAVIGATION_BOUNDS_COORDS = [
      [14.2555, 121.4395],
      [14.2868, 121.4715],
  ];
  ```
  Change to:
  ```js
  const NAVIGATION_BOUNDS_COORDS = [
      [14.2580, 121.4410],
      [14.2840, 121.4700],
  ];
  ```

- [ ] **Step 4: Verify**

  ```bash
  grep -n "MIN_ZOOM\|NAVIGATION_BOUNDS_COORDS" resources/views/reports/gis.blade.php | head -6
  ```

  Expected: `MIN_ZOOM = 13` and updated coordinate values.

- [ ] **Step 5: Commit**

  ```bash
  git add resources/views/reports/gis.blade.php
  git commit -m "fix(gis): tighten MIN_ZOOM to 13 and navigation bounds to Pagsanjan extent"
  ```

---

## Task 8: Re-center Leaflet control + sidebar button

**Files:**
- Modify: `resources/views/reports/gis.blade.php` — add `createRecenterControl` function near other map init helpers
- Modify: `resources/views/reports/gis.blade.php:5285-5297` (map init — wire re-center control)
- Modify: `resources/views/reports/gis.blade.php:115-120` (sidebar — add re-center button below barangay filter)
- Modify: `resources/views/reports/gis.blade.php` — add CSS for `.gis-recenter-control` in `<style>` block

- [ ] **Step 1: Add CSS for re-center control button**

  Find the existing `<style>` block in the Blade file (it will contain GIS-specific styles). Add inside it:

  ```css
  .gis-recenter-control {
      background: white;
      border: none;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      width: 26px;
      height: 26px;
      color: #333;
      padding: 0;
  }
  .gis-recenter-control:hover {
      background: #f4f4f4;
      color: #000;
  }
  .dark .gis-recenter-control {
      background: #2b3530;
      color: #b0b5b2;
  }
  .dark .gis-recenter-control:hover {
      background: #3a4540;
      color: #fff;
  }
  ```

- [ ] **Step 2: Add `createRecenterControl` function**

  Find `function createTileLayer()` (around line 4857). Insert the new function **before** it:

  ```js
  function createRecenterControl(map) {
      const RecenterControl = window.L.Control.extend({
          options: { position: 'topleft' },
          onAdd() {
              const btn = window.L.DomUtil.create('button', 'leaflet-bar leaflet-control gis-recenter-control');
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

- [ ] **Step 3: Wire the control during map initialization**

  Find `createTileLayer().addTo(map);` in the map init block (around line 5290). Add the re-center control immediately after:

  ```js
  createTileLayer().addTo(map);
  createRecenterControl(map).addTo(map);
  ```

- [ ] **Step 4: Add sidebar re-center button**

  Find the barangay filter `<label>` block (around lines 115–120):
  ```html
  <label class="block">
      <span class="eyebrow block mb-1.5">Barangay</span>
      <select id="gis-barangay-filter" class="form-select">
          <option value="all">All Barangays</option>
      </select>
  </label>
  ```

  Change to:
  ```html
  <label class="block">
      <span class="eyebrow block mb-1.5">Barangay</span>
      <select id="gis-barangay-filter" class="form-select">
          <option value="all">All Barangays</option>
      </select>
      <button id="gis-recenter-btn" type="button"
          class="mt-1 text-[11px] text-ink-500 dark:text-[#7a8580] hover:text-forest-700 dark:hover:text-forest-400 underline underline-offset-2 transition-colors">
          ↺ Re-center map
      </button>
  </label>
  ```

- [ ] **Step 5: Wire sidebar button click during map initialization**

  Find the section in map init where other button listeners are set up (around line 5292–5298). After the re-center control add, wire the sidebar button:

  ```js
  document.getElementById('gis-recenter-btn')?.addEventListener('click', () => {
      focusMapOnPagsanjan(map);
  });
  ```

- [ ] **Step 6: Verify**

  ```bash
  grep -n "createRecenterControl\|gis-recenter-control\|gis-recenter-btn" resources/views/reports/gis.blade.php
  ```

  Expected: function definition, CSS class, map control wiring, HTML button, and click listener — at least 5 results.

- [ ] **Step 7: Run PHP tests**

  ```bash
  php artisan test --filter=Gis
  ```

  Expected: all tests pass.

- [ ] **Step 8: Commit**

  ```bash
  git add resources/views/reports/gis.blade.php
  git commit -m "feat(gis): add re-center Leaflet control and sidebar button"
  ```

---

## Visual Verification (after all tasks)

There is no unit test suite for the inline JS. After completing all tasks:

- [ ] Run the app and open the GIS report page
- [ ] Visualization dropdown shows exactly 4 options: Senior Population Overview, Risk Indicator Distribution, Cluster / Health Groups Heatmap, Accessibility Heatmap
- [ ] Select **Senior Population Overview** → "Layer Options" panel appears with two toggles
  - Uncheck "Show senior points" → individual dots disappear, barangay fill stays
  - Uncheck "Show barangay density fill" → colored barangay areas disappear, dots stay
  - Re-check both → both layers return
- [ ] Select **Cluster / Health Groups Heatmap** → "Layer Options" panel shows "Show senior distribution points" toggle
  - Uncheck it → distribution points disappear immediately (no stale state)
  - Re-check it → points reappear
- [ ] Select **Risk Indicator Distribution** → "Layer Options" panel is hidden
- [ ] Select **Accessibility Heatmap** → renders the senior-distribution-accessibility heatmap, legend reads "Accessibility Heatmap"
- [ ] Try to zoom out past zoom 13 → map should not zoom out further
- [ ] Try to pan outside Pagsanjan → map should snap back
- [ ] Click the crosshair button on the map → re-centers on Pagsanjan
- [ ] Click "↺ Re-center map" in the sidebar → same result
- [ ] No "KDE Heatmap Overlays" section appears anywhere
