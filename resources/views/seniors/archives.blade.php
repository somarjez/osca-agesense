@extends('layouts.app')
@section('page-title', 'Archived Records')
@section('page-subtitle', 'Soft-deleted senior citizen records — restore or permanently remove')

@section('content')
<div class="space-y-6">

    {{-- Filter --}}
    <form method="GET" class="card">
        <div class="card-body flex flex-wrap items-end gap-4">
            <div class="flex-1 min-w-[200px]">
                <label class="eyebrow block mb-1.5">Search</label>
                <input type="text" name="search" value="{{ request('search') }}"
                       placeholder="Name or OSCA ID…"
                       class="form-input">
            </div>
            <div class="min-w-[140px]">
                <label class="eyebrow block mb-1.5">Barangay</label>
                <select name="barangay" class="form-select">
                    <option value="">All</option>
                    @foreach ($barangays as $brgy)
                        <option value="{{ $brgy }}" {{ request('barangay')==$brgy?'selected':'' }}>{{ $brgy }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary">Search</button>
                <a href="{{ route('seniors.archives') }}" class="btn">Clear</a>
                <a href="{{ route('seniors.index') }}" class="btn">← Active Records</a>
            </div>
        </div>
    </form>

    <div class="card overflow-hidden">
        <table class="w-full">
            <thead>
                <tr>
                    <th class="th">OSCA ID</th>
                    <th class="th">Name</th>
                    <th class="th">Barangay</th>
                    <th class="th text-center">Age</th>
                    <th class="th text-center">Archived On</th>
                    <th class="th text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($seniors as $senior)
                <tr class="group hover:bg-paper-2 transition-colors">
                    <td class="td">
                        <span class="font-mono text-[11.5px] text-ink-500 tnum">{{ $senior->osca_id }}</span>
                    </td>
                    <td class="td">
                        <div class="flex items-center gap-3">
                            <div class="w-7 h-7 rounded-full bg-slate-100 grid place-items-center flex-shrink-0">
                                <span class="text-[11px] font-semibold text-slate-400">{{ strtoupper(substr($senior->first_name,0,1).substr($senior->last_name,0,1)) }}</span>
                            </div>
                            <div>
                                <p class="font-semibold text-ink-600">{{ $senior->full_name }}</p>
                                <p class="text-[11.5px] text-ink-400">{{ $senior->gender }} · {{ $senior->marital_status }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="td text-ink-500">{{ $senior->barangay }}</td>
                    <td class="td text-center font-mono tnum text-ink-600">{{ $senior->age }}</td>
                    <td class="td text-center text-ink-400 text-[12px]">{{ $senior->deleted_at?->format('M j, Y') }}</td>
                    <td class="td">
                        <div class="flex items-center justify-end gap-1.5">
                            {{-- Restore --}}
                            <form method="POST" action="{{ route('seniors.restore', $senior->id) }}">
                                @csrf
                                <button type="submit"
                                        class="btn btn-ghost text-[11.5px] px-2 py-1 text-forest-700 hover:text-forest-900 hover:bg-forest-50">
                                    Restore
                                </button>
                            </form>
                            {{-- Permanent delete --}}
                            <div x-data="{ open: false }">
                                <button @click="open = true"
                                        class="btn btn-ghost text-[11.5px] px-2 py-1 text-critical-700 hover:text-critical-700 hover:bg-critical-50">
                                    Delete Forever
                                </button>
                                <form x-ref="deleteForm" method="POST" action="{{ route('seniors.force-delete', $senior->id) }}" class="hidden">
                                    @csrf @method('DELETE')
                                </form>
                                <div x-show="open" x-cloak
                                     class="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4"
                                     @keydown.escape.window="open = false">
                                    <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6"
                                         @click.outside="open = false">
                                        <div class="flex items-start gap-3 mb-4">
                                            <div class="w-9 h-9 rounded-full bg-red-100 flex items-center justify-center flex-shrink-0 text-lg">🗑️</div>
                                            <div>
                                                <h3 class="font-semibold text-red-700">Permanently delete this record?</h3>
                                                <p class="text-sm text-slate-600 mt-1">
                                                    <strong>{{ $senior->full_name }}</strong> and all associated data — QoL surveys, ML results, and recommendations — will be permanently erased.
                                                </p>
                                                <p class="text-xs font-semibold text-red-600 mt-2 bg-red-50 px-3 py-1.5 rounded-lg">
                                                    ⚠ This action cannot be undone.
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
                                                Delete Forever
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="td text-center py-16">
                        <p class="font-serif text-lg text-ink-700">No archived records.</p>
                        <p class="text-sm text-ink-400 mt-1">Archived seniors will appear here.</p>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>

        @if ($seniors->hasPages())
        <div class="border-t border-paper-rule px-5 py-3">
            {{ $seniors->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
