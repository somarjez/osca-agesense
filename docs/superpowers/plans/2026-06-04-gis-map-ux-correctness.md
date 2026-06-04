# GIS Map UX & Correctness Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix four user-reported GIS map issues — bare cluster codes, unbounded panning with repeating tiles, accessibility-heatmap drift on drag, and risk-mode popup/point-toggle gaps — entirely within the Leaflet map JS of `resources/views/reports/gis.blade.php`.

**Architecture:** Surgical edits to the existing inline map module. A single cluster-title source of truth feeds legend/dropdown/popups; `maxBounds` is tightened and tiles get `noWrap`/`bounds`; the accessibility heatmap is converted from a live viewport `<canvas>` to a static bounds-anchored `L.imageOverlay` (reusing the existing raster-render helpers that Risk/Cluster already use); a risk-mode point-display toggle mirrors the accessibility one; and `popupHtml` reads the active mode to drop the accessibility line in risk mode.

**Tech Stack:** Laravel Blade, vanilla JS, Leaflet + Leaflet.markercluster, Vite (`npm run build`).

---

## Scope note (read before starting)

This plan implements **only the genuinely-remaining work**. Two items the design spec (`docs/superpowers/specs/2026-06-04-gis-map-ux-correctness-design.md`) lists under issue #4 are **already done** on this branch in commit `68e05d1` and are **out of scope**:

- **Risk-mode freeze fix** — the per-pixel boundary ray-cast is already replaced by a precomputed, cached mask (`getRasterBoundaryMask`, ~line 2031; O(1) lookup at ~line 2487) plus a per-pixel loop that yields every ~10 ms (`makeSliceBudget(10)` + `await yieldToEventLoop()`, ~lines 2498–2500) and raster-resolution caps (`rasterSizeForBounds`, 900 px / 1.5× ratio). User confirmed at runtime on 2026-06-04 that Risk mode no longer freezes.
- **Risk-dot legend note** — already present at ~line 762.

Do **not** re-implement either. The five tasks below are the full remaining scope.

## Testing approach

This is untested inline view JS with no JS unit-test harness, and the spec restricts changes to `gis.blade.php` only — adding a JS test runner is out of scope. Verification is therefore the spec's **manual browser protocol**: after each task, rebuild assets and hard-refresh `http://127.0.0.1:8000/reports/gis` (login `admin@osca.local` / `Admin@OSCA2026!`; the app is already running on :8000), then perform the task's specific checks. Commit after each task passes.

**Line numbers** below are from the current file and will drift as edits land; always confirm the surrounding code matches the quoted snippet before editing.

---

### Task 1: Cluster group names in legend, dropdown, and popups

**Files:**
- Modify: `resources/views/reports/gis.blade.php` (CLUSTER_HEATMAP_RAMPS ~303–356; new helper near ~545; `setSelectOptions` ~860–875; cluster select call ~880; cluster legend ~731–735; `popupHtml` ~3643, ~3660, ~3677)

- [ ] **Step 1: Add a `title` to each cluster ramp (single source of truth)**

In `CLUSTER_HEATMAP_RAMPS` (~line 303), add a `title` field to each of the four entries. Edit only the lines that currently read `label: 'C1',` … `label: 'C4',`:

```javascript
        1: {
            label: 'C1',
            title: 'C1 · High Functioning / Well-Supported Seniors',
            name: 'Cluster 1',
```
```javascript
        2: {
            label: 'C2',
            title: 'C2 · Stable Ageing / Moderate Support Needs',
            name: 'Cluster 2',
```
```javascript
        3: {
            label: 'C3',
            title: 'C3 · Environmentally and Financially Vulnerable Seniors',
            name: 'Cluster 3',
```
```javascript
        4: {
            label: 'C4',
            title: 'C4 · Low Functioning / Multi-Domain Priority Seniors',
            name: 'Cluster 4',
```

- [ ] **Step 2: Add a `clusterDisplayName` helper**

Insert immediately after `clusterLegendLabel` (after its closing `}` at ~line 554), before `function featureClusterNumber`:

```javascript
    function clusterDisplayName(featureOrNumber) {
        const number = typeof featureOrNumber === 'number'
            ? featureOrNumber
            : (featureClusterNumber(featureOrNumber) ?? clusterNumber(clusterLabel(featureOrNumber), featureOrNumber));
        return CLUSTER_HEATMAP_RAMPS[number]?.title ?? 'Unassigned';
    }
```

- [ ] **Step 3: Generalize `setSelectOptions` to accept `{ value, label }` entries**

Replace the body of `setSelectOptions` (~lines 860–875). Strings keep working unchanged; objects render a distinct label:

```javascript
    function setSelectOptions(selectId, defaultLabel, values) {
        const select = document.getElementById(selectId);
        if (!select) return;

        const entries = values.map((value) => (value && typeof value === 'object')
            ? { value: String(value.value), label: String(value.label) }
            : { value: String(value), label: String(value) });

        const currentValue = select.value || 'all';
        select.innerHTML = `<option value="all">${defaultLabel}</option>`;

        entries.forEach((entry) => {
            const option = document.createElement('option');
            option.value = entry.value;
            option.textContent = entry.label;
            select.appendChild(option);
        });

        select.value = entries.some((entry) => entry.value === currentValue) ? currentValue : 'all';
    }
```

- [ ] **Step 4: Feed cluster titles into the cluster select (value stays `Group N`)**

Change the cluster select call (~line 880) so the visible label is the full title while the option **value** stays the existing match key:

```javascript
        setSelectOptions(CLUSTER_FILTER_ID, 'All Groups', uniqueSortedClusterValues(features).map((value) => ({
            value,
            label: CLUSTER_HEATMAP_RAMPS[clusterNumber(value)]?.title ?? value,
        })));
```

Leave the `BARANGAY_FILTER_ID` and `RISK_FILTER_ID` calls (~lines 878–879) unchanged — they still pass string arrays.

- [ ] **Step 5: Show full titles in the cluster legend**

In the cluster legend builder (~lines 731–735), change the chip text from `ramp.label` to `ramp.title`:

```javascript
                const clusterLegend = Object.values(CLUSTER_HEATMAP_RAMPS).map((ramp) => `
                    <span class="inline-flex items-center gap-1.5">
                        <span class="h-2.5 w-10 rounded-full inline-block border border-white/70" style="background:${gradientCss(ramp.stops)};"></span>${ramp.title}
                    </span>
                `).join('');
```

- [ ] **Step 6: Show full titles in senior popups**

In `popupHtml` (~line 3643), replace the `healthGroup` derivation so both popup branches render the descriptive title:

```javascript
        const healthGroup = clusterDisplayName(feature);
```

(`healthGroup` is already interpolated unescaped at ~lines 3660 and 3677; the title is a trusted constant, so no `escapeHtml` is needed. Leave those two `${healthGroup}` interpolations as-is.)

- [ ] **Step 7: Build and verify**

Run: `npm run build`
Then hard-refresh `/reports/gis` and confirm:
- Filter dropdown lists the four full `C# · …` titles; selecting one still narrows the rendered seniors (filtering unchanged).
- In Cluster / Health Groups Heatmap mode, the legend chips show the full titles (wrapping is acceptable).
- A senior popup's **Health Group** line shows the full title; a senior with no cluster shows `Unassigned`.
- Console is free of new cluster-value warning spam.

- [ ] **Step 8: Commit**

```bash
git add resources/views/reports/gis.blade.php
git commit -m "feat(gis): show full cluster group titles in legend, filter, and popups"
```

---

### Task 2: Hard-lock panning to Pagsanjan and stop tile repetition

**Files:**
- Modify: `resources/views/reports/gis.blade.php` (`MUNICIPAL_NAVIGATION_PADDING_RATIO` ~264; `createTileLayer` ~5004–5011)

- [ ] **Step 1: Tighten the navigation padding ratio**

Change line ~264 from `1.25` to `0.15` so `maxBounds` fences panning just outside the municipal boundary instead of 125% beyond it:

```javascript
    const MUNICIPAL_NAVIGATION_PADDING_RATIO = 0.15;
```

- [ ] **Step 2: Add `noWrap` and `bounds` to the tile layer**

Replace `createTileLayer` (~lines 5004–5011). `noWrap` stops the basemap repeating horizontally; `bounds` stops tile requests outside the navigation area. At init the boundary data is not loaded yet, so `mapNavigationBounds()` returns the static `NAVIGATION_BOUNDS_COORDS` fallback — acceptable for limiting tile fetches:

```javascript
    function createTileLayer() {
        return window.L.tileLayer(TILE_LIGHT_URL, {
            maxZoom: 19,
            attribution: TILE_LIGHT_ATTRIBUTION,
            updateWhenIdle: true,
            keepBuffer: 4,
            noWrap: true,
            bounds: mapNavigationBounds(),
        });
    }
```

(`mapNavigationBounds` is defined later in the file at ~line 5090; function hoisting makes it callable here. The existing `maxBoundsViscosity = 1.0` and outside-boundary dim mask are unchanged.)

- [ ] **Step 3: Build and verify**

Run: `npm run build`
Then hard-refresh `/reports/gis` and confirm:
- Dragging hard in every direction is fenced to Pagsanjan — you cannot wander far outside; the map snaps back at the boundary.
- No repeated/duplicate basemap copies appear off to the sides; no stray world tiles at min zoom.
- The recenter button and initial fit still frame Pagsanjan correctly.

- [ ] **Step 4: Commit**

```bash
git add resources/views/reports/gis.blade.php
git commit -m "fix(gis): hard-lock map panning to Pagsanjan and stop tile repetition"
```

---

### Task 3: Convert the accessibility heatmap to a static overlay (stop drift)

**Files:**
- Modify: `resources/views/reports/gis.blade.php` (`createAccessibilityPointHeatmapLayer` ~4169–4316 — replace; `buildAccessibilityDistributionRasterLayer` ~4318–4341 — rewrite to use the new static builder)

**Why:** The accessibility heatmap is a live viewport `<canvas>` repositioned on `moveend` (~line 4210), so it visibly slides relative to the basemap during a drag. Risk and Cluster heatmaps don't drift because they render once into a bounds-anchored `L.imageOverlay` via `createSmoothHeatmapImageOverlay`. This task moves the accessibility heatmap onto that same static path: render the radial-gradient heat + boundary clip + KDE contours once into an offscreen canvas sized to the boundary bounds, export to PNG, and drop it as an overlay. Leaflet then transforms the overlay natively on pan/zoom — no drift, no per-pan repaint, no `moveend` handler.

- [ ] **Step 1: Replace `createAccessibilityPointHeatmapLayer` with a static raster builder**

Replace the entire `createAccessibilityPointHeatmapLayer` function (from `function createAccessibilityPointHeatmapLayer(points, options = {}) {` at ~line 4169 through its closing `}` and `return new HeatLayer();` at ~line 4316) with a function that renders once to an offscreen canvas in raster space and returns an image overlay. This reuses the same helpers the Risk/Cluster raster path uses (`rasterSizeForBounds`, `latLngToRasterPoint`, `getRasterBoundaryMask`, `smoothScalarGrid`, `drawKdeContours`, `createSmoothHeatmapImageOverlay`) and the accessibility gradient/contour parameters from the old `_redraw`:

```javascript
    function createAccessibilityHeatmapOverlay(points, bounds, options = {}) {
        if (!points.length || !bounds?.isValid?.()) {
            return null;
        }

        const stops = gradientStopsFromStops(ACCESSIBILITY_DISTRIBUTION_RAMP);
        const { width, height } = rasterSizeForBounds(bounds);
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d');

        // Radius in raster pixels: convert the meters radius to a fraction of the
        // bounds' width and scale to canvas width (bounds are constant, so this is
        // a one-time computation rather than the old per-pan metersToPixels call).
        const center = bounds.getCenter();
        const widthMeters = Math.max(1, window.L.latLng(center.lat, bounds.getWest())
            .distanceTo(window.L.latLng(center.lat, bounds.getEast())));
        const radiusMeters = options.radiusMeters ?? 620;
        const radius = Math.max(12, Math.round((radiusMeters / widthMeters) * width));

        points
            .slice()
            .sort((a, b) => a[2] - b[2])
            .forEach(([lat, lng, score]) => {
                const point = latLngToRasterPoint(lat, lng, bounds, width, height);
                const [red, green, blue] = colorForGradientValue(score, stops);
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

        // Clip to the municipal boundary using the precomputed cached mask.
        const boundary = options.clipBoundary ?? primaryBoundaryGeoJson();
        const mask = getRasterBoundaryMask(bounds, width, height, boundary);
        const image = context.getImageData(0, 0, width, height);
        const contourDensityGrid = new Float32Array(width * height);
        for (let index = 0; index < image.data.length; index += 4) {
            const pixel = index / 4;
            if (mask && mask[pixel] !== 1) {
                image.data[index + 3] = 0;
                continue;
            }
            contourDensityGrid[pixel] = clampUnit(image.data[index + 3] / 190);
        }
        context.putImageData(image, 0, 0);

        // KDE contour overlay (same levels/step as the old live layer).
        const contourSource = smoothScalarGrid(contourDensityGrid, width, height, 5);
        drawKdeContours(context, contourSource, width, height, {
            step: Math.max(3, Math.round(4)),
            levels: [0.10, 0.18, 0.28, 0.40, 0.54, 0.68, 0.82],
            lineWidth: 1.05,
            haloLineWidth: 0,
        });

        return createSmoothHeatmapImageOverlay(canvas.toDataURL('image/png'), bounds, {
            pane: 'gis-heat-pane',
            opacity: 1,
            interactive: false,
        });
    }
```

- [ ] **Step 2: Point `buildAccessibilityDistributionRasterLayer` at the new builder**

In `buildAccessibilityDistributionRasterLayer` (~line 4330), replace the `createAccessibilityPointHeatmapLayer(points, { … })` call with the static builder (the surrounding `points`/`bounds`/`radiusMeters` setup at ~lines 4319–4328 is unchanged):

```javascript
        const layer = createAccessibilityHeatmapOverlay(points, bounds, {
            radiusMeters,
            clipBoundary: options.clipBoundary ?? primaryBoundaryGeoJson(),
        });
```

- [ ] **Step 3: Build and verify**

Run: `npm run build`
Then hard-refresh `/reports/gis`, switch to **Senior Distribution and Accessibility Heatmap**, and confirm:
- Dragging the map does **not** make the heatmap slide relative to the basemap — the heat stays pinned to its geography.
- The heat stays clipped to Pagsanjan and the KDE contour lines still render.
- No console errors about a missing layer/canvas.

> **Data caveat:** `senior_accessibility_metrics` is currently empty (0 rows) in this DB, so the accessibility surface may render sparse or empty. The drift behaviour is still observable from whatever points render; if the surface is empty, note it and confirm drift on Risk/Cluster modes is unaffected. To populate scores, optionally run `php artisan gis:score-proximity` (see the gis-module skill) — not required for this task.

- [ ] **Step 4: Commit**

```bash
git add resources/views/reports/gis.blade.php
git commit -m "fix(gis): render accessibility heatmap as a static overlay to stop drift"
```

---

### Task 4: Risk-mode hide/unhide control for senior points

**Files:**
- Modify: `resources/views/reports/gis.blade.php` (control markup after ~line 171; ID constants ~239–240; helper near ~899; `syncAccessibilityPointDisplay` sibling ~919–925; risk halo add ~4767; `renderDataLayers` sync call ~5178; change-event ID list ~5481)

- [ ] **Step 1: Add the Risk Point Display control block**

Immediately after the existing `#gis-accessibility-point-display` block (after its closing `</div>` at ~line 171), add a parallel hidden block:

```html
            <div id="gis-risk-point-display" class="border border-paper-rule dark:border-[#2b3530] rounded-lg px-3 py-2" style="display: none;">
                <div class="eyebrow mb-2">Risk Point Display</div>
                <label class="inline-flex items-center gap-2 text-[12px] text-ink-600 dark:text-[#b0b5b2]">
                    <input id="gis-show-risk-senior-points" type="checkbox" class="rounded border-paper-rule text-forest-700 focus:ring-forest-700" checked>
                    <span>Show senior points on risk heatmap</span>
                </label>
            </div>
```

- [ ] **Step 2: Add ID constants**

After `ACCESSIBILITY_POINT_DISPLAY_ID = 'gis-accessibility-point-display';` (~line 240), add:

```javascript
    const SHOW_RISK_SENIOR_POINTS_ID = 'gis-show-risk-senior-points';
    const RISK_POINT_DISPLAY_ID = 'gis-risk-point-display';
```

- [ ] **Step 3: Add the `shouldShowRiskSeniorPoints` helper**

After `shouldShowAccessibilitySeniorPoints` (~lines 899–901), add:

```javascript
    function shouldShowRiskSeniorPoints() {
        return document.getElementById(SHOW_RISK_SENIOR_POINTS_ID)?.checked !== false;
    }
```

- [ ] **Step 4: Add the `syncRiskPointDisplay` function**

After `syncAccessibilityPointDisplay` (~lines 919–925), add a parallel function:

```javascript
    function syncRiskPointDisplay() {
        const control = document.getElementById(RISK_POINT_DISPLAY_ID);
        if (!control) return;

        const mode = document.getElementById(MODE_ID)?.value ?? 'markers';
        control.style.display = mode === 'risk-indicator-heatmap' ? '' : 'none';
    }
```

- [ ] **Step 5: Gate the risk halo layer on the toggle**

In `renderRiskHeatmap` (~line 4767), wrap the halo add in the toggle check:

```javascript
        if (shouldShowRiskSeniorPoints()) {
            ensureLayerRegistry(map).seniors.addLayer(buildRiskIdentityHaloLayer(map, features));
        }
```

- [ ] **Step 6: Sync the control on every render**

In `renderDataLayers`, next to the existing `syncAccessibilityPointDisplay();` call (~line 5178), add:

```javascript
        syncAccessibilityPointDisplay();
        syncRiskPointDisplay();
```

- [ ] **Step 7: Make the toggle trigger a re-render**

In the `change` listener ID list (~line 5481), add `SHOW_RISK_SENIOR_POINTS_ID` so toggling it re-renders:

```javascript
        if ([MODE_ID, BARANGAY_FILTER_ID, RISK_FILTER_ID, CLUSTER_FILTER_ID, CLUSTER_POINTS_TOGGLE_ID, SHOW_HEATMAP_SENIOR_POINTS_ID, SHOW_RISK_SENIOR_POINTS_ID, SHOW_SENIOR_POINTS_TOGGLE_ID, SHOW_BARANGAY_DENSITY_TOGGLE_ID].includes(event.target?.id)) {
```

- [ ] **Step 8: Build and verify**

Run: `npm run build`
Then hard-refresh `/reports/gis` and confirm:
- The **Risk Point Display** block appears only in Risk Indicator Distribution mode (hidden in markers/cluster/accessibility modes).
- Unchecking it removes the senior dots while the KDE risk surface stays; re-checking restores them. Default is checked.

- [ ] **Step 9: Commit**

```bash
git add resources/views/reports/gis.blade.php
git commit -m "feat(gis): add hide/unhide senior points control to risk heatmap mode"
```

---

### Task 5: Drop the accessibility line from risk-mode popups

**Files:**
- Modify: `resources/views/reports/gis.blade.php` (`popupHtml` ~3634–3685)

**Why:** In Risk mode the popup should show only risk-relevant info. `popupHtml` always renders an "Accessibility Status" line. Popups rebind on every re-render, so reading the live mode inside `popupHtml` is correct and avoids threading a flag through every `attachSeniorPopup` caller. "Nearby senior services" stays (it's location info, not accessibility analysis).

- [ ] **Step 1: Compute a mode-aware accessibility block in `popupHtml`**

Near the top of `popupHtml`, after the `accessibility` constant is built (~line 3646), add a block that becomes empty in risk mode:

```javascript
        const popupMode = document.getElementById(MODE_ID)?.value ?? 'markers';
        const accessibilityRow = popupMode === 'risk-indicator-heatmap'
            ? ''
            : `<div><strong>Accessibility Status:</strong> ${accessibility}</div>`;
```

- [ ] **Step 2: Use the block in both popup branches**

In the generalized-point branch, replace the hard-coded line (~line 3661):

```javascript
                    <div><strong>Health Group:</strong> ${healthGroup}</div>
                    ${accessibilityRow}
```

In the standard branch, replace the hard-coded line (~line 3678):

```javascript
                <div><strong>Health Group:</strong> ${healthGroup}</div>
                ${accessibilityRow}
```

(Both branches keep the `Nearby senior services` block immediately below, unchanged.)

- [ ] **Step 3: Build and verify**

Run: `npm run build`
Then hard-refresh `/reports/gis` and confirm:
- In **Risk Indicator Distribution** mode, opening a senior popup shows **no** "Accessibility Status" line but still shows "Nearby senior services".
- In every other mode (markers, accessibility heatmap, cluster), the "Accessibility Status" line is still present.

- [ ] **Step 4: Commit**

```bash
git add resources/views/reports/gis.blade.php
git commit -m "fix(gis): hide accessibility status in risk-mode popups, keep nearby services"
```

---

### Task 6: Full-protocol verification and spec status update

**Files:**
- Modify: `docs/superpowers/specs/2026-06-04-gis-map-ux-correctness-design.md` (status line)

- [ ] **Step 1: Run the spec's full verification protocol**

Rebuild (`npm run build`), hard-refresh, and for each of the 4 modes (markers, accessibility heatmap, risk, cluster): switch in, pan/drag hard, zoom, and toggle each filter. Confirm all spec acceptance criteria:
- No "page unresponsive" dialog entering Risk/Cluster or changing filters (already-fixed; confirm no regression).
- No heatmap drift in any mode while dragging.
- Cannot pan outside Pagsanjan; no repeated/off-area tiles.
- Full cluster names in dropdown, legend, and popups; cluster filter still narrows correctly.
- Risk popups: no "Accessibility Status", still show "Nearby senior services"; Risk Point Display toggle hides/shows points.
- Console free of cluster-value warning spam.

- [ ] **Step 2: Run the GIS smoke check**

Run: `.\.claude\skills\run-osca-system\smoke.ps1 -Password "Admin@OSCA2026!"`
Expected: `ALL PASS 14/14` (confirms `/reports/gis` and `/api/gis/seniors` still serve 200).

- [ ] **Step 3: Mark the spec implemented**

In the design spec, change the status line:

```markdown
**Status:** Implemented (2026-06-04)
```

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/specs/2026-06-04-gis-map-ux-correctness-design.md
git commit -m "docs(gis): mark GIS map UX & correctness spec implemented"
```

---

## Self-review notes

- **Spec coverage:** Issue #1 → Task 1; issue #2 → Task 2; issue #3 → Task 3; issue #4 (points toggle) → Task 4, (risk popup) → Task 5, (freeze + legend) → already done, documented in Scope note. Full-protocol verification → Task 6.
- **Type consistency:** `setSelectOptions` accepts both strings and `{value,label}` (Task 1 Step 3); barangay/risk callers keep passing strings. New IDs `SHOW_RISK_SENIOR_POINTS_ID` / `RISK_POINT_DISPLAY_ID` and helpers `shouldShowRiskSeniorPoints` / `syncRiskPointDisplay` mirror the accessibility equivalents exactly. `createAccessibilityHeatmapOverlay` replaces `createAccessibilityPointHeatmapLayer`; the only caller (`buildAccessibilityDistributionRasterLayer`) is updated in the same task — no dangling references.
- **Helper availability:** `clusterDisplayName`, `clusterNumber`, `featureClusterNumber`, `rasterSizeForBounds`, `latLngToRasterPoint`, `getRasterBoundaryMask`, `gradientStopsFromStops`, `colorForGradientValue`, `clampUnit`, `smoothScalarGrid`, `drawKdeContours`, `createSmoothHeatmapImageOverlay`, `primaryBoundaryGeoJson`, `mapNavigationBounds` all already exist in the file and are reused, not redefined.
