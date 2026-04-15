@extends('layouts.app')
@section('page-title', 'Batch ML Analysis')
@section('page-subtitle', 'Run KMeans clustering and risk scoring for all eligible seniors')

@section('content')
<div class="space-y-5">

    {{-- Run form --}}
    <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm flex items-center gap-5">
        <div class="text-3xl">🔄</div>
        <div class="flex-1">
            <p class="font-semibold text-slate-800">Run Full Batch Analysis</p>
            <p class="text-sm text-slate-500">Processes all seniors with a submitted QoL survey through the full pipeline: preprocess → cluster → risk score → recommendations.</p>
        </div>
        <form method="POST" action="{{ route('ml.batch.run') }}">
            @csrf
            <button type="submit"
                    onclick="return confirm('Run batch ML analysis for {{ $pending->count() }} senior(s)?')"
                    class="px-5 py-2 bg-teal-600 text-white font-semibold text-sm rounded-lg hover:bg-teal-700 shadow-sm">
                Run Batch ({{ $pending->count() }})
            </button>
        </form>
    </div>

    {{-- Queue table --}}
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-700">Eligible Seniors (have QoL survey)</h3>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-slate-50 border-b border-slate-200 text-xs font-semibold text-slate-500 uppercase tracking-wider">
                <tr>
                    <th class="px-4 py-3 text-left">Senior</th>
                    <th class="px-4 py-3 text-left">Barangay</th>
                    <th class="px-4 py-3 text-center">Last Survey</th>
                    <th class="px-4 py-3 text-center">Last ML Run</th>
                    <th class="px-4 py-3 text-center">Current Risk</th>
                    <th class="px-4 py-3 text-center">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse ($pending as $senior)
                @php $ml = $senior->latestMlResult; $survey = $senior->latestQolSurvey; @endphp
                <tr class="hover:bg-slate-25">
                    <td class="px-4 py-3 font-medium text-slate-800">{{ $senior->full_name }}</td>
                    <td class="px-4 py-3 text-slate-500">{{ $senior->barangay }}</td>
                    <td class="px-4 py-3 text-center text-xs text-slate-500">
                        {{ $survey?->survey_date?->format('M j, Y') ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-center text-xs text-slate-500">
                        {{ $ml?->processed_at?->diffForHumans() ?? '<span class="text-amber-500">Never</span>' }}
                    </td>
                    <td class="px-4 py-3 text-center">
                        @if ($ml)
                        <span class="text-xs font-bold px-2 py-0.5 rounded-full
                            {{ match($ml->overall_risk_level) {
                                'CRITICAL'=>'bg-red-100 text-red-700',
                                'HIGH'=>'bg-orange-100 text-orange-700',
                                'MODERATE'=>'bg-amber-100 text-amber-700',
                                default=>'bg-emerald-100 text-emerald-700',
                            } }}">{{ $ml->overall_risk_level }}</span>
                        @else
                        <span class="text-xs text-slate-300">No data</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-center">
                        <form method="POST" action="{{ route('ml.run.single', $senior) }}">
                            @csrf
                            <button type="submit"
                                    class="text-xs px-3 py-1 bg-teal-50 text-teal-700 rounded-md hover:bg-teal-100 font-medium transition-colors">
                                Re-run
                            </button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-12 text-center text-slate-400">
                        <div class="text-3xl mb-2">✅</div>
                        <p>No seniors with QoL surveys found.</p>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
