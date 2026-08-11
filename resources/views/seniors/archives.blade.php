@extends('layouts.app')
@section('page-title', 'Archived Records')
@section('page-subtitle', 'Soft-deleted senior citizen records and QoL surveys — restore or permanently remove')

@section('content')
<div class="space-y-6" x-data="archiveIndex()">

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
                       placeholder="Name, OSCA ID or System ID…"
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

    {{-- Bulk action bar --}}
    <div x-show="selected.length > 0" x-cloak
         class="card px-4 py-3 flex items-center justify-between gap-3 border-forest-200 bg-forest-50/60 dark:bg-forest-900/10">
        <div class="flex items-center gap-2 text-[13px] text-ink-800">
            <x-heroicon-o-check-circle class="w-4 h-4 text-forest-600" />
            <span><span class="font-semibold" x-text="selected.length"></span> record(s) selected</span>
        </div>
        <div class="flex items-center gap-2">
            <button @click="selected = []" class="btn btn-ghost text-[12px] px-3 py-1.5">Deselect all</button>
            <button @click="bulkRestoreOpen = true"
                    class="btn text-[12px] px-3 py-1.5 text-forest-700 border-forest-200 hover:bg-forest-50">
                <x-heroicon-o-arrow-uturn-left class="w-3.5 h-3.5" />
                Restore Selected
            </button>
            <button @click="bulkDeleteOpen = true"
                    class="btn text-[12px] px-3 py-1.5 text-critical-700 border-critical-200 hover:bg-critical-50">
                <x-heroicon-o-trash class="w-3.5 h-3.5" />
                Delete Selected
            </button>
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr>
                    <th class="th w-8">
                        <input type="checkbox"
                               class="rounded border-paper-rule text-forest-600 focus:ring-forest-500"
                               :checked="allOnPageSelected()"
                               :indeterminate="selected.length > 0 && !allOnPageSelected()"
                               @change="toggleAll($event.target.checked)">
                    </th>
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
                <tr class="group hover:bg-forest-50/40 dark:hover:bg-forest-900/10 transition-colors"
                    :class="selected.includes({{ $senior->id }}) ? 'bg-forest-50/60 dark:bg-forest-900/10' : ''">
                    <td class="td w-8">
                        <input type="checkbox"
                               class="rounded border-paper-rule text-forest-600 focus:ring-forest-500"
                               :checked="selected.includes({{ $senior->id }})"
                               @change="toggleRow({{ $senior->id }}, $event.target.checked)">
                    </td>
                    <td class="td">
                        <div class="font-mono text-[12px] {{ $senior->official_osca_id ? 'font-semibold text-ink-800 dark:text-[#e4e1d8]' : 'italic text-ink-300 dark:text-[#4a5550]' }}">
                            {{ $senior->official_osca_id_display }}
                        </div>
                        <div class="text-[10.5px] text-ink-400 dark:text-[#6b7570] font-mono mt-0.5">
                            SYS: {{ $senior->osca_id }}
                        </div>
                    </td>
                    <td class="td">
                        <div class="flex items-center gap-3">
                            <div class="w-7 h-7 rounded-full bg-ink-100 dark:bg-ink-800/60 grid place-items-center flex-shrink-0">
                                <span class="text-[11px] font-semibold text-ink-400 dark:text-ink-500">{{ strtoupper(substr($senior->first_name,0,1).substr($senior->last_name,0,1)) }}</span>
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
                            <button type="button"
                                    @click="restoreSenior({{ $senior->id }})"
                                    :disabled="restoringId === {{ $senior->id }}"
                                    :class="restoringId === {{ $senior->id }} ? 'opacity-70 pointer-events-none' : ''"
                                    class="btn btn-ghost text-[11.5px] px-2 py-1 text-forest-700 hover:text-forest-900 hover:bg-forest-50">
                                <span x-show="restoringId === {{ $senior->id }}" x-cloak class="btn-spinner" aria-hidden="true"></span>
                                <span x-text="restoringId === {{ $senior->id }} ? 'Restoring…' : 'Restore'"></span>
                            </button>
                            {{-- Permanent delete --}}
                            <div x-data="{ open: false }">
                                <button @click="open = true"
                                        class="btn btn-ghost text-[11.5px] px-2 py-1 text-critical-700 hover:text-critical-700 hover:bg-critical-50">
                                    Delete Forever
                                </button>
                                <x-confirm-modal show="open"
                                                 title="Permanently delete this record?"
                                                 confirm="forceDeleteSenior({{ $senior->id }}).then(() => open = false)"
                                                 confirm-label="Delete forever">
                                    <p><strong class="text-ink-900 dark:text-[#e4e1d8]">{{ $senior->full_name }}</strong> and all associated data — QoL surveys, decision-support outputs, and recommendations — will be permanently erased.</p>
                                    <p class="mt-2 text-[12px] font-semibold px-3 py-2 rounded-xl text-critical-700 dark:text-[#e08070] bg-critical-50 dark:bg-critical-50/10 border border-critical-100 dark:border-critical-700/30">
                                        This action cannot be undone.
                                    </p>
                                </x-confirm-modal>
                            </div>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="td text-center py-16">
                        <p class="font-serif text-lg text-ink-700">No archived records.</p>
                        <p class="text-sm text-ink-400 mt-1">Archived seniors will appear here.</p>
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

    {{-- ── Archived QoL Surveys ── --}}
    <div class="card overflow-hidden">
        <div class="px-5 py-4 border-b border-paper-rule flex items-center justify-between">
            <div>
                <div class="font-semibold text-ink-900">Archived QoL Surveys</div>
                <div class="text-xs text-ink-400 mt-0.5">Surveys archived when a senior was moved to archives</div>
            </div>
            <span class="badge badge-neutral">{{ $archivedSurveys->total() }} total</span>
        </div>
        <div class="overflow-x-auto">
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
                <tr class="group hover:bg-forest-50/40 dark:hover:bg-forest-900/10 transition-colors">
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
        </div>

        @if ($archivedSurveys->hasPages())
        <div class="border-t border-paper-rule px-5 py-3">
            {{ $archivedSurveys->links() }}
        </div>
        @endif
    </div>

    {{-- ── Bulk Restore Confirmation Modal ──────────────────────────────── --}}
    <div x-show="bulkRestoreOpen" x-cloak
         class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4"
         @keydown.escape.window="bulkRestoreOpen = false">
        <div class="card max-w-sm w-full shadow-2xl" @click.outside="bulkRestoreOpen = false">
            <div class="card-head">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-forest-50 grid place-items-center flex-shrink-0">
                        <x-heroicon-o-arrow-uturn-left class="w-4 h-4 text-forest-700" />
                    </div>
                    <div class="card-title">Restore selected records?</div>
                </div>
            </div>
            <div class="card-body">
                <p class="text-[13px] text-ink-700">
                    <span class="font-semibold" x-text="selected.length"></span> senior record(s) will be moved back to Active Records. Their QoL surveys will also be restored.
                </p>
                <div class="flex gap-2 justify-end mt-5">
                    <button @click="bulkRestoreOpen = false" :disabled="bulkRestoring" class="btn">Cancel</button>
                    <button @click="confirmBulkRestore()"
                            :disabled="bulkRestoring"
                            :class="bulkRestoring ? 'opacity-70 pointer-events-none' : ''"
                            class="btn btn-primary">
                        <span x-show="bulkRestoring" x-cloak class="btn-spinner" aria-hidden="true"></span>
                        <x-heroicon-o-arrow-uturn-left class="w-3.5 h-3.5" x-show="!bulkRestoring" />
                        <span x-text="bulkRestoring ? 'Restoring…' : 'Restore Selected'"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Bulk Delete Confirmation Modal ───────────────────────────────── --}}
    <div x-show="bulkDeleteOpen" x-cloak
         role="dialog" aria-modal="true" aria-labelledby="bulk-delete-title"
         class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4"
         @keydown.escape.window="bulkDeleteOpen = false">
        <div class="card max-w-sm w-full shadow-2xl" @click.outside="bulkDeleteOpen = false">
            <div class="card-head">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-critical-50 dark:bg-critical-50/10 grid place-items-center flex-shrink-0">
                        <x-heroicon-o-trash class="w-4 h-4 text-critical-700" />
                    </div>
                    <h3 id="bulk-delete-title" class="card-title">Permanently delete selected records?</h3>
                </div>
                <button @click="bulkDeleteOpen = false" class="btn btn-ghost p-1.5" aria-label="Close">
                    <x-heroicon-o-x-mark class="w-4 h-4" />
                </button>
            </div>
            <div class="card-body space-y-3">
                <p class="text-[13px] text-ink-700 dark:text-[#c8c4bc]">
                    <strong class="text-ink-900 dark:text-[#e4e1d8]" x-text="selected.length"></strong> senior record(s) and all associated data — QoL surveys, ML results, and recommendations — will be permanently erased.
                </p>
                <p class="text-[12px] font-semibold px-3 py-2 rounded-xl text-critical-700 dark:text-[#e08070] bg-critical-50 dark:bg-critical-50/10 border border-critical-100 dark:border-critical-700/30">
                    This action cannot be undone.
                </p>
                <div class="flex gap-2 justify-end pt-1 border-t border-paper-rule dark:border-[#2b3530]">
                    <button @click="bulkDeleteOpen = false" :disabled="bulkDeleting" class="btn">Cancel</button>
                    <button @click="confirmBulkDelete()"
                            :disabled="bulkDeleting"
                            :class="bulkDeleting ? 'opacity-70 pointer-events-none' : ''"
                            class="btn btn-danger">
                        <span x-show="bulkDeleting" x-cloak class="btn-spinner" aria-hidden="true"></span>
                        <x-heroicon-o-trash class="w-3.5 h-3.5" x-show="!bulkDeleting" />
                        <span x-text="bulkDeleting ? 'Deleting…' : 'Delete Forever'"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>

@push('scripts')
<script>
function archiveIndex() {
    return {
        selected: [],
        bulkRestoreOpen: false,
        bulkDeleteOpen: false,
        bulkRestoring: false,
        bulkDeleting: false,
        restoringId: null,
        pageIds: @json($seniors->pluck('id')),

        // ── Restore/delete actions — fetch() + Livewire.navigate() ────────
        // Same contract as seniors/index.blade.php's seniorIndex().postAction():
        // the controller (SeniorCitizenController::stateRedirect()) replies
        // with { redirect } instead of a 302 when Accept: application/json is
        // sent, and Livewire.navigate() completes the trip so the persisted
        // sidebar/topbar shell survives instead of a full document reload. Nothing
        // here (selection, row state) is treated as changed until the fetch
        // actually resolves.
        csrfToken: '{{ csrf_token() }}',
        async postAction(url, { method = 'POST', body = null } = {}) {
            const headers = { 'X-CSRF-TOKEN': this.csrfToken, 'Accept': 'application/json' };
            if (method !== 'POST') headers['X-HTTP-METHOD-OVERRIDE'] = method;
            if (body) headers['Content-Type'] = 'application/json';

            let ok = false;
            let target = window.location.href;
            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers,
                    credentials: 'same-origin',
                    body: body ? JSON.stringify(body) : null,
                });
                const data = await res.json().catch(() => null);
                ok = res.ok && !!(data && data.success);
                if (data && data.redirect) target = data.redirect;
            } catch (e) {
                console.error('Archive action request failed', e);
            }
            Livewire.navigate(target);
            return ok;
        },

        async restoreSenior(id) {
            if (this.restoringId) return;
            this.restoringId = id;
            await this.postAction(`/seniors/${id}/restore`, { method: 'POST' });
            this.restoringId = null;
        },

        async forceDeleteSenior(id) {
            return this.postAction(`/seniors/${id}/force-delete`, { method: 'DELETE' });
        },

        async confirmBulkRestore() {
            if (this.bulkRestoring || this.selected.length === 0) return;
            this.bulkRestoring = true;
            const ok = await this.postAction('{{ route('seniors.bulk-restore') }}', {
                method: 'POST',
                body: { ids: this.selected },
            });
            this.bulkRestoring = false;
            this.bulkRestoreOpen = false;
            if (ok) this.selected = [];
        },

        async confirmBulkDelete() {
            if (this.bulkDeleting || this.selected.length === 0) return;
            this.bulkDeleting = true;
            const ok = await this.postAction('{{ route('seniors.bulk-delete') }}', {
                method: 'POST',
                body: { ids: this.selected },
            });
            this.bulkDeleting = false;
            this.bulkDeleteOpen = false;
            if (ok) this.selected = [];
        },

        toggleRow(id, checked) {
            if (checked) {
                if (!this.selected.includes(id)) this.selected.push(id);
            } else {
                this.selected = this.selected.filter(i => i !== id);
            }
        },

        toggleAll(checked) {
            if (checked) {
                this.pageIds.forEach(id => {
                    if (!this.selected.includes(id)) this.selected.push(id);
                });
            } else {
                this.selected = this.selected.filter(id => !this.pageIds.includes(id));
            }
        },

        allOnPageSelected() {
            return this.pageIds.length > 0 && this.pageIds.every(id => this.selected.includes(id));
        },
    };
}
</script>
@endpush
@endsection
