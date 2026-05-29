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
    <x-page-header
        eyebrow="Senior Profile"
        :title="$senior->full_name"
        :subtitle="'OSCA ID: ' . $senior->osca_id . ' · ' . $senior->barangay"
    />

    {{-- Top action bar --}}
    <div class="flex items-center gap-3 flex-wrap">
        <a href="{{ route('seniors.index') }}" class="btn btn-ghost gap-1.5 pl-1.5">
            <x-heroicon-o-arrow-left class="w-3.5 h-3.5" /> Back to records
        </a>
        <div class="ml-auto flex flex-wrap gap-2">
            <a href="{{ route('surveys.qol.create', $senior) }}" class="btn">
                <x-heroicon-o-clipboard-document-list class="w-3.5 h-3.5" /> New QoL Survey
            </a>

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
                <div class="font-serif text-[20px] font-semibold text-ink-900 leading-tight">{{ $senior->full_name }}</div>
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
    <div class="card">
        <div class="card-body">
            <div class="flex flex-wrap gap-x-8 gap-y-4 items-start">

                {{-- Overall Risk + Cluster --}}
                <div class="flex gap-6 flex-shrink-0">
                    <div>
                        <div class="eyebrow mb-2">Overall Risk</div>
                        <x-risk-badge :level="$ml->overall_risk_level" />
                        @if ($ml->priority_flag === 'urgent')
                            <span class="mt-1.5 inline-flex items-center gap-1 text-[11px] font-semibold text-orange-700 bg-orange-50 px-2 py-0.5 rounded-full">⚠ Urgent</span>
                        @endif
                    </div>
                    <div>
                        <div class="eyebrow mb-2">Cluster</div>
                        <x-cluster-badge :id="$ml->cluster_named_id" :label="$ml->cluster_name" />
                    </div>
                </div>

                {{-- Domain bars --}}
                <div class="flex gap-6 flex-1 min-w-0">
                    @foreach ([
                        ['Physical Capacity', $ml->ic_risk],
                        ['Environment',       $ml->env_risk],
                        ['Daily Functioning', $ml->func_risk],
                    ] as [$label, $score])
                    <div class="flex-1 min-w-[90px]">
                        <div class="eyebrow mb-2">{{ $label }}</div>
                        <x-risk-bar :value="$score" />
                    </div>
                    @endforeach
                </div>

                {{-- Wellbeing + Prediction Source --}}
                <div class="text-right flex-shrink-0">
                    <div class="eyebrow mb-1">Wellbeing</div>
                    <div class="font-serif text-3xl font-semibold tnum">
                        {{ number_format($ml->wellbeing_score * 100, 0) }}<span class="text-sm text-ink-400">/100</span>
                    </div>
                    <div class="text-[11px] text-ink-400 mt-1">
                        Scored {{ ($ml->scored_at ?? $ml->processed_at)?->diffForHumans() }}
                    </div>
                    <div class="mt-1.5 flex items-center justify-end gap-1 flex-wrap">
                        <span class="inline-flex items-center gap-1 text-[10px] font-semibold px-1.5 py-0.5 rounded {{ $ml->prediction_source_color }}">
                            {{ $ml->prediction_source_label }}
                        </span>
                        <span class="text-[10px] text-ink-400 font-mono">{{ $ml->model_version }}</span>
                    </div>
                </div>

            </div>

            {{-- Decision-support disclaimer (gov-ready wording) --}}
            <p class="mt-4 pt-3 border-t border-paper-rule dark:border-[#2b3530] text-[11.5px] text-ink-400 dark:text-[#6b7570] leading-relaxed">
                These are <strong class="font-semibold text-ink-500 dark:text-[#8a958f]">decision-support indicators</strong> showing a possible risk level and profile group — not a clinical diagnosis. Use them alongside professional assessment and OSCA case knowledge.
            </p>
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
            'ic'   => ['label' => 'Physical Capacity',   'risk' => $ml->ic_risk_level],
            'env'  => ['label' => 'Environment',          'risk' => $ml->env_risk_level],
            'func' => ['label' => 'Daily Functioning',    'risk' => $ml->func_risk_level],
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

        <div class="lg:col-span-2 space-y-5">

            <x-card title="I. Identifying Information">
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
            </x-card>

            <x-card title="II. Family Composition">
                <div class="grid grid-cols-2 gap-x-8 gap-y-3 text-sm">
                    <x-profile-field label="No. of Children"         :value="$senior->num_children"/>
                    <x-profile-field label="Working Children"        :value="$senior->num_working_children"/>
                    <x-profile-field label="Child Financial Support" :value="$senior->child_financial_support"/>
                    <x-profile-field label="Spouse/Partner Working"  :value="$senior->spouse_working"/>
                    <x-profile-field label="Household Size"          :value="$senior->household_size . ' persons'"/>
                </div>
            </x-card>

            <x-card title="III. Education / HR Profile">
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
            </x-card>

            <x-card title="IV. Dependency Profile">
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
            </x-card>

            <x-card title="V. Economic Profile">
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
            </x-card>

            <x-card title="VI. Health Profile">
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
            </x-card>

            @if ($senior->qolSurveys->isNotEmpty())
            <x-card title="QoL Survey History">
                <x-slot name="actions">
                    <a href="{{ route('surveys.qol.create', $senior) }}" class="text-xs text-forest-700 font-semibold hover:text-forest-900">+ New survey</a>
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
        </div>

        {{-- Right: Recommendations (collapsible) + Section Scores --}}
        <div class="space-y-5">

            <x-card title="Recommendations">
                <x-slot name="actions">
                    <span class="text-[11px] text-ink-500 tnum">{{ $ml?->recommendations->count() ?? 0 }} total</span>
                </x-slot>
                <x-slot name="noPadding">true</x-slot>

                @php
                $categories = [
                    'health'     => ['label' => 'Health',     'icon' => '🏥'],
                    'financial'  => ['label' => 'Financial',  'icon' => '💰'],
                    'social'     => ['label' => 'Social',     'icon' => '🤝'],
                    'functional' => ['label' => 'Functional', 'icon' => '🧠'],
                    'hc_access'  => ['label' => 'HC Access',  'icon' => '🏨'],
                    'sensory'    => ['label' => 'Sensory',    'icon' => '👁'],
                    'general'    => ['label' => 'General',    'icon' => '📋'],
                ];
                $grouped = $ml?->recommendations->groupBy('category') ?? collect();
                @endphp

                @forelse ($grouped as $cat => $recs)
                @php
                    $hasUrgent = $recs->where('urgency', 'urgent')->isNotEmpty();
                    $catInfo   = $categories[$cat] ?? ['label' => ucfirst($cat), 'icon' => '•'];
                @endphp
                <div x-data="{ open: {{ $loop->first ? 'true' : 'false' }} }"
                     class="border-b border-paper-rule last:border-b-0">
                    {{-- Collapsible category header --}}
                    <button @click="open = !open"
                            class="w-full flex items-center justify-between px-5 py-2.5 text-left bg-paper-2 dark:bg-[#1a201d] hover:bg-forest-50/60 dark:hover:bg-forest-900/10 transition-colors">
                        <div class="flex items-center gap-2">
                            <span class="text-base leading-none">{{ $catInfo['icon'] }}</span>
                            <span class="eyebrow">{{ $catInfo['label'] }}</span>
                            @if ($hasUrgent)
                                <span class="inline-flex items-center gap-0.5 text-[10px] font-bold text-orange-700 bg-orange-100 px-1.5 py-0.5 rounded-full">⚠ urgent</span>
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

            @if ($ml?->section_scores)
            <x-card title="Section Scores">
                <div class="space-y-3">
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
                            <span class="font-mono font-semibold text-ink-900 tnum">{{ number_format($val * 100, 0) }}%</span>
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
    </div>
</div>
@endsection
