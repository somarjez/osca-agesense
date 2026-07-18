@extends('layouts.app')
@section('page-title', 'Deceased Seniors')
@section('page-subtitle', number_format($total) . ' deceased seniors · records preserved, excluded from the active roster')

@section('content')
<div class="space-y-6">

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
                       placeholder="Name or OSCA ID…"
                       class="form-input focus:ring-0 focus:border-ink-300 dark:focus:border-[#3d4d46]">
            </div>
            <div class="min-w-[140px]">
                <label class="eyebrow block mb-1.5">Barangay</label>
                <select name="barangay" class="form-select" onchange="this.form.submit()">
                    <option value="">All Barangays</option>
                    @foreach ($barangays as $brgy)
                        <option value="{{ $brgy }}" {{ request('barangay')==$brgy?'selected':'' }}>{{ $brgy }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <x-heroicon-o-magnifying-glass class="w-3.5 h-3.5" />
                    Search
                </button>
                @if (request()->hasAny(['search','barangay']))
                    <a href="{{ route('seniors.deceased') }}" class="btn">
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
                        <span class="font-mono text-[11.5px] text-ink-500 tnum">{{ $senior->osca_id }}</span>
                    </td>
                    <td class="td">
                        <a href="{{ route('seniors.show', $senior) }}" class="flex items-center gap-3">
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
                               class="btn btn-ghost text-[11.5px] px-2.5 py-1.5 gap-1.5"
                               title="View profile">
                                <x-heroicon-o-eye class="w-3.5 h-3.5" /> View
                            </a>
                            @hasanyrole('admin|encoder')
                            <a href="{{ route('seniors.edit', $senior) }}"
                               class="btn btn-ghost text-[11.5px] px-2.5 py-1.5 gap-1.5"
                               title="Edit profile — reactivate here">
                                <x-heroicon-o-pencil class="w-3.5 h-3.5" /> Edit
                            </a>
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

</div>
@endsection
