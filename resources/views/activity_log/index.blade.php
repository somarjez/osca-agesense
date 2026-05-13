{{-- resources/views/activity_log/index.blade.php --}}
@extends('layouts.app')
@section('page-title', 'Activity Log')
@section('page-subtitle', 'Audit trail of all data changes made in the system')

@section('content')
<div class="space-y-5">

    {{-- ── Filters ── --}}
    <form method="GET" class="bg-white border border-slate-200 rounded-xl px-5 py-4 shadow-sm flex flex-wrap items-end gap-4">
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">Action</label>
            <select name="action" class="text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
                <option value="">All Actions</option>
                @foreach ($actions as $a)
                <option value="{{ $a }}" {{ request('action') === $a ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $a)) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex-1 min-w-[200px]">
            <label class="block text-xs font-medium text-slate-500 mb-1">Search description</label>
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="e.g. Juan dela Cruz"
                   class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
        </div>
        <div class="flex gap-2">
            <button type="submit"
                    class="px-4 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700 shadow-sm">
                Filter
            </button>
            @if (request()->hasAny(['action','search']))
            <a href="{{ route('activity-log.index') }}"
               class="px-4 py-2 bg-white border border-slate-200 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-50 shadow-sm">
                Clear
            </a>
            @endif
        </div>
        <span class="ml-auto text-xs text-slate-400 self-center">{{ number_format($logs->total()) }} entries</span>
    </form>

    {{-- ── Log table ── --}}
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr class="text-xs text-slate-400">
                        <th class="px-4 py-2.5 text-left font-medium">When</th>
                        <th class="px-4 py-2.5 text-left font-medium">User</th>
                        <th class="px-4 py-2.5 text-left font-medium">Action</th>
                        <th class="px-4 py-2.5 text-left font-medium">Description</th>
                        <th class="px-4 py-2.5 text-left font-medium">IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($logs as $log)
                    @php
                        $actionStyle = match ($log->action) {
                            'created'      => 'bg-emerald-100 text-emerald-700',
                            'updated'      => 'bg-blue-100 text-blue-700',
                            'archived'     => 'bg-amber-100 text-amber-700',
                            'restored'     => 'bg-teal-100 text-teal-700',
                            'force_deleted'=> 'bg-red-100 text-red-700',
                            default        => 'bg-slate-100 text-slate-600',
                        };
                    @endphp
                    <tr class="hover:bg-slate-25 transition-colors">
                        <td class="px-4 py-2.5 text-slate-500 whitespace-nowrap tabular-nums text-xs">
                            {{ $log->created_at->format('M d, Y') }}
                            <div class="text-slate-400">{{ $log->created_at->format('H:i:s') }}</div>
                        </td>
                        <td class="px-4 py-2.5 text-slate-700">
                            {{ $log->user?->name ?? '—' }}
                        </td>
                        <td class="px-4 py-2.5">
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ $actionStyle }}">
                                {{ str_replace('_', ' ', $log->action) }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-slate-600 max-w-sm truncate" title="{{ $log->description }}">
                            {{ $log->description ?: '—' }}
                        </td>
                        <td class="px-4 py-2.5 text-slate-400 text-xs tabular-nums whitespace-nowrap">
                            {{ $log->ip_address ?? '—' }}
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-slate-400">
                            No activity recorded yet.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if ($logs->hasPages())
        <div class="px-5 py-3 border-t border-slate-100">
            {{ $logs->links() }}
        </div>
        @endif
    </div>

</div>
@endsection
