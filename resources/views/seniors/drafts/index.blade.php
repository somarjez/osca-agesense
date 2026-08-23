{{-- resources/views/seniors/drafts/index.blade.php --}}
@extends('layouts.app')
@section('page-title', 'Drafts')
@section('page-subtitle', 'In-progress senior citizen registrations not yet saved as active records')

@section('content')
<div class="space-y-5">

    {{-- Filters --}}
    <form method="GET" class="card">
        <div class="card-head">
            <div class="card-title">Filter Drafts</div>
        </div>
        <div class="card-body flex flex-wrap items-end gap-4">
            <div class="min-w-[200px] flex-1">
                <label class="eyebrow block mb-1.5">Search</label>
                <input type="text" name="search" value="{{ request('search') }}"
                       placeholder="Applicant name…" class="form-input w-full">
            </div>
            <div class="flex gap-2 self-end">
                <button type="submit" class="btn btn-primary" data-loading="Filtering…">
                    <x-heroicon-o-funnel class="w-3.5 h-3.5" /> Filter
                </button>
                @if (request()->hasAny(['search']))
                <a href="{{ route('seniors.drafts.index') }}" wire:navigate class="btn" data-loading="Clearing…">Clear</a>
                @endif
            </div>
        </div>
    </form>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr>
                    <th class="th">Applicant</th>
                    <th class="th">Barangay</th>
                    <th class="th text-center">Step</th>
                    <th class="th">Started By</th>
                    <th class="th">Last Updated</th>
                    <th class="th text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($drafts as $draft)
                @php
                    $firstName = $draft->data['firstName'] ?? '';
                    $lastName = $draft->data['lastName'] ?? '';
                    $fullName = trim("{$firstName} {$lastName}");
                    $barangay = $draft->data['barangay'] ?? null;
                @endphp
                <tr class="hover:bg-forest-50/40 dark:hover:bg-forest-900/10 transition-colors group">
                    <td class="td font-medium text-ink-900 dark:text-[#e4e1d8]">
                        @if ($fullName !== '')
                            {{ $fullName }}
                        @else
                            <span class="italic text-ink-400 dark:text-[#6b7570]">Unnamed draft</span>
                        @endif
                    </td>
                    <td class="td text-ink-500 dark:text-[#8a9087]">{{ $barangay ?: '—' }}</td>
                    <td class="td text-center">
                        <span class="badge badge-neutral">Step {{ $draft->step }} of 6</span>
                    </td>
                    <td class="td text-ink-600 dark:text-[#b0b5b2]">{{ $draft->createdBy?->name ?? '—' }}</td>
                    <td class="td text-ink-500 dark:text-[#8a9087]">{{ $draft->updated_at?->diffForHumans() }}</td>
                    <td class="td">
                        <div class="flex justify-center gap-1.5">
                            <a href="{{ route('surveys.profile.draft.continue', $draft) }}"
                               class="btn btn-primary text-[11.5px] px-2.5 py-1">Continue Draft →</a>
                            <div x-data="{ open: false }">
                                <button @click="open = true"
                                        class="btn btn-ghost text-[11.5px] px-2 py-1 text-critical-700 hover:bg-critical-50 hover:text-critical-900">
                                    Delete
                                </button>
                                <form x-ref="deleteForm" method="POST" action="{{ route('seniors.drafts.destroy', $draft) }}" class="hidden">
                                    @csrf @method('DELETE')
                                </form>
                                <x-confirm-modal show="open"
                                                 title="Delete Draft?"
                                                 confirm="$refs.deleteForm.requestSubmit()"
                                                 confirm-label="Delete draft">
                                    <p>The in-progress registration for <strong class="text-ink-900 dark:text-[#e4e1d8]">{{ $fullName !== '' ? $fullName : 'this unnamed draft' }}</strong> will be permanently deleted.</p>
                                    <p class="mt-2 text-[12px] font-semibold px-3 py-2 rounded-xl text-critical-700 dark:text-[#e08070] bg-critical-50 dark:bg-critical-50/10 border border-critical-100 dark:border-critical-700/30">
                                        This cannot be undone.
                                    </p>
                                </x-confirm-modal>
                            </div>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="td text-center py-16">
                        <x-heroicon-o-pencil-square class="w-10 h-10 text-ink-200 dark:text-ink-700 mx-auto mb-2" />
                        <p class="font-serif text-base text-ink-700 dark:text-[#c8c4bc]">No drafts found.</p>
                        <p class="text-sm text-ink-400 dark:text-[#6b7570] mt-1">Unfinished registrations you save as a draft will appear here.</p>
                        <a href="{{ route('seniors.create') }}" class="btn btn-primary mt-4">
                            <x-heroicon-o-user-plus class="w-3.5 h-3.5" /> New Profile
                        </a>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>
        @if ($drafts->hasPages())
        <div class="border-t border-paper-rule dark:border-[#2b3530] px-5 py-3">
            {{ $drafts->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
