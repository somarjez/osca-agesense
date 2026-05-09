<div class="space-y-7" wire:poll.60s>

    {{-- ── KPIs ── --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
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
            <span class="eyebrow">Analysis Services</span>
            @foreach ($mlHealth as $service => $status)
                @if ($service === 'mode')
                    @continue
                @endif
                <span class="inline-flex items-center gap-1.5">
                    <span class="status-dot {{ $status === 'ok' ? 'status-dot-ok' : 'status-dot-err' }}"></span>
                    <span class="text-ink-700 font-medium">{{ ucfirst($service) }}</span>
                    <span class="text-ink-400">{{ $status }}</span>
                </span>
            @endforeach
        </div>
    </div>

    {{-- Active filter context banner --}}
    @if ($selectedBarangay || $selectedRisk)
        <div class="flex items-center gap-2 text-[12px] text-ink-600 bg-info-50 border border-info-200 rounded-lg px-4 py-2.5">
            <x-heroicon-o-funnel class="w-3.5 h-3.5 text-info-600 flex-shrink-0" />
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

    {{-- ── Charts row ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        <x-card title="Risk Distribution" sub="Composite risk strata">
            <div wire:ignore class="relative h-44"><canvas id="riskChart"></canvas></div>
            <div class="mt-4 grid grid-cols-2 gap-1.5">
                @foreach ($riskDistribution['labels'] as $i => $label)
                    <div class="flex items-center gap-2 text-[11.5px] text-ink-700">
                        <span class="w-2 h-2 rounded-sm flex-shrink-0" style="background: {{ $riskDistribution['colors'][$i] }}"></span>
                        <span>{{ $label }}</span>
                        <span class="ml-auto font-mono font-semibold tnum">{{ $riskDistribution['data'][$i] }}</span>
                    </div>
                @endforeach
            </div>
        </x-card>

        <x-card title="Health Groups" sub="3 groups · based on capacity & environment">
            <div wire:ignore class="relative h-44"><canvas id="clusterChart"></canvas></div>
            <div class="mt-4 space-y-1.5">
                @foreach ($clusterDistribution['labels'] as $i => $label)
                    <div class="flex items-center gap-2 text-[11.5px] text-ink-700">
                        <span class="w-2 h-2 rounded-sm flex-shrink-0" style="background: {{ $clusterDistribution['colors'][$i] }}"></span>
                        <span>C{{ $clusterDistribution['ids'][$i] ?? ($i + 1) }} &middot; {{ $label }}</span>
                        <span class="ml-auto font-mono font-semibold tnum">{{ $clusterDistribution['data'][$i] }}</span>
                    </div>
                @endforeach
            </div>
        </x-card>

        <x-card title="Domain Scores" sub="Average score across filtered seniors">
            <div wire:ignore class="relative h-56"><canvas id="domainChart"></canvas></div>
        </x-card>
    </div>

    {{-- ── Age + Barangay row ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <x-card title="Age Group Distribution" sub="60-69 · 70-79 · 80+">
            <div wire:ignore class="relative h-48"><canvas id="ageChart"></canvas></div>
        </x-card>

        <x-card title="Barangay Breakdown" sub="Total seniors · high-risk count">
            <div class="overflow-auto max-h-56 scrollbar-thin -mx-1">
                <table class="w-full text-[12.5px]">
                    <thead>
                        <tr class="text-left text-ink-500">
                            <th class="pb-2 font-medium uppercase tracking-wider text-[10.5px]">Barangay</th>
                            <th class="pb-2 font-medium text-right uppercase tracking-wider text-[10.5px]">Total</th>
                            <th class="pb-2 font-medium text-right uppercase tracking-wider text-[10.5px]">High Risk</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-paper-rule">
                        @foreach ($barangayBreakdown as $row)
                            <tr>
                                <td class="py-2 text-ink-700 font-medium">{{ $row['barangay'] }}</td>
                                <td class="py-2 text-right font-mono tnum text-ink-900">{{ $row['total'] }}</td>
                                <td class="py-2 text-right">
                                    @if (($row['high'] ?? 0) > 0)
                                        <span class="badge badge-high">{{ $row['high'] }}</span>
                                        @if (($row['urgent'] ?? 0) > 0)
                                            <span class="ml-1 text-[10px] font-semibold text-high-600">&middot; {{ $row['urgent'] }} urgent</span>
                                        @endif
                                    @else
                                        <span class="text-ink-300">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>

    {{-- ── Bottom row ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

        <x-card title="Recent Senior Records">
            <x-slot name="actions">
                <a href="{{ route('seniors.index') }}" class="text-xs text-forest-700 hover:text-forest-900 font-semibold">View all &rarr;</a>
            </x-slot>
            <x-slot name="noPadding">true</x-slot>
            <div class="divide-y divide-paper-rule">
                @forelse ($recentSeniors as $senior)
                    @php $ml = $senior->latestMlResult; @endphp
                    <a href="{{ route('seniors.show', $senior->id) }}" class="px-5 py-3 flex items-center gap-3 hover:bg-paper-2 transition-colors">
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
                    <div class="px-5 py-10 text-center text-sm text-ink-400">No senior records found.</div>
                @endforelse
            </div>
        </x-card>

        <x-card title="Urgent Pending Actions" sub="Immediate-priority recommendations">
            <x-slot name="actions">
                <a href="{{ route('recommendations.index') }}" class="text-xs text-forest-700 hover:text-forest-900 font-semibold">View all &rarr;</a>
            </x-slot>
            <x-slot name="noPadding">true</x-slot>
            <div class="divide-y divide-paper-rule">
                @forelse ($pendingRecs as $rec)
                    <div class="px-5 py-3 hover:bg-paper-2 transition-colors">
                        <div class="flex items-start gap-3">
                            <span class="mt-0.5 w-6 h-6 flex-shrink-0 rounded-md grid place-items-center text-[11px] font-bold tnum bg-high-100 text-high-700">
                                {{ $rec->priority }}
                            </span>
                            <div class="flex-1 min-w-0">
                                <p class="text-[13px] font-semibold text-ink-900 truncate">{{ $rec->seniorCitizen->full_name }}</p>
                                <p class="text-[12px] text-ink-500 mt-0.5 line-clamp-1">{{ $rec->action }}</p>
                            </div>
                            <span class="badge badge-high flex-shrink-0">Urgent</span>
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-10 text-center text-sm text-ink-400">
                        No urgent pending actions.
                    </div>
                @endforelse
            </div>
        </x-card>
    </div>

    @php $__chartJson = json_encode(['risk' => $riskDistribution, 'cluster' => $clusterDistribution, 'domain' => $domainScoreChart, 'age' => $ageGroupChart], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); @endphp
    <script type="application/json" id="dashboard-chart-data">{!! $__chartJson !!}</script>

</div>
