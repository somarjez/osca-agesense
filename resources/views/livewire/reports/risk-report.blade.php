{{-- resources/views/livewire/reports/risk-report.blade.php --}}
<div class="space-y-5">

    {{-- ── Summary Cards ── --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        @php
        $levels = [
            'HIGH'     => ['color' => 'orange', 'icon' => '<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-orange-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>'],
            'MODERATE' => ['color' => 'amber',  'icon' => '<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>'],
            'LOW'      => ['color' => 'green',  'icon' => '<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'],
        ];
        $colorBg = ['orange' => 'bg-orange-50 border-orange-200', 'amber' => 'bg-amber-50 border-amber-200', 'green' => 'bg-emerald-50 border-emerald-200'];
        $colorText = ['orange' => 'text-orange-700', 'amber' => 'text-amber-700', 'green' => 'text-emerald-700'];
        @endphp
        @foreach ($levels as $level => $meta)
        @php $stat = $summaryStats[$level] ?? null; @endphp
        <div class="bg-white border rounded-xl p-4 shadow-sm {{ $colorBg[$meta['color']] }} cursor-pointer hover:shadow-md transition-shadow"
             wire:click="$set('filterRisk', '{{ strtolower($level) }}')">
            <div class="flex items-center justify-between mb-2">
                <span>{!! $meta['icon'] !!}</span>
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
            @foreach (['HIGH','MODERATE','LOW'] as $lvl)
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
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 inline-block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            Clear filters
        </button>
        @endif

        <div class="ml-auto">
            <a href="{{ route('reports.risk.export') }}"
               class="px-4 py-2 text-sm font-medium border border-slate-200 bg-white text-slate-600 rounded-lg hover:bg-slate-50 transition-colors shadow-sm">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 inline-block mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                Export CSV
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
                    <tr class="hover:bg-slate-25 transition-colors {{ $result->priority_flag === 'urgent' ? 'bg-orange-25' : '' }}">
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
                                    <div class="h-1.5 rounded-full {{ $result->composite_risk >= 0.50 ? 'bg-high-500' : ($result->composite_risk >= 0.30 ? 'bg-moderate-500' : 'bg-low-500') }}"
                                         style="width: {{ round($result->composite_risk * 100) }}%"></div>
                                </div>
                                <span class="text-xs font-mono text-slate-700">{{ number_format($result->composite_risk, 3) }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center text-xs font-mono {{ $result->ic_risk_level === 'high' ? 'text-orange-600 font-semibold' : 'text-slate-500' }}">
                            {{ number_format($result->ic_risk, 2) }}
                        </td>
                        <td class="px-4 py-3 text-center text-xs font-mono {{ $result->env_risk_level === 'high' ? 'text-orange-600 font-semibold' : 'text-slate-500' }}">
                            {{ number_format($result->env_risk, 2) }}
                        </td>
                        <td class="px-4 py-3 text-center text-xs font-mono {{ $result->func_risk_level === 'high' ? 'text-orange-600 font-semibold' : 'text-slate-500' }}">
                            {{ number_format($result->func_risk, 2) }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-0.5 rounded-full text-xs {{ $riskBg }}">
                                {{ $result->overall_risk_level }}
                            </span>
                            @if($result->priority_flag === 'urgent')
                            <div class="text-[10px] text-orange-600 font-semibold mt-0.5">Urgent priority</div>
                            @endif
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
