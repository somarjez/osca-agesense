@extends('layouts.app')
@section('page-title', 'Senior Profile')
@section('page-subtitle', 'Individual record · Pagsanjan, Laguna')

@section('content')
@php $ml = $senior->latestMlResult; @endphp
<div class="space-y-6">

    <x-breadcrumb :links="[
        ['label' => 'Dashboard', 'href' => route('dashboard')],
        ['label' => 'Senior Records', 'href' => route('seniors.index')],
        ['label' => $senior->full_name],
    ]" />
    {{-- Eyebrow only — identity card below carries the name --}}
    <div class="mb-6">
        <p class="eyebrow mb-1.5">Senior Profile</p>
        <div class="page-underrule" aria-hidden="true"></div>
    </div>

    {{-- Top action bar --}}
    <div class="flex items-center gap-3 flex-wrap">
        <a href="{{ route('seniors.index') }}" class="btn btn-ghost gap-1.5 pl-1.5">
            <x-heroicon-o-arrow-left class="w-3.5 h-3.5" /> Back to records
        </a>
        <div class="ml-auto flex flex-wrap gap-2">
            @if (!empty($draftSurvey))
            <a href="{{ route('surveys.qol.edit', $draftSurvey) }}" class="btn btn-primary">
                <x-heroicon-o-clipboard-document-list class="w-3.5 h-3.5" /> Continue draft
            </a>
            @else
            <a href="{{ route('surveys.qol.create', $senior) }}" class="btn">
                <x-heroicon-o-clipboard-document-list class="w-3.5 h-3.5" /> New QoL Survey
            </a>
            @endif

            <div x-data="{
                    loading: false, done: false, err: '',
                    pollTimer: null, pollCount: 0, pollMax: 60,
                    baseTs: {{ $ml ? $ml->processed_at->timestamp : 0 }},
                    run() {
                        this.loading = true; this.err = ''; this.pollCount = 0;
                        fetch('{{ route('ml.run.single', $senior) }}', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
                        })
                        .then(r => r.json())
                        .then(d => {
                            if (d.error) { this.err = d.error; this.loading = false; return; }
                            this.poll();
                        })
                        .catch(() => { this.err = 'Request failed.'; this.loading = false; });
                    },
                    poll() {
                        this.pollTimer = setInterval(() => {
                            this.pollCount++;
                            if (this.pollCount >= this.pollMax) {
                                clearInterval(this.pollTimer);
                                this.loading = false;
                                this.err = 'Analysis timed out. Check that Python services are running, then try again.';
                                return;
                            }
                            fetch('{{ route('ml.result.senior', $senior) }}', {
                                headers: { 'Accept': 'application/json' }
                            })
                            .then(r => r.json())
                            .then(d => {
                                if (d.ready && d.processed_at && d.processed_at > this.baseTs) {
                                    clearInterval(this.pollTimer);
                                    this.done = true;
                                    setTimeout(() => location.reload(), 800);
                                }
                            })
                            .catch(() => {});
                        }, 3000);
                    }
                }">
                <button @click="run()" :disabled="loading || done" class="btn btn-primary disabled:opacity-60 disabled:cursor-not-allowed">
                    <template x-if="loading && !done">
                        <span class="inline-flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
                            Analyzing…
                        </span>
                    </template>
                    <template x-if="done">
                        <span class="inline-flex items-center gap-1"><svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg> Done — reloading…</span>
                    </template>
                    <template x-if="!loading && !done">
                        <span class="inline-flex items-center gap-1.5">
                            <x-heroicon-o-arrow-path class="w-3.5 h-3.5" /> Re-run Assessment
                        </span>
                    </template>
                </button>
                <p x-show="err" x-text="err" x-cloak class="text-xs text-critical-700 mt-1 text-right"></p>
            </div>

            <a href="{{ route('seniors.export', $senior) }}" class="btn">
                <x-heroicon-o-arrow-down-tray class="w-3.5 h-3.5" /> Export PDF
            </a>
            <a href="{{ route('seniors.edit', $senior) }}" class="btn">
                <x-heroicon-o-pencil class="w-3.5 h-3.5" /> Edit
            </a>
        </div>
    </div>

    {{-- Identity header --}}
    <div class="card">
        <div class="card-body flex items-center gap-4">
            <div class="w-12 h-12 rounded-full bg-forest-100 grid place-items-center flex-shrink-0">
                <span class="text-lg font-semibold text-forest-800">{{ strtoupper(substr($senior->first_name,0,1).substr($senior->last_name,0,1)) }}</span>
            </div>
            <div class="flex-1 min-w-0">
                <h1 class="font-serif text-[20px] font-semibold text-ink-900 dark:text-[#e4e1d8] leading-tight">{{ $senior->full_name }}</h1>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 mt-0.5 text-[12.5px] text-ink-500">
                    <span class="font-mono tnum">{{ $senior->osca_id }}</span>
                    <span class="text-ink-300">·</span>
                    <span>{{ $senior->barangay }}</span>
                    <span class="text-ink-300">·</span>
                    <span>{{ $senior->age }} yrs · {{ $senior->gender }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Analysis dispatched banner — polls until job writes a fresher result --}}
    @php
        $latestSurvey    = $senior->latestQolSurvey;
        $pendingAnalysis = session()->has('success')
            && $latestSurvey
            && (
                !$ml
                || ($ml->processed_at && $latestSurvey->updated_at && $ml->processed_at->lt($latestSurvey->updated_at))
            );
    @endphp

    @if ($pendingAnalysis)
    <div x-data="{
            pollTimer: null, pollCount: 0, pollMax: 60, timedOut: false,
            baseTs: {{ $ml ? $ml->processed_at->timestamp : 0 }},
            init() {
                this.pollTimer = setInterval(() => {
                    this.pollCount++;
                    if (this.pollCount >= this.pollMax) {
                        clearInterval(this.pollTimer);
                        this.timedOut = true;
                        return;
                    }
                    fetch('{{ route('ml.result.senior', $senior) }}', { headers: { 'Accept': 'application/json' } })
                    .then(r => r.json())
                    .then(d => {
                        if (d.ready && d.processed_at && d.processed_at > this.baseTs) {
                            clearInterval(this.pollTimer);
                            location.reload();
                        }
                    })
                    .catch(() => {});
                }, 3000);
            }
        }"
        class="card border-l-[3px] border-l-forest-500">
        <div class="card-body flex items-center gap-3 text-sm text-ink-700">
            <template x-if="!timedOut">
                <svg class="w-4 h-4 animate-spin text-forest-500 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                </svg>
            </template>
            <span x-show="!timedOut">ML analysis is running in the background. This page will update automatically when complete.</span>
            <span x-show="timedOut" class="text-critical-700 dark:text-[#e08070]">Analysis timed out. Check that Python services are running, then re-run the assessment.</span>
        </div>
    </div>
    @endif

    @if ($ml && $ml->is_stale && !$pendingAnalysis)
    <x-alert type="warning" title="Results may be outdated">
        This senior's profile or survey data was changed after the last analysis. Re-run the assessment to get accurate scores.
    </x-alert>
    @endif

    @if ($ml && !$pendingAnalysis)
    <div class="card overflow-hidden">
        {{-- Compact assessment strip --}}
        <div class="px-5 py-3 flex items-center gap-4 flex-wrap">

            {{-- Risk + Cluster --}}
            <div class="flex items-center gap-2.5 flex-shrink-0">
                <span class="text-[10.5px] uppercase tracking-wide text-ink-400 dark:text-[#6b7570] font-semibold">Overall risk</span>
                <x-risk-badge :level="$ml->overall_risk_level" />
                @if ($ml->priority_flag === 'urgent')
                <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-orange-700 bg-orange-50 dark:bg-orange-50/10 px-2 py-0.5 rounded-full">
                    <x-heroicon-s-exclamation-triangle class="w-3 h-3 flex-shrink-0" aria-hidden="true" />
                    Urgent
                </span>
                @endif
                <span class="text-paper-rule text-ink-200 dark:text-[#2b3530] select-none">·</span>
                <x-cluster-badge :id="$ml->cluster_named_id" :label="$ml->cluster_name" />
            </div>

            {{-- Vertical divider --}}
            <div class="w-px self-stretch bg-paper-rule dark:bg-[#2b3530] flex-shrink-0 hidden sm:block"></div>

            {{-- Domain bars --}}
            <div class="flex gap-5 flex-1 min-w-0">
                @foreach ([
                    ['Intrinsic Capacity', $ml->ic_risk],
                    ['Environment', $ml->env_risk],
                    ['Functional Ability', $ml->func_risk],
                ] as [$label, $score])
                <div class="flex-1 min-w-[80px]">
                    <div class="flex justify-between text-[11px] text-ink-500 dark:text-[#6b7570] mb-1">
                        <span>{{ $label }}</span>
                        @if ($score !== null)
                        <span class="font-mono tnum">{{ round($score * 100) }}%</span>
                        @endif
                    </div>
                    <x-risk-bar :value="$score" />
                </div>
                @endforeach
            </div>

            {{-- Vertical divider --}}
            <div class="w-px self-stretch bg-paper-rule dark:bg-[#2b3530] flex-shrink-0 hidden sm:block"></div>

            {{-- Wellbeing + meta --}}
            <div class="flex-shrink-0 text-right">
                <div class="flex items-baseline gap-1 justify-end">
                    <span class="font-serif text-[32px] leading-none font-semibold tnum text-ink-900 dark:text-[#e4e1d8]">{{ number_format($ml->wellbeing_score * 100, 0) }}</span>
                    <span class="text-[13px] text-ink-400">/100</span>
                </div>
                <div class="text-[10.5px] text-ink-400 dark:text-[#6b7570] mt-0.5">wellbeing score</div>
                <div class="flex items-center justify-end gap-1.5 mt-1 flex-wrap">
                    <span class="inline-flex items-center gap-1 text-[10px] font-semibold px-1.5 py-0.5 rounded {{ $ml->prediction_source_color }}">
                        {{ $ml->prediction_source_label }}
                    </span>
                    <span class="text-[10px] text-ink-400 font-mono">{{ $ml->model_version }}</span>
                </div>
            </div>

        </div>

        {{-- Slim disclaimer --}}
        <div class="border-t border-paper-rule dark:border-[#2b3530] px-5 py-2 text-[11px] text-ink-400 dark:text-[#6b7570]">
            <strong class="font-semibold text-ink-500 dark:text-[#8a958f]">Decision-support only</strong> — indicates possible risk level and profile group, not a clinical diagnosis. Use alongside professional assessment and OSCA case knowledge.
        </div>
    </div>
    @elseif (!$ml && !$pendingAnalysis)
    <div class="card border-l-[3px] border-l-moderate-500">
        <div class="card-body text-sm text-ink-700">
            No assessment yet. Complete a QoL survey and run the assessment to see risk scores and recommendations.
        </div>
    </div>
    @endif

    {{-- ── Risk Drivers (XAI) ── --}}
    @if ($ml && $ml->xai_data)
    @php
        $xai = $ml->xai_data;
        $xaiDomains = [
            'ic'   => ['label' => 'Intrinsic Capacity',  'risk' => $ml->ic_risk_level],
            'env'  => ['label' => 'Environment',          'risk' => $ml->env_risk_level],
            'func' => ['label' => 'Functional Ability',   'risk' => $ml->func_risk_level],
        ];
    @endphp
    <div class="card">
        <div class="card-head">
            <div>
                <div class="card-title">Risk Drivers</div>
                <div class="card-sub">Key factors behind this assessment · <span class="text-red-600 font-semibold">↑ raises risk</span> · <span class="text-emerald-600 font-semibold">↓ lowers risk</span></div>
            </div>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                @foreach ($xaiDomains as $domainKey => $domainMeta)
                @php
                    $domainXai = $xai[$domainKey] ?? [];
                    $sectionDrivers = $domainXai['section_drivers'] ?? [];
                    $featureDrivers = $domainXai['feature_drivers'] ?? [];
                @endphp
                <div x-data="{ expanded: false }" class="bg-paper rounded-xl border border-paper-rule p-4">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-bold text-ink-500 uppercase tracking-wider">{{ $domainMeta['label'] }}</span>
                        <x-risk-badge :level="$domainMeta['risk']" />
                    </div>

                    <div class="space-y-2.5">
                        @forelse ($sectionDrivers as $driver)
                        @php
                            $isUp  = ($driver['direction'] ?? 'up') === 'up';
                            $pct   = $driver['contribution_pct'] ?? 0;
                            $arrow = $isUp ? '↑' : '↓';
                            $barColor  = $isUp ? 'bg-red-400'   : 'bg-emerald-400';
                            $textColor = $isUp ? 'text-red-700' : 'text-emerald-700';
                            $barWidth  = min(100, $pct);
                        @endphp
                        <div>
                            <div class="flex items-center justify-between text-[12px] mb-0.5">
                                <span class="{{ $textColor }} font-semibold">{{ $arrow }} {{ $driver['section'] }}</span>
                                <span class="text-ink-500 font-mono text-[11px]">{{ number_format($pct, 1) }}%</span>
                            </div>
                            <div class="bg-paper-rule rounded-full h-1.5">
                                <div class="{{ $barColor }} h-1.5 rounded-full transition-all" style="width: {{ $barWidth }}%"></div>
                            </div>
                        </div>
                        @empty
                        <p class="text-xs text-ink-400">No section data available.</p>
                        @endforelse
                    </div>

                    @if (!empty($featureDrivers))
                    <button @click="expanded = !expanded"
                            class="mt-3 text-[11px] text-forest-600 hover:text-forest-800 font-semibold flex items-center gap-1 transition-colors">
                        <span x-text="expanded ? 'Hide feature detail ▲' : 'Show feature detail ▼'">Show feature detail ▼</span>
                    </button>
                    <div x-show="expanded" x-cloak class="mt-3 space-y-2 border-t border-paper-rule pt-3">
                        @foreach ($featureDrivers as $feat)
                        @php
                            $isUp  = ($feat['direction'] ?? 'up') === 'up';
                            $pct   = $feat['contribution_pct'] ?? 0;
                            $arrow = $isUp ? '↑' : '↓';
                            $barColor  = $isUp ? 'bg-red-300'   : 'bg-emerald-300';
                            $textColor = $isUp ? 'text-red-600' : 'text-emerald-600';
                            $barWidth  = min(100, $pct);
                            $featLabel = \App\Support\XaiFeatureLabels::label($feat['feature']);
                        @endphp
                        <div>
                            <div class="flex items-center justify-between text-[11px] mb-0.5">
                                <span class="{{ $textColor }}">{{ $arrow }} {{ $featLabel }}</span>
                                <span class="text-ink-400 font-mono text-[10px]">{{ number_format($pct, 1) }}%</span>
                            </div>
                            <div class="bg-paper-rule rounded-full h-1">
                                <div class="{{ $barColor }} h-1 rounded-full" style="width: {{ $barWidth }}%"></div>
                            </div>
                            <div class="text-[10px] text-ink-400 mt-0.5">
                                Senior: {{ number_format($feat['value'], 2) }} · Cluster avg: {{ number_format($feat['mean'], 2) }}
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>
                @endforeach
            </div>
        </div>
    </div>
    @endif

    {{-- Profile + Recommendations --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        {{-- Left: Tabbed profile sections (I–VI) + Survey History --}}
        <div class="lg:col-span-2 space-y-5" x-data="{ tab: 'info' }">
            <div class="card">
                {{-- Manual header row (no border-b so the tab strip acts as the divider) --}}
                <div class="px-5 pt-4 pb-0 flex items-center justify-between gap-3">
                    <div class="font-serif text-[15px] font-semibold tracking-tightish text-ink-900 dark:text-[#e4e1d8]">Profile</div>
                </div>

                {{-- Section selector — segmented control: all six always visible, no horizontal scroll --}}
                <div class="px-5 mt-3 pb-4 border-b border-paper-rule dark:border-[#2b3530]">
                    <div role="tablist" aria-label="Profile sections"
                         class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-1 p-1 rounded-xl bg-paper-2 dark:bg-[#171c19] border border-paper-rule dark:border-[#2b3530]">
                        @foreach ([
                            ['info',   'I · Identity'],
                            ['family', 'II · Family'],
                            ['edu',    'III · Education'],
                            ['dep',    'IV · Dependency'],
                            ['eco',    'V · Economic'],
                            ['health', 'VI · Health'],
                        ] as [$key, $label])
                        <button type="button" role="tab"
                                @click="tab = '{{ $key }}'"
                                :aria-selected="tab === '{{ $key }}' ? 'true' : 'false'"
                                :class="tab === '{{ $key }}'
                                    ? 'bg-white dark:bg-[#202a26] text-accent-700 dark:text-accent-400 font-semibold shadow-sm ring-1 ring-paper-rule dark:ring-[#2b3530]'
                                    : 'text-ink-500 dark:text-[#8a958f] hover:text-ink-900 dark:hover:text-[#c8c4bc] hover:bg-white/60 dark:hover:bg-[#202a26]/60'"
                                class="px-2.5 py-2 rounded-lg text-[12px] font-medium leading-tight text-center transition-all duration-100 focus:outline-none focus:ring-2 focus:ring-forest-500/30">
                            {{ $label }}
                        </button>
                        @endforeach
                    </div>
                </div>

                <div class="card-body">

                    {{-- I. Identifying Information --}}
                    <div x-show="tab === 'info'">
                        <div class="grid grid-cols-2 gap-x-8 gap-y-3 text-sm">
                            <x-profile-field label="Full Name"          :value="$senior->full_name"/>
                            <x-profile-field label="OSCA ID"            :value="$senior->osca_id"/>
                            <x-profile-field label="Date of Birth"      :value="$senior->date_of_birth?->format('F j, Y')"/>
                            <x-profile-field label="Age"                :value="$senior->age . ' years old'"/>
                            <x-profile-field label="Place of Birth"     :value="$senior->place_of_birth"/>
                            <x-profile-field label="Barangay"           :value="$senior->barangay"/>
                            <x-profile-field label="Gender"             :value="$senior->gender"/>
                            <x-profile-field label="Marital Status"     :value="$senior->marital_status"/>
                            <x-profile-field label="Religion"           :value="$senior->religion"/>
                            <x-profile-field label="Ethnic Origin"      :value="$senior->ethnic_origin"/>
                            <x-profile-field label="Blood Type"         :value="$senior->blood_type"/>
                            <x-profile-field label="Contact"            :value="$senior->contact_number"/>
                            <x-profile-field label="PhilSys / Nat'l ID" :value="$senior->philsys_id"/>
                            <x-profile-field label="Encoded By"         :value="$senior->encoded_by"/>
                            <x-profile-field label="Consent"
                                :value="$senior->consent_given_at
                                    ? 'Given ' . $senior->consent_given_at->format('M d, Y') . ($senior->consent_method ? ' (' . $senior->consent_method . ')' : '')
                                    : null"
                                empty="Not recorded" />
                        </div>
                    </div>

                    {{-- II. Family Composition --}}
                    <div x-show="tab === 'family'">
                        <div class="grid grid-cols-2 gap-x-8 gap-y-3 text-sm">
                            <x-profile-field label="No. of Children"         :value="$senior->num_children"/>
                            <x-profile-field label="Working Children"        :value="$senior->num_working_children"/>
                            <x-profile-field label="Child Financial Support" :value="$senior->child_financial_support"/>
                            <x-profile-field label="Spouse/Partner Working"  :value="$senior->spouse_working"/>
                            <x-profile-field label="Household Size"          :value="$senior->household_size . ' persons'"/>
                        </div>
                    </div>

                    {{-- III. Education / HR Profile --}}
                    <div x-show="tab === 'edu'">
                        <div class="space-y-3 text-sm">
                            <x-profile-field label="Educational Attainment" :value="$senior->educational_attainment"/>
                            @if (!empty($senior->specialization))
                            <div>
                                <span class="eyebrow">Areas of Specialization / Technical Skills</span>
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    @foreach ($senior->specialization as $item)
                                        <span class="badge badge-info">{{ $item }}</span>
                                    @endforeach
                                </div>
                            </div>
                            @endif
                            @if (!empty($senior->community_service))
                            <div>
                                <span class="eyebrow">Community Service and Involvement</span>
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    @foreach ($senior->community_service as $item)
                                        <span class="badge badge-info">{{ $item }}</span>
                                    @endforeach
                                </div>
                            </div>
                            @endif
                        </div>
                    </div>

                    {{-- IV. Dependency Profile --}}
                    <div x-show="tab === 'dep'">
                        <div class="space-y-3 text-sm">
                            @if (!empty($senior->living_with))
                            <div>
                                <span class="eyebrow">Living / Residing With</span>
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    @foreach ($senior->living_with as $item)
                                        <span class="badge badge-info">{{ $item }}</span>
                                    @endforeach
                                </div>
                            </div>
                            @endif
                            @if (!empty($senior->household_condition))
                            <div>
                                <span class="eyebrow">Household Condition</span>
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    @foreach ($senior->household_condition as $item)
                                        <span class="badge badge-info">{{ $item }}</span>
                                    @endforeach
                                </div>
                            </div>
                            @endif
                            @if (empty($senior->living_with) && empty($senior->household_condition))
                                <p class="text-sm text-ink-400">No dependency data recorded.</p>
                            @endif
                        </div>
                    </div>

                    {{-- V. Economic Profile --}}
                    <div x-show="tab === 'eco'">
                        <div class="space-y-3 text-sm">
                            <x-profile-field label="Monthly Income" :value="$senior->monthly_income_range"/>
                            @if (!empty($senior->income_source))
                            <div>
                                <span class="eyebrow">Income Sources</span>
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    @foreach ($senior->income_source as $src)
                                        <span class="badge badge-info">{{ $src }}</span>
                                    @endforeach
                                </div>
                            </div>
                            @endif
                            @if (!empty($senior->real_assets))
                            <div>
                                <span class="eyebrow">Real Assets</span>
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    @foreach ($senior->real_assets as $item)
                                        <span class="badge badge-neutral">{{ $item }}</span>
                                    @endforeach
                                </div>
                            </div>
                            @endif
                            @if (!empty($senior->movable_assets))
                            <div>
                                <span class="eyebrow">Movable Assets</span>
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    @foreach ($senior->movable_assets as $item)
                                        <span class="badge badge-neutral">{{ $item }}</span>
                                    @endforeach
                                </div>
                            </div>
                            @endif
                            @if (!empty($senior->problems_needs))
                            <div>
                                <span class="eyebrow">Problems / Needs</span>
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    @foreach ($senior->problems_needs as $need)
                                        <span class="badge badge-moderate">{{ $need }}</span>
                                    @endforeach
                                </div>
                            </div>
                            @endif
                        </div>
                    </div>

                    {{-- VI. Health Profile --}}
                    <div x-show="tab === 'health'">
                        <div class="space-y-3 text-sm">
                            <div>
                                <span class="eyebrow">Medical Concerns</span>
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    @forelse ($senior->medical_concern ?? [] as $concern)
                                        <span class="badge {{ $concern === 'Physically Healthy' ? 'badge-low' : 'badge-critical' }}">{{ $concern }}</span>
                                    @empty
                                        <span class="text-ink-400 text-xs">None reported</span>
                                    @endforelse
                                </div>
                            </div>
                            @foreach ([
                                ['Dental Concern',               $senior->dental_concern],
                                ['Optical / Vision',             $senior->optical_concern],
                                ['Hearing',                      $senior->hearing_concern],
                                ['Social & Emotional Concerns',  $senior->social_emotional_concern],
                                ['Healthcare Access Difficulty', $senior->healthcare_difficulty],
                            ] as [$sectionLabel, $items])
                            @php $itemsArr = is_array($items) ? $items : (is_string($items) && $items ? [$items] : []); @endphp
                            @if (!empty($itemsArr))
                            <div>
                                <span class="eyebrow">{{ $sectionLabel }}</span>
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    @foreach ($itemsArr as $item)
                                        <span class="badge badge-info">{{ $item }}</span>
                                    @endforeach
                                </div>
                            </div>
                            @endif
                            @endforeach
                            <div>
                                <span class="eyebrow">Medical Check-up</span>
                                <p class="mt-1 text-sm {{ $senior->has_medical_checkup ? 'text-low-700 font-semibold' : 'text-ink-400' }}">
                                    {{ $senior->has_medical_checkup ? 'Yes — ' . ($senior->checkup_schedule ?? 'schedule not specified') : 'No' }}
                                </p>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            {{-- QoL Survey History — bottom of left column --}}
            @if ($senior->qolSurveys->isNotEmpty())
            <x-card title="QoL Survey History">
                <x-slot name="actions">
                    @if (!empty($draftSurvey))
                    <a href="{{ route('surveys.qol.edit', $draftSurvey) }}" class="text-xs text-forest-700 font-semibold hover:text-forest-900">Continue draft →</a>
                    @else
                    <a href="{{ route('surveys.qol.create', $senior) }}" class="text-xs text-forest-700 font-semibold hover:text-forest-900">+ New survey</a>
                    @endif
                </x-slot>
                <x-slot name="noPadding">true</x-slot>
                <table class="w-full text-sm">
                    <thead>
                        <tr>
                            <th class="th">Date</th>
                            <th class="th">Overall</th>
                            <th class="th">Physical</th>
                            <th class="th">Psychological</th>
                            <th class="th">Social</th>
                            <th class="th">Status</th>
                            <th class="th"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($senior->qolSurveys as $survey)
                        <tr class="group hover:bg-forest-50/40 dark:hover:bg-forest-900/10 transition-colors duration-100">
                            <td class="td text-ink-700">{{ $survey->survey_date?->format('M j, Y') }}</td>
                            <td class="td font-semibold tnum">{{ $survey->overall_score ? number_format($survey->overall_score * 100, 0) . '%' : '—' }}</td>
                            <td class="td tnum">{{ $survey->score_physical ? number_format($survey->score_physical * 100, 0) . '%' : '—' }}</td>
                            <td class="td tnum">{{ $survey->score_psychological ? number_format($survey->score_psychological * 100, 0) . '%' : '—' }}</td>
                            <td class="td tnum">{{ $survey->score_social ? number_format($survey->score_social * 100, 0) . '%' : '—' }}</td>
                            <td class="td">
                                <span class="badge {{ $survey->status === 'processed' ? 'badge-low' : 'badge-neutral' }}">
                                    {{ ucfirst($survey->status) }}
                                </span>
                            </td>
                            <td class="td">
                                <div class="flex items-center gap-1.5">
                                    @if ($survey->status === 'processed')
                                    <a href="{{ route('surveys.qol.results', $survey) }}"
                                       class="btn btn-ghost text-[11px] px-2 py-0.5">Results</a>
                                    @endif
                                    <div x-data="{ open: false }">
                                        <button @click="open = true"
                                                class="btn btn-ghost text-[11px] px-2 py-0.5 text-critical-700 hover:bg-critical-50 hover:text-critical-900">
                                            Delete
                                        </button>
                                        <form x-ref="deleteForm" method="POST" action="{{ route('surveys.qol.destroy', $survey) }}" class="hidden">
                                            @csrf @method('DELETE')
                                        </form>
                                        <x-confirm-modal show="open"
                                                         title="Delete QoL Survey?"
                                                         confirm="$refs.deleteForm.submit()"
                                                         confirm-label="Delete survey">
                                            <p>The survey from <strong class="text-ink-900 dark:text-[#e4e1d8]">{{ $survey->survey_date?->format('M j, Y') }}</strong> and its decision-support output will be permanently deleted.</p>
                                            <p class="mt-2 text-[12px] font-semibold px-3 py-2 rounded-xl text-critical-700 dark:text-[#e08070] bg-critical-50 dark:bg-critical-50/10 border border-critical-100 dark:border-critical-700/30">
                                                This cannot be undone.
                                            </p>
                                        </x-confirm-modal>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
            @endif

            {{-- Section Scores — paired with profile sections I–VI --}}
            @if ($ml?->section_scores)
            <x-card title="Section Scores">
                <div class="grid grid-cols-2 gap-x-8 gap-y-3">
                    @php
                    $scoreLabels = [
                        'sec1_age_risk'        => 'Age Risk',
                        'sec2_family_support'  => 'Family Support',
                        'sec3_hr_score'        => 'HR / Skills',
                        'sec4_dependency_risk' => 'Dependency Risk',
                        'sec5_eco_stability'   => 'Economic Stability',
                        'sec6_health_score'    => 'Health Score',
                        'overall_wellbeing'    => 'Overall Wellbeing',
                    ];
                    @endphp
                    @foreach ($scoreLabels as $key => $label)
                    @php $val = $ml->section_scores[$key] ?? null; @endphp
                    @if ($val !== null)
                    <div>
                        <div class="flex justify-between text-[11.5px] mb-1">
                            <span class="text-ink-500">{{ $label }}</span>
                            <span class="font-mono font-semibold text-ink-900 dark:text-[#e4e1d8] tnum">{{ number_format($val * 100, 0) }}%</span>
                        </div>
                        <div class="bar">
                            <div class="bar-fill {{ in_array($key, ['sec1_age_risk','sec4_dependency_risk']) ? 'bar-fill-critical' : 'bar-fill-forest' }}"
                                 style="width: {{ $val * 100 }}%"></div>
                        </div>
                    </div>
                    @endif
                    @endforeach
                </div>
            </x-card>
            @endif
        </div>

        {{-- Right: Recommendations --}}
        <div class="space-y-5">

            {{-- Recommendations --}}
            <x-card title="Recommendations">
                <x-slot name="actions">
                    <div class="flex items-center gap-2">
                        <span class="text-[11px] text-ink-500 tnum">{{ $ml?->recommendations->count() ?? 0 }} total</span>
                        @if ($ml?->recommendations->count())
                        <a href="{{ route('recommendations.show', $senior) }}"
                           class="btn btn-ghost text-[11px] px-2.5 py-1 gap-1">
                            <x-heroicon-o-arrow-top-right-on-square class="w-3 h-3" />
                            View all
                        </a>
                        @endif
                    </div>
                </x-slot>
                <x-slot name="noPadding">true</x-slot>

                @php
                $categories = [
                    'health'     => ['label' => 'Health',     'icon' => 'heroicon-o-heart'],
                    'financial'  => ['label' => 'Financial',  'icon' => 'heroicon-o-banknotes'],
                    'social'     => ['label' => 'Social',     'icon' => 'heroicon-o-user-group'],
                    'functional' => ['label' => 'Functional', 'icon' => 'heroicon-o-bolt'],
                    'hc_access'  => ['label' => 'HC Access',  'icon' => 'heroicon-o-building-office-2'],
                    'sensory'    => ['label' => 'Sensory',    'icon' => 'heroicon-o-eye'],
                    'general'    => ['label' => 'General',    'icon' => 'heroicon-o-clipboard-document-list'],
                ];
                $grouped = $ml?->recommendations->groupBy('category') ?? collect();
                @endphp

                @forelse ($grouped as $cat => $recs)
                @php
                    $hasUrgent = $recs->where('urgency', 'urgent')->isNotEmpty();
                    $catInfo   = $categories[$cat] ?? ['label' => ucfirst($cat), 'icon' => 'heroicon-o-tag'];
                @endphp
                <div x-data="{ open: {{ $loop->first ? 'true' : 'false' }} }"
                     class="border-b border-paper-rule last:border-b-0">
                    {{-- Collapsible category header --}}
                    <button @click="open = !open"
                            class="w-full flex items-center justify-between px-5 py-2.5 text-left bg-paper-2 dark:bg-[#1a201d] hover:bg-forest-50/60 dark:hover:bg-forest-900/10 transition-colors">
                        <div class="flex items-center gap-2">
                            <x-dynamic-component :component="$catInfo['icon']" class="w-4 h-4 text-ink-500 flex-shrink-0" aria-hidden="true" />
                            <span class="eyebrow">{{ $catInfo['label'] }}</span>
                            @if ($hasUrgent)
                                <span class="inline-flex items-center gap-1 text-[10px] font-bold text-orange-700 bg-orange-100 px-1.5 py-0.5 rounded-full">
                                    <x-heroicon-s-exclamation-triangle class="w-3 h-3 flex-shrink-0" aria-hidden="true" />
                                    Urgent
                                </span>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-[11px] text-ink-400 tnum">{{ $recs->count() }}</span>
                            <svg class="w-3.5 h-3.5 text-ink-400 transition-transform duration-150" :class="open ? 'rotate-180' : ''"
                                 xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </div>
                    </button>

                    {{-- Recommendation list --}}
                    <div x-show="open"
                         x-transition:enter="transition ease-out duration-150"
                         x-transition:enter-start="opacity-0 -translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-100"
                         x-transition:leave-start="opacity-100"
                         x-transition:leave-end="opacity-0">
                        <ul class="divide-y divide-paper-rule">
                            @foreach ($recs->sortBy('priority') as $rec)
                            <li class="px-5 py-3">
                                <div class="flex items-start gap-3">
                                    <span class="flex-shrink-0 w-6 h-6 rounded-md grid place-items-center mt-0.5 text-[11px] font-bold tnum
                                        {{ match($rec->urgency) {
                                            'urgent','immediate' => 'bg-high-100 text-high-700',
                                            'planned'            => 'bg-info-100 text-info-700',
                                            default              => 'bg-paper-2 text-ink-500',
                                        } }}">P{{ $rec->priority }}</span>
                                    <div class="flex-1">
                                        <p class="text-[13px] text-ink-900 leading-relaxed">{{ $rec->action }}</p>
                                        @if ($rec->notes)
                                        <p class="text-[11px] text-ink-400 mt-0.5 italic">{{ $rec->notes }}</p>
                                        @endif
                                        <div class="flex items-center gap-2 mt-1.5">
                                            <span class="badge {{ match($rec->urgency) {
                                                'urgent','immediate' => 'badge-high',
                                                'planned'            => 'badge-info',
                                                default              => 'badge-neutral',
                                            } }}">{{ ucfirst($rec->urgency) }}</span>
                                            <span class="text-[11px] text-ink-400">{{ ucfirst($rec->status) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
                @empty
                <div class="p-8 text-center text-sm text-ink-400">No recommendations yet. Run the assessment first.</div>
                @endforelse
            </x-card>

        </div>
    </div>

    {{-- ── Location & Accessibility ── (full-width, horizontal) ── --}}
    @php
        $loc        = $locationPanel['location'];
        $hasCoords  = $loc['status'] !== 'none';
        $isVerified = $loc['status'] === 'verified';
        $facilities = $locationPanel['facilities'];
        $percent    = $locationPanel['percent'];

        $facilityIcons = [
            'health_center' => 'heroicon-o-plus-circle',
            'hospital'      => 'heroicon-o-building-office-2',
            'pharmacy'      => 'heroicon-o-beaker',
            'barangay_hall' => 'heroicon-o-building-library',
            'market'        => 'heroicon-o-shopping-bag',
        ];

        $fmtDist = function ($m) {
            if ($m === null) return null;
            return $m < 1000 ? round($m) . ' m' : number_format($m / 1000, 1) . ' km';
        };
        $fmtDrive = function ($s) {
            if ($s === null) return null;
            return max(1, (int) round($s / 60)) . ' min drive';
        };

        $mapData = [
            'senior' => [
                'lat'    => $loc['lat'],
                'lng'    => $loc['lng'],
                'status' => $loc['status'],
                'name'   => $senior->full_name,
            ],
            'facilities' => collect($facilities)->map(fn ($f) => [
                'lat'   => $f['lat'],
                'lng'   => $f['lng'],
                'name'  => $f['name'],
                'label' => $f['label'],
            ])->values(),
        ];
    @endphp

    <x-card title="Location & Accessibility">
        <x-slot name="actions">
            <a href="{{ route('reports.gis') }}"
               class="btn btn-ghost text-[11px] px-2.5 py-1 gap-1">
                <x-heroicon-o-map class="w-3 h-3" />
                Full map
            </a>
        </x-slot>
        <x-slot name="noPadding">true</x-slot>

        @if ($hasCoords)
        <div class="grid grid-cols-1 lg:grid-cols-12">

            {{-- Map + coordinates --}}
            <div class="lg:col-span-5 p-5 space-y-3.5 border-b lg:border-b-0 lg:border-r border-paper-rule dark:border-[#2b3530]">
                {{-- Mini-map — reads cached coordinates only, no live routing calls --}}
                <div id="senior-mini-map"
                     class="h-60 w-full rounded-xl border border-paper-rule dark:border-[#2b3530] overflow-hidden z-0"
                     role="img"
                     aria-label="Map of {{ $senior->full_name }} and nearby facilities in {{ $senior->barangay }}"></div>
                <script type="application/json" id="senior-map-data">@json($mapData)</script>

                {{-- Coordinates + precision --}}
                <div>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <span class="eyebrow">Coordinates</span>
                            <p class="mt-1 font-mono tnum text-[13px] text-ink-900 dark:text-[#e4e1d8] leading-tight">
                                {{ number_format($loc['lat'], 6) }}, {{ number_format($loc['lng'], 6) }}
                            </p>
                        </div>
                        @if ($isVerified)
                        <span class="inline-flex items-center gap-1 text-[10.5px] font-semibold text-low-700 bg-low-50 dark:bg-low-50/10 px-2 py-0.5 rounded-full flex-shrink-0">
                            <x-heroicon-s-check-badge class="w-3 h-3 flex-shrink-0" aria-hidden="true" />
                            Verified pin
                        </span>
                        @else
                        <span class="inline-flex items-center gap-1 text-[10.5px] font-semibold text-moderate-700 bg-moderate-50 dark:bg-moderate-50/10 px-2 py-0.5 rounded-full flex-shrink-0">
                            <x-heroicon-s-flag class="w-3 h-3 flex-shrink-0" aria-hidden="true" />
                            Approximate
                        </span>
                        @endif
                    </div>
                    @unless ($isVerified)
                    <p class="mt-1.5 text-[11px] text-ink-400 dark:text-[#6b7570]">
                        Barangay-level estimate — no field-verified GPS pin on record.
                    </p>
                    @endunless
                </div>
            </div>

            {{-- Facility access + nearest facilities --}}
            <div class="lg:col-span-7 flex flex-col">

                {{-- Accessibility score --}}
                @if ($percent !== null)
                <div class="p-5 border-b border-paper-rule dark:border-[#2b3530]">
                    <div class="flex items-baseline justify-between mb-1.5">
                        <span class="eyebrow">Facility access</span>
                        <span class="text-[11px] font-semibold {{ $percent >= 75 ? 'text-low-700 dark:text-low-500' : ($percent >= 50 ? 'text-moderate-700 dark:text-moderate-500' : 'text-high-700 dark:text-high-500') }}">
                            {{ $locationPanel['status'] }}
                        </span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="bar flex-1">
                            <div class="bar-fill {{ $percent >= 50 ? 'bar-fill-forest' : 'bar-fill-critical' }}" style="width: {{ $percent }}%"></div>
                        </div>
                        <span class="font-mono tnum text-[13px] font-semibold text-ink-900 dark:text-[#e4e1d8] w-9 text-right">{{ $percent }}%</span>
                    </div>
                    <p class="text-[10.5px] text-ink-400 dark:text-[#6b7570] mt-1">Closeness to the nearest health, civic, and market services.</p>
                </div>
                @endif

                {{-- Nearest facilities --}}
                @if (!empty($facilities))
                <div class="p-5">
                    <span class="eyebrow">Nearest facilities</span>
                    <ul class="mt-2 grid sm:grid-cols-2 sm:gap-x-8">
                        @foreach ($facilities as $facility)
                        <li class="flex items-center gap-3 py-2.5 border-b border-paper-rule dark:border-[#2b3530] last:border-b-0">
                            <span class="w-7 h-7 rounded-lg bg-forest-50 dark:bg-forest-900/15 grid place-items-center flex-shrink-0">
                                <x-dynamic-component :component="$facilityIcons[$facility['key']] ?? 'heroicon-o-map-pin'" class="w-3.5 h-3.5 text-forest-700 dark:text-forest-400" aria-hidden="true" />
                            </span>
                            <div class="flex-1 min-w-0">
                                <p class="text-[12.5px] font-medium text-ink-900 dark:text-[#e4e1d8] truncate leading-tight">{{ $facility['name'] }}</p>
                                <p class="text-[10.5px] text-ink-400 dark:text-[#6b7570]">{{ $facility['label'] }}</p>
                            </div>
                            <div class="text-right flex-shrink-0">
                                @if ($facility['straight_m'] !== null)
                                <p class="font-mono tnum text-[12px] font-semibold text-ink-700 dark:text-[#c8c4bc] leading-tight">{{ $fmtDist($facility['straight_m']) }}</p>
                                @endif
                                @if ($facility['route_s'] !== null)
                                <p class="text-[10px] text-ink-400 dark:text-[#6b7570] tnum">{{ $fmtDrive($facility['route_s']) }}</p>
                                @elseif ($facility['route_m'] !== null)
                                <p class="text-[10px] text-ink-400 dark:text-[#6b7570] tnum">{{ $fmtDist($facility['route_m']) }} by road</p>
                                @else
                                <p class="text-[10px] text-ink-300 dark:text-[#4b554f]">straight-line</p>
                                @endif
                            </div>
                        </li>
                        @endforeach
                    </ul>
                </div>
                @else
                <div class="p-5 text-[12px] text-ink-400 dark:text-[#6b7570]">
                    Facility distances are not calculated yet for this senior.
                </div>
                @endif
            </div>
        </div>
        @else
        {{-- No coordinate stored yet --}}
        <div class="p-5 flex items-start gap-3">
            <div class="w-9 h-9 rounded-lg bg-paper-2 dark:bg-[#1a201d] grid place-items-center flex-shrink-0">
                <x-heroicon-o-map-pin class="w-5 h-5 text-ink-400" />
            </div>
            <div>
                <p class="text-[13px] text-ink-700 dark:text-[#c8c4bc] font-medium">No location on record</p>
                <p class="text-[11px] text-ink-400 dark:text-[#6b7570] mt-0.5">
                    This senior has not been geocoded yet.
                </p>
            </div>
        </div>
        @endif
    </x-card>

    <x-doc-footer :control="'OSCA-PSG-' . now()->format('Y') . '-' . str_pad((string) $senior->id, 5, '0', STR_PAD_LEFT)"
                  signatory="OSCA Officer / Reviewer" />
</div>
@endsection

@push('styles')
<style>
/* Soft land tone behind the mini-map so brief tile gaps during load/zoom read
   as map background rather than Leaflet's default grey or the dark panel. */
#senior-mini-map { background: #f2efe9; }
#senior-mini-map .leaflet-popup-content { font-size: 12px; line-height: 1.45; }
#senior-mini-map .leaflet-popup-content strong { font-weight: 600; }
</style>
@endpush

@push('scripts')
<script>
(function () {
    let leafletWaitAttempts = 0;
    const LEAFLET_MAX_ATTEMPTS = 40; // ~2s of 50ms polls, then give up
    function initSeniorMiniMap() {
        const el = document.getElementById('senior-mini-map');
        const dataEl = document.getElementById('senior-map-data');
        if (!el || !dataEl) return;
        if (el._leaflet_id) return;            // already initialized on this element
        if (!window.L) {                       // app.js (deferred module) hasn't run yet
            if (leafletWaitAttempts++ >= LEAFLET_MAX_ATTEMPTS) return; // Leaflet failed to load
            setTimeout(initSeniorMiniMap, 50); // retry once Leaflet is on window
            return;
        }
        leafletWaitAttempts = 0;               // reset so a later re-init can wait again

        let data;
        try { data = JSON.parse(dataEl.textContent); } catch (e) { return; }
        if (!data.senior || data.senior.lat == null || data.senior.lng == null) return;

        const L = window.L;
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const verified = data.senior.status === 'verified';

        // Popups set innerHTML, so any DB-sourced name must be HTML-escaped first.
        const esc = function (value) {
            return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        };

        const map = L.map(el, {
            zoomControl: true,
            scrollWheelZoom: false,
            attributionControl: true,
            zoomAnimation: !reduce,
            fadeAnimation: !reduce,
            markerZoomAnimation: !reduce,
        }).setView([data.senior.lat, data.senior.lng], 15);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors',
        }).addTo(map);

        const bounds = [];

        // Nearby facilities — muted navy dots so the senior point stays dominant.
        (data.facilities || []).forEach(function (f) {
            if (f.lat == null || f.lng == null) return;
            const marker = L.circleMarker([f.lat, f.lng], {
                radius: 6,
                color: '#ffffff',
                weight: 2,
                fillColor: '#27406b',
                fillOpacity: 0.95,
            }).addTo(map);
            marker.bindPopup('<strong>' + esc(f.name || 'Facility') + '</strong><br>' + esc(f.label || ''));
            bounds.push([f.lat, f.lng]);
        });

        // Senior point — bright accent (verified) or amber dashed ring (approximate).
        const senior = L.circleMarker([data.senior.lat, data.senior.lng], {
            radius: 9,
            color: '#ffffff',
            weight: 3,
            fillColor: verified ? '#2657aa' : '#b4882a',
            fillOpacity: 1,
            dashArray: verified ? null : '3,3',
        }).addTo(map);
        senior.bindPopup(
            '<strong>' + esc(data.senior.name || 'Senior') + '</strong><br>' +
            (verified ? 'Field-verified GPS pin' : 'Approximate — barangay level')
        );
        bounds.push([data.senior.lat, data.senior.lng]);

        if (bounds.length > 1) {
            map.fitBounds(bounds, { padding: [26, 26], maxZoom: 16, animate: !reduce });
        }

        // The card lays out after script runs; recompute tile size once settled.
        setTimeout(function () { map.invalidateSize(); }, 80);
    }

    // Vite emits app.js as a deferred module, so this inline script runs first.
    // Wait for DOMContentLoaded (deferred modules finish before it) so window.L
    // exists, and re-init after Livewire SPA navigation (DOMContentLoaded won't refire).
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSeniorMiniMap);
    } else {
        initSeniorMiniMap();
    }
    document.addEventListener('livewire:navigated', initSeniorMiniMap);
})();
</script>
@endpush
