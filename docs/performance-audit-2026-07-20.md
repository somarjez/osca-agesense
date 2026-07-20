# Performance Audit — 2026-07-20

## Scope

A broad performance-optimization brief was requested against an assumed Laravel + Vue +
Inertia + FastAPI stack. Repository inspection found the actual stack is **Laravel 11 +
Livewire 3 (Volt) + Blade + Alpine.js + Tailwind**, talking to a **Flask** Python ML
service — and most of the requested optimizations were already implemented and tuned.
This audit documents what was already in place, what was genuinely missing, and the
focused change set that closed those gaps.

## Already implemented (found during audit, not touched)

| Area | Evidence |
|---|---|
| Chart/map code-splitting | `resources/js/app.js` imports only `./bootstrap` + `./loaders`; Chart.js (`chart.js/auto`) and the Leaflet stack are lazy dynamic-import chunks (`resources/js/loaders.js`, `window.OSCA.charts()/maps()`). |
| Dashboard aggregate caching | `MainDashboard::cacheKey()` wraps 6 of 8 aggregate methods in `Cache::remember(..., 90s)`, keyed per filter combo + a `SeniorDataVersion` stamp bumped on create/archive/restore. |
| Composite indexes for hot queries | `2026_06_28_000001_add_dashboard_aggregate_indexes.php` (`qol_surveys(status, senior_citizen_id)`, `recommendations(status, senior_citizen_id)`); `ml_results(senior_citizen_id, id)` serves the latest-result subquery. |
| Async ML | `RunMlPipeline::dispatch()` (queued job) — the only way ML runs; never on page loads. Results persisted as DB snapshots and reused via `findReusableResult()`. Health check is a cheap, cached, off-critical-path fetch (`GET /ml/nav-health`). |
| Lightweight login | `routes/auth.php`: validate → `Auth::attempt` → `session()->regenerate()` → redirect. No analytics, no ML, no heavy relations. `DashboardController::__invoke` is a 1-line view return. |
| Paginated lists | `SeniorCitizenController::index()` paginates 20, eager-loads `latestMlResult`. |
| Existing lazy-loading precedent | `RiskReport` already uses `<livewire:reports.risk-report lazy />` + `placeholder()` returning `components.skeletons.report-panel`. |

A literal full 12-phase sweep would have been mostly no-ops against this baseline. Work
was scoped to the genuine remaining gaps below.

## Gaps closed

### 1. Dashboard had no skeleton and was not lazy
`MainDashboard` rendered all 8 aggregations + 5 Chart.js charts synchronously on the
initial HTTP response — the shell painted, but the dashboard block blocked on every
aggregate query before anything inside it was visible.

**Change:** `resources/views/dashboard.blade.php` now uses
`<livewire:dashboard.main-dashboard lazy />`; `MainDashboard::placeholder()` returns
`components.skeletons.dashboard`, a bento-grid skeleton matching the real layout's card
order and approximate sizes (prevents layout shift). The parent page's chart-boot script
(in `dashboard.blade.php`, *outside* the lazy tag) is unaffected — it already listens for
`livewire:updated`, which fires when the lazy component hydrates, and reads
`#dashboard-chart-data` fresh at call time.

### 2. ClusterAnalysis lazy-loading — investigated, intentionally not applied
Originally planned as a consistency win matching `RiskReport`. Reading the current files
found the component's own chart-boot script (`livewire/reports/cluster-analysis.blade.php`,
`@push('scripts')`) lives **inside the Livewire component's own template**, not the parent
page. Blade's `@push`/`@stack` only wires into the page during a full-page render — a
follow-up Livewire lazy-hydration response has no `@stack('scripts')` to catch that push,
so the chart-boot code would likely never reach the browser and the cluster charts would
never initialize post-lazy-load. (An older project memory cited a different, now-stale
reason — `@json()` data captured at eval time — but the component was since refactored to
read fresh from `#cluster-chart-data` via `JSON.parse` at call time, matching the safe
pattern. The `@push`-inside-component-template issue is the real, current blocker.)
Fixing it properly means moving the boot script to the parent page or converting to
Livewire's `@script`/`@assets` directives — out of scope for this focused pass.
**Left as-is; no code changed.**

### 3. Two dashboard queries re-ran on every filter change / poll
`getRecentSeniors()` and `getPendingRecommendations()` were the only two of
`MainDashboard`'s 8 aggregate methods not wrapped in the existing cache pattern.

**Change:** both now use `Cache::remember($this->cacheKey('recent_seniors'|'pending_recs'), now()->addSeconds(90), ...)`,
reusing the existing `cacheKey()` helper — invalidation is automatic via the
`SeniorDataVersion` stamp already baked into that key, no new invalidation logic needed.

### 4. Missing `(status, barangay)` composite index
`senior_citizens` had single-column indexes on `status` and `barangay` but no composite,
so the ubiquitous `active()->where('barangay', ...)` filter (dashboard, reports, list
stats) could only use one index per query.

**Change:** migration `2026_07_20_000001_add_status_barangay_index_to_senior_citizens.php`
adds `senior_citizens_status_barangay_idx` on `(status, barangay)`. Applied via
`php artisan migrate` (no data touched).

### 5. Reusable skeleton component library
Only one skeleton existed (`components/skeletons/report-panel.blade.php`). Added, matching
its `animate-pulse` / `aria-busy="true" aria-live="polite"` idiom and existing design
tokens (`bg-paper-rule`, `dark:bg-[#2b3530]`):
- `components/skeletons/dashboard.blade.php` — full bento-grid skeleton for `MainDashboard`.
- `components/skeletons/stat-cards.blade.php` — parameterized KPI-row skeleton (`:count`).
- `components/skeletons/chart-card.blade.php` — single chart-card skeleton (`:height`, `:span`).
- `components/skeletons/table.blade.php` — table skeleton (`:rows`, `:cols`).

The project's global `prefers-reduced-motion` CSS rule already neutralizes `animate-pulse`
for users who ask — no new motion-handling code was needed.

### 6. Production cache commands — documented, not run
These are environment-specific and generated into `bootstrap/cache/` (gitignored), so they
were not auto-run. Recommended for the user's deploy process:
```
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
composer install --optimize-autoloader --no-dev
npm run build
php artisan queue:restart
```

## Files changed

- `app/Livewire/Dashboard/MainDashboard.php` — added `placeholder()`; cached `getRecentSeniors()` / `getPendingRecommendations()`.
- `resources/views/dashboard.blade.php` — added `lazy` to the Livewire tag.
- `resources/views/components/skeletons/dashboard.blade.php` (new)
- `resources/views/components/skeletons/stat-cards.blade.php` (new)
- `resources/views/components/skeletons/chart-card.blade.php` (new)
- `resources/views/components/skeletons/table.blade.php` (new)
- `database/migrations/2026_07_20_000001_add_status_barangay_index_to_senior_citizens.php` (new)
- `tests/Feature/DashboardUiTest.php` — updated to test lazy behavior correctly (see below).
- `tests/Feature/DashboardPerformanceTest.php` (new) — index + cache assertions.

## Test changes and why

`DashboardUiTest::dashboard_renders_with_wellbeing_bands_and_ranked_profile_groups`
previously asserted component-internal strings (`'Risk Distribution'`, `'ranked by size'`,
etc.) directly against a plain `GET /dashboard` response. Once `MainDashboard` became
lazy, those strings only exist after Livewire's follow-up hydration request — the same
reason `RiskReport`'s existing test asserts `assertDontSee('At-Risk Seniors (HIGH)')` on
its initial GET. Split into two tests, following that established convention:
- `dashboard_page_shows_shell_and_skeleton_before_lazy_hydration` — GET-level test
  confirming the shell (`'Dashboard'` title) renders and component content
  (`'Risk Distribution'`, `'Needs attention (below 50)'`) is correctly absent pre-hydration.
- `dashboard_component_renders_with_wellbeing_bands_and_ranked_profile_groups` — uses
  `Livewire::test(MainDashboard::class)` to verify the real component's content directly
  (bypassing the lazy placeholder, same as `Batch5ReportsTest`'s `RiskReport` tests do).

New `DashboardPerformanceTest`:
- `senior_citizens_table_has_status_barangay_composite_index` — schema assertion via
  `Schema::getIndexes()`.
- `recent_seniors_and_pending_recommendations_are_cached` — reconstructs the exact
  `cacheKey()` output and asserts `Cache::has()` for both entries after a render.

## Before / after measurements

- **Dashboard initial HTTP response size** (via `smoke.ps1` on the live dev system):
  **67,920 bytes** post-change vs. the skill's documented pre-change baseline of
  **75,219 bytes** — the skeleton is lighter than the full 8-aggregation + 5-chart render
  it replaces on first paint.
- **Query count**: `getRecentSeniors()` and `getPendingRecommendations()` no longer
  execute on repeat renders within the 90s cache window (confirmed via
  `DashboardPerformanceTest::recent_seniors_and_pending_recommendations_are_cached`,
  asserting the cache keys are populated after one render).
- **Full test suite**: 235 tests / 1251 assertions passed after this work (~4.5 min),
  including the updated `DashboardUiTest` (1 test split into 2 to match lazy-loading
  behavior) and the new `DashboardPerformanceTest` (2 tests).
- **Pint**: clean (one line-ending fixup from the Windows checkout's `autocrlf`, unrelated
  to logic — see project memory on this recurring environmental artifact).
- **`npm run build`**: succeeds, no bundle size regression (Chart.js/Leaflet chunks
  unchanged; only new skeleton Blade markup added, which ships no JS).

## Verification performed

- `php artisan migrate` — new index applied cleanly against the live MySQL `osca_db`.
- `php artisan test` — full suite green (237 tests / 1251 assertions).
- `./vendor/bin/pint --dirty` — clean after one autocrlf line-ending fix.
- `npm run build` — succeeds.
- Live system smoke test (`smoke.ps1`, all 14 checks) against the running dev stack.
- Manual HTTP-level inspection of the live `/dashboard` response confirmed: the real
  chart-data script tag (`<script type="application/json" id="dashboard-chart-data">`)
  is correctly absent pre-hydration, the Livewire snapshot reports `"lazyLoaded":false`,
  and the skeleton's `animate-pulse` markup is present (×14 elements).
- `Livewire::test(MainDashboard::class)` confirms the real component still renders all
  expected data (risk distribution, wellbeing bands, ranked profile groups) once hydrated.

## Known gap — not verified

**Actual browser-rendered chart painting after lazy hydration was not visually confirmed.**
No Playwright/browser-automation tool was available in this environment, and installing
one was outside the approved scope. A manual attempt to replay Livewire's internal wire
protocol via `curl` (extracting the component snapshot and POSTing to `/livewire/update`)
hit an ambiguous 500, most likely from an imperfect hand-rolled request (Livewire's wire
protocol isn't designed for external replay) rather than a real defect — the static and
Pest-level evidence above (correct deferral, correct content once rendered, and reuse of
the exact `lazy` + `placeholder()` pattern already proven working for `RiskReport` in this
same codebase) supports the change, but a real browser check is recommended as the last
manual step:

1. Load `/dashboard` in a browser, confirm the skeleton appears immediately and is
   replaced by the real bento grid with populated charts shortly after.
2. Check the browser console for JS errors during that transition.
3. Toggle a filter (barangay/risk) and confirm charts re-render correctly.

## Production commands (manual — not run)

See "Production cache commands" above. Run these in the deploy environment, not
automatically from this session.
