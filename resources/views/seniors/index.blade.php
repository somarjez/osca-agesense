@extends('layouts.app')
@section('page-title', 'Senior Citizen Records')
@section('page-subtitle', number_format($stats['total']) . ' active seniors · Pagsanjan, Laguna')

@section('content')
<div class="space-y-6" x-data="seniorIndex()">

    <x-breadcrumb :links="[
        ['label' => 'Dashboard', 'href' => route('dashboard')],
        ['label' => 'Senior Records'],
    ]" />
    <x-page-header
        title="Senior Citizen Records"
        :subtitle="number_format($stats['total']) . ' active seniors · Pagsanjan, Laguna'"
    />

    {{-- Stats strip — compact inline row --}}
    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 px-0.5 text-[13px]">
        <div class="flex items-center gap-2.5">
            <span class="font-mono font-bold text-[22px] leading-none text-ink-900 dark:text-[#e4e1d8] tnum">{{ number_format($stats['total']) }}</span>
            <span class="text-ink-500 dark:text-[#8a9087]">active seniors</span>
        </div>
        <span class="text-ink-200 dark:text-[#2b3530] select-none">·</span>
        <div class="flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-high-500 flex-shrink-0"></span>
            <span class="font-mono font-semibold text-high-700 tnum">{{ number_format($stats['high']) }}</span>
            <span class="text-ink-500 dark:text-[#8a9087]">high risk</span>
            @if (($stats['urgent'] ?? 0) > 0)
            <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-high-700 bg-high-50 border border-high-100 px-2 py-0.5 rounded-full">
                <span class="w-1.5 h-1.5 rounded-full bg-high-500 animate-pulse flex-shrink-0"></span>
                {{ $stats['urgent'] }} urgent
            </span>
            @endif
        </div>
        <div class="ml-auto flex items-center gap-1 text-[12px] text-ink-400 dark:text-[#6b7570]">
            <x-heroicon-o-map-pin class="w-3 h-3" />
            <span>Pagsanjan, Laguna</span>
        </div>
    </div>

    {{-- Filter + Search --}}
    <form method="GET" id="seniors-filter-form" class="card">
        <div class="card-head">
            <div class="flex items-center gap-2.5">
                <x-heroicon-o-funnel class="w-4 h-4 text-ink-400" />
                <div class="card-title">Filter Records</div>
                @if (request()->hasAny(['search','barangay','risk','cluster']))
                    <span class="badge badge-info">Filtered</span>
                @endif
            </div>
            <div class="flex items-center gap-2">
                <button type="button" @click.stop="uploadOpen = true" class="btn btn-ghost">
                    <x-heroicon-o-arrow-up-tray class="w-3.5 h-3.5" />
                    Bulk Upload
                </button>
                <a href="{{ route('seniors.create') }}" wire:navigate class="btn btn-primary">
                    <x-heroicon-o-user-plus class="w-3.5 h-3.5" />
                    New Senior
                </a>
            </div>
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
                <select name="barangay" class="form-select" onchange="document.getElementById('seniors-filter-form').submit()">
                    <option value="">All Barangays</option>
                    @foreach ($barangays as $brgy)
                        <option value="{{ $brgy }}" {{ request('barangay')==$brgy?'selected':'' }}>{{ $brgy }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-[140px]">
                <label class="eyebrow block mb-1.5">Risk Level</label>
                <select name="risk" class="form-select" onchange="document.getElementById('seniors-filter-form').submit()">
                    <option value="">All Levels</option>
                    @foreach (['HIGH','MODERATE','LOW'] as $r)
                        <option value="{{ $r }}" {{ strtoupper(request('risk'))==$r?'selected':'' }}>{{ ucfirst(strtolower($r)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-[180px]">
                <label class="eyebrow block mb-1.5">Profile Group</label>
                <select name="cluster" class="form-select" onchange="document.getElementById('seniors-filter-form').submit()">
                    <option value="">All Groups</option>
                    <option value="1" {{ request('cluster')=='1'?'selected':'' }}>Group 1 · High Functioning</option>
                    <option value="2" {{ request('cluster')=='2'?'selected':'' }}>Group 2 · Stable / Moderate</option>
                    <option value="3" {{ request('cluster')=='3'?'selected':'' }}>Group 3 · Env / Financial Vulnerable</option>
                    <option value="4" {{ request('cluster')=='4'?'selected':'' }}>Group 4 · Multi-Domain Priority</option>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <x-heroicon-o-magnifying-glass class="w-3.5 h-3.5" />
                    Search
                </button>
                @if (request()->hasAny(['search','barangay','risk','cluster']))
                    <a href="{{ route('seniors.index') }}" wire:navigate class="btn">
                        <x-heroicon-o-x-mark class="w-3.5 h-3.5" />
                        Clear
                    </a>
                @endif
            </div>
        </div>
    </form>

    {{-- Bulk action bar (visible when rows are selected) --}}
    <div x-show="selected.length > 0" x-cloak
         class="card px-4 py-3 flex items-center justify-between gap-3 border-forest-200 bg-forest-50/60 dark:bg-forest-900/10">
        <div class="flex items-center gap-2 text-[13px] text-ink-800">
            <x-heroicon-o-check-circle class="w-4 h-4 text-forest-600" />
            <span><span class="font-semibold" x-text="selected.length"></span> record(s) selected</span>
        </div>
        <div class="flex items-center gap-2">
            <button @click="selected = []" class="btn btn-ghost text-[12px] px-3 py-1.5">Deselect all</button>
            <button @click="bulkArchiveOpen = true"
                    class="btn text-[12px] px-3 py-1.5 text-high-700 border-high-200 hover:bg-high-50">
                <x-heroicon-o-archive-box class="w-3.5 h-3.5" />
                Archive Selected
            </button>
        </div>
    </div>

    {{-- Table — overflow-visible so tooltip popups aren't clipped by the card --}}
    <div class="card overflow-visible">
        <table class="w-full">
            <thead>
                <tr>
                    <th class="th w-8">
                        <input type="checkbox"
                               class="rounded border-paper-rule text-forest-600 focus:ring-forest-500"
                               :checked="allOnPageSelected()"
                               :indeterminate="selected.length > 0 && !allOnPageSelected()"
                               @change="toggleAll($event.target.checked)">
                    </th>
                    <th class="th">OSCA ID</th>
                    <th class="th">Name</th>
                    <th class="th">Barangay</th>
                    <th class="th text-center">Age</th>
                    <th class="th text-center">Profile Group</th>
                    <th class="th text-center">Risk</th>
                    <th class="th">Composite Risk</th>
                    <th class="th text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($seniors as $senior)
                @php $ml = $senior->latestMlResult; @endphp
                <tr class="group hover:bg-forest-50/40 dark:hover:bg-forest-900/10 transition-colors duration-100"
                    :class="selected.includes({{ $senior->id }}) ? 'bg-forest-50/60 dark:bg-forest-900/10' : ''">
                    <td class="td w-8">
                        <input type="checkbox"
                               class="rounded border-paper-rule text-forest-600 focus:ring-forest-500"
                               :checked="selected.includes({{ $senior->id }})"
                               @change="toggleRow({{ $senior->id }}, $event.target.checked)">
                    </td>
                    <td class="td">
                        <div class="font-mono text-[12px] {{ $senior->official_osca_id ? 'font-semibold text-ink-800 dark:text-[#e4e1d8]' : 'italic text-ink-300 dark:text-[#4a5550]' }}">
                            {{ $senior->official_osca_id_display }}
                        </div>
                        <div class="text-[10.5px] text-ink-400 dark:text-[#6b7570] font-mono mt-0.5">
                            SYS: {{ $senior->osca_id }}
                        </div>
                    </td>
                    <td class="td">
                        <a href="{{ route('seniors.show', $senior) }}" wire:navigate class="flex items-center gap-3">
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
                            2 => '<strong class="text-ink-900 dark:text-[#e4e1d8]">Group 2 · Stable / Moderate</strong><br>Seniors with moderate limitations in at least one domain. Some domains stable, others beginning to decline. May benefit from social activation and periodic health monitoring.',
                            3 => '<strong class="text-ink-900 dark:text-[#e4e1d8]">Group 3 · Env / Financial Vulnerable</strong><br>Intrinsic capacity relatively preserved but environmental and financial stressors are significant. Priority: livelihood support, housing assistance, and service access.',
                            4 => '<strong class="text-ink-900 dark:text-[#e4e1d8]">Group 4 · Multi-Domain Priority</strong><br>Lowest alignment across all domains. Multi-domain vulnerability in physical health, environment, and functional independence. Requires immediate coordinated intervention.',
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
                        <div class="flex items-center justify-end gap-1">
                            <a href="{{ route('seniors.show', $senior) }}"
                               wire:navigate
                               class="btn btn-ghost text-[11.5px] px-2.5 py-1.5 gap-1.5"
                               title="View profile">
                                <x-heroicon-o-eye class="w-3.5 h-3.5" /> View
                            </a>
                            <a href="{{ route('seniors.edit', $senior) }}"
                               wire:navigate
                               class="btn btn-ghost text-[11.5px] px-2.5 py-1.5 gap-1.5"
                               title="Edit profile">
                                <x-heroicon-o-pencil class="w-3.5 h-3.5" /> Edit
                            </a>
                            <a href="{{ route('surveys.qol.create', $senior) }}"
                               wire:navigate
                               class="btn btn-ghost text-[11.5px] px-2.5 py-1.5 gap-1.5"
                               title="New QoL survey">
                                <x-heroicon-o-clipboard-document-list class="w-3.5 h-3.5" /> QoL
                            </a>
                            <button @click="openArchive('{{ $senior->uuid }}', '{{ addslashes($senior->full_name) }}')"
                                    class="btn btn-ghost text-[11.5px] px-2.5 py-1.5 text-high-700 hover:text-high-900 hover:bg-high-50 dark:hover:bg-high-50/10"
                                    title="Archive record">
                                <x-heroicon-o-archive-box class="w-3.5 h-3.5" />
                            </button>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="9" class="px-4 py-16 text-center">
                        <p class="font-serif text-base text-ink-500">No senior citizens found.</p>
                        <p class="text-[12.5px] text-ink-400 mt-1">Try adjusting your filters or register a new senior.</p>
                        <a href="{{ route('seniors.create') }}" wire:navigate class="btn btn-primary mt-4 inline-flex">
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

    {{-- ── Bulk Upload Success / Error Flash ─────────────────────────────── --}}
    <x-bulk-upload-flash />

    {{-- ── Bulk Import In-Progress Indicator ───────────────────────────────
         Surfaces BulkUploadController's persisted status marker
         (bulk-import-status:{user}, see status()/processUpload()) so the
         insert phase — unlike the ML phase, which already has
         ml/batch.blade.php's resumable poller — survives navigating away
         and back. importStatus is seeded server-side (fresh page load /
         "came back mid-import") and kept live by a 3s poll while
         processing; a much smaller version of ml/batch.blade.php's pattern
         since there's no cancel/elapsed-timer/resume-a-batch-id affordance
         to rebuild here, just a status to display. --}}
    <div x-show="importStatus && importStatus.status === 'processing'" x-cloak
         class="fixed bottom-5 right-5 z-50 max-w-sm w-full card shadow-xl border-l-4 border-info-500 flex items-start gap-3 px-4 py-3">
        <svg class="animate-spin w-5 h-5 text-info-600 flex-shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
        </svg>
        <div class="flex-1 min-w-0">
            <p class="text-[13px] font-semibold text-ink-900">Import in progress</p>
            <p class="text-[12px] text-ink-600 mt-0.5">
                <span x-text="importStatus ? importStatus.processed : 0"></span> of
                <span x-text="importStatus ? importStatus.total : 0"></span> row(s) processed&hellip;
            </p>
        </div>
    </div>

    {{-- ── Bulk Archive Confirmation Modal ──────────────────────────────── --}}
    <div x-show="bulkArchiveOpen" x-cloak
         role="dialog"
         aria-modal="true"
         aria-labelledby="bulk-archive-title"
         class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4"
         @keydown.escape.window="bulkArchiveOpen = false">
        <div class="card max-w-sm w-full shadow-2xl" @click.outside="bulkArchiveOpen = false">
            <div class="card-head">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-high-50 grid place-items-center flex-shrink-0">
                        <x-heroicon-o-archive-box class="w-4 h-4 text-high-700" />
                    </div>
                    <div class="card-title" id="bulk-archive-title">Archive selected records?</div>
                </div>
            </div>
            <div class="card-body">
                <p class="text-[13px] text-ink-700">
                    <span class="font-semibold" x-text="selected.length"></span> senior record(s) will be moved to Archives. Their data is preserved and can be restored at any time.
                </p>
                <div class="flex gap-2 justify-end mt-5">
                    <button @click="bulkArchiveOpen = false" :disabled="bulkArchiving" class="btn">Cancel</button>
                    <button @click="confirmBulkArchive()"
                            :disabled="bulkArchiving"
                            :class="bulkArchiving ? 'opacity-70 pointer-events-none' : ''"
                            class="btn btn-danger">
                        <span x-show="bulkArchiving" x-cloak class="btn-spinner" aria-hidden="true"></span>
                        <x-heroicon-o-archive-box class="w-3.5 h-3.5" x-show="!bulkArchiving" />
                        <span x-text="bulkArchiving ? 'Archiving…' : 'Archive Selected'"></span>
                    </button>
                </div>
            </div>
        </div>
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
                <div class="flex gap-2 justify-end pt-1">
                    <button @click="singleArchiveOpen = false" :disabled="singleArchiving" class="btn">Cancel</button>
                    <button @click="confirmSingleArchive()"
                            :disabled="singleArchiving"
                            :class="singleArchiving ? 'opacity-70 pointer-events-none' : ''"
                            class="btn btn-danger">
                        <span x-show="singleArchiving" x-cloak class="btn-spinner" aria-hidden="true"></span>
                        <x-heroicon-o-archive-box class="w-3.5 h-3.5" x-show="!singleArchiving" />
                        <span x-text="singleArchiving ? 'Archiving…' : 'Archive Record'"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Bulk Upload Modal ───────────────────────────────────────────── --}}
    {{-- <template x-if>, not x-show — this panel (drop zone, drag-drop
         handlers, the full column-reference table) is one of the heaviest
         chunks of markup on this page and was previously always present in
         the DOM (just display:none'd), costing every /seniors load a full
         parse + Alpine bind for content almost nobody opens. x-if defers
         creating it until uploadOpen actually flips true — including the
         validation-error-reopens-the-modal case below, since x-if reacts to
         the same initial value x-show did. No x-cloak needed either: an
         x-if element simply doesn't exist pre-Alpine-init, so there's
         nothing to flash. --}}
    <template x-if="uploadOpen">
    <div role="dialog"
         aria-modal="true"
         aria-labelledby="bulk-upload-title"
         class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4"
         @click.self="closeUpload()"
         @keydown.escape.window="if(uploadOpen) closeUpload()">
        <div class="card w-full max-w-2xl shadow-2xl flex flex-col max-h-[90vh]" @click.stop>

            {{-- Modal header --}}
            <div class="card-head flex-shrink-0">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-forest-50 grid place-items-center flex-shrink-0">
                        <x-heroicon-o-arrow-up-tray class="w-4 h-4 text-forest-700" />
                    </div>
                    <div>
                        <div class="card-title" id="bulk-upload-title">Bulk Upload Seniors</div>
                        <div class="card-sub">Import multiple senior citizen records from a CSV or Excel file.</div>
                    </div>
                </div>
                <button @click="closeUpload()" class="btn btn-ghost p-1.5">
                    <x-heroicon-o-x-mark class="w-4 h-4" />
                </button>
            </div>

            {{-- Scrollable body --}}
            <div class="overflow-y-auto flex-1">
                <div class="card-body space-y-5">

                    {{-- Validation errors --}}
                    @if ($errors->has('file'))
                    <div class="flex items-start gap-3 bg-high-50 border border-high-100 rounded-xl px-4 py-3">
                        <x-heroicon-o-exclamation-triangle class="w-4 h-4 text-high-600 flex-shrink-0 mt-0.5" />
                        <p class="text-[12.5px] text-high-700">{{ $errors->first('file') }}</p>
                    </div>
                    @endif

                    {{-- Drop zone --}}
                    <form id="bulk-upload-form"
                          method="POST"
                          action="{{ route('seniors.bulk-upload') }}"
                          enctype="multipart/form-data">
                        @csrf
                        <div class="relative"
                             @dragover.prevent="dragging = true"
                             @dragleave.prevent="dragging = false"
                             @drop.prevent="handleDrop($event)">
                            <label for="bulk-file-input"
                                   :class="dragging ? 'border-forest-400 bg-forest-50/60 dark:bg-forest-900/20' : 'border-paper-rule hover:border-forest-300 hover:bg-forest-50/30 dark:hover:bg-forest-900/10'"
                                   class="flex flex-col items-center justify-center gap-3 border-2 border-dashed rounded-2xl px-6 py-10 cursor-pointer transition-colors">

                                <div :class="dragging ? 'bg-forest-100 dark:bg-forest-900/40' : 'bg-paper-2 dark:bg-[#1a201d]'"
                                     class="w-12 h-12 rounded-xl grid place-items-center transition-colors">
                                    <x-heroicon-o-document-arrow-up class="w-6 h-6 text-forest-600" />
                                </div>

                                <div class="text-center">
                                    <template x-if="!fileName">
                                        <div>
                                            <p class="text-[13.5px] font-medium text-ink-800 dark:text-[#c8c4bc]">
                                                Drag &amp; drop your file here, or <span class="text-forest-600 font-semibold">browse</span>
                                            </p>
                                            <p class="text-[12px] text-ink-400 mt-1">CSV or Excel (.xlsx, .xls) · max 5 MB</p>
                                        </div>
                                    </template>
                                    <template x-if="fileName">
                                        <div class="flex items-center gap-2">
                                            <x-heroicon-o-document-text class="w-4 h-4 text-forest-600 flex-shrink-0" />
                                            <span class="text-[13px] font-semibold text-ink-900 dark:text-[#e4e1d8]" x-text="fileName"></span>
                                            <button type="button" @click.stop="clearFile()"
                                                    class="text-ink-400 hover:text-high-700 transition-colors">
                                                <x-heroicon-o-x-circle class="w-4 h-4" />
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </label>
                            <input id="bulk-file-input" name="file" type="file"
                                   accept=".csv,.xlsx,.xls,text/csv"
                                   class="sr-only"
                                   @change="handleFileInput($event)">
                        </div>
                    </form>

                    {{-- Template download + column reference --}}
                    <div class="rounded-xl border border-paper-rule dark:border-[#2b3530] overflow-hidden">
                        <div class="px-4 py-3 bg-paper-2 dark:bg-[#1a201d] flex items-center justify-between gap-3 border-b border-paper-rule dark:border-[#2b3530]">
                            <div class="flex items-center gap-2">
                                <x-heroicon-o-table-cells class="w-4 h-4 text-ink-400" />
                                <span class="text-[12.5px] font-semibold text-ink-700 dark:text-[#c8c4bc]">Expected Format</span>
                            </div>
                            <a href="{{ route('seniors.bulk-upload.sample') }}"
                               class="btn btn-ghost text-[11.5px] px-2.5 py-1.5 gap-1.5">
                                <x-heroicon-o-arrow-down-tray class="w-3.5 h-3.5" />
                                Download Template
                            </a>
                        </div>

                        <div class="px-4 py-3 space-y-4">
                            <div>
                                <p class="eyebrow mb-2">Required columns <span class="text-high-700 normal-case tracking-normal text-[11px]">(must be present)</span></p>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach (['first_name','last_name','barangay','dob','gender'] as $col)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-high-50 border border-high-100 text-[11px] font-mono font-semibold text-high-700">{{ $col }}</span>
                                    @endforeach
                                </div>
                            </div>

                            <div>
                                <p class="eyebrow mb-2">QoL survey columns <span class="text-ink-400 normal-case tracking-normal text-[11px]">(optional — 1–5 scale)</span></p>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ([
                                        'qol_enjoy_life','qol_life_satisfaction','qol_future_outlook','qol_meaningfulness',
                                        'phy_energy','phy_pain_r','phy_health_limit_r','phy_mobility_outside','phy_mobility_indoor',
                                        'psych_happiness','psych_peace','psych_lonely_r','psych_confidence',
                                        'func_independence','func_autonomy','func_control','env_income_limit_r',
                                        'soc_social_support','soc_close_friend','soc_participation','soc_opportunity','soc_respect',
                                        'env_safe_home','env_safe_neighborhood','env_service_access','env_home_comfort',
                                        'env_fin_household','env_fin_medical','env_fin_personal',
                                        'spi_belief_comfort','spi_belief_practice',
                                    ] as $col)
                                    <span class="inline-flex px-2 py-0.5 rounded-md bg-info-50 border border-info-100 text-[10.5px] font-mono text-info-700">{{ $col }}</span>
                                    @endforeach
                                </div>
                            </div>

                            <div>
                                <p class="eyebrow mb-2">Multi-value columns <span class="text-ink-400 normal-case tracking-normal text-[11px]">(comma-separated within the cell)</span></p>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach (['specialization','community_service','living_with','household_condition','income_source','real_assets','movable_assets','medical_concern','dental_concern','optical_concern','hearing_concern','social_emotional_concern','healthcare_difficulty','problems_needs'] as $col)
                                    <span class="inline-flex px-2 py-0.5 rounded-md bg-moderate-50 border border-moderate-100 text-[10.5px] font-mono text-moderate-700">{{ $col }}</span>
                                    @endforeach
                                </div>
                            </div>

                            <div class="text-[11.5px] text-ink-500 dark:text-[#8a9087] leading-relaxed border-t border-paper-rule dark:border-[#2b3530] pt-3">
                                <p><span class="font-semibold text-ink-700 dark:text-[#c8c4bc]">Date format:</span> <span class="font-mono">MM/DD/YYYY</span> or <span class="font-mono">YYYY-MM-DD</span></p>
                                <p class="mt-1"><span class="font-semibold text-ink-700 dark:text-[#c8c4bc]">Barangay:</span> Must match one of the 16 registered barangays of Pagsanjan (e.g. <span class="font-mono">Pinagsanjan</span>, <span class="font-mono">Sabang</span>, <span class="font-mono">Barangay I (Poblacion)</span>).</p>
                                <p class="mt-1"><span class="font-semibold text-ink-700 dark:text-[#c8c4bc]">After import:</span> You&rsquo;ll be taken to Batch Assessment, where risk scoring runs automatically for everyone just imported. Keep that page open for the fastest results.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Modal footer --}}
            <div class="px-5 py-4 border-t border-paper-rule flex items-center justify-between gap-3 flex-shrink-0">
                <p class="text-[11.5px] text-ink-400">
                    Rows with missing required fields are skipped. Existing records are <em>not</em> overwritten.
                </p>
                <div class="flex gap-2">
                    <button type="button" @click="closeUpload()" class="btn">Cancel</button>
                    <button type="button" @click="submit()"
                            :disabled="!fileName || uploading"
                            :class="(!fileName || uploading) ? 'opacity-50 cursor-not-allowed' : ''"
                            class="btn btn-primary gap-2">
                        <template x-if="uploading">
                            <svg class="animate-spin w-3.5 h-3.5" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                            </svg>
                        </template>
                        <template x-if="!uploading">
                            <x-heroicon-o-arrow-up-tray class="w-3.5 h-3.5" />
                        </template>
                        <span x-text="uploading ? 'Importing…' : 'Import'"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    </template>

</div>

@push('scripts')
<script>
function seniorIndex() {
    return {
        // Bulk import status poller — seeded from the server (a status key
        // already 'processing' means staff navigated away mid-import and
        // came back, or reloaded); kept live by a 3s poll while processing.
        // Deliberately not tied to the modal's local `uploading` flag: that
        // one only reflects THIS tab's own in-flight submit() and dies on
        // navigation, which is exactly the gap this fills.
        importStatus: @json($bulkImportStatus ?? null),
        importPollTimer: null,
        importStatusUrl: '{{ route('seniors.bulk-upload.status') }}',
        pollImportStatus() {
            this.importPollTimer = setInterval(() => {
                fetch(this.importStatusUrl, { headers: { 'Accept': 'application/json' } })
                    .then(r => r.json())
                    .then(d => {
                        this.importStatus = d;
                        if (d.status !== 'processing') {
                            clearInterval(this.importPollTimer);
                        }
                    })
                    .catch(() => {});
            }, 3000);
        },

        init() {
            // Same navigate-away teardown as ml/batch.blade.php's poller —
            // without it, an unbounded setInterval from a torn-down
            // component keeps firing against a detached Alpine instance.
            document.addEventListener('livewire:navigating', () => {
                clearInterval(this.importPollTimer);
            });
            if (this.importStatus && this.importStatus.status === 'processing') {
                this.pollImportStatus();
            }
        },

        // Multi-select state
        selected: [],
        bulkArchiveOpen: false,
        bulkArchiving: false,
        pageIds: @json($seniors->pluck('id')),

        // Single-senior archive state
        singleArchiveOpen: false,
        singleArchiveName: '',
        singleArchiveId: null,
        singleArchiving: false,
        openArchive(id, name) {
            this.singleArchiveId   = id;
            this.singleArchiveName = name;
            this.singleArchiveOpen = true;
        },

        // ── Archive actions — fetch() + Livewire.navigate() ───────────────
        // Both the single-row and bulk archive confirmations POST here
        // instead of doing a normal <form> submit (full browser navigation).
        // The actual fetch/CSRF/method-override/error-handling logic lives
        // in the shared window.OSCA.postAction() helper (resources/js/app.js)
        // — identical to what seniors/archives.blade.php's archiveIndex()
        // calls, so it's defined once, not copy-pasted per view. The
        // controller (SeniorCitizenController::stateRedirect()) responds
        // with { redirect } — the same URL a plain redirect()->back() would
        // have sent the browser to, filters/search/page/sort intact — and
        // Livewire.navigate() finishes the trip through the SPA layer, so
        // the persisted sidebar/topbar shell never gets torn down. Never treated
        // as done until the server actually replies: on a network error we
        // still fall back to a real navigate (to resync from the server)
        // rather than silently leaving the list in a possibly-stale state.
        csrfToken: '{{ csrf_token() }}',
        postAction(url, options) {
            return window.OSCA.postAction(url, this.csrfToken, options);
        },

        // Modal-close (and, for bulk, selection-clear) below is gated on the
        // resolved `ok` — never assumed. On success we close explicitly. On
        // failure we leave the modal's open flag untouched: postAction()
        // above has already called Livewire.navigate() regardless of
        // outcome, which resyncs this page from the server and tears down /
        // rebuilds this Alpine component (closing the modal as a side
        // effect of the resync, not as an assumed "it worked") — while the
        // flashed error toast (set server-side even on the failure path)
        // renders on the resynced page. This avoids ever showing a
        // success-shaped UI transition before the server has confirmed.
        async confirmSingleArchive() {
            if (this.singleArchiving || !this.singleArchiveId) return;
            this.singleArchiving = true;
            const ok = await this.postAction(`/seniors/${this.singleArchiveId}`, { method: 'DELETE' });
            this.singleArchiving = false;
            if (ok) this.singleArchiveOpen = false;
        },

        async confirmBulkArchive() {
            if (this.bulkArchiving || this.selected.length === 0) return;
            this.bulkArchiving = true;
            const ok = await this.postAction('{{ route('seniors.bulk-archive') }}', {
                method: 'POST',
                body: { ids: this.selected },
            });
            this.bulkArchiving = false;
            if (ok) {
                this.bulkArchiveOpen = false;
                this.selected = [];
            }
        },

        toggleRow(id, checked) {
            if (checked) {
                if (!this.selected.includes(id)) this.selected.push(id);
            } else {
                this.selected = this.selected.filter(i => i !== id);
            }
        },

        toggleAll(checked) {
            if (checked) {
                this.pageIds.forEach(id => {
                    if (!this.selected.includes(id)) this.selected.push(id);
                });
            } else {
                this.selected = this.selected.filter(id => !this.pageIds.includes(id));
            }
        },

        allOnPageSelected() {
            return this.pageIds.length > 0 && this.pageIds.every(id => this.selected.includes(id));
        },

        // Bulk upload state
        uploadOpen: {{ $errors->has('file') ? 'true' : 'false' }},
        dragging: false,
        fileName: null,
        uploading: false,

        closeUpload() {
            this.uploadOpen = false;
            this.dragging = false;
        },

        handleDrop(e) {
            this.dragging = false;
            const file = e.dataTransfer.files[0];
            if (file) this.setFile(file);
        },

        handleFileInput(e) {
            const file = e.target.files[0];
            if (file) this.setFile(file);
        },

        setFile(file) {
            const ext = file.name.split('.').pop().toLowerCase();
            if (!['csv','xls','xlsx','txt'].includes(ext)) {
                alert('Please upload a CSV or Excel file (.csv, .xlsx, .xls).');
                return;
            }
            document.getElementById('bulk-file-input').files = this.makeFileList(file);
            this.fileName = file.name;
        },

        makeFileList(file) {
            const dt = new DataTransfer();
            dt.items.add(file);
            return dt.files;
        },

        clearFile() {
            this.fileName = null;
            const inp = document.getElementById('bulk-file-input');
            inp.value = '';
        },

        submit() {
            if (!this.fileName) return;
            this.uploading = true;
            document.getElementById('bulk-upload-form').submit();
        },
    };
}
</script>
@endpush
@endsection
