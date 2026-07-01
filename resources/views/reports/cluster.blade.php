{{-- resources/views/reports/cluster.blade.php --}}
@extends('layouts.app')
@section('page-title', 'Profile Group Analysis')
@section('page-subtitle', 'Senior citizens grouped by health capacity and environmental factors')

@section('content')
<div class="space-y-5">

    {{-- ── Status messages ── --}}
    @if (session('success'))
    <div class="flex items-center gap-2 px-4 py-2.5 rounded-xl border border-paper-rule dark:border-[#2b3530] bg-white dark:bg-[#1a201d] text-ink-800 dark:text-[#e4e1d8] text-sm">
        <x-heroicon-o-check-circle class="w-4 h-4 flex-shrink-0 text-low-600 dark:text-[#6dd89e]" />
        {{ session('success') }}
    </div>
    @endif
    @if (session('error'))
    <div class="flex items-center gap-2 px-4 py-2.5 rounded-xl border border-paper-rule dark:border-[#2b3530] bg-white dark:bg-[#1a201d] text-ink-800 dark:text-[#e4e1d8] text-sm">
        <x-heroicon-o-exclamation-circle class="w-4 h-4 flex-shrink-0 text-critical-600 dark:text-[#ef8d80]" />
        {{ session('error') }}
    </div>
    @endif

    {{-- ── Action Bar ── --}}
    <div class="flex justify-end gap-2">
        <form method="POST" action="{{ route('reports.cluster.snapshot') }}">
            @csrf
            <button type="submit" class="btn btn-secondary" data-loading="Saving"
                    title="Save today's cluster composition for longitudinal tracking">
                <x-heroicon-o-camera class="w-4 h-4" />
                Take Snapshot
            </button>
        </form>
        <a href="{{ route('reports.cluster.export') }}" class="btn">
            <x-heroicon-o-arrow-down-tray class="w-4 h-4" />
            Export CSV
        </a>
    </div>

    {{-- ── Profile Group Cards (risk averages per profile group) ── --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        @php $clusterBar = [1 => '#2ecc71', 2 => '#3498db', 3 => '#f39c12', 4 => '#e74c3c']; @endphp

        @foreach ($clusterSummary as $cluster)
        @php
            $cid = $cluster->cluster_named_id;
            $barColor = $clusterBar[$cid] ?? '#3a6fc4';
        @endphp
        <div class="card card-body transition-all duration-150 hover:shadow-md hover:-translate-y-0.5">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <span class="badge badge-cluster-{{ $cid }}">Group {{ $cid }}</span>
                    <h3 class="font-serif text-[15px] font-semibold text-ink-900 dark:text-[#e4e1d8] mt-2 leading-snug">{{ $cluster->cluster_name }}</h3>
                </div>
                <div class="text-right flex-shrink-0">
                    <div class="font-serif text-2xl font-semibold tnum text-ink-900 dark:text-[#e4e1d8] leading-none">{{ number_format($cluster->member_count) }}</div>
                    <div class="text-[10px] uppercase tracking-wider text-ink-400 dark:text-[#6b7570] mt-0.5">members</div>
                </div>
            </div>

            <div class="space-y-2 mt-4">
                @foreach ([
                    ['IC Risk',   $cluster->avg_ic_risk],
                    ['Env Risk',  $cluster->avg_env_risk],
                    ['Func Risk', $cluster->avg_func_risk],
                ] as [$label, $val])
                <div>
                    <div class="flex justify-between text-[11.5px] mb-1">
                        <span class="text-ink-500 dark:text-[#8a9087]">{{ $label }}</span>
                        <span class="font-mono font-semibold text-ink-800 dark:text-[#c8c4bc] tnum">{{ number_format($val * 100, 1) }}%</span>
                    </div>
                    <div class="bar">
                        <div class="bar-fill" style="width: {{ $val * 100 }}%; background: {{ $barColor }}"></div>
                    </div>
                </div>
                @endforeach
            </div>

            <div class="mt-4 pt-3 border-t border-paper-rule dark:border-[#2b3530] grid grid-cols-2 gap-3">
                <div>
                    <div class="eyebrow">Composite Risk</div>
                    <div class="font-mono font-semibold tnum text-base text-ink-900 dark:text-[#e4e1d8] mt-0.5">{{ number_format($cluster->avg_composite_risk * 100, 1) }}%</div>
                </div>
                <div>
                    <div class="eyebrow">Wellbeing</div>
                    <div class="font-mono font-semibold tnum text-base text-ink-900 dark:text-[#e4e1d8] mt-0.5">{{ number_format($cluster->avg_wellbeing * 100, 1) }}%</div>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- ── WHO Domain Chart per Cluster ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="card">
            <div class="card-head"><div class="card-title">WHO Domain Risk by Profile Group</div></div>
            <div class="card-body"><div class="relative h-56"><canvas id="domainByClusterChart"></canvas></div></div>
        </div>
        <div class="card">
            <div class="card-head"><div class="card-title">QoL Domain Scores by Profile Group</div></div>
            <div class="card-body"><div class="relative h-56"><canvas id="qolByClusterChart"></canvas></div></div>
        </div>
    </div>

    {{-- ── Evaluation Metrics ── --}}
    @if ($evalMetrics['silhouette'])
    <div class="card">
        <div class="card-head"><div class="card-title">Grouping Quality Indicators</div></div>
        <div class="card-body grid grid-cols-1 sm:grid-cols-3 gap-3 text-center">
            @foreach ([
                ['Group Separation',  $evalMetrics['silhouette'],        'Higher = more distinct groups (0–1)'],
                ['Group Distinctness',$evalMetrics['davies_bouldin'],    'Lower = better defined groups'],
                ['Group Density',     $evalMetrics['calinski_harabasz'], 'Higher = tighter groups'],
            ] as [$label, $val, $hint])
            <div class="rounded-xl p-3 bg-paper-2 dark:bg-[#131917] border border-paper-rule dark:border-[#2b3530]">
                <p class="text-[11px] text-ink-500 dark:text-[#8a9087] mb-1">{{ $label }}</p>
                <p class="font-serif text-xl font-semibold text-ink-900 dark:text-[#e4e1d8] tnum">{{ $val ? number_format($val, 3) : '—' }}</p>
                <p class="text-[10.5px] text-ink-400 dark:text-[#6b7570] mt-1 leading-snug">{{ $hint }}</p>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ── Barangay × Cluster Breakdown ── --}}
    <div class="card overflow-hidden">
        <div class="card-head"><div class="card-title">Barangay × Profile Group Distribution</div></div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="th">Barangay</th>
                        @foreach ([
                            1 => 'Group 1 · High Functioning',
                            2 => 'Group 2 · Stable / Moderate',
                            3 => 'Group 3 · Env / Financial Vulnerable',
                            4 => 'Group 4 · Multi-Domain Priority',
                        ] as $cid => $gl)
                        <th class="th text-center">
                            <span class="inline-flex items-center gap-1.5 justify-center">
                                <span class="cluster-swatch cluster-swatch-{{ $cid }}"></span>{{ $gl }}
                            </span>
                        </th>
                        @endforeach
                        <th class="th text-center">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                    $countCls = [
                        1 => 'text-[#1a6b3f] dark:text-[#6dd89e]',
                        2 => 'text-[#1a5a8a] dark:text-[#6dacd8]',
                        3 => 'text-[#9a6000] dark:text-[#d8b446]',
                        4 => 'text-[#8b1f15] dark:text-[#ef8d80]',
                    ];
                    @endphp
                    @foreach ($barangayCluster as $brgy => $rows)
                    @php
                        $byCluster = $rows->keyBy('cluster_named_id');
                        $total = $rows->sum('count');
                    @endphp
                    <tr class="hover:bg-paper-2 dark:hover:bg-[#131917] transition-colors">
                        <td class="td font-medium text-ink-800 dark:text-[#c8c4bc]">{{ $brgy }}</td>
                        @foreach ([1,2,3,4] as $cid)
                        <td class="td text-center tnum">
                            @if (isset($byCluster[$cid]))
                            <span class="font-semibold {{ $countCls[$cid] }}">{{ $byCluster[$cid]->count }}</span>
                            @else
                            <span class="text-ink-300 dark:text-[#3a4540]">0</span>
                            @endif
                        </td>
                        @endforeach
                        <td class="td text-center font-bold text-ink-900 dark:text-[#e4e1d8] tnum">{{ $total }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── Secondary analysis: tabbed card (Model Insights / Explorer / Snapshots) ── --}}
    <div x-data="{
        section: 'insights',
        insightsTab: 'ic',
        insights: null,
        labels: { ic: 'Intrinsic Capacity (IC)', env: 'Environment', func: 'Functional Ability' },
        async loadInsights() {
            if (this.insights !== null) return;
            try {
                const r = await fetch('{{ route('reports.xai.model-insights') }}');
                if (!r.ok) { this.insights = false; return; }
                this.insights = await r.json();
            } catch(e) { this.insights = false; }
        }
    }" x-init="loadInsights()" class="card overflow-hidden">

        {{-- Section switcher — prominent segmented pills --}}
        <div class="px-5 pt-4 pb-3 border-b border-paper-rule dark:border-[#2b3530]">
            <div class="inline-flex flex-wrap gap-1 bg-paper-2 dark:bg-[#202a26] border border-paper-rule dark:border-[#2b3530] rounded-xl p-1 overflow-x-auto max-w-full">
                @foreach ([
                    ['insights',  'Model Insights'],
                    ['explorer',  'Profile Group Explorer'],
                    ['snapshots', 'Snapshot History'],
                ] as [$key, $label])
                <button type="button"
                        @click="section = '{{ $key }}'"
                        :class="section === '{{ $key }}'
                            ? 'bg-forest-600 text-white shadow-sm'
                            : 'text-ink-600 dark:text-[#9aada5] hover:bg-white/60 dark:hover:bg-white/5'"
                        class="flex-shrink-0 px-4 py-2 text-[13px] font-semibold rounded-lg transition-colors duration-100 whitespace-nowrap">
                    {{ $label }}
                </button>
                @endforeach
            </div>
        </div>

        {{-- Model Insights panel --}}
        <div x-show="section === 'insights'">
            <div class="card-head flex-wrap gap-3 border-b-0">
                <div class="card-sub">Feature importance across <span x-text="insights && insights.n_seniors ? insights.n_seniors : '283'">283</span> seniors · top 15 per domain</div>
                <div class="segmented" role="tablist" aria-label="Domain">
                    <template x-for="[key, label] in Object.entries(labels)" :key="key">
                        <button type="button" @click="insightsTab = key" :class="{ 'on': insightsTab === key }"
                                :aria-selected="insightsTab === key" x-text="label"></button>
                    </template>
                </div>
            </div>
            <div class="card-body pt-2">
                <template x-if="insights === null">
                    <div class="space-y-2.5 py-1" aria-hidden="true">
                        <template x-for="i in 6" :key="i">
                            <div class="flex items-center gap-3">
                                <span class="w-44 h-3 rounded bg-paper-2 dark:bg-[#222a27] flex-shrink-0 animate-pulse"></span>
                                <span class="flex-1 h-2.5 rounded-full bg-paper-2 dark:bg-[#222a27] animate-pulse"></span>
                            </div>
                        </template>
                    </div>
                </template>
                <template x-if="insights === false">
                    <p class="text-sm text-ink-400 dark:text-[#8a9087] text-center py-8">Model insights unavailable. Start the inference service and refresh.</p>
                </template>
                <template x-if="insights && insights[insightsTab]">
                    <ol class="space-y-1">
                        <template x-for="(item, idx) in insights[insightsTab]" :key="item.feature">
                            <li class="flex items-center gap-3 rounded-lg px-2 -mx-2 py-1.5 hover:bg-paper-2 dark:hover:bg-[#131917] transition-colors">
                                <span class="w-5 text-right text-[11px] font-mono tnum text-ink-400 dark:text-[#6b7570] flex-shrink-0" x-text="idx + 1"></span>
                                <span class="text-[12.5px] text-ink-800 dark:text-[#c8c4bc] w-48 flex-shrink-0 truncate" x-text="item.label" :title="item.label"></span>
                                <div class="flex-1 bg-paper-rule dark:bg-[#222a27] rounded-full h-3 overflow-hidden">
                                    <div class="bg-forest-500 h-3 rounded-full transition-all duration-500"
                                         :style="'width: ' + Math.min(100, (item.importance / insights[insightsTab][0].importance) * 100) + '%'"></div>
                                </div>
                                <span class="text-[11.5px] font-mono tnum font-semibold text-forest-700 dark:text-forest-400 w-12 text-right"
                                      x-text="(item.importance * 100).toFixed(1) + '%'"></span>
                            </li>
                        </template>
                    </ol>
                </template>
            </div>
        </div>

        {{-- Profile Group Explorer panel --}}
        <div x-show="section === 'explorer'" class="p-5">
            <livewire:reports.cluster-analysis />
        </div>

        {{-- Snapshot History panel --}}
        <div x-show="section === 'snapshots'">
            @if ($snapshots->isEmpty())
            <div class="px-5 py-10 text-center text-sm text-ink-400 dark:text-[#4a5550]">
                No snapshots yet. Click <strong>Take Snapshot</strong> above to record today's profile group composition.
                <br class="mb-1">Snapshots are also taken automatically at 23:55 daily.
            </div>
            @else
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b border-paper-rule dark:border-[#2b3530] bg-paper dark:bg-[#131917]">
                            <th class="px-4 py-2.5 text-left font-semibold text-ink-600 dark:text-[#6b7d76]">Date</th>
                            <th class="px-4 py-2.5 text-left font-semibold text-ink-600 dark:text-[#6b7d76]">Group 1 — High Functioning</th>
                            <th class="px-4 py-2.5 text-left font-semibold text-ink-600 dark:text-[#6b7d76]">Group 2 — Stable / Moderate</th>
                            <th class="px-4 py-2.5 text-left font-semibold text-ink-600 dark:text-[#6b7d76]">Group 3 — Env / Financial Vulnerable</th>
                            <th class="px-4 py-2.5 text-left font-semibold text-ink-600 dark:text-[#6b7d76]">Group 4 — Multi-Domain Priority</th>
                            <th class="px-4 py-2.5 text-right font-semibold text-ink-600 dark:text-[#6b7d76]">Total</th>
                            <th class="px-4 py-2.5 text-right font-semibold text-ink-600 dark:text-[#6b7d76]">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-paper-rule dark:divide-[#2b3530]">
                        @foreach ($snapshots as $date => $clusters)
                        @php
                            $byId  = $clusters->keyBy('cluster_id');
                            $total = $clusters->sum('member_count');
                        @endphp
                        <tr class="hover:bg-paper dark:hover:bg-[#131917] transition-colors">
                            <td class="px-4 py-2.5 font-medium text-ink-900 dark:text-[#e4e1d8] whitespace-nowrap">
                                {{ \Carbon\Carbon::parse($date)->format('M d, Y') }}
                            </td>
                            @foreach ([1,2,3,4] as $cid)
                            @php
                                $c      = $byId[$cid] ?? null;
                                $colors = [1=>'text-emerald-600',2=>'text-blue-600',3=>'text-amber-600',4=>'text-rose-600'];
                            @endphp
                            <td class="px-4 py-2.5">
                                @if ($c)
                                <span class="{{ $colors[$cid] }} font-semibold">{{ $c->member_count }}</span>
                                <span class="text-ink-400 dark:text-[#4a5550] ml-1">avg risk {{ number_format($c->avg_composite_risk, 3) }}</span>
                                @else
                                <span class="text-ink-300 dark:text-[#3a4540]">—</span>
                                @endif
                            </td>
                            @endforeach
                            <td class="px-4 py-2.5 text-right font-medium text-ink-700 dark:text-[#b0b5b2]">{{ $total }}</td>
                            <td class="px-4 py-2.5 text-right">
                                @if (auth()->user()?->hasRole('admin'))
                                <div x-data="{ open: false }" class="inline-block">
                                    <button type="button" @click="open = true"
                                            class="btn btn-ghost text-[11px] px-2 py-1 text-critical-700 hover:bg-critical-50 hover:text-critical-900">
                                        Delete
                                    </button>
                                    <form x-ref="delForm" method="POST" action="{{ route('reports.cluster.snapshot.destroy', $date) }}" class="hidden">
                                        @csrf @method('DELETE')
                                    </form>
                                    <x-confirm-modal show="open"
                                                     title="Delete snapshot?"
                                                     confirm="$refs.delForm.submit()"
                                                     confirm-label="Delete permanently">
                                        <p>The profile group snapshot from <strong class="text-ink-900 dark:text-[#e4e1d8]">{{ \Carbon\Carbon::parse($date)->format('M d, Y') }}</strong> will be permanently removed.</p>
                                        <p class="mt-2 text-[12px] font-semibold px-3 py-2 rounded-xl text-critical-700 dark:text-[#e08070] bg-critical-50 dark:bg-critical-50/10 border border-critical-100 dark:border-critical-700/30">
                                            This cannot be undone.
                                        </p>
                                    </x-confirm-modal>
                                </div>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>

    </div>

</div>
@endsection

@push('scripts')
<script>
(function () {
    const clusterLabels  = [
        'Group 1: High Functioning',
        'Group 2: Stable / Moderate',
        'Group 3: Env / Financial Vulnerable',
        'Group 4: Multi-Domain Priority',
    ];
    const clusterColors  = ['rgb(46,204,113)', 'rgb(52,152,219)', 'rgb(243,156,18)', 'rgb(231,76,60)'];
    const clusterBgAlpha = ['rgba(46,204,113,0.2)', 'rgba(52,152,219,0.2)', 'rgba(243,156,18,0.2)', 'rgba(231,76,60,0.2)'];
    const domainByCluster = @json($domainByCluster);
    const qolByCluster    = @json($qolByCluster);

    function upsert(id, config) {
        const canvas = document.getElementById(id);
        if (!canvas) return;
        const existing = Object.values(Chart.instances).find(c => c.canvas === canvas);
        if (existing) existing.destroy();
        new Chart(canvas, config);
    }

    function initClusterCharts() {
        upsert('domainByClusterChart', {
            type: 'bar',
            data: {
                labels: ['Intrinsic Capacity', 'Environment', 'Functional Ability'],
                datasets: [1,2,3,4].map((cid, i) => ({
                    label: clusterLabels[i],
                    data: [
                        domainByCluster[cid]?.ic   ?? 0,
                        domainByCluster[cid]?.env  ?? 0,
                        domainByCluster[cid]?.func ?? 0,
                    ].map(v => +(v * 100).toFixed(1)),
                    backgroundColor: clusterBgAlpha[i],
                    borderColor: clusterColors[i],
                    borderWidth: 2,
                    borderRadius: 4,
                }))
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { labels: { font: { size: 11 } } } },
                scales: {
                    y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%', font: { size: 10 } } },
                    x: { ticks: { font: { size: 10 } } },
                }
            }
        });

        upsert('qolByClusterChart', {
            type: 'radar',
            data: {
                labels: ['Physical','Psychological','Social','Financial','Environment','Overall'],
                datasets: [1,2,3,4].map((cid, i) => ({
                    label: clusterLabels[i],
                    data: [
                        qolByCluster[cid]?.physical        ?? 0,
                        qolByCluster[cid]?.psychological    ?? 0,
                        qolByCluster[cid]?.social           ?? 0,
                        qolByCluster[cid]?.financial        ?? 0,
                        qolByCluster[cid]?.environment      ?? 0,
                        qolByCluster[cid]?.overall          ?? 0,
                    ].map(v => +(v * 100).toFixed(1)),
                    backgroundColor: clusterBgAlpha[i],
                    borderColor: clusterColors[i],
                    pointBackgroundColor: clusterColors[i],
                    borderWidth: 2,
                    pointRadius: 3,
                }))
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { labels: { font: { size: 10 } } } },
                scales: {
                    r: {
                        min: 0, max: 100,
                        ticks: { stepSize: 25, font: { size: 9 } },
                        pointLabels: { font: { size: 9 } },
                    }
                }
            }
        });
    }

    document.addEventListener('livewire:navigated', () => setTimeout(initClusterCharts, 0));
    // Fallback for a direct page load / hard refresh (livewire:navigated only fires
    // on SPA navigation). upsert() destroys any existing chart, so this is idempotent.
    if (document.readyState !== 'loading') setTimeout(initClusterCharts, 0);
    else document.addEventListener('DOMContentLoaded', () => setTimeout(initClusterCharts, 0));
})();
</script>
@endpush

