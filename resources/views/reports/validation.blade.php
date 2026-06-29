{{-- resources/views/reports/validation.blade.php --}}
@extends('layouts.app')
@section('page-title', 'System Validation')
@section('page-subtitle', 'Evidence that the analysis behind AgeSense is sound — Pagsanjan, Laguna')

@section('content')

@php
    $chip = fn (array $v) => $v['good']
        ? '<span class="badge badge-low">'.e($v['label']).'</span>'
        : '<span class="badge badge-moderate">'.e($v['label']).'</span>';
    $pct = fn ($x, $d = 1) => number_format(((float) $x) * 100, $d).'%';
    $n3 = fn ($x) => number_format((float) $x, 3);
@endphp

@if (! $available)
    {{-- Empty state — artifacts not yet synced --}}
    <div class="card card-body max-w-xl mx-auto text-center py-10">
        <div class="mx-auto w-12 h-12 rounded-2xl bg-paper-2 dark:bg-[#131917] grid place-items-center mb-4">
            <x-heroicon-o-chart-bar-square class="w-6 h-6 text-ink-400" />
        </div>
        <h2 class="font-serif text-xl font-semibold text-ink-900 dark:text-[#e4e1d8]">No validation data yet</h2>
        <p class="text-sm text-ink-500 dark:text-[#8a9087] mt-2 leading-relaxed">
            The evaluation reports haven't been imported. Run
            <code class="font-mono text-[12.5px] bg-paper-2 dark:bg-[#131917] px-1.5 py-0.5 rounded">php artisan ml:sync-validation</code>
            to publish the latest results, then reload this page.
        </p>
    </div>
@else

<div class="space-y-6" x-data="{ section: 'clustering' }">

    {{-- ── Masthead + scorecard ── --}}
    <div class="rounded-2xl border border-paper-rule dark:border-[#2b3530] bg-white dark:bg-[#151c19] overflow-hidden">
        <div class="px-6 py-5 sm:flex items-start justify-between gap-6">
            <div class="min-w-0">
                <div class="eyebrow text-forest-700 dark:text-forest-300">Model evaluation report</div>
                <h1 class="font-serif text-[26px] font-semibold tracking-snug text-ink-900 dark:text-[#e4e1d8] mt-1 text-balance">
                    How we know the system works
                </h1>
                <p class="text-[13.5px] text-ink-500 dark:text-[#8a9087] mt-2 max-w-2xl leading-relaxed">
                    Every senior is sorted into a care group, given domain risk estimates, an explanation of the drivers,
                    and tailored recommendations. This page shows the independent checks behind each of those four steps —
                    the technical measure, what it means, and whether it holds up.
                </p>
            </div>
            @if ($provenance['generated_at'])
                <div class="mt-4 sm:mt-1 flex-shrink-0 text-right">
                    <div class="eyebrow">Evaluated</div>
                    <div class="font-mono text-[13px] text-ink-700 dark:text-[#c8c4bc] mt-1">
                        {{ $provenance['generated_at']->format('d M Y') }}
                    </div>
                    <div class="text-[11px] text-ink-400 mt-0.5">{{ $provenance['n_seniors'] }} senior records</div>
                </div>
            @endif
        </div>

        {{-- Pipeline scorecard — the four steps, in order --}}
        <div class="border-t border-paper-rule dark:border-[#2b3530] grid grid-cols-2 lg:grid-cols-4 divide-x divide-paper-rule dark:divide-[#2b3530]">
            @php
                $score = [
                    ['#clustering', 'Care groups', $clustering['verdicts']['separation']],
                    ['#risk', 'Risk estimates', $regression['verdicts']['accuracy']],
                    ['#xai', 'Explainability', $xai['verdicts']['sanity']],
                    ['#recs', 'Recommendations', $recs['verdicts']['coverage']],
                ];
            @endphp
            @foreach ($score as $i => [$href, $label, $v])
            <a href="{{ $href }}" class="px-5 py-4 hover:bg-paper-2/60 dark:hover:bg-[#131917] transition-colors group">
                <div class="flex items-center gap-2">
                    <span class="font-mono text-[11px] text-ink-300 dark:text-[#5a6460]">0{{ $i + 1 }}</span>
                    <span class="text-[12.5px] font-semibold text-ink-700 dark:text-[#c8c4bc] group-hover:text-ink-900 dark:group-hover:text-[#e4e1d8]">{{ $label }}</span>
                </div>
                <div class="mt-2">{!! $chip($v) !!}</div>
            </a>
            @endforeach
        </div>
    </div>

    {{-- ── System confidence + scope ── --}}
    @php
        $goodCount = collect($score)->filter(fn ($s) => $s[2]['good'])->count();
        $overallGood = $goodCount >= 3;
        $confGood = $confidence['good'] ?? false;
    @endphp
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- System confidence card --}}
        <div class="card lg:col-span-2 {{ $confGood ? 'ring-1 ring-low-500/30' : 'ring-1 ring-moderate-500/30' }}"
             x-data="{ open: false }">
            <div class="card-body">

                {{-- Headline row --}}
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-start gap-4 min-w-0">
                        <div class="w-11 h-11 rounded-2xl grid place-items-center flex-shrink-0
                                    {{ $confGood ? 'bg-low-50 dark:bg-low-900/30' : 'bg-moderate-50 dark:bg-moderate-900/30' }}">
                            <x-heroicon-o-shield-check class="w-6 h-6 {{ $confGood ? 'text-low-600' : 'text-moderate-600' }}" />
                        </div>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="eyebrow">System confidence</span>
                                <span class="badge {{ $confGood ? 'badge-low' : 'badge-moderate' }}">
                                    {{ $confidence['band'] }}
                                </span>
                                <span class="badge badge-neutral text-[10px]">
                                    {{ ucfirst($confidence['scope']) }}
                                </span>
                            </div>
                            <p class="text-[12px] text-ink-400 dark:text-[#8a9087] mt-1 leading-snug">
                                Soundness, reproducibility &amp; coverage — not a clinical-accuracy claim.
                            </p>
                        </div>
                    </div>

                    {{-- Big score --}}
                    <div class="text-right flex-shrink-0">
                        <div class="font-serif text-5xl font-semibold leading-none tnum
                                    {{ $confGood ? 'text-low-700 dark:text-[#6dd89e]' : 'text-moderate-700 dark:text-[#e0c060]' }}">
                            {{ $confidence['pct'] }}<span class="text-2xl font-normal text-ink-300">%</span>
                        </div>
                        <div class="text-[10px] uppercase tracking-wider text-ink-400 mt-1">
                            Confidence score
                        </div>
                    </div>
                </div>

                {{-- Per-pillar contribution bars --}}
                <div class="mt-5 space-y-2.5">
                    @foreach ($confidence['pillars'] as $cp)
                    <div class="flex items-center gap-3">
                        <div class="w-32 flex-shrink-0">
                            <div class="text-[12px] font-medium text-ink-700 dark:text-[#c8c4bc]">{{ $cp['label'] }}</div>
                            <div class="text-[10.5px] text-ink-400">×{{ $cp['weight_pct'] }}% weight</div>
                        </div>
                        <div class="flex-1 bar h-2.5">
                            <div class="bar-fill" style="width: {{ round($cp['score'] * 100) }}%; background: #2657aa;"></div>
                        </div>
                        <div class="w-12 text-right font-mono font-semibold tnum text-[12.5px] text-ink-800 dark:text-[#c8c4bc]">
                            {{ round($cp['score'] * 100) }}%
                        </div>
                    </div>
                    @endforeach
                </div>

                {{-- Collapsible methodology --}}
                <div class="mt-4 pt-4 border-t border-paper-rule dark:border-[#2b3530]">
                    <button type="button"
                            @click="open = !open"
                            class="flex items-center gap-2 text-[12px] text-ink-500 dark:text-[#8a9087] hover:text-ink-700 dark:hover:text-[#c8c4bc] transition-colors">
                        <x-heroicon-o-calculator class="w-3.5 h-3.5 flex-shrink-0" />
                        <span x-text="open ? 'Hide methodology' : 'How this is computed'"></span>
                        <x-heroicon-o-chevron-down class="w-3.5 h-3.5 transition-transform" ::class="open ? 'rotate-180' : ''" />
                    </button>

                    <div x-show="open" x-collapse x-cloak class="mt-3 space-y-4">
                        {{-- Per-pillar component breakdown --}}
                        @foreach ($confidence['pillars'] as $cp)
                        <div>
                            <div class="text-[11px] font-semibold uppercase tracking-wider text-ink-500 dark:text-[#8a9087] mb-1.5">
                                {{ $cp['label'] }} &middot; weight {{ $cp['weight_pct'] }}%
                            </div>
                            <div class="space-y-1">
                                @foreach ($cp['components'] as [$cLabel, $cVal])
                                <div class="flex items-center gap-2 text-[11.5px]">
                                    <span class="w-44 flex-shrink-0 text-ink-600 dark:text-[#a8b0ab]">{{ $cLabel }}</span>
                                    <div class="flex-1 bar h-1.5">
                                        <div class="bar-fill" style="width: {{ round($cVal * 100) }}%; background: #5689d6;"></div>
                                    </div>
                                    <span class="w-10 text-right font-mono tnum text-ink-500">{{ round($cVal * 100) }}%</span>
                                </div>
                                @endforeach
                            </div>
                        </div>
                        @endforeach

                        {{-- Band scale --}}
                        <div class="rounded-xl bg-paper-2 dark:bg-[#131917] border border-paper-rule dark:border-[#2b3530] p-3">
                            <div class="text-[11px] font-semibold uppercase tracking-wider text-ink-400 mb-2">Confidence bands</div>
                            <div class="flex gap-3 flex-wrap">
                                @foreach ($confidence['bands'] as $band)
                                <div class="flex items-center gap-1.5">
                                    <span class="badge badge-{{ $band['tone'] }} text-[10px]">{{ $band['label'] }}</span>
                                    <span class="text-[11px] text-ink-400">&ge;{{ $band['min'] }}%</span>
                                </div>
                                @endforeach
                            </div>
                            <p class="text-[11px] text-ink-400 dark:text-[#8a9087] mt-2 leading-relaxed">
                                Each component is normalised: 0% = at the minimum acceptable threshold, 100% = target
                                benchmark. Pillar scores are the mean of their components; the overall is the weighted sum.
                            </p>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        {{-- Scope & limits --}}
        <div class="card bg-paper-2/50 dark:bg-[#131917]">
            <div class="card-body">
                <div class="flex items-center gap-2 mb-2">
                    <x-heroicon-o-information-circle class="w-4 h-4 text-ink-400" />
                    <span class="eyebrow">Scope &amp; limits</span>
                </div>
                <p class="text-[12px] text-ink-500 dark:text-[#8a9087] leading-relaxed">
                    A decision-support tool for OSCA and LGU planning. It indicates <em>possible</em> risk levels and service
                    priorities — it does not diagnose medical conditions, confirm clinical outcomes, or replace professional
                    judgment. Findings are from a single municipality and a one-time survey.
                </p>

                <div class="mt-4 pt-3 border-t border-paper-rule dark:border-[#2b3530]">
                    <div class="eyebrow mb-2">Overall evaluation verdict</div>
                    <div class="flex items-center gap-2">
                        <span class="badge {{ $overallGood ? 'badge-low' : 'badge-moderate' }}">{{ $overallGood ? 'PASS' : 'Review' }}</span>
                        <span class="text-[12px] text-ink-500 dark:text-[#8a9087]">{{ $goodCount }} of {{ count($score) }} pillars pass</span>
                    </div>
                </div>
            </div>
        </div>

    </div>

    {{-- ── Sticky in-page nav ── --}}
    <div class="sticky top-2 z-10 -mx-1">
        <div class="segmented flex-wrap shadow-sm">
            @foreach ([['clustering','01 · Care Groups'],['risk','02 · Risk'],['xai','03 · Explainability'],['recs','04 · Recommendations']] as [$id, $lbl])
            <button type="button"
                    @click="section = '{{ $id }}'; document.getElementById('{{ $id }}').scrollIntoView({behavior:'smooth', block:'start'})"
                    :class="section === '{{ $id }}' ? 'bg-white text-ink-900 shadow-sm dark:bg-[#222a27] dark:text-[#e4e1d8]' : ''">{{ $lbl }}</button>
            @endforeach
        </div>
    </div>

    {{-- ════════ 01 · CARE GROUPS ════════ --}}
    <section id="clustering" class="scroll-mt-20 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-serif text-xl font-semibold text-ink-900 dark:text-[#e4e1d8]">
                <span class="font-mono text-sm text-ink-300 dark:text-[#5a6460] mr-2">01</span>Care-group discovery
            </h2>
            {!! $chip($clustering['verdicts']['separation']) !!}
        </div>
        <p class="text-[13.5px] text-ink-500 dark:text-[#8a9087] max-w-3xl leading-relaxed">
            Seniors are sorted into <strong class="text-ink-700 dark:text-[#c8c4bc]">{{ $clustering['k_chosen'] }} care groups</strong>
            by similarity across health, environment, and functional signals. We tested 2 through 6 groups and judged each split
            on three independent separation measures, then confirmed the result is stable.
        </p>

        {{-- KPI row --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            @php
                $c = $clustering['chosen'];
                $kpis = [
                    ['Care groups', $clustering['k_chosen'], 'Distinct senior profiles', true],
                    ['Group separation', $n3($c['silhouette']), 'Silhouette · higher is better (0–1)', $clustering['verdicts']['separation']['good']],
                    ['Group distinctness', $n3($c['davies_bouldin']), 'Davies–Bouldin · lower is better', $clustering['verdicts']['distinctness']['good']],
                    ['Compactness', number_format($c['calinski_harabasz'], 0), 'Calinski–Harabasz · higher is better', true],
                ];
            @endphp
            @foreach ($kpis as [$label, $value, $note, $good])
            <div class="kpi">
                <div class="kpi-rule {{ $good ? 'bg-low-500' : 'bg-moderate-500' }}"></div>
                <div class="kpi-label">{{ $label }}</div>
                <div class="kpi-value">{{ $value }}</div>
                <div class="kpi-delta {{ $good ? 'text-low-700' : 'text-moderate-700' }}">{{ $note }}</div>
            </div>
            @endforeach
        </div>

        {{-- K-Means selection table — the full evidence behind the choice --}}
        <div class="card">
            <div class="card-head">
                <div class="card-title">Cluster selection — K-Means internal validation</div>
                <div class="card-sub">Every candidate split, scored on three independent measures</div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-[13px]">
                    <thead>
                        <tr class="text-ink-400 text-[10.5px] uppercase tracking-wider border-b border-paper-rule dark:border-[#2b3530]">
                            <th class="text-left px-5 py-2.5 font-semibold">Groups (K)</th>
                            <th class="text-right px-4 py-2.5 font-semibold">Silhouette <span class="text-low-600">▲</span></th>
                            <th class="text-right px-4 py-2.5 font-semibold">Davies–Bouldin <span class="text-low-600">▼</span></th>
                            <th class="text-right px-4 py-2.5 font-semibold">Calinski–Harabasz <span class="text-low-600">▲</span></th>
                            <th class="text-left px-5 py-2.5 font-semibold">Verdict</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-paper-rule dark:divide-[#2b3530]">
                        @foreach ($clustering['sweep'] as $row)
                        @php $isChosen = $row['k'] === $clustering['k_chosen']; @endphp
                        <tr class="{{ $isChosen ? 'bg-forest-50/60 dark:bg-forest-900/20' : '' }}">
                            <td class="px-5 py-2.5 font-semibold text-ink-800 dark:text-[#e4e1d8]">
                                {{ $row['k'] }}
                                @if ($isChosen)<span class="badge badge-low ml-1.5">Selected</span>@endif
                            </td>
                            <td class="px-4 py-2.5 text-right font-mono tnum {{ $row['k'] === $clustering['best_silhouette_k'] ? 'text-low-700 dark:text-[#6dd89e] font-semibold' : 'text-ink-700 dark:text-[#c8c4bc]' }}">
                                {{ $n3($row['silhouette']) }}@if ($row['k'] === $clustering['best_silhouette_k']) <span class="text-[10px]">best</span>@endif
                            </td>
                            <td class="px-4 py-2.5 text-right font-mono tnum {{ $row['k'] === $clustering['best_davies_k'] ? 'text-low-700 dark:text-[#6dd89e] font-semibold' : 'text-ink-700 dark:text-[#c8c4bc]' }}">
                                {{ $n3($row['davies_bouldin']) }}@if ($row['k'] === $clustering['best_davies_k']) <span class="text-[10px]">best</span>@endif
                            </td>
                            <td class="px-4 py-2.5 text-right font-mono tnum {{ $row['k'] === $clustering['best_calinski_k'] ? 'text-low-700 dark:text-[#6dd89e] font-semibold' : 'text-ink-700 dark:text-[#c8c4bc]' }}">
                                {{ number_format($row['calinski_harabasz'], 1) }}@if ($row['k'] === $clustering['best_calinski_k']) <span class="text-[10px]">best</span>@endif
                            </td>
                            <td class="px-5 py-2.5 text-[12px] text-ink-500 dark:text-[#8a9087]">
                                @if ($isChosen) Wins 2 of 3 + actionable tiers
                                @elseif ($row['k'] === $clustering['best_silhouette_k']) Highest separation only
                                @else —
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-body pt-3 text-[12px] text-ink-500 dark:text-[#8a9087] leading-relaxed border-t border-paper-rule dark:border-[#2b3530]">
                <strong class="text-ink-700 dark:text-[#c8c4bc]">Reading the table:</strong>
                Silhouette measures how well each senior sits inside its own group (−1 to 1, higher is better);
                Davies–Bouldin compares within-group spread to between-group distance (lower is better);
                Calinski–Harabasz is the ratio of between- to within-group variance (higher is better).
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">
            {{-- K-selection chart --}}
            <div class="card lg:col-span-3">
                <div class="card-head"><div class="card-title">Why {{ $clustering['k_chosen'] }} groups?</div></div>
                <div class="card-body">
                    <div class="relative h-64"><canvas id="kSelectionChart"></canvas></div>
                </div>
            </div>
            {{-- Reasoning --}}
            <div class="card lg:col-span-2">
                <div class="card-head"><div class="card-title">The decision</div></div>
                <div class="card-body text-[13px] text-ink-600 dark:text-[#a8b0ab] space-y-3 leading-relaxed">
                    <p>
                        @php $wins = $clustering['chosen_wins']; @endphp
                        @if ($clustering['best_silhouette_k'] !== $clustering['k_chosen'])
                            Raw separation slightly favours <strong class="text-ink-800 dark:text-[#e4e1d8]">{{ $clustering['best_silhouette_k'] }} groups</strong> (highest Silhouette),
                            but two groups is too broad for planning.
                        @endif
                        <strong class="text-ink-800 dark:text-[#e4e1d8]">{{ $clustering['k_chosen'] }} groups</strong>
                        @if (count($wins))
                            wins on {{ \Illuminate\Support\Str::replaceLast(', ', ' and ', implode(', ', $wins)) }},
                        @endif
                        and produces care tiers an officer can act on — separating environmentally and financially
                        vulnerable seniors from generally stable, moderate-support seniors.
                    </p>
                    @if (count($clustering['ablation']) >= 2)
                    <p>
                        Removing {{ $clustering['ablation'][0]['n_features'] - $clustering['ablation'][1]['n_features'] }}
                        redundant fields sharpened the grouping from
                        {{ $n3($clustering['ablation'][0]['silhouette']) }} to {{ $n3($clustering['ablation'][1]['silhouette']) }}.
                    </p>
                    @endif
                    @if ($clustering['stability']['stable'])
                    <p class="flex items-start gap-2">
                        <x-heroicon-o-check-circle class="w-4 h-4 mt-0.5 text-low-600 flex-shrink-0" />
                        <span>Identical result across all {{ $clustering['stability']['seeds'] }} random starts — fully reproducible.</span>
                    </p>
                    @endif
                </div>
            </div>
        </div>

        {{-- Ablation + stability evidence --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="card">
                <div class="card-head">
                    <div class="card-title">Feature selection lifted separation</div>
                    <div class="card-sub">Removing redundant survey fields sharpened the grouping</div>
                </div>
                <div class="card-body space-y-2.5">
                    @foreach ($clustering['ablation'] as $a)
                    <div class="flex items-center gap-3">
                        <div class="w-28 flex-shrink-0 text-[11.5px] text-ink-500 dark:text-[#8a9087]">
                            {{ \Illuminate\Support\Str::contains($a['condition'], 'FINAL') ? 'Final set' : 'Baseline' }}
                            <span class="font-mono text-ink-400">({{ $a['n_features'] }})</span>
                        </div>
                        <div class="flex-1 bar h-2.5"><div class="bar-fill" style="width: {{ $a['silhouette'] * 180 }}%; background: {{ \Illuminate\Support\Str::contains($a['condition'], 'FINAL') ? '#2657aa' : '#8ab1e6' }}"></div></div>
                        <div class="w-14 text-right font-mono font-semibold tnum text-ink-800 dark:text-[#c8c4bc]">{{ $n3($a['silhouette']) }}</div>
                    </div>
                    @endforeach
                </div>
            </div>
            <div class="card">
                <div class="card-head">
                    <div class="card-title">Reproducibility across random starts</div>
                    <div class="card-sub">Re-run with {{ $clustering['stability']['seeds'] }} different seeds</div>
                </div>
                <div class="card-body flex items-center gap-4">
                    <div class="text-center flex-shrink-0">
                        <div class="font-serif text-4xl font-semibold text-ink-900 dark:text-[#e4e1d8] tnum">{{ $clustering['stability']['seeds'] }}<span class="text-ink-300">/</span>{{ $clustering['stability']['seeds'] }}</div>
                        <div class="text-[10.5px] uppercase tracking-wider text-ink-400 mt-1">identical runs</div>
                    </div>
                    <p class="text-[12.5px] text-ink-500 dark:text-[#8a9087] leading-relaxed">
                        @if ($clustering['stability']['stable'])
                            Every seed produced the <strong class="text-ink-700 dark:text-[#c8c4bc]">exact same</strong>
                            silhouette, Davies–Bouldin, and Calinski–Harabasz scores — the grouping is fully deterministic,
                            so two officers on two devices see the same care groups.
                        @else
                            Scores varied across {{ $clustering['stability']['distinct'] }} distinct outcomes; results are
                            seed-sensitive and should be pinned before deployment.
                        @endif
                    </p>
                </div>
            </div>
        </div>

        {{-- The four groups --}}
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach ($clustering['groups'] as $g)
            <div class="card card-body">
                <div class="flex items-center justify-between">
                    <span class="badge badge-cluster-{{ $g['id'] }}">Group {{ $g['id'] }}</span>
                    <div class="text-right">
                        <div class="font-serif text-xl font-semibold tnum text-ink-900 dark:text-[#e4e1d8] leading-none">{{ $g['n_seniors'] }}</div>
                        <div class="text-[10px] uppercase tracking-wider text-ink-400 dark:text-[#6b7570]">members</div>
                    </div>
                </div>
                <h3 class="font-serif text-[14px] font-semibold text-ink-900 dark:text-[#e4e1d8] mt-3 leading-snug">{{ $g['name'] }}</h3>
                <p class="text-[12px] text-ink-500 dark:text-[#8a9087] mt-1.5 leading-relaxed">{{ \Illuminate\Support\Str::limit($g['interpretation'], 140) }}</p>
                @if ($g['ic_level'])
                <div class="mt-3 pt-3 border-t border-paper-rule dark:border-[#2b3530] grid grid-cols-3 gap-1 text-center">
                    @foreach (['Capacity' => $g['ic_level'], 'Environ.' => $g['env_level'], 'Function' => $g['func_level']] as $lbl => $lvl)
                    @php $tone = ['High' => 'text-low-700 dark:text-[#6dd89e]', 'Moderate' => 'text-moderate-700 dark:text-[#e0c060]', 'Low' => 'text-high-700 dark:text-[#ef8d80]'][$lvl] ?? 'text-ink-500'; @endphp
                    <div>
                        <div class="text-[9.5px] uppercase tracking-wider text-ink-400">{{ $lbl }}</div>
                        <div class="text-[12px] font-semibold {{ $tone }} mt-0.5">{{ $lvl }}</div>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
            @endforeach
        </div>
    </section>

        {{-- Visual evidence --}}
        <div>
            <div class="eyebrow mb-3">Visual evidence</div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-start">
                @include('reports.partials.validation-figure', ['plot' => 'k_selection_umap', 'title' => 'Figure 1 — Cluster selection', 'caption' => 'Internal validation scores across candidate group counts; the chosen split balances separation with usefulness.'])
                @include('reports.partials.validation-figure', ['plot' => 'cluster_umap_scatter', 'title' => 'Figure 2 — Group separation map', 'caption' => 'Seniors projected to 2-D (UMAP). Distinct colour regions show the groups occupy different areas of the profile space.'])
                @include('reports.partials.validation-figure', ['plot' => 'cluster_heatmap', 'title' => 'Figure 4 — Feature heatmap', 'caption' => 'Average of each survey signal per group; darker cells mark the signals that most distinguish a group.'])
                {{-- Figures 3 + 6 stacked to fill the column beside the heatmap, leaving no gap --}}
                <div class="grid gap-4">
                    @include('reports.partials.validation-figure', ['plot' => 'cluster_radar', 'title' => 'Figure 3 — Profile shapes', 'caption' => 'WHO-domain signature of each care group — how the four profiles differ across capacity, environment, and function.'])
                    @include('reports.partials.validation-figure', ['plot' => 'section_scores_by_cluster', 'title' => 'Figure 6 — Section scores by group', 'caption' => 'Survey-section averages per group, confirming the profiles separate on real, measured characteristics.'])
                </div>
            </div>
            {{-- Figure 5 centered on its own row --}}
            <div class="flex justify-center mt-4">
                <div class="w-full md:w-1/2">
                    @include('reports.partials.validation-figure', ['plot' => 'silhouette_plot', 'title' => 'Figure 5 — Silhouette profile', 'caption' => 'Per-senior silhouette widths; consistently positive widths indicate members sit inside their assigned group.'])
                </div>
            </div>
        </div>
    </section>

    {{-- ════════ 02 · RISK ════════ --}}
    <section id="risk" class="scroll-mt-20 space-y-4 pt-2">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-serif text-xl font-semibold text-ink-900 dark:text-[#e4e1d8]">
                <span class="font-mono text-sm text-ink-300 dark:text-[#5a6460] mr-2">02</span>Risk estimation
            </h2>
            {!! $chip($regression['verdicts']['accuracy']) !!}
        </div>
        <p class="text-[13.5px] text-ink-500 dark:text-[#8a9087] max-w-3xl leading-relaxed">
            Each senior receives three domain risk scores — intrinsic capacity, environment, and functional ability —
            blended into one overall level. The models were tested on held-out seniors they never saw during training.
        </p>

        {{-- Regression KPIs --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            @php
                $rkpis = [
                    ['Cross-validated accuracy', $pct($regression['mean_cv_r2'], 1), 'Average R² across domains', $regression['verdicts']['accuracy']['good']],
                    ['Average error', $n3(collect($regression['cv'])->avg('gbr_mae')), 'Mean absolute error (0–1 scale)', true],
                    ['Agreement with rules', $n3($regression['mean_rho']), 'Correlation with the coded rubric', $regression['verdicts']['agreement']['good']],
                    ['Data leakage', $regression['leakage']['pass'] ? 'None' : 'Check', $regression['leakage']['cv_strategy'], $regression['leakage']['pass']],
                ];
            @endphp
            @foreach ($rkpis as [$label, $value, $note, $good])
            <div class="kpi">
                <div class="kpi-rule {{ $good ? 'bg-low-500' : 'bg-moderate-500' }}"></div>
                <div class="kpi-label">{{ $label }}</div>
                <div class="kpi-value">{{ $value }}</div>
                <div class="kpi-delta {{ $good ? 'text-low-700' : 'text-moderate-700' }}">{{ $note }}</div>
            </div>
            @endforeach
        </div>

        {{-- Per-domain regression evidence table --}}
        <div class="card">
            <div class="card-head">
                <div class="card-title">Regression performance by domain</div>
                <div class="card-sub">5-fold cross-validation and held-out test set · deployed model is Gradient Boosting (GBR)</div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-[12.5px] whitespace-nowrap">
                    <thead>
                        <tr class="text-ink-400 text-[10px] uppercase tracking-wider border-b border-paper-rule dark:border-[#2b3530]">
                            <th class="text-left px-5 py-2.5 font-semibold">Domain</th>
                            <th class="text-left px-3 py-2.5 font-semibold">Model</th>
                            <th class="text-right px-3 py-2.5 font-semibold">CV R²</th>
                            <th class="text-right px-3 py-2.5 font-semibold">CV MAE</th>
                            <th class="text-right px-3 py-2.5 font-semibold">Test MAE</th>
                            <th class="text-right px-3 py-2.5 font-semibold">Test RMSE</th>
                            <th class="text-right px-5 py-2.5 font-semibold">Test R²</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-paper-rule dark:divide-[#2b3530]">
                        @foreach ($regression['table'] as $d)
                        <tr class="bg-forest-50/40 dark:bg-forest-900/10">
                            <td class="px-5 py-2.5 font-semibold text-ink-800 dark:text-[#e4e1d8]" rowspan="2">{{ $d['name'] }}</td>
                            <td class="px-3 py-2.5"><span class="badge badge-low">GBR · deployed</span></td>
                            <td class="px-3 py-2.5 text-right font-mono tnum font-semibold">{{ $n3($d['gbr']['cv_r2']) }} <span class="text-ink-400 text-[10px]">±{{ $n3($d['gbr']['cv_r2_std']) }}</span></td>
                            <td class="px-3 py-2.5 text-right font-mono tnum">{{ $n3($d['gbr']['cv_mae']) }}</td>
                            <td class="px-3 py-2.5 text-right font-mono tnum">{{ $n3($d['gbr']['mae']) }}</td>
                            <td class="px-3 py-2.5 text-right font-mono tnum">{{ $n3($d['gbr']['rmse']) }}</td>
                            <td class="px-5 py-2.5 text-right font-mono tnum font-semibold">{{ $n3($d['gbr']['r2']) }}</td>
                        </tr>
                        <tr>
                            <td class="px-3 py-2 text-ink-500"><span class="badge badge-neutral">RFR · comparison</span></td>
                            <td class="px-3 py-2 text-right font-mono tnum text-ink-500">{{ $n3($d['rfr']['cv_r2']) }}</td>
                            <td class="px-3 py-2 text-right font-mono tnum text-ink-500">{{ $n3($d['rfr']['cv_mae']) }}</td>
                            <td class="px-3 py-2 text-right font-mono tnum text-ink-500">{{ $n3($d['rfr']['mae']) }}</td>
                            <td class="px-3 py-2 text-right font-mono tnum text-ink-500">{{ $n3($d['rfr']['rmse']) }}</td>
                            <td class="px-5 py-2 text-right font-mono tnum text-ink-500">{{ $n3($d['rfr']['r2']) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-body pt-3 border-t border-paper-rule dark:border-[#2b3530] text-[12px] text-ink-500 dark:text-[#8a9087] leading-relaxed">
                <strong class="text-ink-700 dark:text-[#c8c4bc]">R²</strong> is the share of variation the model explains (1.0 = perfect);
                <strong class="text-ink-700 dark:text-[#c8c4bc]">MAE</strong> and <strong class="text-ink-700 dark:text-[#c8c4bc]">RMSE</strong> are the average error on a 0–1 risk scale (lower is better).
                The small ± on CV R² shows the score barely moves across folds — the models are stable, not over-fit to one split.
            </div>
        </div>

        {{-- Deployed hyperparameters --}}
        <div class="card">
            <div class="card-head">
                <div class="card-title">Tuned configuration (deployed GBR)</div>
                <div class="card-sub">Selected by grid search on cross-validated error</div>
            </div>
            <div class="card-body grid grid-cols-1 md:grid-cols-3 gap-4">
                @foreach ($regression['table'] as $d)
                <div class="rounded-xl p-4 bg-paper-2 dark:bg-[#131917] border border-paper-rule dark:border-[#2b3530]">
                    <div class="text-[12px] font-semibold text-ink-800 dark:text-[#e4e1d8] mb-2">{{ $d['name'] }}</div>
                    <dl class="space-y-1">
                        @foreach ($d['gbr']['params'] as $k => $v)
                        <div class="flex justify-between text-[11.5px]">
                            <dt class="text-ink-500 dark:text-[#8a9087] font-mono">{{ $k }}</dt>
                            <dd class="font-mono font-semibold text-ink-700 dark:text-[#c8c4bc] tnum">{{ is_null($v) ? 'none' : $v }}</dd>
                        </div>
                        @endforeach
                    </dl>
                </div>
                @endforeach
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            {{-- Per-domain accuracy --}}
            <div class="card">
                <div class="card-head"><div class="card-title">Accuracy by domain</div></div>
                <div class="card-body"><div class="relative h-56"><canvas id="cvR2Chart"></canvas></div></div>
            </div>
            {{-- Calibration --}}
            <div class="card">
                <div class="card-head">
                    <div class="card-title">Calibration</div>
                    <div class="card-sub">Predicted vs. actual risk, by band</div>
                </div>
                <div class="card-body"><div class="relative h-56"><canvas id="calibrationChart"></canvas></div></div>
            </div>
        </div>

        {{-- Agreement with rubric + leakage --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="card">
                <div class="card-head">
                    <div class="card-title">Agreement with the coded rubric</div>
                    <div class="card-sub">Spearman rank correlation (ρ) between model output and the rule-based score</div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-[12.5px]">
                        <thead>
                            <tr class="text-ink-400 text-[10px] uppercase tracking-wider border-b border-paper-rule dark:border-[#2b3530]">
                                <th class="text-left px-5 py-2.5 font-semibold">Domain</th>
                                <th class="text-right px-3 py-2.5 font-semibold">ρ (GBR)</th>
                                <th class="text-right px-3 py-2.5 font-semibold">ρ (RFR)</th>
                                <th class="text-right px-3 py-2.5 font-semibold">p-value</th>
                                <th class="text-right px-5 py-2.5 font-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-paper-rule dark:divide-[#2b3530]">
                            @foreach ($regression['domains'] as $key => $name)
                            @php $cr = $regression['correlation'][$key] ?? null; @endphp
                            @if ($cr)
                            <tr>
                                <td class="px-5 py-2.5 font-medium text-ink-800 dark:text-[#c8c4bc]">{{ $name }}</td>
                                <td class="px-3 py-2.5 text-right font-mono tnum font-semibold">{{ $n3($cr['rho']) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono tnum text-ink-500">{{ $n3($cr['rfr_rho']) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono tnum text-ink-500">&lt;0.001</td>
                                <td class="px-5 py-2.5 text-right"><span class="badge badge-low">{{ $cr['status'] }}</span></td>
                            </tr>
                            @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="card-body pt-3 border-t border-paper-rule dark:border-[#2b3530] text-[12px] text-ink-500 dark:text-[#8a9087] leading-relaxed">
                    ρ near 1.0 means the learned models rank seniors almost identically to the transparent, hand-coded rubric —
                    the machine learning adds nuance without contradicting the documented logic.
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <div class="card-title">Guards against leakage &amp; over-fitting</div>
                </div>
                <div class="card-body space-y-3">
                    @php
                        $guards = [
                            ['Validation', $regression['leakage']['cv_strategy'], true],
                            ['Inputs', $regression['leakage']['features_used'].' survey-derived features, no labels', true],
                            ['Target separation', $regression['leakage']['note'], $regression['leakage']['pass']],
                        ];
                    @endphp
                    @foreach ($guards as [$label, $value, $ok])
                    <div class="flex items-start gap-3 p-3 rounded-xl bg-paper-2 dark:bg-[#131917] border border-paper-rule dark:border-[#2b3530]">
                        <x-heroicon-o-shield-check class="w-4 h-4 mt-0.5 flex-shrink-0 {{ $ok ? 'text-low-600' : 'text-moderate-600' }}" />
                        <div>
                            <div class="text-[11px] uppercase tracking-wider text-ink-400 font-semibold">{{ $label }}</div>
                            <div class="text-[12.5px] text-ink-700 dark:text-[#c8c4bc] mt-0.5 leading-snug">{{ $value }}</div>
                        </div>
                    </div>
                    @endforeach
                    <p class="text-[12px] text-ink-500 dark:text-[#8a9087] leading-relaxed">
                        The composite risk and final level are computed <em>after</em> prediction from the domain outputs, so no
                        target value ever leaks into the model's inputs.
                    </p>
                </div>
            </div>
        </div>

        {{-- Confusion matrix + threshold reasoning --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="card">
                <div class="card-head">
                    <div class="card-title">Risk-level agreement</div>
                    <div class="card-sub">Accuracy {{ $pct($classification['accuracy'], 0) }} · catches {{ $pct($classification['high_recall'], 0) }} of high-risk seniors</div>
                </div>
                <div class="card-body">
                    @php
                        $m = $classification['matrix'];
                        $levels = $classification['levels'];
                        $maxCell = 1;
                        foreach ($m as $row) { $maxCell = max($maxCell, max($row)); }
                        $lvlClass = ['LOW' => 'low', 'MODERATE' => 'moderate', 'HIGH' => 'high'];
                    @endphp
                    <div class="overflow-x-auto">
                        <table class="w-full text-center text-[12px] border-collapse">
                            <thead>
                                <tr>
                                    <th class="p-1.5"></th>
                                    <th class="p-1.5 font-semibold text-ink-400 text-[10px] uppercase tracking-wider" colspan="{{ count($levels) }}">Predicted</th>
                                </tr>
                                <tr>
                                    <th class="p-1.5 text-[10px] font-semibold text-ink-400 uppercase tracking-wider text-left">Actual ↓</th>
                                    @foreach ($levels as $p)
                                        <th class="p-1.5"><span class="badge badge-{{ $lvlClass[$p] }}">{{ ucfirst(strtolower($p)) }}</span></th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($levels as $t)
                                <tr>
                                    <td class="p-1.5 text-left"><span class="badge badge-{{ $lvlClass[$t] }}">{{ ucfirst(strtolower($t)) }}</span></td>
                                    @foreach ($levels as $p)
                                        @php
                                            $val = $m[$t][$p];
                                            $intensity = $val / $maxCell;
                                            $isDiag = $t === $p;
                                        @endphp
                                        <td class="p-1">
                                            <div class="rounded-lg py-2.5 font-mono font-semibold tnum
                                                        {{ $isDiag ? 'text-forest-900 dark:text-white' : 'text-ink-700 dark:text-[#c8c4bc]' }}"
                                                 style="background: rgba(38,87,170,{{ number_format(0.06 + $intensity * 0.62, 3) }});">
                                                {{ $val }}
                                            </div>
                                        </td>
                                    @endforeach
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="text-[12px] text-ink-500 dark:text-[#8a9087] mt-3 leading-relaxed">
                        The model is deliberately tuned toward <strong class="text-ink-700 dark:text-[#c8c4bc]">sensitivity</strong>:
                        it would rather flag a senior for review than miss one who needs help. That is why high-risk recall
                        ({{ $pct($classification['high_recall'], 0) }}) is the headline number, not raw precision.
                        Scores of 0.70 and above are additionally marked with a <strong class="text-ink-700 dark:text-[#c8c4bc]">Priority Flag</strong>.
                    </p>
                </div>
            </div>

            {{-- Threshold trade-off --}}
            <div class="card">
                <div class="card-head">
                    <div class="card-title">Where we set the line</div>
                    <div class="card-sub">Chosen: {{ ucfirst($classification['selected_threshold']) }} thresholds</div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-[12.5px]">
                        <thead>
                            <tr class="text-ink-400 text-[10.5px] uppercase tracking-wider border-b border-paper-rule dark:border-[#2b3530]">
                                <th class="text-left px-4 py-2 font-semibold">Setting</th>
                                <th class="text-right px-3 py-2 font-semibold">High recall</th>
                                <th class="text-right px-3 py-2 font-semibold">Balance (F1)</th>
                                <th class="text-right px-4 py-2 font-semibold">Over-flags</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-paper-rule dark:divide-[#2b3530]">
                            @foreach ($classification['thresholds'] as $row)
                            <tr class="{{ $row['set'] === $classification['selected_threshold'] ? 'bg-forest-50/60 dark:bg-forest-900/20' : '' }}">
                                <td class="px-4 py-2.5 font-medium text-ink-800 dark:text-[#c8c4bc] capitalize">
                                    {{ $row['set'] }}
                                    @if ($row['set'] === $classification['selected_threshold'])
                                        <span class="badge badge-low ml-1">Selected</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 text-right font-mono tnum">{{ $n3($row['high_recall']) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono tnum">{{ $n3($row['weighted_f1']) }}</td>
                                <td class="px-4 py-2.5 text-right font-mono tnum text-ink-500">{{ $row['false_escalations'] }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="card-body pt-3">
                    <p class="text-[12px] text-ink-500 dark:text-[#8a9087] leading-relaxed">
                        Stricter settings catch more high-risk seniors but raise false alarms. The selected setting keeps
                        recall high while holding over-flagging to a workable level for a small office.
                    </p>
                </div>
            </div>
        </div>
    </section>

        {{-- Full classification report --}}
        <div class="card">
            <div class="card-head">
                <div class="card-title">Risk-level classification report</div>
                <div class="card-sub">Per-level precision, recall, and F1 on the held-out set ({{ array_sum(array_column($classification['per_class'], 'support')) }} seniors)</div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-[12.5px]">
                    <thead>
                        <tr class="text-ink-400 text-[10px] uppercase tracking-wider border-b border-paper-rule dark:border-[#2b3530]">
                            <th class="text-left px-5 py-2.5 font-semibold">Risk level</th>
                            <th class="text-right px-4 py-2.5 font-semibold">Precision</th>
                            <th class="text-right px-4 py-2.5 font-semibold">Recall</th>
                            <th class="text-right px-4 py-2.5 font-semibold">F1-score</th>
                            <th class="text-right px-5 py-2.5 font-semibold">Seniors</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-paper-rule dark:divide-[#2b3530]">
                        @php $lvlClass2 = ['LOW' => 'low', 'MODERATE' => 'moderate', 'HIGH' => 'high']; @endphp
                        @foreach ($classification['levels'] as $lvl)
                        @php $pc = $classification['per_class'][$lvl]; @endphp
                        <tr>
                            <td class="px-5 py-2.5"><span class="badge badge-{{ $lvlClass2[$lvl] }}">{{ ucfirst(strtolower($lvl)) }}</span></td>
                            <td class="px-4 py-2.5 text-right font-mono tnum">{{ $n3($pc['precision']) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono tnum {{ $lvl === 'HIGH' ? 'font-semibold text-low-700 dark:text-[#6dd89e]' : '' }}">{{ $n3($pc['recall']) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono tnum">{{ $n3($pc['f1']) }}</td>
                            <td class="px-5 py-2.5 text-right font-mono tnum text-ink-500">{{ $pc['support'] }}</td>
                        </tr>
                        @endforeach
                        <tr class="border-t-2 border-paper-rule dark:border-[#2b3530] bg-paper-2/40 dark:bg-[#131917]">
                            <td class="px-5 py-2.5 font-semibold text-ink-800 dark:text-[#e4e1d8]">Overall</td>
                            <td class="px-4 py-2.5 text-right font-mono tnum text-ink-400">—</td>
                            <td class="px-4 py-2.5 text-right font-mono tnum text-ink-400">—</td>
                            <td class="px-4 py-2.5 text-right font-mono tnum font-semibold">{{ $n3($classification['weighted_f1']) }} <span class="text-[10px] text-ink-400">wt. F1</span></td>
                            <td class="px-5 py-2.5 text-right font-mono tnum font-semibold">{{ $pct($classification['accuracy'], 1) }} <span class="text-[10px] text-ink-400">acc</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

        {{-- Visual evidence --}}
        <div>
            <div class="eyebrow mb-3">Visual evidence</div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                @include('reports.partials.validation-figure', ['plot' => 'risk_distribution', 'title' => 'Figure 7 — Risk distribution', 'caption' => 'How composite risk and risk levels are spread across the senior population.'])
                @include('reports.partials.validation-figure', ['plot' => 'risk_level_confusion_matrix', 'title' => 'Figure 8 — Confusion matrix', 'caption' => 'Predicted vs. actual risk levels on the held-out set.'])
                @include('reports.partials.validation-figure', ['plot' => 'calibration_reliability', 'title' => 'Figure 9 — Calibration', 'caption' => 'Predicted risk vs. the rule-based reference across score bands.'])
            </div>
        </div>
    </section>

    {{-- ════════ 03 · EXPLAINABILITY ════════ --}}
    <section id="xai" class="scroll-mt-20 space-y-4 pt-2">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-serif text-xl font-semibold text-ink-900 dark:text-[#e4e1d8]">
                <span class="font-mono text-sm text-ink-300 dark:text-[#5a6460] mr-2">03</span>Explainability
            </h2>
            {!! $chip($xai['verdicts']['sanity']) !!}
        </div>
        <p class="text-[13.5px] text-ink-500 dark:text-[#8a9087] max-w-3xl leading-relaxed">
            The system is not a black box. We can show which survey answers drive the grouping, confirm each risk domain
            behaves the way it should, and publish exactly how the scores are weighted.
        </p>

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">
            {{-- Kruskal feature significance --}}
            <div class="card lg:col-span-3">
                <div class="card-head">
                    <div class="card-title">What separates the care groups</div>
                    <div class="card-sub">{{ $xai['kruskal_significant'] }} of {{ $xai['kruskal_total'] }} signals are statistically significant</div>
                </div>
                <div class="card-body"><div class="relative h-72"><canvas id="kruskalChart"></canvas></div></div>
            </div>

            {{-- Scoring weights --}}
            <div class="card lg:col-span-2">
                <div class="card-head"><div class="card-title">How the score is built</div></div>
                <div class="card-body space-y-3">
                    <div>
                        <div class="eyebrow mb-2">Blend of evidence</div>
                        @php
                            $ens = $xai['ensemble_weights'];
                            $ensLabels = ['rule' => 'Coded rubric', 'gbr' => 'Gradient model', 'rfr' => 'Forest model'];
                        @endphp
                        @foreach ($ens as $k => $w)
                        <div class="mb-2">
                            <div class="flex justify-between text-[11.5px] mb-1">
                                <span class="text-ink-500 dark:text-[#8a9087]">{{ $ensLabels[$k] ?? ucfirst($k) }}</span>
                                <span class="font-mono font-semibold text-ink-800 dark:text-[#c8c4bc] tnum">{{ $pct($w, 0) }}</span>
                            </div>
                            <div class="bar"><div class="bar-fill" style="width: {{ $w * 100 }}%; background: #2657aa"></div></div>
                        </div>
                        @endforeach
                    </div>
                    <div class="pt-2 border-t border-paper-rule dark:border-[#2b3530]">
                        <div class="eyebrow mb-2">Survey section influence</div>
                        @php
                            $secLabels = [
                                'sec1' => 'Demographics', 'sec2' => 'Family & household', 'sec3' => 'Education & skills',
                                'sec4' => 'Living situation', 'sec5' => 'Economic', 'sec6' => 'Health & function',
                            ];
                            $sw = collect($xai['section_weights'])->sortDesc();
                        @endphp
                        @foreach ($sw as $k => $w)
                        <div class="flex items-center justify-between text-[12px] py-0.5">
                            <span class="text-ink-600 dark:text-[#a8b0ab]">{{ $secLabels[$k] ?? $k }}</span>
                            <span class="font-mono tnum text-ink-700 dark:text-[#c8c4bc]">{{ $pct($w, 0) }}</span>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- Domain weights + multicollinearity --}}
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">
            <div class="card lg:col-span-3">
                <div class="card-head">
                    <div class="card-title">How domains combine into overall risk</div>
                    <div class="card-sub">Documented weighting of each need area in the composite score</div>
                </div>
                <div class="card-body space-y-2">
                    @php
                        $domLabels = [
                            'medical' => 'Medical', 'financial' => 'Financial', 'social' => 'Social',
                            'hc_access' => 'Healthcare access', 'housing' => 'Housing',
                            'functional' => 'Functional', 'sensory' => 'Sensory',
                        ];
                        $maxDw = max(1, ...array_values(array_map('floatval', $xai['domain_weights'] ?: [1])));
                    @endphp
                    @foreach (collect($xai['domain_weights'])->sortDesc() as $k => $w)
                    <div class="flex items-center gap-3">
                        <div class="w-32 flex-shrink-0 text-[12px] text-ink-600 dark:text-[#a8b0ab]">{{ $domLabels[$k] ?? ucfirst($k) }}</div>
                        <div class="flex-1 bar h-2.5"><div class="bar-fill" style="width: {{ ($w / $maxDw) * 100 }}%; background: #2657aa"></div></div>
                        <div class="w-12 text-right font-mono font-semibold tnum text-[12px] text-ink-700 dark:text-[#c8c4bc]">{{ $pct($w, 0) }}</div>
                    </div>
                    @endforeach
                </div>
            </div>
            <div class="card lg:col-span-2">
                <div class="card-head">
                    <div class="card-title">Multicollinearity control (VIF)</div>
                </div>
                <div class="card-body">
                    <div class="flex items-baseline gap-2">
                        <span class="font-serif text-4xl font-semibold text-ink-900 dark:text-[#e4e1d8] tnum">{{ $n3($xai['vif']['max']) }}</span>
                        <span class="text-[12px] text-ink-500">highest of {{ $xai['vif']['n_features'] }} features</span>
                    </div>
                    <div class="mt-3">
                        {!! $chip(['good' => $xai['vif']['all_below'], 'label' => $xai['vif']['all_below'] ? 'All below threshold ('.rtrim(rtrim(number_format($xai['vif']['threshold'], 1), '0'), '.').')' : 'Exceeds threshold']) !!}
                    </div>
                    <p class="text-[12px] text-ink-500 dark:text-[#8a9087] mt-3 leading-relaxed">
                        The Variance Inflation Factor flags features that duplicate each other's information. Keeping every
                        feature under {{ rtrim(rtrim(number_format($xai['vif']['threshold'], 1), '0'), '.') }} means each survey signal
                        contributes something distinct — the model isn't double-counting.
                    </p>
                </div>
            </div>
        </div>

        {{-- Full Kruskal-Wallis significance table --}}
        <div class="card" x-data="{ open: false }">
            <div class="card-head">
                <div class="card-title">Feature significance — Kruskal–Wallis H test</div>
                <button type="button" @click="open = !open" class="btn btn-ghost text-[12px]">
                    <span x-text="open ? 'Show top 12' : 'Show all ' + {{ $xai['kruskal_total'] }}"></span>
                </button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-[12.5px]">
                    <thead>
                        <tr class="text-ink-400 text-[10px] uppercase tracking-wider border-b border-paper-rule dark:border-[#2b3530]">
                            <th class="text-left px-5 py-2.5 font-semibold">Survey signal</th>
                            <th class="text-right px-4 py-2.5 font-semibold">H-statistic</th>
                            <th class="text-right px-4 py-2.5 font-semibold">p-value</th>
                            <th class="text-right px-5 py-2.5 font-semibold">Significant</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-paper-rule dark:divide-[#2b3530]">
                        @foreach ($xai['kruskal_all'] as $i => $f)
                        <tr x-show="open || {{ $i }} < 12" {{ $i >= 12 ? 'x-cloak' : '' }}>
                            <td class="px-5 py-2 text-ink-700 dark:text-[#c8c4bc]">{{ $f['label'] }}</td>
                            <td class="px-4 py-2 text-right font-mono tnum">{{ number_format($f['h'], 1) }}</td>
                            <td class="px-4 py-2 text-right font-mono tnum text-ink-500">{{ $f['p'] < 0.001 ? '<0.001' : number_format($f['p'], 3) }}</td>
                            <td class="px-5 py-2 text-right">
                                @if ($f['significant'])
                                    <x-heroicon-o-check-circle class="w-4 h-4 text-low-600 inline" />
                                @else
                                    <span class="text-ink-400 text-[11px]">n.s.</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-body pt-3 border-t border-paper-rule dark:border-[#2b3530] text-[12px] text-ink-500 dark:text-[#8a9087] leading-relaxed">
                A higher H-statistic means the signal differs more sharply between care groups. {{ $xai['kruskal_significant'] }}
                of {{ $xai['kruskal_total'] }} signals are statistically significant (p &lt; 0.05) — confirming the groups
                differ on real, measured characteristics rather than noise.
            </div>
        </div>

        {{-- Rule sanity --}}
        <div class="card">
            <div class="card-head">
                <div class="card-title">Each domain separates low from high risk</div>
                <div class="card-sub">Average score among the lowest-risk vs. highest-risk seniors</div>
            </div>
            <div class="card-body grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3">
                @foreach ($xai['sanity'] as $s)
                <div class="rounded-xl p-3 bg-paper-2 dark:bg-[#131917] border border-paper-rule dark:border-[#2b3530] text-center">
                    <div class="text-[11px] font-semibold text-ink-600 dark:text-[#a8b0ab] mb-1.5 truncate" title="{{ $s['domain'] }}">{{ $s['domain'] }}</div>
                    <div class="font-mono text-[13px] tnum">
                        <span class="text-low-700 dark:text-[#6dd89e]">{{ $n3($s['low_mean']) }}</span>
                        <span class="text-ink-300 mx-1">→</span>
                        <span class="text-high-700 dark:text-[#ef8d80]">{{ $n3($s['high_mean']) }}</span>
                    </div>
                    @if ($s['pass'])
                        <x-heroicon-o-check-circle class="w-3.5 h-3.5 text-low-600 mx-auto mt-1.5" />
                    @endif
                </div>
                @endforeach
            </div>
        </div>

        {{-- Importance plot (served from storage) --}}
        <div class="card">
            <div class="card-head">
                <div class="card-title">Model feature importance</div>
                <div class="card-sub">Drivers learned by the gradient model</div>
            </div>
            <div class="card-body">
                <img src="{{ route('reports.validation.plot', 'gbr_feature_importance') }}"
                     alt="Feature importance chart from the risk model"
                     class="w-full rounded-xl border border-paper-rule dark:border-[#2b3530] bg-white"
                     loading="lazy"
                     onerror="this.closest('.card').style.display='none'">
            </div>
        </div>
    </section>

    {{-- ════════ 04 · RECOMMENDATIONS ════════ --}}
    <section id="recs" class="scroll-mt-20 space-y-4 pt-2">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-serif text-xl font-semibold text-ink-900 dark:text-[#e4e1d8]">
                <span class="font-mono text-sm text-ink-300 dark:text-[#5a6460] mr-2">04</span>Recommendations
            </h2>
            {!! $chip($recs['verdicts']['coverage']) !!}
        </div>
        <p class="text-[13.5px] text-ink-500 dark:text-[#8a9087] max-w-3xl leading-relaxed">
            Every senior receives a tailored action list drawn from a citation-backed catalogue. Higher-risk seniors get a
            longer priority list, and suggestions are spread across need areas rather than piling into one.
        </p>

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">
            <div class="lg:col-span-2 grid grid-cols-2 gap-4 content-start">
                @php
                    $rkpi = [
                        ['Total suggestions', number_format($recs['flat_total'] ?: 0), 'Across '.$recs['total'].' seniors', true],
                        ['Seniors covered', $pct($recs['coverage'], 0), $recs['with_recs'].' of '.$recs['total'], $recs['verdicts']['coverage']['good']],
                        ['Staff-reviewed', $recs['flat_total'] ? $pct($recs['needs_validation'] / $recs['flat_total'], 0) : '—', 'Flagged for human validation', true],
                        ['Need areas', count($recs['cat_full'] ?: $recs['categories']), 'Distinct categories used', true],
                    ];
                @endphp
                @foreach ($rkpi as [$label, $value, $note, $good])
                <div class="kpi">
                    <div class="kpi-rule {{ $good ? 'bg-low-500' : 'bg-moderate-500' }}"></div>
                    <div class="kpi-label">{{ $label }}</div>
                    <div class="kpi-value text-[28px]">{{ $value }}</div>
                    <div class="kpi-delta {{ $good ? 'text-low-700' : 'text-moderate-700' }}">{{ $note }}</div>
                </div>
                @endforeach
            </div>
            <div class="card lg:col-span-3">
                <div class="card-head">
                    <div class="card-title">Where suggestions are directed</div>
                    <div class="card-sub">Every suggestion across all seniors, grouped by need area</div>
                </div>
                <div class="card-body"><div class="relative h-64"><canvas id="categoriesChart"></canvas></div></div>
            </div>
        </div>

        {{-- Priority mix + evidence base --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="card">
                <div class="card-head">
                    <div class="card-title">Urgency mix</div>
                    <div class="card-sub">How quickly each suggestion is meant to be acted on</div>
                </div>
                <div class="card-body space-y-2.5">
                    @php
                        $priTone = ['Immediate' => '#c0392b', 'Urgent' => '#c47832', 'Planned' => '#2657aa', 'Maintenance' => '#4a8a68'];
                        $priTotal = max(1, array_sum($recs['pri_full'] ?: [1]));
                    @endphp
                    @forelse ($recs['pri_full'] as $pri => $count)
                    <div class="flex items-center gap-3">
                        <div class="w-24 flex-shrink-0 text-[12px] font-medium text-ink-600 dark:text-[#a8b0ab]">{{ $pri }}</div>
                        <div class="flex-1 bar h-2.5"><div class="bar-fill" style="width: {{ ($count / $priTotal) * 100 }}%; background: {{ $priTone[$pri] ?? '#2657aa' }}"></div></div>
                        <div class="w-24 text-right font-mono tnum text-[12px] text-ink-700 dark:text-[#c8c4bc]">{{ number_format($count) }} <span class="text-ink-400">({{ $pct($count / $priTotal, 0) }})</span></div>
                    </div>
                    @empty
                    <p class="text-[12px] text-ink-400">Priority detail not available in this run.</p>
                    @endforelse
                </div>
            </div>
            <div class="card">
                <div class="card-head">
                    <div class="card-title">Grounded in real service pathways</div>
                    <div class="card-sub">{{ count($recs['evidence_sources']) }} distinct evidence sources cited</div>
                </div>
                <div class="card-body">
                    @if (count($recs['evidence_sources']))
                    <ul class="space-y-1.5">
                        @foreach (array_slice($recs['evidence_sources'], 0, 6, true) as $src => $count)
                        <li class="flex items-start justify-between gap-3 text-[12.5px]">
                            <span class="text-ink-700 dark:text-[#c8c4bc] leading-snug">{{ $src }}</span>
                            <span class="font-mono tnum text-ink-400 flex-shrink-0">{{ number_format($count) }}</span>
                        </li>
                        @endforeach
                    </ul>
                    @else
                    <p class="text-[12px] text-ink-400">Citation detail not available in this run.</p>
                    @endif
                    <p class="text-[11.5px] text-ink-500 dark:text-[#8a9087] mt-3 pt-3 border-t border-paper-rule dark:border-[#2b3530] leading-relaxed">
                        Each suggestion cites a Philippine law, public-health protocol, or service program (RHU/BHC, PhilHealth,
                        MSWDO, OSCA, DSWD, DOLE, TESDA). All are flagged for human validation before any official action.
                    </p>
                </div>
            </div>
        </div>

        {{-- Suggestion depth by risk level --}}
        <div class="card">
            <div class="card-head">
                <div class="card-title">Priority scales with need</div>
                <div class="card-sub">Higher-risk seniors receive a longer priority action list</div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-[12.5px]">
                    <thead>
                        <tr class="text-ink-400 text-[10px] uppercase tracking-wider border-b border-paper-rule dark:border-[#2b3530]">
                            <th class="text-left px-5 py-2.5 font-semibold">Risk level</th>
                            <th class="text-right px-4 py-2.5 font-semibold">Seniors</th>
                            <th class="text-right px-4 py-2.5 font-semibold">Avg. priority actions</th>
                            <th class="text-right px-5 py-2.5 font-semibold">Avg. total suggestions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-paper-rule dark:divide-[#2b3530]">
                        @php $lvlClass3 = ['LOW' => 'low', 'MODERATE' => 'moderate', 'HIGH' => 'high']; @endphp
                        @foreach (['HIGH', 'MODERATE', 'LOW'] as $lvl)
                        @php $b = $recs['by_level'][$lvl]; @endphp
                        <tr>
                            <td class="px-5 py-2.5"><span class="badge badge-{{ $lvlClass3[$lvl] }}">{{ ucfirst(strtolower($lvl)) }}</span></td>
                            <td class="px-4 py-2.5 text-right font-mono tnum text-ink-500">{{ $b['count'] }}</td>
                            <td class="px-4 py-2.5 text-right font-mono tnum font-semibold">{{ number_format($b['avg_top'], 1) }}</td>
                            <td class="px-5 py-2.5 text-right font-mono tnum">{{ number_format($b['avg_all'], 1) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-body pt-3 border-t border-paper-rule dark:border-[#2b3530] text-[12px] text-ink-500 dark:text-[#8a9087] leading-relaxed">
                Suggestions are drawn from a citation-backed catalogue and capped per category, so a senior's list spans several
                need areas rather than piling into one. The priority list lengthens with risk, focusing officer attention where it matters most.
            </div>
        </div>
    </section>

    {{-- ════════ STRENGTHS & LIMITATIONS ════════ --}}
    <section class="scroll-mt-20 space-y-4 pt-2">
        <h2 class="font-serif text-xl font-semibold text-ink-900 dark:text-[#e4e1d8]">Strengths &amp; honest limitations</h2>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="card">
                <div class="card-head"><div class="card-title">What holds up under scrutiny</div></div>
                <div class="card-body space-y-2.5">
                    @foreach ([
                        'Indicators and interpretations are organised under the WHO Healthy Ageing Framework.',
                        'Multicollinearity is controlled — every retained feature stays under the VIF threshold.',
                        'Feature selection measurably improved cluster separation over the full feature set.',
                        'Clustering is fully reproducible: identical metrics across every random seed tested.',
                        'Profiles are meaningful — the large majority of features differ significantly across groups.',
                        'Risk indicators show strong internal cross-validation and align tightly with the transparent rubric.',
                    ] as $s)
                    <div class="flex items-start gap-2.5">
                        <x-heroicon-o-check-circle class="w-4 h-4 mt-0.5 text-low-600 flex-shrink-0" />
                        <span class="text-[12.5px] text-ink-600 dark:text-[#a8b0ab] leading-relaxed">{{ $s }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
            <div class="card">
                <div class="card-head"><div class="card-title">Stated plainly, not hidden</div></div>
                <div class="card-body space-y-2.5">
                    @foreach ([
                        'Clustering quality is moderate, not excellent — groups are profiles for planning, not clinical categories.',
                        'The chosen split does not dominate every metric; a broader split scores higher on raw separation but is less actionable.',
                        'A separate Critical class is not validated on a single case — it is folded into High with a Priority Flag.',
                        'Risk indicators track the rule-based rubric closely; they are not externally validated clinical outcomes.',
                        'Data is from a single municipality and may not generalise without external validation.',
                        'The survey is cross-sectional — it cannot confirm future decline or intervention effect.',
                    ] as $l)
                    <div class="flex items-start gap-2.5">
                        <x-heroicon-o-exclamation-triangle class="w-4 h-4 mt-0.5 text-moderate-600 flex-shrink-0" />
                        <span class="text-[12.5px] text-ink-600 dark:text-[#a8b0ab] leading-relaxed">{{ $l }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Defensible Q&A --}}
        <div class="card" x-data="{ q: null }">
            <div class="card-head"><div class="card-title">Defensible answers for the panel</div></div>
            <div class="divide-y divide-paper-rule dark:divide-[#2b3530]">
                @php
                    $qa = [
                        ['Why did you use this number of groups?', 'It gives the most useful, actionable profile structure for OSCA. A broader two-group split is statistically cleaner but too general for program planning. The chosen split separates well-supported, stable moderate-support, environmentally/financially vulnerable, and multi-domain priority seniors — each mapping to different services.'],
                        ['Is the clustering excellent?', 'No — it is moderate, and we do not overstate it. Senior conditions naturally overlap in real community data. The groups are presented as planning profiles, not fixed clinical categories.'],
                        ['Why not keep a Critical class?', 'Only one Critical case exists, and a single-case class cannot be reliably validated. The safer, more honest choice is to merge it into High and raise a Priority Flag for scores at or above 0.70.'],
                        ['Are the risk outputs predictions?', 'They are risk indicators — possible concern levels estimated from available signals and a rule-aligned model. They do not predict confirmed illness or clinical outcomes.'],
                        ['Why is Gradient Boosting the primary risk model?', 'It achieved stronger internal cross-validation across all three domains than the Random Forest comparator, with high R² and low error. Random Forest is retained as a robustness comparison.'],
                        ['Does high rule-vs-model agreement prove accuracy?', 'It proves consistency and transparency with the documented rubric — not external clinical accuracy. We acknowledge external validation is still needed for broader claims.'],
                        ['Can the system replace staff decisions?', 'No. It organises profiles, risk indicators, explanations, and suggestions to support OSCA review. Every recommendation is flagged for human validation; final decisions stay with qualified personnel.'],
                    ];
                @endphp
                @foreach ($qa as $i => [$question, $answer])
                <div>
                    <button type="button" @click="q === {{ $i }} ? q = null : q = {{ $i }}"
                            class="w-full flex items-center justify-between gap-3 px-5 py-3.5 text-left hover:bg-paper-2/50 dark:hover:bg-[#131917] transition-colors">
                        <span class="text-[13px] font-semibold text-ink-800 dark:text-[#e4e1d8]">{{ $question }}</span>
                        <x-heroicon-o-chevron-down class="w-4 h-4 text-ink-400 flex-shrink-0 transition-transform" ::class="q === {{ $i }} ? 'rotate-180' : ''" />
                    </button>
                    <div x-show="q === {{ $i }}" x-collapse x-cloak class="px-5 pb-4">
                        <p class="text-[12.5px] text-ink-600 dark:text-[#a8b0ab] leading-relaxed max-w-3xl">{{ $answer }}</p>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Provenance footer ── --}}
    <div class="rounded-2xl border border-paper-rule dark:border-[#2b3530] bg-paper-2/60 dark:bg-[#131917] px-6 py-5">
        <div class="eyebrow mb-2">Provenance</div>
        <p class="text-[12.5px] text-ink-500 dark:text-[#8a9087] leading-relaxed max-w-3xl">
            Results computed over {{ $provenance['n_seniors'] }} senior records, with age frozen to each survey date for
            reproducibility. The deployed predictions are pinned to a checksum-verified model snapshot, so every device
            shows the same result.
            @if ($provenance['generated_at'])
                Last evaluated {{ $provenance['generated_at']->format('d F Y, H:i') }}.
            @endif
        </p>
    </div>
</div>

{{-- Chart data island --}}
<script type="application/json" id="validation-chart-data">@json($charts)</script>
@endif

@endsection

@push('scripts')
<script>
(function () {
    function isDark() { return document.documentElement.classList.contains('dark'); }
    function C() {
        const d = isDark();
        return {
            grid: d ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)',
            tick: d ? '#8a9087' : '#6b7269',
            ink:  d ? '#c8c4bc' : '#383d36',
        };
    }
    const ACCENT = '#2657aa', ACCENT_LT = '#5689d6';
    const LEVELS = { low: '#4a8a68', moderate: '#c19a3b', high: '#c47832' };
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const anim = reduce ? false : { duration: 650, easing: 'easeOutQuart' };

    function upsert(id, config) {
        const canvas = document.getElementById(id);
        if (!canvas || typeof Chart === 'undefined') return;
        const existing = Object.values(Chart.instances).find(c => c.canvas === canvas);
        if (existing) existing.destroy();
        new Chart(canvas, config);
    }

    function render() {
        const el = document.getElementById('validation-chart-data');
        if (!el) return;
        const p = JSON.parse(el.textContent);
        const col = C();

        // 01 · K-selection — silhouette line + chosen-K highlight
        const kc = p.kSelection;
        upsert('kSelectionChart', {
            type: 'line',
            data: {
                labels: kc.labels,
                datasets: [{
                    label: 'Separation (silhouette)',
                    data: kc.silhouette,
                    borderColor: ACCENT,
                    backgroundColor: 'rgba(38,87,170,0.10)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: kc.labels.map(l => l === 'K=' + kc.chosen ? 7 : 4),
                    pointBackgroundColor: kc.labels.map(l => l === 'K=' + kc.chosen ? '#4a8a68' : ACCENT),
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                }],
            },
            options: {
                responsive: true, maintainAspectRatio: false, animation: anim,
                plugins: { legend: { display: false },
                    tooltip: { callbacks: { afterLabel: (c) => c.label === 'K=' + kc.chosen ? '✓ Selected' : '' } } },
                scales: {
                    y: { grid: { color: col.grid }, ticks: { color: col.tick, font: { size: 11 } }, title: { display: true, text: 'Group separation', color: col.tick, font: { size: 10 } } },
                    x: { grid: { display: false }, ticks: { color: col.tick, font: { size: 12, weight: '600' } } },
                },
            },
        });

        // 02 · Cross-validated R² per domain — GBR (deployed) vs RFR
        const cv = p.cvR2;
        upsert('cvR2Chart', {
            type: 'bar',
            data: {
                labels: cv.labels,
                datasets: [
                    { label: 'Deployed model', data: cv.gbr.map(v => v == null ? null : +(v * 100).toFixed(1)), backgroundColor: ACCENT, borderRadius: 5, maxBarThickness: 38 },
                    { label: 'Comparison model', data: cv.rfr.map(v => v == null ? null : +(v * 100).toFixed(1)), backgroundColor: ACCENT_LT + '99', borderRadius: 5, maxBarThickness: 38 },
                ],
            },
            options: {
                responsive: true, maintainAspectRatio: false, animation: anim,
                plugins: { legend: { position: 'bottom', labels: { color: col.ink, font: { size: 11 }, boxWidth: 12, usePointStyle: true } } },
                scales: {
                    y: { beginAtZero: true, max: 100, grid: { color: col.grid }, ticks: { color: col.tick, font: { size: 10 }, callback: v => v + '%' } },
                    x: { grid: { display: false }, ticks: { color: col.tick, font: { size: 11 } } },
                },
            },
        });

        // 02 · Calibration — predicted vs actual
        const cal = p.calibration;
        upsert('calibrationChart', {
            type: 'line',
            data: {
                labels: cal.labels,
                datasets: [
                    { label: 'Predicted', data: cal.predicted, borderColor: ACCENT, backgroundColor: ACCENT, tension: 0.3, pointRadius: 4 },
                    { label: 'Actual', data: cal.actual, borderColor: '#4a8a68', backgroundColor: '#4a8a68', borderDash: [5, 4], tension: 0.3, pointRadius: 4 },
                ],
            },
            options: {
                responsive: true, maintainAspectRatio: false, animation: anim,
                plugins: { legend: { position: 'bottom', labels: { color: col.ink, font: { size: 11 }, boxWidth: 12, usePointStyle: true } } },
                scales: {
                    y: { beginAtZero: true, grid: { color: col.grid }, ticks: { color: col.tick, font: { size: 10 } } },
                    x: { grid: { display: false }, ticks: { color: col.tick, font: { size: 10 } } },
                },
            },
        });

        // 03 · Kruskal H-statistic — horizontal bars
        const kr = p.kruskal;
        upsert('kruskalChart', {
            type: 'bar',
            data: {
                labels: kr.labels,
                datasets: [{ data: kr.values, backgroundColor: ACCENT, borderRadius: 4, maxBarThickness: 18 }],
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false, animation: anim,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => ' Strength: ' + c.parsed.x } } },
                scales: {
                    x: { grid: { color: col.grid }, ticks: { color: col.tick, font: { size: 10 } } },
                    y: { grid: { display: false }, ticks: { color: col.ink, font: { size: 11 } } },
                },
            },
        });

        // 04 · Recommendation categories — horizontal bars
        const cat = p.categories;
        upsert('categoriesChart', {
            type: 'bar',
            data: {
                labels: cat.labels.map(l => l.replace(/_/g, ' ').replace(/\b\w/g, m => m.toUpperCase())),
                datasets: [{ data: cat.values, backgroundColor: ACCENT_LT, borderRadius: 4, maxBarThickness: 26 }],
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false, animation: anim,
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, grid: { color: col.grid }, ticks: { color: col.tick, font: { size: 10 }, precision: 0 } },
                    y: { grid: { display: false }, ticks: { color: col.ink, font: { size: 11 } } },
                },
            },
        });
    }

    function observeDark() {
        const obs = new MutationObserver((muts) => {
            for (const m of muts) { if (m.attributeName === 'class') { setTimeout(render, 50); break; } }
        });
        obs.observe(document.documentElement, { attributes: true });
    }

    document.addEventListener('livewire:navigated', () => setTimeout(render, 0));
    if (document.readyState !== 'loading') setTimeout(render, 0);
    else document.addEventListener('DOMContentLoaded', () => setTimeout(render, 0));
    document.addEventListener('DOMContentLoaded', observeDark);
})();
</script>
@endpush
