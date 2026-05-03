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
                                        class="text-xs px-2 py-1 bg-red-50 text-red-700 border border-red-200 rounded-md hover:bg-red-100 font-medium transition-colors">
                                    Delete
                                </button>
                                <form x-ref="deleteForm" method="POST" action="{{ route('surveys.qol.destroy', $survey) }}" class="hidden">
                                    @csrf @method('DELETE')
                                </form>
                                <div x-show="open" x-cloak
                                     class="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4"
                                     @keydown.escape.window="open = false">
                                    <div class="rounded-2xl shadow-2xl max-w-sm w-full p-6"
                                         style="background:#ffffff !important; color:#1e293b;"
                                         @click.outside="open = false">
                                        <div class="flex items-start gap-3 mb-4">
                                            <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0" style="background:#fee2e2;">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                            </div>
                                            <div>
                                                <h3 class="font-semibold" style="color:#1e293b;">Delete QoL Survey?</h3>
                                                <p class="text-sm mt-1" style="color:#64748b;">
                                                    The survey from <strong style="color:#334155;">{{ $survey->survey_date?->format('M j, Y') }}</strong> and its ML results will be permanently deleted.
                                                </p>
                                                <p class="text-xs font-semibold mt-2 px-3 py-1.5 rounded-lg" style="color:#dc2626; background:#fef2f2;">
                                                    This cannot be undone.
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
                        <div class="flex justify-center mb-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
                        </div>
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
