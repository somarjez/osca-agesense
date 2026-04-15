@extends('layouts.app')
@section('page-title', 'Recommendations')
@section('page-subtitle', $senior->full_name . ' · ' . $senior->barangay)

@section('content')
<div class="space-y-5">
    <a href="{{ route('seniors.show', $senior) }}" class="text-sm text-slate-500 hover:text-slate-700">← Back to profile</a>

    @php
    $grouped = $recommendations->groupBy('category');
    $catIcons = ['health'=>'🏥','financial'=>'💰','social'=>'🤝','functional'=>'🦾','hc_access'=>'💊','general'=>'📋'];
    @endphp

    @forelse ($grouped as $category => $recs)
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center gap-2">
            <span class="text-lg">{{ $catIcons[$category] ?? '📌' }}</span>
            <h3 class="font-semibold text-slate-700 capitalize">{{ str_replace('_',' ', $category) }}</h3>
            <span class="ml-auto text-xs text-slate-400">{{ $recs->count() }} action(s)</span>
        </div>
        <div class="divide-y divide-slate-50">
            @foreach ($recs->sortBy('priority') as $rec)
            <div class="px-5 py-4 flex items-start gap-3">
                <span class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold mt-0.5
                    {{ match($rec->urgency) {
                        'immediate' => 'bg-red-500 text-white',
                        'urgent'    => 'bg-orange-400 text-white',
                        'planned'   => 'bg-blue-400 text-white',
                        default     => 'bg-slate-200 text-slate-600',
                    } }}">{{ $rec->priority }}</span>
                <div class="flex-1">
                    <p class="text-sm text-slate-800">{{ $rec->action }}</p>
                    <div class="flex items-center gap-3 mt-1.5 flex-wrap">
                        <span class="{{ $rec->urgency_badge }} text-xs px-2 py-0.5 rounded-full font-medium">
                            {{ ucfirst($rec->urgency) }}
                        </span>
                        @if ($rec->risk_level)
                        <span class="text-xs text-slate-400">Risk: {{ strtoupper($rec->risk_level) }}</span>
                        @endif
                        @if ($rec->target_date)
                        <span class="text-xs text-slate-400">Target: {{ $rec->target_date->format('M j, Y') }}</span>
                        @endif
                    </div>
                    @if ($rec->notes)
                    <p class="text-xs text-slate-500 mt-1 italic">{{ $rec->notes }}</p>
                    @endif
                </div>
                <form method="POST" action="{{ route('recommendations.status', $rec) }}" class="flex-shrink-0">
                    @csrf @method('PATCH')
                    <select name="status" onchange="this.form.submit()"
                            class="text-xs border border-slate-200 rounded-lg px-2 py-1 bg-white focus:ring-2 focus:ring-teal-500">
                        @foreach (['pending','in_progress','completed','dismissed'] as $s)
                            <option value="{{ $s }}" {{ $rec->status===$s?'selected':'' }}>
                                {{ ucwords(str_replace('_',' ',$s)) }}
                            </option>
                        @endforeach
                    </select>
                </form>
            </div>
            @endforeach
        </div>
    </div>
    @empty
    <div class="bg-white border border-slate-200 rounded-xl p-12 text-center shadow-sm">
        <div class="text-3xl mb-2">💡</div>
        <p class="text-slate-500 font-medium">No recommendations yet.</p>
        <a href="{{ route('surveys.qol.create', $senior) }}"
           class="inline-block mt-3 px-4 py-2 bg-purple-600 text-white text-sm font-medium rounded-lg hover:bg-purple-700">
            Take QoL Survey to generate recommendations →
        </a>
    </div>
    @endforelse
</div>
@endsection
