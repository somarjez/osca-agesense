@extends('layouts.app')
@section('page-title', 'Senior Citizen Records')
@section('page-subtitle', number_format($stats['total']) . ' active seniors · Pagsanjan, Laguna')

@section('content')
<div class="space-y-6">

    {{-- Stats strip --}}
    <div class="grid grid-cols-2 gap-4">
        <x-kpi label="Total Active" :value="number_format($stats['total'])" accent="forest" sub="Active records · Pagsanjan, Laguna" />

        {{-- High Risk merged with Urgent Priority --}}
        <div class="kpi kpi-high relative overflow-hidden">
            <div class="kpi-rule bg-high-500"></div>
            <div class="kpi-label">High Risk</div>
            <div class="flex items-baseline gap-2">
                <div class="kpi-value text-high-700">{{ number_format($stats['high']) }}</div>
                @if (($stats['urgent'] ?? 0) > 0)
                <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-high-700 bg-high-50 border border-high-200 px-1.5 py-0.5 rounded">
                    <span class="w-1.5 h-1.5 rounded-full bg-high-500 animate-pulse flex-shrink-0"></span>
                    {{ $stats['urgent'] }} urgent
                </span>
                @endif
            </div>
            <div class="kpi-delta text-high-600">
                Need priority action
                @if (($stats['urgent'] ?? 0) > 0)
                · <span class="font-semibold">{{ $stats['urgent'] }}</span> score ≥ 0.70
                @endif
            </div>
        </div>
    </div>

    {{-- Filter + Search --}}
    <form method="GET" class="card">
        <div class="card-head">
            <div class="card-title">Filter Records</div>
            <a href="{{ route('seniors.create') }}" class="btn btn-primary">
                <x-heroicon-o-user-plus class="w-3.5 h-3.5" />
                New Senior
            </a>
        </div>
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
                    @foreach (['HIGH','MODERATE','LOW'] as $r)
                        <option value="{{ $r }}" {{ strtoupper(request('risk'))==$r?'selected':'' }}>{{ ucfirst(strtolower($r)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-[180px]">
                <label class="eyebrow block mb-1.5">Health Group</label>
                <select name="cluster" class="form-select">
                    <option value="">All Groups</option>
                    <option value="1" {{ request('cluster')=='1'?'selected':'' }}>Group 1 · High Functioning</option>
                    <option value="2" {{ request('cluster')=='2'?'selected':'' }}>Group 2 · Moderate</option>
                    <option value="3" {{ request('cluster')=='3'?'selected':'' }}>Group 3 · Low Functioning</option>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <x-heroicon-o-magnifying-glass class="w-3.5 h-3.5" />
                    Search
                </button>
                @if (request()->hasAny(['search','barangay','risk','cluster']))
                    <a href="{{ route('seniors.index') }}" class="btn">Clear filters</a>
                @endif
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
                        @php
                        $clusterTips = [
                            1 => '<strong class="text-ink-900 dark:text-[#e4e1d8]">Group 1 · High Functioning</strong><br>Generally independent seniors with good physical capacity and strong social support. Lower care needs; focus on maintenance and preventive programs.',
                            2 => '<strong class="text-ink-900 dark:text-[#e4e1d8]">Group 2 · Moderate</strong><br>Seniors with moderate limitations in at least one domain. May need assistance with some daily activities or show early signs of decline.',
                            3 => '<strong class="text-ink-900 dark:text-[#e4e1d8]">Group 3 · Low Functioning</strong><br>Seniors with significant impairments across multiple domains. Higher dependency and care needs; priority for targeted interventions.',
                        ];
                        @endphp
                        <x-tooltip :text="$clusterTips[$ml->cluster_named_id] ?? ''" position="top" width="w-64">
                            <x-cluster-badge :id="$ml->cluster_named_id" />
                        </x-tooltip>
                        @else
                            <span class="text-ink-300">—</span>
                        @endif
                    </td>
                    <td class="td text-center">
                        @if ($ml?->overall_risk_level)
                        @php
                        $riskTips = [
                            'LOW'      => '<strong class="text-ink-900 dark:text-[#e4e1d8]">Low Risk</strong><br>Minimal vulnerabilities detected. Senior is largely self-sufficient with stable health and support conditions.',
                            'MODERATE' => '<strong class="text-ink-900 dark:text-[#e4e1d8]">Moderate Risk</strong><br>Some vulnerabilities present. Monitor closely and consider light-touch interventions to prevent further decline.',
                            'HIGH'     => '<strong class="text-ink-900 dark:text-[#e4e1d8]">High Risk</strong><br>Multiple risk factors identified. Priority action recommended. Seniors with score ≥ 0.70 are flagged urgent-priority.',
                        ];
                        @endphp
                        <x-tooltip :text="$riskTips[strtoupper($ml->overall_risk_level)] ?? ''" position="top" width="w-64">
                            <x-risk-badge :level="$ml->overall_risk_level" :priority="$ml->priority_flag" />
                        </x-tooltip>
                        @else
                            <span class="text-ink-300 text-[11px]">Unassessed</span>
                        @endif
                    </td>
                    <td class="td">
                        @if ($ml?->composite_risk !== null)
                        @php
                        $pct = round($ml->composite_risk * 100);
                        $urgentNote = ($ml->priority_flag === 'urgent') ? ' · Urgent-priority (≥70%)' : '';
                        $compositeTip = '<strong class="text-ink-900 dark:text-[#e4e1d8]">Composite Risk Score: ' . $pct . '%</strong><br>'
                            . 'A weighted aggregate of physical, environmental, and functional risk scores from the ML assessment.' . $urgentNote . '<br>'
                            . '<span class="mt-1.5 inline-block text-ink-400">0–29% Low &nbsp;·&nbsp; 30–49% Moderate &nbsp;·&nbsp; 50%+ High &nbsp;·&nbsp; 70%+ Urgent-priority</span>';
                        @endphp
                        <x-tooltip :text="$compositeTip" position="top" width="w-72">
                            <x-risk-bar :value="$ml->composite_risk" />
                        </x-tooltip>
                        @else
                            <span class="text-ink-300 text-xs">—</span>
                        @endif
                    </td>
                    <td class="td">
                        <div class="flex items-center justify-end gap-1" x-data="{ archiveOpen: false }">
                            <a href="{{ route('seniors.show', $senior) }}"
                               class="btn btn-ghost text-[11.5px] px-2.5 py-1.5 gap-1.5"
                               title="View profile">
                                <x-heroicon-o-eye class="w-3.5 h-3.5" /> View
                            </a>
                            <a href="{{ route('seniors.edit', $senior) }}"
                               class="btn btn-ghost text-[11.5px] px-2.5 py-1.5 gap-1.5"
                               title="Edit profile">
                                <x-heroicon-o-pencil class="w-3.5 h-3.5" /> Edit
                            </a>
                            <a href="{{ route('surveys.qol.create', $senior) }}"
                               class="btn btn-ghost text-[11.5px] px-2.5 py-1.5 gap-1.5"
                               title="New QoL survey">
                                <x-heroicon-o-clipboard-document-list class="w-3.5 h-3.5" /> QoL
                            </a>
                            <button @click="archiveOpen = true"
                                    class="btn btn-ghost text-[11.5px] px-2.5 py-1.5 text-high-700 hover:text-high-900 hover:bg-high-50 dark:hover:bg-high-50/10"
                                    title="Archive record">
                                <x-heroicon-o-archive-box class="w-3.5 h-3.5" />
                            </button>
                            <form x-ref="archiveForm" method="POST" action="{{ route('seniors.destroy', $senior) }}" class="hidden">
                                @csrf @method('DELETE')
                            </form>
                            <div x-show="archiveOpen" x-cloak
                                 class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4"
                                 @keydown.escape.window="archiveOpen = false">
                                <div class="card max-w-sm w-full shadow-2xl"
                                     @click.outside="archiveOpen = false">
                                    <div class="card-head">
                                        <div class="flex items-center gap-2.5">
                                            <div class="w-8 h-8 rounded-lg bg-high-50 grid place-items-center flex-shrink-0">
                                                <x-heroicon-o-archive-box class="w-4 h-4 text-high-700" />
                                            </div>
                                            <div class="card-title">Archive record?</div>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <p class="text-sm text-ink-700">
                                            <span class="font-semibold text-ink-900">{{ $senior->full_name }}</span>
                                            will be moved to Archives. Their data is preserved and can be restored at any time.
                                        </p>
                                        <div class="flex gap-2.5 justify-end mt-5">
                                            <button @click="archiveOpen = false" class="btn">Cancel</button>
                                            <button @click="$refs.archiveForm.submit()"
                                                    class="btn bg-high-600 border-high-600 text-white hover:bg-high-700 hover:border-high-700 hover:text-white">
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
                    <td colspan="8" class="px-4 py-16 text-center">
                        <p class="font-serif text-base text-ink-500">No senior citizens found.</p>
                        <p class="text-[12.5px] text-ink-400 mt-1">Try adjusting your filters or register a new senior.</p>
                        <a href="{{ route('seniors.create') }}" class="btn btn-primary mt-4 inline-flex">
                            <x-heroicon-o-user-plus class="w-3.5 h-3.5" /> New Senior
                        </a>
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
