{{-- resources/views/activity_log/index.blade.php --}}
@extends('layouts.app')
@section('page-title', 'Activity Log')
@section('page-subtitle', 'Audit trail of all data changes made in the system')

@section('content')
<div class="space-y-5">

    {{-- ── Filters ── --}}
    <div class="card">
        <div class="card-head">
            <div class="card-title">Filter Log</div>
            <div class="flex items-center gap-3">
                <span class="text-[12px] text-ink-400">{{ number_format($logs->total()) }} entries</span>
                <form method="POST" action="{{ route('activity-log.clear') }}" x-data
                      @submit.prevent="if (confirm('Permanently delete ALL {{ number_format($logs->total()) }} log entries? This cannot be undone.')) $el.submit()">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-ghost text-xs text-critical-700 hover:bg-critical-50">
                        Clear All
                    </button>
                </form>
            </div>
        </div>
        <form method="GET">
        <div class="card-body flex flex-wrap items-end gap-4">
            <div class="min-w-[160px]">
                <label class="eyebrow block mb-1.5">Action</label>
                <select name="action" class="form-select">
                    <option value="">All Actions</option>
                    @foreach ($actions as $a)
                    <option value="{{ $a }}" {{ request('action') === $a ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $a)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex-1 min-w-[200px]">
                <label class="eyebrow block mb-1.5">Search description</label>
                <input type="text" name="search" value="{{ request('search') }}"
                       placeholder="e.g. Juan dela Cruz"
                       class="form-input">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <x-heroicon-o-magnifying-glass class="w-3.5 h-3.5" />
                    Filter
                </button>
                @if (request()->hasAny(['action','search']))
                <a href="{{ route('activity-log.index') }}" class="btn">Clear</a>
                @endif
            </div>
        </div>
        </form>
    </div>

    {{-- ── Log table ── --}}
    <div x-data="{
        selected: [],
        get allIds() { return [...$el.querySelectorAll('.row-cb')].map(c => parseInt(c.value)); },
        toggleAll(checked) { this.selected = checked ? this.allIds : []; },
        toggle(id) {
            this.selected.includes(id)
                ? this.selected = this.selected.filter(i => i !== id)
                : this.selected.push(id);
        }
    }">

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="th w-8 pr-0">
                            <input type="checkbox"
                                   @change="toggleAll($event.target.checked)"
                                   :checked="allIds.length > 0 && selected.length === allIds.length"
                                   class="rounded border-paper-rule text-forest-700 focus:ring-forest-500">
                        </th>
                        <th class="th">When</th>
                        <th class="th">User</th>
                        <th class="th">Action</th>
                        <th class="th">Description</th>
                        <th class="th">IP</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                    @php
                        $actionStyle = match ($log->action) {
                            'created'       => 'badge-low',
                            'updated'       => 'badge-info',
                            'archived'      => 'badge-moderate',
                            'restored'      => 'badge-low',
                            'force_deleted' => 'badge-critical',
                            default         => 'badge-neutral',
                        };
                    @endphp
                    <tr class="hover:bg-forest-50/40 dark:hover:bg-forest-900/10 transition-colors">
                        <td class="td w-8 pr-0">
                            <input type="checkbox" class="row-cb rounded border-paper-rule text-forest-700 focus:ring-forest-500"
                                   value="{{ $log->id }}"
                                   :checked="selected.includes({{ $log->id }})"
                                   @change="toggle({{ $log->id }})">
                        </td>
                        <td class="td whitespace-nowrap tnum">
                            <span class="text-ink-700">{{ $log->created_at->format('M d, Y') }}</span>
                            <div class="text-[11px] text-ink-400">{{ $log->created_at->format('H:i:s') }}</div>
                        </td>
                        <td class="td text-ink-700">{{ $log->user?->name ?? '—' }}</td>
                        <td class="td">
                            <span class="badge {{ $actionStyle }}">{{ str_replace('_', ' ', $log->action) }}</span>
                        </td>
                        <td class="td text-ink-600 max-w-sm truncate" title="{{ $log->description }}">
                            {{ $log->description ?: '—' }}
                        </td>
                        <td class="td text-[11.5px] text-ink-400 tnum whitespace-nowrap">
                            {{ $log->ip_address ?? '—' }}
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-4 py-16 text-center text-ink-400">
                            No activity recorded yet.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($logs->hasPages())
        <div class="px-5 py-3 border-t border-paper-rule">
            {{ $logs->links() }}
        </div>
        @endif
    </div>

    {{-- Floating action bar — appears when at least one row is checked --}}
    <div x-show="selected.length > 0" x-cloak x-transition
         class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50
                bg-ink-900 text-white rounded-xl shadow-xl
                flex items-center gap-4 px-5 py-3 text-sm">

        <span x-text="`${selected.length} selected`" class="font-medium tabular-nums"></span>

        <form method="POST" action="{{ route('activity-log.bulk-destroy') }}">
            @csrf @method('DELETE')
            <template x-for="id in selected" :key="id">
                <input type="hidden" name="ids[]" :value="id">
            </template>
            <button type="submit"
                    @click.prevent="
                        const noun = selected.length === 1 ? 'entry' : 'entries';
                        if (confirm(\`Permanently delete \${selected.length} log \${noun}? This cannot be undone.\`))
                            \$el.closest('form').submit()
                    "
                    class="btn bg-critical-600 text-white hover:bg-critical-700 border-transparent text-xs py-1.5">
                Delete Selected
            </button>
        </form>

        <button @click="selected = []"
                class="text-white/50 hover:text-white text-xs underline underline-offset-2">
            Deselect all
        </button>
    </div>

    </div>{{-- end Alpine wrapper --}}

</div>
@endsection
