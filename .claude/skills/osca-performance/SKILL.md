---
name: osca-performance
description: Use when the AgeSense OSCA app feels slow, laggy, or janky — especially "fast on high-end machines but slow on devices without a GPU", slow dashboard/chart rendering, heavy page loads, or any frontend rendering performance work. Covers the global asset bundle, Chart.js pitfalls, CSS motion cost, and dashboard query caching. Consult this before adding libraries, animations, or "optimizing" rendering, even if the user doesn't say the word "performance".
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

## The global asset bundle (every page pays for it)

`resources/js/app.js` statically imports **Chart.js (`chart.js/auto`), Leaflet,
markercluster, leaflet.heat**, and their CSS into one bundle loaded on *every*
page (~440 KB). It sets `window.Chart` and `window.L` for Blade scripts to use.

- **Charts** are used on the dashboard, cluster analysis, risk report.
- **Leaflet/maps** are used on **only** the GIS report (`reports/gis.blade.php`)
  and the profile mini-map — yet ship everywhere. Code-splitting them off the
  global bundle is the main remaining win, but it's **risky**: `gis.blade.php`'s
  inline script depends on `window.L` existing before it runs, so any split must
  guarantee load order. Verify the GIS page fully works before shipping such a
  change.

### Chart.js double-load trap (do not reintroduce)
Chart.js must be loaded **once**. It is already provided by the bundle
(`window.Chart`). The layout (`resources/views/layouts/app.blade.php`) **must not**
also add a `<script src="...cdn.../chart.js...">` tag — that was a second,
render-blocking ~200 KB copy on every page. If you need Chart.js on a page, use the
bundled `window.Chart`; don't add a CDN tag.

**Load-order note:** dashboard chart scripts live in `@push('scripts')` and only
*register* listeners at parse time; the actual `new Chart()` runs on
`livewire:navigated` / `livewire:updated` / `DOMContentLoaded`, all after the
deferred `app.js` module sets `window.Chart`. So relying solely on the bundle is
safe — but keep new chart code inside those events, not inline-at-parse.

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
