{{-- resources/views/livewire/reports/cluster-analysis.blade.php --}}
<div class="space-y-5">

    {{-- ── Eval Metrics Banner ── --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        @php
        $metrics = [
            ['label' => 'K (Clusters)',           'value' => $evalMetrics['k_chosen'],                   'note' => 'Validated K=3',         'good' => true],
            ['label' => 'Silhouette Score',        'value' => number_format($evalMetrics['silhouette'],3), 'note' => 'Higher is better (>0.3)', 'good' => $evalMetrics['silhouette'] > 0.3],
            ['label' => 'Davies-Bouldin',          'value' => number_format($evalMetrics['davies_bouldin'],3), 'note' => 'Lower is better (<1.5)', 'good' => $evalMetrics['davies_bouldin'] < 1.5],
            ['label' => 'Calinski-Harabasz',       'value' => number_format($evalMetrics['calinski_harabasz'],1), 'note' => 'Higher is better', 'good' => true],
        ];
        @endphp
        @foreach ($metrics as $m)
        <div class="bg-white border rounded-xl p-4 shadow-sm text-center {{ $m['good'] ? 'border-emerald-200' : 'border-amber-200' }}">
            <p class="text-2xl font-bold text-slate-800">{{ $m['value'] }}</p>
            <p class="text-xs font-semibold text-slate-600 mt-0.5">{{ $m['label'] }}</p>
            <p class="text-xs {{ $m['good'] ? 'text-emerald-600' : 'text-amber-600' }} mt-0.5">{{ $m['note'] }}</p>
        </div>
        @endforeach
    </div>

    {{-- ── Filter ── --}}
    <div class="flex items-center gap-3">
        <select wire:model.live="selectedBarangay"
                class="text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-teal-500 shadow-sm">
            <option value="">All Barangays</option>
            @foreach (\App\Models\SeniorCitizen::barangayList() as $brgy)
            <option value="{{ $brgy }}">{{ $brgy }}</option>
            @endforeach
        </select>
        <span class="text-sm text-slate-500">{{ $results->count() }} records</span>

        <div class="ml-auto">
            <a href="{{ route('reports.cluster.export') }}"
               class="px-4 py-2 text-sm font-medium border border-slate-200 bg-white text-slate-600 rounded-lg hover:bg-slate-50 transition-colors shadow-sm">
                📥 Export CSV
            </a>
        </div>
    </div>

    {{-- ── Cluster Cards ── --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        @php
        $clusterStyles = [
            1 => ['bg' => 'bg-emerald-50', 'border' => 'border-emerald-200', 'badge' => 'bg-emerald-100 text-emerald-700', 'bar' => 'bg-emerald-500', 'icon' => '🟢'],
            2 => ['bg' => 'bg-amber-50',   'border' => 'border-amber-200',   'badge' => 'bg-amber-100 text-amber-700',     'bar' => 'bg-amber-500',   'icon' => '🟡'],
            3 => ['bg' => 'bg-rose-50',    'border' => 'border-rose-200',    'badge' => 'bg-rose-100 text-rose-700',       'bar' => 'bg-rose-500',    'icon' => '🔴'],
        ];
        @endphp
        @foreach ($clusterSummaries as $clusterId => $summary)
        @php $style = $clusterStyles[$clusterId] ?? $clusterStyles[2]; @endphp
        <div class="bg-white border rounded-xl shadow-sm overflow-hidden {{ $style['border'] }}">
            <div class="{{ $style['bg'] }} px-5 py-4 border-b {{ $style['border'] }}">
                <div class="flex items-center gap-2">
                    <span class="text-xl">{{ $style['icon'] }}</span>
                    <div>
                        <p class="font-bold text-slate-800">Cluster {{ $clusterId }}</p>
                        <p class="text-xs text-slate-600">{{ $summary['name'] }}</p>
                    </div>
                    <span class="ml-auto text-lg font-bold text-slate-700">{{ $summary['count'] }}</span>
                </div>
            </div>
            <div class="px-5 py-4 space-y-3">
                {{-- Domain Risk Bars --}}
                @foreach (['avg_ic' => 'Intrinsic Capacity', 'avg_env' => 'Environment', 'avg_func' => 'Functional'] as $key => $label)
                <div>
                    <div class="flex justify-between text-xs text-slate-500 mb-1">
                        <span>{{ $label }}</span>
                        <span class="font-medium text-slate-700">{{ round($summary[$key] * 100) }}%</span>
                    </div>
                    <div class="w-full bg-slate-100 rounded-full h-2">
                        <div class="{{ $style['bar'] }} h-2 rounded-full transition-all"
                             style="width: {{ round($summary[$key] * 100) }}%"></div>
                    </div>
                </div>
                @endforeach

                <div class="pt-2 border-t border-slate-100 grid grid-cols-2 gap-2 text-xs">
                    <div class="text-center p-2 bg-red-50 rounded-lg">
                        <p class="font-bold text-red-700">{{ $summary['critical_count'] + $summary['high_count'] }}</p>
                        <p class="text-slate-500">High/Critical</p>
                    </div>
                    <div class="text-center p-2 bg-slate-50 rounded-lg">
                        <p class="font-bold text-slate-700">{{ $summary['barangay_top'] ?? '—' }}</p>
                        <p class="text-slate-500">Top Barangay</p>
                    </div>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- ── Domain Risk Chart ── --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-700 mb-4">WHO Domain Risk by Cluster (%)</h3>
            <div class="h-52">
                <canvas id="domainClusterChart"></canvas>
            </div>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-700 mb-4">Risk Level Distribution by Cluster</h3>
            <div class="space-y-3 mt-2">
                @foreach ($riskByCluster as $clusterId => $dist)
                @php
                $total = array_sum($dist);
                $colors = ['LOW' => 'bg-emerald-400', 'MODERATE' => 'bg-amber-400', 'HIGH' => 'bg-orange-500', 'CRITICAL' => 'bg-red-500'];
                @endphp
                <div>
                    <p class="text-xs font-semibold text-slate-600 mb-1">Cluster {{ $clusterId }}</p>
                    <div class="flex h-5 rounded-full overflow-hidden gap-px">
                        @foreach ($dist as $level => $count)
                        @if ($count > 0)
                        <div class="{{ $colors[$level] }} flex items-center justify-center text-white text-xs font-bold"
                             style="width: {{ $total > 0 ? round($count / $total * 100) : 0 }}%"
                             title="{{ $level }}: {{ $count }}">
                            {{ $count > 2 ? $count : '' }}
                        </div>
                        @endif
                        @endforeach
                    </div>
                    <div class="flex gap-3 mt-1">
                        @foreach ($dist as $level => $count)
                        <span class="text-xs text-slate-500">{{ $level }}: <strong>{{ $count }}</strong></span>
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ── Member Table ── --}}
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-700">Cluster Member Records</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 border-b border-slate-100">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500">Senior Citizen</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500">Barangay</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500 cursor-pointer hover:text-slate-700"
                            wire:click="sortColumn('cluster_named_id')">Cluster</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500 cursor-pointer hover:text-slate-700"
                            wire:click="sortColumn('composite_risk')">
                            Composite Risk {{ $sortBy === 'composite_risk' ? ($sortDir === 'asc' ? '↑' : '↓') : '' }}
                        </th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500">IC Risk</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500">Env Risk</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500">Func Risk</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500">Overall</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse ($results as $result)
                    @php
                    $senior = $result->seniorCitizen;
                    $clusterColors = [1 => 'text-emerald-700 bg-emerald-50', 2 => 'text-amber-700 bg-amber-50', 3 => 'text-rose-700 bg-rose-50'];
                    $riskColors = ['LOW' => 'bg-emerald-100 text-emerald-700', 'MODERATE' => 'bg-amber-100 text-amber-700', 'HIGH' => 'bg-orange-100 text-orange-700', 'CRITICAL' => 'bg-red-100 text-red-700'];
                    @endphp
                    <tr class="hover:bg-slate-25 transition-colors">
                        <td class="px-4 py-3 font-medium text-slate-800">{{ $senior?->full_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $senior?->barangay }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold
                                {{ $clusterColors[$result->cluster_named_id] ?? 'text-slate-600 bg-slate-100' }}">
                                C{{ $result->cluster_named_id }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <div class="flex items-center justify-center gap-2">
                                <div class="w-16 bg-slate-100 rounded-full h-1.5">
                                    <div class="h-1.5 rounded-full {{ $result->composite_risk > 0.65 ? 'bg-red-500' : ($result->composite_risk > 0.45 ? 'bg-amber-500' : 'bg-emerald-500') }}"
                                         style="width: {{ round($result->composite_risk * 100) }}%"></div>
                                </div>
                                <span class="text-xs font-mono font-semibold text-slate-700">{{ number_format($result->composite_risk, 3) }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center text-xs font-mono text-slate-600">{{ number_format($result->ic_risk, 3) }}</td>
                        <td class="px-4 py-3 text-center text-xs font-mono text-slate-600">{{ number_format($result->env_risk, 3) }}</td>
                        <td class="px-4 py-3 text-center text-xs font-mono text-slate-600">{{ number_format($result->func_risk, 3) }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-0.5 rounded-full text-xs font-bold
                                {{ $riskColors[$result->overall_risk_level] ?? 'bg-slate-100 text-slate-600' }}">
                                {{ $result->overall_risk_level }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('seniors.show', $result->senior_citizen_id) }}"
                               class="text-xs text-teal-600 hover:text-teal-700 font-medium">View →</a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="px-4 py-10 text-center text-slate-400 text-sm">
                            No cluster analysis results found. Run the ML pipeline to generate data.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const domainData = @json($domainChart);
    new Chart(document.getElementById('domainClusterChart'), {
        type: 'bar',
        data: {
            labels: domainData.labels,
            datasets: domainData.datasets,
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { font: { size: 10 }, padding: 10 } }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                y: {
                    beginAtZero: true, max: 100,
                    ticks: { font: { size: 10 }, callback: v => v + '%' },
                    grid: { color: 'rgba(0,0,0,0.04)' }
                }
            }
        }
    });
});
</script>
@endpush
