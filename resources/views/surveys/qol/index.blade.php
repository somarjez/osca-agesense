@extends('layouts.app')
@section('page-title', 'QoL Surveys')
@section('page-subtitle', 'Quality of Life survey submissions and results')

@section('content')
<div class="space-y-5">

    {{-- Filters --}}
    <form method="GET" class="card">
        <div class="card-head">
            <div class="card-title">Filter Surveys</div>
        </div>
        <div class="card-body flex flex-wrap items-end gap-4">
            <div class="min-w-[140px]">
                <label class="eyebrow block mb-1.5">Status</label>
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="draft"     {{ request('status')==='draft'     ? 'selected' : '' }}>Draft</option>
                    <option value="submitted" {{ request('status')==='submitted' ? 'selected' : '' }}>Submitted</option>
                    <option value="processed" {{ request('status')==='processed' ? 'selected' : '' }}>Processed</option>
                </select>
            </div>
            <div class="min-w-[160px]">
                <label class="eyebrow block mb-1.5">Barangay</label>
                <select name="barangay" class="form-select">
                    <option value="">All Barangays</option>
                    @foreach (\App\Models\SeniorCitizen::barangayList() as $b)
                        <option value="{{ $b }}" {{ request('barangay')===$b ? 'selected' : '' }}>{{ $b }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-2 self-end">
                <button type="submit" class="btn btn-primary">
                    <x-heroicon-o-funnel class="w-3.5 h-3.5" /> Filter
                </button>
                @if (request()->hasAny(['status','barangay']))
                <a href="{{ route('surveys.qol.index') }}" class="btn">Clear</a>
                @endif
            </div>
        </div>
    </form>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr>
                    <th class="th">Senior</th>
                    <th class="th">Barangay</th>
                    <th class="th">Survey Date</th>
                    <th class="th text-center">Overall Score</th>
                    <th class="th text-center">Status</th>
                    <th class="th text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($surveys as $survey)
                <tr class="hover:bg-forest-50/40 dark:hover:bg-forest-900/10 transition-colors group">
                    <td class="td font-medium text-ink-900 dark:text-[#e4e1d8]">{{ $survey->seniorCitizen?->full_name }}</td>
                    <td class="td text-ink-500 dark:text-[#8a9087]">{{ $survey->seniorCitizen?->barangay }}</td>
                    <td class="td text-ink-600 dark:text-[#b0b5b2]">{{ $survey->survey_date?->format('M j, Y') }}</td>
                    <td class="td text-center">
                        @if ($survey->overall_score !== null)
                        @php $pct = round($survey->overall_score * 100); @endphp
                        <div class="flex items-center justify-center gap-2">
                            <div class="bar w-14">
                                <div class="bar-fill {{ $pct >= 70 ? 'bar-fill-low' : ($pct >= 50 ? 'bar-fill-moderate' : 'bar-fill-critical') }}"
                                     style="width: {{ $pct }}%"></div>
                            </div>
                            <span class="text-xs font-semibold font-mono tnum text-ink-700 dark:text-[#b0b5b2]">{{ $pct }}%</span>
                        </div>
                        @else
                        <span class="text-ink-300 dark:text-[#4a5550] text-xs">—</span>
                        @endif
                    </td>
                    <td class="td text-center">
                        <span class="badge {{ match($survey->status) {
                            'processed' => 'badge-low',
                            'submitted' => 'badge-info',
                            'draft'     => 'badge-neutral',
                            default     => 'badge-neutral',
                        } }}">{{ ucfirst($survey->status) }}</span>
                    </td>
                    <td class="td">
                        <div class="flex justify-center gap-1.5">
                            @if ($survey->status === 'processed')
                            <a href="{{ route('surveys.qol.results', $survey) }}"
                               class="btn btn-ghost text-[11.5px] px-2 py-1">Results</a>
                            @endif
                            <a href="{{ route('surveys.qol.edit', $survey) }}"
                               class="btn btn-ghost text-[11.5px] px-2 py-1">Edit</a>
                            <div x-data="{ open: false }">
                                <button @click="open = true"
                                        class="btn btn-ghost text-[11.5px] px-2 py-1 text-critical-700 hover:bg-critical-50 hover:text-critical-900">
                                    Delete
                                </button>
                                <form x-ref="deleteForm" method="POST" action="{{ route('surveys.qol.destroy', $survey) }}" class="hidden">
                                    @csrf @method('DELETE')
                                </form>
                                <x-confirm-modal show="open"
                                                 title="Delete QoL Survey?"
                                                 confirm="$refs.deleteForm.submit()"
                                                 confirm-label="Delete survey">
                                    <p>The survey from <strong class="text-ink-900 dark:text-[#e4e1d8]">{{ $survey->survey_date?->format('M j, Y') }}</strong> and its decision-support output will be permanently deleted.</p>
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
                        <x-heroicon-o-clipboard-document-list class="w-10 h-10 text-ink-200 dark:text-ink-700 mx-auto mb-2" />
                        <p class="font-serif text-base text-ink-700 dark:text-[#c8c4bc]">No surveys found.</p>
                        <p class="text-sm text-ink-400 dark:text-[#6b7570] mt-1">Adjust your filters or add a new survey to a senior profile.</p>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>
        @if ($surveys->hasPages())
        <div class="border-t border-paper-rule dark:border-[#2b3530] px-5 py-3">
            {{ $surveys->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
