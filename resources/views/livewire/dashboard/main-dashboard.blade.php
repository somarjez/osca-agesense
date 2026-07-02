<div class="space-y-7" wire:poll.60s>

    <x-page-header title="Dashboard" subtitle="OSCA senior citizen overview · Pagsanjan, Laguna" />

    {{-- ── KPIs ── --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 rise-in">
        <x-kpi label="Total Seniors"   :value="number_format($stats['total'])"    accent="forest"   sub="Active records · all barangays" />
        <x-kpi label="QoL Surveyed"    :value="number_format($stats['surveyed'])" accent="info"     sub="With WHO IC/ENV/FA data" />

        {{-- High Risk card merged with Urgent Priority --}}
        <div class="kpi kpi-high relative overflow-hidden">
            <div class="kpi-rule bg-high-500"></div>
            <div class="kpi-label">High Risk</div>
            <div class="flex items-baseline gap-2">
                <div class="kpi-value text-high-700">{{ number_format($stats['highRisk']) }}</div>
                @if ($stats['urgent'] > 0)
                    <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-high-700 bg-high-50 border border-high-200 px-1.5 py-0.5 rounded">
                        <span class="w-1.5 h-1.5 rounded-full bg-high-500 animate-pulse flex-shrink-0"></span>
                        {{ $stats['urgent'] }} urgent
                    </span>
                @endif
            </div>
            <div class="kpi-delta text-high-600">
                Need priority action
                @if ($stats['urgent'] > 0)
                    &middot; <span class="font-semibold">{{ $stats['urgent'] }}</span> score &ge; 0.70
                @endif
            </div>
        </div>

        <x-kpi label="Pending Actions" :value="number_format($stats['pendingRecs'])" accent="moderate" sub="Recommendations open" />
    </div>

    {{-- ── Filter bar ── --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="eyebrow">Filter</div>

        <select wire:model.live="selectedBarangay" class="form-select max-w-[180px] py-1.5 text-[13px]">
            <option value="">All Barangays</option>
            @foreach (\App\Models\SeniorCitizen::barangayList() as $brgy)
                <option value="{{ $brgy }}">{{ $brgy }}</option>
            @endforeach
        </select>

        <select wire:model.live="selectedRisk" class="form-select max-w-[160px] py-1.5 text-[13px]">
            <option value="">All Risk Levels</option>
            <option value="high">High</option>
            <option value="moderate">Moderate</option>
            <option value="low">Low</option>
        </select>

        @if ($selectedBarangay || $selectedRisk)
            <button wire:click="clearFilters" class="text-[12px] text-ink-500 hover:text-ink-900 underline underline-offset-2">
                Clear filters
            </button>
        @endif

        <div class="ml-auto flex items-center gap-3 text-[11.5px] text-ink-500">
            <span class="hidden md:inline text-ink-400">Tip: click a risk slice to filter</span>
            <a href="{{ route('ml.status') }}" class="inline-flex items-center gap-1.5 hover:text-ink-900 transition-colors">
                <span class="eyebrow">Analysis Services</span>
                <x-heroicon-o-arrow-top-right-on-square class="w-3 h-3 opacity-60" />
            </a>
        </div>
    </div>

    {{-- Active filter context banner --}}
    @if ($selectedBarangay || $selectedRisk)
        <div class="flex items-center gap-2 text-[12px] text-info-700 dark:text-info-500 bg-info-100/50 dark:bg-info-500/10 border border-info-100 dark:border-info-500/20 rounded-xl px-4 py-2.5">
            <x-heroicon-o-funnel class="w-3.5 h-3.5 text-info-500 flex-shrink-0" />
            <span>
                Showing data for
                @if ($selectedBarangay)
                    <strong>{{ $selectedBarangay }}</strong>
                @endif
                @if ($selectedBarangay && $selectedRisk)
                    &middot;
                @endif
                @if ($selectedRisk)
                    <strong>{{ ucfirst($selectedRisk) }} Risk</strong> only
                @endif
            </span>
        </div>
    @endif

    {{-- ── Collage: masonry of analytics + priority cards (packs with no gaps) ── --}}
    @php
        $riskTotal    = max(1, array_sum($riskDistribution['data'] ?? []));
        $clusterTotal = max(1, array_sum($clusterDistribution['data'] ?? []));
        $brgyMax      = max(1, max(array_map(fn($r) => $r['total'] ?? 0, $barangayBreakdown ?: [['total' => 1]])));
    @endphp
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5 items-stretch rise-in rise-1">

        {{-- Risk Distribution --}}
        <x-card title="Risk Distribution" sub="Composite risk strata · click to filter" class="card-lift">
            <div wire:ignore class="relative h-56"><canvas id="riskChart" aria-label="Risk distribution: high, moderate, and low risk senior counts" role="img"></canvas></div>
            <div class="sr-only">
                <table>
                    <caption>Risk distribution data</caption>
                    <thead><tr><th scope="col">Risk Level</th><th scope="col">Count</th></tr></thead>
                    <tbody>
                        <tr><td>High Risk</td><td>{{ $riskDistribution['data'][0] ?? 0 }}</td></tr>
                        <tr><td>Moderate Risk</td><td>{{ $riskDistribution['data'][1] ?? 0 }}</td></tr>
                        <tr><td>Low Risk</td><td>{{ $riskDistribution['data'][2] ?? 0 }}</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="mt-4 space-y-1.5">
                @foreach ($riskDistribution['labels'] as $i => $label)
                    @php $val = $riskDistribution['data'][$i] ?? 0; $pct = round($val / $riskTotal * 100); @endphp
                    <div class="flex items-center gap-2 text-[11.5px] text-ink-700 dark:text-[#b0b5b2]">
                        <span class="w-2 h-2 rounded-sm flex-shrink-0" style="background: {{ $riskDistribution['colors'][$i] }}"></span>
                        <span>{{ $label }}</span>
                        <span class="ml-auto font-mono font-semibold tnum">{{ $val }}</span>
                        <span class="text-ink-400 dark:text-[#6b7570] tnum w-9 text-right">{{ $pct }}%</span>
                    </div>
                @endforeach
            </div>

            {{-- Headline stat --}}
            @php
                $highCount = $riskDistribution['data'][0] ?? 0;
                $modCount  = $riskDistribution['data'][1] ?? 0;
                $actionPct = round(($highCount + $modCount) / $riskTotal * 100);
            @endphp
            <div class="mt-4 pt-4 border-t border-paper-rule dark:border-[#2b3530]">
                <div class="text-center">
                    <span class="font-serif text-2xl font-semibold text-ink-900 dark:text-[#e4e1d8] tnum">{{ $actionPct }}%</span>
                    <span class="text-[12px] text-ink-500 dark:text-[#8a9087] ml-1">need monitoring or action</span>
                    <div class="text-[10.5px] text-ink-400 dark:text-[#6b7570] mt-0.5 tnum">
                        {{ $modCount }} moderate &middot; {{ $highCount }} high-risk
                    </div>
                </div>
            </div>
        </x-card>

        {{-- Profile Groups — ranked bars by size --}}
        @php
            $pgEntries = collect(array_keys($clusterDistribution['data'] ?? []))->map(fn ($i) => [
                'id'    => $clusterDistribution['ids'][$i]    ?? ($i + 1),
                'label' => $clusterDistribution['labels'][$i] ?? 'Group ' . ($i + 1),
                'count' => $clusterDistribution['data'][$i]   ?? 0,
                'color' => $clusterDistribution['colors'][$i] ?? '#94a3b8',
            ])->sortByDesc('count')->values();
            $pgMax   = max(1, $pgEntries->max('count'));
            $pgTotal = max(1, $pgEntries->sum('count'));
        @endphp
        <x-card title="Profile Groups" sub="{{ count($clusterDistribution['ids'] ?? []) }} groups · ranked by size" class="card-lift">
            <div class="space-y-3">
                @forelse ($pgEntries as $grp)
                @php $barPct = round($grp['count'] / $pgMax * 100); @endphp
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="w-2.5 h-2.5 rounded-sm flex-shrink-0" style="background: {{ $grp['color'] }}"></span>
                        <span class="text-[11.5px] font-semibold text-ink-800 dark:text-[#c8c4bc] truncate flex-1 min-w-0"
                              title="{{ $grp['label'] }}">G{{ $grp['id'] }} · {{ $grp['label'] }}</span>
                        <span class="text-[11.5px] font-mono font-semibold text-ink-900 dark:text-[#e4e1d8] tnum flex-shrink-0">{{ $grp['count'] }}</span>
                        <span class="text-[11px] text-ink-400 dark:text-[#6b7570] tnum w-9 text-right flex-shrink-0">{{ round($grp['count'] / $pgTotal * 100) }}%</span>
                    </div>
                    <div class="h-2.5 rounded-full bg-paper-2 dark:bg-[#202a26] overflow-hidden">
                        <div class="h-2.5 rounded-full" style="width: {{ $barPct }}%; background: {{ $grp['color'] }}"></div>
                    </div>
                </div>
                @empty
                <p class="text-[12.5px] text-ink-400 dark:text-[#6b7570] text-center py-4">No profile group data available.</p>
                @endforelse
            </div>
        </x-card>

        {{-- Domain Scores — radar + score legend --}}
        <x-card title="Domain Scores" sub="Average WHO-domain score across filtered seniors" class="card-lift" :fill="true">
            {{-- Radar chart grows to fill available card space --}}
            <div wire:ignore class="relative flex-1 min-h-0" style="min-height: 13rem"><canvas id="domainChart" aria-label="Average score per WHO health domain" role="img"></canvas></div>
            <div class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 shrink-0">
                @foreach ($domainScoreChart['labels'] as $di => $dlabel)
                @php $dscore = $domainScoreChart['data'][$di] ?? 0; @endphp
                <div class="flex items-center gap-2">
                    <span class="text-[11px] text-ink-500 dark:text-[#8a9087] truncate flex-1">{{ $dlabel }}</span>
                    <span class="text-[11px] font-mono font-semibold tnum text-ink-800 dark:text-[#c8c4bc] flex-shrink-0">{{ number_format($dscore, 1) }}</span>
                </div>
                @endforeach
            </div>
        </x-card>

        {{-- Urgent Pending Actions — first 5, not collapsible --}}
        <div class="card card-lift">
            <div class="card-head">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="w-7 h-7 rounded-lg grid place-items-center bg-high-50 text-high-600 flex-shrink-0">
                        <x-heroicon-o-exclamation-triangle class="w-4 h-4" />
                    </span>
                    <div class="min-w-0">
                        <div class="card-title">Urgent Pending Actions</div>
                        <div class="card-sub">Top 5 immediate-priority</div>
                    </div>
                </div>
                <span class="badge badge-neutral tnum flex-shrink-0">{{ $pendingRecs->count() }}</span>
            </div>
            <div class="divide-y divide-paper-rule">
                @forelse ($pendingRecs->take(5) as $rec)
                    <a href="{{ route('seniors.show', $rec->seniorCitizen->id) }}" class="px-4 py-3 flex items-start gap-3 hover:bg-forest-50/40 dark:hover:bg-forest-900/10 transition-colors">
                        <span class="mt-0.5 w-6 h-6 flex-shrink-0 rounded-md grid place-items-center text-[11px] font-bold tnum bg-high-100 text-high-700">
                            {{ $rec->priority }}
                        </span>
                        <div class="flex-1 min-w-0">
                            <p class="text-[13px] font-semibold text-ink-900 truncate">{{ $rec->seniorCitizen->full_name }}</p>
                            <p class="text-[12px] text-ink-500 mt-0.5 line-clamp-1">{{ $rec->action }}</p>
                        </div>
                        <span class="badge {{ $rec->urgency === 'immediate' ? 'badge-critical' : 'badge-high' }} flex-shrink-0 capitalize">{{ $rec->urgency }}</span>
                        <x-heroicon-o-chevron-right class="w-4 h-4 text-ink-400 flex-shrink-0 self-center" />
                    </a>
                @empty
                    <div class="px-4 py-6">
                        <x-empty-state icon="chart" title="No urgent actions" description="Immediate-priority recommendations will appear here as assessments run." />
                    </div>
                @endforelse
            </div>
            @if ($pendingRecs->count() > 0)
                <div class="px-4 py-2.5 border-t border-paper-rule">
                    <a href="{{ route('recommendations.index') }}" class="text-xs text-forest-700 hover:text-forest-900 font-semibold inline-flex items-center gap-1">
                        View all recommendations <x-heroicon-o-arrow-right class="w-3 h-3" />
                    </a>
                </div>
            @endif
        </div>

        {{-- Recent Senior Records — first 5, not collapsible --}}
        <div class="card card-lift">
            <div class="card-head">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="w-7 h-7 rounded-lg grid place-items-center bg-forest-50 text-forest-700 flex-shrink-0">
                        <x-heroicon-o-user-group class="w-4 h-4" />
                    </span>
                    <div class="min-w-0">
                        <div class="card-title">Recent Senior Records</div>
                        <div class="card-sub">Latest 5 registered</div>
                    </div>
                </div>
                <span class="badge badge-neutral tnum flex-shrink-0">{{ $recentSeniors->count() }}</span>
            </div>
            <div class="divide-y divide-paper-rule">
                @forelse ($recentSeniors->take(5) as $senior)
                    @php $ml = $senior->latestMlResult; @endphp
                    <a href="{{ route('seniors.show', $senior->id) }}" class="px-4 py-3 flex items-center gap-3 hover:bg-forest-50/40 dark:hover:bg-forest-900/10 transition-colors">
                        <div class="w-8 h-8 rounded-full bg-forest-100 grid place-items-center flex-shrink-0">
                            <span class="text-[11px] font-semibold text-forest-800">{{ strtoupper(substr($senior->first_name, 0, 1) . substr($senior->last_name, 0, 1)) }}</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-ink-900 truncate">{{ $senior->full_name }}</p>
                            <p class="text-[11.5px] text-ink-500">{{ $senior->barangay }} &middot; Age {{ $senior->age }}</p>
                        </div>
                        @if ($ml)
                            <x-risk-badge :level="$ml->overall_risk_level" :priority="$ml->priority_flag" />
                        @else
                            <span class="badge badge-neutral">Not yet assessed</span>
                        @endif
                    </a>
                @empty
                    <div class="px-4 py-6">
                        <x-empty-state icon="users" title="No seniors registered" description="Add senior citizens to begin tracking." />
                    </div>
                @endforelse
            </div>
            @if ($recentSeniors->count() > 0)
                <div class="px-4 py-2.5 border-t border-paper-rule">
                    <a href="{{ route('seniors.index') }}" class="text-xs text-forest-700 hover:text-forest-900 font-semibold inline-flex items-center gap-1">
                        View all senior records <x-heroicon-o-arrow-right class="w-3 h-3" />
                    </a>
                </div>
            @endif
        </div>

        {{-- Wellbeing Index — gauge (fills space in the collage) --}}
        <x-card class="card-lift">
            <div class="text-center">
                <div class="card-title">Wellbeing Index</div>
                <div class="card-sub mt-0.5">Average wellbeing of assessed seniors — higher is better</div>
                <div wire:ignore class="relative w-full max-w-[240px] mx-auto h-[150px] mt-3">
                    <canvas id="wellbeingGauge" aria-label="Average wellbeing index, 0 to 100" role="img"></canvas>
                </div>
                <div class="mt-3 pt-3 border-t border-paper-rule dark:border-[#2b3530] space-y-1.5 text-left">
                    <div class="flex items-center gap-2 text-[11.5px] text-ink-700 dark:text-[#b0b5b2]">
                        <span class="w-2 h-2 rounded-full bg-low-500 flex-shrink-0"></span>
                        <span>Good (70–100)</span>
                        <span class="ml-auto font-mono font-semibold tnum">{{ $stats['wellbeingBands']['good'] ?? 0 }}</span>
                    </div>
                    <div class="flex items-center gap-2 text-[11.5px] text-ink-700 dark:text-[#b0b5b2]">
                        <span class="w-2 h-2 rounded-full bg-moderate-500 flex-shrink-0"></span>
                        <span>Fair (50–69)</span>
                        <span class="ml-auto font-mono font-semibold tnum">{{ $stats['wellbeingBands']['fair'] ?? 0 }}</span>
                    </div>
                    <div class="flex items-center gap-2 text-[11.5px] text-ink-700 dark:text-[#b0b5b2]">
                        <span class="w-2 h-2 rounded-full bg-high-500 flex-shrink-0"></span>
                        <span>Needs attention (below 50)</span>
                        <span class="ml-auto font-mono font-semibold tnum">{{ $stats['wellbeingBands']['low'] ?? 0 }}</span>
                    </div>
                </div>
            </div>
        </x-card>

        {{-- Age Group Distribution --}}
        <x-card title="Age Group Distribution" sub="Senior count by age band" class="card-lift">
            <div wire:ignore class="relative h-60"><canvas id="ageChart" aria-label="Age distribution: senior counts grouped by age bands" role="img"></canvas></div>
        </x-card>

        {{-- Barangay Breakdown — proportional data-bar list (wide base of the bento) --}}
        <x-card title="Barangay Breakdown" sub="Total seniors · high-risk share, by barangay" class="card-lift xl:col-span-2">
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-x-8 gap-y-2.5">
                @foreach ($barangayBreakdown as $row)
                    @php
                        $totalPct = round(($row['total'] ?? 0) / $brgyMax * 100);
                        $highPct  = round(($row['high'] ?? 0) / $brgyMax * 100);
                    @endphp
                    <div class="flex items-center gap-3">
                        <div class="w-20 flex-shrink-0 truncate text-[12px] text-ink-700 dark:text-[#b0b5b2]" title="{{ $row['barangay'] }}">{{ $row['barangay'] }}</div>
                        <div class="flex-1 relative h-2.5 rounded-full bg-paper-2 dark:bg-[#202a26] overflow-hidden">
                            <div class="absolute inset-y-0 left-0 rounded-full bg-accent-300 dark:bg-accent-700" style="width: {{ max($totalPct, 3) }}%"></div>
                            @if (($row['high'] ?? 0) > 0)
                                <div class="absolute inset-y-0 left-0 rounded-full bg-high-500" style="width: {{ max($highPct, 2) }}%"></div>
                            @endif
                        </div>
                        <div class="w-7 flex-shrink-0 text-right font-mono tnum text-[12px] text-ink-900 dark:text-[#e4e1d8]">{{ $row['total'] }}</div>
                        @if (($row['high'] ?? 0) > 0)
                            <span class="w-6 flex-shrink-0 text-right text-[11px] font-semibold text-high-600 tnum">{{ $row['high'] }}</span>
                        @else
                            <span class="w-6 flex-shrink-0 text-right text-ink-300">—</span>
                        @endif
                    </div>
                @endforeach
            </div>
            <div class="mt-3 pt-3 border-t border-paper-rule dark:border-[#2b3530] flex items-center gap-4 text-[10.5px] text-ink-400">
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-accent-300"></span>Total seniors</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-high-500"></span>High risk</span>
            </div>
        </x-card>

    </div>

    @php $__chartJson = json_encode(['risk' => $riskDistribution, 'domain' => $domainScoreChart, 'age' => $ageGroupChart, 'wellbeing' => $stats['wellbeingIndex'] ?? null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); @endphp
    <script type="application/json" id="dashboard-chart-data">{!! $__chartJson !!}</script>

</div>
