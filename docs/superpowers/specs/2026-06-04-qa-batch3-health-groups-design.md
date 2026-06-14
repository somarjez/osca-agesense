# QA Batch 3 — Health Groups

**Date:** 2026-06-04
**Status:** Approved design, pending implementation plan
**Source:** QA Testing punch list (June 4, 2026), Batch 3 of 6

## Context

Third of six module-scoped batches from the 2026-06-04 QA punch list. Covers the
Health Groups page (the cluster report at `reports/cluster.blade.php`): the section
switcher, the Model Insights visualization, and snapshot deletion.

Batches 1 (PR #74) and 2 (PR #75) are merged. GIS, Reports, and Admin/Misc remain as
Batches 4-6.

## Affected code (current state)

- `resources/views/reports/cluster.blade.php` — the Health Groups page.
  - Secondary-analysis tabbed card (`:181-315`), an Alpine `x-data` with `section`
    (`insights` | `explorer` | `snapshots`).
  - Section tab strip (`:197-213`): underline-style buttons (`border-b-2`), subtle.
  - Model Insights panel (`:215-256`): Alpine fetches `reports.xai.model-insights`,
    renders horizontal feature-importance bars normalized to the top feature; domain
    labels at `:186` are `{ ic: 'Physical Capacity (IC)', env: 'Environment',
    func: 'Daily Functioning' }`; inner `.segmented` domain switcher (`:219-224`).
  - Snapshot History panel (`:263-314`): table of `$snapshots` grouped by date; no
    delete action.
- `app/Http/Controllers/ReportController.php` — `snapshotClusters()` (`:599`, creates
  via `osca:snapshot-clusters`); `cluster()` builds `$snapshots` (grouped by
  `DATE(snapshot_date)`).
- `app/Models/ClusterSnapshot.php` — plain `Model`, NO SoftDeletes (deletes are
  permanent). One row per (date, cluster_id); a "snapshot" = up-to-4 rows sharing a date.
- `routes/reports.php` — admin-only group already holds `cluster.snapshot` (POST). New
  delete route goes here.

## Requirements

### 1. Prominent section switcher (`cluster.blade.php:197-213`)

Replace the thin underline tab strip with a **segmented pill control** that clearly
reads as the primary view switcher:

- A contained group; the active section is a **filled pill** (forest background, light
  text); inactive sections are a subtle surface with a hover state.
- Larger touch targets; keep horizontal-scroll safety on narrow screens.
- Must remain visually distinct from the inner `.segmented` domain control in the Model
  Insights panel (so the two controls don't read as the same level).
- Preserve the existing Alpine `section` state and the three keys
  (`insights`/`explorer`/`snapshots`).

### 2. Model Insights visualization redesign (`cluster.blade.php:215-256, :186`)

Data already loads; improve presentation and labels.

- **Labels** (`:186`): `'Physical Capacity (IC)'` → `'Intrinsic Capacity (IC)'`;
  `'Daily Functioning'` → `'Functional Ability'`; `'Environment'` unchanged. (Consistent
  with Batch 2 WHO terminology.)
- **Bar redesign**: each feature row gains a **rank number**; clearer/larger feature
  labels; a stronger bar with a visible track; the importance percentage emphasized;
  tightened, scannable spacing. Keep the per-domain switch but restyle it to match the
  new section control's register. Polish the loading skeleton and the "Model insights
  unavailable" empty state.
- Keep the existing relative-to-top normalization (top feature = 100%); this is a visual
  redesign, not a scale change.

### 3. Delete snapshots (`cluster.blade.php:263-314` + controller + route)

Add a per-date **Delete** action to Snapshot History:

- New **admin-only** route in `routes/reports.php`:
  `Route::delete('/cluster/snapshot/{date}', [ReportController::class, 'destroySnapshot'])->name('cluster.snapshot.destroy')`.
- `ReportController@destroySnapshot(string $date)`: validate the date, hard-delete all
  `ClusterSnapshot` rows for that date
  (`ClusterSnapshot::whereDate('snapshot_date', $date)->delete()`), redirect back with a
  success flash (or an info flash if nothing matched). Permanent by nature (no
  SoftDeletes).
- UI: a small **Delete** button on each snapshot row, wired to an `<x-confirm-modal>`
  that states the date's snapshot will be **permanently** removed. Match the existing
  delete-confirm pattern used elsewhere (e.g. the QoL list).

## Design decisions

- **Section switcher = segmented pill group** (user-confirmed) — filled active pill,
  distinct from the inner domain segmented control.
- **Snapshot delete = per-date row** (the whole day's snapshot), matching how the table
  groups rows (user-confirmed).
- **Permanent delete** is inherent — `ClusterSnapshot` has no soft-deletes; the confirm
  modal must say so.
- **WHO terminology** carried over from Batch 2.

## Out of scope (later batches)

- GIS Analytics, Reports (Risk & Barangay), Admin/Misc → Batches 4-6.
- The "Take Snapshot" creation flow and the daily 23:55 auto-snapshot are unchanged.
- The Cluster Explorer panel (`<livewire:reports.cluster-analysis />`) is unchanged.

## Verification

- Health Groups page: the section switcher is an obvious segmented pill control; the
  active section is clearly filled; switching still works.
- Model Insights: bars render with rank numbers, corrected WHO domain labels, and a
  clearer layout; domain switch works; loading/empty states look intentional.
- Snapshot History: each row has a Delete button; confirming permanently removes that
  date's snapshot and the row disappears; the route is admin-only (forbidden for
  encoder/viewer); a feature test covers the delete + authorization.
- Full `php artisan test` passes; `./vendor/bin/pint` clean on changed files before push.
