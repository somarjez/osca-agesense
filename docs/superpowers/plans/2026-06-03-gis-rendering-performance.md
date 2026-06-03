# GIS Rendering Performance — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate main-thread rendering freezes in the GIS Analytics page by switching markers to canvas-rendered CircleMarkers, adding an async yield before layer rebuilds, and debouncing heatmap zoom repaints.

**Architecture:** Three independent edits to `resources/views/reports/gis.blade.php` only. No new files, no PHP changes. Each task is self-contained and can be verified with `php -l` + `php artisan test` + manual map check.

**Tech Stack:** Leaflet.js (`L.circleMarker`, `L.canvas()`), vanilla JS, Blade

---

## File Map

| File | Changes |
|---|---|
| `resources/views/reports/gis.blade.php` | Add canvas renderer; replace `L.marker` with `L.circleMarker`; remove `createMarkerIcon()`; async yield in `refreshRenderedLayer`; debounce `zoomend` handler |

---

## Task 1: CircleMarker + Canvas renderer for markers mode

**Files:**
- Modify: `resources/views/reports/gis.blade.php` (3 spots)

### Context

In markers mode, `pointToLayer` at line 4894 creates one `L.marker` per senior using `createMarkerIcon()` — an SVG DivIcon. 400 seniors = 400 DOM nodes created synchronously = 300–600 ms freeze.

`L.circleMarker` with a shared `L.canvas()` renderer draws all circles in one GPU-accelerated pass. No DOM nodes per marker.

The `debounce` function is already in the file at line 325. `colorWithAlpha(hex, alpha)` is already defined at line 1063. `coordinateKind(feature)` is at line 831. `barangayColor(name)` is at line 1039. `attachSeniorPopup(layer, feature)` is at line 3195.

**Important:** `createFacilityIcon()` at line 3147 is a different function that must NOT be removed. Only `createMarkerIcon()` (lines 3132–3145) is deleted.

**Map lifecycle caveat:** The map is destroyed and recreated in `renderMap()` (lines 5076–5087) on every Livewire navigation (line 5150). A module-level renderer singleton would go stale (it would point at the old destroyed map's pane) after navigating away and back. To avoid this, the renderer is cached **on the map instance** (`map._gisCanvasRenderer`), not in a module-level variable — each map gets its own renderer, and it is garbage-collected with the old map.

- [ ] **Step 1: Add the `getCanvasRenderer(map)` helper after `latestRouteDistanceUrl` (line 338)**

Find:

```js
    let latestRouteDistanceUrl = null;

    function riskColor(level) {
```

Replace with:

```js
    let latestRouteDistanceUrl = null;

    function getCanvasRenderer(map) {
        if (!map._gisCanvasRenderer) {
            map._gisCanvasRenderer = window.L.canvas({ padding: 0.5, pane: 'gis-senior-pane' });
        }
        return map._gisCanvasRenderer;
    }

    function riskColor(level) {
```

- [ ] **Step 2: Replace `pointToLayer` in markers mode (lines 4893–4910)**

Find:

```js
        if (mode === 'markers') {
            const markerLayer = window.L.geoJSON(featureCollection, {
                pointToLayer(feature, latlng) {
                    const kind = coordinateKind(feature);
                    const color = barangayColor(feature.properties?.barangay);
                    const marker = window.L.marker(latlng, {
                        icon: createMarkerIcon(color, kind),
                        gisRiskLevel: feature.properties?.risk_level,
                        gisBarangay: feature.properties?.barangay,
                        gisCoordinateKind: kind,
                        pane: 'gis-senior-pane',
                    });

                    attachSeniorPopup(marker, feature);

                    return marker;
                },
            });
```

Replace with:

```js
        if (mode === 'markers') {
            const markerLayer = window.L.geoJSON(featureCollection, {
                pointToLayer(feature, latlng) {
                    const kind = coordinateKind(feature);
                    const color = barangayColor(feature.properties?.barangay);
                    const isFallback = kind === 'fallback';
                    const marker = window.L.circleMarker(latlng, {
                        renderer: getCanvasRenderer(map),
                        radius: isFallback ? 5 : 7,
                        fillColor: isFallback ? colorWithAlpha(color, 0.5) : color,
                        fillOpacity: isFallback ? 0.6 : 0.9,
                        color: '#ffffff',
                        weight: isFallback ? 1 : 2,
                        gisRiskLevel: feature.properties?.risk_level,
                        gisBarangay: feature.properties?.barangay,
                        gisCoordinateKind: kind,
                    });

                    attachSeniorPopup(marker, feature);

                    return marker;
                },
            });
```

- [ ] **Step 3: Remove `createMarkerIcon()` function (lines 3132–3145)**

Find and delete this entire function:

```js
    function createMarkerIcon(color, kind = 'verified') {
        const isFallback = kind === 'fallback';
        const background = isFallback ? colorWithAlpha(color, 0.16) : color;
        const border = isFallback ? `2px dashed ${color}` : '2px solid #ffffff';
        const size = isFallback ? 13 : 14;

        return window.L.divIcon({
            className: 'gis-marker-icon',
            html: `<span style="display:block;width:${size}px;height:${size}px;border-radius:9999px;background:${background};border:${border};box-shadow:0 4px 10px rgba(15,23,42,0.18);"></span>`,
            iconSize: [size, size],
            iconAnchor: [size / 2, size / 2],
            popupAnchor: [0, -8],
        });
    }
```

Leave `createFacilityIcon()` immediately below it — do NOT delete that function.

- [ ] **Step 4: Verify PHP syntax**

```powershell
php -l resources/views/reports/gis.blade.php
```

Expected: `No syntax errors detected`

- [ ] **Step 5: Run full test suite**

```powershell
php artisan test
```

Expected: all tests pass.

- [ ] **Step 6: Manual check**

```powershell
php artisan serve
```

Open http://127.0.0.1:8000/reports/gis. Verify:
- Senior Distribution Points mode renders colored circles (not pin icons) immediately — no freeze
- Circles show barangay color, with smaller/lighter circles for approximate coordinates
- Clicking a circle opens the popup correctly
- Cluster groups still appear at low zoom levels with count badges
- Switching to Accessibility Heatmap still works

- [ ] **Step 7: Commit**

```powershell
git add resources/views/reports/gis.blade.php
git commit -m "perf(gis): replace DivIcon markers with canvas CircleMarker — ~10x faster initial render"
```

---

## Task 2: Async yield in `refreshRenderedLayer`

**Files:**
- Modify: `resources/views/reports/gis.blade.php:4981-4987`

### Context

`refreshRenderedLayer()` calls `renderDataLayers()` synchronously. Every filter change and mode switch triggers a full layer rebuild with no opportunity for the browser to process input events in between. Adding `setStatus + setTimeout(0)` splits the freeze into two ticks — the status message renders in tick 1, the rebuild happens in tick 2.

`renderDataLayers()` already calls `clearDynamicLayers()` internally — no pre-clear is needed here.

`setStatus(message, tone)` is defined at line 520. `emptyFeatureCollection()` is already used in the existing code.

- [ ] **Step 1: Replace `refreshRenderedLayer()` (lines 4981–4987)**

Find:

```js
    function refreshRenderedLayer() {
        const el = document.getElementById(MAP_ID);
        const map = el?._leaflet_map_instance;
        if (!el || !map || !latestSeniorGeoJson) return;

        renderDataLayers(map, latestSeniorGeoJson, latestFacilityGeoJson ?? emptyFeatureCollection());
    }
```

Replace with:

```js
    function refreshRenderedLayer() {
        const el = document.getElementById(MAP_ID);
        const map = el?._leaflet_map_instance;
        if (!el || !map || !latestSeniorGeoJson) return;

        setStatus('Rendering...', 'neutral');
        setTimeout(() => {
            renderDataLayers(map, latestSeniorGeoJson, latestFacilityGeoJson ?? emptyFeatureCollection());
        }, 0);
    }
```

- [ ] **Step 2: Verify PHP syntax**

```powershell
php -l resources/views/reports/gis.blade.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Run full test suite**

```powershell
php artisan test
```

Expected: all tests pass.

- [ ] **Step 4: Manual check**

Open http://127.0.0.1:8000/reports/gis. Change the Barangay filter dropdown. Verify:
- The status bar briefly shows "Rendering..." before the map updates (visual feedback)
- The UI does not freeze — the dropdown responds immediately after selection
- The map updates correctly after the brief yield

- [ ] **Step 5: Commit**

```powershell
git add resources/views/reports/gis.blade.php
git commit -m "perf(gis): async yield in refreshRenderedLayer to unblock UI during layer rebuild"
```

---

## Task 3: Debounce `zoomend moveend` heatmap repaint

**Files:**
- Modify: `resources/views/reports/gis.blade.php:5106`

### Context

Line 5106 registers `map.on('zoomend moveend', () => refreshHeatmapLayersForZoom(map))`. Fast zoom fires this 3–5 times in under 500 ms, stacking canvas KDE repaints on the main thread. Wrapping in `debounce(..., 150)` means only the final zoom position triggers a repaint.

`debounce(fn, ms)` is already defined in the file at line 325. It was added in an earlier performance session.

- [ ] **Step 1: Wrap the zoomend handler (line 5106)**

Find:

```js
        map.on('zoomend moveend', () => refreshHeatmapLayersForZoom(map));
```

Replace with:

```js
        map.on('zoomend moveend', debounce(() => refreshHeatmapLayersForZoom(map), 150));
```

- [ ] **Step 2: Verify PHP syntax**

```powershell
php -l resources/views/reports/gis.blade.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Run full test suite**

```powershell
php artisan test
```

Expected: all tests pass.

- [ ] **Step 4: Manual check**

Open http://127.0.0.1:8000/reports/gis. Switch to "Cluster / Health Groups Heatmap". Zoom in and out rapidly 4–5 zoom levels. Verify:
- The heatmap does not visibly stutter or flash intermediate states during rapid zoom
- After stopping, the heatmap repaints correctly at the final zoom level within ~150 ms

- [ ] **Step 5: Commit**

```powershell
git add resources/views/reports/gis.blade.php
git commit -m "perf(gis): debounce zoomend/moveend heatmap repaint to 150ms"
```

---

## Task 4: Smoke test — full GIS page validation

No code changes.

- [ ] **Step 1: Run full test suite one final time**

```powershell
php artisan test
```

Expected: all tests pass.

- [ ] **Step 2: Open the GIS page and exercise all modes**

```powershell
php artisan serve
```

Navigate to http://127.0.0.1:8000/reports/gis. For each of the 5 visualization modes:

| Mode | What to verify |
|---|---|
| Senior Distribution Points | Circles render immediately; no freeze; barangay colors; popups work; clusters at low zoom |
| Accessibility Heatmap | Renders; switching from markers mode is responsive |
| Barangay Density View | Renders; no console errors |
| Risk Indicator Distribution | Renders; KDE overlay checkboxes work |
| Cluster / Health Groups Heatmap | Renders; zoom in/out — no stutter |

- [ ] **Step 3: Test filter responsiveness**

With 400+ seniors loaded in markers mode, change each filter dropdown in quick succession (barangay → risk → cluster). Verify the map updates after each without visible UI freeze.

- [ ] **Step 4: Run the smoke script**

```powershell
.\.claude\skills\run-osca-system\smoke.ps1 -Password "Admin@OSCA2026!"
```

Expected: ALL PASS 14/14.
