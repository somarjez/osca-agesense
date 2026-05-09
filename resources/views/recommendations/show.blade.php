@extends('layouts.app')
@section('page-title', 'Recommendations')
@section('page-subtitle', $senior->full_name . ' · ' . $senior->barangay)

@section('content')
<div class="space-y-5">
    <a href="{{ route('seniors.show', $senior) }}" class="btn btn-ghost gap-1.5 pl-1.5 w-fit">
        <x-heroicon-o-arrow-left class="w-3.5 h-3.5" /> Back to profile
    </a>

    @php
    $grouped = $recommendations->groupBy('category');
    $catLabels = [
        'health'     => 'Health',
        'financial'  => 'Financial',
        'social'     => 'Social',
        'functional' => 'Functional',
        'hc_access'  => 'Healthcare Access',
        'general'    => 'General',
    ];
    @endphp

    @forelse ($grouped as $category => $recs)
    <div class="card overflow-hidden">
        <div class="card-head">
            <div class="card-title">{{ $catLabels[$category] ?? ucwords(str_replace('_',' ', $category)) }}</div>
            <span class="badge badge-neutral">{{ $recs->count() }} action{{ $recs->count() !== 1 ? 's' : '' }}</span>
        </div>
        <div class="divide-y divide-paper-rule">
            @foreach ($recs->sortBy('priority') as $rec)
            <div class="px-5 py-4 flex items-start gap-3">
                <span class="flex-shrink-0 w-6 h-6 rounded-md grid place-items-center text-[11px] font-bold mt-0.5
                    {{ match($rec->urgency) {
                        'urgent'  => 'bg-high-100 text-high-700',
                        'planned' => 'bg-info-100 text-info-700',
                        default   => 'bg-paper-2 text-ink-500',
                    } }}">{{ $rec->priority }}</span>
                <div class="flex-1">
                    <p class="text-[13px] text-ink-800">{{ $rec->action }}</p>
                    <div class="flex items-center gap-3 mt-1.5 flex-wrap">
                        @if ($rec->urgency === 'urgent')
                            <span class="badge badge-high">{{ ucfirst($rec->urgency) }}</span>
                        @elseif ($rec->urgency === 'planned')
                            <span class="badge badge-info">{{ ucfirst($rec->urgency) }}</span>
                        @else
                            <span class="badge badge-neutral">{{ ucfirst($rec->urgency ?? 'Pending') }}</span>
                        @endif
                        @if ($rec->risk_level)
                        <span class="text-[11.5px] text-ink-400">Risk: {{ strtoupper($rec->risk_level) }}</span>
                        @endif
                        @if ($rec->target_date)
                        <span class="text-[11.5px] text-ink-400">Target: {{ $rec->target_date->format('M j, Y') }}</span>
                        @endif
                    </div>
                    @if ($rec->notes)
                    <p class="text-[11.5px] text-ink-400 mt-1 italic">{{ $rec->notes }}</p>
                    @endif
                </div>
                <form method="POST" action="{{ route('recommendations.status', $rec) }}" class="flex-shrink-0">
                    @csrf @method('PATCH')
                    <select name="status" onchange="this.form.submit()" class="form-select w-auto text-[12px] py-1">
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
    <div class="card p-12 text-center">
        <x-heroicon-o-light-bulb class="w-10 h-10 text-ink-300 mx-auto mb-3" />
        <p class="font-serif text-base text-ink-500 font-medium">No recommendations yet.</p>
        <p class="text-[12.5px] text-ink-400 mt-1">Complete a QoL survey to generate tailored recommendations.</p>
        <a href="{{ route('surveys.qol.create', $senior) }}" class="btn btn-primary mt-4 inline-flex">
            <x-heroicon-o-clipboard-document-list class="w-3.5 h-3.5" /> Take QoL Survey
        </a>
    </div>
    @endforelse
</div>
@endsection
