# GIS Group B — Performance & Visual Quality Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate pan-lag on the cluster heatmap and make both cluster and risk heatmaps produce smooth, connected color regions instead of scattered isolated blobs.

**Architecture:** Two independent changes to `resources/views/reports/gis.blade.php`. Task 1 splits the canvas layer's event listeners so `moveend` only repositions the canvas (no redraw) while `zoomend`/`resize` do the full reset. Task 2 increases per-kernel smoothing ratios and adds a final blur pass to the shared raster compositor.

**Tech Stack:** Laravel 11 Blade, Leaflet.js, HTML Canvas API (2D context, `filter: blur()`), inline JavaScript.

---

## File Map

| File | What changes |
|---|---|
| `resources/views/reports/gis.blade.php` | Both tasks — canvas event split + smoothing/blur |

---

## Task 1: Split `createClusterFlowHeatmapLayer` pan vs zoom events

**Files:**
- Modify: `resources/views/reports/gis.blade.php:4163–4177` (onAdd, onRemove)
- Modify: `resources/views/reports/gis.blade.php:4190` (after `_reset`, insert `_reposition`)

**Background:** `createClusterFlowHeatmapLayer` is the live canvas layer overlaid on the cluster heatmap. It currently binds `map.on('moveend zoomend resize', this._reset, this)`. On every pan (`moveend`), `_reset` is called which: resizes the canvas, calls `_redraw()` which redraws radial gradients for all ~275 senior points. This blocks the main thread causing jank.

The fix: bind `moveend` to a new lightweight `_reposition` method that only updates the canvas CSS position (no redraw, no resize). Bind `zoomend resize` to the existing `_reset` as before.

- [ ] **Step 1: Confirm current onAdd and onRemove**

  ```bash
  sed -n '4163,4177p' resources/views/reports/gis.blade.php
  ```

  Expected:
  ```js
  onAdd(map) {
      this._map = map;
      this._canvas = window.L.DomUtil.create('canvas', 'leaflet-layer gis-cluster-flow-heat-canvas');
      this._canvas.style.pointerEvents = 'none';
      (map.getPane('gis-heat-pane') ?? map.getPanes().overlayPane).appendChild(this._canvas);
      map.on('moveend zoomend resize', this._reset, this);
      this._reset();
  },

  onRemove(map) {
      if (this._canvas?.parentNode) {
          this._canvas.parentNode.removeChild(this._canvas);
      }
      map.off('moveend zoomend resize', this._reset, this);
  },
  ```

- [ ] **Step 2: Update `onAdd` — split the event binding**

  In `resources/views/reports/gis.blade.php`, find the `onAdd` inside `createClusterFlowHeatmapLayer` (line 4163). Change:
  ```js
  map.on('moveend zoomend resize', this._reset, this);
  ```
  to:
  ```js
  map.on('zoomend resize', this._reset, this);
  map.on('moveend', this._reposition, this);
  ```

- [ ] **Step 3: Update `onRemove` — unbind matching split handlers**

  In the `onRemove` method (line 4172). Change:
  ```js
  map.off('moveend zoomend resize', this._reset, this);
  ```
  to:
  ```js
  map.off('zoomend resize', this._reset, this);
  map.off('moveend', this._reposition, this);
  ```

- [ ] **Step 4: Add `_reposition` method after `_reset`**

  Find `_reset()` (line 4179) and its closing `},` (line 4190). Insert `_reposition` immediately after the closing `},` of `_reset`:

  ```js
  _reposition() {
      if (!this._canvas) return;
      const topLeft = this._map.containerPointToLayerPoint([0, 0]);
      window.L.DomUtil.setPosition(this._canvas, topLeft);
  },
  ```

- [ ] **Step 5: Verify**

  ```bash
  grep -n "moveend\|zoomend\|_reposition\|_reset" resources/views/reports/gis.blade.php | grep -A2 -B2 "gis-cluster-flow"
  ```

  Or more directly:
  ```bash
  sed -n '4163,4200p' resources/views/reports/gis.blade.php
  ```

  Expected: `onAdd` binds `zoomend resize` → `_reset` AND `moveend` → `_reposition`. `onRemove` unbinds both. `_reposition` method exists between `_reset` and `_redraw`.

- [ ] **Step 6: Run PHP tests**

  ```bash
  php artisan test --filter=Gis
  ```

  Expected: all 6 pass.

- [ ] **Step 7: Commit**

  ```bash
  git add resources/views/reports/gis.blade.php
  git commit -m "perf(gis): skip canvas redraw on pan — reposition only, redraw on zoom/resize"
  ```

---

## Task 2: Increase smoothing ratios + add final output blur

**Files:**
- Modify: `resources/views/reports/gis.blade.php:2182,2186,2190` (smoothing ratio defaults)
- Modify: `resources/views/reports/gis.blade.php:2593–2597` (final return — swap canvas)

**Background:** `createClusterDistributionRasterLayer` builds the static raster image used by both the cluster heatmap and risk indicator. It renders each senior as a radial gradient kernel, then composites by dominant group (winner-takes-all). With 275 seniors across 8 barangays, individual blobs are too small and sparse to overlap — producing isolated color islands.

Two fixes:
- Increase the per-kernel Gaussian blur ratios so each senior's blob is ~50% wider before compositing (adjacent seniors merge into connected regions).
- Apply a final `blur(5px)` pass to the composited canvas before converting to the PNG that becomes the `L.imageOverlay`.

Both fixes apply to **both** cluster heatmap and risk indicator because they both go through `createClusterDistributionRasterLayer`.

- [ ] **Step 1: Confirm current smoothing ratio lines**

  ```bash
  sed -n '2180,2192p' resources/views/reports/gis.blade.php
  ```

  Expected (exact values):
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

- [ ] **Step 2: Raise the three smoothing ratio defaults**

  In `resources/views/reports/gis.blade.php`:

  Line 2182 — change `0.34` to `0.52`:
  ```js
  Math.min(options.smoothingPixelMax ?? 36, radius * (options.smoothingPixelRatio ?? 0.52))
  ```

  Line 2186 — change `0.24` to `0.38`:
  ```js
  Math.min(options.peakSmoothingPixelMax ?? 22, peakRadius * (options.peakSmoothingPixelRatio ?? 0.38))
  ```

  Line 2190 — change `0.16` to `0.26`:
  ```js
  Math.min(options.pointCoreSmoothingPixelMax ?? 14, pointCoreRadius * (options.pointCoreSmoothingPixelRatio ?? 0.26))
  ```

- [ ] **Step 3: Verify smoothing ratios**

  ```bash
  sed -n '2180,2192p' resources/views/reports/gis.blade.php
  ```

  Expected: values `0.52`, `0.38`, `0.26` in the three `??` defaults.

- [ ] **Step 4: Confirm the current return statement**

  ```bash
  sed -n '2591,2599p' resources/views/reports/gis.blade.php
  ```

  Expected:
  ```js
      });

      return createSmoothHeatmapImageOverlay(outputCanvas.toDataURL('image/png'), bounds, {
          pane: 'gis-heat-pane',
          opacity: 1,
          interactive: false,
      });
  }
  ```

- [ ] **Step 5: Replace the return with a blurred canvas**

  Replace the `return createSmoothHeatmapImageOverlay(outputCanvas.toDataURL...` block (lines 2593–2597) with:

  ```js
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

- [ ] **Step 6: Verify**

  ```bash
  grep -n "blurredCanvas\|blurCtx\|blur(5px)" resources/views/reports/gis.blade.php
  ```

  Expected: 4–5 results — declaration, width/height, getContext, filter, drawImage — all near line 2593.

  ```bash
  grep -n "outputCanvas.toDataURL" resources/views/reports/gis.blade.php
  ```

  Expected: no results (the old return is gone).

- [ ] **Step 7: Run PHP tests**

  ```bash
  php artisan test --filter=Gis
  ```

  Expected: all 6 pass.

- [ ] **Step 8: Commit**

  ```bash
  git add resources/views/reports/gis.blade.php
  git commit -m "feat(gis): smoother heatmap output — raise kernel smoothing ratios and add final blur pass"
  ```

---

## Visual Verification (after both tasks)

There is no unit test suite for the inline JS canvas rendering. After completing both tasks:

- [ ] Run the app (`php artisan serve` or equivalent) and open the GIS report page
- [ ] Select **Cluster / Health Groups Heatmap**
  - Pan the map rapidly — should feel smooth with no jank (canvas blobs freeze during pan, snap on release)
  - Health group color regions should look like connected blobs, not scattered islands
- [ ] Select **Risk Indicator Distribution**
  - Risk color regions should look smoother and more connected
  - Contour lines should be visible but softened
- [ ] Switch back to **Senior Population Overview** — confirm unaffected (no canvas layer in this mode)
