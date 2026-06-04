# QA Batch 5 — Reports (Risk & Barangay)

**Date:** 2026-06-05
**Status:** Approved design, pending implementation plan
**Source:** QA Testing punch list (June 4, 2026), Batch 5 of 6

## Context

Fifth of six module-scoped batches from the 2026-06-04 QA punch list. Covers the Risk
Report (interactive explorer + CSV export) and the Barangay Report (card layout + roster).

Batches 1-4 are merged (PRs #74, #75, #76, #77). Admin/Misc remains as Batch 6.

## Affected code (current state)

### Risk Report
- `app/Livewire/Reports/RiskReport.php` — `WithPagination`; filters `filterRisk`,
  `filterBarangay`, `filterCluster`; sort `sortBy` (default `composite_risk`) / `sortDir`
  via `sortColumn()`; `render()` builds the latest-ML-result query, paginates 25, and
  computes `$summaryStats`.
- `resources/views/livewire/reports/risk-report.blade.php` — filter bar (`:30-62`) with
  the three selects + Clear + an `Export CSV` link to `route('reports.risk.export')` (no
  params). Table headers (`:72-88`): only `composite_risk` (`:76`) and
  `overall_risk_level` (`:83`) are sortable; **IC/Env/Func (`:80-82`) are NOT sortable**.
  The **cluster filter (`:44-49`) is stale** — lists only Cluster 1/2/3 with old labels
  ("High Functioning / Moderate-Mixed / Low Functioning"); the system is K=4.
- `app/Http/Controllers/ReportController.php` `exportRisk()` — hardcodes
  `->whereIn('ml_results.overall_risk_level', ['HIGH'])`; takes no request params.

### Barangay Report
- `app/Http/Controllers/ReportController.php` `barangay(string $brgy)` — loads `$seniors`
  (full barangay roster, `->get()`, no pagination); aggregate cards (`$riskDist`,
  `$clusterDist`, `$domainAvgs`, `$urgentCount`, `$pendingRecs`) via separate queries.
- `resources/views/reports/barangay.blade.php` — `$total`/`$surveyed` from
  `$seniors->count()` (`:27-28`); a `grid grid-cols-1 lg:grid-cols-2 gap-4` (`:65`)
  holding "Average Domain Risk Scores" (left, `:68`) and "Health Group Distribution"
  (right, `:102`) — the right card also contains "Pending Recommendations" (`:140`), so
  default grid stretch makes the left card expand to match. Roster table (`:165-178`)
  iterates `$seniors` directly; `$total` shown at `:162`.

Reusable: WHO/cluster labels already standardized elsewhere; `form-input` text-input
class; Livewire `WithPagination`; Laravel paginator `links()`.

## Requirements

### Risk Report

1. **Search (name / OSCA ID).** Add a `filterSearch` public property to `RiskReport`,
   bound live in the filter bar. Apply as a `whereHas('seniorCitizen', …)` matching
   `osca_id` OR full name (case-insensitive, partial). Reset pagination on change.
   Include in the Clear control and the `@if` that shows Clear.

2. **Domain-score drill-down.** Make the IC / Env / Func column headers sortable via
   `sortColumn('ic_risk')`, `sortColumn('env_risk')`, `sortColumn('func_risk')` (the
   existing `sortColumn` already validates against allowed columns — extend the allowed
   set to include these three). Show the same sort-arrow affordance as the other sortable
   headers.

3. **Cluster filter — 4 real health groups.** Replace the stale 3-option cluster select
   with the four clusters, filtering on `cluster_named_id` (values 1-4), displaying the
   full titles:
   - C1 · High Functioning / Well-Supported Seniors
   - C2 · Stable Ageing / Moderate Support Needs
   - C3 · Environmentally and Financially Vulnerable Seniors
   - C4 · Low Functioning / Multi-Domain Priority Seniors

4. **CSV export reflects the active view.** The `Export CSV` link carries the current
   `filterRisk`, `filterBarangay`, `filterCluster`, `filterSearch`, `sortBy`, `sortDir`
   as query params (built in the Livewire view from the component state).
   `exportRisk(Request $request)` removes the hardcoded HIGH-only filter and applies the
   same filter/search/sort logic (composite-risk desc when no sort given). With no params,
   it exports ALL risk levels. Keep the existing CSV columns/format.

### Barangay Report

5. **Card layout fix.** Add `items-start` to the domain/health-group grid
   (`barangay.blade.php:65`) so "Average Domain Risk Scores" no longer stretches to match
   the taller "Health Group Distribution + Pending Recommendations" card.

6. **Roster pagination + search.** In `barangay()`, build a separate paginated, searchable
   roster query (name OR OSCA, GET param `roster_search`), e.g. `$roster = SeniorCitizen::
   active()->where('barangay',$brgy)->with('latestMlResult')->when($request->roster_search,
   …)->orderBy('last_name')->paginate(25)->withQueryString()`. Keep the barangay-wide
   aggregate cards and a `$total` **count** computed over the whole barangay (NOT the
   paginated subset). The roster table iterates `$roster`, adds a search input and
   `$roster->links()`. `barangay()` gains a `Request $request` parameter.

## Design decisions

- **Cluster filter shows the 4 full "C{n} · …" titles** (user-confirmed), consistent with
  GIS/Health Groups.
- **Export reflects on-screen filters/search/sort** via query params; controller
  re-applies the logic (user-confirmed). The filter clauses are duplicated between the
  Livewire component and the controller export — acceptable for ~4 clauses; if it grows,
  extract a shared query scope later.
- **Roster search/pagination scope only the roster table**, not the barangay aggregates or
  the `$total` KPI.
- **Search = name + OSCA ID** in both reports, matching the pattern established in
  Batches 1-2.

## Out of scope (later batch)

- Admin/Misc (Batch Analysis search, Activity Log delete, Export Registry, password eye)
  → Batch 6.
- The cluster/risk summary stat cards and charts on the Risk Report are unchanged beyond
  the filter additions.

## Verification

- Risk Report: search by name and OSCA returns matching rows; IC/Env/Func headers sort;
  cluster filter lists the four full titles and filters correctly; Export CSV downloads a
  file matching the current filters/search/sort (and ALL levels when unfiltered).
- Barangay Report: the "Average Domain Risk Scores" card sizes to its content (no awkward
  stretch); the roster paginates and is searchable while the aggregate cards and `$total`
  still reflect the whole barangay.
- `php artisan test` passes (new feature tests cover search, export-all-levels, roster
  pagination/search); `./vendor/bin/pint` clean on changed PHP files before push.
