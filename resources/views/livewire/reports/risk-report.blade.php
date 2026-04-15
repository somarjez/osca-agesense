{{-- resources/views/livewire/reports/risk-report.blade.php --}}
<div class="space-y-5">

    {{-- ── Summary Cards ── --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        @php
        $levels = ['CRITICAL' => ['color' => 'red', 'icon' => '🚨'], 'HIGH' => ['color' => 'orange', 'icon' => '⚠️'], 'MODERATE' => ['color' => 'amber', 'icon' => '📊'], 'LOW' => ['color' => 'green', 'icon' => '✅']];
        $colorBg = ['red' => 'bg-red-50 border-red-200', 'orange' => 'bg-orange-50 border-orange-200', 'amber' => 'bg-amber-50 border-amber-200', 'green' => 'bg-emerald-50 border-emerald-200'];
        $colorText = ['red' => 'text-red-700', 'orange' => 'text-orange-700', 'amber' => 'text-amber-700', 'green' => 'text-emerald-700'];
        @endphp
        @foreach ($levels as $level => $meta)
        @php $stat = $summaryStats[$level] ?? null; @endphp
        <div class="bg-white border rounded-xl p-4 shadow-sm {{ $colorBg[$meta['color']] }} cursor-pointer hover:shadow-md transition-shadow"
             wire:click="$set('filterRisk', '{{ strtolower($level) }}')">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xl">{{ $meta['icon'] }}</span>
                <span class="text-xs font-medium {{ $colorText[$meta['color']] }}">{{ $level }}</span>
            </div>
            <p class="text-2xl font-bold text-slate-800">{{ $stat?->count ?? 0 }}</p>
            <p class="text-xs text-slate-500 mt-0.5">Avg risk: {{ $stat ? number_format($stat->avg_risk, 3) : '—' }}</p>
        </div>
        @endforeach
    </div>

    {{-- ── Filters ── --}}
    <div class="flex flex-wrap items-center gap-3 bg-white border border-slate-200 rounded-xl px-4 py-3 shadow-sm">
        <select wire:model.live="filterRisk"
                class="text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500">
            <option value="">All Risk Levels</option>
            @foreach (['CRITICAL','HIGH','MODERATE','LOW'] as $lvl)
            <option value="{{ strtolower($lvl) }}">{{ $lvl }}</option>
            @endforeach
        </select>

        <select wire:model.live="filterBarangay"
                class="text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500">
            <option value="">All Barangays</option>
            @foreach (\App\Models\SeniorCitizen::barangayList() as $brgy)
            <option value="{{ $brgy }}">{{ $brgy }}</option>
            @endforeach
        </select>

        <select wire:model.live="filterCluster"
                class="text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500">
            <option value="">All Clusters</option>
            <option value="1">Cluster 1 — High Functioning</option>
            <option value="2">Cluster 2 — Moderate / Mixed</option>
            <option value="3">Cluster 3 — Low Functioning</option>
        </select>

        @if ($filterRisk || $filterBarangay || $filterCluster)
        <button wire:click="$set('filterRisk',''); $set('filterBarangay',''); $set('filterCluster','')"
                class="text-xs text-rose-500 hover:text-rose-700 font-medium">
            ✕ Clear filters
        </button>
        @endif

        <div class="ml-auto">
            <a href="{{ route('reports.risk.export') }}"
               class="px-4 py-2 text-sm font-medium border border-slate-200 bg-white text-slate-600 rounded-lg hover:bg-slate-50 transition-colors shadow-sm">
                📥 Export CSV
            </a>
        </div>
    </div>

    {{-- ── Data Table ── --}}
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 border-b border-slate-100">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wide">Senior Citizen</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wide">Barangay</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wide">Age</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wide">Cluster</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wide cursor-pointer hover:text-slate-800"
                            wire:click="sortColumn('composite_risk')">
                            Composite Risk {{ $sortBy === 'composite_risk' ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' }}
                        </th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wide">IC</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wide">Env</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wide">Func</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wide cursor-pointer hover:text-slate-800"
                            wire:click="sortColumn('overall_risk_level')">
                            Overall Risk
                        </th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wide">Wellbeing</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse ($records as $result)
                    @php
                    $senior = $result->seniorCitizen;
                    $riskBg = match($result->overall_risk_level) {
                        'CRITICAL' => 'bg-red-100 text-red-700 font-bold',
                        'HIGH'     => 'bg-orange-100 text-orange-700 font-semibold',
                        'MODERATE' => 'bg-amber-100 text-amber-700',
                        'LOW'      => 'bg-emerald-100 text-emerald-700',
                        default    => 'bg-slate-100 text-slate-600',
                    };
                    $clusterBg = match($result->cluster_named_id) {
                        1 => 'bg-emerald-100 text-emerald-700',
                        2 => 'bg-amber-100 text-amber-700',
                        3 => 'bg-rose-100 text-rose-700',
                        default => 'bg-slate-100 text-slate-600',
                    };
                    @endphp
                    <tr class="hover:bg-slate-25 transition-colors {{ $result->overall_risk_level === 'CRITICAL' ? 'bg-red-25' : '' }}">
                        <td class="px-4 py-3 font-medium text-slate-800">
                            {{ $senior?->full_name ?? '—' }}
                            <span class="text-xs text-slate-400 font-normal ml-1">{{ $senior?->osca_id }}</span>
                        </td>
                        <td class="px-4 py-3 text-slate-500">{{ $senior?->barangay }}</td>
                        <td class="px-4 py-3 text-center text-slate-600">{{ $senior?->age }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $clusterBg }}">
                                C{{ $result->cluster_named_id }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <div class="flex items-center justify-center gap-1.5">
                                <div class="w-14 bg-slate-100 rounded-full h-1.5">
                                    <div class="h-1.5 rounded-full {{ $result->composite_risk > 0.65 ? 'bg-red-500' : ($result->composite_risk > 0.45 ? 'bg-amber-500' : 'bg-emerald-500') }}"
                                         style="width: {{ round($result->composite_risk * 100) }}%"></div>
                                </div>
                                <span class="text-xs font-mono text-slate-700">{{ number_format($result->composite_risk, 3) }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center text-xs font-mono {{ $result->ic_risk_level === 'critical' ? 'text-red-600 font-bold' : 'text-slate-500' }}">
                            {{ number_format($result->ic_risk, 2) }}
                        </td>
                        <td class="px-4 py-3 text-center text-xs font-mono {{ $result->env_risk_level === 'critical' ? 'text-red-600 font-bold' : 'text-slate-500' }}">
                            {{ number_format($result->env_risk, 2) }}
                        </td>
                        <td class="px-4 py-3 text-center text-xs font-mono {{ $result->func_risk_level === 'critical' ? 'text-red-600 font-bold' : 'text-slate-500' }}">
                            {{ number_format($result->func_risk, 2) }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-0.5 rounded-full text-xs {{ $riskBg }}">
                                {{ $result->overall_risk_level }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center text-xs font-mono text-slate-500">
                            {{ number_format($result->wellbeing_score, 2) }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('seniors.show', $result->senior_citizen_id) }}"
                               class="text-xs text-teal-600 hover:text-teal-700 font-medium">View →</a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="11" class="px-4 py-10 text-center text-slate-400">
                            No risk data found. Adjust filters or run ML analysis.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($records->hasPages())
        <div class="px-4 py-3 border-t border-slate-100">
            {{ $records->links() }}
        </div>
        @endif
    </div>

</div>
