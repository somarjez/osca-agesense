# Design: GIS Client-Side Rendering Performance

**Date:** 2026-06-03
**Branch context:** main

## Problem

The GIS Analytics page feels sluggish across all interactions even after the API caching, filter debounce, and layer cleanup fixes deployed earlier in this session. Every map interaction triggers the same synchronous main-thread pattern:

1. `clearDynamicLayers()` tears down all Leaflet layer groups
2. `renderActiveView()` immediately rebuilds them — creating 400+ `L.marker` objects with custom SVG `DivIcon` instances, triggering MarkerCluster grouping, and adding to the DOM — all synchronously

400 seniors × SVG DivIcon creation ≈ 300–600 ms of uninterrupted main-thread time. This explains:
- Slow initial render after data loads
- Freeze when switching modes (clear + rebuild)
- Sluggish filter response (even debounced, the render itself blocks)
- Heatmap stutter on fast zoom (multiple repaints stack up)

## Decision

Three targeted changes to `resources/views/reports/gis.blade.php`. No new files, no architectural change.

---

## Change 1: CircleMarker + Canvas renderer for markers mode

**Root cause:** `createMarkerIcon()` (line 3132) generates an SVG string and wraps it in a `DivIcon`. Leaflet creates a real DOM element per marker. 400 DOM elements created synchronously = main-thread freeze.

**Fix:** Add a shared `L.canvas()` renderer at module level. In the `pointToLayer` callback (line 4894–4909), replace `L.marker` + `createMarkerIcon()` with `L.circleMarker` drawn on the canvas renderer.

### New canvas renderer helper (add near module-level variables, ~line 340)

The renderer is cached **on the map instance**, not in a module-level singleton. The map is destroyed and recreated in `renderMap()` on every Livewire navigation, so a module singleton would hold a stale reference to the old map's pane after navigating away and back. Caching on `map._gisCanvasRenderer` gives each map its own renderer that is garbage-collected with the old map.

```js
function getCanvasRenderer(map) {
    if (!map._gisCanvasRenderer) {
        // pane: 'gis-senior-pane' preserves z-index ordering (zIndex 620, above tiles, below facility markers)
        map._gisCanvasRenderer = window.L.canvas({ padding: 0.5, pane: 'gis-senior-pane' });
    }
    return map._gisCanvasRenderer;
}
```

### Replacement `pointToLayer` in markers mode (lines 4894–4909)

Replace:

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

With:

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

**Visual mapping preserved:**
- Barangay color → `fillColor` (same `barangayColor()` function)
- Coordinate kind → `radius: 7` (verified/GPS) vs `radius: 5` (approximate/fallback), `fillOpacity: 0.9` vs `0.6`
- White border → `color: '#fff', weight: 2`
- Popups → unchanged, `attachSeniorPopup()` works the same on CircleMarker

**`createMarkerIcon()` function (lines 3132–3145):** Remove entirely. It is only called in the markers mode `pointToLayer` (line 4899). No other callsite. `createFacilityIcon()` (line 3147) is separate and must NOT be removed.

**ClusterTone compatibility:** `clusterTone()` reads `marker.options.gisBarangay` (line 3166). CircleMarker passes `gisBarangay` in its options object — same access pattern. No change needed.

**`pane` option:** The `gis-senior-pane` pane is no longer needed in the CircleMarker options because the canvas renderer draws to a canvas element, not a pane DOM element. Remove `pane: 'gis-senior-pane'` from the circleMarker options. The pane setup code (line 3516–3518) and its uses in heatmap identity halo layers must remain — do not remove.

---

## Change 2: Async yield on layer rebuild

**Root cause:** `refreshRenderedLayer()` (line 4981) calls `renderDataLayers()` synchronously. Clearing old layers and building new ones happen in one uninterrupted block. The UI freezes for the full duration.

**Fix:** Add a `setStatus('Rendering...', 'neutral')` call before yielding to the browser, then build in the next tick.

Replace `refreshRenderedLayer()` (lines 4981–4987):

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

**Why this works:** `renderDataLayers` already calls `clearDynamicLayers` at line 4848 — no pre-clear is needed here. The `setStatus` call gives the browser one frame to display the "Rendering..." message before the render begins, breaking the synchronous block. The old layers stay visible during that frame (no blank flash), then are atomically replaced when the render completes.

---

## Change 3: Debounce heatmap zoom repaint

**Root cause:** Line 5106 registers `map.on('zoomend moveend', () => refreshHeatmapLayersForZoom(map))`. Fast zoom (3–4 levels in < 300ms) fires this 3–4 times consecutively, each triggering a full canvas KDE repaint.

**Fix:** Wrap in the `debounce` helper already defined in the file (added in the earlier performance session).

Replace line 5106:

```js
    map.on('zoomend moveend', () => refreshHeatmapLayersForZoom(map));
```

With:

```js
    map.on('zoomend moveend', debounce(() => refreshHeatmapLayersForZoom(map), 150));
```

150 ms matches the heatmap zoom radius recalculation window — any faster and intermediate zoom levels are skipped without visible artifact.

---

## What Does Not Change

- Facility markers (`createFacilityIcon()`) — remain as DivIcon squares
- Cluster icon rendering (`iconCreateFunction`) — remains as DivIcon, rendered once per cluster group not per senior
- All heatmap rendering, KDE engine, canvas layers — unchanged
- MarkerCluster grouping and spiderfy — unchanged
- Popup content, filter logic, legend — unchanged
- Identity halo layers (`buildRiskIdentityHaloLayer`, `buildClusterIdentityHaloLayer`) — already use CircleMarker, unchanged

## Files Changed

| File | Changes |
|---|---|
| `resources/views/reports/gis.blade.php` | Add canvas renderer + `getCanvasRenderer()`; replace `L.marker` with `L.circleMarker` in markers mode; remove `createMarkerIcon()`; async yield in `refreshRenderedLayer()`; debounce `zoomend` handler |
