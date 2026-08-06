---
name: osca-performance
description: Use when the AgeSense OSCA app feels slow, laggy, or janky — especially "fast on high-end machines but slow on devices without a GPU", slow dashboard/chart rendering, heavy page loads, sluggish wire:navigate clicks, or any frontend rendering performance work. Covers the global asset bundle, Chart.js pitfalls, CSS motion cost, dashboard query caching, and the persisted sidebar/topbar + wire:navigate race fix. Consult this before adding libraries, animations, or "optimizing" rendering, even if the user doesn't say the word "performance".
---

# OSCA Performance & Rendering

How AgeSense OSCA performs, and where its render cost lives. The recurring symptom
is **"fast with a GPU, laggy without one"** — that almost always means
**client-side rendering cost** (animations, canvas/chart compositing, repaints),
not server speed. A slow DB query is slow on every device; a GPU-dependent symptom
is a frontend symptom. **Diagnose before changing:** Chrome DevTools → Performance,
with **CPU throttling 6×** to emulate a GPU-less device, and watch for long
main-thread tasks during load / filter changes.

All paths are relative to the Laravel app root
(`...\osca-system\osca-system\`). Frontend assets are gitignored — **`npm run
build` + hard refresh** after any Blade/CSS/JS change or nothing takes effect.

---

## The global asset bundle

`resources/js/app.js` no longer statically imports Chart.js or Leaflet — both are
**code-split** via `resources/js/loaders.js`'s memoized `loadCharts()` /
`loadMaps()`, each a dynamic `import()` fetched only the first time a page
actually calls `OSCA.charts()` / `OSCA.maps()`. Both loaders set `window.Chart` /
`window.L` for Blade scripts to use, matching the pre-split global contract, so
call sites didn't need to change — they just need to `await` the loader before
using the global. `app.js` itself (sidebar/topbar/navigation logic, `window.OSCA`
utilities, the double-submit guard, etc.) is ~55 KB.

- **Charts** are used on the dashboard, cluster analysis, risk report.
- **Leaflet/maps** are used on the GIS report (`reports/gis.blade.php`) and the
  profile mini-map. `gis.blade.php`'s inline script must `await OSCA.maps()`
  (or otherwise wait on the loader's promise) before touching `window.L` — it can
  no longer assume Leaflet is already loaded by the time it runs, unlike before
  the split. Verify the GIS page fully works before touching this loader.

### Chart.js double-load trap (do not reintroduce)
Chart.js must be loaded **once**, via `OSCA.charts()` (which memoizes the
dynamic import — repeat calls resolve the same cached promise, not a second
fetch). The layout (`resources/views/layouts/app.blade.php`) **must not** add a
`<script src="...cdn.../chart.js...">` tag, and no page should statically
`import 'chart.js'` — either would be a second, render-blocking copy loaded on
top of the lazy one.

**Load-order note:** dashboard chart scripts live in `@push('scripts')` and only
*register* listeners at parse time; the actual `new Chart()` must run after
`OSCA.charts()`'s promise resolves (typically inside a `livewire:navigated` /
`livewire:updated` / `DOMContentLoaded` handler that awaits it first) — `window.Chart`
does not exist until that promise settles. Don't assume it's already set just
because a previous page happened to load it first.

---

## Chart animation cost
Chart.js ships a 1000 ms entry animation. The dashboard has **six** charts
(`resources/views/dashboard.blade.php`) that otherwise animate simultaneously and
**re-animate on every filter change** — the worst CPU spike on a weak device.

- A short global default is set in `app.js`:
  `Chart.defaults.animation.duration = 300`. This covers every chart project-wide
  (including cluster analysis).
- Per-chart `animation: { duration }` overrides in `dashboard.blade.php` are kept
  short (≈300–400 ms). Don't push them back up to 700–900 ms.
- Charts keep their type, data, colors, tooltips, and click-to-filter — only the
  animation duration is trimmed.

---

## CSS motion cost
`resources/css/app.css` — keep decorative motion cheap, especially anything
multiplied across many elements:

- **Don't animate `box-shadow`.** Animating a multi-layer shadow recomputes blur
  every frame; across ~20 KPI/cards that janks GPU-less devices. `.card-lift` /
  `.kpi` transition only `transform` (the lift) — the hover shadow snaps in.
- **Avoid `transition-all`.** It animates every property, including layout ones.
  Use `transition` (Tailwind's curated set: colors/transform/shadow/opacity) or a
  specific `transition-colors` / `transition-[width]`.
- **`.bar-fill`** animates `width` only (50+ bars on the dashboard).
- **Avoid `filter: blur()` in animations** (e.g. the login reveal) — it's
  software-rendered on older GPUs. Opacity + translate is cheap.
- A `prefers-reduced-motion` block already collapses animation for users who ask;
  keep new keyframes/transitions covered by it.

### Fonts
The layout loads Google Fonts (Plus Jakarta Sans, Source Serif 4, JetBrains Mono)
render-blocking with `display=swap`. Only load weights actually used — verify with
a usage grep before adding/removing a weight (a used-but-missing weight gets
faux-synthesized; an unused loaded weight is wasted bytes).

---

## Backend interaction lag (server-side, not the GPU symptom)
The dashboard (`app/Livewire/Dashboard/MainDashboard.php`) re-runs ~10 aggregate
queries on **every** render (load + each filter change).

- The shared `MAX(id) per active senior` subquery (`latestMlIds()`, the
  denominator of several aggregates) is cached: `Cache::remember(
  MainDashboard::LATEST_IDS_CACHE_KEY, 5 min, …)`. It's filter-independent (one
  global key) and self-heals on a 5-min TTL — matching the GIS GeoJSON cache
  convention. Filters apply on top via `whereIn`, so correctness is preserved.
- Composite indexes for the hot aggregates live in
  `database/migrations/2026_06_28_000001_add_dashboard_aggregate_indexes.php`
  (`qol_surveys(status, senior_citizen_id)`,
  `recommendations(status, senior_citizen_id)`). `ml_results` already has
  `(senior_citizen_id, id)` and accessibility metrics already index
  `senior_citizen_id` — check existing indexes before adding more.
- Livewire text filters should be debounced (`wire:model.live.debounce.300ms`).
  **Selects** fire once per discrete pick — don't debounce them; it only adds lag.

---

## GIS map
The map's render hot spots (canvas markers, boundary-mask raster clipping,
pan-vs-zoom repaint) are **already optimized** — see the `gis-module` skill. Don't
redo that work; profile to confirm a regression before adding new machinery.

---

## Navigation architecture (wire:navigate)

Every page shares the sidebar + topbar from `resources/views/layouts/app.blade.php`.
Two things depend on each other here — read both before touching either.

### The persisted shell
`<aside>` (sidebar), `<header>` (topbar), and `<x-ml-service-guard />` are wrapped
in Livewire `@persist(...)` blocks. This means their DOM is built **once** and
reused across every `wire:navigate` — not destroyed/recreated per click — which is
what stops the topbar's Services-status fetch and ~20 role-gated sidebar links from
re-parsing and re-binding on every single navigation. `ml-service-guard.blade.php`
persists its own root div *inside* its `@auth`/`@unless(routeIs('ml.status'))`
guard, not around the `<x-ml-service-guard />` call site — the component renders
nothing on `/ml/status`, and persisting an unconditional wrapper around it would
let that page's empty state permanently overwrite the real one on the next
navigation. Keep that nesting if you touch this file.

The trade-off: anything server-rendered *inside* a persisted element goes stale
after the first load, since it stops receiving fresh HTML on navigation. Three
things that used to be `request()->routeIs(...)`/`@yield` server logic are now
synced client-side instead (`resources/js/app.js`, inside the `alpine:init`
listener):
- **Active nav link** — an `Alpine.reactive({ path })` store plus a
  `$navActive(path, prefix=false)` magic method, updated on every
  `livewire:navigated`. This is Livewire's own documented pattern for exactly this
  problem (see their `SupportNavigate` test fixture `navbar-sidebar.blade.php`).
- **Page title** — `<head>` is *not* persisted and is always merged fresh
  (Livewire's `mergeNewHead`), so a `<meta name="page-title">` tag carries the
  current `@yield('page-title')` value; JS copies it into the persisted `<h1
  id="topbar-page-title">` on navigation.
- **Topbar search value** — derived straight from `location.pathname`/`search`,
  no meta tag needed.

If you add new server-rendered state inside the persisted sidebar/topbar (a badge,
a count, anything not purely a function of the URL), it needs the same treatment —
otherwise it'll silently freeze at whatever it was on first load.

### The wire:navigate race (fixed, don't reintroduce)
Livewire 3.8's `wire:navigate` has no `AbortController` and no "is this still the
current destination" check (confirmed by reading
`vendor/livewire/livewire/dist/livewire.esm.js`) — click Dashboard, then Senior
Records before Dashboard's response lands, and whichever fetch resolves *second*
wins, even if that's the page you already navigated away from.
`resources/js/navigation.js` fixes this by tracking the most recent
`alpine:navigate` intent and re-issuing `Alpine.navigate(...)` if a landing
doesn't match it — but only when a mismatch has already been corrected once for
that exact intent does it stop retrying, since a link that legitimately
server-redirects would otherwise loop forever against this check. Don't "simplify"
this by dropping that guard, and don't try to fix the race by aborting the
in-flight `fetch()` instead — Livewire's `prefetchHtml()` registers its cache
entry *before* the fetch starts and only clears it from the fetch's own
`.then()`; an aborted fetch leaves that entry permanently unresolved, hanging
that link until a hard reload.

Sidebar and pagination links use `wire:navigate.hover` (prefetch after ~60ms of
hover), not bare `wire:navigate` — keep that modifier on new nav links too, it's
the cheapest win here.

---

## Principles
- **Measure first.** Reproduce with DevTools Performance + 6× CPU throttle; find
  the long task before touching code.
- **Preserve functionality.** These are render-cost trims — same data, same
  behavior, same numbers; just lighter to draw.
- **Per-element cost multiplies.** A cheap-looking effect (shadow, blur,
  `transition-all`) becomes expensive across 20–50 cards/bars/charts.
- **Verify:** `npm run build`, then exercise the page on a throttled profile;
  confirm charts/filters/map still behave. See the `run-osca-system` skill for the
  full build → migrate → test flow.
- **Verify navigation changes specifically:** walk every sidebar link (correct
  active state, title, dark mode, sidebar collapse state all survive), and click
  two different links in rapid succession to confirm the second one wins.
