{{-- resources/views/reports/cluster.blade.php --}}
@extends('layouts.app')
@section('page-title', 'Health Group Analysis')
@section('page-subtitle', 'Senior citizens grouped by health capacity and environmental factors')

@section('content')
<div class="space-y-5">

    {{-- ── Action Bar ── --}}
    @if (session('success'))
    <div class="flex items-center gap-2 px-4 py-2.5 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">
        <x-heroicon-o-check-circle class="w-4 h-4 flex-shrink-0" />
        {{ session('success') }}
    </div>
    @endif
    @if (session('error'))
    <div class="flex items-center gap-2 px-4 py-2.5 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm">
        <x-heroicon-o-exclamation-circle class="w-4 h-4 flex-shrink-0" />
        {{ session('error') }}
    </div>
    @endif

    <div class="flex justify-end gap-2">
        <form method="POST" action="{{ route('reports.cluster.snapshot') }}">
            @csrf
            <button type="submit"
                    class="flex items-center gap-2 px-4 py-2 text-sm font-medium border border-slate-200 text-slate-600 rounded-lg hover:bg-slate-50 transition-colors"
                    title="Save today's cluster composition for longitudinal tracking">
                <x-heroicon-o-camera class="w-4 h-4" />
                Take Snapshot
            </button>
        </form>
        <a href="{{ route('reports.cluster.export') }}"
           class="flex items-center gap-2 px-4 py-2 text-sm font-medium border border-slate-200 text-slate-600 rounded-lg hover:bg-slate-50 transition-colors">
            <x-heroicon-o-arrow-down-tray class="w-4 h-4" />
            Export CSV
        </a>
    </div>

    {{-- ── Cluster Cards ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        @php
        $clusterColors = [
            1 => ['bg' => 'bg-emerald-50', 'border' => 'border-emerald-200', 'text' => 'text-emerald-700', 'bar' => 'bg-emerald-500'],
            2 => ['bg' => 'bg-amber-50',   'border' => 'border-amber-200',   'text' => 'text-amber-700',   'bar' => 'bg-amber-500'],
            3 => ['bg' => 'bg-rose-50',    'border' => 'border-rose-200',    'text' => 'text-rose-700',    'bar' => 'bg-rose-500'],
        ];
        @endphp

        @foreach ($clusterSummary as $cluster)
        @php $c = $clusterColors[$cluster->cluster_named_id] ?? $clusterColors[1]; @endphp
        <div class="{{ $c['bg'] }} border {{ $c['border'] }} rounded-xl p-5">
            <div class="flex items-start justify-between mb-3">
                <div>
                    <span class="text-xs font-bold {{ $c['text'] }} uppercase tracking-wider">Cluster {{ $cluster->cluster_named_id }}</span>
                    <h3 class="font-display text-lg text-slate-800 mt-0.5">{{ $cluster->cluster_name }}</h3>
                </div>
                <span class="text-2xl font-bold text-slate-800">{{ number_format($cluster->member_count) }}</span>
            </div>

            <div class="space-y-2 mt-4">
                @foreach ([
                    ['IC Risk',   $cluster->avg_ic_risk],
                    ['Env Risk',  $cluster->avg_env_risk],
                    ['Func Risk', $cluster->avg_func_risk],
                ] as [$label, $val])
                <div>
                    <div class="flex justify-between text-xs mb-0.5">
                        <span class="text-slate-500">{{ $label }}</span>
                        <span class="{{ $c['text'] }} font-semibold">{{ number_format($val * 100, 1) }}%</span>
                    </div>
                    <div class="bg-white/60 rounded-full h-1.5">
                        <div class="{{ $c['bar'] }} h-1.5 rounded-full" style="width: {{ $val * 100 }}%"></div>
                    </div>
                </div>
                @endforeach
            </div>

            <div class="mt-3 pt-3 border-t {{ $c['border'] }} flex justify-between text-xs">
                <span class="text-slate-500">Avg Composite Risk</span>
                <span class="{{ $c['text'] }} font-bold">{{ number_format($cluster->avg_composite_risk * 100, 1) }}%</span>
            </div>
            <div class="flex justify-between text-xs mt-1">
                <span class="text-slate-500">Avg Wellbeing</span>
                <span class="{{ $c['text'] }} font-bold">{{ number_format($cluster->avg_wellbeing * 100, 1) }}%</span>
            </div>
        </div>
        @endforeach
    </div>

    {{-- ── WHO Domain Chart per Cluster ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-700 mb-4">WHO Domain Risk by Cluster</h3>
            <div class="relative h-56">
                <canvas id="domainByClusterChart"></canvas>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-700 mb-4">QoL Domain Scores by Cluster</h3>
            <div class="relative h-56">
                <canvas id="qolByClusterChart"></canvas>
            </div>
        </div>
    </div>

    {{-- ── Evaluation Metrics ── --}}
    @if ($evalMetrics['silhouette'])
    <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
        <h3 class="text-sm font-semibold text-slate-700 mb-3">Grouping Quality Indicators</h3>
        <div class="grid grid-cols-4 gap-4 text-center">
            @foreach ([
                ['Group Separation',  $evalMetrics['silhouette'],        'Higher = more distinct groups (0–1)'],
                ['Group Distinctness',$evalMetrics['davies_bouldin'],    'Lower = better defined groups'],
                ['Group Density',     $evalMetrics['calinski_harabasz'], 'Higher = tighter groups'],
                ['Spread Score',      $evalMetrics['inertia'],           'How spread out members are within groups'],
            ] as [$label, $val, $hint])
            <div class="bg-slate-50 rounded-xl p-3">
                <p class="text-xs text-slate-500 mb-1">{{ $label }}</p>
                <p class="text-xl font-bold text-slate-800">{{ $val ? number_format($val, 3) : '—' }}</p>
                <p class="text-xs text-slate-400 mt-1">{{ $hint }}</p>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ── Barangay × Cluster Breakdown ── --}}
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="bg-slate-50 border-b border-slate-100 px-5 py-3">
            <h3 class="text-sm font-semibold text-slate-700">Barangay × Cluster Distribution</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-slate-100">
                    <tr class="text-xs text-slate-400">
                        <th class="px-5 py-2.5 text-left font-medium">Barangay</th>
                        <th class="px-5 py-2.5 text-center font-medium text-emerald-600">Group 1 – High Functioning</th>
                        <th class="px-5 py-2.5 text-center font-medium text-amber-600">Group 2 – Moderate / Mixed</th>
                        <th class="px-5 py-2.5 text-center font-medium text-rose-600">Group 3 – Low Functioning</th>
                        <th class="px-5 py-2.5 text-center font-medium text-slate-500">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @foreach ($barangayCluster as $brgy => $rows)
                    @php
                        $byCluster = $rows->keyBy('cluster_named_id');
                        $total = $rows->sum('count');
                    @endphp
                    <tr class="hover:bg-slate-25">
                        <td class="px-5 py-2.5 font-medium text-slate-700">{{ $brgy }}</td>
                        @foreach ([1,2,3] as $cid)
                        <td class="px-5 py-2.5 text-center">
                            @if (isset($byCluster[$cid]))
                            <span class="font-semibold {{ ['1'=>'text-emerald-700','2'=>'text-amber-700','3'=>'text-rose-700'][$cid] }}">
                                {{ $byCluster[$cid]->count }}
                            </span>
                            @else
                            <span class="text-slate-300">0</span>
                            @endif
                        </td>
                        @endforeach
                        <td class="px-5 py-2.5 text-center font-bold text-slate-700">{{ $total }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── Interactive Livewire Drill-Down ── --}}
    <div class="mt-2">
        <h3 class="text-sm font-semibold text-slate-700 mb-3">Interactive Cluster Explorer</h3>
        <livewire:reports.cluster-analysis />
    </div>

</div>
@endsection

@push('scripts')
<script>
(function () {
    const clusterLabels  = ['Group 1: High Functioning', 'Group 2: Moderate/Mixed', 'Group 3: Low Functioning'];
    const clusterColors  = ['rgb(16,185,129)', 'rgb(245,158,11)', 'rgb(244,63,94)'];
    const clusterBgAlpha = ['rgba(16,185,129,0.2)', 'rgba(245,158,11,0.2)', 'rgba(244,63,94,0.2)'];
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
                labels: ['Physical Capacity', 'Environment', 'Daily Functioning'],
                datasets: [1,2,3].map((cid, i) => ({
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
                datasets: [1,2,3].map((cid, i) => ({
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
})();
</script>
@endpush
