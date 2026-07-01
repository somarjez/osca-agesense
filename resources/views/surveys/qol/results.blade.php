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
        <div class="card">
            <div class="card-body text-center">
                <p class="eyebrow mb-3">Overall QoL Score</p>
                <div class="relative w-28 h-28 mx-auto mb-3">
                    <svg class="w-28 h-28 -rotate-90" viewBox="0 0 36 36">
                        <circle cx="18" cy="18" r="15.9" fill="none" stroke="currentColor"
                                class="text-paper-rule dark:text-[#2b3530]" stroke-width="3"/>
                        @php $pct = round(($survey->overall_score ?? 0) * 100); @endphp
                        <circle cx="18" cy="18" r="15.9" fill="none"
                                stroke="{{ $pct >= 70 ? '#4a8a68' : ($pct >= 50 ? '#c19a3b' : '#b94a3a') }}"
                                stroke-width="3"
                                stroke-dasharray="{{ $pct }}, 100"
                                stroke-linecap="round"/>
                    </svg>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <span class="text-2xl font-bold font-serif text-ink-900 dark:text-[#e4e1d8]">{{ $pct }}%</span>
                    </div>
                </div>
                <p class="text-[13px] font-semibold
                    {{ $pct >= 70 ? 'text-low-700' : ($pct >= 50 ? 'text-moderate-700' : 'text-critical-700') }}">
                    {{ $pct >= 70 ? 'Good' : ($pct >= 50 ? 'Fair' : 'Poor') }} Quality of Life
                </p>
            </div>
        </div>

        {{-- ML Risk --}}
        @if ($ml)
        <div class="card">
            <div class="card-body">
                <p class="eyebrow mb-3">Risk Assessment</p>
                <div class="space-y-3">
                    @foreach ([
                        ['Physical Capacity', $ml->ic_risk,        $ml->ic_risk_level],
                        ['Environment',       $ml->env_risk,       $ml->env_risk_level],
                        ['Daily Functioning', $ml->func_risk,      $ml->func_risk_level],
                        ['Overall Risk',      $ml->composite_risk, $ml->overall_risk_level],
                    ] as [$label, $score, $level])
                    <div class="flex items-center gap-3">
                        <span class="text-[11.5px] text-ink-500 dark:text-[#6b7570] w-[90px] flex-shrink-0">{{ $label }}</span>
                        <div class="flex-1 bar">
                            <div class="bar-fill {{ $score >= 0.50 ? 'bar-fill-high' : ($score >= 0.30 ? 'bar-fill-moderate' : 'bar-fill-low') }}"
                                 style="width: {{ round($score * 100) }}%"></div>
                        </div>
                        <span class="text-[11.5px] font-mono font-semibold w-10 text-right text-ink-700 dark:text-[#b0b5b2] tnum">{{ round($score * 100, 1) }}%</span>
                        <x-risk-badge :level="$level" />
                    </div>
                    @endforeach
                </div>
                <div class="mt-3 pt-3 border-t border-paper-rule dark:border-[#2b3530] text-center">
                    <span class="eyebrow">Profile Group</span>
                    <p class="font-semibold text-[13px] text-ink-900 dark:text-[#e4e1d8] mt-1">
                        Group {{ $ml->cluster_named_id }}: {{ $ml->cluster_name }}
                    </p>
                </div>
            </div>
        </div>
        @else
        <div class="card">
            <div class="card-body flex items-center justify-center h-full min-h-[120px]">
                <p class="text-[13px] text-ink-400 dark:text-[#6b7570]">Assessment not yet run.</p>
            </div>
        </div>
        @endif

        {{-- Domain radar chart --}}
        <div class="card">
            <div class="card-body">
                <p class="eyebrow mb-3">Domain Scores</p>
                <div class="relative h-48">
                    <canvas id="domainRadar"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- Domain breakdown table --}}
    <div class="card overflow-hidden">
        <div class="card-head">
            <div class="card-title">QoL Domain Breakdown</div>
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-0 divide-x divide-y divide-paper-rule dark:divide-[#2b3530]">
            @foreach ($domainLabels as $col => $label)
            @php $score = $survey->$col; $pctD = $score !== null ? round($score * 100) : null; @endphp
            <div class="p-4 text-center">
                <p class="eyebrow mb-2">{{ $label }}</p>
                @if ($pctD !== null)
                <p class="text-2xl font-bold font-serif tnum
                    {{ $pctD >= 70 ? 'text-low-700' : ($pctD >= 50 ? 'text-moderate-700' : 'text-critical-700') }}">
                    {{ $pctD }}%
                </p>
                <div class="bar mt-2 mx-auto max-w-[80px]">
                    <div class="bar-fill {{ $pctD >= 70 ? 'bar-fill-low' : ($pctD >= 50 ? 'bar-fill-moderate' : 'bar-fill-critical') }}"
                         style="width: {{ $pctD }}%"></div>
                </div>
                @else
                <p class="text-ink-300 dark:text-[#4a5550] text-lg">—</p>
                @endif
            </div>
            @endforeach
        </div>
    </div>

    {{-- Recommendations --}}
    @if ($ml && $ml->recommendations->count())
    <div class="card overflow-hidden">
        <div class="card-head">
            <div class="card-title">Generated Recommendations ({{ $ml->recommendations->count() }})</div>
            @if ($senior && !$senior->trashed())
            <a href="{{ route('recommendations.show', $senior) }}" class="text-xs text-forest-700 dark:text-forest-400 hover:text-forest-900 font-medium">Manage →</a>
            @endif
        </div>
        <div class="divide-y divide-paper-rule dark:divide-[#2b3530]">
            @foreach ($ml->recommendations->sortBy('priority') as $rec)
            <div class="px-5 py-3 flex items-start gap-3 hover:bg-forest-50/40 dark:hover:bg-forest-900/10 transition-colors">
                <span class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-[11px] font-bold
                    {{ $rec->urgency === 'immediate' ? 'bg-critical-500 text-white' : ($rec->urgency === 'urgent' ? 'bg-high-500 text-white' : 'bg-paper-2 dark:bg-[#202a26] text-ink-600 dark:text-[#8a9087]') }}">
                    {{ $rec->priority }}
                </span>
                <div class="flex-1">
                    <p class="text-[13px] text-ink-700 dark:text-[#c8c4bc]">{{ $rec->action }}</p>
                    <p class="text-[11.5px] text-ink-400 dark:text-[#6b7570] mt-0.5">
                        {{ ucfirst($rec->category) }} · {{ ucfirst($rec->urgency) }}
                        @if ($rec->domain) · {{ strtoupper($rec->domain) }} @endif
                    </p>
                </div>
                <span class="badge {{ match($rec->urgency) {
                    'immediate' => 'badge-critical',
                    'urgent'    => 'badge-high',
                    'planned'   => 'badge-info',
                    default     => 'badge-neutral',
                } }} flex-shrink-0">{{ ucfirst($rec->urgency) }}</span>
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

    function isDark() {
        return document.documentElement.classList.contains('dark');
    }

    function initDomainRadar() {
        const canvas = document.getElementById('domainRadar');
        if (!canvas) return;
        const existing = Object.values(Chart.instances).find(c => c.canvas === canvas);
        if (existing) existing.destroy();

        const dark = isDark();
        const gridColor   = dark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)';
        const tickColor   = dark ? '#6b7570' : '#8a8f86';
        const labelColor  = dark ? '#8a9087' : '#6b7269';

        new Chart(canvas, {
            type: 'radar',
            data: {
                labels,
                datasets: [{
                    data,
                    backgroundColor: 'rgba(58, 111, 196, 0.15)',
                    borderColor: '#3a6fc4',
                    pointBackgroundColor: '#3a6fc4',
                    pointRadius: 3,
                    borderWidth: 2,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    r: {
                        min: 0, max: 100,
                        ticks: {
                            stepSize: 25,
                            font: { size: 9 },
                            color: tickColor,
                            backdropColor: 'transparent',
                        },
                        grid: { color: gridColor },
                        angleLines: { color: gridColor },
                        pointLabels: { font: { size: 9 }, color: labelColor },
                    }
                }
            }
        });
    }

    // Observe dark mode class changes and re-render chart
    new MutationObserver(() => setTimeout(initDomainRadar, 50))
        .observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

    document.addEventListener('livewire:navigated', () => setTimeout(initDomainRadar, 0));
    initDomainRadar();
})();
</script>
@endpush
