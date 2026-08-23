<!DOCTYPE html>
<html lang="en"
      x-data="appLayout"
      :class="{ 'dark': dark }"
      class="h-full overflow-hidden">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'AgeSense')</title>
    <meta name="robots" content="noindex, nofollow">
    {{-- The sidebar/topbar are @persist'd (see below) so their DOM survives
         wire:navigate untouched — but that means anything server-rendered
         inside them (page title, active nav link) goes stale on navigation
         unless synced from somewhere that DOES refresh. <head> is not
         persisted and is merged fresh on every navigation (Livewire's
         mergeNewHead), so this meta tag is that refresh source: app.js reads
         it on livewire:navigated and copies its value into the persisted
         <h1>. --}}
    <meta name="page-title" content="@yield('page-title', 'Dashboard')">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    {{-- Apply dark class immediately from localStorage to prevent flash --}}
    <script>
        try { if (localStorage.getItem('darkMode') === 'true') document.documentElement.classList.add('dark'); } catch(e) {}
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600;8..60,700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Chart.js is bundled into app.js (which sets window.Chart). The former
         CDN <script> here was a second, render-blocking copy on every page —
         removed. --}}
    @livewireStyles
    @stack('styles')
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="h-full overflow-hidden bg-paper dark:bg-[#131917]">
<a href="#main-content"
   class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[100] focus:px-4 focus:py-2 focus:rounded-lg focus:bg-forest-700 focus:text-white focus:text-sm focus:font-semibold focus:shadow-lg">
    Skip to content
</a>
<div class="flex flex-col h-screen overflow-hidden">

    {{-- ── Republic of the Philippines band ── --}}
    <x-gov-band class="no-print flex-shrink-0" />

    <div class="flex flex-1 overflow-hidden min-h-0">

    {{-- ── Sidebar ── --}}
    {{-- @persist'd: without this, wire:navigate destroys and recreates the
         whole sidebar subtree (20 role-gated links) on every navigation, and
         the Services link's x-init ml.nav-health fetch below re-fires every
         time too. Persisting means this DOM is built once and just gets
         moved between the old and new <body>; only its active-link state
         needs a client-side refresh (via $navActive below) since it no
         longer receives fresh server-rendered classes after the first load. --}}
    @persist('sidebar')
    <aside :class="sidebarOpen ? 'w-64' : 'w-[68px]'"
           class="no-print flex-shrink-0 flex flex-col bg-white dark:bg-[#151c19] border-r border-paper-rule dark:border-[#2b3530] transition-[width] duration-200 overflow-hidden">

        {{-- Brand block --}}
        <div class="flex-shrink-0 border-b border-paper-rule dark:border-[#2b3530] px-3 py-3.5">
            {{-- Expanded --}}
            <div x-show="sidebarOpen" x-cloak class="flex items-center gap-3">
                <x-app-logo :size="34" class="shadow-sm tilt-hover" />
                <div class="min-w-0 flex-1">
                    <div class="masthead-name text-[16px] whitespace-nowrap">AgeSense</div>
                    <div class="masthead-office whitespace-nowrap mt-1">OSCA · Pagsanjan</div>
                </div>
                <button @click="toggleSidebar()"
                        class="sidebar-icon-btn flex-shrink-0"
                        type="button"
                        aria-label="Collapse sidebar"
                        title="Collapse sidebar">
                    <x-heroicon-o-chevron-left class="w-3.5 h-3.5" aria-hidden="true" />
                </button>
            </div>
            {{-- Collapsed --}}
            <div x-show="!sidebarOpen" class="flex flex-col items-center gap-2">
                <x-app-logo :size="32" class="shadow-sm" />
                <button @click="toggleSidebar()"
                        class="sidebar-icon-btn"
                        type="button"
                        aria-label="Expand sidebar"
                        title="Expand sidebar">
                    <x-heroicon-o-bars-3 class="w-3.5 h-3.5" aria-hidden="true" />
                </button>
            </div>
        </div>

        {{-- Nav --}}
        <nav class="flex-1 min-h-0 py-3 overflow-y-auto scrollbar-thin" :class="sidebarOpen ? 'px-3' : 'px-2'">

            {{-- ── Workspace ── --}}
            <div x-show="sidebarOpen" x-cloak
                 class="eyebrow px-3 pt-1 pb-2">Workspace</div>
            <div x-show="!sidebarOpen" x-cloak class="h-1"></div>

            <a href="{{ route('dashboard') }}"
               wire:navigate
               class="nav-link"
               :class="{ 'nav-link-active': $navActive('{{ route('dashboard', [], false) }}'), 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Dashboard'">
                <x-heroicon-o-home class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Dashboard</span>
            </a>
            <a href="{{ route('seniors.index') }}"
               wire:navigate
               class="nav-link"
               :class="{ 'nav-link-active': $navActive('{{ route('seniors.index', [], false) }}', 'resource'), 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Senior Records'">
                <x-heroicon-o-users class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Senior Records</span>
            </a>
            <a href="{{ route('seniors.deceased') }}"
               wire:navigate
               class="nav-link"
               :class="{ 'nav-link-active': $navActive('{{ route('seniors.deceased', [], false) }}'), 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Deceased Seniors'">
                <x-heroicon-o-user-minus class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Deceased Seniors</span>
            </a>

            @hasanyrole('admin|encoder')
            <a href="{{ route('seniors.create') }}"
               wire:navigate
               class="nav-link"
               :class="{ 'nav-link-active': $navActive('{{ route('seniors.create', [], false) }}'), 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'New Profile'">
                <x-heroicon-o-user-plus class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">New Profile</span>
            </a>
            <a href="{{ route('seniors.drafts.index') }}"
               wire:navigate
               class="nav-link"
               :class="{ 'nav-link-active': $navActive(['{{ route('seniors.drafts.index', [], false) }}', '{{ url('/surveys/profile/drafts') }}'], true), 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Drafts'">
                <x-heroicon-o-pencil-square class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Drafts</span>
            </a>
            <a href="{{ route('surveys.qol.index') }}"
               wire:navigate
               class="nav-link"
               :class="{ 'nav-link-active': $navActive('{{ route('surveys.qol.index', [], false) }}', true), 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'QoL Surveys'">
                <x-heroicon-o-clipboard-document-list class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">QoL Surveys</span>
            </a>
            @endhasanyrole

            {{-- ── Analytics ── --}}
            <div x-show="sidebarOpen" x-cloak
                 class="eyebrow px-3 pt-5 pb-2">Analytics</div>
            <div x-show="!sidebarOpen" x-cloak class="my-2 border-t border-paper-rule dark:border-[#2b3530] mx-1"></div>

            <a href="{{ route('reports.cluster') }}"
               wire:navigate
               class="nav-link"
               :class="{ 'nav-link-active': $navActive('{{ route('reports.cluster', [], false) }}'), 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Profile Groups'">
                <x-heroicon-o-squares-2x2 class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Profile Groups</span>
            </a>
            <a href="{{ route('reports.gis') }}"
               wire:navigate
               class="nav-link"
               :class="{ 'nav-link-active': $navActive('{{ route('reports.gis', [], false) }}'), 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'GIS Analytics'">
                <x-heroicon-o-map class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">GIS Analytics</span>
            </a>
            <a href="{{ route('reports.risk') }}"
               wire:navigate
               class="nav-link"
               :class="{ 'nav-link-active': $navActive('{{ route('reports.risk', [], false) }}'), 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Risk Reports'">
                <x-heroicon-o-shield-check class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Risk Reports</span>
            </a>
            <a href="{{ route('reports.barangay.index') }}"
               wire:navigate
               class="nav-link"
               :class="{ 'nav-link-active': $navActive('{{ route('reports.barangay.index', [], false) }}', true), 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Barangay Report'">
                <x-heroicon-o-map-pin class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Barangay Report</span>
            </a>
            <a href="{{ route('recommendations.index') }}"
               wire:navigate
               class="nav-link"
               :class="{ 'nav-link-active': $navActive('{{ route('recommendations.index', [], false) }}', true), 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Recommendations'">
                <x-heroicon-o-light-bulb class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Recommendations</span>
            </a>

            {{-- ── Assessment Tools (admin + encoder) ── --}}
            @hasanyrole('admin|encoder')
            <div x-show="sidebarOpen" x-cloak
                 class="eyebrow px-3 pt-5 pb-2">Assessment</div>
            <div x-show="!sidebarOpen" x-cloak class="my-2 border-t border-paper-rule dark:border-[#2b3530] mx-1"></div>

            <a href="{{ route('ml.status') }}"
               wire:navigate
               class="nav-link"
               :class="{ 'nav-link-active': $navActive('{{ route('ml.status', [], false) }}'), 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Service Status'">
                <x-heroicon-o-bolt class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Service Status</span>
            </a>
            <a href="{{ route('ml.batch') }}"
               wire:navigate
               class="nav-link"
               :class="{ 'nav-link-active': $navActive('{{ route('ml.batch', [], false) }}'), 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Batch Analysis'">
                <x-heroicon-o-arrow-path class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Batch Analysis</span>
            </a>
            @endhasanyrole

            {{-- ── Administration (admin only) ── --}}
            @role('admin')
            <div x-show="sidebarOpen" x-cloak
                 class="eyebrow px-3 pt-5 pb-2">Administration</div>
            <div x-show="!sidebarOpen" x-cloak class="my-2 border-t border-paper-rule dark:border-[#2b3530] mx-1"></div>

            <a href="{{ route('reports.validation') }}"
               wire:navigate
               class="nav-link"
               :class="{ 'nav-link-active': $navActive('{{ route('reports.validation', [], false) }}'), 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'System Validation'">
                <x-heroicon-o-chart-bar-square class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">System Validation</span>
            </a>
            <a href="{{ route('activity-log.index') }}"
               wire:navigate
               class="nav-link"
               :class="{ 'nav-link-active': $navActive('{{ route('activity-log.index', [], false) }}', true), 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Activity Log'">
                <x-heroicon-o-clipboard-document-check class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Activity Log</span>
            </a>
            <a href="{{ route('reports.registry') }}"
               wire:navigate
               class="nav-link"
               :class="{ 'nav-link-active': $navActive('{{ route('reports.registry', [], false) }}'), 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Registry and Backup'">
                <x-heroicon-o-table-cells class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Registry and Backup</span>
            </a>
            <a href="{{ route('users.index') }}"
               wire:navigate
               class="nav-link"
               :class="{ 'nav-link-active': $navActive('{{ route('users.index', [], false) }}', true), 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'User Management'">
                <x-heroicon-o-user-group class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">User Management</span>
            </a>

            {{-- Archives ── --}}
            <div x-show="sidebarOpen" x-cloak
                 class="eyebrow px-3 pt-5 pb-2">Archives</div>
            <div x-show="!sidebarOpen" x-cloak class="my-2 border-t border-paper-rule dark:border-[#2b3530] mx-1"></div>

            <a href="{{ route('seniors.archives') }}"
               wire:navigate
               class="nav-link"
               :class="{ 'nav-link-active': $navActive('{{ route('seniors.archives', [], false) }}', true), 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Archives'">
                <x-heroicon-o-archive-box class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Archives</span>
            </a>
            @endrole

            {{-- Help --}}
            <div x-show="sidebarOpen" x-cloak
                 class="eyebrow px-3 pt-5 pb-2">Help</div>
            <div x-show="!sidebarOpen" x-cloak class="my-2 border-t border-paper-rule dark:border-[#2b3530] mx-1"></div>

            <a href="{{ route('help') }}"
               wire:navigate
               class="nav-link"
               :class="{ 'nav-link-active': $navActive('{{ route('help', [], false) }}'), 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Help Centre'">
                <x-heroicon-o-question-mark-circle class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Help Centre</span>
            </a>
        </nav>

    </aside>
    @endpersist

    {{-- ── Main ── --}}
    <div class="flex-1 flex flex-col overflow-hidden min-h-0">

        {{-- Topbar --}}
        {{-- @persist'd for the same reason as the sidebar above — additionally
             stops the Services link's x-init (ml.nav-health fetch) from
             re-firing on every navigation; it now runs once per full load.
             Page title and search value are server-rendered but this DOM is
             now frozen after the first render, so both are kept in sync from
             app.js on livewire:navigated: title from the <meta name="page-title">
             tag (head is not persisted, so it's always fresh), search value
             read directly from location.search. --}}
        @persist('topbar')
        <header class="no-print bg-white dark:bg-[#151c19] border-b border-paper-rule dark:border-[#2b3530] px-6 flex items-center flex-shrink-0 gap-4 h-14 shadow-[0_1px_0_rgba(20,30,25,0.05)]">

            {{-- Left: Page title (grows to fill the space; truncate is only a
                 safety net for an unusually long dynamic title at narrow widths) --}}
            <div class="flex items-center gap-2.5 min-w-0 flex-1">
                <h1 id="topbar-page-title" class="font-serif text-[18px] font-semibold tracking-snug text-ink-900 dark:text-[#e4e1d8] leading-none truncate">@yield('page-title', 'Dashboard')</h1>
            </div>

            {{-- Right: utilities --}}
            <div class="flex items-center gap-1.5 flex-shrink-0 ml-auto">
                {{-- Date --}}
                <span class="text-[11px] text-ink-400 dark:text-[#4a5550] tnum whitespace-nowrap hidden lg:block px-1">{{ now()->format('D, M j') }}</span>

                <div class="h-4 w-px bg-paper-rule dark:bg-[#2b3530] mx-1"></div>

                {{-- ML Services status --}}
                {{-- Backed by the single shared poller in resources/js/ml-health.js
                     (OSCA.mlHealth), not a one-shot fetch — this element sits inside
                     @persist('topbar') so an x-init here would only ever run once per
                     hard page load and could never reflect a later recovery. Seeded
                     synchronously from OSCA.mlHealth's current value, then kept live
                     via the osca:ml-health window event it dispatches on every poll.
                     Keep this attribute a single short line — a multi-line x-data with
                     an embedded comment containing a stray quote has broken this exact
                     kind of attribute twice before (see BladeAlpineAttributeIntegrityTest). --}}
                <a href="{{ route('ml.status') }}" wire:navigate
                   x-data="{ dot: OSCA.mlHealth.dot, title: OSCA.mlHealth.title }"
                   @osca:ml-health.window="dot = $event.detail.dot; title = $event.detail.title"
                   class="inline-flex items-center gap-1.5 text-[11.5px] text-ink-500 dark:text-[#6b7570]
                          hover:text-ink-900 dark:hover:text-[#e4e1d8] hover:bg-paper-2 dark:hover:bg-[#202a26]
                          px-2 py-1.5 rounded-lg transition-all duration-150"
                   :title="title">
                    <span class="status-dot"
                          :class="{ 'status-dot-ok': dot === 'ok', 'status-dot-warn': dot === 'warn', 'status-dot-err': dot === 'err' }"></span>
                    {{-- Non-color signal alongside the dot — a colorblind or
                         inattentive user shouldn't have to rely on red vs.
                         green alone to notice services are down. --}}
                    <x-heroicon-o-exclamation-triangle x-show="dot === 'err'" x-cloak
                                                        class="w-3.5 h-3.5 text-critical-600 dark:text-[#e08070] flex-shrink-0" />
                    <span class="font-medium hidden sm:inline text-[11.5px]">Services</span>
                </a>

                <div class="h-4 w-px bg-paper-rule dark:bg-[#2b3530] mx-1"></div>

                {{-- User profile dropdown --}}
                @php
                    $roleLabels = ['admin' => 'Administrator', 'encoder' => 'Encoder', 'viewer' => 'Viewer'];
                    $roleName   = auth()->user()?->getRoleNames()->first() ?? 'viewer';
                @endphp
                {{-- The topbar is @persist'd (see above), so this local profileOpen
                     state now survives navigation too — previously a full body
                     swap reset it for free. Close it explicitly on navigate so
                     clicking a sidebar link while this is open doesn't leave it
                     hanging open on the destination page. --}}
                {{-- x-on:, not the @ shorthand: "@livewire:..." collides with
                     Livewire's own @livewire(...) Blade directive and breaks
                     view compilation (confirmed — ArgumentCountError from
                     Illuminate\Filesystem\Filesystem, Blade parsing this as
                     @livewire's directive arguments). x-on: isn't a Blade
                     "@word" token, so it isn't intercepted. --}}
                <div x-data="{ profileOpen: false }" class="relative"
                     @keydown.escape.window="profileOpen = false"
                     x-on:livewire:navigating.window="profileOpen = false">
                    <button type="button" @click="profileOpen = !profileOpen"
                            class="flex items-center gap-1.5 pl-1 pr-1.5 py-1 rounded-lg hover:bg-paper-2 dark:hover:bg-[#202a26] transition-colors duration-150"
                            aria-haspopup="menu" :aria-expanded="profileOpen ? 'true' : 'false'"
                            aria-label="Account menu">
                        <span class="w-7 h-7 rounded-xl chip-3d tilt-hover bg-forest-100 dark:bg-forest-900/60 text-forest-800 dark:text-forest-300 grid place-items-center font-semibold text-[11px]">
                            {{ strtoupper(substr(auth()->user()?->name ?? 'A', 0, 2)) }}
                        </span>
                        <x-heroicon-o-chevron-down class="w-3 h-3 text-ink-400 dark:text-[#6b7570] transition-transform duration-150"
                                                   ::class="profileOpen ? 'rotate-180' : ''" aria-hidden="true" />
                    </button>

                    <div x-show="profileOpen" x-cloak @click.outside="profileOpen = false"
                         x-transition:enter="transition ease-out duration-150"
                         x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                         x-transition:leave="transition ease-in duration-100"
                         x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                         class="absolute right-0 top-full mt-2 w-56 origin-top-right z-50 rounded-xl bg-white dark:bg-[#1a201d]
                                border border-paper-rule dark:border-[#2b3530] shadow-lg overflow-hidden"
                         role="menu">
                        {{-- Identity header --}}
                        <div class="px-4 py-3 border-b border-paper-rule dark:border-[#2b3530]">
                            <x-tooltip :text="e(auth()->user()?->name ?? 'OSCA Staff')" position="bottom" width="w-56" class="block">
                                <div class="text-[13px] font-semibold text-ink-900 dark:text-[#e4e1d8] truncate">{{ auth()->user()?->name ?? 'OSCA Staff' }}</div>
                            </x-tooltip>
                            <div class="text-[11px] text-ink-500 dark:text-[#6b7570]">{{ $roleLabels[$roleName] ?? 'OSCA Staff' }}</div>
                        </div>
                        {{-- Dark mode toggle --}}
                        <button type="button" @click="toggleDark()" role="menuitem"
                                class="w-full flex items-center gap-2.5 px-4 py-2.5 text-[12.5px] text-ink-700 dark:text-[#b0b5b2]
                                       hover:bg-paper-2 dark:hover:bg-[#202a26] transition-colors duration-150">
                            <x-heroicon-o-sun  class="w-4 h-4" x-show="dark"  x-cloak aria-hidden="true" />
                            <x-heroicon-o-moon class="w-4 h-4" x-show="!dark" aria-hidden="true" />
                            <span x-text="dark ? 'Light mode' : 'Dark mode'"></span>
                        </button>
                        {{-- Sign out — data-no-loading opts out of the global submit
                             text-swap guard; the icon swaps for a spinner instead. --}}
                        <form method="POST" action="{{ route('logout') }}" x-data="{ out: false }" @submit="out = true"
                              class="border-t border-paper-rule dark:border-[#2b3530]">
                            @csrf
                            <button type="submit" data-no-loading role="menuitem" :aria-busy="out"
                                    :class="{ 'opacity-70 pointer-events-none': out }"
                                    class="w-full flex items-center gap-2.5 px-4 py-2.5 text-[12.5px] text-ink-700 dark:text-[#b0b5b2]
                                           hover:bg-paper-2 dark:hover:bg-[#202a26] transition-colors duration-150">
                                <x-heroicon-o-arrow-right-on-rectangle class="w-4 h-4" x-show="!out" aria-hidden="true" />
                                <span x-show="out" x-cloak class="btn-spinner w-4 h-4" aria-hidden="true"></span>
                                Sign out
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>
        @endpersist

        {{-- Page content --}}
        <main id="main-content" class="flex-1 overflow-y-auto min-h-0 px-7 py-7 pb-10 bg-paper dark:bg-[#131917]">
            {{-- Document header shown only when printing (hidden on screen) --}}
            <div class="print-only hidden mb-6" aria-hidden="true">
                <div class="flex items-end justify-between border-b-2 border-ink-900 pb-2">
                    <div>
                        <div class="font-serif text-lg font-semibold text-ink-900">AgeSense</div>
                        <div class="text-[12px] text-ink-700">Office of Senior Citizens Affairs, Pagsanjan, Laguna · @yield('page-title', 'Report')</div>
                    </div>
                    <div class="text-right text-[10px] text-ink-600 leading-snug">
                        <div>Generated {{ now()->format('F j, Y · g:i A') }}</div>
                        <div>System-generated decision-support output</div>
                    </div>
                </div>
            </div>

            @yield('content')
        </main>
    </div>
    </div>
</div>

@livewireScripts
@stack('scripts')
<x-toast />
<x-ml-service-guard />
<x-idle-warning />
</body>
</html>
