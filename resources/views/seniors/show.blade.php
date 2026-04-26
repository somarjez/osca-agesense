@extends('layouts.app')
@section('page-title', $senior->full_name)
@section('page-subtitle', 'OSCA ID: ' . $senior->osca_id . ' · ' . $senior->barangay)

@section('content')
@php $ml = $senior->latestMlResult; @endphp
<div class="space-y-6">

    {{-- Top action bar --}}
    <div class="flex items-center gap-3 flex-wrap">
        <a href="{{ route('seniors.index') }}" class="text-sm text-ink-500 hover:text-ink-900 inline-flex items-center gap-1.5">
            <span>←</span> Back to records
        </a>
        <div class="ml-auto flex flex-wrap gap-2">
            <a href="{{ route('surveys.qol.create', $senior) }}" class="btn">+ New QoL Survey</a>

            <div x-data="{
                    loading: false, done: false, err: '',
                    run() {
                        this.loading = true; this.err = '';
                        fetch('{{ route('ml.run.single', $senior) }}', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
                        })
                        .then(r => r.json())
                        .then(d => {
                            if (d.success) { this.done = true; setTimeout(() => location.reload(), 1200); }
                            else { this.err = d.error || 'Analysis failed.'; this.loading = false; }
                        })
                        .catch(() => { this.err = 'Request failed. Is the ML service running?'; this.loading = false; });
                    }
                }">
                <button @click="run()" :disabled="loading || done" class="btn btn-primary disabled:opacity-60 disabled:cursor-not-allowed">
                    <template x-if="loading"><span>Running…</span></template>
                    <template x-if="done"><span>✓ Refreshing…</span></template>
                    <template x-if="!loading && !done"><span>Re-run ML Analysis</span></template>
                </button>
                <p x-show="err" x-text="err" x-cloak class="text-xs text-critical-700 mt-1 text-right"></p>
            </div>

            <a href="{{ route('seniors.export', $senior) }}" class="btn">Export PDF</a>
            <a href="{{ route('seniors.edit', $senior) }}" class="btn">Edit</a>
        </div>
    </div>

    {{-- ── ML Result Banner ── --}}
    @if ($ml)
    <div class="card">
        <div class="card-body">
            <div class="grid grid-cols-2 lg:grid-cols-6 gap-6 items-start">
                <div>
                    <div class="eyebrow mb-2">Overall Risk</div>
                    <x-risk-badge :level="$ml->overall_risk_level" />
                </div>
                <div>
                    <div class="eyebrow mb-2">Cluster</div>
                    <x-cluster-badge :id="$ml->cluster_named_id" :label="$ml->cluster_name" />
                </div>
                @foreach ([
                    ['IC Risk', $ml->ic_risk, $ml->ic_risk_level],
                    ['Env Risk', $ml->env_risk, $ml->env_risk_level],
                    ['Func Risk', $ml->func_risk, $ml->func_risk_level],
                ] as [$label, $score, $level])
                <div>
                    <div class="eyebrow mb-2">{{ $label }}</div>
                    <x-risk-bar :value="$score" />
                </div>
                @endforeach
                <div class="text-right">
                    <div class="eyebrow mb-1">Wellbeing</div>
                    <div class="font-serif text-3xl font-semibold tnum">
                        {{ number_format($ml->wellbeing_score * 100, 0) }}<span class="text-sm text-ink-400">/100</span>
                    </div>
                    <div class="text-[11px] text-ink-400 mt-1">Analyzed {{ $ml->processed_at?->diffForHumans() }}</div>
                </div>
            </div>
        </div>
    </div>
    @else
    <div class="card border-l-[3px] border-l-moderate-500">
        <div class="card-body text-sm text-ink-700">
            No ML analysis yet. Complete a QoL survey and run the analysis to see risk scores and recommendations.
        </div>
    </div>
    @endif

    {{-- ── Profile + Recommendations ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        <div class="lg:col-span-2 space-y-5">

            <x-card title="I. Identifying Information">
                <div class="grid grid-cols-2 gap-x-8 gap-y-3 text-sm">
                    <x-profile-field label="Full Name"      :value="$senior->full_name"/>
                    <x-profile-field label="OSCA ID"        :value="$senior->osca_id"/>
                    <x-profile-field label="Date of Birth"  :value="$senior->date_of_birth?->format('F j, Y')"/>
                    <x-profile-field label="Age"            :value="$senior->age . ' years old'"/>
                    <x-profile-field label="Barangay"       :value="$senior->barangay"/>
                    <x-profile-field label="Gender"         :value="$senior->gender"/>
                    <x-profile-field label="Marital Status" :value="$senior->marital_status"/>
                    <x-profile-field label="Religion"       :value="$senior->religion"/>
                    <x-profile-field label="Blood Type"     :value="$senior->blood_type"/>
                    <x-profile-field label="Contact"        :value="$senior->contact_number"/>
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

            <x-card title="V. Economic Profile">
                <div class="space-y-3 text-sm">
                    <x-profile-field label="Monthly Income" :value="$senior->monthly_income_range"/>
                    <div>
                        <span class="eyebrow">Income Sources</span>
                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                            @foreach ($senior->income_source ?? [] as $src)
                                <span class="badge badge-info">{{ $src }}</span>
                            @endforeach
                        </div>
                    </div>
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
                        ['Dental Concern',                $senior->dental_concern ?? []],
                        ['Optical / Vision',              $senior->optical_concern ?? []],
                        ['Hearing',                       $senior->hearing_concern ?? []],
                        ['Healthcare Access Difficulty',  $senior->healthcare_difficulty ?? []],
                    ] as [$sectionLabel, $items])
                    @if (!empty($items))
                    <div>
                        <span class="eyebrow">{{ $sectionLabel }}</span>
                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                            @foreach ($items as $item)
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
                        <tr class="group hover:bg-paper-2">
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
                                                class="btn btn-ghost text-[11px] px-2 py-0.5 text-critical-700 hover:bg-critical-50">
                                            Delete
                                        </button>
                                        <form x-ref="deleteForm" method="POST" action="{{ route('surveys.qol.destroy', $survey) }}" class="hidden">
                                            @csrf @method('DELETE')
                                        </form>
                                        <div x-show="open" x-cloak
                                             class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
                                             @keydown.escape.window="open = false">
                                            <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6"
                                                 @click.outside="open = false">
                                                <div class="flex items-start gap-3 mb-4">
                                                    <div class="w-9 h-9 rounded-full bg-red-50 flex items-center justify-center flex-shrink-0 text-lg">🗑️</div>
                                                    <div>
                                                        <h3 class="font-semibold text-slate-800">Delete QoL Survey?</h3>
                                                        <p class="text-sm text-slate-500 mt-1">
                                                            The survey from <strong>{{ $survey->survey_date?->format('M j, Y') }}</strong> and its ML results will be permanently deleted.
                                                        </p>
                                                        <p class="text-xs font-semibold text-red-600 mt-2 bg-red-50 px-3 py-1.5 rounded-lg">
                                                            ⚠ This cannot be undone.
                                                        </p>
                                                    </div>
                                                </div>
                                                <div class="flex gap-3 justify-end pt-2 border-t border-slate-100">
                                                    <button @click="open = false"
                                                            class="px-4 py-2 text-sm text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors">
                                                        Cancel
                                                    </button>
                                                    <button @click="$refs.deleteForm.submit()"
                                                            class="px-4 py-2 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700 transition-colors">
                                                        Delete Survey
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
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

        {{-- Right: Recommendations + Section Scores --}}
        <div class="space-y-5">
            <x-card title="Recommendations">
                <x-slot name="actions">
                    <span class="text-[11px] text-ink-500 tnum">{{ $ml?->recommendations->count() ?? 0 }} total</span>
                </x-slot>
                <x-slot name="noPadding">true</x-slot>

                @php
                $categories = [
                    'health'     => 'Health',
                    'financial'  => 'Financial',
                    'social'     => 'Social',
                    'functional' => 'Functional',
                    'hc_access'  => 'HC Access',
                    'general'    => 'General',
                ];
                $grouped = $ml?->recommendations->groupBy('category') ?? collect();
                @endphp

                @forelse ($grouped as $cat => $recs)
                <div class="border-b border-paper-rule last:border-b-0">
                    <div class="px-5 py-2 bg-paper-2">
                        <span class="eyebrow">{{ $categories[$cat] ?? ucfirst($cat) }}</span>
                    </div>
                    <ul class="divide-y divide-paper-rule">
                        @foreach ($recs->sortBy('priority') as $rec)
                        <li class="px-5 py-3">
                            <div class="flex items-start gap-3">
                                <span class="flex-shrink-0 w-6 h-6 rounded-md grid place-items-center mt-0.5 text-[11px] font-bold tnum
                                    {{ match($rec->urgency) {
                                        'immediate' => 'bg-critical-100 text-critical-700',
                                        'urgent'    => 'bg-high-100 text-high-700',
                                        'planned'   => 'bg-info-100 text-info-700',
                                        default     => 'bg-paper-2 text-ink-500',
                                    } }}">P{{ $rec->priority }}</span>
                                <div class="flex-1">
                                    <p class="text-[13px] text-ink-900 leading-relaxed">{{ $rec->action }}</p>
                                    <div class="flex items-center gap-2 mt-1.5">
                                        <span class="badge {{ match($rec->urgency) {
                                            'immediate' => 'badge-critical',
                                            'urgent'    => 'badge-high',
                                            'planned'   => 'badge-info',
                                            default     => 'badge-neutral',
                                        } }}">{{ ucfirst($rec->urgency) }}</span>
                                        <span class="text-[11px] text-ink-400">{{ ucfirst($rec->status) }}</span>
                                    </div>
                                </div>
                            </div>
                        </li>
                        @endforeach
                    </ul>
                </div>
                @empty
                <div class="p-8 text-center text-sm text-ink-400">No recommendations yet. Run ML analysis first.</div>
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
