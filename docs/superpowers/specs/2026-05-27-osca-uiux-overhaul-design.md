# OSCA AgeSense UI/UX Overhaul — Design Spec

**Date:** 2026-05-27
**Approach:** B — Component-First Refactor
**Status:** Approved

---

## 1. Problem Statement

A systematic audit of the AgeSense OSCA system (Laravel 11 + Livewire 3 + Tailwind CSS + Alpine.js) found 22 issues across four priority tiers. The core design system (`app.css` + `tailwind.config.js`) is well-built, but several pages bypass it, flash messages are silently dropped, accessibility is incomplete, and missing shared components cause repeated inconsistencies across pages.

**Top 5 critical issues:**
1. `@yield('page-subtitle')` is set on every page but the layout never renders it — subtitles are silently dropped system-wide
2. Flash messages render as inline `.badge` chips in the topbar instead of dismissible notifications
3. Delete QoL Survey modal uses `style="background:#ffffff !important"` and `onmouseover`/`onmouseout` inline handlers — breaks dark mode entirely
4. Profile survey (`profile-survey.blade.php`) uses a completely different color palette (`teal-600`, `slate-*`, `emerald-*`, `purple-600`) instead of design tokens
5. Modals lack `role="dialog"`, `aria-modal`, and focus trapping — WCAG 2.2 failure

---

## 2. Approach: Component-First Refactor

Build five missing shared components first, then refactor all pages to use them. Every issue is resolved structurally — not just patched. This creates a maintainable foundation for future work.

**Constraints (non-negotiable):**
- Do not break Livewire bindings or wire directives
- Do not rename database fields or change ML logic
- Do not change the overall layout structure (sidebar + topbar + main area)
- Do not add new routes or controllers — UI-only changes

---

## 3. New Shared Components

### 3.1 `x-page-header`

**Purpose:** Replaces the broken `@yield('page-subtitle')` pattern. Every page currently sets `@section('page-subtitle', '...')` but the layout never renders it, so subtitles are lost. This component renders them correctly in-content.

**Props:**
- `title` (required) — main page heading
- `subtitle` (optional) — descriptive line below heading
- `eyebrow` (optional) — small label above heading (e.g. "Senior Profile")

**Placement:** Inside `@section('content')` on every page, at the top. The broken `@yield('page-subtitle')` is removed from the layout.

**Markup pattern:**
```blade
<x-page-header title="Senior Profiles" subtitle="Browse and manage registered seniors" />
```

---

### 3.2 `x-breadcrumb`

**Purpose:** Standardized breadcrumb navigation with correct ARIA. Currently each page either has custom breadcrumb HTML or no breadcrumb at all.

**Props:**
- `links` — array of `['label' => string, 'href' => string]` objects; last item renders as `aria-current="page"` with no link

**Placement:** Above `<x-page-header>` on detail pages (show, edit, survey, recommendations).

**Markup pattern:**
```blade
<x-breadcrumb :links="[
    ['label' => 'Dashboard', 'href' => route('dashboard')],
    ['label' => 'Seniors', 'href' => route('seniors.index')],
    ['label' => $senior->full_name],
]" />
```

---

### 3.3 `x-toast`

**Purpose:** Replaces the broken flash message system. Flash currently renders as `.badge` chips inside the topbar right-area (layout lines 286–290). This component renders as a fixed-position overlay with auto-dismiss and ARIA live region.

**Props:**
- No props required — reads session flash automatically (`success`, `error`, `warning`, `info`)
- Can also accept explicit `type` and `message` props for inline use

**Behavior:**
- Fixed position: bottom-right, `z-50`
- Auto-dismisses after 5 seconds (Alpine.js timer)
- `role="status"`, `aria-live="polite"` for screen readers
- Manual dismiss button included

**Placement:** Single instance in `layouts/app.blade.php`, just before `</body>`. The existing topbar badge flash loop is removed.

---

### 3.4 `x-alert`

**Purpose:** Inline contextual alert for form feedback, banners, and confirmation messages. Replaces the ad-hoc `bg-purple-600` success banner and `bg-red-50` error divs in `profile-survey.blade.php`.

**Props:**
- `type` — `success` | `error` | `warning` | `info` (default: `info`)
- `title` (optional) — bold heading line
- Default slot — body content

**Colors:** Uses design tokens: `low-*` for success, `high-*` for error, `moderate-*` for warning, `info-*` for info. Full dark mode support.

---

### 3.5 `x-empty-state`

**Purpose:** Standardized "no data" treatment for tables, chart panels, and lists. Currently inconsistent or absent.

**Props:**
- `title` (required)
- `description` (optional)
- `icon` — `inbox` | `chart` | `users` | `document` (default: `inbox`)
- `action` slot (optional) — for CTA button/link

**Placement:** Inside cards/tables wherever data may be empty.

---

## 4. Page Changes

### Layout (`layouts/app.blade.php`)
- **Remove** flash badge loop (lines 286–290) from topbar right-area
- **Add** `<a href="#main-content" class="sr-only focus:not-sr-only ...">Skip to content</a>` as first child of `<body>`
- **Add** `id="main-content"` to existing `<main>` element (line 343)
- **Add** `<x-toast />` before `</body>`
- **Remove** the unused `@yield('page-subtitle')` reference if it exists anywhere in the layout

### Dashboard (`livewire/dashboard/main-dashboard.blade.php`)
- Add `<x-page-header title="Dashboard" subtitle="OSCA senior citizen overview" />`
- Add `aria-label` to all Chart.js `<canvas>` elements
- Add visually-hidden `<table>` alternatives for doughnut charts (screen reader fallback)
- Replace ad-hoc empty-state divs with `<x-empty-state />`

### Senior List (`seniors/index.blade.php`)
- Add `<x-breadcrumb>` and `<x-page-header>`
- **Fix per-row archive modal duplication**: move the inline `x-data="{ archiveOpen: false }"` modal out of the `@foreach` loop; define one modal outside the table driven by Alpine `seniorIndex()` store which already manages `archiveOpen`. The row button sets `archiveSeniorId` and opens the modal.

### Senior Profile (`seniors/show.blade.php`)
- Add `<x-breadcrumb>` and `<x-page-header>`
- **Delete QoL Survey modal** (lines 445–480): remove all `style=""` attributes and `onmouseover`/`onmouseout` handlers; replace with Tailwind utility classes and Alpine hover state (`:class`)
- **Remove** the `@if ($ml->critical_flag)` badge block (lines 204–207) — CRITICAL level was removed from the system; the badge is stale
- Add `role="dialog"`, `aria-modal="true"`, `aria-labelledby`, and Alpine `x-trap` focus trap to all modals (requires `@alpinejs/focus` plugin — check if installed; if not, install via npm)

### Profile Survey (`livewire/surveys/profile-survey.blade.php`)
This is the largest single-file fix. The component uses a completely foreign color palette.

| Replace | With |
|---|---|
| `teal-600` | `forest-700` |
| `bg-white border border-slate-200 rounded-xl` | `<x-card>` |
| `bg-emerald-50 border-emerald-200` | `<x-alert type="success">` |
| `bg-red-50 border-red-200` | `<x-alert type="error">` |
| `bg-purple-600` (success CTA) | `btn btn-primary` |
| `slate-100/200` label/border colors | `ink-200/300` |

### QoL Survey Form (`livewire/surveys/qol-survey-form.blade.php`)
- Add `<x-breadcrumb>` and `<x-page-header>` — the form content itself is already design-system-consistent

### Recommendations (`recommendations/show.blade.php`)
- Add `<x-breadcrumb>` and `<x-page-header>`
- Replace hard-coded "Back to profile" `btn btn-ghost` link with proper breadcrumb (keep the button too for UX, just ensure breadcrumb is also present)
- Replace ad-hoc empty state (lines 107–114) with `<x-empty-state icon="document" title="No recommendations yet." ...>`

### Risk Report (`reports/risk.blade.php`)
- Add `<x-page-header title="Risk Report" />`
- Add `@media print` CSS (via `@push('styles')` stack): hide topbar, sidebar, filter bar, action buttons; show formal print header; set margins and page-break rules for KPI cards and tables

### `x-risk-badge` component
- Replace `⚠` Unicode character with an SVG icon + `aria-hidden="true"` + `<span class="sr-only">urgent</span>`

### Minor fixes
- `help/index.blade.php` line 34: fix typo "OSCA AgeS ense" → "OSCA AgeSense"
- `ml/status.blade.php`: replace raw `<div class="w-3 h-3 rounded-full">` with `.status-dot` CSS class
- `seniors/pdf.blade.php`: replace hardcoded `#0d9488` (teal) with forest palette hex (`forest-700` = `#2d6a4f`)

---

## 5. Accessibility (WCAG 2.2)

All pages receive:
- Skip-to-content link (in layout)
- `<main id="main-content">` landmark (in layout)
- Chart canvas `aria-label` attributes
- Accessible data table alternatives for charts
- Modal `role="dialog"`, `aria-modal`, `aria-labelledby`, focus trap
- Breadcrumb `aria-label="Breadcrumb"` + `aria-current="page"`
- Toast `role="status"`, `aria-live="polite"`
- Risk badge urgent indicator replaces emoji with labelled SVG

---

## 6. Print Layout

`reports/risk.blade.php` gains a print-optimized stylesheet pushed via `@push('styles')`:
- Hides: sidebar, topbar, filter form, action buttons, print button
- Shows: formal document header with LGU name, report date, page reference
- Page margins: 20mm
- KPI cards: `display: block; page-break-inside: avoid`
- Table rows: `page-break-inside: avoid`

---

## 7. Success Criteria

- [ ] Flash messages appear as toast overlays (not topbar badges)
- [ ] Page subtitles are visible on all pages
- [ ] Dark mode works on all modals (no `!important` overrides)
- [ ] Profile survey uses forest/ink palette throughout
- [ ] Skip-to-content link functional on keyboard
- [ ] Charts have accessible text alternatives
- [ ] Print layout hides navigation chrome
- [ ] All emoji indicators replaced with labelled SVGs
- [ ] No Livewire binding breakage (wire:model, wire:click all intact)
- [ ] Typo on help page fixed
- [ ] `.status-dot` class used in ML status page
