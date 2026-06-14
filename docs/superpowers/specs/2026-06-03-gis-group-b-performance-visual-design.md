# GIS Group B — Performance & Visual Quality Design Spec

**Date:** 2026-06-03
**File scope:** `resources/views/reports/gis.blade.php` (all changes)
**Issues addressed:** #5 lag during pan/zoom, #6 scattered heatmap colors

---

## Background

Two rendering problems remain after Group A:

**Issue 5 — Lag:** The cluster heatmap has a live canvas layer (`createClusterFlowHeatmapLayer`) that redraws on every `moveend` AND `zoomend`. Each redraw clears the canvas and redraws radial gradients for all 275 senior points. This blocks the main thread on every pan gesture, causing visible jank.

The risk indicator heatmap is a static `L.imageOverlay` (rendered once, never redraws on pan/zoom). Its perceived lag is the initial render time (~1–2 sec for the multi-kernel raster pipeline), which is acceptable and already has a "Rendering..." status indicator.

**Issue 6 — Scattered colors:** Both the cluster and risk heatmaps use a winner-takes-all pixel coloring: each pixel gets the color of the dominant group/risk level. With 275 seniors spread across 8 barangays, individual senior blobs don't overlap enough to form continuous regions. The result looks like isolated color islands separated by transparent gaps (the base map shows through).

---

## Section 1 — Cluster Canvas Pan Performance

### Change

**File:** `resources/views/reports/gis.blade.php` — inside `createClusterFlowHeatmapLayer`

Split the canvas layer's event bindings so that `moveend` (pan) only repositions the canvas, while `zoomend` and `resize` trigger a full reset and redraw.

### Current binding (in `onAdd`)
```js
map.on('moveend zoomend resize', this._reset, this);
```

### New bindings

**`onAdd`:**
```js
map.on('zoomend resize', this._reset, this);
map.on('moveend', this._reposition, this);
```

**`onRemove`:**
```js
map.off('zoomend resize', this._reset, this);
map.off('moveend', this._reposition, this);
```

**New `_reposition` method** (added alongside `_reset`):
```js
_reposition() {
    if (!this._canvas) return;
    const topLeft = this._map.containerPointToLayerPoint([0, 0]);
    window.L.DomUtil.setPosition(this._canvas, topLeft);
},
```

### Behavior after change

| Event | Before | After |
|---|---|---|
| `moveend` (pan) | Full redraw (~N×275 gradients) | CSS reposition only (<1ms) |
| `zoomend` | Full redraw | Full redraw (unchanged) |
| `resize` | Full redraw | Full redraw (unchanged) |

**Accepted trade-off:** During a pan gesture, the heatmap blobs are frozen relative to the screen (they don't scroll with the map tiles). They snap to correct lat/lng positions when the pan ends (`moveend` fires). This is the same freeze-on-pan pattern already applied to the contour cache in the same layer.

---

## Section 2 — Visual Quality: Smoother Heatmap Output

### Change

**File:** `resources/views/reports/gis.blade.php` — inside `createClusterDistributionRasterLayer`

Two sub-changes applied in sequence:

#### 2a. Increase smoothing ratios

`createClusterDistributionRasterLayer` computes three pixel-radius values for Gaussian blur before compositing:

```js
const smoothingPixels = Math.max(
    options.smoothingPixelMin ?? 14,
    Math.min(options.smoothingPixelMax ?? 36, radius * (options.smoothingPixelRatio ?? 0.34))
);
const peakSmoothingPixels = Math.max(
    options.peakSmoothingPixelMin ?? 8,
    Math.min(options.peakSmoothingPixelMax ?? 22, peakRadius * (options.peakSmoothingPixelRatio ?? 0.24))
);
const pointCoreSmoothingPixels = Math.max(
    options.pointCoreSmoothingPixelMin ?? 5,
    Math.min(options.pointCoreSmoothingPixelMax ?? 14, pointCoreRadius * (options.pointCoreSmoothingPixelRatio ?? 0.16))
);
```

Change the three default ratios:

| Parameter | Before | After |
|---|---|---|
| `smoothingPixelRatio` | `0.34` | `0.52` |
| `peakSmoothingPixelRatio` | `0.24` | `0.38` |
| `pointCoreSmoothingPixelRatio` | `0.16` | `0.26` |

Each individual senior's blob becomes ~50% softer before compositing. Adjacent seniors' blobs now merge into connected regions rather than leaving transparent gaps between them.

#### 2b. Final output blur

After `drawKdeContours` draws the contour lines onto `outputCanvas`, and immediately before returning, composite the output canvas through a `blur(5px)` filter:

```js
// After drawKdeContours call, replace the existing return with:
const blurredCanvas = document.createElement('canvas');
blurredCanvas.width = width;
blurredCanvas.height = height;
const blurCtx = blurredCanvas.getContext('2d');
blurCtx.filter = 'blur(5px)';
blurCtx.drawImage(outputCanvas, 0, 0);

return createSmoothHeatmapImageOverlay(blurredCanvas.toDataURL('image/png'), bounds, {
    pane: 'gis-heat-pane',
    opacity: 1,
    interactive: false,
});
```

The existing `return createSmoothHeatmapImageOverlay(outputCanvas.toDataURL('image/png'), bounds, {...})` at line ~2593 is replaced by the above — the only change is swapping `outputCanvas` for `blurredCanvas`.

The `5px` blur rounds hard color boundaries at group edges without destroying the visible shape of the data. Contour lines are drawn before the blur and get softened too — this is intentional, making them look integrated with the surface rather than sharp overlays.

**Scope:** `createClusterDistributionRasterLayer` is shared by both `buildClusterDistributionHeatmapLayer` (cluster heatmap) and `buildRiskDistributionRasterLayer` (risk indicator). Both modes benefit from both sub-changes.

---

## Change Summary

| # | File | Location | What changes |
|---|------|----------|-------------|
| 1 | gis.blade.php | `createClusterFlowHeatmapLayer.onAdd` | Bind `moveend` → `_reposition`, `zoomend resize` → `_reset` |
| 2 | gis.blade.php | `createClusterFlowHeatmapLayer.onRemove` | Unbind matching split handlers |
| 3 | gis.blade.php | `createClusterFlowHeatmapLayer` | Add `_reposition()` method |
| 4 | gis.blade.php | `createClusterDistributionRasterLayer` | Raise three smoothing ratio defaults |
| 5 | gis.blade.php | `createClusterDistributionRasterLayer` | Add final blur canvas before `toDataURL` |

All changes are in one file. No PHP changes, no new packages.
