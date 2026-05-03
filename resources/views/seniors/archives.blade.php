@extends('layouts.app')
@section('page-title', 'Archived Records')
@section('page-subtitle', 'Soft-deleted senior citizen records and QoL surveys — restore or permanently remove')

@section('content')
<div class="space-y-6">

    {{-- Filter --}}
    <form method="GET" class="card">
        <div class="card-head">
            <div class="card-title">Search Archives</div>
            <a href="{{ route('seniors.index') }}" class="btn btn-ghost gap-1.5">
                <x-heroicon-o-arrow-left class="w-3.5 h-3.5" /> Active Records
            </a>
        </div>
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
                <button type="submit" class="btn btn-primary">
                    <x-heroicon-o-magnifying-glass class="w-3.5 h-3.5" /> Search
                </button>
                @if (request()->hasAny(['search','barangay']))
                    <a href="{{ route('seniors.archives') }}" class="btn">Clear</a>
                @endif
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
                                    <div class="rounded-2xl shadow-2xl max-w-sm w-full p-6"
                                         style="background:#ffffff; color:#1e293b;"
                                         @click.outside="open = false">
                                        <div class="flex items-start gap-3 mb-4">
                                            <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0" style="background:#fee2e2;">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                            </div>
                                            <div>
                                                <h3 class="font-semibold" style="color:#b91c1c;">Permanently delete this record?</h3>
                                                <p class="text-sm mt-1" style="color:#475569;">
                                                    <strong style="color:#334155;">{{ $senior->full_name }}</strong> and all associated data — QoL surveys, ML results, and recommendations — will be permanently erased.
                                                </p>
                                                <p class="text-xs font-semibold mt-2 px-3 py-1.5 rounded-lg" style="color:#dc2626; background:#fef2f2;">
                                                    This action cannot be undone.
                                                </p>
                                            </div>
                                        </div>
                                        <div class="flex gap-3 justify-end pt-3 mt-1" style="border-top:1px solid #e2e8f0;">
                                            <button @click="open = false"
                                                    class="px-4 py-2 text-sm font-medium rounded-lg transition-colors"
                                                    style="color:#475569; background:#f1f5f9; border:1px solid #cbd5e1;"
                                                    onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                                                Cancel
                                            </button>
                                            <button @click="$refs.deleteForm.submit()"
                                                    class="px-4 py-2 text-sm font-semibold rounded-lg transition-colors"
                                                    style="background:#dc2626; color:#ffffff; border:1px solid #dc2626;"
                                                    onmouseover="this.style.background='#b91c1c'" onmouseout="this.style.background='#dc2626'">
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

    {{-- ── Archived QoL Surveys ── --}}
    <div class="card overflow-hidden">
        <div class="px-5 py-4 border-b border-paper-rule flex items-center justify-between">
            <div>
                <div class="font-semibold text-ink-900">Archived QoL Surveys</div>
                <div class="text-xs text-ink-400 mt-0.5">Surveys archived when a senior was moved to archives</div>
            </div>
            <span class="badge badge-neutral">{{ $archivedSurveys->total() }} total</span>
        </div>
        <table class="w-full">
            <thead>
                <tr>
                    <th class="th">Senior</th>
                    <th class="th">Barangay</th>
                    <th class="th text-center">Survey Date</th>
                    <th class="th text-center">Overall Score</th>
                    <th class="th text-center">Status</th>
                    <th class="th text-center">Archived On</th>
                    <th class="th text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($archivedSurveys as $survey)
                <tr class="group hover:bg-paper-2 transition-colors">
                    <td class="td font-medium text-ink-600">{{ $survey->seniorCitizen?->full_name ?? '—' }}</td>
                    <td class="td text-ink-400">{{ $survey->seniorCitizen?->barangay ?? '—' }}</td>
                    <td class="td text-center text-ink-500">{{ $survey->survey_date?->format('M j, Y') }}</td>
                    <td class="td text-center">
                        @if ($survey->overall_score !== null)
                            <span class="font-mono text-sm font-semibold text-ink-700">{{ round($survey->overall_score * 100, 1) }}%</span>
                        @else
                            <span class="text-ink-300 text-xs">—</span>
                        @endif
                    </td>
                    <td class="td text-center">
                        <span class="badge {{ match($survey->status) {
                            'processed'  => 'badge-low',
                            'submitted'  => 'badge-info',
                            'draft'      => 'badge-neutral',
                            default      => 'badge-neutral',
                        } }}">{{ ucfirst($survey->status) }}</span>
                    </td>
                    <td class="td text-center text-ink-400 text-[12px]">{{ $survey->deleted_at?->format('M j, Y') }}</td>
                    <td class="td">
                        <div class="flex items-center justify-end gap-1.5">
                            {{-- Only allow restoring the survey if the senior is also restored --}}
                            @if ($survey->seniorCitizen && !$survey->seniorCitizen->trashed())
                            <form method="POST" action="{{ route('surveys.qol.restore', $survey->id) }}">
                                @csrf
                                <button type="submit"
                                        class="btn btn-ghost text-[11.5px] px-2 py-1 text-forest-700 hover:text-forest-900 hover:bg-forest-50">
                                    Restore
                                </button>
                            </form>
                            @else
                            <span class="text-[11.5px] text-ink-300 px-2 py-1">Restore senior first</span>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="td text-center py-10">
                        <p class="text-ink-400 text-sm">No archived QoL surveys.</p>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>

        @if ($archivedSurveys->hasPages())
        <div class="border-t border-paper-rule px-5 py-3">
            {{ $archivedSurveys->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
