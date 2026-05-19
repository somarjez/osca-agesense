{{-- resources/views/livewire/reports/risk-report.blade.php --}}
<div class="space-y-5">

    {{-- ── Summary Cards ── --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
        @php
        $levelMeta = [
            'HIGH'     => ['accent' => 'high',     'label' => 'High Risk'],
            'MODERATE' => ['accent' => 'moderate',  'label' => 'Moderate Risk'],
            'LOW'      => ['accent' => 'low',       'label' => 'Low Risk'],
        ];
        @endphp
        @foreach ($levelMeta as $level => $meta)
        @php $stat = $summaryStats[$level] ?? null; @endphp
        <div class="kpi cursor-pointer transition-shadow hover:shadow-md"
             wire:click="$set('filterRisk', '{{ strtolower($level) }}')">
            <div class="kpi-rule bg-{{ $meta['accent'] }}-500"></div>
            <div class="kpi-label">{{ $meta['label'] }}</div>
            <div class="kpi-value text-{{ $meta['accent'] }}-700">{{ $stat?->count ?? 0 }}</div>
            <div class="kpi-delta">Avg risk: {{ $stat ? number_format($stat->avg_risk, 3) : '—' }}</div>
        </div>
        @endforeach
    </div>

    {{-- ── Filters ── --}}
    <div class="card">
        <div class="card-body flex flex-wrap items-center gap-3 py-3">
            <x-heroicon-o-funnel class="w-4 h-4 text-ink-400 flex-shrink-0" />

            <select wire:model.live="filterRisk" class="form-select max-w-[160px]">
                <option value="">All Risk Levels</option>
                @foreach (['HIGH','MODERATE','LOW'] as $lvl)
                <option value="{{ strtolower($lvl) }}">{{ $lvl }}</option>
                @endforeach
            </select>

            <select wire:model.live="filterBarangay" class="form-select max-w-[180px]">
                <option value="">All Barangays</option>
                @foreach (\App\Models\SeniorCitizen::barangayList() as $brgy)
                <option value="{{ $brgy }}">{{ $brgy }}</option>
                @endforeach
            </select>

            <select wire:model.live="filterCluster" class="form-select max-w-[200px]">
                <option value="">All Clusters</option>
                <option value="1">Cluster 1 — High Functioning</option>
                <option value="2">Cluster 2 — Moderate / Mixed</option>
                <option value="3">Cluster 3 — Low Functioning</option>
            </select>

            @if ($filterRisk || $filterBarangay || $filterCluster)
            <button wire:click="$set('filterRisk',''); $set('filterBarangay',''); $set('filterCluster','')"
                    class="btn btn-ghost text-[12.5px] gap-1.5">
                <x-heroicon-o-x-mark class="w-3.5 h-3.5" /> Clear
            </button>
            @endif

            <div class="ml-auto">
                <a href="{{ route('reports.risk.export') }}" class="btn">
                    <x-heroicon-o-arrow-down-tray class="w-3.5 h-3.5" /> Export CSV
                </a>
            </div>
        </div>
    </div>

    {{-- ── Data Table ── --}}
    <div class="card overflow-visible">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="th">Senior Citizen</th>
                        <th class="th">Barangay</th>
                        <th class="th text-center">Age</th>
                        <th class="th text-center">Cluster</th>
                        <th class="th text-center cursor-pointer select-none"
                            wire:click="sortColumn('composite_risk')">
                            Composite Risk {{ $sortBy === 'composite_risk' ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' }}
                        </th>
                        <th class="th text-center">IC</th>
                        <th class="th text-center">Env</th>
                        <th class="th text-center">Func</th>
                        <th class="th text-center cursor-pointer select-none"
                            wire:click="sortColumn('overall_risk_level')">
                            Overall Risk
                        </th>
                        <th class="th text-center">Wellbeing</th>
                        <th class="th"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($records as $result)
                    @php $senior = $result->seniorCitizen; @endphp
                    <tr class="hover:bg-forest-50/40 dark:hover:bg-forest-900/10 transition-colors
                               {{ $result->priority_flag === 'urgent' ? 'bg-high-50/30 dark:bg-high-500/5' : '' }}">
                        <td class="td">
                            <div class="font-semibold text-ink-900 dark:text-[#e4e1d8]">{{ $senior?->full_name ?? '—' }}</div>
                            <div class="text-[11px] text-ink-400 dark:text-[#6b7570] font-mono">{{ $senior?->osca_id }}</div>
                        </td>
                        <td class="td text-ink-500 dark:text-[#8a9087]">{{ $senior?->barangay }}</td>
                        <td class="td text-center font-mono tnum text-ink-700 dark:text-[#b0b5b2]">{{ $senior?->age }}</td>
                        <td class="td text-center">
                            <x-cluster-badge :id="$result->cluster_named_id" />
                        </td>
                        <td class="td">
                            <x-risk-bar :value="$result->composite_risk" />
                        </td>
                        <td class="td text-center font-mono text-[11.5px] tnum
                            {{ $result->ic_risk_level === 'high' ? 'text-high-700 font-semibold' : 'text-ink-500 dark:text-[#6b7570]' }}">
                            {{ number_format($result->ic_risk, 2) }}
                        </td>
                        <td class="td text-center font-mono text-[11.5px] tnum
                            {{ $result->env_risk_level === 'high' ? 'text-high-700 font-semibold' : 'text-ink-500 dark:text-[#6b7570]' }}">
                            {{ number_format($result->env_risk, 2) }}
                        </td>
                        <td class="td text-center font-mono text-[11.5px] tnum
                            {{ $result->func_risk_level === 'high' ? 'text-high-700 font-semibold' : 'text-ink-500 dark:text-[#6b7570]' }}">
                            {{ number_format($result->func_risk, 2) }}
                        </td>
                        <td class="td text-center">
                            <x-risk-badge :level="$result->overall_risk_level" :priority="$result->priority_flag" />
                        </td>
                        <td class="td text-center font-mono text-[11.5px] tnum text-ink-500 dark:text-[#6b7570]">
                            {{ number_format($result->wellbeing_score, 2) }}
                        </td>
                        <td class="td text-right">
                            <a href="{{ route('seniors.show', $result->senior_citizen_id) }}"
                               class="text-[12px] text-forest-700 dark:text-forest-400 hover:text-forest-900 dark:hover:text-forest-300 font-semibold">
                                View →
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="11" class="td text-center py-12 text-ink-400 dark:text-[#6b7570]">
                            No risk data found. Adjust filters or run ML analysis.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($records->hasPages())
        <div class="border-t border-paper-rule dark:border-[#2b3530] px-5 py-3">
            {{ $records->links() }}
        </div>
        @endif
    </div>

</div>
