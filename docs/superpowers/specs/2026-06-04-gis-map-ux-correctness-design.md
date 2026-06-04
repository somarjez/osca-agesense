# GIS Map UX & Correctness Fixes (User-Reported) — Design

**Date:** 2026-06-04
**Scope file:** `osca-system/resources/views/reports/gis.blade.php` (inline Leaflet map JS, ~5,500 lines)
**Status:** Approved for planning
**Related:** Builds on `2026-06-04-gis-map-performance-design.md` — the freeze and drift fixes here are that spec's decisive items (boundary-mask precompute + static overlays). The deeper memoization / raster-cache / full audit from the performance plan is deferred unless lag persists after these fixes.

---

## Problem

Four issues reported against the GIS Analytics map:

1. **Cluster groups show as bare codes.** The legend shows `C1`–`C4`, the filter dropdown shows `Group 1`–`Group 4`, and popups show the raw cluster value. Staff want the descriptive group names.
2. **No usable map boundary.** Panning/dragging wanders far outside Pagsanjan ("I'm being lost in the map"), and the basemap repeats so off-area tiles appear.
3. **Heatmap drifts on drag.** When dragging, the heatmap slides to other parts of the map relative to the basemap.
4. **Risk Indicator Distribution mode.** The heatmap visualization needs fixing (page freeze), staff want a hide/unhide control for the senior points, and the mode should show **only risk** — not also analyze accessibility.

## Root causes (confirmed in code)

1. **Cluster names** — Legend uses `ramp.label` = `'C1'..'C4'` (`CLUSTER_HEATMAP_RAMPS`, ~line 305–344). The filter dropdown values come from `uniqueSortedClusterValues` → `'Group N'` (~line 833–858), rendered as-is by `setSelectOptions` (~line 860). Popups render the raw `p.cluster_label` (~line 3643, 3660, 3677). No descriptive names exist anywhere.

2. **Getting lost / off-area tiles** — `MUNICIPAL_NAVIGATION_PADDING_RATIO = 1.25` (line 264) sets `maxBounds` to 125% of the boundary size beyond the municipality, so panning roams far outside Pagsanjan. `createTileLayer` (line 5004) sets no `noWrap` and no `bounds`, so the basemap repeats endlessly. `maxBoundsViscosity = 1.0` and the outside-boundary dim mask already exist (lines 5105, 5024–5078).

3. **Heatmap drift** — The **Accessibility** heatmap (`createAccessibilityPointHeatmapLayer`, line 4169) is a viewport `<canvas>` positioned via a `moveend` "reposition" hack (line 4210) that visibly slides relative to the basemap during a drag. The **Risk** and **Cluster** heatmaps are static `L.imageOverlay`s (`createSmoothHeatmapImageOverlay`, line 2774) anchored to bounds and do **not** drift.

4. **Risk mode freeze + accessibility coupling** — `createClusterDistributionRasterLayer` (used by Risk and Cluster) calls `rasterPixelInsideBoundary` (lines 2482/2577/2670) for **every output pixel**, each running a full polygon ray-cast — hundreds of millions of synchronous ops per build, tripping the browser "page unresponsive" dialog. The shared `popupHtml` (line 3634) always renders an "Accessibility Status" line (line 3661, 3678), including in risk mode. Only the Accessibility mode has a points hide/unhide control (line 165); risk mode has none.

## Decisions (from brainstorming)

- **Cluster names:** show full descriptive titles in **all three** places — filter dropdown, map legend, and senior popups. The descriptive set:
  - `C1 · High Functioning / Well-Supported Seniors`
  - `C2 · Stable Ageing / Moderate Support Needs`
  - `C3 · Environmentally and Financially Vulnerable Seniors`
  - `C4 · Low Functioning / Multi-Domain Priority Seniors`
- **Map bounds:** **hard lock** to Pagsanjan — tight pan limit at the boundary, dim/mask outside, and stop tile repetition.
- **Risk mode "only risks":** remove the **Accessibility Status** line from popups in risk mode; **keep** "Nearby senior services" (location info, not accessibility analysis).
- **Approach:** surgical fixes mapped to the four issues; the freeze and drift fixes inherently pull in the performance plan's decisive items.

## Design

### 1. Cluster group names — legend, dropdown, popups

- Add a single source-of-truth lookup keyed by cluster number 1–4 holding the full `C# · …` titles. (May live alongside `CLUSTER_HEATMAP_RAMPS` as an added `title` field, or as a separate constant — implementer's choice, but one source.)
- New helper `clusterDisplayName(feature | number)` returns the full title via `clusterNumber(...)`; returns `"Unassigned"` when no cluster number resolves.
- **Dropdown:** keep the option **value** as the existing match key (`Group N`) so `featureMatchesSelectedCluster` (line 571) is unchanged; only the visible **text** becomes the full title. Generalize `setSelectOptions` to accept `{ value, label }` entries (or an optional label resolver) and use it for the cluster select only; barangay/risk selects keep current behavior.
- **Legend:** the cluster legend (line 731–735) renders each group chip with its color swatch followed by the full title. Titles are long and may wrap to multiple lines — acceptable.
- **Popups:** replace the raw `healthGroup` value (line 3643) with `clusterDisplayName(feature)` in both popup branches (generalized and standard).

### 2. Hard lock to Pagsanjan

- Reduce `MUNICIPAL_NAVIGATION_PADDING_RATIO` from `1.25` to `~0.15` — a small margin so the boundary is not flush to the viewport edge, but panning is fenced to the municipality. (`mapNavigationBounds` → `setMaxBounds`, lines 5090–5107.)
- Add `noWrap: true` and a `bounds` option to `createTileLayer` (line 5004) so the basemap does not repeat horizontally and does not request tiles outside the navigation area.
- Keep `maxBoundsViscosity = 1.0` and the existing outside-boundary dim mask. Net effect: panning is fenced to Pagsanjan and no stray off-area tiles appear.

### 3. Accessibility heatmap — stop the drift

- Convert `createAccessibilityPointHeatmapLayer` from a live viewport `<canvas>` to a static `L.imageOverlay` anchored to the boundary bounds, reusing the `createSmoothHeatmapImageOverlay(dataUrl, bounds, …)` path that Risk/Cluster already use: render the radial-gradient heat once into an offscreen canvas sized to the bounds, export to PNG, drop as an overlay.
- Remove the `map.on('zoomend resize', this._reset)` and `map.on('moveend', this._reposition)` handlers (and their `onRemove` counterparts). Leaflet transforms the overlay natively on pan/zoom — no drift, no per-pan repaint.

### 4. Risk Indicator Distribution mode

- **Fix freeze / visualization:** replace the per-pixel `rasterPixelInsideBoundary` ray-cast with a precomputed boundary clip-mask — paint the clip polygon once onto an offscreen mask canvas at the raster `(width, height)`, read back with `getImageData`, store a `Uint8`/`Uint8ClampedArray` mask (1 = inside). The per-pixel test becomes an O(1) array lookup. Cache the mask keyed by `(boundsSignature, width, height)` and reuse across builds. Cap raster resolution (`maxRasterSide` ~900, pixel-ratio cap ~1.5). Build time drops from seconds to <100ms; the "page unresponsive" dialog is eliminated. Benefits Cluster mode too (same engine).
- **Hide/unhide points:** add a "Risk Point Display" control block that mirrors the existing "Accessibility Point Display" block (line 165–171), shown only when the mode is `risk-indicator-heatmap` (parallel to `syncAccessibilityPointDisplay`, line 919). Its checkbox toggles the risk dots/halo layer; default = checked (points shown).
- **Remove accessibility from risk popups:** pass the active mode (or an `includeAccessibility` flag) into `popupHtml`; when mode is `risk-indicator-heatmap`, omit the "Accessibility Status" line. Keep "Nearby senior services." All other modes unchanged.
- Keep the existing risk-dot legend note (line 762) clarifying that dots = individual seniors and color = local risk density.

## Out of scope (YAGNI)

- The performance plan's deeper items — `validatedFeatureSet` memoization, per-filter raster caching, rebuild-only-on-change for boundary/facility layers, and the full per-mode correctness audit — are deferred. Revisit only if lag persists after these fixes.
- No Web Workers / OffscreenCanvas.
- No backend, API, route, or data-pipeline / geocoding changes.
- No new visualization modes; no visual restyle beyond the cluster-name labels.

## Files touched

- `osca-system/resources/views/reports/gis.blade.php` only.

## Verification

1. Rebuild frontend assets; hard-refresh.
2. For each of the 4 modes: switch in, pan and drag hard, zoom, toggle each filter.
3. Confirm:
   - No "page unresponsive" dialog when switching into Risk or Cluster mode or changing filters.
   - No heatmap drift in any mode while dragging.
   - Cannot pan outside Pagsanjan; no stray off-area / repeated basemap tiles.
   - Full cluster names appear in the filter dropdown, the legend, and senior popups; the cluster filter still narrows the rendered set correctly.
   - In Risk mode: popups show no "Accessibility Status" line but still show "Nearby senior services"; the Risk Point Display toggle hides/shows the senior points.
4. Spot-check console: free of cluster-value warning spam.

## Acceptance criteria

- Cluster groups display by full descriptive name in dropdown, legend, and popups, with filtering unchanged.
- Map panning is hard-fenced to Pagsanjan with no repeating/off-area basemap tiles.
- No heatmap drifts relative to the basemap during a drag.
- Risk Indicator Distribution renders without a "page unresponsive" freeze.
- Risk mode has a working hide/unhide control for senior points.
- Risk-mode popups show only risk-relevant info (no accessibility analysis) while retaining nearby services.
