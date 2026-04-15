{{-- resources/views/reports/risk.blade.php --}}
@extends('layouts.app')
@section('page-title', 'Risk Reports')
@section('page-subtitle', 'Composite risk scoring, domain analysis, and at-risk senior identification')

@section('content')
<div class="space-y-5">

    {{-- ── Export + Filter Bar ── --}}
    <div class="flex items-center gap-3 flex-wrap">
        <form method="GET" class="flex gap-2 flex-wrap">
            <select name="barangay"
                    class="text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none shadow-sm">
                <option value="">All Barangays</option>
                @foreach ($barangays as $b)
                <option value="{{ $b }}" {{ request('barangay') === $b ? 'selected' : '' }}>{{ $b }}</option>
                @endforeach
            </select>
            <select name="risk_level"
                    class="text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none shadow-sm">
                <option value="">CRITICAL + HIGH</option>
                <option value="critical" {{ request('risk_level') === 'critical' ? 'selected' : '' }}>CRITICAL only</option>
                <option value="high"     {{ request('risk_level') === 'high'     ? 'selected' : '' }}>HIGH only</option>
            </select>
            <button type="submit" class="px-4 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700 shadow-sm">Filter</button>
        </form>
        <a href="{{ route('reports.risk.export') }}"
           class="ml-auto flex items-center gap-2 px-4 py-2 text-sm font-medium border border-slate-200 text-slate-600 rounded-lg hover:bg-slate-50 transition-colors">
            ⬇ Export CSV
        </a>
    </div>

    {{-- ── Risk Overview Cards ── --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach ([
            ['CRITICAL', $riskDist['CRITICAL'] ?? 0, 'bg-red-50 border-red-200 text-red-700'],
            ['HIGH',     $riskDist['HIGH']     ?? 0, 'bg-orange-50 border-orange-200 text-orange-700'],
            ['MODERATE', $riskDist['MODERATE'] ?? 0, 'bg-amber-50 border-amber-200 text-amber-700'],
            ['LOW',      $riskDist['LOW']      ?? 0, 'bg-emerald-50 border-emerald-200 text-emerald-700'],
        ] as [$level, $count, $style])
        <div class="{{ $style }} border rounded-xl p-4">
            <p class="text-xs font-bold uppercase tracking-wider">{{ $level }}</p>
            <p class="text-3xl font-bold mt-1">{{ number_format($count) }}</p>
            <p class="text-xs mt-0.5 opacity-70">senior citizens</p>
        </div>
        @endforeach
    </div>

    {{-- ── Domain Averages + Rec Categories ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        {{-- Domain Risk Avg Bars --}}
        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-700 mb-4">Average Domain Risk Scores</h3>
            @foreach ([
                ['Intrinsic Capacity (IC)',  $domainAvgs?->ic        ?? 0, 'bg-red-400'],
                ['Environment',             $domainAvgs?->env       ?? 0, 'bg-orange-400'],
                ['Functional',              $domainAvgs?->func      ?? 0, 'bg-amber-400'],
                ['Composite',               $domainAvgs?->composite ?? 0, 'bg-slate-500'],
            ] as [$label, $val, $barColor])
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

            {{-- Risk threshold legend --}}
            <div class="mt-4 pt-3 border-t border-slate-100 grid grid-cols-4 gap-1 text-center text-xs">
                <div class="bg-red-50 text-red-700 px-1 py-1 rounded">Critical &gt;75%</div>
                <div class="bg-orange-50 text-orange-700 px-1 py-1 rounded">High 65–75%</div>
                <div class="bg-amber-50 text-amber-700 px-1 py-1 rounded">Moderate 45–65%</div>
                <div class="bg-emerald-50 text-emerald-700 px-1 py-1 rounded">Low &lt;45%</div>
            </div>
        </div>

        {{-- Recommendations by Category --}}
        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-700 mb-4">Pending Recommendations by Category</h3>
            @foreach ($recsByCategory as $rec)
            @php
            $catIcons = ['health'=>'🏥','financial'=>'💰','social'=>'👥','functional'=>'🦾','hc_access'=>'🏨','general'=>'📌'];
            @endphp
            <div class="flex items-center gap-3 mb-2.5">
                <span class="text-base">{{ $catIcons[$rec->category] ?? '📌' }}</span>
                <div class="flex-1">
                    <div class="flex justify-between text-sm mb-0.5">
                        <span class="text-slate-600 capitalize">{{ str_replace('_',' ', $rec->category) }}</span>
                        <span class="font-semibold text-slate-800">{{ $rec->count }}</span>
                    </div>
                    <div class="w-full bg-slate-100 rounded-full h-1.5">
                        <div class="bg-teal-500 h-1.5 rounded-full"
                             style="width: {{ $recsByCategory->max('count') > 0 ? ($rec->count / $recsByCategory->max('count') * 100) : 0 }}%"></div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>

    {{-- ── Barangay × Risk Heatmap ── --}}
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="bg-slate-50 border-b border-slate-100 px-5 py-3">
            <h3 class="text-sm font-semibold text-slate-700">Barangay × Risk Level Distribution</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-slate-100">
                    <tr class="text-xs text-slate-400">
                        <th class="px-5 py-2.5 text-left font-medium">Barangay</th>
                        <th class="px-5 py-2.5 text-center font-medium text-red-600">CRITICAL</th>
                        <th class="px-5 py-2.5 text-center font-medium text-orange-600">HIGH</th>
                        <th class="px-5 py-2.5 text-center font-medium text-amber-600">MODERATE</th>
                        <th class="px-5 py-2.5 text-center font-medium text-emerald-600">LOW</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @foreach ($barangayRisk as $brgy => $rows)
                    @php $byRisk = $rows->keyBy('overall_risk_level'); @endphp
                    <tr class="hover:bg-slate-25">
                        <td class="px-5 py-2.5 font-medium text-slate-700">{{ $brgy }}</td>
                        @foreach (['CRITICAL','HIGH','MODERATE','LOW'] as $level)
                        <td class="px-5 py-2.5 text-center">
                            @php $cnt = $byRisk[$level]?->count ?? 0; @endphp
                            @if ($cnt > 0)
                            <span class="font-semibold {{ match($level) {
                                'CRITICAL' => 'text-red-700',
                                'HIGH'     => 'text-orange-600',
                                'MODERATE' => 'text-amber-600',
                                'LOW'      => 'text-emerald-600',
                            } }}">{{ $cnt }}</span>
                            @else
                            <span class="text-slate-300">—</span>
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
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="bg-red-50 border-b border-red-100 px-5 py-3 flex items-center gap-2">
            <span class="text-base">🚨</span>
            <h3 class="text-sm font-semibold text-red-800">At-Risk Seniors (CRITICAL & HIGH)</h3>
            <span class="ml-auto text-xs text-red-600">{{ $atRiskSeniors->total() }} total</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr class="text-xs text-slate-400">
                        <th class="px-4 py-2.5 text-left font-medium">Senior Citizen</th>
                        <th class="px-4 py-2.5 text-left font-medium">Barangay</th>
                        <th class="px-4 py-2.5 text-left font-medium">Age</th>
                        <th class="px-4 py-2.5 text-left font-medium">Risk Level</th>
                        <th class="px-4 py-2.5 text-left font-medium">Composite</th>
                        <th class="px-4 py-2.5 text-left font-medium">IC Risk</th>
                        <th class="px-4 py-2.5 text-left font-medium">Env Risk</th>
                        <th class="px-4 py-2.5 text-left font-medium">Func Risk</th>
                        <th class="px-4 py-2.5 text-left font-medium">Cluster</th>
                        <th class="px-4 py-2.5 text-right font-medium">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($atRiskSeniors as $senior)
                    <tr class="hover:bg-red-25 transition-colors">
                        <td class="px-4 py-2.5 font-medium text-slate-800">
                            {{ $senior->first_name }} {{ $senior->last_name }}
                            <div class="text-xs text-slate-400">{{ $senior->osca_id }}</div>
                        </td>
                        <td class="px-4 py-2.5 text-slate-600">{{ $senior->barangay }}</td>
                        <td class="px-4 py-2.5 text-slate-600">{{ $senior->age }}</td>
                        <td class="px-4 py-2.5">
                            <span class="text-xs font-bold px-2 py-0.5 rounded-full
                                {{ $senior->overall_risk_level === 'CRITICAL' ? 'bg-red-100 text-red-700' : 'bg-orange-100 text-orange-700' }}">
                                {{ $senior->overall_risk_level }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 font-semibold text-slate-700">{{ number_format($senior->composite_risk * 100, 1) }}%</td>
                        <td class="px-4 py-2.5 text-slate-600">{{ number_format($senior->ic_risk * 100, 1) }}%</td>
                        <td class="px-4 py-2.5 text-slate-600">{{ number_format($senior->env_risk * 100, 1) }}%</td>
                        <td class="px-4 py-2.5 text-slate-600">{{ number_format($senior->func_risk * 100, 1) }}%</td>
                        <td class="px-4 py-2.5 text-slate-600 text-xs">{{ $senior->cluster_name }}</td>
                        <td class="px-4 py-2.5 text-right">
                            <a href="{{ route('seniors.show', $senior->id) }}"
                               class="text-xs font-medium text-teal-600 hover:text-teal-700">View →</a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="10" class="px-4 py-8 text-center text-slate-400">
                            <div class="text-3xl mb-2">✅</div>
                            No critical or high risk seniors found with current filters.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($atRiskSeniors->hasPages())
        <div class="border-t border-slate-100 px-4 py-3">
            {{ $atRiskSeniors->links() }}
        </div>
        @endif
    </div>

    {{-- ── Interactive Risk Explorer ── --}}
    <div class="mt-2">
        <h3 class="text-sm font-semibold text-slate-700 mb-3">Interactive Risk Explorer</h3>
        <livewire:reports.risk-report />
    </div>

</div>
@endsection
