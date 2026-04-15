@extends('layouts.app')
@section('page-title', 'Senior Citizen Records')
@section('page-subtitle', number_format($stats['total']) . ' active seniors — Pagsanjan, Laguna')

@section('content')
<div class="space-y-5">

    {{-- Stats strip --}}
    <div class="grid grid-cols-3 gap-4">
        @foreach ([
            ['Total Active',  $stats['total'],    'teal'],
            ['Critical Risk', $stats['critical'], 'red'],
            ['High Risk',     $stats['high'],     'orange'],
        ] as [$label, $val, $color])
        <div class="bg-white border border-slate-200 rounded-xl px-4 py-3 flex items-center gap-4 shadow-sm">
            <span class="text-2xl font-bold text-{{ $color }}-600">{{ number_format($val) }}</span>
            <span class="text-sm text-slate-500">{{ $label }}</span>
        </div>
        @endforeach
    </div>

    {{-- Filter + Search --}}
    <form method="GET" class="bg-white border border-slate-200 rounded-xl px-5 py-4 shadow-sm flex flex-wrap items-end gap-4">
        <div class="flex-1 min-w-48">
            <label class="block text-xs font-medium text-slate-500 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}"
                   placeholder="Name or OSCA ID…"
                   class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-teal-500">
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">Barangay</label>
            <select name="barangay" class="text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500">
                <option value="">All</option>
                @foreach ($barangays as $brgy)
                    <option value="{{ $brgy }}" {{ request('barangay')==$brgy?'selected':'' }}>{{ $brgy }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">Risk Level</label>
            <select name="risk" class="text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500">
                <option value="">All</option>
                @foreach (['CRITICAL','HIGH','MODERATE','LOW'] as $r)
                    <option value="{{ $r }}" {{ strtoupper(request('risk'))==$r?'selected':'' }}>{{ $r }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">Cluster</label>
            <select name="cluster" class="text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500">
                <option value="">All</option>
                <option value="1" {{ request('cluster')=='1'?'selected':'' }}>1 – High Functioning</option>
                <option value="2" {{ request('cluster')=='2'?'selected':'' }}>2 – Moderate</option>
                <option value="3" {{ request('cluster')=='3'?'selected':'' }}>3 – Low Functioning</option>
            </select>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="px-4 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700 shadow-sm">
                Search
            </button>
            <a href="{{ route('seniors.index') }}" class="px-4 py-2 border border-slate-200 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-50">
                Clear
            </a>
            <a href="{{ route('seniors.create') }}" class="px-4 py-2 bg-emerald-600 text-white text-sm font-medium rounded-lg hover:bg-emerald-700 shadow-sm">
                + New Senior
            </a>
        </div>
    </form>

    {{-- Table --}}
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr class="text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                    <th class="px-4 py-3">OSCA ID</th>
                    <th class="px-4 py-3">Name</th>
                    <th class="px-4 py-3">Barangay</th>
                    <th class="px-4 py-3 text-center">Age</th>
                    <th class="px-4 py-3 text-center">Cluster</th>
                    <th class="px-4 py-3 text-center">Risk Level</th>
                    <th class="px-4 py-3 text-center">Composite Risk</th>
                    <th class="px-4 py-3 text-center">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse ($seniors as $senior)
                @php $ml = $senior->latestMlResult; @endphp
                <tr class="hover:bg-slate-25 transition-colors group">
                    <td class="px-4 py-3">
                        <span class="font-mono text-xs bg-slate-100 px-2 py-0.5 rounded text-slate-600">
                            {{ $senior->osca_id }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <div class="w-7 h-7 rounded-full bg-teal-100 flex items-center justify-center flex-shrink-0">
                                <span class="text-xs font-bold text-teal-700">{{ substr($senior->first_name,0,1) }}</span>
                            </div>
                            <div>
                                <p class="font-medium text-slate-800">{{ $senior->full_name }}</p>
                                <p class="text-xs text-slate-400">{{ $senior->gender }} · {{ $senior->marital_status }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-slate-600">{{ $senior->barangay }}</td>
                    <td class="px-4 py-3 text-center text-slate-700 font-medium">{{ $senior->age }}</td>
                    <td class="px-4 py-3 text-center">
                        @if ($ml?->cluster_named_id)
                        <span class="text-xs font-semibold px-2 py-1 rounded-full
                            {{ match($ml->cluster_named_id) {
                                1 => 'bg-emerald-100 text-emerald-700',
                                2 => 'bg-amber-100 text-amber-700',
                                3 => 'bg-rose-100 text-rose-700',
                                default => 'bg-slate-100 text-slate-600'
                            } }}">
                            C{{ $ml->cluster_named_id }}
                        </span>
                        @else
                        <span class="text-xs text-slate-300">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-center">
                        @if ($ml?->overall_risk_level)
                        <span class="text-xs font-bold px-2 py-1 rounded-full
                            {{ match($ml->overall_risk_level) {
                                'CRITICAL' => 'bg-red-100 text-red-700',
                                'HIGH'     => 'bg-orange-100 text-orange-700',
                                'MODERATE' => 'bg-amber-100 text-amber-700',
                                'LOW'      => 'bg-emerald-100 text-emerald-700',
                                default    => 'bg-slate-100 text-slate-600'
                            } }}">
                            {{ $ml->overall_risk_level }}
                        </span>
                        @else
                        <span class="text-xs text-slate-300">No data</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-center">
                        @if ($ml?->composite_risk !== null)
                        <div class="flex items-center justify-center gap-2">
                            <div class="w-16 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                                <div class="h-full rounded-full {{ $ml->composite_risk > 0.65 ? 'bg-red-500' : ($ml->composite_risk > 0.45 ? 'bg-amber-400' : 'bg-emerald-500') }}"
                                     style="width: {{ round($ml->composite_risk * 100) }}%"></div>
                            </div>
                            <span class="text-xs text-slate-600 font-medium">{{ round($ml->composite_risk * 100, 1) }}%</span>
                        </div>
                        @else
                        <span class="text-xs text-slate-300">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                            <a href="{{ route('seniors.show', $senior) }}"
                               class="text-xs px-2 py-1 bg-teal-50 text-teal-700 rounded-md hover:bg-teal-100 font-medium">View</a>
                            <a href="{{ route('surveys.qol.create', $senior) }}"
                               class="text-xs px-2 py-1 bg-purple-50 text-purple-700 rounded-md hover:bg-purple-100 font-medium">QoL</a>
                            <a href="{{ route('seniors.edit', $senior) }}"
                               class="text-xs px-2 py-1 bg-slate-50 text-slate-600 rounded-md hover:bg-slate-100 font-medium">Edit</a>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-4 py-12 text-center text-slate-400">
                        <div class="text-3xl mb-2">👥</div>
                        <p class="font-medium">No senior citizens found.</p>
                        <a href="{{ route('seniors.create') }}" class="text-teal-600 hover:underline text-sm mt-1 inline-block">Register the first one →</a>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>

        @if ($seniors->hasPages())
        <div class="border-t border-slate-100 px-4 py-3">
            {{ $seniors->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
