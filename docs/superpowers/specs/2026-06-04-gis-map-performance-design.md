# GIS Map Performance + Correctness Fix — Design

**Date:** 2026-06-04
**Scope file:** `osca-system/resources/views/reports/gis.blade.php` (inline Leaflet map JS, ~5,300 lines)
**Status:** Approved for planning

---

## Problem

The GIS Analytics map is unusable in practice:

- The browser shows a **"page unresponsive"** dialog — the main thread is blocked for many seconds.
- Panning/zooming is laggy: *"every movement it loads."*
- The Risk Indicator heatmap shows **more senior dots than colored heatmap** area.
- Filter (barangay/risk/cluster) and color/legend correctness is suspected but unconfirmable because the page freezes before it can be observed.

User's acceptance goal: *"Assure that per visualization they work as intended and properly loads, only loads that are needed."*

## Root causes (confirmed in code)

1. **Page freeze (critical) — per-pixel point-in-polygon in raster build.**
   `createClusterDistributionRasterLayer` (used by Risk and Cluster heatmaps) builds an output canvas sized up to `maxRasterSide` (1280) × `devicePixelRatio` (≤2) per side — on a 2× display ≈ **2560 × 1440 ≈ 3.7M pixels**. The per-pixel loop calls `rasterPixelInsideBoundary(x, y)` for **every pixel**, and each call runs `pointInsideBoundary`, ray-casting over **every vertex** of the municipal boundary polygon. Total ≈ pixels × boundary-vertices = hundreds of millions of synchronous operations. This re-runs on **every mode switch and every filter change**. This is what trips the browser's unresponsive-page kill.

2. **Per-pan lag — canvas heatmaps repaint on every move.**
   `createAccessibilityPointHeatmapLayer` and the cluster-flow heatmap layer bind `map.on('moveend zoomend resize', this._reset)`, and `_reset` → `_redraw` loops every senior and repaints radial gradients across the full canvas on each pan. (The Risk/Cluster *raster* surfaces are already static `L.imageOverlay`s and are fine.)

3. **Per-switch heaviness — full rebuild on every change.**
   Every filter/mode change calls `refreshRenderedLayer` → `renderDataLayers`, which re-runs `validatedFeatureSet` (boundary point-in-polygon for all ~275 seniors) and rebuilds boundary + facility layers that did not change.

4. **Risk dots vs. heatmap mismatch.**
   `buildRiskIdentityHaloLayer(features)` draws a dot for **every** filtered senior, while `riskDistributionPoints` drops seniors with no usable risk weight and the raster only paints pixels above `minVisibleDensity` (0.10). Isolated or no-risk-data seniors therefore appear as dots over blank map.

## Decisions (from brainstorming)

- **Risk dots:** *Keep both layers, clarify with legend/labels* — points = individuals, color = local density. Do not force dots to always sit on color.
- **Performance approach:** *Static overlays + memoized switches* — the most thorough option, fully addressing "every movement loads."
- **Known bugs to also audit:** filters-not-applying and colors/legend-mismatch (user could not confirm under the freeze; audit once responsive).

## Design

### A. Eliminate the freeze — precompute the boundary clip mask *(decisive fix)*

The clip boundary is the municipal polygon, **constant** across filters and zoom. Replace per-pixel ray-casting with a one-time rasterized mask:

- Paint the boundary polygon onto an offscreen mask canvas at the raster's `(width, height)`, read it back with `getImageData`, and store a `Uint8Array`/`Uint8ClampedArray` mask (1 = inside).
- Cache the mask keyed by `(boundsSignature, width, height)`; reuse across builds since bounds are stable.
- The per-pixel test becomes an O(1) array lookup instead of a polygon ray-cast.
- Cap raster resolution: `maxRasterSide` 1280 → ~900, `pixelRatioCap` 2 → 1.5, so the canvas stays modest while still visually smooth.

Net effect: raster build drops from multiple seconds to well under ~100ms; no main-thread freeze.

### B. Stop per-pan repaints — static image overlays

Convert the Accessibility and cluster-flow canvas layers to the same static `L.imageOverlay` pattern Risk/Cluster raster already use: render once to a PNG, drop it in as an overlay. Unbind their `moveend`/`zoomend`/`resize` → `_reset` handlers. Pan translates the image (free); zoom scales it. No per-pan main-thread paint.

### C. Only load what's needed — memoize per-switch work

- Run `validatedFeatureSet` (the all-seniors boundary validation) **once** after data load; cache the validated feature set and reuse it. Filter changes re-filter the cached set in memory (cheap) instead of re-validating geometry.
- Rebuild boundary + facility layers only when their inputs change (data load, or selected-barangay highlight) — not on every mode/filter change.
- Memoize built heatmap rasters keyed by a filter signature `(mode, barangay, risk, cluster, toggles)`; re-selecting a prior state reuses the cached overlay.

### D. Risk dots — keep both, clarify *(user choice)*

- Keep raster + halo dots.
- Render halo dots through the **canvas renderer** (not SVG) for pan performance.
- Update the Risk legend/status text to state: *"Dots = individual seniors; color = local risk density. A lone senior may show a dot with little surrounding color."*

### E. Per-mode correctness audit *(now observable once responsive)*

With the freeze gone, verify in each of the 4 modes (Senior Population Overview, Risk Indicator, Cluster/Health Groups, Accessibility):

- Barangay / risk / cluster filters actually narrow the rendered set.
- Status text and KPI cards report correct counts.
- Heatmap colors match the legend.

Fix any mismatch found during the audit.

## Out of scope (YAGNI)

- No Web Workers / OffscreenCanvas threading (mask precompute makes the sync build fast enough).
- No backend, API, route, or data-pipeline / geocoding changes.
- No new visualization modes.
- No redesign of the map's visual styling beyond the Risk legend wording.

## Files touched

- `osca-system/resources/views/reports/gis.blade.php` only. No PHP/route changes.

## Verification

1. Rebuild frontend assets, hard-refresh.
2. For each of the 4 modes: switch into it, pan and zoom, toggle each filter.
3. Confirm: no "page unresponsive" dialog; pan/zoom is smooth; counts and colors are correct; console is free of cluster-value warning spam.
4. Spot-check Risk mode: dots and heatmap coexist with the clarified legend.

## Acceptance criteria

- No browser "page unresponsive" dialog when switching modes or changing filters.
- Panning and zooming do not trigger a full heatmap repaint (verified: no main-thread long task on `moveend`).
- Switching modes/filters does not re-run all-seniors boundary validation more than once per data load.
- Each visualization renders correctly with working filters and legend-matching colors.
- Risk mode shows dots + heatmap with the clarified legend wording.
