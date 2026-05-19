{{-- resources/views/recommendations/index.blade.php --}}
@extends('layouts.app')
@section('page-title', 'Recommendations')
@section('page-subtitle', 'Navigate by senior citizen to view and update care action items')

@section('content')
<div class="space-y-5">

    {{-- ── Summary Stats ── --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <x-kpi label="Seniors with Recs"   :value="number_format($stats['seniors'])"   accent="forest" />
        <x-kpi label="Total Actions"        :value="number_format($stats['total'])"     accent="forest" />
        <x-kpi label="Pending Actions"      :value="number_format($stats['pending'])"   accent="moderate" />
        <x-kpi label="Immediate / Urgent"   :value="number_format($stats['immediate'])" accent="high" />
    </div>

    {{-- ── Filters ── --}}
    <form method="GET" class="card">
        <div class="card-head">
            <div class="card-title">Filter</div>
        </div>
        <div class="card-body flex flex-wrap items-end gap-4">
            <div class="min-w-[160px]">
                <label class="eyebrow block mb-1.5">Barangay</label>
                <select name="barangay" class="form-select">
                    <option value="">All Barangays</option>
                    @foreach ($barangays as $brgy)
                    <option value="{{ $brgy }}" {{ request('barangay') === $brgy ? 'selected' : '' }}>{{ $brgy }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-[140px]">
                <label class="eyebrow block mb-1.5">Risk Level</label>
                <select name="risk" class="form-select">
                    <option value="">All Levels</option>
                    @foreach (['HIGH','MODERATE','LOW'] as $r)
                    <option value="{{ $r }}" {{ strtoupper(request('risk')) === $r ? 'selected' : '' }}>{{ ucfirst(strtolower($r)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-center gap-2 self-end pb-2">
                <input type="checkbox" name="has_urgent" value="1" id="has_urgent"
                       class="rounded border-paper-rule text-forest-600 focus:ring-forest-500"
                       {{ request('has_urgent') ? 'checked' : '' }}>
                <label for="has_urgent" class="text-[13px] text-ink-700 cursor-pointer select-none">Has pending urgent actions</label>
            </div>
            <div class="flex gap-2 self-end">
                <button type="submit" class="btn btn-primary">
                    <x-heroicon-o-funnel class="w-3.5 h-3.5" />
                    Filter
                </button>
                @if (request()->hasAny(['barangay','risk','has_urgent']))
                <a href="{{ route('recommendations.index') }}" class="btn">Clear</a>
                @endif
            </div>
        </div>
    </form>

    {{-- ── Seniors Table ── --}}
    <div class="card overflow-hidden">
        <div class="card-head">
            <div class="card-title">Seniors with Recommendations</div>
            <span class="text-[12px] text-ink-400">{{ $seniors->total() }} senior(s)</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="th">Senior Citizen</th>
                        <th class="th">Barangay</th>
                        <th class="th text-center">Risk</th>
                        <th class="th text-center">Cluster</th>
                        <th class="th text-center">Total</th>
                        <th class="th text-center">Pending</th>
                        <th class="th text-center">Urgent</th>
                        <th class="th text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($seniors as $senior)
                    @php $ml = $senior->latestMlResult; @endphp
                    <tr class="hover:bg-forest-50/40 dark:hover:bg-forest-900/10 transition-colors group">

                        {{-- Name --}}
                        <td class="td">
                            <div class="flex items-center gap-3">
                                <div class="w-7 h-7 rounded-full bg-forest-100 grid place-items-center flex-shrink-0">
                                    <span class="text-[11px] font-semibold text-forest-800">{{ strtoupper(substr($senior->first_name,0,1).substr($senior->last_name,0,1)) }}</span>
                                </div>
                                <div>
                                    <p class="font-semibold text-ink-900">{{ $senior->full_name }}</p>
                                    <p class="text-[11.5px] text-ink-500">Age {{ $senior->age }}</p>
                                </div>
                            </div>
                        </td>

                        <td class="td text-ink-700">{{ $senior->barangay }}</td>

                        {{-- Risk --}}
                        <td class="td text-center">
                            @if ($ml?->overall_risk_level)
                                <x-risk-badge :level="$ml->overall_risk_level" />
                            @else
                                <span class="text-ink-300">—</span>
                            @endif
                        </td>

                        {{-- Cluster --}}
                        <td class="td text-center">
                            @if ($ml?->cluster_named_id)
                                <x-cluster-badge :id="$ml->cluster_named_id" />
                            @else
                                <span class="text-ink-300">—</span>
                            @endif
                        </td>

                        {{-- Total --}}
                        <td class="td text-center font-semibold text-ink-700 tnum">{{ $senior->recommendations_count }}</td>

                        {{-- Pending --}}
                        <td class="td text-center">
                            @if ($senior->pending_count > 0)
                                <span class="badge badge-moderate">{{ $senior->pending_count }}</span>
                            @else
                                <span class="inline-flex items-center gap-1 text-[11.5px] text-low-700 font-medium">
                                    <x-heroicon-o-check class="w-3.5 h-3.5" /> All done
                                </span>
                            @endif
                        </td>

                        {{-- Immediate / Urgent --}}
                        <td class="td text-center">
                            @if ($senior->immediate_count > 0)
                                <span class="badge badge-high">
                                    <x-heroicon-o-exclamation-triangle class="w-3 h-3" />
                                    {{ $senior->immediate_count }}
                                </span>
                            @else
                                <span class="text-ink-300">—</span>
                            @endif
                        </td>

                        {{-- View button --}}
                        <td class="td text-right">
                            <a href="{{ route('recommendations.show', $senior) }}"
                               class="btn btn-ghost text-[11.5px] px-2.5 py-1.5">
                                <x-heroicon-o-eye class="w-3.5 h-3.5" /> View
                            </a>
                        </td>

                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="px-4 py-16 text-center">
                            <x-heroicon-o-check-circle class="w-10 h-10 text-ink-200 mx-auto mb-2" />
                            <p class="font-serif text-base text-ink-500">No seniors match the selected filters.</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($seniors->hasPages())
        <div class="border-t border-paper-rule px-5 py-3">
            {{ $seniors->links() }}
        </div>
        @endif
    </div>

</div>
@endsection
