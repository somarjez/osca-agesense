{{-- resources/views/reports/risk.blade.php --}}
@extends('layouts.app')
@section('page-title', 'Risk Reports')

@push('styles')
<style>
@media print {
    aside, header, nav, form, .btn, [aria-label="Dismiss notification"] { display: none !important; }
    body, html { overflow: visible !important; height: auto !important; }
    #main-content { overflow: visible !important; padding: 0 !important; }
    main { overflow: visible !important; }
    .print-header { display: block !important; border-bottom: 2px solid #2d6a4f; padding-bottom: 12px; margin-bottom: 24px; }
    .print-header h1 { font-size: 18px; font-weight: 700; color: #1a3c2b; }
    .print-header p  { font-size: 11px; color: #4a6856; margin-top: 4px; }
    @page { margin: 20mm; }
    .card, .kpi { page-break-inside: avoid; }
    tr { page-break-inside: avoid; }
}
</style>
@endpush

@section('content')
<div class="space-y-5">

    {{-- Print-only formal header (hidden on screen via CSS, shown in print) --}}
    <div class="print-header hidden">
        <h1>OSCA Risk Report — Pagsanjan, Laguna</h1>
        <p>Generated: {{ now()->format('F j, Y') }} · OSCA AgeSense System</p>
    </div>

    {{-- ── Interactive Risk Explorer (primary tool) ── --}}
    <div>
        <div class="eyebrow text-forest-600 dark:text-forest-400 mb-1">Risk Analysis</div>
        <h2 class="font-display text-2xl leading-tight text-ink-900 dark:text-[#e4e1d8]">Interactive Risk Explorer</h2>
        <p class="text-sm text-ink-500 dark:text-[#8a9087] mt-0.5">Filter and drill down by barangay, risk level, and domain scores.</p>
    </div>

    <livewire:reports.risk-report lazy />

    {{-- ── Supporting analytics ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">

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
                <p class="text-[10.5px] text-ink-400 dark:text-[#6b7570]">Scores ≥ 70% are High-risk with <strong>⚠ Urgent</strong> priority flag.</p>
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
                            <span class="text-ink-600 dark:text-[#b0b5b2]">{{ \App\Support\RecommendationCategories::label($rec->category) }}</span>
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

    <x-doc-footer signatory="OSCA Officer / Reviewer" />
</div>
@endsection
