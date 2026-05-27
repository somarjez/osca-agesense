# OSCA AgeSense UI/UX Overhaul — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all 22 UI/UX issues found in the OSCA AgeSense audit by building five shared Blade components first, then refactoring each page to use them.

**Architecture:** Component-First Refactor (Approach B). New Blade components (`x-toast`, `x-page-header`, `x-breadcrumb`, `x-alert`, `x-empty-state`) are created in `resources/views/components/`, then all pages are updated to use them. Critical bugs (modal inline styles, dead subtitle yield, foreign color palette in profile-survey) are fixed as part of each page's update.

**Tech Stack:** Laravel 11, Livewire 3, Tailwind CSS (custom design tokens), Alpine.js, Heroicons (via Blade component package)

**Design tokens to know:**
- Success/good = `low-*` (e.g. `bg-low-50`, `text-low-700`, `border-low-200`)
- Error/danger = `high-*`
- Warning = `moderate-*`
- Info = `info-*`
- Brand = `forest-*` (forest-700 is primary)
- Text = `ink-*` (ink-900 = darkest, ink-400 = muted)
- Background = `paper` (default), `paper-2` (slight shade), `paper-rule` (borders)
- Design system classes defined in `resources/css/app.css`: `.card`, `.card-head`, `.card-title`, `.card-body`, `.btn`, `.btn-primary`, `.btn-ghost`, `.badge`, `.badge-high`, `.badge-moderate`, `.badge-low`, `.badge-neutral`, `.kpi`, `.bar`, `.nav-link`, `.status-dot-ok`, `.status-dot-warn`, `.status-dot-err`

**Before you start:** All files are in `osca-system/resources/views/`. Run `npm run dev` (Vite) in a terminal so CSS changes hot-reload. The app runs via `php artisan serve`. Do NOT run database migrations or touch ML service code.

---

## File Map

**Create:**
- `resources/views/components/toast.blade.php` — fixed-position flash notification with ARIA
- `resources/views/components/page-header.blade.php` — page title + subtitle block
- `resources/views/components/breadcrumb.blade.php` — accessible breadcrumb nav
- `resources/views/components/alert.blade.php` — inline contextual alert
- `resources/views/components/empty-state.blade.php` — "no data" placeholder block

**Modify:**
- `resources/views/layouts/app.blade.php` — skip link, main id, remove topbar flash badges, add x-toast
- `resources/views/components/risk-badge.blade.php` — replace ⚠ emoji with labelled SVG
- `resources/views/seniors/index.blade.php` — x-page-header, x-breadcrumb, fix per-row archive modal
- `resources/views/seniors/show.blade.php` — x-page-header, x-breadcrumb, fix delete modal, remove critical_flag badge, modal ARIA
- `resources/views/livewire/surveys/profile-survey.blade.php` — replace entire foreign color palette with design tokens
- `resources/views/livewire/surveys/qol-survey-form.blade.php` — add x-page-header, x-breadcrumb
- `resources/views/livewire/dashboard/main-dashboard.blade.php` — add x-page-header, chart ARIA, x-empty-state
- `resources/views/recommendations/show.blade.php` — add x-page-header, x-breadcrumb, x-empty-state
- `resources/views/reports/risk.blade.php` — add x-page-header, print CSS via @push('styles')
- `resources/views/help/index.blade.php` — typo fix
- `resources/views/ml/status.blade.php` — use .status-dot classes
- `resources/views/seniors/pdf.blade.php` — replace #0d9488 with forest palette

---

## Task 1: `x-toast` component

**Files:**
- Create: `resources/views/components/toast.blade.php`

The existing flash system loops over session keys in the topbar and renders `.badge` chips (layout lines 286–290). This is wrong — flash messages disappear if the user doesn't look at the topbar, and they break layout. This component renders a fixed-position overlay that auto-dismisses.

- [ ] **Step 1: Create the toast component**

Create `resources/views/components/toast.blade.php`:

```blade
@props(['type' => null, 'message' => null])

@php
    // Auto-detect from session if not passed as props
    if (!$type) {
        if (session()->has('success')) { $type = 'success'; }
        elseif (session()->has('error'))   { $type = 'error'; }
        elseif (session()->has('warning')) { $type = 'warning'; }
        elseif (session()->has('info'))    { $type = 'info'; }
    }
    if (!$message) {
        $message = session('success') ?? session('error') ?? session('warning') ?? session('info');
    }
    $styles = [
        'success' => 'bg-low-50 border-low-200 text-low-800 dark:bg-low-900/30 dark:border-low-700 dark:text-low-200',
        'error'   => 'bg-high-50 border-high-200 text-high-800 dark:bg-high-900/30 dark:border-high-700 dark:text-high-200',
        'warning' => 'bg-moderate-50 border-moderate-200 text-moderate-800 dark:bg-moderate-900/30 dark:border-moderate-700 dark:text-moderate-200',
        'info'    => 'bg-info-50 border-info-200 text-info-800 dark:bg-info-900/30 dark:border-info-700 dark:text-info-200',
    ];
    $iconPaths = [
        'success' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'error'   => 'M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z',
        'warning' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z',
        'info'    => 'M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z',
    ];
@endphp

@if ($message && $type)
<div
    x-data="{ show: true }"
    x-show="show"
    x-init="setTimeout(() => show = false, 5000)"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-y-2"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 translate-y-2"
    role="status"
    aria-live="polite"
    aria-atomic="true"
    class="fixed bottom-5 right-5 z-50 flex items-start gap-3 max-w-sm w-full rounded-2xl border px-4 py-3 shadow-lg {{ $styles[$type] ?? $styles['info'] }}"
    x-cloak
>
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
         stroke-width="1.5" stroke="currentColor"
         class="w-5 h-5 flex-shrink-0 mt-0.5" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $iconPaths[$type] ?? $iconPaths['info'] }}" />
    </svg>
    <p class="text-sm leading-snug flex-1">{{ $message }}</p>
    <button @click="show = false"
            class="flex-shrink-0 opacity-50 hover:opacity-100 transition-opacity ml-1"
            aria-label="Dismiss notification">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
             stroke-width="2" stroke="currentColor" class="w-4 h-4" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
    </button>
</div>
@endif
```

- [ ] **Step 2: Verify the component renders correctly**

Open `resources/views/components/toast.blade.php` and confirm there are no syntax errors. The `@php` block must close with `@endphp`, and the `@if` must close with `@endif`.

- [ ] **Step 3: Commit**

```bash
git add resources/views/components/toast.blade.php
git commit -m "feat: add x-toast component with ARIA live region and auto-dismiss"
```

---

## Task 2: `x-page-header` component

**Files:**
- Create: `resources/views/components/page-header.blade.php`

The `@yield('page-subtitle')` in the layout is broken — it's never rendered. Every page silently loses its subtitle. This component fixes the pattern by letting pages render titles and subtitles directly inside their content area.

- [ ] **Step 1: Create the component**

Create `resources/views/components/page-header.blade.php`:

```blade
@props(['title', 'subtitle' => null, 'eyebrow' => null])
<div class="mb-6">
    @if ($eyebrow)
        <p class="eyebrow mb-1.5">{{ $eyebrow }}</p>
    @endif
    <h1 class="text-xl font-bold text-ink-900 dark:text-ink-100 leading-tight tracking-tight">
        {{ $title }}
    </h1>
    @if ($subtitle)
        <p class="text-sm text-ink-500 dark:text-ink-400 mt-0.5 leading-relaxed">{{ $subtitle }}</p>
    @endif
</div>
```

The `.eyebrow` class is already defined in `app.css` — it renders as a small uppercase label.

- [ ] **Step 2: Commit**

```bash
git add resources/views/components/page-header.blade.php
git commit -m "feat: add x-page-header component, replaces broken page-subtitle yield"
```

---

## Task 3: `x-breadcrumb` component

**Files:**
- Create: `resources/views/components/breadcrumb.blade.php`

- [ ] **Step 1: Create the component**

Create `resources/views/components/breadcrumb.blade.php`:

```blade
@props(['links' => []])
@if (count($links) > 1)
<nav aria-label="Breadcrumb" class="mb-3">
    <ol class="flex items-center gap-1 text-[12.5px]">
        @foreach ($links as $i => $link)
            @if ($i < count($links) - 1)
                <li>
                    <a href="{{ $link['href'] }}"
                       class="text-ink-400 hover:text-ink-700 dark:text-ink-500 dark:hover:text-ink-300 transition-colors">
                        {{ $link['label'] }}
                    </a>
                </li>
                <li aria-hidden="true">
                    <span class="text-ink-300 dark:text-ink-600 px-0.5">/</span>
                </li>
            @else
                <li aria-current="page"
                    class="text-ink-700 dark:text-ink-300 font-medium truncate max-w-[200px]">
                    {{ $link['label'] }}
                </li>
            @endif
        @endforeach
    </ol>
</nav>
@endif
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/components/breadcrumb.blade.php
git commit -m "feat: add x-breadcrumb component with ARIA nav landmark"
```

---

## Task 4: `x-alert` component

**Files:**
- Create: `resources/views/components/alert.blade.php`

- [ ] **Step 1: Create the component**

Create `resources/views/components/alert.blade.php`:

```blade
@props(['type' => 'info', 'title' => null])
@php
    $styles = [
        'success' => 'bg-low-50 border-low-200 text-low-800 dark:bg-low-900/20 dark:border-low-700 dark:text-low-200',
        'error'   => 'bg-high-50 border-high-200 text-high-800 dark:bg-high-900/20 dark:border-high-700 dark:text-high-200',
        'warning' => 'bg-moderate-50 border-moderate-200 text-moderate-800 dark:bg-moderate-900/20 dark:border-moderate-700 dark:text-moderate-200',
        'info'    => 'bg-info-50 border-info-200 text-info-800 dark:bg-info-900/20 dark:border-info-700 dark:text-info-200',
    ];
@endphp
<div role="alert" class="rounded-xl border px-4 py-3 {{ $styles[$type] ?? $styles['info'] }}">
    @if ($title)
        <p class="font-semibold text-sm mb-0.5">{{ $title }}</p>
    @endif
    <div class="text-sm leading-relaxed">{{ $slot }}</div>
</div>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/components/alert.blade.php
git commit -m "feat: add x-alert component for inline contextual alerts"
```

---

## Task 5: `x-empty-state` component

**Files:**
- Create: `resources/views/components/empty-state.blade.php`

- [ ] **Step 1: Create the component**

Create `resources/views/components/empty-state.blade.php`:

```blade
@props(['title', 'description' => null, 'icon' => 'inbox'])
@php
    $iconPaths = [
        'inbox'    => 'M2.25 13.5h3.86a2.25 2.25 0 012.012 1.244l.256.512a2.25 2.25 0 002.013 1.244h3.218a2.25 2.25 0 002.013-1.244l.256-.512a2.25 2.25 0 012.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 00-2.15-1.588H6.911a2.25 2.25 0 00-2.15 1.588L2.35 13.177a2.25 2.25 0 00-.1.661z',
        'chart'    => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
        'users'    => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
        'document' => 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z',
    ];
@endphp
<div class="flex flex-col items-center justify-center py-12 text-center">
    <div class="w-12 h-12 rounded-2xl bg-paper-2 dark:bg-[#1e2823] flex items-center justify-center mb-3">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
             stroke-width="1.5" stroke="currentColor"
             class="w-6 h-6 text-ink-400 dark:text-ink-500" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="{{ $iconPaths[$icon] ?? $iconPaths['inbox'] }}" />
        </svg>
    </div>
    <p class="font-semibold text-ink-700 dark:text-ink-300 text-sm">{{ $title }}</p>
    @if ($description)
        <p class="text-ink-400 dark:text-ink-500 text-sm mt-1 max-w-xs leading-relaxed">{{ $description }}</p>
    @endif
    @isset($action)
        <div class="mt-4">{{ $action }}</div>
    @endisset
</div>
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/components/empty-state.blade.php
git commit -m "feat: add x-empty-state component for no-data placeholder blocks"
```

---

## Task 6: Layout — skip link, main landmark, remove topbar flash, add toast

**Files:**
- Modify: `resources/views/layouts/app.blade.php`

This task wires everything together. The topbar flash badge loop (lines 286–290) is removed, a skip link and `<x-toast />` are added.

- [ ] **Step 1: Read the file around line 22 and 286–293 to confirm current state**

Read `resources/views/layouts/app.blade.php` lines 22 and 286–293 before editing. Confirm:
- Line 22: `<body class="h-full overflow-hidden bg-paper dark:bg-[#131917]">`
- Lines 286–290: the `@foreach (['success'=>'low',...` flash badge loop
- Line 343: `<main class="flex-1 overflow-y-auto min-h-0 px-7 py-7 pb-10 bg-paper dark:bg-[#131917]">`
- Line 352: `</body>`

- [ ] **Step 2: Add skip-to-content link immediately after the `<body>` tag**

Find this exact string (line 22–23):
```blade
<body class="h-full overflow-hidden bg-paper dark:bg-[#131917]">
<div class="flex h-screen overflow-hidden">
```

Replace with:
```blade
<body class="h-full overflow-hidden bg-paper dark:bg-[#131917]">
<a href="#main-content"
   class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[100] focus:px-4 focus:py-2 focus:rounded-lg focus:bg-forest-700 focus:text-white focus:text-sm focus:font-semibold focus:shadow-lg">
    Skip to content
</a>
<div class="flex h-screen overflow-hidden">
```

- [ ] **Step 3: Add `id="main-content"` to the `<main>` element**

Find:
```blade
        <main class="flex-1 overflow-y-auto min-h-0 px-7 py-7 pb-10 bg-paper dark:bg-[#131917]">
```

Replace with:
```blade
        <main id="main-content" class="flex-1 overflow-y-auto min-h-0 px-7 py-7 pb-10 bg-paper dark:bg-[#131917]">
```

- [ ] **Step 4: Remove the topbar flash badge loop**

Find and remove these lines (286–290):
```blade
                {{-- Flash messages --}}
                @foreach (['success'=>'low','warning'=>'moderate','info'=>'info','error'=>'critical'] as $type => $variant)
                    @if (session($type))
                    <div class="badge badge-{{ $variant }} mr-1">{{ session($type) }}</div>
                    @endif
                @endforeach
```

Replace with an empty string (delete the block entirely — the blank line between the topbar right-area items can stay).

- [ ] **Step 5: Add `<x-toast />` before `</body>`**

Find:
```blade
@livewireScripts
@stack('scripts')
</body>
```

Replace with:
```blade
@livewireScripts
@stack('scripts')
<x-toast />
</body>
```

- [ ] **Step 6: Verify in browser**

Start `php artisan serve` and trigger a flash message (e.g., edit any senior record and save). Confirm:
- Toast appears bottom-right, auto-dismisses after 5 seconds
- No badge chips appear in the topbar
- Tab key from top of page shows the "Skip to content" focus ring

- [ ] **Step 7: Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "fix: skip link, main landmark, replace topbar flash badges with x-toast"
```

---

## Task 7: Fix `x-risk-badge` — replace ⚠ emoji with labelled SVG

**Files:**
- Modify: `resources/views/components/risk-badge.blade.php`

The current badge uses `⚠` as a Unicode character. Screen readers announce it as "warning sign" without context. An SVG with `aria-hidden="true"` plus a visually-hidden `<span>` gives screen readers the right label.

- [ ] **Step 1: Read the current file**

Read `resources/views/components/risk-badge.blade.php` and confirm line 16 reads:
```blade
    {{ $lvl ?: '—' }}{{ $isUrgent ? ' ⚠' : '' }}
```

- [ ] **Step 2: Replace the emoji with an SVG**

Find:
```blade
<span class="badge {{ $class }} {{ $isUrgent ? 'ring-1 ring-orange-400' : '' }}">
    <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
    {{ $lvl ?: '—' }}{{ $isUrgent ? ' ⚠' : '' }}
</span>
```

Replace with:
```blade
<span class="badge {{ $class }} {{ $isUrgent ? 'ring-1 ring-orange-400' : '' }}">
    <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
    {{ $lvl ?: '—' }}
    @if ($isUrgent)
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
             class="w-3 h-3 ml-0.5 inline-block" aria-hidden="true">
            <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
        </svg>
        <span class="sr-only">urgent</span>
    @endif
</span>
```

- [ ] **Step 3: Verify**

Load the seniors list page (or any page showing risk badges). Confirm badges still appear correctly and the urgent ring is visible. Tab through and confirm a screen reader would announce "HIGH urgent" instead of "HIGH warning sign".

- [ ] **Step 4: Commit**

```bash
git add resources/views/components/risk-badge.blade.php
git commit -m "fix: replace emoji warning in risk-badge with accessible SVG icon"
```

---

## Task 8: Senior List page — `x-page-header`, `x-breadcrumb`, fix per-row archive modal

**Files:**
- Modify: `resources/views/seniors/index.blade.php`

- [ ] **Step 1: Read lines 1–15 of the file**

Confirm the file starts with:
```blade
@extends('layouts.app')
@section('page-title', 'Senior Citizen Records')
@section('page-subtitle', number_format($stats['total']) . ' active seniors · Pagsanjan, Laguna')
```

- [ ] **Step 2: Add `x-breadcrumb` and `x-page-header` at the top of `@section('content')`**

Find:
```blade
@section('content')
<div class="space-y-6" x-data="seniorIndex()">

    {{-- Stats strip --}}
```

Replace with:
```blade
@section('content')
<div class="space-y-6" x-data="seniorIndex()">

    <x-breadcrumb :links="[
        ['label' => 'Dashboard', 'href' => route('dashboard')],
        ['label' => 'Senior Records'],
    ]" />
    <x-page-header
        title="Senior Citizen Records"
        subtitle="{{ number_format($stats['total']) }} active seniors · Pagsanjan, Laguna"
    />

    {{-- Stats strip --}}
```

- [ ] **Step 3: Fix per-row archive modal duplication**

The table rows each contain `x-data="{ archiveOpen: false }"` which creates one modal DOM node per senior. The `seniorIndex()` Alpine component already exists — move the modal state there.

Find this pattern in the `@foreach ($seniors as $senior)` row (around line 229):
```blade
                        <div class="flex items-center justify-end gap-1" x-data="{ archiveOpen: false }">
```

Replace with:
```blade
                        <div class="flex items-center justify-end gap-1">
```

Then find the archive confirm button inside the row (it calls `archiveOpen = true`). Replace:
```blade
                                @click="archiveOpen = true"
```
With:
```blade
                                @click="archiveSeniorId = {{ $senior->id }}; archiveOpen = true; archiveSeniorName = '{{ addslashes($senior->full_name) }}'"
```

Then find the archive form inside the row (it has a hidden form that submits to destroy route) — read its exact `action` attribute. Remove the entire per-row modal div block (the `x-show="archiveOpen"` block inside the foreach) — it will be replaced by the single shared modal.

Find the per-row modal block inside `@foreach`:
```blade
                            <div x-show="archiveOpen" x-cloak
                                 class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4"
                                 @keydown.escape.window="archiveOpen = false"
                                 @keydown.enter.window="if(archiveOpen) $refs.archiveForm.submit()">
```
Delete this block and all its contents through its closing `</div>`.

Also remove the hidden archive form from inside the foreach row — it was paired with the per-row modal. The shared bulk archive modal at the bottom of the file (lines 332+) already handles bulk archive. For single-row archive, modify the shared modal to handle both.

Find the `seniorIndex()` function in `resources/js/seniors/index.js` (or `@push('scripts')` at the bottom of index.blade.php) and add `archiveSeniorId: null, archiveSeniorName: ''` to the data if not present.

> **Note:** Read lines 229–310 of the file carefully before editing. The archive form's exact `action` attribute and the modal's Alpine state need to be traced. The goal is: one modal, driven by `archiveSeniorId` state, with a dynamic form action URL.

- [ ] **Step 4: Verify**

Load the seniors list. Confirm:
- Breadcrumb shows "Dashboard / Senior Records"
- Page header shows with title and subtitle
- Clicking "Archive" on any row opens the confirmation modal
- Modal identifies the correct senior name
- Bulk archive still works via the bulk action bar

- [ ] **Step 5: Commit**

```bash
git add resources/views/seniors/index.blade.php
git commit -m "fix: add x-page-header, x-breadcrumb to seniors list; deduplicate per-row archive modal"
```

---

## Task 9: Senior Profile page — page header, fix delete modal, remove stale badge, modal ARIA

**Files:**
- Modify: `resources/views/seniors/show.blade.php`

- [ ] **Step 1: Add `x-breadcrumb` and `x-page-header`**

Find (at the start of `@section('content')`):
```blade
@section('content')
@php $ml = $senior->latestMlResult; @endphp
<div class="space-y-6">

    {{-- Top action bar --}}
    <div class="flex items-center gap-3 flex-wrap">
        <a href="{{ route('seniors.index') }}" class="btn btn-ghost gap-1.5 pl-1.5">
            <x-heroicon-o-arrow-left class="w-3.5 h-3.5" /> Back to records
        </a>
```

Replace with:
```blade
@section('content')
@php $ml = $senior->latestMlResult; @endphp
<div class="space-y-6">

    <x-breadcrumb :links="[
        ['label' => 'Dashboard', 'href' => route('dashboard')],
        ['label' => 'Senior Records', 'href' => route('seniors.index')],
        ['label' => $senior->full_name],
    ]" />
    <x-page-header
        eyebrow="Senior Profile"
        :title="$senior->full_name"
        subtitle="OSCA ID: {{ $senior->osca_id }} · {{ $senior->barangay }}"
    />

    {{-- Top action bar --}}
    <div class="flex items-center gap-3 flex-wrap">
        <a href="{{ route('seniors.index') }}" class="btn btn-ghost gap-1.5 pl-1.5">
            <x-heroicon-o-arrow-left class="w-3.5 h-3.5" /> Back to records
        </a>
```

- [ ] **Step 2: Remove the stale `critical_flag` badge**

Find and delete (lines 204–206):
```blade
                        @if ($ml->critical_flag)
                            <span class="inline-flex items-center gap-0.5 text-[10px] font-bold px-1.5 py-0.5 rounded text-high-700 bg-high-50 border border-high-200">⚠ Critical</span>
                        @endif
```

Delete these three lines entirely. The CRITICAL level was removed from the system; this badge is stale.

- [ ] **Step 3: Fix the Delete QoL Survey modal**

Read lines 445–480 of the file. The modal has `style="background:#ffffff !important"`, `style="background:#fee2e2;"`, and `onmouseover`/`onmouseout` event handlers. Replace the entire inner modal div (the white box, not the backdrop) with:

```blade
                                        <div class="bg-white dark:bg-ink-900 rounded-2xl shadow-2xl max-w-sm w-full p-6 border border-paper-rule dark:border-ink-700"
                                             @click.outside="open = false">
                                            <div class="flex items-start gap-3 mb-4">
                                                <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 bg-high-100 dark:bg-high-900/40">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-high-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                    </svg>
                                                </div>
                                                <div>
                                                    <h3 id="delete-survey-title" class="font-semibold text-ink-900 dark:text-ink-100">
                                                        Delete QoL Survey?
                                                    </h3>
                                                    <p class="text-sm mt-1 text-ink-600 dark:text-ink-400">
                                                        The survey from <strong class="text-ink-800 dark:text-ink-200">{{ $survey->survey_date?->format('M j, Y') }}</strong> and its ML results will be permanently deleted.
                                                    </p>
                                                    <p class="text-xs font-semibold mt-2 px-3 py-1.5 rounded-lg text-high-700 bg-high-50 dark:bg-high-900/30 dark:text-high-300">
                                                        This cannot be undone.
                                                    </p>
                                                </div>
                                            </div>
                                            <div class="flex gap-3 justify-end pt-3 mt-1 border-t border-paper-rule dark:border-ink-700">
                                                <button @click="open = false"
                                                        class="btn btn-ghost">
                                                    Cancel
                                                </button>
                                                <button @click="$refs.deleteForm.submit()"
                                                        class="btn bg-high-600 hover:bg-high-700 text-white border-transparent">
                                                    Delete Survey
                                                </button>
                                            </div>
                                        </div>
```

Also update the backdrop div (the `fixed inset-0` parent) to add ARIA attributes:
```blade
                                        <div x-show="open" x-cloak
                                             role="dialog"
                                             aria-modal="true"
                                             aria-labelledby="delete-survey-title"
                                             class="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4"
                                             @keydown.escape.window="open = false">
```

- [ ] **Step 4: Verify dark mode**

Toggle dark mode on the senior profile page. Confirm:
- The delete modal backdrop and inner box are both visible and styled correctly in dark mode
- No white flash or invisible text

- [ ] **Step 5: Commit**

```bash
git add resources/views/seniors/show.blade.php
git commit -m "fix: page header, breadcrumb, dark-mode delete modal, remove stale critical_flag badge"
```

---

## Task 10: Profile Survey — design token cleanup

**Files:**
- Modify: `resources/views/livewire/surveys/profile-survey.blade.php`

This file uses `teal-600`, `slate-*`, `emerald-*`, `purple-600` instead of the system's design tokens. This task replaces every foreign color with the correct token.

- [ ] **Step 1: Read the full file**

Read the entire `profile-survey.blade.php`. Map every occurrence of the foreign colors to their system replacement:

| Foreign class | Replacement |
|---|---|
| `bg-teal-600` | `bg-forest-700` |
| `border-teal-600` | `border-forest-700` |
| `text-teal-600` | `text-forest-700` |
| `ring-teal-600` | `ring-forest-700` |
| `bg-white border border-slate-200 rounded-xl` (step card) | replace wrapping div with `<x-card>` |
| `bg-slate-100` | `bg-paper-2` |
| `bg-slate-200` | `bg-paper-rule` |
| `text-slate-400` | `text-ink-400` |
| `text-slate-500` | `text-ink-500` |
| `text-slate-600` | `text-ink-600` |
| `text-slate-700` | `text-ink-700` |
| `border-slate-200` | `border-paper-rule` |
| `bg-emerald-50 border-emerald-200` (success banner) | replace entire banner div with `<x-alert type="success">` |
| `bg-red-50 border-red-200` (error banner) | replace entire banner div with `<x-alert type="error">` |
| `bg-purple-600 hover:bg-purple-700` (success CTA) | `btn btn-primary` |

- [ ] **Step 2: Apply replacements using Edit (one at a time)**

Use the Edit tool for each replacement. Replace each instance individually using exact string matching to avoid partial-match errors. Do not do a global find-replace across the file — Livewire templates can have multiple similar patterns that need different treatments.

For the success alert banner, find the `bg-emerald-50` div block and replace it entirely. Typical pattern:
```blade
<div class="bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3 ...">
    ...
</div>
```
Replace with:
```blade
<x-alert type="success" title="Profile saved">
    The senior's profile information has been updated.
</x-alert>
```
(Adjust the message text to match what the original div actually says.)

For the error alert, similarly replace with:
```blade
<x-alert type="error" title="Please fix the errors below">
    {{ $slot }} {{-- or the specific error message content --}}
</x-alert>
```

For the success CTA button (purple), find the exact `bg-purple-600` button and replace its classes with `btn btn-primary` while keeping all other attributes (`wire:click`, etc.) intact.

- [ ] **Step 3: Verify Livewire bindings are intact**

Load the profile survey for any senior. Tab through all steps and confirm:
- `wire:model` fields still bind correctly
- Step navigation (next/back) works
- Form submission works (no console errors)
- Color palette now matches the rest of the system

- [ ] **Step 4: Commit**

```bash
git add resources/views/livewire/surveys/profile-survey.blade.php
git commit -m "fix: replace foreign teal/slate/emerald/purple palette with design tokens in profile-survey"
```

---

## Task 11: Dashboard — page header, chart ARIA, empty states

**Files:**
- Modify: `resources/views/livewire/dashboard/main-dashboard.blade.php`

- [ ] **Step 1: Add `x-page-header` at the top of the component**

The dashboard component starts with a filter bar or KPI grid. Add before the first content element:
```blade
<x-page-header title="Dashboard" subtitle="OSCA senior citizen overview · Pagsanjan, Laguna" />
```

- [ ] **Step 2: Add `aria-label` to all Chart.js canvas elements**

Search for `<canvas` in the file. For each canvas, add an `aria-label` attribute describing the chart:

```blade
{{-- Risk distribution chart --}}
<canvas id="riskChart" aria-label="Risk distribution: high, moderate, and low risk senior counts"></canvas>

{{-- Cluster distribution chart --}}
<canvas id="clusterChart" aria-label="Cluster distribution: senior count per cluster group"></canvas>

{{-- Domain radar chart --}}
<canvas id="radarChart" aria-label="Domain scores radar: QoL scores across health, economic, social, functional, and mental health domains"></canvas>

{{-- Age distribution chart --}}
<canvas id="ageChart" aria-label="Age distribution: senior counts grouped by five-year age bands"></canvas>
```

Adjust `id` values to match what is actually in the file. Read the file to find the exact canvas IDs.

- [ ] **Step 3: Add visually-hidden data table for the risk doughnut chart**

After the risk chart canvas, add:
```blade
<div class="sr-only" aria-hidden="false">
    <table>
        <caption>Risk distribution data</caption>
        <thead><tr><th scope="col">Level</th><th scope="col">Count</th></tr></thead>
        <tbody>
            <tr><td>High Risk</td><td>{{ $stats['high'] ?? 0 }}</td></tr>
            <tr><td>Moderate Risk</td><td>{{ $stats['moderate'] ?? 0 }}</td></tr>
            <tr><td>Low Risk</td><td>{{ $stats['low'] ?? 0 }}</td></tr>
        </tbody>
    </table>
</div>
```

(Match the variable names to what the component actually passes — read the component's `$stats` or equivalent variable.)

- [ ] **Step 4: Replace any ad-hoc empty state divs with `<x-empty-state />`**

Search for patterns like `text-center` + empty messaging text (e.g. "No data available", "No seniors registered"). Replace with:
```blade
<x-empty-state icon="chart" title="No data yet" description="Analysis data will appear once seniors complete QoL surveys." />
```

- [ ] **Step 5: Verify**

Load the dashboard. Confirm:
- Page header appears at the top
- Charts render exactly as before
- No JavaScript errors in the browser console

- [ ] **Step 6: Commit**

```bash
git add resources/views/livewire/dashboard/main-dashboard.blade.php
git commit -m "fix: add page header, chart aria-labels, sr-only data tables, x-empty-state to dashboard"
```

---

## Task 12: QoL Survey Form — page header and breadcrumb

**Files:**
- Modify: `resources/views/livewire/surveys/qol-survey-form.blade.php`

The form's design (`.bar` progress, `forest-700` colors) is already correct. This task only adds navigation context.

- [ ] **Step 1: Add breadcrumb and page header**

Read the top of `qol-survey-form.blade.php`. Find the opening wrapper div and add before the first content:
```blade
<x-breadcrumb :links="[
    ['label' => 'Dashboard', 'href' => route('dashboard')],
    ['label' => 'Senior Records', 'href' => route('seniors.index')],
    ['label' => $senior->full_name, 'href' => route('seniors.show', $senior)],
    ['label' => 'QoL Survey'],
]" />
<x-page-header
    eyebrow="Quality of Life Survey"
    :title="$senior->full_name"
    subtitle="Step {{ $step }} of {{ $totalSteps }}"
/>
```

Verify that `$senior`, `$step`, and `$totalSteps` are available in the component — read the Livewire component PHP class (`app/Livewire/Surveys/QolSurveyForm.php`) to confirm public properties.

- [ ] **Step 2: Commit**

```bash
git add resources/views/livewire/surveys/qol-survey-form.blade.php
git commit -m "fix: add breadcrumb and page header to QoL survey form"
```

---

## Task 13: Recommendations page — page header, breadcrumb, x-empty-state

**Files:**
- Modify: `resources/views/recommendations/show.blade.php`

- [ ] **Step 1: Add breadcrumb and page header**

The file currently starts with a "Back to profile" button (line 7–9). Insert breadcrumb and page header before it:

Find:
```blade
@section('content')
<div class="space-y-5">
    <a href="{{ route('seniors.show', $senior) }}" class="btn btn-ghost gap-1.5 pl-1.5 w-fit">
        <x-heroicon-o-arrow-left class="w-3.5 h-3.5" /> Back to profile
    </a>
```

Replace with:
```blade
@section('content')
<div class="space-y-5">
    <x-breadcrumb :links="[
        ['label' => 'Dashboard', 'href' => route('dashboard')],
        ['label' => 'Senior Records', 'href' => route('seniors.index')],
        ['label' => $senior->full_name, 'href' => route('seniors.show', $senior)],
        ['label' => 'Recommendations'],
    ]" />
    <x-page-header
        eyebrow="Recommendations"
        :title="$senior->full_name"
        :subtitle="$senior->barangay . ' · ' . ($recommendations->count()) . ' recommendations'"
    />
    <a href="{{ route('seniors.show', $senior) }}" class="btn btn-ghost gap-1.5 pl-1.5 w-fit">
        <x-heroicon-o-arrow-left class="w-3.5 h-3.5" /> Back to profile
    </a>
```

- [ ] **Step 2: Replace ad-hoc empty state (lines 107–114)**

Find:
```blade
    @empty
    <div class="card p-12 text-center">
        <x-heroicon-o-light-bulb class="w-10 h-10 text-ink-300 mx-auto mb-3" />
        <p class="font-serif text-base text-ink-500 font-medium">No recommendations yet.</p>
        <p class="text-[12.5px] text-ink-400 mt-1">Complete a QoL survey to generate tailored recommendations.</p>
        <a href="{{ route('surveys.qol.create', $senior) }}" class="btn btn-primary mt-4 inline-flex">
            <x-heroicon-o-clipboard-document-list class="w-3.5 h-3.5" /> Take QoL Survey
        </a>
    </div>
```

Replace with:
```blade
    @empty
    <x-card>
        <x-empty-state
            icon="document"
            title="No recommendations yet"
            description="Complete a QoL survey and run the ML assessment to generate tailored recommendations.">
            <x-slot name="action">
                <a href="{{ route('surveys.qol.create', $senior) }}" class="btn btn-primary inline-flex gap-1.5">
                    <x-heroicon-o-clipboard-document-list class="w-3.5 h-3.5" /> Take QoL Survey
                </a>
            </x-slot>
        </x-empty-state>
    </x-card>
```

- [ ] **Step 3: Commit**

```bash
git add resources/views/recommendations/show.blade.php
git commit -m "fix: add breadcrumb, page header, x-empty-state to recommendations page"
```

---

## Task 14: Risk Report — page header and print CSS

**Files:**
- Modify: `resources/views/reports/risk.blade.php`

- [ ] **Step 1: Add `@push('styles')` print stylesheet at the top of the content section**

Read the top of `reports/risk.blade.php`. Find the first line of `@section('content')` and add before it:

```blade
@push('styles')
<style>
@media print {
    /* Hide navigation chrome */
    aside, header, nav, form, .btn, button[type="button"], [aria-label="Dismiss notification"] {
        display: none !important;
    }
    /* Reset page layout for print */
    body, html { overflow: visible !important; height: auto !important; }
    #main-content { overflow: visible !important; padding: 0 !important; }
    main { overflow: visible !important; }

    /* Formal print header */
    .print-header {
        display: block !important;
        border-bottom: 2px solid #2d6a4f;
        padding-bottom: 12px;
        margin-bottom: 24px;
    }
    .print-header h1 { font-size: 18px; font-weight: 700; color: #1a3c2b; }
    .print-header p  { font-size: 11px; color: #4a6856; margin-top: 4px; }

    /* Page setup */
    @page { margin: 20mm; }

    /* Prevent cards from splitting across pages */
    .card, .kpi { page-break-inside: avoid; }
    tr { page-break-inside: avoid; }
}
</style>
@endpush
```

Also ensure `@stack('styles')` is in `layouts/app.blade.php`. Check the layout `<head>` for `@stack('styles')`. If it doesn't exist, add it after `@livewireStyles`:
```blade
    @livewireStyles
    @stack('styles')
```

- [ ] **Step 2: Add `x-page-header` and a print-only header block**

Find the first element inside `@section('content')` and add:
```blade
{{-- Print-only formal header (hidden on screen) --}}
<div class="print-header hidden">
    <h1>OSCA Risk Report — Pagsanjan, Laguna</h1>
    <p>Generated: {{ now()->format('F j, Y') }} · OSCA AgeSense System</p>
</div>

<x-page-header title="Risk Report" subtitle="Senior citizen risk level distribution and domain breakdown" />
```

- [ ] **Step 3: Verify print layout**

Open the risk report page in Chrome. Press Ctrl+P (print preview). Confirm:
- Sidebar and topbar are not visible in print preview
- Print header appears with "OSCA Risk Report" and date
- KPI cards, domain bars, and table are all visible

- [ ] **Step 4: Commit**

```bash
git add resources/views/reports/risk.blade.php
git commit -m "fix: add x-page-header and print CSS to risk report"
```

---

## Task 15: Minor fixes — help typo, ML status dots, PDF colors

**Files:**
- Modify: `resources/views/help/index.blade.php`
- Modify: `resources/views/ml/status.blade.php`
- Modify: `resources/views/seniors/pdf.blade.php`

- [ ] **Step 1: Fix help page typo**

Read `resources/views/help/index.blade.php` line 34. Find:
```
OSCA AgeS ense system
```
Replace with:
```
OSCA AgeSense system
```

Commit:
```bash
git add resources/views/help/index.blade.php
git commit -m "fix: typo 'AgeS ense' → 'AgeSense' in help page"
```

- [ ] **Step 2: Fix ML status page — use `.status-dot` classes**

Read `resources/views/ml/status.blade.php`. Find all raw status indicator divs like:
```blade
<div class="w-3 h-3 rounded-full {{ $service === 'ok' ? 'bg-green-500' : 'bg-red-500' }}"></div>
```

Replace each with the design system status dot class. The CSS classes defined in `app.css` are:
- `.status-dot-ok` — green/online
- `.status-dot-warn` — amber/degraded  
- `.status-dot-err` — red/offline

Pattern:
```blade
<span class="{{ $service === 'ok' ? 'status-dot-ok' : ($service === 'warn' ? 'status-dot-warn' : 'status-dot-err') }}"></span>
```

Read the actual file to identify the exact variable names and conditions used before editing.

Commit:
```bash
git add resources/views/ml/status.blade.php
git commit -m "fix: use .status-dot system classes in ML status page"
```

- [ ] **Step 3: Fix PDF template — replace teal with forest colors**

Read `resources/views/seniors/pdf.blade.php`. Find every occurrence of `#0d9488` (teal) in inline styles. Replace with `#2d6a4f` (forest-700). Also check for `teal` in any class names and replace with `forest`.

Common occurrences to check:
```
background: #0d9488  →  background: #2d6a4f
color: #0d9488       →  color: #2d6a4f
border-color: #0d9488 → border-color: #2d6a4f
```

Commit:
```bash
git add resources/views/seniors/pdf.blade.php
git commit -m "fix: replace teal (#0d9488) with forest (#2d6a4f) in PDF template"
```

---

## Task 16: Final verification pass

**No file changes — browser testing only.**

- [ ] **Step 1: Test flash messages end-to-end**

Edit any senior profile and save. Confirm a green toast appears bottom-right and auto-dismisses. Trigger a validation error and confirm a red toast appears.

- [ ] **Step 2: Test dark mode across all modified pages**

Toggle dark mode. Visit: Dashboard, Senior List, Senior Profile, Profile Survey, QoL Survey, Recommendations, Risk Report. Confirm no white boxes, no invisible text, no inline-style overrides breaking the dark background.

- [ ] **Step 3: Test keyboard navigation**

Tab from the browser URL bar into the app. Confirm "Skip to content" focus ring appears first. Press Enter — confirm focus jumps to `#main-content`. Tab through modals and confirm focus is trapped within them.

- [ ] **Step 4: Test Livewire bindings**

In Profile Survey: complete all steps, confirm `wire:model` fields save correctly.
In QoL Survey: navigate through all 8 steps, confirm progress bar advances.
In Dashboard: change filters, confirm Livewire re-renders charts correctly.

- [ ] **Step 5: Commit final state**

```bash
git add -A
git commit -m "chore: final UI/UX overhaul — all 22 issues resolved (Approach B)"
```

---

## Spec Self-Review

**Coverage check against spec:**
- [x] `x-toast` replaces broken topbar flash — Task 1 + Task 6
- [x] `x-page-header` replaces dead `@yield('page-subtitle')` — Task 2 + Tasks 8–14
- [x] `x-breadcrumb` on all detail pages — Task 3 + Tasks 8–13
- [x] `x-alert` for inline feedback — Task 4 + Task 10
- [x] `x-empty-state` for no-data blocks — Task 5 + Tasks 11, 13
- [x] Skip link + main landmark — Task 6
- [x] Topbar flash badges removed — Task 6
- [x] Risk badge urgent emoji → SVG — Task 7
- [x] Per-row archive modal dedup — Task 8
- [x] Profile survey token cleanup — Task 10
- [x] Delete modal dark mode fix — Task 9
- [x] Critical_flag badge removed — Task 9
- [x] Modal role/aria-modal/labelledby — Task 9
- [x] Chart aria-label attributes — Task 11
- [x] Chart accessible data tables — Task 11
- [x] Risk report print CSS — Task 14
- [x] Print-only formal header block — Task 14
- [x] Help page typo — Task 15
- [x] ML status `.status-dot` classes — Task 15
- [x] PDF teal → forest color — Task 15
- [x] Final verification — Task 16
