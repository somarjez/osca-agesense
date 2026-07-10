# Frontend Performance & UI Rendering Optimization — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make navigation, section open/close, charts, and the GIS map feel near-instant by code-splitting the single 444 kB JS bundle, enabling `wire:navigate` SPA navigation, lazy-loading heavy panels, and taking the ML health check off the render path — with no change to workflow or visual design.

**Architecture:** The app is Laravel 11 + Livewire 3 + Alpine 3 + Blade (no Vue/Inertia). Heavy JS libraries (Chart.js, Leaflet + markercluster + heat) currently load globally on every page. We split them into memoized dynamic-import loader promises (`window.OSCA.charts()`, `window.OSCA.maps()`) that populate the existing `window.Chart` / `window.L` globals on demand. Consumer scripts already reference those globals and already guard for "not loaded yet," so call-site edits are small. `wire:navigate` is then layered on with a centralized navigate-lifecycle teardown.

**Tech Stack:** Vite 5, Livewire 3 (`wire:navigate`, `lazy`), Alpine 3, Chart.js 4, Leaflet 1.9.

## Global Constraints

- Commit only from the inner Laravel repo: `osca-system/osca-system` (remote `somarjez/osca-agesense`, base `main`). Work branch: `perf/frontend-rendering-optimization` (already created).
- Never push directly to `main`; each phase is a reviewable commit, PR opened only when the user asks.
- No visual or workflow change: routes, design system, and user flows stay identical.
- Alpine is Livewire 3's single bundled copy — **never** `import` or `start` a second Alpine in `app.js`.
- Every JS change must survive `npm run build` (exit 0) before commit.
- Verification for UI/bundler changes = build output assertions + DevTools Network checks (no JS unit-test harness exists in this repo).
- Preserve the exact `window.Chart`, `window.L`, and `window.OSCA` global contract that existing inline blade scripts depend on.

---

## File Structure

| File | Responsibility |
|------|----------------|
| `resources/js/loaders.js` (create) | Memoized dynamic-import loaders: `loadCharts()` → sets `window.Chart` + defaults; `loadMaps()` → sets `window.L` + plugins. Exposed as `window.OSCA.charts()` / `window.OSCA.maps()`. |
| `resources/js/app.js` (modify) | Global entry: Alpine data, `window.OSCA` helpers, submit/print/countup/scroll listeners, navigate-lifecycle teardown. Heavy `import Chart`/`import L` removed. |
| `resources/views/dashboard.blade.php` (modify) | Chart triggers await `OSCA.charts()`. |
| `resources/views/reports/*.blade.php`, `resources/views/livewire/reports/cluster-analysis.blade.php`, `resources/views/surveys/qol/results.blade.php` (modify) | Chart triggers await `OSCA.charts()`. |
| `resources/views/reports/gis.blade.php` (modify) | Map triggers await `OSCA.maps()`; destroy-safe teardown. |
| `resources/views/seniors/show.blade.php` (modify) | Mini-map triggers await `OSCA.maps()`. |
| `resources/views/layouts/app.blade.php` (modify) | `wire:navigate` on nav/topbar links; ML health moved to deferred fetch. |
| `routes/web.php` (modify) | Tiny cached ML nav-health JSON endpoint. |

---

## Phase 1 — Code-split the mega-bundle

### Task 1: Create the memoized loader module

**Files:**
- Create: `resources/js/loaders.js`
- Test: manual build + Network verification (no JS test runner)

**Interfaces:**
- Produces: `window.OSCA.charts(): Promise<Chart>` and `window.OSCA.maps(): Promise<typeof L>`. Both idempotent/memoized. `charts()` resolves after Chart.js loads, its global defaults are applied, and `window.Chart` is set. `maps()` resolves after Leaflet + markercluster + heat + their CSS load and `window.L` is set.

- [ ] **Step 1: Write the loader module**

```js
// resources/js/loaders.js
// Memoized dynamic-import loaders. Heavy libs (Chart.js, Leaflet stack) are
// split out of the global bundle and fetched only when a page actually needs
// them. Both loaders set the same window globals (window.Chart / window.L)
// that existing inline blade scripts already depend on, so call sites only
// need to await the promise before running.

let chartsPromise = null
let mapsPromise = null

export function loadCharts() {
    if (chartsPromise) return chartsPromise
    chartsPromise = import('chart.js/auto').then(({ default: Chart }) => {
        // Global defaults previously lived at the top of app.js.
        Chart.defaults.font.family = "'DM Sans', system-ui, sans-serif"
        Chart.defaults.font.size = 11
        Chart.defaults.color = '#64748b'
        Chart.defaults.plugins.legend.display = false
        Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(15, 23, 42, 0.9)'
        Chart.defaults.plugins.tooltip.padding = 10
        Chart.defaults.plugins.tooltip.cornerRadius = 8
        Chart.defaults.plugins.tooltip.titleFont = { weight: '600', size: 12 }
        Chart.defaults.plugins.tooltip.bodyFont = { size: 11 }
        Chart.defaults.scale.grid.color = 'rgba(0, 0, 0, 0.04)'
        Chart.defaults.scale.ticks.color = '#94a3b8'
        Chart.defaults.animation.duration = 300
        window.Chart = Chart
        return Chart
    })
    return chartsPromise
}

export function loadMaps() {
    if (mapsPromise) return mapsPromise
    mapsPromise = import('leaflet').then(async ({ default: L }) => {
        await import('leaflet/dist/leaflet.css')
        await import('leaflet.markercluster')
        await import('leaflet.markercluster/dist/MarkerCluster.css')
        await import('leaflet.markercluster/dist/MarkerCluster.Default.css')
        await import('leaflet.heat')
        window.L = L
        return L
    })
    return mapsPromise
}
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/loaders.js
git commit -m "feat(perf): add memoized Chart.js/Leaflet dynamic-import loaders"
```

---

### Task 2: Remove heavy imports from app.js and wire up OSCA.charts/maps

**Files:**
- Modify: `resources/js/app.js`

**Interfaces:**
- Consumes: `loadCharts`, `loadMaps` from Task 1.
- Produces: `window.OSCA.charts` / `window.OSCA.maps` on the existing `window.OSCA` object. Removes top-level `import Chart from 'chart.js/auto'` and all `import L`/leaflet imports and the top-level `Chart.defaults.*` block and `window.Chart = Chart` / `window.L = L` assignments (those now live in the loaders).

- [ ] **Step 1: Edit app.js imports**

Replace the top import block:
```js
import './bootstrap'
import Chart from 'chart.js/auto'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import 'leaflet.markercluster'
import 'leaflet.markercluster/dist/MarkerCluster.css'
import 'leaflet.markercluster/dist/MarkerCluster.Default.css'
import 'leaflet.heat'
```
with:
```js
import './bootstrap'
import { loadCharts, loadMaps } from './loaders'
```

- [ ] **Step 2: Remove the top-level Chart.defaults block and global assignments**

Delete the entire `// ── Chart.js global defaults ──` block (the `Chart.defaults.*` lines through `Chart.defaults.animation.duration = 300`) — it now lives in `loadCharts()`. Also delete the two lines:
```js
window.Chart = Chart
window.L = L
```

- [ ] **Step 3: Expose loaders on window.OSCA**

Inside the `window.OSCA = { … }` object, add these two methods (alongside `riskColor`, `clusterColor`, `buildDoughnut`, `buildHBar`):
```js
    /** Lazy-load Chart.js (memoized). Resolves after window.Chart is set. */
    charts() { return loadCharts() },

    /** Lazy-load Leaflet + plugins (memoized). Resolves after window.L is set. */
    maps() { return loadMaps() },
```

- [ ] **Step 4: Guard buildDoughnut/buildHBar against missing Chart**

At the top of `buildDoughnut(...)` and `buildHBar(...)`, ensure Chart is present (defensive — call sites should await `OSCA.charts()` first). Add as the first line of each method body:
```js
        if (!window.Chart) return null
```

- [ ] **Step 5: Build and verify split chunks exist**

Run: `npm run build`
Expected: exit 0, and the output lists **separate** chunks for Chart.js and Leaflet (e.g. a `chart`-named and a `leaflet`/`markercluster` chunk) in addition to a now-smaller `app-*.js`. Confirm the main `app-*.js` gzip is well under the 143.44 kB baseline.

- [ ] **Step 6: Record the new baseline in the spec**

Update `docs/superpowers/specs/2026-07-10-frontend-performance-optimization-design.md` §9 "After P1" column with the new gzip sizes from Step 5.

- [ ] **Step 7: Commit**

```bash
git add resources/js/app.js docs/superpowers/specs/2026-07-10-frontend-performance-optimization-design.md
git commit -m "perf(assets): split Chart.js and Leaflet out of the global bundle"
```

---

### Task 3: Make dashboard + report chart scripts await OSCA.charts()

**Files:**
- Modify: `resources/views/dashboard.blade.php`
- Modify: `resources/views/reports/barangay.blade.php`, `resources/views/reports/cluster.blade.php`, `resources/views/reports/validation.blade.php`, `resources/views/livewire/reports/cluster-analysis.blade.php`, `resources/views/surveys/qol/results.blade.php`

**Interfaces:**
- Consumes: `window.OSCA.charts()` from Task 2.

- [ ] **Step 1: Find every chart entry point**

Run: `grep -rn "DOMContentLoaded\|livewire:navigated\|livewire:updated\|new Chart\|OSCA.build" resources/views/dashboard.blade.php resources/views/reports/*.blade.php resources/views/livewire/reports/cluster-analysis.blade.php resources/views/surveys/qol/results.blade.php`
Expected: the render/trigger functions that either call `new Chart`, `OSCA.buildDoughnut/buildHBar`, or the `upsert()` helper.

- [ ] **Step 2: Wrap dashboard chart triggers**

In `resources/views/dashboard.blade.php`, change the trigger listeners at the bottom of the `@push('scripts')` IIFE. Current:
```js
    document.addEventListener('livewire:navigated', () => setTimeout(render, 0));
    document.addEventListener('livewire:updated', render);
    document.addEventListener('DOMContentLoaded', () => {
        observeDark();
    });
```
Change to route every render through the charts loader:
```js
    const boot = () => window.OSCA.charts().then(() => render());
    document.addEventListener('livewire:navigated', () => setTimeout(boot, 0));
    document.addEventListener('livewire:updated', boot);
    document.addEventListener('DOMContentLoaded', () => {
        observeDark();
        boot();
    });
```
(Note: the existing code already had a `DOMContentLoaded` render path elsewhere in the IIFE — ensure the initial render now goes through `boot()` so charts load first. Remove any direct initial `render()` call that runs before `OSCA.charts()` resolves.)

- [ ] **Step 3: Wrap each report chart script the same way**

For each of `reports/barangay.blade.php`, `reports/cluster.blade.php`, `reports/validation.blade.php`, `livewire/reports/cluster-analysis.blade.php`, `surveys/qol/results.blade.php`: locate the function that builds the chart(s) (call it `render`/`draw`/inline) and its `DOMContentLoaded` / `livewire:navigated` listeners, and route them through `window.OSCA.charts().then(() => <renderFn>())` exactly as in Step 2. If a script calls `new Chart(...)` synchronously at parse time (not inside a listener), move that call into a function invoked via `window.OSCA.charts().then(...)`.

- [ ] **Step 4: Build**

Run: `npm run build`
Expected: exit 0.

- [ ] **Step 5: Manual render verification**

Serve the app (`php artisan serve` + `npm run dev`, or built assets). In the browser with DevTools Network open:
- Load **login** → confirm neither the chart nor leaflet chunk is requested.
- Load **dashboard** → charts render; the Chart.js chunk is requested; the Leaflet chunk is **not**.
- Load each report page with charts → charts render correctly.

- [ ] **Step 6: Commit**

```bash
git add resources/views/dashboard.blade.php resources/views/reports/barangay.blade.php resources/views/reports/cluster.blade.php resources/views/reports/validation.blade.php resources/views/livewire/reports/cluster-analysis.blade.php resources/views/surveys/qol/results.blade.php
git commit -m "perf(charts): await lazy Chart.js loader before rendering charts"
```

---

### Task 4: Make GIS + senior mini-map scripts await OSCA.maps()

**Files:**
- Modify: `resources/views/reports/gis.blade.php`
- Modify: `resources/views/seniors/show.blade.php`

**Interfaces:**
- Consumes: `window.OSCA.maps()` from Task 2.

- [ ] **Step 1: Wrap the GIS map triggers**

In `resources/views/reports/gis.blade.php` (bottom of the `@push('scripts')` block, ~lines 5763–5764). Current:
```js
    document.addEventListener('DOMContentLoaded', renderMap);
    document.addEventListener('livewire:navigated', () => setTimeout(renderMap, 0));
```
Change to load Leaflet first (`renderMap` already guards `if (!el || !window.L) return;`, and `OSCA.maps()` sets `window.L` before resolving):
```js
    const bootMap = () => window.OSCA.maps().then(() => renderMap());
    document.addEventListener('DOMContentLoaded', bootMap);
    document.addEventListener('livewire:navigated', () => setTimeout(bootMap, 0));
```

- [ ] **Step 2: Wrap the senior mini-map trigger**

In `resources/views/seniors/show.blade.php` (~lines 1152–1159). The `initSeniorMiniMap` function already early-returns when `!window.L`. Change its triggers:
```js
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSeniorMiniMap);
    } else {
        initSeniorMiniMap();
    }
    document.addEventListener('livewire:navigated', initSeniorMiniMap);
```
to:
```js
    const bootSeniorMap = () => window.OSCA.maps().then(() => initSeniorMiniMap());
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootSeniorMap);
    } else {
        bootSeniorMap();
    }
    document.addEventListener('livewire:navigated', bootSeniorMap);
```
(Adapt the exact surrounding lines to what is present; the change is: replace direct `initSeniorMiniMap` invocations with `bootSeniorMap`.)

- [ ] **Step 3: Build**

Run: `npm run build`
Expected: exit 0.

- [ ] **Step 4: Manual verification**

DevTools Network:
- **GIS Analytics** page → map renders; Leaflet + markercluster chunks requested here (and only here / senior profile).
- **Senior profile** with coordinates → mini-map renders; Leaflet chunk requested.
- **Dashboard / seniors index** → Leaflet chunk **not** requested.

- [ ] **Step 5: Commit**

```bash
git add resources/views/reports/gis.blade.php resources/views/seniors/show.blade.php
git commit -m "perf(gis): await lazy Leaflet loader before initializing maps"
```

---

## Phase 2 — SPA navigation via wire:navigate (gated behind Phase 1)

### Task 5: Centralize navigate-lifecycle teardown for charts and maps

**Files:**
- Modify: `resources/js/app.js`

**Interfaces:**
- Consumes: `window.Chart` (present only after `OSCA.charts()`), Leaflet map instances registered by page scripts.

- [ ] **Step 1: Add a teardown listener in app.js**

Add near the other `document.addEventListener('livewire:...')` blocks in `app.js`:
```js
// ── SPA navigation teardown ───────────────────────────────────────────────────
// wire:navigate morphs the <body> without a full reload. Destroy live Chart.js
// instances and Leaflet maps for the outgoing page so they don't leak or leave
// a "canvas already in use" error when the next page re-inits on the same id.
document.addEventListener('livewire:navigating', function () {
    if (window.Chart && window.Chart.instances) {
        Object.values(window.Chart.instances).forEach((c) => { try { c.destroy() } catch (e) {} })
    }
    if (window.__oscaMaps) {
        window.__oscaMaps.forEach((m) => { try { m.remove() } catch (e) {} })
        window.__oscaMaps = []
    }
})
```

- [ ] **Step 2: Register created maps for teardown**

In `resources/views/reports/gis.blade.php` and `resources/views/seniors/show.blade.php`, immediately after the `L.map(...)` instance is created (the `const map = window.L.map(...)` / `const map = L.map(...)` line), register it:
```js
    (window.__oscaMaps = window.__oscaMaps || []).push(map);
```
This lets the centralized `livewire:navigating` handler dispose maps on navigation. (Charts are found via `window.Chart.instances` and need no per-site registration.)

- [ ] **Step 3: Build**

Run: `npm run build`
Expected: exit 0.

- [ ] **Step 4: Commit**

```bash
git add resources/js/app.js resources/views/reports/gis.blade.php resources/views/seniors/show.blade.php
git commit -m "perf(spa): destroy charts and maps on wire:navigate teardown"
```

---

### Task 6: Add wire:navigate to sidebar and topbar navigation links

**Files:**
- Modify: `resources/views/layouts/app.blade.php`

**Interfaces:**
- Consumes: Task 5 teardown (must be committed first).

- [ ] **Step 1: Add wire:navigate to every primary nav `<a>`**

In `resources/views/layouts/app.blade.php`, add `wire:navigate` to each sidebar nav link (`href="{{ route('dashboard') }}"`, `seniors.index`, `seniors.create`, `surveys.qol.index`, `reports.cluster`, `reports.gis`, `reports.risk`, `reports.barangay.index`, `recommendations.index`, `ml.status`, `ml.batch`, `reports.validation`, `activity-log.index`, `reports.registry`, `users.index`, `seniors.archives`, `help`) and the topbar "Services" link (line ~301). Example transform:
```blade
<a href="{{ route('dashboard') }}"
   wire:navigate
   class="nav-link {{ request()->routeIs('dashboard') ? 'nav-link-active' : '' }}"
```
Do **not** add `wire:navigate` to: the logout `<form>` POST, the topbar search GET `<form>`, or the "Skip to content" anchor.

- [ ] **Step 2: Build**

Run: `npm run build`
Expected: exit 0.

- [ ] **Step 3: Manual SPA verification**

`php artisan serve` + built assets. DevTools Network (filter: Doc/Fetch):
- Click through Dashboard → Senior Records → GIS → Risk Reports → back to Dashboard.
- Expected: each switch issues an XHR/`fetch` (Livewire navigate), **not** a full document load (no full HTML `Doc` request, no white flash).
- Navigate Dashboard → GIS → Dashboard twice: no duplicate-canvas console error; charts and map re-render each time; sidebar collapse state and dark mode persist.
- On a slow-network throttle, GIS map still disposes/re-inits cleanly.

- [ ] **Step 4: Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "perf(spa): wire:navigate on sidebar and topbar navigation"
```

---

## Phase 3 — Lazy panels + skeletons

### Task 7: Reconsider dashboard wire:poll and add refresh affordance

**Files:**
- Modify: `resources/views/livewire/dashboard/main-dashboard.blade.php`
- Modify: `app/Livewire/Dashboard/MainDashboard.php`

**Interfaces:**
- Consumes: existing `MainDashboard` component.

- [ ] **Step 1: Replace aggressive poll with a lighter interval**

In `resources/views/livewire/dashboard/main-dashboard.blade.php` line 1, change `wire:poll.60s` to `wire:poll.300s` (5 min — matches the `latestMlIds` cache TTL, so opening the dashboard no longer re-runs all queries every minute). Keep the existing manual filter interactions intact.

- [ ] **Step 2: Build + verify**

Run: `npm run build` (exit 0). Load dashboard; confirm it still updates on filter changes and no longer issues a Livewire roundtrip every 60 s (DevTools Network: no `main-dashboard` update request until 5 min or a manual action).

- [ ] **Step 3: Commit**

```bash
git add resources/views/livewire/dashboard/main-dashboard.blade.php app/Livewire/Dashboard/MainDashboard.php
git commit -m "perf(dashboard): relax wire:poll from 60s to 300s"
```

---

### Task 8: Lazy-load report Livewire components with skeletons

**Files:**
- Modify: report Livewire components (`app/Livewire/Reports/ClusterAnalysis.php`, `app/Livewire/Reports/RiskReport.php`) and their mount points in the report blades.

**Interfaces:**
- Consumes: existing report Livewire components.

- [ ] **Step 1: Add lazy + placeholder to each report component**

For each report Livewire component, add a `placeholder()` method returning a skeleton and mount it lazily. In the component class:
```php
    public function placeholder(): \Illuminate\View\View
    {
        return view('components.skeletons.report-panel');
    }
```
Mark the component `#[Lazy]` (add `use Livewire\Attributes\Lazy;` and the `#[Lazy]` class attribute), or render its mount tag with `lazy`:
```blade
<livewire:reports.risk-report lazy />
```

- [ ] **Step 2: Create the skeleton view**

Create `resources/views/components/skeletons/report-panel.blade.php`:
```blade
<div class="space-y-4 animate-pulse" aria-busy="true" aria-live="polite">
    <div class="h-6 w-48 rounded bg-paper-rule dark:bg-[#2b3530]"></div>
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        @for ($i = 0; $i < 4; $i++)
            <div class="h-24 rounded-xl bg-paper-rule/60 dark:bg-[#2b3530]/60"></div>
        @endfor
    </div>
    <div class="h-64 rounded-xl bg-paper-rule/40 dark:bg-[#2b3530]/40"></div>
</div>
```

- [ ] **Step 3: Build + verify**

Run: `npm run build` (exit 0). Load each report page: the shell + skeleton paints immediately, then the panel streams in. Confirm filters/inputs are not reset after the lazy load resolves.

- [ ] **Step 4: Commit**

```bash
git add app/Livewire/Reports/ClusterAnalysis.php app/Livewire/Reports/RiskReport.php resources/views/components/skeletons/report-panel.blade.php resources/views/reports/
git commit -m "perf(reports): lazy-load report panels with skeleton placeholders"
```

---

## Phase 4 — ML health off the render path

### Task 9: Add a cached ML nav-health JSON endpoint

**Files:**
- Modify: `routes/web.php`
- Reference: `app/Services/MlService.php` (`healthCheck()`), current inline block in `layouts/app.blade.php` lines ~278–300.

**Interfaces:**
- Produces: `GET /ml/nav-health` (named `ml.nav-health`) returning `{ dot: 'ok'|'warn'|'err', title: string }`, guarded by the same `auth` + role middleware as the rest of the app, reading/writing the existing `ml_nav_health` cache key with the current 30 s/15 s TTL logic.

- [ ] **Step 1: Add the route**

In `routes/web.php`, inside the authenticated `role:admin,encoder,viewer` group, add:
```php
Route::get('/ml/nav-health', function () {
    $health = \Illuminate\Support\Facades\Cache::get('ml_nav_health');
    if ($health === null) {
        try {
            $health = app(\App\Services\MlService::class)->healthCheck();
        } catch (\Throwable) {
            $health = ['preprocessor' => 'unreachable', 'inference' => 'unreachable', 'local_runner' => 'unavailable', 'mode' => 'php_fallback'];
        }
        $ttl = ($health['preprocessor'] === 'ok' && $health['inference'] === 'ok') ? 30 : 15;
        \Illuminate\Support\Facades\Cache::put('ml_nav_health', $health, $ttl);
    }
    $dot = match (true) {
        $health['preprocessor'] === 'ok' && $health['inference'] === 'ok' => 'ok',
        ($health['local_runner'] ?? null) === 'available' => 'warn',
        default => 'err',
    };
    $title = match ($dot) {
        'ok' => 'HTTP services online',
        'warn' => 'HTTP services offline — using local fallback',
        default => 'All analysis services unavailable',
    };
    return response()->json(['dot' => $dot, 'title' => $title]);
})->name('ml.nav-health');
```

- [ ] **Step 2: Verify the route lints and registers**

Run: `php -l routes/web.php` (No syntax errors) and `php artisan route:list --name=ml.nav-health` (route present).

- [ ] **Step 3: Commit**

```bash
git add routes/web.php
git commit -m "feat(ml): cached nav-health JSON endpoint for async topbar status"
```

---

### Task 10: Make the topbar health dot load asynchronously

**Files:**
- Modify: `resources/views/layouts/app.blade.php`

**Interfaces:**
- Consumes: `GET /ml/nav-health` (Task 9).

- [ ] **Step 1: Replace the synchronous PHP health block**

In `layouts/app.blade.php`, delete the `@php … MlService::healthCheck() … @endphp` block (lines ~278–300) so no page render calls the ML service. Replace the Services `<a>` with an Alpine component that fetches after paint:
```blade
<a href="{{ route('ml.status') }}" wire:navigate
   x-data="{ dot: 'checking', title: 'Checking analysis services…' }"
   x-init="fetch('{{ route('ml.nav-health') }}', { headers: { 'Accept': 'application/json' } })
             .then(r => r.ok ? r.json() : null)
             .then(d => { if (d) { dot = d.dot; title = d.title } })
             .catch(() => { dot = 'err'; title = 'Status unavailable' })"
   class="inline-flex items-center gap-1.5 text-[11.5px] text-ink-500 dark:text-[#6b7570]
          hover:text-ink-900 dark:hover:text-[#e4e1d8] hover:bg-paper-2 dark:hover:bg-[#202a26]
          px-2 py-1.5 rounded-lg transition-all duration-150"
   :title="title">
    <span class="status-dot"
          :class="{ 'status-dot-ok': dot === 'ok', 'status-dot-warn': dot === 'warn', 'status-dot-err': dot === 'err' }"></span>
    <span class="font-medium hidden sm:inline text-[11.5px]">Services</span>
</a>
```
(Add a neutral "checking" appearance for `.status-dot` with no modifier if needed; default grey dot is acceptable.)

- [ ] **Step 2: Build + verify non-blocking behavior**

Run: `npm run build` (exit 0). **Stop the Flask ML services**, then load the dashboard: the page must render immediately (no multi-second stall), and the Services dot updates to offline/err a moment later via the async fetch. With services running, dot shows online.

- [ ] **Step 3: Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "perf(ml): move topbar health check off the synchronous render path"
```

---

## Phase 5 — Production hardening + final measurement

### Task 11: Final build, re-measure, and document production steps

**Files:**
- Modify: `docs/superpowers/specs/2026-07-10-frontend-performance-optimization-design.md` (§9)
- Create: `docs/superpowers/plans/perf-production-checklist.md`

- [ ] **Step 1: Final production build**

Run: `npm run build`
Expected: exit 0. Capture the gzip sizes of `app-*.js`, the `chart-*` chunk, and the `leaflet/markercluster-*` chunks.

- [ ] **Step 2: Fill in the before/after table**

Update §9 of the design spec with per-page requested JS gzip (login, dashboard, GIS) measured from DevTools Network, and set the "Section switch = full document load?" row to "No" for the final column.

- [ ] **Step 3: Write the production checklist**

Create `docs/superpowers/plans/perf-production-checklist.md` documenting (deployment steps, not committed app behavior):
```markdown
# Production perf checklist
- [ ] `php artisan optimize` (config:cache + route:cache) and `view:cache`
- [ ] `APP_DEBUG=false` in production .env
- [ ] `npm run build` and deploy `public/build` (fingerprinted assets)
- [ ] Confirm web server serves `public/build/assets/*` with long-lived immutable cache headers
- [ ] Re-run `php artisan config:clear` before any config edit; re-cache after
```

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/specs/2026-07-10-frontend-performance-optimization-design.md docs/superpowers/plans/perf-production-checklist.md
git commit -m "docs(perf): final before/after measurements and production checklist"
```

---

## Self-Review

**Spec coverage:**
- §4 bundle code-split → Tasks 1–4. ✅
- §5 wire:navigate → Tasks 5–6. ✅
- §6 lazy panels + poll → Tasks 7–8. ✅
- §7 ML health off render path → Tasks 9–10. ✅
- §8 production hardening + §9 measurement → Task 11. ✅
- §3 already-optimal items → intentionally no tasks (verified out of scope). ✅

**Type/name consistency:** `window.OSCA.charts()` / `window.OSCA.maps()` defined in Task 2, consumed in Tasks 3–4. `window.__oscaMaps` array defined and consumed in Task 5. `ml_nav_health` cache key + `/ml/nav-health` route defined in Task 9, consumed in Task 10. `loadCharts`/`loadMaps` defined in Task 1, imported in Task 2. Consistent.

**Placeholder scan:** No TBD/TODO; every code step shows concrete code; verification steps give exact commands and expected output. The report-blade wraps in Task 3 Step 3 and the senior-map lines in Task 4 Step 2 say "adapt to what is present" because those exact surrounding lines vary — the transformation rule and the target pattern are fully specified, which is the intended instruction.

**Risk ordering:** Lowest-risk, highest-value first (Task 1–4 code-split), higher-risk SPA nav gated behind it (Task 5–6), then progressive enhancements (7–10), measurement last (11).
