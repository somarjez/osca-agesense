{{-- resources/views/seniors/show.blade.php --}}
@extends('layouts.app')
@section('page-title', $senior->full_name)
@section('page-subtitle', 'OSCA ID: ' . $senior->osca_id . ' · ' . $senior->barangay)

@section('content')
@php $ml = $senior->latestMlResult; @endphp
<div class="space-y-5">

    {{-- ── Top Action Bar ── --}}
    <div class="flex items-center gap-3 flex-wrap">
        <a href="{{ route('seniors.index') }}"
           class="text-sm text-slate-500 hover:text-slate-700 flex items-center gap-1">
            ← Back to records
        </a>
        <div class="ml-auto flex gap-2">
            <a href="{{ route('surveys.qol.create', $senior) }}"
               class="px-4 py-2 text-sm font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors shadow-sm">
                📋 New QoL Survey
            </a>
            <form method="POST" action="{{ route('ml.run.single', $senior) }}">
                @csrf
                <button type="submit"
                        class="px-4 py-2 text-sm font-medium bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition-colors shadow-sm">
                    🤖 Re-run ML Analysis
                </button>
            </form>
            <a href="{{ route('seniors.export', $senior) }}"
               class="px-4 py-2 text-sm font-medium border border-slate-200 text-slate-600 rounded-lg hover:bg-slate-50 transition-colors">
                📄 Export PDF
            </a>
            <a href="{{ route('seniors.edit', $senior) }}"
               class="px-4 py-2 text-sm font-medium border border-slate-200 text-slate-600 rounded-lg hover:bg-slate-50 transition-colors">
                ✏️ Edit Profile
            </a>
        </div>
    </div>

    {{-- ── ML Result Banner ── --}}
    @if ($ml)
    <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
        <div class="flex items-start gap-5 flex-wrap">
            {{-- Risk Level --}}
            <div class="text-center">
                <div class="text-xs text-slate-500 mb-1 uppercase tracking-wider font-medium">Overall Risk</div>
                <span class="inline-block text-lg font-bold px-4 py-2 rounded-xl
                    {{ match($ml->overall_risk_level) {
                        'CRITICAL' => 'bg-red-100 text-red-700 border border-red-200',
                        'HIGH'     => 'bg-orange-100 text-orange-700 border border-orange-200',
                        'MODERATE' => 'bg-amber-100 text-amber-700 border border-amber-200',
                        'LOW'      => 'bg-emerald-100 text-emerald-700 border border-emerald-200',
                        default    => 'bg-slate-100 text-slate-600',
                    } }}">
                    {{ $ml->overall_risk_level }}
                </span>
            </div>

            {{-- Cluster --}}
            <div class="text-center">
                <div class="text-xs text-slate-500 mb-1 uppercase tracking-wider font-medium">Cluster</div>
                <span class="inline-block text-sm font-semibold px-4 py-2 rounded-xl
                    {{ match($ml->cluster_named_id) {
                        1 => 'bg-emerald-100 text-emerald-700 border border-emerald-200',
                        2 => 'bg-amber-100 text-amber-700 border border-amber-200',
                        3 => 'bg-rose-100 text-rose-700 border border-rose-200',
                        default => 'bg-slate-100 text-slate-600',
                    } }}">
                    C{{ $ml->cluster_named_id }}: {{ $ml->cluster_name }}
                </span>
            </div>

            {{-- Domain Risk Scores --}}
            <div class="flex gap-4 flex-wrap">
                @foreach ([
                    ['IC Risk', $ml->ic_risk, $ml->ic_risk_level],
                    ['Env Risk', $ml->env_risk, $ml->env_risk_level],
                    ['Func Risk', $ml->func_risk, $ml->func_risk_level],
                    ['Composite', $ml->composite_risk, strtolower($ml->overall_risk_level)],
                ] as [$label, $score, $level])
                <div>
                    <div class="text-xs text-slate-500 mb-1">{{ $label }}</div>
                    <div class="flex items-center gap-1.5">
                        <div class="w-16 bg-slate-200 rounded-full h-2">
                            <div class="h-2 rounded-full {{ in_array($level, ['critical','high']) ? 'bg-red-500' : (in_array($level, ['moderate']) ? 'bg-amber-400' : 'bg-emerald-500') }}"
                                 style="width: {{ $score * 100 }}%"></div>
                        </div>
                        <span class="text-sm font-semibold text-slate-700">{{ number_format($score, 2) }}</span>
                    </div>
                </div>
                @endforeach
            </div>

            {{-- Wellbeing --}}
            <div class="ml-auto text-right">
                <div class="text-xs text-slate-500 mb-1">Wellbeing Score</div>
                <div class="text-2xl font-bold text-slate-800">{{ number_format($ml->wellbeing_score * 100, 0) }}<span class="text-sm text-slate-400">/100</span></div>
                <div class="text-xs text-slate-400 mt-0.5">Analyzed {{ $ml->processed_at?->diffForHumans() }}</div>
            </div>
        </div>
    </div>
    @else
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-700">
        No ML analysis yet. Complete a QoL survey and run the analysis to see risk scores and recommendations.
    </div>
    @endif

    {{-- ── Profile + Recommendations Grid ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        {{-- Left: Profile Summary --}}
        <div class="lg:col-span-2 space-y-4">

            {{-- Identifying Information --}}
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
                <div class="bg-slate-50 border-b border-slate-100 px-5 py-3 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-slate-700">I. Identifying Information</h3>
                </div>
                <div class="p-5 grid grid-cols-2 gap-x-8 gap-y-3 text-sm">
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
            </div>

            {{-- Family Composition --}}
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
                <div class="bg-slate-50 border-b border-slate-100 px-5 py-3">
                    <h3 class="text-sm font-semibold text-slate-700">II. Family Composition</h3>
                </div>
                <div class="p-5 grid grid-cols-2 gap-x-8 gap-y-3 text-sm">
                    <x-profile-field label="No. of Children"         :value="$senior->num_children"/>
                    <x-profile-field label="Working Children"        :value="$senior->num_working_children"/>
                    <x-profile-field label="Child Financial Support" :value="$senior->child_financial_support"/>
                    <x-profile-field label="Spouse/Partner Working"  :value="$senior->spouse_working"/>
                    <x-profile-field label="Household Size"          :value="$senior->household_size . ' persons'"/>
                </div>
            </div>

            {{-- Economic Profile --}}
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
                <div class="bg-slate-50 border-b border-slate-100 px-5 py-3">
                    <h3 class="text-sm font-semibold text-slate-700">V. Economic Profile</h3>
                </div>
                <div class="p-5 space-y-3 text-sm">
                    <x-profile-field label="Monthly Income" :value="$senior->monthly_income_range"/>
                    <div>
                        <span class="text-xs font-medium text-slate-500 uppercase">Income Sources</span>
                        <div class="mt-1 flex flex-wrap gap-1">
                            @foreach ($senior->income_source ?? [] as $src)
                            <span class="text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded-full">{{ $src }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            {{-- Health Profile --}}
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
                <div class="bg-slate-50 border-b border-slate-100 px-5 py-3">
                    <h3 class="text-sm font-semibold text-slate-700">VI. Health Profile</h3>
                </div>
                <div class="p-5 space-y-3 text-sm">
                    <div>
                        <span class="text-xs font-medium text-slate-500 uppercase">Medical Concerns</span>
                        <div class="mt-1 flex flex-wrap gap-1">
                            @forelse ($senior->medical_concern ?? [] as $concern)
                            <span class="text-xs {{ $concern === 'Physically Healthy' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' }} px-2 py-0.5 rounded-full">
                                {{ $concern }}
                            </span>
                            @empty
                            <span class="text-slate-400 text-xs">None reported</span>
                            @endforelse
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <x-profile-field label="Dental"  :value="$senior->dental_concern"/>
                        <x-profile-field label="Optical" :value="$senior->optical_concern"/>
                        <x-profile-field label="Hearing" :value="$senior->hearing_concern"/>
                        <x-profile-field label="Medical Checkup" :value="$senior->has_medical_checkup ? 'Yes' : 'No'"/>
                    </div>
                </div>
            </div>

            {{-- QoL Survey History --}}
            @if ($senior->qolSurveys->isNotEmpty())
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
                <div class="bg-slate-50 border-b border-slate-100 px-5 py-3 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-slate-700">QoL Survey History</h3>
                    <a href="{{ route('surveys.qol.create', $senior) }}" class="text-xs text-teal-600 hover:underline">+ New Survey</a>
                </div>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-100">
                        <tr class="text-xs text-slate-400">
                            <th class="px-5 py-2 text-left font-medium">Date</th>
                            <th class="px-5 py-2 text-left font-medium">Overall</th>
                            <th class="px-5 py-2 text-left font-medium">Physical</th>
                            <th class="px-5 py-2 text-left font-medium">Psychological</th>
                            <th class="px-5 py-2 text-left font-medium">Social</th>
                            <th class="px-5 py-2 text-left font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach ($senior->qolSurveys as $survey)
                        <tr class="hover:bg-slate-25">
                            <td class="px-5 py-2 text-slate-600">{{ $survey->survey_date?->format('M j, Y') }}</td>
                            <td class="px-5 py-2 font-semibold text-slate-800">{{ $survey->overall_score ? number_format($survey->overall_score * 100, 0) . '%' : '—' }}</td>
                            <td class="px-5 py-2 text-slate-600">{{ $survey->score_physical ? number_format($survey->score_physical * 100, 0) . '%' : '—' }}</td>
                            <td class="px-5 py-2 text-slate-600">{{ $survey->score_psychological ? number_format($survey->score_psychological * 100, 0) . '%' : '—' }}</td>
                            <td class="px-5 py-2 text-slate-600">{{ $survey->score_social ? number_format($survey->score_social * 100, 0) . '%' : '—' }}</td>
                            <td class="px-5 py-2">
                                <span class="text-xs px-2 py-0.5 rounded-full
                                    {{ $survey->status === 'processed' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                    {{ ucfirst($survey->status) }}
                                </span>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif

        </div>

        {{-- Right: Recommendations --}}
        <div class="space-y-4">
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
                <div class="bg-slate-50 border-b border-slate-100 px-5 py-3 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-slate-700">Recommendations</h3>
                    <span class="text-xs text-slate-400">{{ $ml?->recommendations->count() ?? 0 }} total</span>
                </div>

                @php
                $categories = [
                    'health'     => ['label' => '🏥 Health', 'color' => 'red'],
                    'financial'  => ['label' => '💰 Financial', 'color' => 'amber'],
                    'social'     => ['label' => '👥 Social', 'color' => 'blue'],
                    'functional' => ['label' => '🦾 Functional', 'color' => 'purple'],
                    'hc_access'  => ['label' => '🏨 HC Access', 'color' => 'teal'],
                    'general'    => ['label' => '📌 General', 'color' => 'slate'],
                ];
                $grouped = $ml?->recommendations->groupBy('category') ?? collect();
                @endphp

                @forelse ($grouped as $cat => $recs)
                @php $catInfo = $categories[$cat] ?? ['label' => ucfirst($cat), 'color' => 'slate']; @endphp
                <div class="border-b border-slate-100 last:border-b-0">
                    <div class="px-5 py-2 bg-slate-25">
                        <span class="text-xs font-semibold text-slate-600">{{ $catInfo['label'] }}</span>
                    </div>
                    <ul class="divide-y divide-slate-50">
                        @foreach ($recs->sortBy('priority') as $rec)
                        <li class="px-5 py-3">
                            <div class="flex items-start gap-2">
                                <span class="flex-shrink-0 w-5 h-5 rounded-full text-xs font-bold flex items-center justify-center mt-0.5
                                    {{ match($rec->urgency) {
                                        'immediate' => 'bg-red-500 text-white',
                                        'urgent'    => 'bg-orange-400 text-white',
                                        'planned'   => 'bg-blue-400 text-white',
                                        default     => 'bg-slate-300 text-slate-700',
                                    } }}">{{ $rec->priority }}</span>
                                <div class="flex-1">
                                    <p class="text-xs text-slate-700 leading-relaxed">{{ $rec->action }}</p>
                                    <div class="flex items-center gap-2 mt-1">
                                        <span class="{{ $rec->urgency_badge }} text-xs px-1.5 py-0.5 rounded-full">
                                            {{ ucfirst($rec->urgency) }}
                                        </span>
                                        <span class="text-xs text-slate-400">{{ ucfirst($rec->status) }}</span>
                                    </div>
                                </div>
                            </div>
                        </li>
                        @endforeach
                    </ul>
                </div>
                @empty
                <div class="p-8 text-center text-sm text-slate-400">
                    No recommendations yet. Run ML analysis first.
                </div>
                @endforelse
            </div>

            {{-- Section Scores --}}
            @if ($ml?->section_scores)
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
                <div class="bg-slate-50 border-b border-slate-100 px-5 py-3">
                    <h3 class="text-sm font-semibold text-slate-700">Section Scores</h3>
                </div>
                <div class="p-4 space-y-2">
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
                        <div class="flex justify-between text-xs mb-1">
                            <span class="text-slate-500">{{ $label }}</span>
                            <span class="font-medium text-slate-700">{{ number_format($val * 100, 0) }}%</span>
                        </div>
                        <div class="w-full bg-slate-100 rounded-full h-1.5">
                            <div class="h-1.5 rounded-full {{ in_array($key, ['sec1_age_risk','sec4_dependency_risk']) ? 'bg-red-400' : 'bg-teal-500' }}"
                                 style="width: {{ $val * 100 }}%"></div>
                        </div>
                    </div>
                    @endif
                    @endforeach
                </div>
            </div>
            @endif
        </div>
    </div>
</div>
@endsection
