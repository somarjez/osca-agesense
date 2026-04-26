@extends('layouts.app')
@section('page-title', 'Senior Citizen Records')
@section('page-subtitle', number_format($stats['total']) . ' active seniors · Pagsanjan, Laguna')

@section('content')
<div class="space-y-6">

    {{-- Stats strip --}}
    <div class="grid grid-cols-3 gap-4">
        <x-kpi label="Total Active"  :value="number_format($stats['total'])"    accent="forest" />
        <x-kpi label="Critical Risk" :value="number_format($stats['critical'])" accent="critical" valueColor="text-critical-700" />
        <x-kpi label="High Risk"     :value="number_format($stats['high'])"     accent="high"     valueColor="text-high-700" />
    </div>

    {{-- Filter + Search --}}
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
            <div class="min-w-[140px]">
                <label class="eyebrow block mb-1.5">Risk Level</label>
                <select name="risk" class="form-select">
                    <option value="">All</option>
                    @foreach (['CRITICAL','HIGH','MODERATE','LOW'] as $r)
                        <option value="{{ $r }}" {{ strtoupper(request('risk'))==$r?'selected':'' }}>{{ $r }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-[180px]">
                <label class="eyebrow block mb-1.5">Cluster</label>
                <select name="cluster" class="form-select">
                    <option value="">All</option>
                    <option value="1" {{ request('cluster')=='1'?'selected':'' }}>C1 · High Functioning</option>
                    <option value="2" {{ request('cluster')=='2'?'selected':'' }}>C2 · Moderate</option>
                    <option value="3" {{ request('cluster')=='3'?'selected':'' }}>C3 · Low Functioning</option>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary">Search</button>
                <a href="{{ route('seniors.index') }}" class="btn">Clear</a>
                <a href="{{ route('seniors.create') }}" class="btn btn-primary">+ New Senior</a>
            </div>
        </div>
    </form>

    {{-- Table --}}
    <div class="card overflow-hidden">
        <table class="w-full">
            <thead>
                <tr>
                    <th class="th">OSCA ID</th>
                    <th class="th">Name</th>
                    <th class="th">Barangay</th>
                    <th class="th text-center">Age</th>
                    <th class="th text-center">Cluster</th>
                    <th class="th text-center">Risk</th>
                    <th class="th">Composite Risk</th>
                    <th class="th text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($seniors as $senior)
                @php $ml = $senior->latestMlResult; @endphp
                <tr class="group hover:bg-paper-2 transition-colors">
                    <td class="td">
                        <span class="font-mono text-[11.5px] text-ink-500 tnum">{{ $senior->osca_id }}</span>
                    </td>
                    <td class="td">
                        <a href="{{ route('seniors.show', $senior) }}" class="flex items-center gap-3">
                            <div class="w-7 h-7 rounded-full bg-forest-100 grid place-items-center flex-shrink-0">
                                <span class="text-[11px] font-semibold text-forest-800">{{ strtoupper(substr($senior->first_name,0,1).substr($senior->last_name,0,1)) }}</span>
                            </div>
                            <div>
                                <p class="font-semibold text-ink-900">{{ $senior->full_name }}</p>
                                <p class="text-[11.5px] text-ink-500">{{ $senior->gender }} · {{ $senior->marital_status }}</p>
                            </div>
                        </a>
                    </td>
                    <td class="td text-ink-700">{{ $senior->barangay }}</td>
                    <td class="td text-center font-mono tnum text-ink-900">{{ $senior->age }}</td>
                    <td class="td text-center">
                        @if ($ml?->cluster_named_id)
                            <x-cluster-badge :id="$ml->cluster_named_id" />
                        @else
                            <span class="text-ink-300">—</span>
                        @endif
                    </td>
                    <td class="td text-center">
                        @if ($ml?->overall_risk_level)
                            <x-risk-badge :level="$ml->overall_risk_level" />
                        @else
                            <span class="text-ink-300 text-xs">No data</span>
                        @endif
                    </td>
                    <td class="td">
                        @if ($ml?->composite_risk !== null)
                            <x-risk-bar :value="$ml->composite_risk" />
                        @else
                            <span class="text-ink-300 text-xs">—</span>
                        @endif
                    </td>
                    <td class="td">
                        <div class="flex items-center justify-end gap-1.5">
                            <a href="{{ route('seniors.show', $senior) }}" class="btn btn-ghost text-[11.5px] px-2 py-1">View</a>
                            <a href="{{ route('surveys.qol.create', $senior) }}" class="btn btn-ghost text-[11.5px] px-2 py-1">QoL</a>
                            <a href="{{ route('seniors.edit', $senior) }}" class="btn btn-ghost text-[11.5px] px-2 py-1">Edit</a>
                            <div x-data="{ open: false }">
                                <button @click="open = true"
                                        class="btn btn-ghost text-[11.5px] px-2 py-1 text-critical-700 hover:text-critical-700 hover:bg-critical-50">
                                    Archive
                                </button>
                                <form x-ref="archiveForm" method="POST" action="{{ route('seniors.destroy', $senior) }}" class="hidden">
                                    @csrf @method('DELETE')
                                </form>
                                <div x-show="open" x-cloak
                                     class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
                                     @keydown.escape.window="open = false">
                                    <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6"
                                         @click.outside="open = false">
                                        <div class="flex items-start gap-3 mb-4">
                                            <div class="w-9 h-9 rounded-full bg-amber-50 flex items-center justify-center flex-shrink-0 text-lg">📦</div>
                                            <div>
                                                <h3 class="font-semibold text-slate-800">Archive this record?</h3>
                                                <p class="text-sm text-slate-500 mt-1">
                                                    <strong>{{ $senior->full_name }}</strong> will be moved to Archives. Their data is preserved and can be restored at any time.
                                                </p>
                                            </div>
                                        </div>
                                        <div class="flex gap-3 justify-end pt-2 border-t border-slate-100">
                                            <button @click="open = false"
                                                    class="px-4 py-2 text-sm text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors">
                                                Cancel
                                            </button>
                                            <button @click="$refs.archiveForm.submit()"
                                                    class="px-4 py-2 bg-amber-600 text-white text-sm font-semibold rounded-lg hover:bg-amber-700 transition-colors">
                                                Archive
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
                    <td colspan="8" class="td text-center py-16">
                        <p class="font-serif text-lg text-ink-700">No senior citizens found.</p>
                        <a href="{{ route('seniors.create') }}" class="text-forest-700 hover:text-forest-900 text-sm mt-2 inline-block font-semibold">Register the first one →</a>
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
