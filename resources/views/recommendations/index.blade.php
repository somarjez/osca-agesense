{{-- resources/views/recommendations/index.blade.php --}}
@extends('layouts.app')
@section('page-title', 'Recommendations Management')
@section('page-subtitle', 'Track, assign, and update care action items for senior citizens')

@section('content')
<div class="space-y-4">

    {{-- ── Filters ── --}}
    <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <form method="GET" class="flex flex-wrap gap-3 items-end">
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Status</label>
                <select name="status" class="text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    <option value="">All Statuses</option>
                    @foreach (['pending','in_progress','completed','dismissed'] as $s)
                    <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucfirst(str_replace('_',' ',$s)) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Urgency</label>
                <select name="urgency" class="text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    <option value="">All Urgencies</option>
                    @foreach ($urgencies as $u)
                    <option value="{{ $u }}" {{ request('urgency') === $u ? 'selected' : '' }}>{{ ucfirst($u) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Category</label>
                <select name="category" class="text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    <option value="">All Categories</option>
                    @foreach ($categories as $cat)
                    <option value="{{ $cat }}" {{ request('category') === $cat ? 'selected' : '' }}>{{ ucfirst(str_replace('_',' ',$cat)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="px-4 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700 shadow-sm">
                    Filter
                </button>
                @if (request()->hasAny(['status','urgency','category','barangay']))
                <a href="{{ route('recommendations.index') }}"
                   class="px-4 py-2 border border-slate-200 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-50">
                    Clear
                </a>
                @endif
            </div>
        </form>
    </div>

    {{-- ── Table ── --}}
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr class="text-xs text-slate-500 font-semibold uppercase tracking-wider">
                        <th class="px-4 py-3 text-left w-8">
                            <input type="checkbox" class="rounded border-slate-300" id="select-all">
                        </th>
                        <th class="px-4 py-3 text-left">Senior Citizen</th>
                        <th class="px-4 py-3 text-left">Action</th>
                        <th class="px-4 py-3 text-left">Category</th>
                        <th class="px-4 py-3 text-left">Urgency</th>
                        <th class="px-4 py-3 text-left">Status</th>
                        <th class="px-4 py-3 text-right">Update</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100" x-data="{ selected: [] }">
                    @forelse ($recs as $rec)
                    <tr class="hover:bg-slate-25 transition-colors">
                        <td class="px-4 py-3">
                            <input type="checkbox" class="rounded border-slate-300 rec-checkbox" value="{{ $rec->id }}">
                        </td>
                        <td class="px-4 py-3">
                            <a href="{{ route('seniors.show', $rec->senior_citizen_id) }}"
                               class="font-medium text-teal-600 hover:text-teal-700 hover:underline">
                                {{ $rec->seniorCitizen?->full_name }}
                            </a>
                            <div class="text-xs text-slate-400">{{ $rec->seniorCitizen?->barangay }}</div>
                        </td>
                        <td class="px-4 py-3 max-w-xs">
                            <p class="text-slate-700 text-xs leading-relaxed line-clamp-2">{{ $rec->action }}</p>
                            @if ($rec->domain && $rec->domain !== 'general')
                            <span class="text-xs text-slate-400">{{ str_replace('_',' ', $rec->domain) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @php $catIcons = ['health'=>'🏥','financial'=>'💰','social'=>'👥','functional'=>'🦾','hc_access'=>'🏨','general'=>'📌']; @endphp
                            <span class="text-xs bg-slate-100 text-slate-600 px-2 py-0.5 rounded-full">
                                {{ $catIcons[$rec->category] ?? '📌' }} {{ ucfirst(str_replace('_',' ',$rec->category)) }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="{{ $rec->urgency_badge }} text-xs px-2 py-0.5 rounded-full font-medium">
                                {{ ucfirst($rec->urgency) }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <form method="POST" action="{{ route('recommendations.status', $rec) }}">
                                @csrf @method('PATCH')
                                <select name="status" onchange="this.form.submit()"
                                        class="text-xs border border-slate-200 rounded-lg px-2 py-1 bg-white focus:ring-1 focus:ring-teal-500 focus:outline-none
                                            {{ match($rec->status) {
                                                'pending'     => 'text-amber-700',
                                                'in_progress' => 'text-blue-700',
                                                'completed'   => 'text-emerald-700',
                                                'dismissed'   => 'text-slate-500',
                                                default       => '',
                                            } }}">
                                    @foreach (['pending','in_progress','completed','dismissed'] as $s)
                                    <option value="{{ $s }}" {{ $rec->status === $s ? 'selected' : '' }}>
                                        {{ ucfirst(str_replace('_',' ',$s)) }}
                                    </option>
                                    @endforeach
                                </select>
                            </form>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('recommendations.show', $rec) }}"
                               class="text-xs text-slate-400 hover:text-teal-600 transition-colors">
                                Details →
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-slate-400">
                            <div class="text-3xl mb-2">✅</div>
                            No recommendations match your filters.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Bulk Actions --}}
        <div class="border-t border-slate-100 px-4 py-3 flex items-center gap-3 bg-slate-50" id="bulk-bar" style="display:none!important">
            <span class="text-sm text-slate-600" id="selected-count">0 selected</span>
            <form method="POST" action="{{ route('recommendations.index') }}">
                @csrf
                <input type="hidden" name="ids" id="bulk-ids">
                <select name="status" class="text-sm border border-slate-200 rounded-lg px-2 py-1 mr-2">
                    @foreach (['pending','in_progress','completed','dismissed'] as $s)
                    <option value="{{ $s }}">Mark as {{ ucfirst(str_replace('_',' ',$s)) }}</option>
                    @endforeach
                </select>
                <button type="submit" class="px-3 py-1.5 bg-teal-600 text-white text-sm rounded-lg hover:bg-teal-700">Apply</button>
            </form>
        </div>

        @if ($recs->hasPages())
        <div class="border-t border-slate-100 px-4 py-3">
            {{ $recs->links() }}
        </div>
        @endif
    </div>
</div>

@push('scripts')
<script>
const checkboxes = document.querySelectorAll('.rec-checkbox');
const selectAll  = document.getElementById('select-all');
const bulkBar    = document.getElementById('bulk-bar');
const bulkIds    = document.getElementById('bulk-ids');
const selCount   = document.getElementById('selected-count');

function updateBulk() {
    const checked = [...checkboxes].filter(c => c.checked).map(c => c.value);
    bulkIds.value = JSON.stringify(checked);
    selCount.textContent = checked.length + ' selected';
    bulkBar.style.display = checked.length > 0 ? 'flex' : 'none';
}

selectAll.addEventListener('change', function() {
    checkboxes.forEach(c => c.checked = this.checked);
    updateBulk();
});
checkboxes.forEach(c => c.addEventListener('change', updateBulk));
</script>
@endpush
@endsection
