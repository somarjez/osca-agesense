# QA Batch 4 — GIS Analytics

**Date:** 2026-06-04
**Status:** Approved design, pending implementation plan
**Source:** QA Testing punch list (June 4, 2026), Batch 4 of 6

## Context

Fourth of six module-scoped batches from the 2026-06-04 QA punch list. Covers the GIS
Analytics map (`reports/gis.blade.php`): the bulk-geocode confirmation, where
accessibility is computed, the Risk Distribution heatmap weighting, and the cluster
filter labels.

Batches 1-3 (PRs #74, #75, #76) are merged. Reports and Admin/Misc remain as Batches 5-6.

The GIS module has a dedicated project skill (`.claude/skills/gis-module/`) covering the
data pipeline, API endpoints, and gotchas — consult it during implementation.

## Affected code (current state)

`resources/views/reports/gis.blade.php` (~4800 lines, Leaflet map + inline JS):

- **Geocode form** (`:49-51`): `<form method="POST" action="{{ route('reports.gis.geocode') }}"
  onsubmit="return confirm('Run bulk barangay-level geocoding now? …');">` — a native
  browser `confirm()` dialog ("127.0.0.1:8000 says").
- **Visualization modes** (`:106-111`): `markers` (Senior Population Overview),
  `risk-indicator-heatmap` (Risk Indicator Distribution), `cluster-heatmap`
  (Cluster / Health Groups Heatmap), `senior-distribution-accessibility-heatmap`
  (Accessibility Heatmap).
- **Accessibility on click** (`:3582-3585`): every senior marker binds
  `layer.on('popupopen', () => updateRoadNetworkServices(layer, feature))`, which calls
  ORS/OSRM `roadRouteDistance(...)` to compute route distances — runs in ALL modes.
- **Risk heatmap weighting** (`:607-618`): `riskWeight(level)` returns HIGH=1.0,
  MODERATE=0.6, LOW=0.3. The KDE surface SUMS these per-point weights and normalizes by
  the max (`:1788` `intensity = weight / max`), so a dense cluster of LOW-risk seniors
  accumulates more total weight than a few HIGH-risk seniors and paints red — the surface
  reads as population density tinted by risk, not risk severity.
- **Cluster filter population** (`:854-870`, `uniqueSortedClusterValues`): builds options
  as `Group ${number}` for both value and display text. Full titles already exist in
  `CLUSTER_HEATMAP_RAMPS[n].title` (`:316-358`): "C1 · High Functioning / Well-Supported
  Seniors", "C2 · Stable Ageing / Moderate Support Needs", "C3 · Environmentally and
  Financially Vulnerable Seniors", "C4 · Low Functioning / Multi-Domain Priority Seniors".

Reusable: `<x-confirm-modal>` / `<x-modal>` components; `route('reports.gis.geocode')`.

## Requirements

### 1. Bulk geocode → in-app modal (`gis.blade.php:49-51`)

Replace the native `confirm()` with an in-app modal:

- The geocode trigger becomes a button that opens an `<x-confirm-modal>` (Alpine
  `x-data="{ open: false }"`), explaining bulk barangay-level geocoding runs now and will
  NOT overwrite verified manual/GPS coordinates.
- Confirming submits the existing POST form to `route('reports.gis.geocode')`.
- Remove the `onsubmit="return confirm(...)"` handler. No native browser dialog remains.

### 2. Accessibility computed only in Markers + Accessibility modes (`:3582-3585`)

Gate the ORS route-distance computation so it runs ONLY in:
- `markers` (Senior Population Overview), and
- `senior-distribution-accessibility-heatmap` (Accessibility Heatmap).

In `risk-indicator-heatmap` and `cluster-heatmap` modes:
- `updateRoadNetworkServices` (the `popupopen` route-distance fetch) does NOT run — no
  ORS/OSRM calls.
- The senior popup still shows identity / risk / cluster info, but **omits the
  road-network / accessibility section entirely** (user-confirmed: no "switch modes"
  hint).

Implementation: read the active visualization mode at popup-open time and early-return /
skip rendering the route-services section when the mode is not one of the two allowed.

### 3. Risk Distribution heatmap reflects risk severity, not density (`riskWeight :607`, KDE `:1788`)

The Risk Indicator Distribution surface must make high-risk areas clearly stand out and
must NOT paint dense low-risk areas red.

- **Widen tier separation** in `riskWeight` so LOW contributes far less relative to HIGH
  (e.g. LOW near the floor, HIGH at the ceiling), so HIGH-risk points dominate the
  surface.
- Ensure intensity reflects **risk severity**, not raw population density: a dense
  cluster of LOW-risk seniors reads green; even a few HIGH-risk seniors read red.
  (Achieved by widening the weight gap and/or adjusting how the summed-weight surface is
  normalized — exact approach decided in the plan.)
- This is visual tuning of a canvas KDE surface — verified by eye, not unit test.

### 4. Cluster filter shows real labels (`uniqueSortedClusterValues :854-870`)

The "Cluster / Health Group" dropdown must display the full cluster titles instead of
`Group 1…4` / `C1…C4`:

- Option **display text** = `CLUSTER_HEATMAP_RAMPS[number].title` (the full
  "C{n} · …" label).
- The underlying option **value** continues to work with the existing
  `featureMatchesSelectedCluster` filtering (keep the value as today's `Group ${number}`
  or map it consistently — the filter must still select correctly).
- Unassigned/other values keep their existing fallback label.

## Design decisions

- **In Risk/Cluster modes, popups drop the accessibility section** (no hint). (Confirmed.)
- **Risk heatmap = severity, not density** — dense low-risk reads green. (Confirmed.)
- **Reuse `<x-confirm-modal>`** for the geocode confirmation, matching the app's other
  destructive/confirm dialogs.
- **Cluster titles already exist** in `CLUSTER_HEATMAP_RAMPS` — reuse them; do not
  hardcode a second copy.

## Testing strategy

Most changes are client-side JS in `gis.blade.php` and cannot be unit-tested via PHPUnit:

- **Automated (PHPUnit feature test):** GET `reports.gis` renders 200 for an authorized
  user; the page no longer contains the `confirm('Run bulk` handler and DOES contain the
  geocode confirm-modal markup; the cluster titles (`C1 · High Functioning …`) are
  present in the page source.
- **Manual (required):** load the map and verify — geocode modal appears (no native
  dialog); clicking a senior in Risk/Cluster modes shows no accessibility/route section
  and makes no ORS calls, while Markers/Accessibility modes still do; the Risk
  Distribution surface clearly highlights high-risk areas and dense low-risk areas read
  green; the cluster dropdown lists the four full titles and filtering works. The
  `gis-module` skill's smoke test (`.claude/skills/run-osca-system/smoke.ps1`) verifies
  the GIS API still serves data.

## Out of scope (later batches)

- Reports (Risk & Barangay), Admin/Misc → Batches 5-6.
- The GIS data pipeline commands (geocode/score/route-cache) are unchanged — only the
  bulk-geocode *confirmation UI* changes, not the geocode command itself.
- Accessibility scoring math and the ORS caching layer are unchanged.

## Verification

- Geocode: clicking the trigger opens an in-app modal; confirming runs geocoding; no
  native browser dialog.
- Mode gating: Risk/Cluster popups omit the accessibility section and trigger no route
  requests (check the network tab); Markers/Accessibility popups still compute and show
  route distances.
- Risk heatmap: high-risk areas are the hottest; a dense low-risk barangay does not paint
  red.
- Cluster dropdown: shows the four full "C{n} · …" titles; selecting one filters the map
  correctly; "All Groups" still works.
- `php artisan test` passes; `./vendor/bin/pint` clean on changed PHP files before push.
