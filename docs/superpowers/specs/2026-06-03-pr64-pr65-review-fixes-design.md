# PR#64 & PR#65 Code-Review Fixes — Design Spec

**Date:** 2026-06-03
**File scope:** `resources/views/reports/gis.blade.php` (all changes)
**PHP scope:** none (PHP issues in PR#64 were false positives or below threshold)

---

## Background

Two GIS PRs were code-reviewed and produced a combined fix list:

- **PR#64** (merged, accessibility heatmap): one label mismatch below the auto-post threshold (score 75) but explicitly requested by the developer.
- **PR#65** (open, health cluster heatmap): three pre-merge blockers + four post-merge cleanups confirmed by verification agents.

All changes are in a single Blade/JS file. No new dependencies, no schema changes, no API surface changes.

---

## Section 1 — Quick Visual Fixes

### 1a. PR#64 — Frontend/backend label mismatch (`'Priority'` → `'Farthest'`)

**Location:** `accessibilityConcernFromDistance`, line 1027

**Change:**
```js
// before
let level = 'Priority';
// after
let level = 'Farthest';
```

**Why:** The backend `accessibilityConcernPayload` assigns `$level = 'Farthest'` as the default (worst) accessibility bucket. The frontend fallback function used `'Priority'`. Any senior whose backend accessibility data is absent gets their level computed client-side from distance — that level will now match what the backend would have produced, keeping popup labels and downstream filtering consistent.

---

### 1b. PR#65 — Missing stroke opacity on cluster distribution points

**Location:** `buildClusterDistributionPointLayer`, circleMarker options (~line 4833)

**Change:** Add `opacity: 0.82` to the circleMarker options object.

```js
return window.L.circleMarker(latlng, {
    renderer: getCanvasRenderer(map),
    pane: 'gis-senior-pane',
    radius: isFallback ? 5 : 7.5,
    color: '#ffffff',
    weight: isFallback ? 1 : 2,
    opacity: 0.82,               // ← add this
    fillColor: isFallback ? colorWithAlpha(color, 0.5) : color,
    fillOpacity: isFallback ? 0.58 : 0.9,
    interactive: true,
    // ...
});
```

**Why:** The replaced function `buildClusterIdentityHaloLayer` set `opacity: 0.82`. Omitting it causes Leaflet to default to `1.0`, making the white stroke ring around each cluster point fully opaque — visually heavier than intended, especially in dense regions.

---

### 1c. PR#65 — Unintended dark halo on flow heatmap contours

**Location:** `createClusterFlowHeatmapLayer._redraw`, `drawKdeContours` call (~line 4089)

**Change:** Add `haloLineWidth: 0` to the options object.

```js
drawKdeContours(context, contourSourceGrid, width, height, {
    step: Math.max(3, Math.round(4 * ratio)),
    levels: [0.10, 0.18, 0.28, 0.40, 0.54, 0.68, 0.82],
    lineWidth: 1.05 * ratio,
    haloLineWidth: 0,            // ← add this
});
```

**Why:** `drawKdeContours` was refactored in PR#65 to default `haloLineWidth` to `0.25`. Since the flow heatmap call omits this option, a dark `rgba(15,23,42,0.06)` outer ring is drawn around every contour line — an unintended side effect not mentioned in the PR description. Setting `haloLineWidth: 0` explicitly opts out.

---

## Section 2 — Performance Fix: Contour Caching

**Location:** `createClusterFlowHeatmapLayer` (inside `createClusterFlowHeatmapLayer._redraw` and `initialize`)

**Problem:** `_redraw` is called on every `moveend`, `zoomend`, and `resize`. The current implementation runs the full pipeline on each event:
1. Radial gradient draws for each senior point
2. Pixel-buffer readback (`getImageData`)
3. Boundary clip loop
4. `contourDensityGrid` population
5. `smoothScalarGrid` — 5 box-blur passes over the full canvas
6. `drawKdeContours` — 7 marching-square scans

Steps 5–6 are the expensive part and were absent from the replaced layer. On a 1920×1080 display with `devicePixelRatio=2` (~8.3 MP canvas), this adds substantial synchronous main-thread work per map interaction.

**Solution:** Cache contour output in an offscreen canvas. Invalidate the cache when zoom level or canvas dimensions change. On pure pan (`moveend` with no zoom change), composite the cached canvas instead of recomputing.

### Implementation

**In `initialize`:**
```js
initialize() {
    this._points = points;
    this._options = options;
    this._contourCache = null;   // ← add this
},
```

**In `_redraw`**, replace the current contour block (from `const contourDensityGrid` to the final `drawKdeContours` call) with:

```js
// Decide if contour needs rebuilding
const currentZoom = this._map.getZoom();
const needContour = !this._contourCache
    || this._contourCache.zoom !== currentZoom
    || this._contourCache.canvas.width !== width
    || this._contourCache.canvas.height !== height;

// Pixel loop: boundary clip always runs; contour grid only allocated when needed
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

// Rebuild offscreen contour canvas on zoom/resize
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

// Composite cached contour on top
if (this._contourCache) {
    context.drawImage(this._contourCache.canvas, 0, 0);
}
```

**Cache invalidation rules:**
- `this._contourCache === null` → first render, always builds
- `zoom !== currentZoom` → zoom level changed, rebuild
- `canvas.width/height !== width/height` → `resize` event fired, rebuild

**Accepted trade-off:** During a pan gesture, contour lines hold their position while the blob layer shifts underneath them. They snap to the correct position on `zoomend`. This is the standard Leaflet freeze-on-pan pattern and is visually acceptable for a secondary density overlay.

---

## Section 3 — Post-Merge Cleanups

All four changes are in `resources/views/reports/gis.blade.php`.

### 3a. Delete `buildClusterPointRampLayer` (~line 4282–4815)

The entire function (~533 lines). All three call sites were replaced by `buildClusterFlowHeatmapLayer` in PR#65. The function has zero callers. Removing it:
- Shrinks the file by ~533 lines
- Eliminates risk of accidental re-wiring
- Removes the dead `c1SouthVisibilityY` / `southernC1*` hardcoded cluster-1 logic

### 3b. Remove unused `opacity` variable in `drawKdeContours` (line 2122)

```js
// remove:
const opacity = 0.18 + levelFrac * 0.26;
```

Left behind when `strokeStyle` was refactored to use `options.opacityBase`/`opacityRange`. Never read after assignment.

### 3c. Remove redundant second shadow reset in `drawKdeContours` (lines 2132–2133)

```js
// remove these two lines (the second occurrence, after the halo block):
context.shadowColor = 'transparent';
context.shadowBlur = 0;
```

Shadow was already set to `transparent`/`0` three lines earlier. The `if (haloLineWidth > 0)` block between the two resets does not modify shadow state, making the second assignment a no-op.

### 3d. Extract shared cluster badge helper

Both `buildClusterDistributionPointLayer` and `buildAccessibilitySeniorPointLayer` inline identical `L.divIcon` HTML for the 34×34px circular cluster badge. Extract a named helper placed before both functions:

```js
function makeClusterDivIcon(tone, count) {
    return window.L.divIcon({
        html: `<div style="background:${tone};color:#fff;width:34px;height:34px;border-radius:9999px;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.95);box-shadow:0 8px 18px rgba(15,23,42,0.18);font-size:11px;font-weight:700;">${count}</div>`,
        className: 'gis-cluster-icon',
        iconSize: [34, 34],
    });
}
```

Each `iconCreateFunction` replaces its inline `L.divIcon(...)` with:
```js
return makeClusterDivIcon(tone, cluster.getChildCount());
```

---

## Change Summary

| # | File | Location | Type |
|---|------|----------|------|
| 1a | gis.blade.php | `accessibilityConcernFromDistance` line 1027 | 1-line fix |
| 1b | gis.blade.php | `buildClusterDistributionPointLayer` ~line 4833 | 1-line add |
| 1c | gis.blade.php | `createClusterFlowHeatmapLayer._redraw` ~line 4089 | 1-line add |
| 2 | gis.blade.php | `createClusterFlowHeatmapLayer` initialize + _redraw | ~20-line refactor |
| 3a | gis.blade.php | `buildClusterPointRampLayer` ~line 4282–4815 | delete ~533 lines |
| 3b | gis.blade.php | `drawKdeContours` line 2122 | delete 1 line |
| 3c | gis.blade.php | `drawKdeContours` lines 2132–2133 | delete 2 lines |
| 3d | gis.blade.php | new helper + 2 call sites | add ~10 lines, remove ~20 |

All changes are in one file. No migrations, no API changes, no new packages.
