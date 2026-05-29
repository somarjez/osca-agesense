{{-- resources/views/livewire/reports/cluster-analysis.blade.php --}}
<div class="space-y-6">

    {{-- ── Eval metrics ── --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        @php
        $fmt = fn($v, $d) => $v !== null ? number_format($v, $d) : '—';
        $metrics = [
            ['Health Groups',      $evalMetrics['k_chosen'],                                        $evalMetrics['k_chosen'] . ' groups identified', true],
            ['Group Separation',   $fmt($evalMetrics['silhouette'], 3),                             'Higher is better · ≥0.30',  $evalMetrics['silhouette'] !== null && $evalMetrics['silhouette'] > 0.3],
            ['Group Distinctness', $fmt($evalMetrics['davies_bouldin'], 3),                         'Lower is better · ≤1.50',   $evalMetrics['davies_bouldin'] !== null && $evalMetrics['davies_bouldin'] < 1.5],
            ['Group Density',      $fmt($evalMetrics['calinski_harabasz'], 1),                      'Higher is better',          true],
        ];
        @endphp
        @foreach ($metrics as [$label, $value, $note, $good])
        <div class="kpi">
            <div class="kpi-rule {{ $good ? 'bg-low-500' : 'bg-moderate-500' }}"></div>
            <div class="kpi-label">{{ $label }}</div>
            <div class="kpi-value">{{ $value }}</div>
            <div class="kpi-delta {{ $good ? 'text-low-700' : 'text-moderate-700' }}">{{ $note }}</div>
        </div>
        @endforeach
    </div>

    {{-- ── Filter + export ── --}}
    <div class="flex items-center gap-3">
        <div class="eyebrow">Filter</div>
        <select wire:model.live="selectedBarangay" class="form-select max-w-[200px] py-1.5 text-[13px]">
            <option value="">All Barangays</option>
            @foreach (\App\Models\SeniorCitizen::barangayList() as $brgy)
                <option value="{{ $brgy }}">{{ $brgy }}</option>
            @endforeach
        </select>
        <span class="text-sm text-ink-500">{{ $results->count() }} total records</span>
        <a href="{{ route('reports.cluster.export') }}" class="btn ml-auto">Export CSV</a>
    </div>

    {{-- ── Cluster cards ── --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">
        @foreach ($clusterSummaries as $clusterId => $summary)
        <div class="card">
            <div class="card-head">
                <div class="flex items-center gap-2.5">
                    <span class="cluster-swatch cluster-swatch-{{ $clusterId }}"></span>
                    <div>
                        <div class="card-title">Cluster {{ $clusterId }}</div>
                        <div class="card-sub">{{ $summary['name'] }}</div>
                    </div>
                </div>
                <div class="text-right">
                    <div class="font-serif text-2xl font-semibold tnum text-ink-900 dark:text-[#e4e1d8]">{{ $summary['count'] }}</div>
                    <div class="text-[10.5px] uppercase tracking-wider text-ink-400 dark:text-[#6b7570]">members</div>
                </div>
            </div>
            <div class="card-body space-y-3">
                @foreach (['avg_ic' => 'Intrinsic Capacity', 'avg_env' => 'Environment', 'avg_func' => 'Functional Ability'] as $key => $label)
                <div>
                    <div class="flex justify-between text-[11.5px] mb-1">
                        <span class="text-ink-500">{{ $label }}</span>
                        <span class="font-mono font-semibold text-ink-900 dark:text-[#e4e1d8] tnum">{{ round($summary[$key] * 100) }}%</span>
                    </div>
                    <div class="bar">
                        <div class="bar-fill bar-fill-forest" style="width: {{ round($summary[$key] * 100) }}%"></div>
                    </div>
                </div>
                @endforeach

                <div class="pt-3 mt-2 border-t border-paper-rule dark:border-[#2b3530] grid grid-cols-2 gap-3 text-xs">
                    <div>
                        <div class="eyebrow">High Risk</div>
                        <div class="font-mono font-semibold text-high-700 tnum text-base mt-0.5">{{ $summary['high_count'] }}</div>
                    </div>
                    <div>
                        <div class="eyebrow">Top Barangay</div>
                        <div class="text-ink-900 dark:text-[#e4e1d8] font-semibold mt-0.5 truncate">{{ $summary['barangay_top'] ?? '—' }}</div>
                    </div>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- ── Charts row ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <x-card title="Risk by Health Group" sub="Score out of 100%">
            <div wire:ignore class="h-56"><canvas id="domainClusterChart"></canvas></div>
        </x-card>

        <x-card title="Risk Level Distribution by Cluster" sub="Stacked composition">
            <div class="space-y-4 mt-1">
                @foreach ($riskByCluster as $clusterId => $dist)
                @php
                $total = array_sum($dist);
                $colors = ['LOW' => 'bg-low-500', 'MODERATE' => 'bg-moderate-500', 'HIGH' => 'bg-high-500'];
                @endphp
                <div>
                    <div class="flex items-center gap-2 mb-1.5">
                        <span class="cluster-swatch cluster-swatch-{{ $clusterId }}"></span>
                        <span class="text-[12.5px] font-semibold text-ink-900 dark:text-[#e4e1d8]">Cluster {{ $clusterId }}</span>
                        <span class="text-[11px] text-ink-500 dark:text-[#6b7570] ml-auto tnum">{{ $total }} members</span>
                    </div>
                    <div class="flex h-5 rounded overflow-hidden gap-px bg-paper-2 dark:bg-[#1a201d]">
                        @foreach ($dist as $level => $count)
                            @if ($count > 0)
                            <div class="{{ $colors[$level] }} grid place-items-center text-white text-[10px] font-bold tnum"
                                 style="width: {{ $total > 0 ? round($count / $total * 100) : 0 }}%"
                                 title="{{ $level }}: {{ $count }}">
                                {{ $count > 2 ? $count : '' }}
                            </div>
                            @endif
                        @endforeach
                    </div>
                    <div class="flex flex-wrap gap-3 mt-1.5">
                        @foreach ($dist as $level => $count)
                            <span class="text-[11px] text-ink-500 dark:text-[#6b7570]">{{ $level }}: <strong class="text-ink-900 dark:text-[#e4e1d8] tnum">{{ $count }}</strong></span>
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>
        </x-card>
    </div>

    {{-- ── Member table ── --}}
    <x-card title="Cluster Member Records">
        <x-slot name="noPadding">true</x-slot>
        <table class="w-full text-sm">
            <thead>
                <tr>
                    <th class="th">Senior Citizen</th>
                    <th class="th">Barangay</th>
                    <th class="th text-center cursor-pointer" wire:click="sortColumn('cluster_named_id')">Cluster</th>
                    <th class="th cursor-pointer" wire:click="sortColumn('composite_risk')">
                        Composite Risk {{ $sortBy === 'composite_risk' ? ($sortDir === 'asc' ? '↑' : '↓') : '' }}
                    </th>
                    <th class="th text-center">IC</th>
                    <th class="th text-center">ENV</th>
                    <th class="th text-center">FA</th>
                    <th class="th text-center">Overall</th>
                    <th class="th"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($records as $result)
                @php $senior = $result->seniorCitizen; @endphp
                <tr class="hover:bg-forest-50/40 dark:hover:bg-forest-900/10 transition-colors">
                    <td class="td font-semibold text-ink-900 dark:text-[#e4e1d8]">{{ $senior?->full_name ?? '—' }}</td>
                    <td class="td text-ink-500 dark:text-[#8a9087]">{{ $senior?->barangay }}</td>
                    <td class="td text-center"><x-cluster-badge :id="$result->cluster_named_id" /></td>
                    <td class="td"><x-risk-bar :value="$result->composite_risk" /></td>
                    <td class="td text-center font-mono text-[11.5px] text-ink-700 dark:text-[#b0b5b2] tnum">{{ number_format($result->ic_risk, 3) }}</td>
                    <td class="td text-center font-mono text-[11.5px] text-ink-700 dark:text-[#b0b5b2] tnum">{{ number_format($result->env_risk, 3) }}</td>
                    <td class="td text-center font-mono text-[11.5px] text-ink-700 dark:text-[#b0b5b2] tnum">{{ number_format($result->func_risk, 3) }}</td>
                    <td class="td text-center"><x-risk-badge :level="$result->overall_risk_level" /></td>
                    <td class="td text-right">
                        <a href="{{ route('seniors.show', $result->senior_citizen_id) }}" class="text-xs text-forest-700 hover:text-forest-900 font-semibold">View →</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="9" class="td text-center py-12 text-ink-400">
                        No results found. Run the health assessment to generate data.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        @if ($records->hasPages())
        <div class="border-t border-paper-rule dark:border-[#2b3530] px-5 py-3">
            {{ $records->links() }}
        </div>
        @endif
    </x-card>

    {{-- Chart data for JS --}}
    <script type="application/json" id="cluster-chart-data">
    {!! json_encode($domainChart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
    </script>
</div>

@push('scripts')
<script>
(function () {
    function isDark() { return document.documentElement.classList.contains('dark'); }

    function chartColors() {
        const dark = isDark();
        return {
            grid:     dark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)',
            tick:     dark ? '#6b7570' : '#8a8f86',
            legend:   dark ? '#8a9087' : '#6b7269',
        };
    }

    function upsertCluster(id, config) {
        const canvas = document.getElementById(id);
        if (!canvas) return;
        const existing = Object.values(Chart.instances).find(c => c.canvas === canvas);
        if (existing) existing.destroy();
        new Chart(canvas, config);
    }

    function renderClusterDomainChart() {
        const el = document.getElementById('cluster-chart-data');
        if (!el) return;
        const domainData = JSON.parse(el.textContent);
        const palette = ['#4a8a68', '#c19a3b', '#b94a3a'];
        const datasets = (domainData.datasets || []).map((ds, i) => ({
            ...ds,
            backgroundColor: palette[i] ?? '#3f8068',
            borderRadius: 3,
            borderSkipped: false,
            barPercentage: 0.7,
        }));
        const c = chartColors();

        upsertCluster('domainClusterChart', {
            type: 'bar',
            data: { labels: domainData.labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 11 }, padding: 12, boxWidth: 10, boxHeight: 10, color: c.legend }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 11, weight: 500 }, color: c.tick }
                    },
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: { callback: v => v + '%', color: c.tick },
                        grid: { color: c.grid }
                    }
                }
            }
        });
    }

    new MutationObserver(() => setTimeout(renderClusterDomainChart, 50))
        .observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

    document.addEventListener('livewire:navigated', () => setTimeout(renderClusterDomainChart, 0));
    document.addEventListener('livewire:updated', renderClusterDomainChart);
    renderClusterDomainChart();
})();
</script>
@endpush
