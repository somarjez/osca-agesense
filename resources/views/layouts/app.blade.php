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
    <aside :class="sidebarOpen ? 'w-64' : 'w-[68px]'"
           class="flex-shrink-0 flex flex-col bg-white dark:bg-[#151c19] border-r border-paper-rule dark:border-[#2b3530] transition-[width] duration-200 overflow-hidden shadow-sm">

        {{-- Brand block --}}
        <div class="flex-shrink-0 border-b border-paper-rule dark:border-[#2b3530] px-3 py-3.5">
            {{-- Expanded --}}
            <div x-show="sidebarOpen" x-cloak class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-xl bg-forest-800 text-forest-100 grid place-items-center font-serif font-bold text-[16px] flex-shrink-0 shadow-sm">A</div>
                <div class="min-w-0 flex-1">
                    <div class="font-serif text-[15.5px] font-semibold tracking-tightish leading-none text-ink-900 dark:text-[#e4e1d8] whitespace-nowrap">AgeSense</div>
                    <div class="text-[10px] tracking-[0.09em] text-ink-400 dark:text-[#4a5550] font-medium whitespace-nowrap mt-0.5">OSCA · Pagsanjan, Laguna</div>
                </div>
                <button @click="toggleSidebar()"
                        class="btn btn-ghost p-1 flex-shrink-0"
                        title="Collapse sidebar">
                    <x-heroicon-o-chevron-left class="w-4 h-4" />
                </button>
            </div>
            {{-- Collapsed --}}
            <div x-show="!sidebarOpen" class="flex flex-col items-center gap-2">
                <div class="w-8 h-8 rounded-xl bg-forest-800 text-forest-100 grid place-items-center font-serif font-bold text-[16px] flex-shrink-0 shadow-sm">A</div>
                <button @click="toggleSidebar()"
                        class="btn btn-ghost p-1"
                        title="Expand sidebar">
                    <x-heroicon-o-bars-3 class="w-4 h-4" />
                </button>
            </div>
        </div>

        {{-- Nav --}}
        <nav class="flex-1 py-3 overflow-y-auto scrollbar-thin" :class="sidebarOpen ? 'px-3' : 'px-2'">

            {{-- ── Workspace ── --}}
            <div x-show="sidebarOpen" x-cloak
                 class="eyebrow px-3 pt-1 pb-2">Workspace</div>
            <div x-show="!sidebarOpen" x-cloak class="h-1"></div>

            <a href="{{ route('dashboard') }}"
               class="nav-link {{ request()->routeIs('dashboard') ? 'nav-link-active' : '' }}"
               :class="{ 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Dashboard'">
                <x-heroicon-o-home class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Dashboard</span>
            </a>
            <a href="{{ route('seniors.index') }}"
               class="nav-link {{ request()->routeIs('seniors.index') ? 'nav-link-active' : '' }}"
               :class="{ 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Senior Records'">
                <x-heroicon-o-users class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Senior Records</span>
            </a>

            @hasanyrole('admin|encoder')
            <a href="{{ route('seniors.create') }}"
               class="nav-link {{ request()->routeIs('seniors.create') ? 'nav-link-active' : '' }}"
               :class="{ 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'New Profile'">
                <x-heroicon-o-user-plus class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">New Profile</span>
            </a>
            <a href="{{ route('surveys.qol.index') }}"
               class="nav-link {{ request()->routeIs('surveys.qol*') ? 'nav-link-active' : '' }}"
               :class="{ 'nav-link-collapsed': !sidebarOpen }"
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
               class="nav-link {{ request()->routeIs('reports.cluster') ? 'nav-link-active' : '' }}"
               :class="{ 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Health Groups'">
                <x-heroicon-o-squares-2x2 class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Health Groups</span>
            </a>
            <a href="{{ route('reports.gis') }}"
               class="nav-link {{ request()->routeIs('reports.gis') ? 'nav-link-active' : '' }}"
               :class="{ 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'GIS Analytics'">
                <x-heroicon-o-map class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">GIS Analytics</span>
            </a>
            <a href="{{ route('reports.risk') }}"
               class="nav-link {{ request()->routeIs('reports.risk') ? 'nav-link-active' : '' }}"
               :class="{ 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Risk Reports'">
                <x-heroicon-o-shield-check class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Risk Reports</span>
            </a>
            <a href="{{ route('reports.barangay.index') }}"
               class="nav-link {{ request()->routeIs('reports.barangay*') ? 'nav-link-active' : '' }}"
               :class="{ 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Barangay Report'">
                <x-heroicon-o-map-pin class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Barangay Report</span>
            </a>
            <a href="{{ route('recommendations.index') }}"
               class="nav-link {{ request()->routeIs('recommendations*') ? 'nav-link-active' : '' }}"
               :class="{ 'nav-link-collapsed': !sidebarOpen }"
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
            @endhasanyrole

            {{-- ── Administration (admin only) ── --}}
            @role('admin')
            <div x-show="sidebarOpen" x-cloak
                 class="eyebrow px-3 pt-5 pb-2">Administration</div>
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
            <a href="{{ route('users.index') }}"
               class="nav-link {{ request()->routeIs('users*') ? 'nav-link-active' : '' }}"
               :class="{ 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'User Management'">
                <x-heroicon-o-user-group class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">User Management</span>
            </a>

            {{-- Archives ── --}}
            <div x-show="sidebarOpen" x-cloak
                 class="eyebrow px-3 pt-5 pb-2">Archives</div>
            <div x-show="!sidebarOpen" x-cloak class="my-2 border-t border-paper-rule dark:border-[#2b3530] mx-1"></div>

            <a href="{{ route('seniors.archives') }}"
               class="nav-link {{ request()->routeIs('seniors.archives*') ? 'nav-link-active' : '' }}"
               :class="{ 'nav-link-collapsed': !sidebarOpen }"
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
               class="nav-link {{ request()->routeIs('help') ? 'nav-link-active' : '' }}"
               :class="{ 'nav-link-collapsed': !sidebarOpen }"
               :title="sidebarOpen ? '' : 'Help Centre'">
                <x-heroicon-o-question-mark-circle class="w-4 h-4 flex-shrink-0" />
                <span x-show="sidebarOpen" x-cloak class="whitespace-nowrap">Help Centre</span>
            </a>
        </nav>

        {{-- Sidebar Footer — user profile --}}
        <div class="border-t border-paper-rule dark:border-[#2b3530] flex-shrink-0 px-3 py-3">
            <div class="flex items-center gap-2.5" :class="sidebarOpen ? '' : 'flex-col gap-1.5 items-center'">

                {{-- Avatar --}}
                <div class="w-8 h-8 rounded-xl bg-forest-100 dark:bg-forest-900/60 text-forest-800 dark:text-forest-300 grid place-items-center font-semibold text-[12px] flex-shrink-0 border border-forest-200 dark:border-forest-800/40">
                    {{ strtoupper(substr(auth()->user()?->name ?? 'A', 0, 2)) }}
                </div>

                {{-- Name/role (expanded only) --}}
                <div x-show="sidebarOpen" x-cloak class="flex-1 min-w-0">
                    <div class="text-[12.5px] font-semibold text-ink-900 dark:text-[#e4e1d8] truncate leading-tight">{{ auth()->user()?->name ?? 'OSCA Staff' }}</div>
                    <div class="text-[10.5px] text-ink-400 dark:text-[#4a5550] leading-tight">
                        @php
                            $roleLabels = ['admin' => 'Administrator', 'encoder' => 'Encoder', 'viewer' => 'Viewer'];
                            $roleName   = auth()->user()?->getRoleNames()->first() ?? 'viewer';
                        @endphp
                        {{ $roleLabels[$roleName] ?? 'OSCA Staff' }}
                    </div>
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
        <header class="bg-white dark:bg-[#151c19] border-b border-paper-rule dark:border-[#2b3530] px-6 flex items-center flex-shrink-0 gap-4 h-[52px]">

            {{-- Left: Page title (fixed width so search stays centered) --}}
            <div class="flex items-center gap-2.5 min-w-0 w-52 flex-shrink-0">
                <h1 class="font-serif text-[17px] font-semibold tracking-snug text-ink-900 dark:text-[#e4e1d8] leading-none truncate">@yield('page-title', 'Dashboard')</h1>
            </div>

            {{-- Center: Search bar — navigates to seniors.index with ?search= --}}
            {{-- Use / key to focus. Shows current search value when on senior pages. --}}
            <form action="{{ route('seniors.index') }}" method="GET"
                  x-data="{}"
                  @keydown.slash.window.prevent="$refs.topbarSearch.focus()"
                  class="flex-1 hidden md:block max-w-sm mx-auto">
                <div class="topbar-search">
                    <x-heroicon-o-magnifying-glass class="w-3.5 h-3.5 text-ink-300 dark:text-[#4a5550] flex-shrink-0" />
                    <input x-ref="topbarSearch"
                           type="text"
                           name="search"
                           value="{{ request()->routeIs('seniors.*') ? request('search') : '' }}"
                           placeholder="Search seniors by name or OSCA ID…"
                           autocomplete="off" />
                    <kbd class="text-[10px] text-ink-300 dark:text-[#4a5550] font-mono bg-paper-rule/60 dark:bg-[#2b3530]/80 px-1.5 py-0.5 rounded-md flex-shrink-0 leading-none">/</kbd>
                </div>
            </form>

            {{-- Right: utilities --}}
            <div class="flex items-center gap-1.5 flex-shrink-0 ml-auto">
                {{-- Flash messages --}}
                @foreach (['success'=>'low','warning'=>'moderate','info'=>'info','error'=>'critical'] as $type => $variant)
                    @if (session($type))
                    <div class="badge badge-{{ $variant }} mr-1">{{ session($type) }}</div>
                    @endif
                @endforeach

                {{-- Date --}}
                <span class="text-[11px] text-ink-400 dark:text-[#4a5550] tnum whitespace-nowrap hidden lg:block px-1">{{ now()->format('D, M j') }}</span>

                <div class="h-4 w-px bg-paper-rule dark:bg-[#2b3530] mx-1"></div>

                {{-- ML Services status --}}
                @php
                    $navHealth = \Illuminate\Support\Facades\Cache::get('ml_nav_health');
                    if ($navHealth === null) {
                        try {
                            $navHealth = app(\App\Services\MlService::class)->healthCheck();
                        } catch (\Throwable) {
                            $navHealth = ['preprocessor' => 'unreachable', 'inference' => 'unreachable', 'local_runner' => 'unavailable', 'mode' => 'php_fallback'];
                        }
                        // Cache online status for 30s; offline status for 15s so nav recovers quickly after start
                        $navCacheTtl = ($navHealth['preprocessor'] === 'ok' && $navHealth['inference'] === 'ok') ? 30 : 15;
                        \Illuminate\Support\Facades\Cache::put('ml_nav_health', $navHealth, $navCacheTtl);
                    }
                    $navDotClass = match(true) {
                        $navHealth['preprocessor'] === 'ok' && $navHealth['inference'] === 'ok' => 'status-dot-ok',
                        $navHealth['local_runner'] === 'available'                              => 'status-dot-warn',
                        default                                                                 => 'status-dot-err',
                    };
                    $navTitle = match($navDotClass) {
                        'status-dot-ok'   => 'HTTP services online',
                        'status-dot-warn' => 'HTTP services offline — using local fallback',
                        default           => 'All analysis services unavailable',
                    };
                @endphp
                <a href="{{ route('ml.status') }}"
                   class="inline-flex items-center gap-1.5 text-[11.5px] text-ink-500 dark:text-[#6b7570]
                          hover:text-ink-900 dark:hover:text-[#e4e1d8] hover:bg-paper-2 dark:hover:bg-[#202a26]
                          px-2 py-1.5 rounded-lg transition-all duration-150"
                   title="{{ $navTitle }}">
                    <span class="status-dot {{ $navDotClass }}"></span>
                    <span class="font-medium hidden sm:inline text-[11.5px]">Services</span>
                </a>

                <div class="h-4 w-px bg-paper-rule dark:bg-[#2b3530] mx-1"></div>

                {{-- Notification bell --}}
                <button class="w-8 h-8 flex items-center justify-center rounded-lg text-ink-400 dark:text-[#6b7570]
                               hover:bg-paper-2 dark:hover:bg-[#202a26] hover:text-ink-700 dark:hover:text-[#c8c4bc]
                               transition-all duration-150 cursor-not-allowed opacity-50"
                        title="Notifications (coming soon)" disabled>
                    <x-heroicon-o-bell class="w-4 h-4" />
                </button>
            </div>
        </header>

        {{-- Page content --}}
        <main class="flex-1 overflow-y-auto min-h-0 px-7 py-7 pb-10 bg-paper dark:bg-[#131917]">
            @yield('content')
        </main>
    </div>
</div>

@livewireScripts
@stack('scripts')
</body>
</html>
