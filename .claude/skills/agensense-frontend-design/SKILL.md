---
name: agensense-frontend-design
description: Use when running /frontend-design on AgeSense OSCA — provides the project's Tailwind token set, component library, audience constraints, and design language so frontend-design outputs fit the existing system without re-deriving context.
---

# Frontend Design: AgeSense OSCA Context

## Stack and constraints

- **Templates:** Laravel 11 Blade; all components are `.blade.php`
- **CSS:** Tailwind CSS 3.4 JIT + component layer in `resources/css/app.css`
- **No** inline `<style>` globals — scoped `<style>` blocks on individual pages are acceptable for page-specific animations (see login page)
- **Login page exception:** No Alpine JS; button loading state must use plain JS. Every other view has Livewire 3 available.

## Audience and tone

Government LGU staff (non-technical). The design must read as trustworthy, official, and dignified — not startup, not corporate SaaS. Think prestigious public-health publication: authoritative yet warm.

**Jargon rule:** Never show ML/statistics terms in user-visible text. "K=4", "cluster", "kmeans", "K-means", "algorithm" must not appear in labels, headings, tooltips, or badges.

## Typography

| Role | Family | Token |
|---|---|---|
| Body / UI | Plus Jakarta Sans | `font-sans` (default) |
| Headings / editorial | Source Serif 4 | `font-serif` / `font-display` |
| Data / mono | JetBrains Mono | `font-mono` |

Google Fonts load string (login page):
```
Plus+Jakarta+Sans:wght@400;500;600;700|Inter+Tight:wght@400;500;600;700|Source+Serif+4:opsz,wght@8..60,400;8..60,500;8..60,600;8..60,700
```

Letter-spacing utilities: `tracking-snug` (−0.02 em), `tracking-tightish` (−0.015 em).

## Color system

All tokens live in `tailwind.config.js`. Quick lookup:

```
navy.{50–900}     — institutional chrome (dark sidebar, left panel, gov band)
accent.{50–950}   — action color (buttons, links, focus rings) — blue ramp
forest.*          — exact alias of accent.* (legacy; 228 usages; interchangeable)
paper.DEFAULT     — #fbfaf6  page bg
paper.2           — #f6f4ec  recessed panels
paper.rule        — #e8e4d6  borders / dividers
ink.{100–900}     — text on paper
cluster.{1–4}     — care-group palette (#2ecc71 / #3498db / #f39c12 / #e74c3c)
low/moderate/high/critical — risk badge colors (each .50/.100/.500/.700)
```

Safelist patterns (always generated even if not in templates):
- `(bg|text|border)-(low|moderate|high|critical|info|forest|accent)-(50|100|500|700)`
- `(bg|text|border)-navy-(50|100|200|300|400|500|600|700|800|900)`
- `badge-cluster-{1-4}`, `cluster-swatch-{1-4}`

## Layout pattern: two-panel pages (login, onboarding)

```
[ navy left panel (lg:flex hidden) ] [ white/paper right panel ]
```

Left panel structure: `bg-navy-900` + layered decorative glows (dot grid, top-right radial, bottom-left ambient accent) + brand lockup (top) + editorial headline + stat band (bottom). Use `pointer-events-none` on all decorative elements.

Right panel: `bg-paper`, centered form/content, max-w-sm container. Add a short `h-[3px] bg-accent-500` rule as visual anchor above primary headings.

## Entrance animations (login / full-page layouts)

Standard pattern — page-specific `<style>` block:
```css
@keyframes ageReveal {
    from { opacity: 0; transform: translateY(14px); filter: blur(3px); }
    to   { opacity: 1; transform: none; filter: blur(0); }
}
.reveal   { animation: ageReveal 0.65s cubic-bezier(0.22, 1, 0.36, 1) both; }
.reveal-2 { animation-delay: 0.09s; }
.reveal-3 { animation-delay: 0.18s; }
@media (prefers-reduced-motion: reduce) {
    .reveal, .reveal-2, .reveal-3 { animation: none; }
}
```

The `app.css` global system uses `.rise-in` + `.rise-{1-4}` for Livewire pages.

## Care group indicators

Use 10 px circles (`border-radius: 9999px`) with a soft ring, not bars:
```html
<div class="care-dot" style="background: #2ecc71"></div> <!-- Thriving  -->
<div class="care-dot" style="background: #3498db"></div> <!-- Stable    -->
<div class="care-dot" style="background: #f39c12"></div> <!-- At-Risk   -->
<div class="care-dot" style="background: #e74c3c"></div> <!-- Priority  -->
```
```css
.care-dot { width:10px; height:10px; border-radius:9999px; flex-shrink:0;
            opacity:0.9; box-shadow:0 0 0 2px rgba(255,255,255,0.10); }
```

Stat band label: "Care Groups" (not "Health Groups", never "K=4").

## Component classes — reuse before writing new CSS

```
.card / .card-head / .card-title / .card-body — card shell
.kpi / .kpi-label / .kpi-value / .kpi-rule    — stat tile
.badge / .badge-cluster-{1-4} / .badge-{low|moderate|high|critical}
.btn / .btn-primary / .btn-secondary / .btn-ghost / .btn-danger
.btn-spinner                                  — inline loading spinner
.form-input / .form-select                    — form fields
.eyebrow                                      — 10.5 px uppercase label (use sparingly)
.gov-band / .gov-band-title                   — top republic strip
.masthead-name / .masthead-office             — brand lockup text
.nav-link / .nav-link-active                  — sidebar nav
.segmented                                    — pill toggle
.bar / .bar-fill / .bar-fill-{low|moderate|high|critical}
```

## Dark mode

A `.dark` class on `<html>` triggers dark remaps defined in `app.css`. All component classes have dark variants. When building new components, check that `bg-white`, `bg-paper`, `border-paper-rule`, `text-ink-*`, and `btn-*` all have `.dark` overrides before shipping.

## Dos and don'ts

| Do | Don't |
|---|---|
| Use `font-serif` for editorial headings | Use system-ui or Arial |
| Use `text-balance` on h1–h3 | Let long headings widow on narrow viewports |
| Use navy for chrome/authority surfaces | Use navy for action elements |
| Use `accent`/`forest` for interactive elements | Use green (`low`) for buttons |
| Use cluster colors for care-group dots only | Use cluster colors for general decorative purposes |
| Write plain-language labels ("Care Groups") | Show "K=4", "kmeans", "cluster ID" to users |
| Apply `pointer-events-none` to decorative glows | Let decorative elements intercept clicks |
