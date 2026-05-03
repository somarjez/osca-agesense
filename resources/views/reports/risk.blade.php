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
                ['Intrinsic Capacity (IC)',  $domainAvgs?->ic        ?? 0],
                ['Environment',             $domainAvgs?->env       ?? 0],
                ['Functional',              $domainAvgs?->func      ?? 0],
                ['Composite',               $domainAvgs?->composite ?? 0],
            ] as [$label, $val])
            @php
                $barColor = $val >= 0.65 ? 'bg-critical-500'
                          : ($val >= 0.45 ? 'bg-high-500'
                          : ($val >= 0.25 ? 'bg-moderate-500' : 'bg-low-500'));
            @endphp
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

            {{-- Risk threshold legend (mirrors osca5.ipynb thresholds) --}}
            <div class="mt-4 pt-3 border-t border-slate-100 grid grid-cols-4 gap-1 text-center text-xs">
                <div class="bg-red-50 text-red-700 px-1 py-1 rounded">Critical ≥65%</div>
                <div class="bg-orange-50 text-orange-700 px-1 py-1 rounded">High 45–65%</div>
                <div class="bg-amber-50 text-amber-700 px-1 py-1 rounded">Moderate 25–45%</div>
                <div class="bg-emerald-50 text-emerald-700 px-1 py-1 rounded">Low &lt;25%</div>
            </div>
        </div>

        {{-- Recommendations by Category --}}
        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-700 mb-4">Pending Recommendations by Category</h3>
            @foreach ($recsByCategory as $rec)
            @php
            $catIcons = [
                'health'     => '<svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>',
                'financial'  => '<svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
                'social'     => '<svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-teal-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-5.356-3.712M9 20H4v-2a4 4 0 015.356-3.712M15 7a4 4 0 11-8 0 4 4 0 018 0zm6 3a3 3 0 11-6 0 3 3 0 016 0zM3 10a3 3 0 116 0 3 3 0 01-6 0z"/></svg>',
                'functional' => '<svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>',
                'hc_access'  => '<svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>',
                'general'    => '<svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"/></svg>',
            ];
            $defaultIcon = '<svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"/></svg>';
            @endphp
            <div class="flex items-center gap-3 mb-2.5">
                <span>{!! $catIcons[$rec->category] ?? $defaultIcon !!}</span>
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
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-red-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
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
                            <div class="flex justify-center mb-2">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
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
