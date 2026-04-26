@extends('layouts.app')
@section('page-title', 'QoL Surveys')
@section('page-subtitle', 'Quality of Life survey submissions and results')

@section('content')
<div class="space-y-5">
    {{-- Filters --}}
    <form method="GET" class="bg-white border border-slate-200 rounded-xl px-5 py-4 shadow-sm flex flex-wrap items-end gap-4">
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">Status</label>
            <select name="status" class="text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500">
                <option value="">All</option>
                <option value="draft" {{ request('status')==='draft'?'selected':'' }}>Draft</option>
                <option value="submitted" {{ request('status')==='submitted'?'selected':'' }}>Submitted</option>
                <option value="processed" {{ request('status')==='processed'?'selected':'' }}>Processed</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">Barangay</label>
            <select name="barangay" class="text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500">
                <option value="">All</option>
                @foreach (\App\Models\SeniorCitizen::barangayList() as $b)
                    <option value="{{ $b }}" {{ request('barangay')===$b?'selected':'' }}>{{ $b }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="px-4 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700">Filter</button>
        <a href="{{ route('surveys.qol.index') }}" class="px-4 py-2 border border-slate-200 text-slate-600 text-sm rounded-lg hover:bg-slate-50">Clear</a>
    </form>

    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr class="text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                    <th class="px-4 py-3">Senior</th>
                    <th class="px-4 py-3">Barangay</th>
                    <th class="px-4 py-3">Survey Date</th>
                    <th class="px-4 py-3 text-center">Overall Score</th>
                    <th class="px-4 py-3 text-center">Status</th>
                    <th class="px-4 py-3 text-center">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse ($surveys as $survey)
                <tr class="hover:bg-slate-25 transition-colors group">
                    <td class="px-4 py-3 font-medium text-slate-800">{{ $survey->seniorCitizen?->full_name }}</td>
                    <td class="px-4 py-3 text-slate-500">{{ $survey->seniorCitizen?->barangay }}</td>
                    <td class="px-4 py-3 text-slate-500">{{ $survey->survey_date?->format('M j, Y') }}</td>
                    <td class="px-4 py-3 text-center">
                        @if ($survey->overall_score !== null)
                        <div class="flex items-center justify-center gap-2">
                            <div class="w-14 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                                <div class="h-full rounded-full {{ $survey->overall_score >= 0.7 ? 'bg-emerald-500' : ($survey->overall_score >= 0.5 ? 'bg-amber-400' : 'bg-red-400') }}"
                                     style="width: {{ round($survey->overall_score * 100) }}%"></div>
                            </div>
                            <span class="text-xs font-semibold text-slate-700">{{ round($survey->overall_score * 100, 1) }}%</span>
                        </div>
                        @else
                        <span class="text-slate-300 text-xs">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-full
                            {{ match($survey->status) {
                                'processed'  => 'bg-emerald-100 text-emerald-700',
                                'submitted'  => 'bg-blue-100 text-blue-700',
                                'draft'      => 'bg-slate-100 text-slate-500',
                                default      => 'bg-slate-100 text-slate-500',
                            } }}">
                            {{ ucfirst($survey->status) }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex justify-center gap-2">
                            @if ($survey->status === 'processed')
                            <a href="{{ route('surveys.qol.results', $survey) }}"
                               class="text-xs px-2 py-1 bg-teal-50 text-teal-700 rounded-md hover:bg-teal-100 font-medium">Results</a>
                            @endif
                            <a href="{{ route('surveys.qol.edit', $survey) }}"
                               class="text-xs px-2 py-1 bg-slate-50 text-slate-600 rounded-md hover:bg-slate-100 font-medium">Edit</a>
                            <div x-data="{ open: false }">
                                <button @click="open = true"
                                        class="text-xs px-2 py-1 bg-red-50 text-red-600 rounded-md hover:bg-red-100 font-medium">
                                    Delete
                                </button>
                                <form x-ref="deleteForm" method="POST" action="{{ route('surveys.qol.destroy', $survey) }}" class="hidden">
                                    @csrf @method('DELETE')
                                </form>
                                <div x-show="open" x-cloak
                                     class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
                                     @keydown.escape.window="open = false">
                                    <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6"
                                         @click.outside="open = false">
                                        <div class="flex items-start gap-3 mb-4">
                                            <div class="w-9 h-9 rounded-full bg-red-50 flex items-center justify-center flex-shrink-0 text-lg">🗑️</div>
                                            <div>
                                                <h3 class="font-semibold text-slate-800">Delete QoL Survey?</h3>
                                                <p class="text-sm text-slate-500 mt-1">
                                                    The survey from <strong>{{ $survey->survey_date?->format('M j, Y') }}</strong> and its ML results will be permanently deleted.
                                                </p>
                                                <p class="text-xs font-semibold text-red-600 mt-2 bg-red-50 px-3 py-1.5 rounded-lg">
                                                    ⚠ This cannot be undone.
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
                                                Delete Survey
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
                    <td colspan="6" class="px-4 py-12 text-center text-slate-400">
                        <div class="text-3xl mb-2">📋</div>
                        <p class="font-medium">No surveys found.</p>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        @if ($surveys->hasPages())
        <div class="border-t border-slate-100 px-4 py-3">{{ $surveys->links() }}</div>
        @endif
    </div>
</div>
@endsection
