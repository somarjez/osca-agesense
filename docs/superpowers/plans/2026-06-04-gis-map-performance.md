# GIS Map Performance + Correctness Fix — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the GIS Analytics map responsive — eliminate the "page unresponsive" freeze, stop per-pan repaints, and ensure each of the 4 visualizations renders correctly while only recomputing what changed.

**Architecture:** All work is in one file: the inline Leaflet JS inside `osca-system/resources/views/reports/gis.blade.php`. The decisive fix replaces per-pixel point-in-polygon ray-casting (run millions of times per heatmap build) with a one-time rasterized boundary **mask** (O(1) array lookup per pixel). Secondary fixes stop the accessibility heatmap from repainting on pan, cache the all-seniors boundary validation so filter/mode switches don't re-run geometry, cap raster resolution, and clarify the Risk legend.

**Tech Stack:** Laravel Blade, vanilla JS, Leaflet (+ leaflet.markercluster), HTML Canvas 2D. Build via Vite (`npm run build`). No JS test runner exists in this project.

---

## Testing note (read before starting)

This project has **no JavaScript test harness** (no Vitest/Jest; the code is inline in a Blade template). Introducing one is out of scope per the spec. Verification is therefore **browser-based with concrete, measurable expectations** using Chrome DevTools. Each task below specifies exactly what to measure and the pass threshold. Treat those as the test.

**Standing setup for every verification step:**
1. App dir is `osca-system/osca-system`. Build assets: `npm run build`
2. Start the app (or use the running dev server). Log in and open **Reports → GIS Analytics**.
3. Hard-refresh the page (Ctrl+Shift+R) so the rebuilt bundle loads.
4. Open DevTools → **Performance** tab (for long-task/freeze checks) and **Console** (for warnings).

**Primary pass condition (whole plan):** Switching between all 4 visualization modes and toggling every filter never triggers the browser "page unresponsive" dialog, and the longest main-thread task during a mode switch is well under ~200ms (check the Performance flame chart — no multi-second yellow block).

---

## File structure

Single file, modified in place:

- **Modify:** `osca-system/resources/views/reports/gis.blade.php`
  - New helper region (boundary mask) added near the other raster helpers (~line 1898, just above `rasterSizeForBounds`).
  - Edits at: `createClusterDistributionRasterLayer` (~2316), `createClusterFlowHeatmapLayer` (~4249), `createAccessibilityPointHeatmapLayer` (~4008/4016/4019), `rasterSizeForBounds` (~1911), `validatedFeatureSet` (~1324), `renderMap` data-load `.then` (~5234), `buildRiskIdentityHaloLayer` (~4389) + its caller `renderRiskHeatmap` (~4550), and `updateLegend` risk branch (~713).

All commits run from the git root: `C:/Users/jramo/OneDrive/Desktop/02. AgeSense/osca-system` (current branch: `fix/gis-map-performance`). The Blade file's git path is `osca-system/resources/views/reports/gis.blade.php`.

---

## Task 1: Add the reusable boundary-mask helper

**Files:**
- Modify: `osca-system/resources/views/reports/gis.blade.php` (insert above `rasterSizeForBounds`, ~line 1898)

This helper paints the clip boundary polygon onto an offscreen canvas once and returns a flat `Uint8Array` where `1` = pixel is inside the boundary. It accepts a `projectFn(lat, lng) → {x, y}` so it works in both raster space (Tasks 2) and screen space (Task 3). A small cache stores raster-space masks since the municipal bounds are stable across filters/zoom.

- [ ] **Step 1: Insert the helper functions**

Insert immediately **before** `function rasterSizeForBounds(bounds, options = {}) {`:

```javascript
    // Rasterizes a clip-boundary polygon into a flat inside/outside bitmap.
    // projectFn maps a (lat, lng) to canvas pixel coords for the target space.
    // Replaces per-pixel point-in-polygon ray-casting (which ran pixels ×
    // boundary-vertices times and froze the main thread) with an O(1) lookup.
    function buildBoundaryMask(width, height, projectFn, boundary) {
        if (!hasBoundaryFeatures(boundary) || width <= 0 || height <= 0) {
            return null;
        }

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#000000';

        const tracePolygon = (rings) => {
            if (!Array.isArray(rings) || !rings.length) return;
            ctx.beginPath();
            rings.forEach((ring) => {
                if (!Array.isArray(ring)) return;
                ring.forEach(([lng, lat], pointIndex) => {
                    const point = projectFn(Number(lat), Number(lng));
                    if (pointIndex === 0) {
                        ctx.moveTo(point.x, point.y);
                    } else {
                        ctx.lineTo(point.x, point.y);
                    }
                });
                ctx.closePath();
            });
            // even-odd so inner rings (holes) are carved out, matching
            // pointInsideBoundary's outer-ring / hole semantics.
            ctx.fill('evenodd');
        };

        boundary.features.forEach((feature) => {
            const geometry = feature?.geometry;
            const coordinates = geometry?.coordinates;
            if (!geometry || !Array.isArray(coordinates)) return;

            if (geometry.type === 'Polygon') {
                tracePolygon(coordinates);
            } else if (geometry.type === 'MultiPolygon') {
                coordinates.forEach((polygon) => tracePolygon(polygon));
            }
        });

        const data = ctx.getImageData(0, 0, width, height).data;
        const mask = new Uint8Array(width * height);
        for (let pixel = 0; pixel < mask.length; pixel++) {
            mask[pixel] = data[(pixel * 4) + 3] > 0 ? 1 : 0;
        }

        return mask;
    }

    // Raster-space masks are keyed by bounds + size; the municipal bounds are
    // constant across filters and zoom, so the mask is built at most once.
    const rasterBoundaryMaskCache = new Map();

    function getRasterBoundaryMask(bounds, width, height, boundary) {
        if (!hasBoundaryFeatures(boundary) || !bounds?.isValid?.()) {
            return null;
        }

        const key = [
            bounds.getSouth().toFixed(6),
            bounds.getWest().toFixed(6),
            bounds.getNorth().toFixed(6),
            bounds.getEast().toFixed(6),
            `${width}x${height}`,
        ].join('|');

        if (rasterBoundaryMaskCache.has(key)) {
            return rasterBoundaryMaskCache.get(key);
        }

        const mask = buildBoundaryMask(
            width,
            height,
            (lat, lng) => latLngToRasterPoint(lat, lng, bounds, width, height),
            boundary
        );
        rasterBoundaryMaskCache.set(key, mask);

        return mask;
    }
```

- [ ] **Step 2: Verify the page still loads (no syntax error)**

Run: `npm run build` (in `osca-system/osca-system`)
Expected: build succeeds with no errors. Hard-refresh the GIS page; the map still renders in the default "Senior Population Overview" mode (unchanged behavior — helper is not used yet).

- [ ] **Step 3: Commit**

```bash
git add osca-system/resources/views/reports/gis.blade.php
git commit -m "perf(gis): add reusable boundary-mask helper for raster clipping"
```

---

## Task 2: Use the mask in the raster engine (fixes Risk + Cluster freeze)

**Files:**
- Modify: `osca-system/resources/views/reports/gis.blade.php` — `createClusterDistributionRasterLayer`, the `rasterPixelInsideBoundary` definition (~lines 2316–2325)

This is the decisive freeze fix. The two call sites (`if (!rasterPixelInsideBoundary(x, y)) continue;` at ~2407 and ~2500) stay unchanged — we only swap the implementation from a per-pixel ray-cast to a mask lookup.

- [ ] **Step 1: Replace the `rasterPixelInsideBoundary` definition**

Find this exact block (inside `createClusterDistributionRasterLayer`, just before the `for (let index = 0; ...)` output loop):

```javascript
        const rasterPixelInsideBoundary = (x, y) => {
            if (!hasBoundaryFeatures(options.clipBoundary)) {
                return true;
            }

            const lng = bounds.getWest() + ((x + 0.5) / width) * (bounds.getEast() - bounds.getWest());
            const lat = bounds.getNorth() - ((y + 0.5) / height) * (bounds.getNorth() - bounds.getSouth());

            return pointInsideBoundary([lng, lat], options.clipBoundary);
        };
```

Replace it with:

```javascript
        const boundaryMask = getRasterBoundaryMask(bounds, width, height, options.clipBoundary);
        const rasterPixelInsideBoundary = (x, y) => {
            if (!boundaryMask) {
                return true;
            }

            return boundaryMask[(y * width) + x] === 1;
        };
```

- [ ] **Step 2: Verify the freeze is gone (Risk mode)**

Build (`npm run build`), hard-refresh. Start a DevTools Performance recording, then change **Visualization** to **Risk Indicator Distribution**. Stop the recording.
Expected: the surface renders; **no "page unresponsive" dialog**; the mode-switch main-thread task is well under ~200ms (no multi-second block). The risk heatmap still clips cleanly to the Pagsanjan boundary (no color bleeding outside the municipality).

- [ ] **Step 3: Verify Cluster mode raster too**

Change **Visualization** to **Cluster / Health Groups Heatmap** with the Cluster filter = "All Groups". Confirm it renders without freeze and is clipped to the boundary.

- [ ] **Step 4: Commit**

```bash
git add osca-system/resources/views/reports/gis.blade.php
git commit -m "perf(gis): replace per-pixel raster clip with boundary mask lookup"
```

---

## Task 3: Use the mask in the cluster-flow layer (fixes cluster-mode zoom freeze)

**Files:**
- Modify: `osca-system/resources/views/reports/gis.blade.php` — `createClusterFlowHeatmapLayer` `_redraw`, the per-pixel boundary loop (~lines 4249–4264)

The cluster-flow canvas is screen-space (projection changes each zoom), so its mask cannot be cached — but building it once per redraw (O(pixels)) still removes the per-pixel × per-vertex ray-cast that froze on zoom.

- [ ] **Step 1: Replace the per-pixel boundary loop**

Find this exact block inside `createClusterFlowHeatmapLayer`'s `_redraw`:

```javascript
                const boundary = this._options.clipBoundary ?? primaryBoundaryGeoJson();
                const image = context.getImageData(0, 0, width, height);
                for (let index = 0; index < image.data.length; index += 4) {
                    if (!image.data[index + 3]) continue;
                    const pixel = index / 4;
                    const cssX = (pixel % width) / ratio;
                    const cssY = Math.floor(pixel / width) / ratio;

                    if (hasBoundaryFeatures(boundary) && !canvasPixelInsideBoundary(this._map, cssX, cssY, boundary)) {
                        image.data[index + 3] = 0;
                        continue;
                    }

                    contourDensityGrid[pixel] = clampUnit(image.data[index + 3] / 190);
                }
                context.putImageData(image, 0, 0);
```

Replace it with:

```javascript
                const boundary = this._options.clipBoundary ?? primaryBoundaryGeoJson();
                const image = context.getImageData(0, 0, width, height);
                const flowMask = hasBoundaryFeatures(boundary)
                    ? buildBoundaryMask(width, height, (lat, lng) => {
                        const containerPoint = this._map.latLngToContainerPoint([lat, lng]);
                        return { x: containerPoint.x * ratio, y: containerPoint.y * ratio };
                    }, boundary)
                    : null;
                for (let index = 0; index < image.data.length; index += 4) {
                    if (!image.data[index + 3]) continue;
                    const pixel = index / 4;

                    if (flowMask && flowMask[pixel] !== 1) {
                        image.data[index + 3] = 0;
                        continue;
                    }

                    contourDensityGrid[pixel] = clampUnit(image.data[index + 3] / 190);
                }
                context.putImageData(image, 0, 0);
```

- [ ] **Step 2: Verify cluster-mode zoom no longer freezes**

Build, hard-refresh, switch to **Cluster / Health Groups Heatmap**. Start a Performance recording and zoom in/out twice. Stop.
Expected: no freeze; each zoom redraw is well under ~200ms; flow heat stays clipped to the boundary. Panning only repositions (already the case) — confirm it stays smooth.

- [ ] **Step 3: Commit**

```bash
git add osca-system/resources/views/reports/gis.blade.php
git commit -m "perf(gis): mask-clip cluster-flow heat redraw instead of per-pixel raycast"
```

---

## Task 4: Stop the accessibility heatmap repainting on pan

**Files:**
- Modify: `osca-system/resources/views/reports/gis.blade.php` — `createAccessibilityPointHeatmapLayer` `onAdd`/`onRemove`/`_reset` (~lines 4008–4030)

The accessibility canvas repaints fully on `moveend`. Mirror the cluster-flow pattern: redraw only on `zoomend`/`resize`, and merely **reposition** the canvas on `moveend`.

- [ ] **Step 1: Update the event bindings in `onAdd`**

Find (inside `createAccessibilityPointHeatmapLayer`'s `onAdd`):

```javascript
                (map.getPane('gis-heat-pane') ?? map.getPanes().overlayPane).appendChild(this._canvas);
                map.on('moveend zoomend resize', this._reset, this);
                this._reset();
```

Replace with:

```javascript
                (map.getPane('gis-heat-pane') ?? map.getPanes().overlayPane).appendChild(this._canvas);
                map.on('zoomend resize', this._reset, this);
                map.on('moveend', this._reposition, this);
                this._reset();
```

- [ ] **Step 2: Update `onRemove`**

Find:

```javascript
                map.off('moveend zoomend resize', this._reset, this);
            },
```

Replace with:

```javascript
                map.off('zoomend resize', this._reset, this);
                map.off('moveend', this._reposition, this);
            },
```

- [ ] **Step 3: Add the `_reposition` method**

Immediately **after** the `_reset() { ... }` method closes (the line `            },` right after `this._redraw();`), insert:

```javascript
            // Pan: reposition the canvas only; content is frozen until the next
            // zoom/resize redraw. Avoids the per-pan full-canvas gradient repaint.
            _reposition() {
                if (!this._canvas) return;
                const topLeft = this._map.containerPointToLayerPoint([0, 0]);
                window.L.DomUtil.setPosition(this._canvas, topLeft);
            },
```

- [ ] **Step 4: Verify accessibility pan is smooth**

Build, hard-refresh, switch to **Accessibility Heatmap**. Start a Performance recording and pan around several times. Stop.
Expected: panning produces **no** heatmap repaint task (only cheap repositioning); the heat surface tracks the map correctly after pan; zooming still redraws it crisply.

- [ ] **Step 5: Commit**

```bash
git add osca-system/resources/views/reports/gis.blade.php
git commit -m "perf(gis): reposition accessibility heat on pan instead of repainting"
```

---

## Task 5: Cap raster resolution

**Files:**
- Modify: `osca-system/resources/views/reports/gis.blade.php` — `rasterSizeForBounds` (~lines 1911–1913)

Smaller canvases mean fewer pixels to process and smaller masks, with no visible quality loss at this map scale (the output is blurred anyway).

- [ ] **Step 1: Lower the caps**

Find:

```javascript
        const pixelRatio = Math.max(1, Math.min(options.pixelRatioCap ?? 2, window.devicePixelRatio || 1));
        const maxSide = Math.round((options.maxRasterSide ?? 1280) * pixelRatio);
        const minSide = Math.round((options.minRasterSide ?? 720) * pixelRatio);
```

Replace with:

```javascript
        const pixelRatio = Math.max(1, Math.min(options.pixelRatioCap ?? 1.5, window.devicePixelRatio || 1));
        const maxSide = Math.round((options.maxRasterSide ?? 900) * pixelRatio);
        const minSide = Math.round((options.minRasterSide ?? 560) * pixelRatio);
```

- [ ] **Step 2: Verify visual quality is still acceptable**

Build, hard-refresh. Switch through Risk and Cluster heatmaps.
Expected: surfaces still look smooth (no obvious blockiness); switches are even snappier than after Task 2.

- [ ] **Step 3: Commit**

```bash
git add osca-system/resources/views/reports/gis.blade.php
git commit -m "perf(gis): cap heatmap raster resolution to reduce build cost"
```

---

## Task 6: Memoize the all-seniors boundary validation

**Files:**
- Modify: `osca-system/resources/views/reports/gis.blade.php` — add `prevalidateAllFeatures` (near `validatedFeatureSet`, ~line 1313), read the cache in `validatedFeatureSet` (~1324–1349), and call it once in `renderMap`'s data-load `.then` (~line 5238)

`validatedFeatureSet` runs point-in-polygon geometry for every senior on **every** filter/mode switch. Compute each senior's validity **once** after data load, stash it on the feature, and have `validatedFeatureSet` reuse it.

- [ ] **Step 1: Add `prevalidateAllFeatures`**

Insert immediately **before** `function validatedFeatureSet(features, options = {}) {`:

```javascript
    // Computes each senior's boundary validity + coordinate kind once after data
    // load. Filter/mode switches then reuse these flags instead of re-running
    // point-in-polygon geometry for every senior on every interaction.
    function prevalidateAllFeatures(features) {
        if (!Array.isArray(features)) return;

        features.forEach((feature) => {
            feature.__gisValidity = {
                kind: coordinateKind(feature),
                insidePrimary: featureInsidePrimaryBoundary(feature),
                insideAssigned: featureInsideAssignedBarangay(feature),
            };
        });
    }
```

- [ ] **Step 2: Read the cache inside `validatedFeatureSet`**

Find:

```javascript
        features.forEach((feature) => {
            const kind = coordinateKind(feature);

            if (!featureInsidePrimaryBoundary(feature)) {
                stats.outsidePagsanjan++;
                return;
            }

            if (!featureInsideAssignedBarangay(feature)) {
                stats.mismatches++;
                return;
            }
```

Replace with:

```javascript
        features.forEach((feature) => {
            const validity = feature.__gisValidity;
            const kind = validity ? validity.kind : coordinateKind(feature);
            const insidePrimary = validity ? validity.insidePrimary : featureInsidePrimaryBoundary(feature);
            const insideAssigned = validity ? validity.insideAssigned : featureInsideAssignedBarangay(feature);

            if (!insidePrimary) {
                stats.outsidePagsanjan++;
                return;
            }

            if (!insideAssigned) {
                stats.mismatches++;
                return;
            }
```

- [ ] **Step 3: Call `prevalidateAllFeatures` once after boundaries load**

In `renderMap`'s `.then` handler, find:

```javascript
                latestBarangayBoundaryGeoJson = barangayBoundaryGeoJson;
                initializeFilters(seniorGeoJson.features || []);
                renderBoundaryLayers(map, municipalBoundaryGeoJson, barangayBoundaryGeoJson);
```

Replace with:

```javascript
                latestBarangayBoundaryGeoJson = barangayBoundaryGeoJson;
                prevalidateAllFeatures(seniorGeoJson.features || []);
                initializeFilters(seniorGeoJson.features || []);
                renderBoundaryLayers(map, municipalBoundaryGeoJson, barangayBoundaryGeoJson);
```

- [ ] **Step 4: Verify counts are unchanged and switches are cheaper**

Build, hard-refresh. Note the senior count in the status line and KPI cards in "Senior Population Overview". Switch modes and toggle filters several times.
Expected: counts are identical to before this task (no behavior change), and a Performance recording of repeated mode switches shows no repeated geometry-heavy task — switching is noticeably faster.

- [ ] **Step 5: Commit**

```bash
git add osca-system/resources/views/reports/gis.blade.php
git commit -m "perf(gis): cache per-senior boundary validation once per data load"
```

---

## Task 7: Clarify the Risk legend and render halo dots on canvas

**Files:**
- Modify: `osca-system/resources/views/reports/gis.blade.php` — `updateLegend` risk branch (~lines 713–723), `buildRiskIdentityHaloLayer` signature + caller `renderRiskHeatmap` (~4389, ~4550)

Per the design decision (keep both dots and heatmap, just explain), add a one-line clarification to the Risk legend so a lone senior dot over faint color reads as expected. Also render the ~275 halo dots through the canvas renderer for smoother pan/zoom.

- [ ] **Step 1: Add the clarification to the Risk legend**

In `updateLegend`, find the generic heatmap branch (the `legendEl.innerHTML = ...` that uses `heatmapLabel[0..2]`):

```javascript
            legendEl.innerHTML = `
                <span class="font-semibold text-ink-700 dark:text-[#b0b5b2]">${heatmapLabel[0]}</span>
                <span class="inline-flex items-center gap-2 min-w-[260px]">
                    <span>${heatmapLabel[1]}</span>
                    <span class="h-2.5 w-28 rounded-full inline-block border border-white/70" style="background:${gradient};"></span>
                    <span>${heatmapLabel[2]}</span>
                </span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-sky-600 inline-block"></span>Facilities</span>
                ${boundaryLegend}
            `;
            return;
```

Replace with:

```javascript
            const riskDotNote = mode === 'risk-indicator-heatmap'
                ? '<span class="text-ink-400 dark:text-[#6b7570]">Dots are individual seniors; color shows local risk density. A senior in a sparsely populated area may appear as a dot with little surrounding color.</span>'
                : '';

            legendEl.innerHTML = `
                <span class="font-semibold text-ink-700 dark:text-[#b0b5b2]">${heatmapLabel[0]}</span>
                <span class="inline-flex items-center gap-2 min-w-[260px]">
                    <span>${heatmapLabel[1]}</span>
                    <span class="h-2.5 w-28 rounded-full inline-block border border-white/70" style="background:${gradient};"></span>
                    <span>${heatmapLabel[2]}</span>
                </span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-sky-600 inline-block"></span>Facilities</span>
                ${boundaryLegend}
                ${riskDotNote}
            `;
            return;
```

- [ ] **Step 2: Thread `map` into `buildRiskIdentityHaloLayer` and use the canvas renderer**

Find the signature and the `circleMarker` call:

```javascript
    function buildRiskIdentityHaloLayer(features) {
        // Small senior dots (colored by real risk level) shown above the risk
        // KDE surface so markers stay visible and popups keep working.
        return window.L.geoJSON({
            type: 'FeatureCollection',
            features,
        }, {
            pointToLayer(feature, latlng) {
                const color = riskColor(feature.properties?.risk_level);

                return window.L.circleMarker(latlng, {
                    pane: 'gis-senior-pane',
                    radius: 3.5,
```

Replace with:

```javascript
    function buildRiskIdentityHaloLayer(map, features) {
        // Small senior dots (colored by real risk level) shown above the risk
        // KDE surface so markers stay visible and popups keep working.
        return window.L.geoJSON({
            type: 'FeatureCollection',
            features,
        }, {
            pointToLayer(feature, latlng) {
                const color = riskColor(feature.properties?.risk_level);

                return window.L.circleMarker(latlng, {
                    renderer: getCanvasRenderer(map),
                    pane: 'gis-senior-pane',
                    radius: 3.5,
```

- [ ] **Step 3: Update the caller in `renderRiskHeatmap`**

Find:

```javascript
        ensureLayerRegistry(map).seniors.addLayer(buildRiskIdentityHaloLayer(features));
```

Replace with:

```javascript
        ensureLayerRegistry(map).seniors.addLayer(buildRiskIdentityHaloLayer(map, features));
```

- [ ] **Step 4: Verify**

Build, hard-refresh, switch to **Risk Indicator Distribution**.
Expected: the new clarification line appears in the legend under the map; halo dots still render and their popups still open on click; pan/zoom with dots visible stays smooth.

- [ ] **Step 5: Commit**

```bash
git add osca-system/resources/views/reports/gis.blade.php
git commit -m "feat(gis): clarify risk legend and render risk halo dots on canvas"
```

---

## Task 8: Per-mode correctness audit

**Files:**
- Modify: `osca-system/resources/views/reports/gis.blade.php` (only if a bug is found)

Now that the map is responsive, confirm each mode behaves correctly. The user flagged "filters not applying" and "colors/legend mismatch" but could not confirm under the freeze. This task is a structured manual audit; fix any real issue found, otherwise record that the mode is correct.

- [ ] **Step 1: Audit each of the 4 modes**

For **each** mode (Senior Population Overview, Risk Indicator Distribution, Cluster / Health Groups Heatmap, Accessibility Heatmap), verify:

1. **Barangay filter:** select a specific barangay → only that barangay's seniors/surface remain; the map re-centers on it; status count drops accordingly. Reset to "All Barangays".
2. **Risk filter:** select Low, then Moderate, then High → the rendered set and counts change to match; selecting High shows fewer than All.
3. **Cluster filter:** select each available group → the rendered set narrows to that group (most relevant in Cluster mode).
4. **Colors vs legend:** the colors drawn match the legend swatches for that mode (risk green→red; cluster group colors; accessibility green→red).
5. **Counts:** the status line and KPI cards ("Total Mapped Seniors", "High Risk Seniors", "Barangays Covered") are consistent with the active filters.

- [ ] **Step 2: Record findings / fix bugs**

For each issue found, note the exact symptom and the mode, then fix it in `gis.blade.php` and re-verify that mode. If no issues are found, note "all 4 modes verified correct" in the commit message.

- [ ] **Step 3: Commit**

If fixes were made:
```bash
git add osca-system/resources/views/reports/gis.blade.php
git commit -m "fix(gis): <describe the specific correctness fix>"
```
If no fixes were needed, skip the commit (nothing changed).

---

## Task 9: Full verification pass and final check

**Files:** none (verification only)

- [ ] **Step 1: Rebuild and clear caches**

Run (in `osca-system/osca-system`): `npm run build`
Hard-refresh the GIS page.

- [ ] **Step 2: End-to-end responsiveness check**

With DevTools Performance recording:
1. Cycle through all 4 visualization modes in order.
2. In each mode, pan around and zoom in/out twice.
3. Toggle the barangay, risk, and cluster filters.

Expected (all must hold):
- **No "page unresponsive" dialog at any point.**
- No single main-thread task exceeds ~200ms during mode switches.
- Panning never triggers a heatmap repaint task (only repositioning).
- Console shows no errors and no repeated cluster-value warning spam.

- [ ] **Step 3: Confirm the branch is clean and push-ready**

Run: `git status` (from git root)
Expected: working tree clean, all changes committed on `fix/gis-map-performance`.

- [ ] **Step 4: Open a PR**

Per project workflow (branch + PR, never push to main). Use `gh.exe` (quote the path). Base branch: `main`. Summarize the freeze fix, pan-lag fix, validation memoization, and the Risk legend clarification.

---

## Self-review notes

- **Spec coverage:** A (mask precompute) → Tasks 1–2; resolution cap → Task 5; B (static/non-repaint overlays) → Tasks 3–4 (cluster-flow already repositions on pan; accessibility fixed); C (memoize) → Task 6 (per-senior validation cache). **Deviation:** the spec's "rebuild boundary/facility layers only when inputs change" and "memoize built rasters by filter signature" micro-optimizations are intentionally **descoped** — once Task 6 removes the per-senior geometry from boundary rebuilds, the remaining boundary/facility rebuild is cheap polygon construction (no freeze, no measurable lag), so adding cross-render layer caching would add staleness risk for negligible gain (YAGNI). If Task 9 still shows a slow switch, revisit. D (risk dots keep+explain) → Task 7; E (correctness audit) → Task 8.
- **Type/name consistency:** `buildBoundaryMask(width, height, projectFn, boundary)` is defined in Task 1 and called in Tasks 2 (via `getRasterBoundaryMask`) and 3. `getRasterBoundaryMask` defined and used in Task 2. `prevalidateAllFeatures` defined and called in Task 6. `buildRiskIdentityHaloLayer` signature changed to `(map, features)` in Task 7 with its sole caller updated in the same task. `getCanvasRenderer(map)` already exists in the file (used by the markers layer). `latLngToRasterPoint`, `hasBoundaryFeatures`, `clampUnit`, `primaryBoundaryGeoJson`, `featureInsidePrimaryBoundary`, `featureInsideAssignedBarangay`, `coordinateKind` all pre-exist.
- **Placeholder scan:** none — every code step shows the exact before/after. Task 8 is genuinely a manual audit (no code unless a bug surfaces), which is appropriate.
