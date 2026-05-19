{{-- resources/views/reports/risk.blade.php --}}
@extends('layouts.app')
@section('page-title', 'Risk Reports')

@section('content')
<div class="space-y-5">

    {{-- ── Filter Bar ── --}}
    <div class="card">
        <div class="card-body flex flex-wrap items-center gap-3 py-3">
            <x-heroicon-o-funnel class="w-4 h-4 text-ink-400 flex-shrink-0" />
            <form method="GET" class="flex gap-2 flex-wrap flex-1">
                <select name="barangay" class="form-select max-w-[200px]">
                    <option value="">All Barangays</option>
                    @foreach ($barangays as $b)
                    <option value="{{ $b }}" {{ request('barangay') === $b ? 'selected' : '' }}>{{ $b }}</option>
                    @endforeach
                </select>
                <select name="risk_level" class="form-select max-w-[160px]">
                    <option value="">All HIGH risk</option>
                    <option value="high" {{ request('risk_level') === 'high' ? 'selected' : '' }}>HIGH only</option>
                </select>
                <button type="submit" class="btn btn-primary">Filter</button>
            </form>
            <a href="{{ route('reports.risk.export') }}" class="btn ml-auto">
                <x-heroicon-o-arrow-down-tray class="w-3.5 h-3.5" /> Export CSV
            </a>
        </div>
    </div>

    {{-- ── Risk Overview Cards ── --}}
    <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach ([
            ['HIGH',     $riskDist['HIGH']     ?? 0, 'high'],
            ['MODERATE', $riskDist['MODERATE'] ?? 0, 'moderate'],
            ['LOW',      $riskDist['LOW']      ?? 0, 'low'],
        ] as [$level, $count, $accent])
        <div class="kpi">
            <div class="kpi-rule bg-{{ $accent }}-500"></div>
            <div class="kpi-label">{{ $level }} Risk</div>
            <div class="kpi-value text-{{ $accent }}-700">{{ number_format($count) }}</div>
            <div class="kpi-delta">senior citizens</div>
        </div>
        @endforeach
    </div>

    {{-- ── Domain Averages + Rec Categories ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        {{-- Domain Risk Avg Bars --}}
        <div class="card">
            <div class="card-head">
                <div class="card-title">Average Domain Risk Scores</div>
            </div>
            <div class="card-body space-y-3">
                @foreach ([
                    ['Intrinsic Capacity (IC)',  $domainAvgs?->ic        ?? 0],
                    ['Environment',             $domainAvgs?->env       ?? 0],
                    ['Functional',              $domainAvgs?->func      ?? 0],
                    ['Composite',               $domainAvgs?->composite ?? 0],
                ] as [$label, $val])
                @php $barClass = $val >= 0.50 ? 'bar-fill-high' : ($val >= 0.30 ? 'bar-fill-moderate' : 'bar-fill-low'); @endphp
                <div>
                    <div class="flex justify-between text-[12.5px] mb-1">
                        <span class="text-ink-500 dark:text-[#8a9087]">{{ $label }}</span>
                        <span class="font-semibold font-mono tnum text-ink-900 dark:text-[#e4e1d8]">{{ number_format($val * 100, 1) }}%</span>
                    </div>
                    <div class="bar">
                        <div class="bar-fill {{ $barClass }}" style="width: {{ $val * 100 }}%"></div>
                    </div>
                </div>
                @endforeach

                <div class="pt-3 border-t border-paper-rule dark:border-[#2b3530] grid grid-cols-3 gap-1 text-center text-[11px]">
                    <div class="badge badge-high py-1">High ≥50%</div>
                    <div class="badge badge-moderate py-1">Moderate 30–50%</div>
                    <div class="badge badge-low py-1">Low &lt;30%</div>
                </div>
                <p class="text-[10.5px] text-ink-400 dark:text-[#6b7570]">Scores ≥ 70% are High-risk with urgent-priority flag.</p>
            </div>
        </div>

        {{-- Recommendations by Category --}}
        <div class="card">
            <div class="card-head">
                <div class="card-title">Pending Recommendations by Category</div>
            </div>
            <div class="card-body space-y-2.5">
                @foreach ($recsByCategory as $rec)
                <div class="flex items-center gap-3">
                    <div class="flex-1">
                        <div class="flex justify-between text-[12.5px] mb-1">
                            <span class="text-ink-600 dark:text-[#b0b5b2] capitalize">{{ str_replace('_', ' ', $rec->category) }}</span>
                            <span class="font-semibold text-ink-900 dark:text-[#e4e1d8] tnum">{{ $rec->count }}</span>
                        </div>
                        <div class="bar">
                            <div class="bar-fill bar-fill-forest"
                                 style="width: {{ $recsByCategory->max('count') > 0 ? ($rec->count / $recsByCategory->max('count') * 100) : 0 }}%"></div>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ── Barangay × Risk Level Distribution ── --}}
    <div class="card overflow-hidden">
        <div class="card-head">
            <div class="card-title">Barangay × Risk Level Distribution</div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="th">Barangay</th>
                        <th class="th text-center text-high-700">HIGH</th>
                        <th class="th text-center text-moderate-700">MODERATE</th>
                        <th class="th text-center text-low-700">LOW</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($barangayRisk as $brgy => $rows)
                    @php $byRisk = $rows->keyBy('overall_risk_level'); @endphp
                    <tr class="hover:bg-forest-50/40 dark:hover:bg-forest-900/10 transition-colors">
                        <td class="td font-medium text-ink-800 dark:text-[#c8c4bc]">
                            <a href="{{ route('reports.barangay', $brgy) }}"
                               class="hover:text-forest-700 dark:hover:text-forest-400 transition-colors">
                                {{ $brgy }}
                            </a>
                        </td>
                        @foreach (['HIGH','MODERATE','LOW'] as $level)
                        @php $cnt = $byRisk[$level]?->count ?? 0; @endphp
                        <td class="td text-center font-mono tnum">
                            @if ($cnt > 0)
                            <span class="font-semibold {{ match($level) {
                                'HIGH'     => 'text-high-700 dark:text-[#e08070]',
                                'MODERATE' => 'text-moderate-700 dark:text-[#d4a830]',
                                'LOW'      => 'text-low-700 dark:text-[#4a8a68]',
                                default    => 'text-ink-400',
                            } }}">{{ $cnt }}</span>
                            @else
                            <span class="text-ink-300 dark:text-[#4a5550]">—</span>
                            @endif
                        </td>
                        @endforeach
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── At-Risk Seniors Table ── --}}
    <div class="card overflow-hidden">
        <div class="card-head bg-high-50/40 dark:bg-high-500/5 border-b border-high-100 dark:border-high-500/10">
            <div class="flex items-center gap-2">
                <x-heroicon-o-exclamation-triangle class="w-4 h-4 text-high-700 flex-shrink-0" />
                <div class="card-title text-high-700 dark:text-[#e08070]">At-Risk Seniors (HIGH)</div>
            </div>
            <span class="text-[11.5px] text-high-700 dark:text-[#e08070] tnum font-semibold">{{ $atRiskSeniors->total() }} total</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="th">Senior Citizen</th>
                        <th class="th">Barangay</th>
                        <th class="th text-center">Age</th>
                        <th class="th text-center">Risk</th>
                        <th class="th text-center">Composite</th>
                        <th class="th text-center">IC</th>
                        <th class="th text-center">Env</th>
                        <th class="th text-center">Func</th>
                        <th class="th">Cluster</th>
                        <th class="th"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($atRiskSeniors as $senior)
                    <tr class="hover:bg-forest-50/40 dark:hover:bg-forest-900/10 transition-colors">
                        <td class="td">
                            <div class="font-semibold text-ink-900 dark:text-[#e4e1d8]">{{ $senior->first_name }} {{ $senior->last_name }}</div>
                            <div class="text-[11px] text-ink-400 dark:text-[#6b7570] font-mono">{{ $senior->osca_id }}</div>
                        </td>
                        <td class="td text-ink-500 dark:text-[#8a9087]">{{ $senior->barangay }}</td>
                        <td class="td text-center font-mono tnum text-ink-700 dark:text-[#b0b5b2]">{{ $senior->age }}</td>
                        <td class="td text-center">
                            <span class="badge badge-high">{{ $senior->overall_risk_level }}</span>
                        </td>
                        <td class="td text-center font-mono tnum font-semibold text-ink-800 dark:text-[#c8c4bc]">{{ number_format($senior->composite_risk * 100, 1) }}%</td>
                        <td class="td text-center font-mono tnum text-[11.5px] text-ink-500 dark:text-[#8a9087]">{{ number_format($senior->ic_risk * 100, 1) }}%</td>
                        <td class="td text-center font-mono tnum text-[11.5px] text-ink-500 dark:text-[#8a9087]">{{ number_format($senior->env_risk * 100, 1) }}%</td>
                        <td class="td text-center font-mono tnum text-[11.5px] text-ink-500 dark:text-[#8a9087]">{{ number_format($senior->func_risk * 100, 1) }}%</td>
                        <td class="td text-[12px] text-ink-500 dark:text-[#8a9087]">{{ $senior->cluster_name }}</td>
                        <td class="td text-right">
                            <a href="{{ route('seniors.show', $senior->id) }}"
                               class="text-[12px] text-forest-700 dark:text-forest-400 hover:text-forest-900 dark:hover:text-forest-300 font-semibold">View →</a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="10" class="td text-center py-12 text-ink-400 dark:text-[#6b7570]">
                            No high risk seniors found with current filters.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($atRiskSeniors->hasPages())
        <div class="border-t border-paper-rule dark:border-[#2b3530] px-5 py-3">
            {{ $atRiskSeniors->links() }}
        </div>
        @endif
    </div>

    {{-- ── Interactive Risk Explorer ── --}}
    <div>
        <div class="eyebrow px-1 mb-3">Interactive Risk Explorer</div>
        <livewire:reports.risk-report />
    </div>

</div>
@endsection
