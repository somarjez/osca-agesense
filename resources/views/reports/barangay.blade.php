{{-- resources/views/reports/barangay.blade.php --}}
@extends('layouts.app')
@section('page-title', $brgy)
@section('page-subtitle', 'Barangay drill-down — senior citizen risk and cluster breakdown')

@section('content')
<div class="space-y-5">

    {{-- ── Barangay selector ── --}}
    <div class="flex items-center gap-3 flex-wrap">
        <form method="GET" class="flex gap-2 flex-wrap">
            <select name="brgy" id="brgy-selector"
                    class="text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none shadow-sm"
                    onchange="window.location.href='{{ url('/reports/barangay/') }}/' + this.value">
                @foreach ($barangays as $b)
                <option value="{{ $b }}" {{ $b === $brgy ? 'selected' : '' }}>{{ $b }}</option>
                @endforeach
            </select>
        </form>
        <a href="{{ route('reports.risk') }}"
           class="ml-auto flex items-center gap-1.5 text-xs text-slate-500 hover:text-slate-700 transition-colors">
            ← All Barangays
        </a>
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
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm col-span-1">
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Seniors</p>
            <p class="text-3xl font-bold mt-1 text-slate-800">{{ $total }}</p>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm col-span-1">
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Analysed</p>
            <p class="text-3xl font-bold mt-1 text-slate-800">{{ $surveyed }}</p>
        </div>
        <div class="bg-orange-50 border border-orange-200 rounded-xl p-4">
            <p class="text-xs font-bold uppercase tracking-wider text-orange-700">HIGH</p>
            <p class="text-3xl font-bold mt-1 text-orange-700">{{ $high }}</p>
            @if ($urgentCount > 0)
            <p class="text-xs mt-0.5 text-orange-500">{{ $urgentCount }} urgent</p>
            @endif
        </div>
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
            <p class="text-xs font-bold uppercase tracking-wider text-amber-700">MODERATE</p>
            <p class="text-3xl font-bold mt-1 text-amber-700">{{ $moderate }}</p>
        </div>
        <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4">
            <p class="text-xs font-bold uppercase tracking-wider text-emerald-700">LOW</p>
            <p class="text-3xl font-bold mt-1 text-emerald-700">{{ $low }}</p>
        </div>
    </div>

    {{-- ── Domain averages + cluster breakdown ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        {{-- Domain Risk Avg Bars --}}
        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-700 mb-4">Average Domain Risk Scores</h3>
            @foreach ([
                ['Intrinsic Capacity (IC)',  $domainAvgs?->ic        ?? 0],
                ['Environment',             $domainAvgs?->env       ?? 0],
                ['Functional Ability',      $domainAvgs?->func      ?? 0],
                ['Composite',               $domainAvgs?->composite ?? 0],
            ] as [$label, $val])
            @php $barColor = $val >= 0.50 ? 'bg-high-500' : ($val >= 0.30 ? 'bg-moderate-500' : 'bg-low-500'); @endphp
            <div class="mb-3">
                <div class="flex justify-between text-sm mb-1">
                    <span class="text-slate-600">{{ $label }}</span>
                    <span class="font-semibold text-slate-800">{{ number_format($val * 100, 1) }}%</span>
                </div>
                <div class="w-full bg-slate-100 rounded-full h-2.5">
                    <div class="{{ $barColor }} h-2.5 rounded-full transition-all" style="width: {{ $val * 100 }}%"></div>
                </div>
            </div>
            @endforeach
            <div class="mt-4 pt-3 border-t border-slate-100 grid grid-cols-3 gap-1 text-center text-xs">
                <div class="bg-orange-50 text-orange-700 px-1 py-1 rounded">High ≥50%</div>
                <div class="bg-amber-50 text-amber-700 px-1 py-1 rounded">Moderate 30–50%</div>
                <div class="bg-emerald-50 text-emerald-700 px-1 py-1 rounded">Low &lt;30%</div>
            </div>
        </div>

        {{-- Cluster distribution --}}
        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-700 mb-4">Health Group Distribution</h3>
            @if ($clusterDist->isEmpty())
                <p class="text-sm text-slate-400">No cluster data available for this barangay.</p>
            @else
            @php $clusterTotal = $clusterDist->sum('count'); @endphp
            @foreach ($clusterDist as $c)
            @php
                $pct = $clusterTotal > 0 ? $c->count / $clusterTotal * 100 : 0;
                $color = match ($c->cluster_named_id) {
                    1 => ['bar' => 'bg-emerald-400', 'badge' => 'bg-emerald-100 text-emerald-800'],
                    2 => ['bar' => 'bg-amber-400',   'badge' => 'bg-amber-100 text-amber-800'],
                    3 => ['bar' => 'bg-orange-400',  'badge' => 'bg-orange-100 text-orange-800'],
                    default => ['bar' => 'bg-slate-300', 'badge' => 'bg-slate-100 text-slate-700'],
                };
            @endphp
            <div class="mb-3">
                <div class="flex justify-between items-center mb-1">
                    <span class="text-sm text-slate-600">
                        <span class="inline-block px-1.5 py-0.5 rounded text-xs font-bold {{ $color['badge'] }} mr-1">{{ $c->cluster_named_id }}</span>
                        {{ $c->cluster_name }}
                    </span>
                    <span class="text-sm font-semibold text-slate-800">{{ $c->count }} <span class="text-slate-400 font-normal">({{ number_format($pct, 1) }}%)</span></span>
                </div>
                <div class="w-full bg-slate-100 rounded-full h-2.5">
                    <div class="{{ $color['bar'] }} h-2.5 rounded-full" style="width: {{ $pct }}%"></div>
                </div>
            </div>
            @endforeach
            @endif

            {{-- Pending recs by category --}}
            @if ($pendingRecs->isNotEmpty())
            <div class="mt-5 pt-4 border-t border-slate-100">
                <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-3">Pending Recommendations</h4>
                @foreach ($pendingRecs as $rec)
                <div class="flex items-center justify-between text-sm mb-1.5">
                    <span class="text-slate-600 capitalize">{{ str_replace('_', ' ', $rec->category) }}</span>
                    <span class="font-semibold text-slate-700 tabular-nums">{{ $rec->count }}</span>
                </div>
                @endforeach
            </div>
            @endif
        </div>
    </div>

    {{-- ── Senior roster ── --}}
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="bg-slate-50 border-b border-slate-100 px-5 py-3 flex items-center gap-2">
            <x-heroicon-o-users class="w-4 h-4 text-slate-400 flex-shrink-0" />
            <h3 class="text-sm font-semibold text-slate-700">Senior Citizen Roster — {{ $brgy }}</h3>
            <span class="ml-auto text-xs text-slate-400">{{ $total }} seniors</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr class="text-xs text-slate-400">
                        <th class="px-4 py-2.5 text-left font-medium">Senior Citizen</th>
                        <th class="px-4 py-2.5 text-left font-medium">Age</th>
                        <th class="px-4 py-2.5 text-left font-medium">Gender</th>
                        <th class="px-4 py-2.5 text-left font-medium">Risk Level</th>
                        <th class="px-4 py-2.5 text-left font-medium">Composite</th>
                        <th class="px-4 py-2.5 text-left font-medium">Health Group</th>
                        <th class="px-4 py-2.5 text-right font-medium">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($seniors as $senior)
                    @php $ml = $senior->latestMlResult; @endphp
                    <tr class="hover:bg-slate-25 transition-colors">
                        <td class="px-4 py-2.5 font-medium text-slate-800">
                            {{ $senior->full_name }}
                            <div class="text-xs text-slate-400">{{ $senior->osca_id }}</div>
                        </td>
                        <td class="px-4 py-2.5 text-slate-600">{{ $senior->age }}</td>
                        <td class="px-4 py-2.5 text-slate-600">{{ $senior->gender ?? '—' }}</td>
                        <td class="px-4 py-2.5">
                            @if ($ml)
                            @php
                                $riskStyle = match ($ml->overall_risk_level) {
                                    'HIGH'     => 'bg-orange-100 text-orange-700',
                                    'MODERATE' => 'bg-amber-100 text-amber-700',
                                    'LOW'      => 'bg-emerald-100 text-emerald-700',
                                    default    => 'bg-slate-100 text-slate-500',
                                };
                            @endphp
                            <span class="text-xs font-bold px-2 py-0.5 rounded-full {{ $riskStyle }}">
                                {{ $ml->overall_risk_level }}
                                @if ($ml->priority_flag === 'urgent')
                                    <span class="ml-0.5 text-red-600">!</span>
                                @endif
                            </span>
                            @else
                            <span class="text-xs text-slate-300">No data</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 font-semibold text-slate-700 tabular-nums">
                            {{ $ml ? number_format($ml->composite_risk * 100, 1) . '%' : '—' }}
                        </td>
                        <td class="px-4 py-2.5 text-slate-600 text-xs">
                            {{ $ml?->cluster_name ?? '—' }}
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <a href="{{ route('seniors.show', $senior->id) }}"
                               class="text-xs font-medium text-teal-600 hover:text-teal-700">View →</a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-slate-400">
                            No active seniors registered in {{ $brgy }}.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
