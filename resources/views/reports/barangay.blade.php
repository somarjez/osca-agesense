{{-- resources/views/reports/barangay.blade.php --}}
@extends('layouts.app')
@section('page-title', $brgy)

@section('content')
<div class="space-y-5">

    {{-- ── Barangay selector ── --}}
    <div class="card">
        <div class="card-body flex flex-wrap items-center gap-3 py-3">
            <x-heroicon-o-map-pin class="w-4 h-4 text-ink-400 flex-shrink-0" />
            <select class="form-select max-w-[220px]"
                    onchange="window.location.href='{{ url('/reports/barangay/') }}/' + this.value">
                @foreach ($barangays as $b)
                <option value="{{ $b }}" {{ $b === $brgy ? 'selected' : '' }}>{{ $b }}</option>
                @endforeach
            </select>
            <a href="{{ route('reports.risk') }}"
               class="btn btn-ghost text-[12.5px] gap-1.5 ml-auto">
                <x-heroicon-o-arrow-left class="w-3.5 h-3.5" /> All Barangays
            </a>
        </div>
    </div>

    {{-- ── Summary KPI cards ── --}}
    @php
        $total    = $seniors->count();
        $surveyed = $seniors->filter(fn($s) => $s->latestMlResult !== null)->count();
        $high     = $riskDist['HIGH']     ?? 0;
        $moderate = $riskDist['MODERATE'] ?? 0;
        $low      = $riskDist['LOW']      ?? 0;
    @endphp
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="kpi col-span-1">
            <div class="kpi-rule bg-forest-500"></div>
            <div class="kpi-label">Total Seniors</div>
            <div class="kpi-value">{{ $total }}</div>
        </div>
        <div class="kpi col-span-1">
            <div class="kpi-rule bg-info-500"></div>
            <div class="kpi-label">Analysed</div>
            <div class="kpi-value">{{ $surveyed }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-rule bg-high-500"></div>
            <div class="kpi-label">HIGH Risk</div>
            <div class="kpi-value text-high-700">{{ $high }}</div>
            @if ($urgentCount > 0)
            <div class="kpi-delta text-high-700">{{ $urgentCount }} urgent</div>
            @endif
        </div>
        <div class="kpi">
            <div class="kpi-rule bg-moderate-500"></div>
            <div class="kpi-label">MODERATE Risk</div>
            <div class="kpi-value text-moderate-700">{{ $moderate }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-rule bg-low-500"></div>
            <div class="kpi-label">LOW Risk</div>
            <div class="kpi-value text-low-700">{{ $low }}</div>
        </div>
    </div>

    {{-- ── Domain averages + cluster breakdown ── --}}
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
                    ['Functional Ability',      $domainAvgs?->func      ?? 0],
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
            </div>
        </div>

        {{-- Cluster distribution --}}
        <div class="card">
            <div class="card-head">
                <div class="card-title">Health Group Distribution</div>
            </div>
            <div class="card-body">
                @if ($clusterDist->isEmpty())
                    <p class="text-[13px] text-ink-400 dark:text-[#6b7570]">No cluster data available for this barangay.</p>
                @else
                @php
                    $clusterTotal = $clusterDist->sum('count');
                    $clusterAccent = [1 => 'low', 2 => 'moderate', 3 => 'high'];
                @endphp
                <div class="space-y-3">
                @foreach ($clusterDist as $c)
                @php
                    $pct    = $clusterTotal > 0 ? $c->count / $clusterTotal * 100 : 0;
                    $accent = $clusterAccent[$c->cluster_named_id] ?? 'forest';
                @endphp
                <div>
                    <div class="flex justify-between items-center mb-1">
                        <span class="text-[12.5px] text-ink-700 dark:text-[#b0b5b2] flex items-center gap-2">
                            <x-cluster-badge :id="$c->cluster_named_id" />
                            {{ $c->cluster_name }}
                        </span>
                        <span class="text-[12.5px] font-semibold text-ink-900 dark:text-[#e4e1d8] tnum">
                            {{ $c->count }}
                            <span class="text-ink-400 dark:text-[#6b7570] font-normal">({{ number_format($pct, 1) }}%)</span>
                        </span>
                    </div>
                    <div class="bar">
                        <div class="bar-fill bar-fill-{{ $accent }}" style="width: {{ $pct }}%"></div>
                    </div>
                </div>
                @endforeach
                </div>
                @endif

                {{-- Pending recs by category --}}
                @if ($pendingRecs->isNotEmpty())
                <div class="mt-5 pt-4 border-t border-paper-rule dark:border-[#2b3530]">
                    <div class="eyebrow mb-3">Pending Recommendations</div>
                    <div class="space-y-1.5">
                    @foreach ($pendingRecs as $rec)
                    <div class="flex items-center justify-between text-[12.5px]">
                        <span class="text-ink-600 dark:text-[#b0b5b2] capitalize">{{ str_replace('_', ' ', $rec->category) }}</span>
                        <span class="font-semibold text-ink-900 dark:text-[#e4e1d8] tnum">{{ $rec->count }}</span>
                    </div>
                    @endforeach
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Senior roster ── --}}
    <div class="card overflow-hidden">
        <div class="card-head">
            <div class="flex items-center gap-2">
                <x-heroicon-o-users class="w-4 h-4 text-ink-400 flex-shrink-0" />
                <div class="card-title">Senior Citizen Roster — {{ $brgy }}</div>
            </div>
            <span class="text-[11.5px] text-ink-400 dark:text-[#6b7570] tnum">{{ $total }} seniors</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="th">Senior Citizen</th>
                        <th class="th text-center">Age</th>
                        <th class="th text-center">Gender</th>
                        <th class="th text-center">Risk Level</th>
                        <th class="th text-center">Composite</th>
                        <th class="th">Health Group</th>
                        <th class="th"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($seniors as $senior)
                    @php $ml = $senior->latestMlResult; @endphp
                    <tr class="hover:bg-forest-50/40 dark:hover:bg-forest-900/10 transition-colors">
                        <td class="td">
                            <div class="font-semibold text-ink-900 dark:text-[#e4e1d8]">{{ $senior->full_name }}</div>
                            <div class="text-[11px] text-ink-400 dark:text-[#6b7570] font-mono">{{ $senior->osca_id }}</div>
                        </td>
                        <td class="td text-center font-mono tnum text-ink-700 dark:text-[#b0b5b2]">{{ $senior->age }}</td>
                        <td class="td text-center text-ink-500 dark:text-[#8a9087]">{{ $senior->gender ?? '—' }}</td>
                        <td class="td text-center">
                            @if ($ml)
                                <x-risk-badge :level="$ml->overall_risk_level" :priority="$ml->priority_flag" />
                            @else
                                <span class="text-[11.5px] text-ink-300 dark:text-[#4a5550]">No data</span>
                            @endif
                        </td>
                        <td class="td text-center font-mono tnum font-semibold text-ink-800 dark:text-[#c8c4bc]">
                            {{ $ml ? number_format($ml->composite_risk * 100, 1) . '%' : '—' }}
                        </td>
                        <td class="td text-[12px] text-ink-500 dark:text-[#8a9087]">{{ $ml?->cluster_name ?? '—' }}</td>
                        <td class="td text-right">
                            <a href="{{ route('seniors.show', $senior->id) }}"
                               class="text-[12px] text-forest-700 dark:text-forest-400 hover:text-forest-900 dark:hover:text-forest-300 font-semibold">View →</a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="td text-center py-12 text-ink-400 dark:text-[#6b7570]">
                            No active seniors registered in {{ $brgy }}.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-doc-footer signatory="OSCA Officer / Reviewer" />
</div>
@endsection
