@extends('layouts.app')
@section('page-title', 'QoL Survey Results')
@section('page-subtitle', $survey->seniorCitizen?->full_name . ' · ' . $survey->survey_date?->format('M j, Y'))

@section('content')
@php
    $senior = $survey->seniorCitizen;
    $ml = $survey->mlResult;
    $domainLabels = [
        'score_qol'           => 'Overall QoL',
        'score_physical'      => 'Physical Health',
        'score_psychological' => 'Psychological',
        'score_independence'  => 'Independence',
        'score_social'        => 'Social',
        'score_environment'   => 'Environment',
        'score_financial'     => 'Financial',
        'score_spirituality'  => 'Spirituality',
    ];
@endphp
<div class="space-y-5">

    {{-- Back --}}
    @if ($senior && !$senior->trashed())
    <a href="{{ route('seniors.show', $senior) }}" class="btn btn-ghost gap-1.5 pl-1.5 w-fit">
        <x-heroicon-o-arrow-left class="w-3.5 h-3.5" /> Back to {{ $senior->full_name }}
    </a>
    @else
    <a href="{{ route('surveys.qol.index') }}" class="btn btn-ghost gap-1.5 pl-1.5 w-fit">
        <x-heroicon-o-arrow-left class="w-3.5 h-3.5" /> Back to QoL Surveys
    </a>
    @endif

    {{-- Summary header --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Overall Score --}}
        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm text-center">
            <p class="text-xs text-slate-500 uppercase tracking-wider font-medium mb-3">Overall QoL Score</p>
            <div class="relative w-28 h-28 mx-auto mb-3">
                <svg class="w-28 h-28 -rotate-90" viewBox="0 0 36 36">
                    <circle cx="18" cy="18" r="15.9" fill="none" stroke="#e2e8f0" stroke-width="3"/>
                    @php $pct = round(($survey->overall_score ?? 0) * 100); @endphp
                    <circle cx="18" cy="18" r="15.9" fill="none"
                            stroke="{{ $pct >= 70 ? '#10b981' : ($pct >= 50 ? '#f59e0b' : '#ef4444') }}"
                            stroke-width="3"
                            stroke-dasharray="{{ $pct }}, 100"
                            stroke-linecap="round"/>
                </svg>
                <div class="absolute inset-0 flex items-center justify-center">
                    <span class="text-2xl font-bold text-slate-800">{{ $pct }}%</span>
                </div>
            </div>
            <p class="text-sm font-semibold {{ $pct >= 70 ? 'text-emerald-600' : ($pct >= 50 ? 'text-amber-600' : 'text-red-600') }}">
                {{ $pct >= 70 ? 'Good' : ($pct >= 50 ? 'Fair' : 'Poor') }} Quality of Life
            </p>
        </div>

        {{-- ML Risk --}}
        @if ($ml)
        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
            <p class="text-xs text-slate-500 uppercase tracking-wider font-medium mb-3">Risk Assessment</p>
            <div class="space-y-3">
                @foreach ([
                    ['Physical Capacity', $ml->ic_risk,        $ml->ic_risk_level],
                    ['Environment',       $ml->env_risk,       $ml->env_risk_level],
                    ['Daily Functioning', $ml->func_risk,      $ml->func_risk_level],
                    ['Overall Risk',      $ml->composite_risk, $ml->overall_risk_level],
                ] as [$label, $score, $level])
                <div class="flex items-center gap-3">
                    <span class="text-xs text-slate-500 w-20 flex-shrink-0">{{ $label }}</span>
                    <div class="flex-1 bg-slate-200 rounded-full h-1.5">
                        <div class="h-1.5 rounded-full {{ $score >= 0.65 ? 'bg-critical-500' : ($score >= 0.45 ? 'bg-high-500' : ($score >= 0.25 ? 'bg-moderate-500' : 'bg-low-500')) }}"
                             style="width: {{ round($score * 100) }}%"></div>
                    </div>
                    <span class="text-xs font-semibold w-12 text-right text-slate-700">{{ round($score * 100, 1) }}%</span>
                    <span class="text-xs px-1.5 py-0.5 rounded font-bold
                        {{ match(strtoupper($level)) {
                            'CRITICAL' => 'bg-red-100 text-red-700',
                            'HIGH'     => 'bg-orange-100 text-orange-700',
                            'MODERATE' => 'bg-amber-100 text-amber-700',
                            default    => 'bg-emerald-100 text-emerald-700',
                        } }}">
                        {{ strtoupper($level) }}
                    </span>
                </div>
                @endforeach
            </div>
            <div class="mt-3 pt-3 border-t border-slate-100 text-center">
                <span class="text-xs text-slate-500">Health Group</span>
                <p class="font-semibold text-slate-800">
                    Group {{ $ml->cluster_named_id }}: {{ $ml->cluster_name }}
                </p>
            </div>
        </div>
        @else
        <div class="bg-slate-50 border border-slate-200 rounded-xl p-5 flex items-center justify-center">
            <p class="text-sm text-slate-400">Assessment not yet run.</p>
        </div>
        @endif

        {{-- Domain radar chart --}}
        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
            <p class="text-xs text-slate-500 uppercase tracking-wider font-medium mb-3">Domain Scores</p>
            <canvas id="domainRadar" class="max-h-48"></canvas>
        </div>
    </div>

    {{-- Domain breakdown table --}}
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-700">QoL Domain Breakdown</h3>
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-0 divide-x divide-y divide-slate-100">
            @foreach ($domainLabels as $col => $label)
            @php $score = $survey->$col; $pctD = $score !== null ? round($score * 100) : null; @endphp
            <div class="p-4 text-center">
                <p class="text-xs text-slate-500 mb-2">{{ $label }}</p>
                @if ($pctD !== null)
                <p class="text-2xl font-bold {{ $pctD >= 70 ? 'text-emerald-600' : ($pctD >= 50 ? 'text-amber-500' : 'text-red-500') }}">
                    {{ $pctD }}%
                </p>
                <div class="w-full bg-slate-200 rounded-full h-1 mt-2">
                    <div class="h-1 rounded-full {{ $pctD >= 70 ? 'bg-emerald-500' : ($pctD >= 50 ? 'bg-amber-400' : 'bg-red-400') }}"
                         style="width: {{ $pctD }}%"></div>
                </div>
                @else
                <p class="text-slate-300 text-lg">—</p>
                @endif
            </div>
            @endforeach
        </div>
    </div>

    {{-- Recommendations --}}
    @if ($ml && $ml->recommendations->count())
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-700">Generated Recommendations ({{ $ml->recommendations->count() }})</h3>
            @if ($senior && !$senior->trashed())
            <a href="{{ route('recommendations.show', $senior) }}" class="text-xs text-teal-600 hover:text-teal-700 font-medium">Manage →</a>
            @endif
        </div>
        <div class="divide-y divide-slate-50">
            @foreach ($ml->recommendations->sortBy('priority') as $rec)
            <div class="px-5 py-3 flex items-start gap-3">
                <span class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold
                    {{ $rec->urgency === 'immediate' ? 'bg-red-500 text-white' : ($rec->urgency === 'urgent' ? 'bg-orange-400 text-white' : 'bg-slate-200 text-slate-600') }}">
                    {{ $rec->priority }}
                </span>
                <div class="flex-1">
                    <p class="text-sm text-slate-700">{{ $rec->action }}</p>
                    <p class="text-xs text-slate-400 mt-0.5">
                        {{ ucfirst($rec->category) }} · {{ ucfirst($rec->urgency) }}
                        @if ($rec->domain) · {{ strtoupper($rec->domain) }} @endif
                    </p>
                </div>
                <span class="{{ $rec->urgency_badge }} text-xs px-2 py-0.5 rounded-full font-medium flex-shrink-0">
                    {{ ucfirst($rec->urgency) }}
                </span>
            </div>
            @endforeach
        </div>
    </div>
    @endif

</div>
@endsection

@push('scripts')
<script>
(function () {
    const labels = @json(array_values($domainLabels));
    const data   = @json(collect($domainLabels)->keys()->map(fn($k) => round(($survey->{$k} ?? 0) * 100, 1))->values());

    function initDomainRadar() {
        const canvas = document.getElementById('domainRadar');
        if (!canvas) return;
        const existing = Object.values(Chart.instances).find(c => c.canvas === canvas);
        if (existing) existing.destroy();
        new Chart(canvas, {
            type: 'radar',
            data: {
                labels,
                datasets: [{
                    data,
                    backgroundColor: 'rgba(20,184,166,0.15)',
                    borderColor: 'rgb(20,184,166)',
                    pointBackgroundColor: 'rgb(20,184,166)',
                    pointRadius: 3,
                    borderWidth: 2,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    r: {
                        min: 0, max: 100,
                        ticks: { stepSize: 25, font: { size: 8 } },
                        pointLabels: { font: { size: 8 } },
                    }
                }
            }
        });
    }

    document.addEventListener('livewire:navigated', () => setTimeout(initDomainRadar, 0));
})();
</script>
@endpush
