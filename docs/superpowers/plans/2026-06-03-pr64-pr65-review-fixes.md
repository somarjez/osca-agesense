# PR#64 & PR#65 Code-Review Fixes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply all confirmed code-review findings from PR#64 (merged) and PR#65 (open) to the main branch working copy.

**Architecture:** Eight targeted edits to a single Blade/JS file (`gis.blade.php`). No PHP changes, no new dependencies, no migrations. Changes fall into three groups: visual-regression fixes (Tasks 1–3), a performance refactor (Task 4), and post-merge cleanups (Tasks 5–8).

**Tech Stack:** Laravel 11, Leaflet.js, inline JavaScript in Blade template.

---

## File Map

| File | What changes |
|------|-------------|
| `resources/views/reports/gis.blade.php` | All 8 tasks — label fix, opacity, halo, contour cache, dead-code removal, unused variable, redundant resets, shared divIcon helper |

---

## Task 1: Fix frontend/backend label mismatch (`'Priority'` → `'Farthest'`)

**Files:**
- Modify: `resources/views/reports/gis.blade.php:1027`

**Background:** `accessibilityConcernFromDistance` is the frontend fallback that computes an accessibility level when the backend provides no `accessibility_level` property. The backend assigns `'Farthest'` to the worst bucket; the frontend was using `'Priority'`. Any senior whose data goes through the fallback path gets an inconsistent label in popups and filters.

- [ ] **Step 1: Verify the current value**

  ```bash
  grep -n "let level = " resources/views/reports/gis.blade.php
  ```

  Expected output includes: `1027:        let level = 'Priority';`

- [ ] **Step 2: Apply the fix**

  In `resources/views/reports/gis.blade.php` line 1027, change:
  ```js
  let level = 'Priority';
  ```
  to:
  ```js
  let level = 'Farthest';
  ```

- [ ] **Step 3: Verify**

  ```bash
  grep -n "let level = " resources/views/reports/gis.blade.php
  ```

  Expected: `1027:        let level = 'Farthest';`

- [ ] **Step 4: Commit**

  ```bash
  git add resources/views/reports/gis.blade.php
  git commit -m "fix(gis): align frontend fallback accessibility level label with backend ('Priority' -> 'Farthest')"
  ```

---

## Task 2: Restore missing stroke opacity on cluster distribution points

**Files:**
- Modify: `resources/views/reports/gis.blade.php:4828–4841`

**Background:** `buildClusterDistributionPointLayer` replaced `buildClusterIdentityHaloLayer`. The old function set `opacity: 0.82` on every `L.circleMarker`; the new one omitted it. Leaflet defaults stroke opacity to `1.0`, making the white border ring fully opaque — heavier than intended on dense cluster maps.

- [ ] **Step 1: Confirm the missing property**

  ```bash
  grep -n "opacity" resources/views/reports/gis.blade.php | grep -A5 -B5 "4828\|4829\|4830\|4831\|4832\|4833\|4834\|4835"
  ```

  Confirm that the `circleMarker` at line 4828 has `fillOpacity` but no `opacity` entry.

- [ ] **Step 2: Add the missing property**

  In `resources/views/reports/gis.blade.php`, find the `circleMarker` options inside `buildClusterDistributionPointLayer` (line 4828). Add `opacity: 0.82` after `weight`:

  ```js
  return window.L.circleMarker(latlng, {
      renderer: getCanvasRenderer(map),
      pane: 'gis-senior-pane',
      radius: isFallback ? 5 : 7.5,
      color: '#ffffff',
      weight: isFallback ? 1 : 2,
      opacity: 0.82,
      fillColor: isFallback ? colorWithAlpha(color, 0.5) : color,
      fillOpacity: isFallback ? 0.58 : 0.9,
      interactive: true,
      gisRiskLevel: feature.properties?.risk_level,
      gisBarangay: feature.properties?.barangay,
      gisCoordinateKind: kind,
      gisCluster: clusterLabel(feature),
  });
  ```

- [ ] **Step 3: Verify**

  ```bash
  grep -n "opacity: 0.82" resources/views/reports/gis.blade.php
  ```

  Should include a line near 4833 (exact line number will shift by 1 after adding the property).

- [ ] **Step 4: Commit**

  ```bash
  git add resources/views/reports/gis.blade.php
  git commit -m "fix(gis): restore opacity: 0.82 on cluster distribution circleMarker (regression from buildClusterIdentityHaloLayer replacement)"
  ```

---

## Task 3: Suppress unintended dark halo on flow heatmap contours

**Files:**
- Modify: `resources/views/reports/gis.blade.php:4086–4090`

**Background:** `drawKdeContours` was updated in PR#65 to default `haloLineWidth` to `0.25`. The call inside `createClusterFlowHeatmapLayer._redraw` omits this option, so a dark `rgba(15,23,42,0.06)` halo stroke renders around every contour line — unintentional, not mentioned in the PR. Setting `haloLineWidth: 0` explicitly disables it for this layer.

- [ ] **Step 1: Confirm the current call**

  Read lines 4086–4090:
  ```bash
  sed -n '4086,4090p' resources/views/reports/gis.blade.php
  ```

  Expected output:
  ```js
  drawKdeContours(context, contourSourceGrid, width, height, {
      step: Math.max(3, Math.round(4 * ratio)),
      levels: [0.10, 0.18, 0.28, 0.40, 0.54, 0.68, 0.82],
      lineWidth: 1.05 * ratio,
  });
  ```

- [ ] **Step 2: Add `haloLineWidth: 0`**

  In `resources/views/reports/gis.blade.php`, find the `drawKdeContours` call at line 4086 (inside `createClusterFlowHeatmapLayer._redraw`). Add `haloLineWidth: 0` after `lineWidth`:

  ```js
  drawKdeContours(context, contourSourceGrid, width, height, {
      step: Math.max(3, Math.round(4 * ratio)),
      levels: [0.10, 0.18, 0.28, 0.40, 0.54, 0.68, 0.82],
      lineWidth: 1.05 * ratio,
      haloLineWidth: 0,
  });
  ```

- [ ] **Step 3: Verify**

  ```bash
  grep -n "haloLineWidth: 0" resources/views/reports/gis.blade.php
  ```

  Should show exactly one result near the `createClusterFlowHeatmapLayer` function.

- [ ] **Step 4: Commit**

  ```bash
  git add resources/views/reports/gis.blade.php
  git commit -m "fix(gis): suppress unintended dark halo on cluster flow heatmap contours (haloLineWidth: 0)"
  ```

---

## Task 4: Cache contour computation — skip on pan, rebuild on zoom/resize

**Files:**
- Modify: `resources/views/reports/gis.blade.php` — `createClusterFlowHeatmapLayer` (lines ~4141–4091)

**Background:** `createClusterFlowHeatmapLayer._redraw` fires on every `moveend`, `zoomend`, and `resize`. It currently runs `smoothScalarGrid` (5 passes) and `drawKdeContours` (7 marching-square scans) synchronously on every event — new work absent from the layer it replaced. Caching the contour output keyed by zoom level eliminates this cost on pure pan events. On zoom or resize, the cache is invalidated and rebuilt.

**Trade-off:** Contour lines hold position during a pan and snap to correct geometry on `zoomend`. Acceptable for a secondary density overlay.

- [ ] **Step 1: Add `_contourCache` to `initialize`**

  In `resources/views/reports/gis.blade.php`, find `createClusterFlowHeatmapLayer`'s `initialize` method (~line 4143). It currently reads:
  ```js
  initialize() {
      this._points = points;
      this._options = options;
  },
  ```

  Change to:
  ```js
  initialize() {
      this._points = points;
      this._options = options;
      this._contourCache = null;
  },
  ```

- [ ] **Step 2: Replace the contour block in `_redraw`**

  In `resources/views/reports/gis.blade.php`, replace the entire block from line 4039 (`const contourDensityGrid = new Float32Array(...)`) through line 4090 (the closing `});` of `drawKdeContours`) with the code below. This is a full replacement of lines 4039–4090 — the point-drawing `forEach` loop (lines 4041–4066) is reproduced unchanged inside the new block.

  Replace lines 4039–4090 with:

  ```js
  // ─── point-drawing loop stays exactly as-is ────────────────────────────────
  this._points
      .slice()
      .sort((a, b) => a[2] - b[2])
      .forEach(([lat, lng, score]) => {
          const mapPoint = this._map.latLngToContainerPoint([lat, lng]);
          const point = {
              x: mapPoint.x * ratio,
              y: mapPoint.y * ratio,
          };

          if (mapPoint.x < -(radius / ratio) || mapPoint.y < -(radius / ratio) || mapPoint.x > cssWidth + (radius / ratio) || mapPoint.y > cssHeight + (radius / ratio)) {
              return;
          }

          const [red, green, blue] = colorForGradientValue(score, this._stops);
          const gradient = context.createRadialGradient(point.x, point.y, 0, point.x, point.y, radius);
          gradient.addColorStop(0.00, `rgba(${red},${green},${blue},0.66)`);
          gradient.addColorStop(0.20, `rgba(${red},${green},${blue},0.48)`);
          gradient.addColorStop(0.48, `rgba(${red},${green},${blue},0.24)`);
          gradient.addColorStop(0.78, `rgba(${red},${green},${blue},0.08)`);
          gradient.addColorStop(1.00, `rgba(${red},${green},${blue},0)`);
          context.fillStyle = gradient;
          context.beginPath();
          context.arc(point.x, point.y, radius, 0, Math.PI * 2);
          context.fill();
      });
  // ─── end of unchanged point-drawing loop ────────────────────────────────────

  const currentZoom = this._map.getZoom();
  const needContour = !this._contourCache
      || this._contourCache.zoom !== currentZoom
      || this._contourCache.canvas.width !== width
      || this._contourCache.canvas.height !== height;

  const boundary = this._options.clipBoundary ?? primaryBoundaryGeoJson();
  const image = context.getImageData(0, 0, width, height);
  const contourDensityGrid = needContour ? new Float32Array(width * height) : null;

  for (let index = 0; index < image.data.length; index += 4) {
      if (!image.data[index + 3]) continue;
      const pixel = index / 4;
      const cssX = (pixel % width) / ratio;
      const cssY = Math.floor(pixel / width) / ratio;

      if (hasBoundaryFeatures(boundary) && !canvasPixelInsideBoundary(this._map, cssX, cssY, boundary)) {
          image.data[index + 3] = 0;
          continue;
      }
      if (contourDensityGrid) {
          contourDensityGrid[pixel] = clampUnit(image.data[index + 3] / 190);
      }
  }
  context.putImageData(image, 0, 0);

  if (needContour && contourDensityGrid) {
      const offscreen = document.createElement('canvas');
      offscreen.width = width;
      offscreen.height = height;
      const contourSourceGrid = smoothScalarGrid(contourDensityGrid, width, height, 5);
      drawKdeContours(offscreen.getContext('2d'), contourSourceGrid, width, height, {
          step: Math.max(3, Math.round(4 * ratio)),
          levels: [0.10, 0.18, 0.28, 0.40, 0.54, 0.68, 0.82],
          lineWidth: 1.05 * ratio,
          haloLineWidth: 0,
      });
      this._contourCache = { canvas: offscreen, zoom: currentZoom };
  }

  if (this._contourCache) {
      context.drawImage(this._contourCache.canvas, 0, 0);
  }
  ```

  Note: the `haloLineWidth: 0` here supersedes the separate Task 3 change — if Task 3 was already committed, the `haloLineWidth: 0` inside the new `drawKdeContours` call replaces the old one. The Task 3 commit is safe either way.

- [ ] **Step 3: Verify structure**

  ```bash
  grep -n "_contourCache\|needContour\|contourDensityGrid\|drawKdeContours\|smoothScalarGrid" resources/views/reports/gis.blade.php | head -30
  ```

  Expected: `_contourCache` appears in `initialize` and three times in `_redraw` (`needContour` check, assignment, composite). `drawKdeContours` inside `createClusterFlowHeatmapLayer` now draws to `offscreen.getContext('2d')`, not `context`.

- [ ] **Step 4: Commit**

  ```bash
  git add resources/views/reports/gis.blade.php
  git commit -m "perf(gis): cache flow heatmap contour per zoom level — skip smoothScalarGrid+drawKdeContours on pan"
  ```

---

## Task 5: Delete dead `buildClusterPointRampLayer`

**Files:**
- Modify: `resources/views/reports/gis.blade.php:4282–4816`

**Background:** All three call sites were replaced by `buildClusterFlowHeatmapLayer` in PR#65. The function body (~535 lines) is unreachable. Leaving it risks future edits to the wrong copy and unnecessarily inflates the file.

- [ ] **Step 1: Confirm zero callers**

  ```bash
  grep -n "buildClusterPointRampLayer" resources/views/reports/gis.blade.php
  ```

  Expected: exactly one result — the `function buildClusterPointRampLayer(` definition. No call sites.

- [ ] **Step 2: Delete lines 4282–4816**

  In `resources/views/reports/gis.blade.php`, delete from line 4282 (`    function buildClusterPointRampLayer(features, options = {}) {`) through line 4816 (`    }`) inclusive, plus the blank line 4281 before it.

  The line immediately after the deletion should be:
  ```
      function buildClusterDistributionPointLayer(map, features) {
  ```

- [ ] **Step 3: Verify**

  ```bash
  grep -n "buildClusterPointRampLayer" resources/views/reports/gis.blade.php
  ```

  Expected: no results.

- [ ] **Step 4: Commit**

  ```bash
  git add resources/views/reports/gis.blade.php
  git commit -m "refactor(gis): remove dead buildClusterPointRampLayer (no callers since PR#65 replaced all call sites)"
  ```

---

## Task 6: Remove unused `opacity` variable in `drawKdeContours`

**Files:**
- Modify: `resources/views/reports/gis.blade.php:2122`

**Background:** `const opacity = 0.18 + levelFrac * 0.26` was left behind when the `strokeStyle` expression was refactored to use `options.opacityBase`/`opacityRange`/`maxOpacity`. The variable is assigned but never read.

- [ ] **Step 1: Confirm the dead variable**

  ```bash
  sed -n '2120,2136p' resources/views/reports/gis.blade.php
  ```

  Confirm line 2122 is `const opacity = 0.18 + levelFrac * 0.26;` and that `opacity` does not appear in the lines that follow within the same loop iteration.

- [ ] **Step 2: Delete line 2122**

  In `resources/views/reports/gis.blade.php`, delete the line:
  ```js
  const opacity = 0.18 + levelFrac * 0.26;
  ```

- [ ] **Step 3: Verify**

  ```bash
  grep -n "const opacity" resources/views/reports/gis.blade.php
  ```

  Expected: no results.

- [ ] **Step 4: Commit**

  ```bash
  git add resources/views/reports/gis.blade.php
  git commit -m "refactor(gis): remove unused opacity variable in drawKdeContours (leftover from opacityBase/opacityRange refactor)"
  ```

---

## Task 7: Remove redundant shadow reset in `drawKdeContours`

**Files:**
- Modify: `resources/views/reports/gis.blade.php:2131–2132` (line numbers shift by −1 after Task 6)

**Background:** After Task 6 removes line 2122, what were lines 2132–2133 become 2131–2132. The `context.shadowColor = 'transparent'; context.shadowBlur = 0;` pair appears twice per contour iteration: once before the `if (haloLineWidth > 0)` block, and again immediately after it. Nothing inside the `if` block changes shadow state, making the second assignment a no-op.

- [ ] **Step 1: Confirm the redundant lines**

  After Task 6 is committed, run:
  ```bash
  sed -n '2120,2135p' resources/views/reports/gis.blade.php
  ```

  Expected output (approximate — line numbers shift after Task 6):
  ```js
  context.shadowColor = 'transparent';
  context.shadowBlur = 0;
  const haloLineWidth = options.haloLineWidth ?? 0.25;
  if (haloLineWidth > 0) {
      context.lineWidth = lineWidth + haloLineWidth;
      context.strokeStyle = `rgba(15,23,42,${options.haloOpacity ?? 0.06})`;
      context.stroke();
  }
  context.lineWidth = lineWidth;
  context.shadowColor = 'transparent';   // ← redundant
  context.shadowBlur = 0;               // ← redundant
  context.strokeStyle = `rgba(255,255,255,...`;
  context.stroke();
  ```

- [ ] **Step 2: Delete the two redundant lines**

  In `resources/views/reports/gis.blade.php`, find the second `context.shadowColor = 'transparent';` and `context.shadowBlur = 0;` pair (the one that appears after the closing `}` of the `if (haloLineWidth > 0)` block, directly before `context.strokeStyle`). Delete both lines.

  The block should now read:
  ```js
  context.shadowColor = 'transparent';
  context.shadowBlur = 0;
  const haloLineWidth = options.haloLineWidth ?? 0.25;
  if (haloLineWidth > 0) {
      context.lineWidth = lineWidth + haloLineWidth;
      context.strokeStyle = `rgba(15,23,42,${options.haloOpacity ?? 0.06})`;
      context.stroke();
  }
  context.lineWidth = lineWidth;
  context.strokeStyle = `rgba(255,255,255,${Math.min(options.maxOpacity ?? 0.88, (options.opacityBase ?? 0.48) + (levelFrac * (options.opacityRange ?? 0.30)))})`;
  context.stroke();
  ```

- [ ] **Step 3: Verify**

  ```bash
  grep -n "shadowColor\|shadowBlur" resources/views/reports/gis.blade.php
  ```

  Expected: exactly two results (one `shadowColor` and one `shadowBlur`), both within `drawKdeContours`.

- [ ] **Step 4: Commit**

  ```bash
  git add resources/views/reports/gis.blade.php
  git commit -m "refactor(gis): remove redundant second shadowColor/shadowBlur reset in drawKdeContours"
  ```

---

## Task 8: Extract shared `makeClusterDivIcon` helper

**Files:**
- Modify: `resources/views/reports/gis.blade.php` — 3 `iconCreateFunction` sites (lines ~4874, ~4976, ~5625) + insert helper before `buildClusterDistributionPointLayer`

**Background:** Three `iconCreateFunction` implementations return byte-for-byte identical `L.divIcon(...)` HTML for the 34×34px circular cluster badge. Extract to a single named function to eliminate the duplication.

- [ ] **Step 1: Confirm the three duplicate sites**

  ```bash
  grep -n "gis-cluster-icon" resources/views/reports/gis.blade.php
  ```

  Expected: three results (one in `buildClusterDistributionPointLayer`, one in `buildAccessibilitySeniorPointLayer`, one in the cluster markers block around line 5625).

- [ ] **Step 2: Insert `makeClusterDivIcon` helper**

  In `resources/views/reports/gis.blade.php`, find the blank line immediately before `function buildClusterDistributionPointLayer` (~line 4817 after prior tasks). Insert the helper function before it:

  ```js
  function makeClusterDivIcon(tone, count) {
      return window.L.divIcon({
          html: `<div style="background:${tone};color:#fff;width:34px;height:34px;border-radius:9999px;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.95);box-shadow:0 8px 18px rgba(15,23,42,0.18);font-size:11px;font-weight:700;">${count}</div>`,
          className: 'gis-cluster-icon',
          iconSize: [34, 34],
      });
  }
  ```

- [ ] **Step 3: Replace the first duplicate (inside `buildClusterDistributionPointLayer`)**

  Find `iconCreateFunction` inside `buildClusterDistributionPointLayer`. Replace the `return window.L.divIcon({...})` block (the one that uses `tone` derived from the majority cluster):

  ```js
  iconCreateFunction(cluster) {
      const markers = cluster.getAllChildMarkers();
      const counts = new Map();

      markers.forEach((marker) => {
          const label = marker.options.gisCluster;
          if (!label) return;

          const key = clusterNumber(label) ?? label;
          const current = counts.get(key) ?? { label, count: 0 };
          current.count++;
          counts.set(key, current);
      });

      const majority = [...counts.values()].sort((a, b) => b.count - a.count)[0];
      const tone = majority ? clusterColorForLabel(majority.label) : '#64748b';

      return makeClusterDivIcon(tone, cluster.getChildCount());
  },
  ```

- [ ] **Step 4: Replace the second duplicate (inside `buildAccessibilitySeniorPointLayer`)**

  Find `iconCreateFunction` inside `buildAccessibilitySeniorPointLayer`. Replace the `return window.L.divIcon({...})` block:

  ```js
  iconCreateFunction(cluster) {
      const markers = cluster.getAllChildMarkers();
      const tone = accessibilityClusterTone(markers);

      return makeClusterDivIcon(tone, cluster.getChildCount());
  },
  ```

- [ ] **Step 5: Replace the third duplicate (cluster markers block ~line 5621)**

  Find `iconCreateFunction` in the cluster markers section (the one that calls `clusterTone(markers)`). Replace the `return window.L.divIcon({...})` block:

  ```js
  iconCreateFunction(cluster) {
      const markers = cluster.getAllChildMarkers();
      const tone = clusterTone(markers);

      return makeClusterDivIcon(tone, cluster.getChildCount());
  },
  ```

- [ ] **Step 6: Verify no inline badge HTML remains**

  ```bash
  grep -n "gis-cluster-icon" resources/views/reports/gis.blade.php
  ```

  Expected: exactly one result — inside `makeClusterDivIcon`.

  ```bash
  grep -n "makeClusterDivIcon" resources/views/reports/gis.blade.php
  ```

  Expected: four results — one function definition, three call sites.

- [ ] **Step 7: Commit**

  ```bash
  git add resources/views/reports/gis.blade.php
  git commit -m "refactor(gis): extract makeClusterDivIcon helper — deduplicate identical divIcon badge in 3 iconCreateFunction sites"
  ```

---

## Visual Verification (after all tasks)

There is no unit-test suite for the inline JS in this Blade file. After completing all tasks, verify visually:

- [ ] Run the app (use `run-osca-system` skill or `php artisan serve`)
- [ ] Open the GIS report page
- [ ] Switch to **Accessibility Heatmap** mode, zoom to a barangay with seniors that have no backend accessibility data. Their popup `Level` label should read `Farthest`, not `Priority`.
- [ ] Switch to **Cluster / Health Groups Heatmap** mode. Senior distribution points should have a semi-transparent (not fully opaque) white stroke border.
- [ ] Confirm no dark ring appears around the contour lines on the cluster flow heatmap.
- [ ] Pan the map rapidly — should feel smooth (no jank from contour computation on every pan).
- [ ] Zoom in/out — contour lines should update and snap to correct geometry.

- [ ] Run PHP tests to confirm no regressions:

  ```bash
  php artisan test --filter=Gis
  ```

  Expected: all passing (these cover caching headers, not JS rendering).
