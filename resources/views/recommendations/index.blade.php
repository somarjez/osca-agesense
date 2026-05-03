{{-- resources/views/recommendations/index.blade.php --}}
@extends('layouts.app')
@section('page-title', 'Recommendations Management')
@section('page-subtitle', 'Navigate by senior citizen to view and update care action items')

@section('content')
<div class="space-y-5">

    {{-- ── Summary Stats ── --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        @php
        $statCards = [
            ['Seniors with Recs',  $stats['seniors'],   'teal',  '<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-teal-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-5.356-3.712M9 20H4v-2a4 4 0 015.356-3.712M15 7a4 4 0 11-8 0 4 4 0 018 0zm6 3a3 3 0 11-6 0 3 3 0 016 0zM3 10a3 3 0 116 0 3 3 0 01-6 0z"/></svg>'],
            ['Total Actions',      $stats['total'],     'slate', '<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>'],
            ['Pending Actions',    $stats['pending'],   'amber', '<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'],
            ['Immediate / Urgent', $stats['immediate'], 'red',   '<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>'],
        ];
        @endphp
        @foreach ($statCards as [$label, $val, $color, $icon])
        <div class="bg-white border border-slate-200 rounded-xl px-4 py-3 shadow-sm flex items-center gap-3">
            <span>{!! $icon !!}</span>
            <div>
                <p class="text-2xl font-bold text-{{ $color }}-600 leading-none">{{ number_format($val) }}</p>
                <p class="text-xs text-slate-500 mt-0.5">{{ $label }}</p>
            </div>
        </div>
        @endforeach
    </div>

    {{-- ── Filters ── --}}
    <form method="GET" class="bg-white border border-slate-200 rounded-xl px-5 py-4 shadow-sm flex flex-wrap items-end gap-4">
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">Barangay</label>
            <select name="barangay" class="text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
                <option value="">All Barangays</option>
                @foreach ($barangays as $brgy)
                <option value="{{ $brgy }}" {{ request('barangay') === $brgy ? 'selected' : '' }}>{{ $brgy }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">Risk Level</label>
            <select name="risk" class="text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
                <option value="">All Levels</option>
                @foreach (['CRITICAL','HIGH','MODERATE','LOW'] as $r)
                <option value="{{ $r }}" {{ strtoupper(request('risk')) === $r ? 'selected' : '' }}>{{ $r }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-center gap-2 mt-4">
            <input type="checkbox" name="has_urgent" value="1" id="has_urgent"
                   class="rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                   {{ request('has_urgent') ? 'checked' : '' }}>
            <label for="has_urgent" class="text-sm text-slate-600 cursor-pointer">Has pending urgent actions</label>
        </div>
        <div class="flex gap-2">
            <button type="submit"
                    class="px-4 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700 shadow-sm">
                Filter
            </button>
            @if (request()->hasAny(['barangay','risk','has_urgent']))
            <a href="{{ route('recommendations.index') }}"
               class="px-4 py-2 border border-slate-200 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-50">
                Clear
            </a>
            @endif
        </div>
    </form>

    {{-- ── Seniors Table ── --}}
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">

        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-700">Seniors with Recommendations</h3>
            <span class="text-xs text-slate-400">{{ $seniors->total() }} senior(s)</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr class="text-xs text-slate-500 font-semibold uppercase tracking-wider">
                        <th class="px-4 py-3 text-left">Senior Citizen</th>
                        <th class="px-4 py-3 text-left">Barangay</th>
                        <th class="px-4 py-3 text-center">Risk</th>
                        <th class="px-4 py-3 text-center">Cluster</th>
                        <th class="px-4 py-3 text-center">Total</th>
                        <th class="px-4 py-3 text-center">Pending</th>
                        <th class="px-4 py-3 text-center">Urgent / Immediate</th>
                        <th class="px-4 py-3 text-center">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse ($seniors as $senior)
                    @php $ml = $senior->latestMlResult; @endphp
                    <tr class="hover:bg-slate-25 transition-colors group">

                        {{-- Name --}}
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <div class="w-7 h-7 rounded-full bg-teal-100 flex items-center justify-center flex-shrink-0">
                                    <span class="text-xs font-bold text-teal-700">{{ substr($senior->first_name, 0, 1) }}</span>
                                </div>
                                <div>
                                    <p class="font-medium text-slate-800">{{ $senior->full_name }}</p>
                                    <p class="text-xs text-slate-400">Age {{ $senior->age }}</p>
                                </div>
                            </div>
                        </td>

                        {{-- Barangay --}}
                        <td class="px-4 py-3 text-slate-600 text-xs">{{ $senior->barangay }}</td>

                        {{-- Risk --}}
                        <td class="px-4 py-3 text-center">
                            @if ($ml?->overall_risk_level)
                            <span class="text-xs font-bold px-2 py-1 rounded-full
                                {{ match($ml->overall_risk_level) {
                                    'CRITICAL' => 'bg-red-100 text-red-700',
                                    'HIGH'     => 'bg-orange-100 text-orange-700',
                                    'MODERATE' => 'bg-amber-100 text-amber-700',
                                    'LOW'      => 'bg-emerald-100 text-emerald-700',
                                    default    => 'bg-slate-100 text-slate-500',
                                } }}">{{ $ml->overall_risk_level }}</span>
                            @else
                            <span class="text-xs text-slate-300">—</span>
                            @endif
                        </td>

                        {{-- Cluster --}}
                        <td class="px-4 py-3 text-center">
                            @if ($ml?->cluster_named_id)
                            <span class="text-xs font-semibold px-2 py-1 rounded-full
                                {{ match($ml->cluster_named_id) {
                                    1 => 'bg-emerald-100 text-emerald-700',
                                    2 => 'bg-amber-100 text-amber-700',
                                    3 => 'bg-rose-100 text-rose-700',
                                    default => 'bg-slate-100 text-slate-500',
                                } }}">C{{ $ml->cluster_named_id }}</span>
                            @else
                            <span class="text-xs text-slate-300">—</span>
                            @endif
                        </td>

                        {{-- Total recs --}}
                        <td class="px-4 py-3 text-center">
                            <span class="text-sm font-semibold text-slate-700">{{ $senior->recommendations_count }}</span>
                        </td>

                        {{-- Pending --}}
                        <td class="px-4 py-3 text-center">
                            @if ($senior->pending_count > 0)
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">
                                {{ $senior->pending_count }}
                            </span>
                            @else
                            <span class="inline-flex items-center gap-0.5 text-xs text-emerald-600 font-medium">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                All done
                            </span>
                            @endif
                        </td>

                        {{-- Immediate / Urgent --}}
                        <td class="px-4 py-3 text-center">
                            @if ($senior->immediate_count > 0)
                            <span class="inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full bg-red-100 text-red-700">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                                {{ $senior->immediate_count }}
                            </span>
                            @else
                            <span class="text-xs text-slate-300">—</span>
                            @endif
                        </td>

                        {{-- View button --}}
                        <td class="px-4 py-3 text-center">
                            <a href="{{ route('recommendations.show', $senior) }}"
                               class="inline-flex items-center gap-1 text-xs px-3 py-1.5 bg-teal-50 text-teal-700 rounded-lg hover:bg-teal-100 font-medium transition-colors">
                                View →
                            </a>
                        </td>

                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="px-4 py-12 text-center text-slate-400">
                            <div class="flex justify-center mb-2">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                            <p class="font-medium">No seniors match the selected filters.</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($seniors->hasPages())
        <div class="border-t border-slate-100 px-5 py-3">
            {{ $seniors->links() }}
        </div>
        @endif

    </div>
</div>
@endsection
