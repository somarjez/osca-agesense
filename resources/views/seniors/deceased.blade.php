@extends('layouts.app')
@section('page-title', 'Deceased Seniors')
@section('page-subtitle', number_format($total) . ' deceased seniors · records preserved, excluded from the active roster')

@section('content')
<div class="space-y-6" x-data="{
        singleArchiveOpen: false,
        singleArchiveName: '',
        singleArchiveId: null,
        openArchive(id, name) {
            this.singleArchiveId   = id;
            this.singleArchiveName = name;
            this.singleArchiveOpen = true;
        },
    }">

    <x-breadcrumb :links="[
        ['label' => 'Dashboard', 'href' => route('dashboard')],
        ['label' => 'Senior Records', 'href' => route('seniors.index')],
        ['label' => 'Deceased Seniors'],
    ]" />
    <x-page-header
        title="Deceased Seniors"
        :subtitle="number_format($total) . ' deceased seniors · records preserved, excluded from the active roster'"
    />

    {{-- Filter + Search --}}
    <form method="GET" class="card">
        <div class="card-head">
            <div class="flex items-center gap-2.5">
                <x-heroicon-o-funnel class="w-4 h-4 text-ink-400" />
                <div class="card-title">Filter Records</div>
                @if (request()->hasAny(['search','barangay']))
                    <span class="badge badge-info">Filtered</span>
                @endif
            </div>
            <a href="{{ route('seniors.index') }}" class="btn btn-ghost gap-1.5">
                <x-heroicon-o-arrow-left class="w-3.5 h-3.5" /> Active Records
            </a>
        </div>
        <div class="card-body flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[200px]">
                <label class="eyebrow block mb-1.5">Search</label>
                <input type="text" name="search" value="{{ request('search') }}"
                       placeholder="Name, OSCA ID or System ID…"
                       class="form-input focus:ring-0 focus:border-ink-300 dark:focus:border-[#3d4d46]">
            </div>
            <div class="min-w-[140px]">
                <label class="eyebrow block mb-1.5">Barangay</label>
                <select name="barangay" class="form-select" onchange="this.form.requestSubmit()">
                    <option value="">All Barangays</option>
                    @foreach ($barangays as $brgy)
                        <option value="{{ $brgy }}" {{ request('barangay')==$brgy?'selected':'' }}>{{ $brgy }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary" data-loading="Filtering…">
                    <x-heroicon-o-magnifying-glass class="w-3.5 h-3.5" />
                    Search
                </button>
                @if (request()->hasAny(['search','barangay']))
                    <a href="{{ route('seniors.deceased') }}" wire:navigate class="btn" data-loading="Clearing…">
                        <x-heroicon-o-x-mark class="w-3.5 h-3.5" />
                        Clear
                    </a>
                @endif
            </div>
        </div>
    </form>

    {{-- Table --}}
    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr>
                    <th class="th">OSCA ID</th>
                    <th class="th">Name</th>
                    <th class="th">Barangay</th>
                    <th class="th text-center">Age</th>
                    <th class="th text-center">Date of Death</th>
                    <th class="th">Status Changed By</th>
                    <th class="th text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($seniors as $senior)
                <tr class="group hover:bg-paper-2/60 dark:hover:bg-[#1a201d]/60 transition-colors">
                    <td class="td">
                        <div class="font-mono text-[12px] {{ $senior->official_osca_id ? 'font-semibold text-ink-800 dark:text-[#e4e1d8]' : 'italic text-ink-300 dark:text-[#4a5550]' }}">
                            {{ $senior->official_osca_id_display }}
                        </div>
                        <div class="text-[10.5px] text-ink-400 dark:text-[#6b7570] font-mono mt-0.5">
                            SYS: {{ $senior->osca_id }}
                        </div>
                    </td>
                    <td class="td">
                        <a href="{{ route('seniors.show', $senior) }}" wire:navigate data-loading="Opening…" class="flex items-center gap-3">
                            <div class="w-7 h-7 rounded-full bg-ink-100 dark:bg-ink-800/60 grid place-items-center flex-shrink-0">
                                <span class="text-[11px] font-semibold text-ink-400 dark:text-ink-500">{{ strtoupper(substr($senior->first_name,0,1).substr($senior->last_name,0,1)) }}</span>
                            </div>
                            <div>
                                <p class="font-semibold text-ink-600">{{ $senior->full_name }}</p>
                                <p class="text-[11.5px] text-ink-400">{{ $senior->gender }} · {{ $senior->marital_status }}</p>
                            </div>
                        </a>
                    </td>
                    <td class="td text-ink-500">{{ $senior->barangay }}</td>
                    <td class="td text-center font-mono tnum text-ink-600">{{ $senior->age }}</td>
                    <td class="td text-center text-ink-600 text-[12px]">
                        {{ $senior->date_of_death?->format('M j, Y') ?? '—' }}
                    </td>
                    <td class="td text-ink-500 text-[12px]">
                        @if ($senior->status_changed_at)
                            {{ $senior->status_changed_by ?? 'unknown' }}
                            <span class="block text-ink-400 text-[11px]">{{ $senior->status_changed_at->format('M j, Y g:ia') }}</span>
                        @else
                            <span class="text-ink-300">—</span>
                        @endif
                    </td>
                    <td class="td">
                        <div class="flex items-center justify-end gap-1">
                            <a href="{{ route('seniors.show', $senior) }}"
                               wire:navigate data-loading="Opening…"
                               class="btn btn-ghost text-[11.5px] px-2.5 py-1.5 gap-1.5"
                               title="View profile">
                                <x-heroicon-o-eye class="w-3.5 h-3.5" /> View
                            </a>
                            @hasanyrole('admin|encoder')
                            <a href="{{ route('seniors.edit', $senior) }}"
                               wire:navigate data-loading="Opening…"
                               class="btn btn-ghost text-[11.5px] px-2.5 py-1.5 gap-1.5"
                               title="Edit profile — reactivate here">
                                <x-heroicon-o-pencil class="w-3.5 h-3.5" /> Edit
                            </a>
                            @endrole
                            @hasanyrole('admin')
                            <button @click="openArchive('{{ $senior->uuid }}', '{{ addslashes($senior->full_name) }}')"
                                    class="btn btn-ghost text-[11.5px] px-2.5 py-1.5 text-high-700 hover:text-high-900 hover:bg-high-50 dark:hover:bg-high-50/10"
                                    title="Archive record">
                                <x-heroicon-o-archive-box class="w-3.5 h-3.5" />
                            </button>
                            @endrole
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-4 py-16 text-center">
                        <p class="font-serif text-base text-ink-500">No deceased seniors on record.</p>
                        <p class="text-[12.5px] text-ink-400 mt-1">Seniors marked deceased via Edit Profile will appear here.</p>
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

    {{-- ── Single Archive Confirmation Modal ─────────────────────────── --}}
    <div x-show="singleArchiveOpen" x-cloak
         role="dialog"
         aria-modal="true"
         aria-labelledby="single-archive-title"
         class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4"
         @keydown.escape.window="singleArchiveOpen = false">
        <div class="card max-w-sm w-full shadow-2xl" @click.outside="singleArchiveOpen = false">
            <div class="card-head">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-high-50 dark:bg-high-50/10 grid place-items-center flex-shrink-0">
                        <x-heroicon-o-archive-box class="w-4 h-4 text-high-700" />
                    </div>
                    <div class="card-title" id="single-archive-title">Archive this record?</div>
                </div>
                <button @click="singleArchiveOpen = false" class="btn btn-ghost p-1.5">
                    <x-heroicon-o-x-mark class="w-4 h-4" />
                </button>
            </div>
            <div class="card-body space-y-4">
                <p class="text-[13px] text-ink-700 dark:text-[#c8c4bc]">
                    <span class="font-semibold" x-text="singleArchiveName"></span> will be moved to Archives. Their data is preserved and can be restored at any time.
                </p>
                <form id="single-archive-form" method="POST" :action="`/seniors/${singleArchiveId}`">
                    @csrf
                    @method('DELETE')
                </form>
                <div class="flex gap-2 justify-end pt-1">
                    <button @click="singleArchiveOpen = false" class="btn">Cancel</button>
                    <button @click="document.getElementById('single-archive-form').submit()"
                            class="btn btn-danger">
                        <x-heroicon-o-archive-box class="w-3.5 h-3.5" />
                        Archive Record
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>
@endsection
