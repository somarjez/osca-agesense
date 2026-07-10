# Frontend Performance & UI Rendering Optimization — Design

**Date:** 2026-07-10
**Status:** Draft for review
**Scope:** AgeSense OSCA system — perceived responsiveness of navigation, section open/close, charts, GIS map, and dashboard/report panels.

---

## 1. Context & premise correction

The originating brief was written for an **Inertia + Vue** SPA (KeepAlive, `v-show`/`v-if`, dynamic Vue imports, Pinia). **This application uses none of that.** Verified stack:

| Layer | Actual technology |
|-------|-------------------|
| Backend | Laravel 11 |
| Interactivity | Livewire 3 (+ Volt), Alpine.js 3 |
| Views | Blade (58 `.blade.php`, **0 `.vue`**) |
| Styling | Tailwind 3 |
| Charts | Chart.js 4 (`chart.js/auto`) |
| Maps | Leaflet 1.9 + `leaflet.markercluster` + `leaflet.heat` |
| Bundler | Vite 5 (`laravel-vite-plugin`) |

Every *goal* in the brief is valid; the *mechanisms* are translated to the Livewire/Alpine equivalents:

| Brief (Vue) mechanism | AgeSense (Livewire/Alpine) equivalent |
|-----------------------|----------------------------------------|
| Replace reloads with Inertia navigation | `wire:navigate` SPA-style navigation |
| `<KeepAlive>` / preserve component state | `wire:navigate` DOM morph + `localStorage`-backed Alpine state |
| Lazy-load Vue components (dynamic import) | Livewire `lazy` components + JS dynamic `import()` for Chart.js/Leaflet |
| `v-show` vs `v-if` | Alpine `x-show` vs `x-if` (already used) |
| Pinia store cache | Livewire computed caching + Laravel `Cache::remember` (already used) |

---

## 2. Baseline measurement (captured 2026-07-10)

Production build (`npm run build`), **before** any change:

| Asset | Raw | Gzip |
|-------|-----|------|
| `assets/app-*.js` (single chunk, 68 modules) | **444.48 kB** | **143.44 kB** |
| `assets/app-*.css` (main) | 112.17 kB | 17.74 kB |
| `assets/app-*.css` (secondary) | 11.71 kB | 2.86 kB |

**Key defect:** the single `app.js` chunk bundles Chart.js (full auto build) + Leaflet + markercluster + heat + Alpine glue and is loaded on **every** page via `@vite(['resources/css/app.css','resources/js/app.js'])` in `layouts/app.blade.php` (and `app.css` alone on `auth/login.blade.php`, `layouts/error.blade.php`). The login page and every senior profile download the entire GIS map engine they never render. Vite performs **no code-splitting** today.

Re-measurement after each phase is required; results recorded in §9.

---

## 3. Already optimal — verified, no action

These were audited and confirmed healthy. **Out of scope** — do not modify:

- **Seniors table** (`SeniorCitizenController::index`): `paginate(20)->withQueryString()`, server-side search (first/last name, OSCA ID) + barangay + cluster filters. Search is a GET form submit — no per-keystroke request spam. ✅
- **Dashboard charts** (`dashboard.blade.php`): use an `upsert` pattern that reuses the existing `Chart` instance per canvas (does not blindly destroy/rebuild) and already re-render on `livewire:navigated`. ✅
- **Dashboard heavy query** (`MainDashboard::latestMlIds`): the shared `MAX(id) GROUP BY` denominator is `Cache::remember`'d for 5 min. ✅
- **ML service status**: **not** globally polled. The topbar shows a cached health dot; no dashboard/login page polls `/ml/status`, `/ml/start`, or `/ml/stop`. (One render-path nuance addressed in Phase 4.) ✅
- **Duplicate assets**: `@vite` appears once per layout; the former Chart.js CDN `<script>` was already removed; Alpine is Livewire's single bundled copy (app.js explicitly does not import a second). ✅

Only the gaps below are in scope.

---

## 4. Phase 1 — Code-split the mega-bundle 🔴

**Problem:** one 444 kB / 143 kB-gzip JS chunk on every page.

**Approach:** restructure `resources/js/app.js` so the always-loaded global entry contains only Alpine data components, Chart.js global defaults registration, the `window.OSCA` helpers, and the small submit/print/countup/scroll listeners. Heavy libraries become **conditional dynamic imports**:

- **Leaflet stack** (`leaflet`, `leaflet.markercluster`, `leaflet.heat`, and their three CSS files): moved into an async module loaded only when a map mount point (e.g. `#gis-map`) exists on the page, or on demand by GIS page scripts.
- **Chart.js**: moved into an async module loaded only when a chart canvas exists on the page. `window.Chart` / `window.OSCA.buildDoughnut`/`buildHBar` become available *after* the chunk resolves; chart-consuming inline scripts must await a ready signal.

**Interface contract (single, well-defined):** expose two small loader promises so page scripts have one clear way to depend on heavy libs, e.g.:

```js
window.OSCA.charts()  // → Promise<typeof Chart>, resolves after Chart.js chunk loads (idempotent, memoized)
window.OSCA.maps()    // → Promise<{ L }>, resolves after Leaflet+plugins load (idempotent, memoized)
```

Inline blade chart/map scripts change from assuming `window.Chart` is present to `OSCA.charts().then(Chart => …)`. This is the interface boundary that keeps each page responsible only for its own rendering while the heavy deps load once and cache.

**Files:** `resources/js/app.js` (split), `vite.config.js` (verify manual chunks / dynamic-import output), chart-bearing blades (`dashboard.blade.php`, `reports/*.blade.php`, `livewire/reports/cluster-analysis.blade.php`, `surveys/qol/results.blade.php`), GIS blade + its map init script.

**Risk:** medium — every chart/map init site must adopt the ready-promise. Mitigated by the memoized loader (safe to call repeatedly) and by keeping Chart.js defaults registration inside the chunk.

**Verification:** `npm run build` shows separate `chart`/`leaflet` chunks; DevTools Network on login + dashboard + senior profile confirms the Leaflet chunk is **not** requested; GIS page still renders map; all charts still render.

**Expected outcome:** common-page JS payload drops well below the 143 kB gzip baseline (Leaflet+cluster+heat and, on non-chart pages, Chart.js no longer shipped).

---

## 5. Phase 2 — SPA navigation via `wire:navigate` 🟠 (gated behind Phase 1)

**Problem:** primary navigation is full-page reloads. Sidebar links in `layouts/app.blade.php` and the topbar Services/account links are plain `<a href>`. Only ~8 scattered links use `wire:navigate`.

**Approach:** add `wire:navigate` to sidebar nav links and topbar navigation links so section switches morph the DOM instead of reloading. Because Phase 1 introduces async chart/map loaders, wire them to the Livewire navigation lifecycle:

- On `livewire:navigated`: (re)run page chart/map init via the ready-promises. (Dashboard already listens here.)
- On `livewire:navigating`: destroy live `Chart` instances and Leaflet maps for the outgoing page to prevent leaks / duplicate canvases.

State that must survive navigation (sidebar collapse, dark mode) is already `localStorage`-backed on the `<html x-data="appLayout">` root and re-initialises correctly after a morph — verify no flash/reset.

**Files:** `layouts/app.blade.php` (nav + topbar links), `resources/js/app.js` (centralised navigate lifecycle teardown), GIS map script (make destroy-safe like dashboard charts already are).

**Risk:** higher — changes JS boot semantics on every page. Gated: only after Phase 1 lands and chart/map lifecycle is proven. `@stack('scripts')` blocks that rely on `DOMContentLoaded` must also bind `livewire:navigated`.

**Verification:** DevTools Network shows section switches issue XHR (not full document loads); no duplicate chart canvases after navigating away and back; GIS map disposes and re-inits cleanly; sidebar/dark state persists.

---

## 6. Phase 3 — Lazy panels + skeletons 🟡

**Problem:** heavy Livewire panels render synchronously; no `lazy` usage anywhere.

**Approach:** mark the dashboard and report Livewire components `lazy` with skeleton placeholders so the shell paints immediately and widgets stream in (`<livewire:… lazy />` + a `placeholder()` method). Reconsider `wire:poll.60s` on the dashboard root (`livewire/dashboard/main-dashboard.blade.php`) — either lengthen the interval or replace with an explicit "Refresh" action so opening the dashboard does not re-run all queries on a timer.

**Files:** dashboard mount point, report Livewire components (`Reports/ClusterAnalysis`, `Reports/RiskReport`), their placeholder skeletons.

**Risk:** low–medium — lazy components boot with default public-property state; verify filters/inputs are not silently reset.

**Verification:** dashboard shell paints before widget data arrives; skeletons visible on slow network; filters retain values.

---

## 7. Phase 4 — ML health off the render path 🟡

**Problem:** `layouts/app.blade.php` calls `MlService::healthCheck()` during render (cached 15–30 s). On a cache miss, the page render **blocks on a Flask network call**.

**Approach:** move the health check out of the synchronous render. Render the topbar dot in a neutral "checking" state, then have a small Alpine `x-init` fetch a lightweight cached JSON endpoint after paint and update the dot. Keep the existing short-TTL cache server-side so the endpoint is cheap.

**Files:** `layouts/app.blade.php` (topbar health block), a tiny read-only status endpoint (or reuse an existing cached one), `routes/web.php`.

**Risk:** low — read-only, non-blocking; falls back to "unknown" if the fetch fails.

**Verification:** with Flask stopped, page render is not delayed; dot updates asynchronously to offline state.

---

## 8. Phase 5 — Production hardening + re-measure

**Approach:** document/apply production steps (not committed app code, deployment guidance):
- `php artisan config:cache route:cache view:cache` (and `optimize`).
- `APP_DEBUG=false` in production `.env`.
- Confirm Vite fingerprinted assets are served with long-lived cache headers.
- Final `npm run build` re-measurement; fill in §9 before/after table.

**Risk:** low.

**Verification:** cached config/routes present; DevTools shows `immutable`/long max-age on hashed assets.

---

## 9. Before/after measurement table

Measured from `npm run build` gzip output. "Requested" = the JS chunks a page actually downloads given the lazy loaders (charts load only where a canvas exists; the Leaflet stack only where a map exists).

| Metric | Baseline (2026-07-10) | After P1 | After P2 | Final |
|--------|----------------------:|---------:|---------:|------:|
| Global JS gzip (every page) | 143.44 kB | 19.37 kB | 19.43 kB | **19.43 kB** |
| Login page JS requested (gzip) | 143.44 kB | 19.37 kB | 19.43 kB | **19.43 kB** (−86%) |
| Dashboard page JS requested (gzip) | 143.44 kB | 90.90 kB | 90.96 kB | **90.96 kB** (app 19.43 + Chart.js 71.53; Chart.js now loads after paint) |
| GIS page JS requested (gzip) | 143.44 kB | 73.95 kB | 74.01 kB | **74.01 kB** (app 19.43 + Leaflet 43.56 + markercluster 9.09 + heat 1.93; no Chart.js) |
| Section switch = full document load? | Yes | Yes | No | **No** (`wire:navigate`) |
| Topbar render blocks on ML healthCheck? | Yes (cache-miss) | Yes | Yes | **No** (async `/ml/nav-health` fetch after paint) |

**Final chunk inventory (gzip):** `app.js` 19.43 kB (global) · `auto` [Chart.js] 71.53 kB (lazy) · `leaflet-src` 43.56 kB + `leaflet.markercluster-src` 9.09 kB + `leaflet-heat` 1.93 kB (lazy) · Leaflet CSS chunks ~3.1 kB (lazy, map pages only). CSS `app.css` unchanged at 17.77 kB.

**Runtime verification still pending (requires a browser — no browser in the build session):** DevTools Network confirmation that login requests neither Chart.js nor Leaflet; that section switches issue XHR not full documents with no duplicate-canvas errors; that the GIS map disposes/re-inits cleanly on navigate; and that page render no longer stalls when Flask is stopped. See the production checklist.

---

## 10. Rollout & constraints

- **Repository:** commit only from the inner Laravel repo (`osca-system/osca-system`, remote `somarjez/osca-agesense`, base `main`).
- **Branching:** feature branch + PR; never push directly to `main`. Each phase is its own reviewable PR where practical (P1 first, P2 gated behind it).
- **No visual/workflow change:** design system, routes, and user workflow stay identical — this is purely a rendering/loading optimization.
- **Test gate:** `php -l`, `npm run build`, and existing PHPUnit (MySQL `osca_db` required) must pass before each PR.

---

## 11. Out of scope (YAGNI)

- Any Inertia/Vue migration.
- Re-engineering the already-optimal table pagination/filtering (§3).
- New GIS clustering/aggregation beyond what `leaflet.markercluster` already provides.
- Global watcher/computed refactors — none problematic were found.
