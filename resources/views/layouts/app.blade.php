<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'OSCA System') — Pagsanjan, Laguna</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
    @livewireStyles
    <style>
        body { font-family: 'DM Sans', system-ui, sans-serif; }
        .font-display { font-family: 'DM Serif Display', Georgia, serif; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="h-full">
<div class="flex h-screen overflow-hidden bg-slate-50">

    {{-- ── Sidebar ── --}}
    <aside class="w-64 flex-shrink-0 bg-white border-r border-slate-200 flex flex-col">

        {{-- Logo --}}
        <div class="px-5 py-4 border-b border-slate-100">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-teal-500 to-emerald-600 flex items-center justify-center shadow-sm">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-bold text-slate-800 leading-none">OSCA System</p>
                    <p class="text-xs text-slate-500 mt-0.5">Pagsanjan, Laguna</p>
                </div>
            </div>
        </div>

        {{-- Nav --}}
        <nav class="flex-1 px-3 py-4 space-y-0.5 overflow-y-auto">
            @php
            $navItems = [
                ['route'=>'dashboard',          'label'=>'Dashboard',         'emoji'=>'🏠'],
                ['route'=>'seniors.index',       'label'=>'Senior Records',    'emoji'=>'👥'],
                ['route'=>'seniors.create',      'label'=>'New Profile',       'emoji'=>'➕'],
                ['route'=>'surveys.qol.index',   'label'=>'QoL Surveys',       'emoji'=>'📋'],
                ['route'=>'reports.cluster',     'label'=>'Cluster Analysis',  'emoji'=>'📊'],
                ['route'=>'reports.risk',        'label'=>'Risk Reports',      'emoji'=>'🛡️'],
                ['route'=>'recommendations.index','label'=>'Recommendations',  'emoji'=>'💡'],
            ];
            @endphp
            @foreach ($navItems as $item)
            <a href="{{ route($item['route']) }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-all
                      {{ request()->routeIs($item['route'].'*')
                         ? 'bg-teal-50 text-teal-700 shadow-sm ring-1 ring-teal-100'
                         : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
                <span class="text-base leading-none">{{ $item['emoji'] }}</span>
                {{ $item['label'] }}
            </a>
            @endforeach

            <div class="pt-3 mt-3 border-t border-slate-100">
                <p class="px-3 mb-1 text-xs font-semibold text-slate-400 uppercase tracking-wider">ML Pipeline</p>
                <a href="{{ route('ml.status') }}"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 hover:bg-slate-50 transition-all {{ request()->routeIs('ml.status') ? 'bg-teal-50 text-teal-700' : '' }}">
                    <span class="text-base">⚙️</span> Service Status
                </a>
                <a href="{{ route('ml.batch') }}"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-600 hover:bg-slate-50 transition-all {{ request()->routeIs('ml.batch') ? 'bg-teal-50 text-teal-700' : '' }}">
                    <span class="text-base">🔄</span> Batch Analysis
                </a>
            </div>
        </nav>

        {{-- User footer --}}
        <div class="border-t border-slate-100 px-4 py-3">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-full bg-teal-100 flex items-center justify-center flex-shrink-0">
                    <span class="text-xs font-bold text-teal-700">
                        {{ strtoupper(substr(auth()->user()?->name ?? 'A', 0, 1)) }}
                    </span>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-slate-700 truncate">{{ auth()->user()?->name ?? 'Admin' }}</p>
                    <p class="text-xs text-slate-400">OSCA Staff</p>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-slate-400 hover:text-slate-700 transition-colors" title="Logout">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/>
                        </svg>
                    </button>
                </form>
            </div>
        </div>
    </aside>

    {{-- ── Main Content ── --}}
    <div class="flex-1 flex flex-col overflow-hidden">

        {{-- Topbar --}}
        <header class="bg-white border-b border-slate-200 px-6 py-3 flex items-center justify-between shadow-sm flex-shrink-0">
            <div>
                <h1 class="text-lg font-semibold text-slate-800">@yield('page-title', 'Dashboard')</h1>
                @hasSection('page-subtitle')
                    <p class="text-sm text-slate-500 mt-0.5">@yield('page-subtitle')</p>
                @endif
            </div>
            <div class="flex items-center gap-3 text-sm">
                @foreach (['success'=>'emerald','warning'=>'amber','info'=>'blue','error'=>'red'] as $type => $color)
                    @if (session($type))
                    <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg border
                                bg-{{ $color }}-50 border-{{ $color }}-200 text-{{ $color }}-700">
                        {{ session($type) }}
                    </div>
                    @endif
                @endforeach
                <span class="text-slate-400 text-xs">{{ now()->format('D, M j, Y') }}</span>
            </div>
        </header>

        {{-- Page --}}
        <main class="flex-1 overflow-y-auto p-6">
            @yield('content')
        </main>
    </div>
</div>

@livewireScripts
@stack('scripts')
</body>
</html>
