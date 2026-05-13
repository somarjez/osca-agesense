<!DOCTYPE html>
<html lang="en"
      x-data="appLayout"
      :class="{ 'dark': dark }"
      class="h-full overflow-hidden">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'AgeSense') — OSCA · Pagsanjan, Laguna</title>
    {{-- Apply dark class immediately from localStorage to prevent flash --}}
    <script>
        try { if (localStorage.getItem('darkMode') === 'true') document.documentElement.classList.add('dark'); } catch(e) {}
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;450;500;600;700&family=Source+Serif+4:opsz,wght@8..60,400;8..60,500;8..60,600;8..60,700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
    @livewireStyles
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="h-full overflow-hidden bg-paper dark:bg-[#131917]">
<div class="flex h-screen overflow-hidden">

    {{-- ── Sidebar ── --}}
    <aside :class="sidebarOpen ? 'w-64' : 'w-16'"
           class="flex-shrink-0 flex flex-col bg-paper-2 dark:bg-[#1a201d] border-r border-paper-rule dark:border-[#2b3530] transition-[width] duration-200 overflow-hidden">

        {{-- Hamburger toggle (always at top) --}}
        <div class="flex-shrink-0 border-b border-paper-rule dark:border-[#2b3530] flex items-center"
             :class="sidebarOpen ? 'px-3 py-2.5 gap-3' : 'py-2.5 justify-center'">
            <button @click="toggleSidebar()"
                    class="btn btn-ghost p-1.5 flex-shrink-0"
                    :title="sidebarOpen ? 'Collapse sidebar' : 'Expand sidebar'">
                <x-heroicon-o-bars-3 class="w-5 h-5" />
            </button>
            <div x-show="sidebarOpen" x-cloak class="flex items-center gap-2.5 min-w-0 overflow-hidden">
                <div class="w-7 h-7 rounded-md bg-forest-800 text-forest-100 grid place-items-center font-serif font-semibold text-[15px] flex-shrink-0">A</div>
                <div class="min-w-0">
                    <div class="font-serif text-[17px] font-semibold tracking-tightish leading-none text-ink-900 dark:text-[#e4e1d8] whitespace-nowrap">AgeSense</div>
                    <div class="text-[10px] tracking-[0.08em] uppercase text-ink-500 dark:text-[#4a5550] font-medium whitespace-nowrap">Healthy Ageing Analytics</div>
                </div>
            </div>
        </div>

        {{-- Deployment (expanded only) --}}
        <div x-show="sidebarOpen" x-cloak
             class="px-4 py-2.5 border-b border-paper-rule dark:border-[#2b3530] flex-shrink-0">
            <div class="text-[10px] tracking-[0.1em] uppercase text-ink-400 dark:text-[#4a5550] font-semibold">Deployment</div>
            <div class="font-serif text-[13px] font-medium mt-0.5 text-ink-900 dark:text-[#b0b5b2]">OSCA · Pagsanjan, Laguna</div>
        </div>

        {{-- Nav --}}
        <nav class="flex-1 py-3 overflow-y-auto scrollbar-thin" :class="sidebarOpen ? 'px-3' : 'px-2'">

            <div x-show="sidebarOpen" x-cloak
                 class="text-[10.5px] tracking-[0.12em] uppercase text-ink-400 dark:text-[#4a5550] font-semibold px-3 pt-2 pb-2">Workspace</div>
            <div x-show="!sidebarOpen" x-cloak class="h-2"></div>

            @php
            $navItems = [
                ['route'=>'dashboard',            'label'=>'Dashboard',         'icon'=>'home'],
                ['route'=>'seniors.index',        'label'=>'Senior Records',    'icon'=>'users'],
                ['route'=>'seniors.create',       'label'=>'New Profile',       'icon'=>'user-plus'],
                ['route'=>'surveys.qol.index',    'label'=>'QoL Surveys',       'icon'=>'clipboard-document-list'],
                ['route'=>'reports.cluster',      'label'=>'Health Groups',     'icon'=>'squares-2x2'],
                ['route'=>'reports.risk',         'label'=>'Risk Reports',      'icon'=>'shield-check'],
                ['route'=>'reports.barangay.index','label'=>'Barangay Report',   'icon'=>'map-pin','active'=>'reports.barangay*'],
                ['route'=>'recommendations.index','label'=>'Recommendations',   'icon'=>'light-bulb'],
            ];
            @endphp

            @foreach ($navItems as $item)
            <a href="{{ route($item['route']) }}"
               class="nav-link {{ request()->routeIs($item['active'] ?? $item['route'].'*') ? 'nav-link-active' : '' }}"
               :class="{ 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : '{{ $item['label'] }}'">
                <x-dynamic-component :component="'heroicon-o-'.$item['icon']" class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">{{ $item['label'] }}</span>
            </a>
            @endforeach

            <div x-show="sidebarOpen" x-cloak
                 class="text-[10.5px] tracking-[0.12em] uppercase text-ink-400 dark:text-[#4a5550] font-semibold px-3 pt-5 pb-2">Assessment Tools</div>
            <div x-show="!sidebarOpen" x-cloak class="my-2 border-t border-paper-rule dark:border-[#2b3530] mx-1"></div>

            <a href="{{ route('ml.status') }}"
               class="nav-link {{ request()->routeIs('ml.status') ? 'nav-link-active' : '' }}"
               :class="{ 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Service Status'">
                <x-heroicon-o-bolt class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Service Status</span>
            </a>
            <a href="{{ route('ml.batch') }}"
               class="nav-link {{ request()->routeIs('ml.batch') ? 'nav-link-active' : '' }}"
               :class="{ 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Batch Analysis'">
                <x-heroicon-o-arrow-path class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Batch Analysis</span>
            </a>

            <div x-show="sidebarOpen" x-cloak
                 class="text-[10.5px] tracking-[0.12em] uppercase text-ink-400 dark:text-[#4a5550] font-semibold px-3 pt-5 pb-2">Administration</div>
            <div x-show="!sidebarOpen" x-cloak class="my-2 border-t border-paper-rule dark:border-[#2b3530] mx-1"></div>

            <a href="{{ route('activity-log.index') }}"
               class="nav-link {{ request()->routeIs('activity-log*') ? 'nav-link-active' : '' }}"
               :class="{ 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Activity Log'">
                <x-heroicon-o-clipboard-document-check class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Activity Log</span>
            </a>
            <a href="{{ route('reports.registry.export') }}"
               class="nav-link"
               :class="{ 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Export Registry'">
                <x-heroicon-o-table-cells class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Export Registry</span>
            </a>

            <div x-show="sidebarOpen" x-cloak
                 class="text-[10.5px] tracking-[0.12em] uppercase text-ink-400 dark:text-[#4a5550] font-semibold px-3 pt-5 pb-2">Archives</div>
            <div x-show="!sidebarOpen" x-cloak class="my-2 border-t border-paper-rule dark:border-[#2b3530] mx-1"></div>

            <a href="{{ route('seniors.archives') }}"
               class="nav-link {{ request()->routeIs('seniors.archives*') ? 'nav-link-active' : '' }}"
               :class="{ 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Archives'">
                <x-heroicon-o-archive-box class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Archives</span>
            </a>

            <div x-show="sidebarOpen" x-cloak
                 class="text-[10.5px] tracking-[0.12em] uppercase text-ink-400 dark:text-[#4a5550] font-semibold px-3 pt-5 pb-2">Help</div>
            <div x-show="!sidebarOpen" x-cloak class="my-2 border-t border-paper-rule dark:border-[#2b3530] mx-1"></div>

            <a href="{{ route('help') }}"
               class="nav-link {{ request()->routeIs('help') ? 'nav-link-active' : '' }}"
               :class="{ 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Help Centre'">
                <x-heroicon-o-question-mark-circle class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Help Centre</span>
            </a>
        </nav>

        {{-- Footer --}}
        <div class="border-t border-paper-rule dark:border-[#2b3530] flex-shrink-0 px-3 py-2">
            <div class="flex items-center gap-2" :class="sidebarOpen ? '' : 'flex-col'">

                {{-- Avatar --}}
                <div class="w-7 h-7 rounded-full bg-forest-200 dark:bg-forest-900/60 text-forest-800 dark:text-forest-300 grid place-items-center font-semibold text-xs flex-shrink-0">
                    {{ strtoupper(substr(auth()->user()?->name ?? 'A', 0, 2)) }}
                </div>

                {{-- Name/role (expanded only) --}}
                <div x-show="sidebarOpen" x-cloak class="flex-1 min-w-0">
                    <div class="text-[12px] font-semibold text-ink-900 dark:text-[#e4e1d8] truncate">{{ auth()->user()?->name ?? 'OSCA Staff' }}</div>
                    <div class="text-[10px] text-ink-500 dark:text-[#4a5550]">OSCA Officer</div>
                </div>

                {{-- Dark mode toggle --}}
                <button @click="toggleDark()"
                        class="btn btn-ghost p-1.5 flex-shrink-0"
                        :title="dark ? 'Light mode' : 'Dark mode'">
                    <x-heroicon-o-sun  class="w-4 h-4" x-show="dark"  x-cloak />
                    <x-heroicon-o-moon class="w-4 h-4" x-show="!dark" />
                </button>

                {{-- Logout --}}
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn btn-ghost p-1.5 flex-shrink-0" title="Sign out">
                        <x-heroicon-o-arrow-right-on-rectangle class="w-4 h-4" />
                    </button>
                </form>
            </div>
        </div>
    </aside>

    {{-- ── Main ── --}}
    <div class="flex-1 flex flex-col overflow-hidden min-h-0">

        {{-- Topbar --}}
        <header class="bg-paper dark:bg-[#131917] border-b border-paper-rule dark:border-[#2b3530] px-9 py-4 flex items-center justify-between flex-shrink-0 gap-6">
            <div class="flex items-baseline gap-3 min-w-0">
                <h1 class="font-serif text-[22px] font-semibold tracking-snug text-ink-900 dark:text-[#e4e1d8] leading-tight whitespace-nowrap">@yield('page-title', 'Dashboard')</h1>
                @hasSection('page-subtitle')
                    <p class="text-[12.5px] text-ink-400 dark:text-[#6b7570] truncate">@yield('page-subtitle')</p>
                @endif
            </div>
            <div class="flex items-center gap-3 flex-shrink-0">
                @foreach (['success'=>'low','warning'=>'moderate','info'=>'info','error'=>'critical'] as $type => $variant)
                    @if (session($type))
                    <div class="badge badge-{{ $variant }}">{{ session($type) }}</div>
                    @endif
                @endforeach
                <span class="text-[11px] text-ink-400 dark:text-[#4a5550] tnum whitespace-nowrap">{{ now()->format('D, M j') }}</span>
                <div class="h-4 w-px bg-paper-rule dark:bg-[#2b3530]"></div>
                <a href="{{ route('ml.status') }}" class="inline-flex items-center gap-1.5 text-[11.5px] text-ink-500 dark:text-[#6b7570] hover:text-ink-900 dark:hover:text-[#e4e1d8] transition-colors" title="Analysis service status">
                    <span class="status-dot status-dot-ok"></span>
                    <span class="font-medium">Services</span>
                </a>
                <div class="h-4 w-px bg-paper-rule dark:bg-[#2b3530]"></div>
                <button class="btn btn-ghost p-1.5" title="Notifications">
                    <x-heroicon-o-bell class="w-4 h-4" />
                </button>
            </div>
        </header>

        {{-- Page content --}}
        <main class="flex-1 overflow-y-auto min-h-0 px-9 py-8 pb-10">
            @yield('content')
        </main>
    </div>
</div>

@livewireScripts
@stack('scripts')
</body>
</html>
