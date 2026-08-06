@extends('layouts.app')
@section('page-title', 'GIS Analytics')
@section('page-subtitle', 'Spatial visibility for senior distribution and community accessibility context')

@section('content')
<div class="space-y-5">

    <x-page-header title="GIS Analytics" subtitle="Spatial visibility for senior distribution and community accessibility context" />

    @php
        $geocodeTone = match ($geocodeStatus['status'] ?? 'Pending') {
            'Completed' => 'text-low-700 bg-low-50 border-low-200',
            'Needs Update' => 'text-moderate-700 bg-moderate-50 border-moderate-200',
            default => 'text-high-700 bg-high-50 border-high-200',
        };
    @endphp

    {{-- Toolbar — Visualization / Barangay / Risk-or-Cluster / Export --}}
    <div class="card card-body">
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-[1.2fr_1fr_1fr_auto] gap-3 xl:items-start">
            <label class="block">
                <span class="eyebrow block mb-1.5">Visualization</span>
                <select id="gis-visualization-mode" class="form-select">
                    <option value="markers">Senior Population Overview</option>
                    <option value="risk-indicator-heatmap">Risk Indicator Distribution</option>
                    <option value="cluster-heatmap">Profile Groups Heatmap</option>
                    <option value="senior-distribution-accessibility-heatmap">Accessibility Heatmap</option>
                </select>
            </label>
            <label class="block">
                <span class="eyebrow block mb-1.5">Barangay</span>
                <select id="gis-barangay-filter" class="form-select">
                    <option value="all">All Barangays</option>
                </select>
                <button id="gis-recenter-btn" type="button"
                    class="mt-1 text-[11px] text-ink-500 dark:text-[#7a8580] hover:text-forest-700 dark:hover:text-forest-400 underline underline-offset-2 transition-colors">
                    ↺ Re-center map
                </button>
            </label>
            <label class="block">
                <span id="gis-secondary-filter-label" class="eyebrow block mb-1.5">Risk Level</span>
                <select id="gis-risk-filter" class="form-select">
                    <option value="all">All Risk Levels</option>
                    <option value="low">Low</option>
                    <option value="moderate">Moderate</option>
                    <option value="high">High</option>
                </select>
                <select id="gis-cluster-filter" class="form-select hidden">
                    <option value="all">All Groups</option>
                </select>
            </label>
            @role('admin')
            <div class="block">
                <span class="eyebrow block mb-1.5 invisible select-none" aria-hidden="true">Export</span>
                <a href="{{ route('reports.gis.export') }}"
                   class="btn text-[12px] px-3 py-2.5 whitespace-nowrap justify-center w-full md:w-auto">
                    <x-heroicon-o-arrow-down-tray class="w-3.5 h-3.5" />
                    Export CSV
                </a>
            </div>
            @endrole
        </div>
    </div>

    {{-- Map (left) + context sidebar (right) --}}
    <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_320px] gap-5 items-start">

        {{-- Map card --}}
        <div class="card overflow-hidden">
            <div class="card-head">
                <div>
                    <div class="card-title">Senior Citizen Spatial Distribution</div>
                    <div class="card-sub">Generalized senior distribution and accessibility context within Pagsanjan</div>
                </div>
                <span class="text-[11.5px] text-ink-400 dark:text-[#6b7570] whitespace-nowrap">Centered on Pagsanjan, Laguna</span>
            </div>

            <div class="card-body space-y-4">
                <div class="relative">
                    <label class="block">
                        <span class="eyebrow block mb-1.5">Find a Senior</span>
                        <input id="gis-senior-search" type="text" autocomplete="off"
                            class="form-input" placeholder="Search by name, OSCA ID or System ID...">
                    </label>
                    <ul id="gis-senior-search-results"
                        class="hidden absolute z-[1300] mt-1 w-full max-h-72 overflow-y-auto rounded-lg border border-paper-rule dark:border-[#2b3530] bg-paper-0 dark:bg-[#1b211e] shadow-lg text-sm">
                    </ul>
                </div>

                {{-- Map layers — collapsible so the map gets the room --}}
                <details class="group border border-paper-rule dark:border-[#2b3530] rounded-lg">
                    <summary class="cursor-pointer select-none list-none px-3 py-2 flex items-center justify-between text-[12px] font-semibold text-ink-700 dark:text-[#d8ddd9] [&::-webkit-details-marker]:hidden">
                        <span>Map layers</span>
                        <x-heroicon-o-chevron-down class="w-3.5 h-3.5 transition-transform duration-150 group-open:rotate-180" />
                    </summary>
                    <div class="px-3 pb-3 space-y-3 border-t border-paper-rule dark:border-[#2b3530] pt-3">
                        <div id="gis-layer-options" class="hidden space-y-3">
                            <div id="gis-layer-options-markers" class="hidden">
                                <div class="flex flex-wrap gap-x-4 gap-y-2 text-[12px] text-ink-600 dark:text-[#b0b5b2]">
                                    <label class="inline-flex items-center gap-2">
                                        <input id="gis-show-senior-points-toggle" type="checkbox" class="rounded border-paper-rule text-forest-700 focus:ring-forest-700" checked>
                                        <span>Show senior points</span>
                                    </label>
                                    <label class="inline-flex items-center gap-2">
                                        <input id="gis-show-barangay-density-toggle" type="checkbox" class="rounded border-paper-rule text-forest-700 focus:ring-forest-700" checked>
                                        <span>Show barangay density fill</span>
                                    </label>
                                </div>
                            </div>
                            <div id="gis-layer-options-cluster" class="hidden">
                                <div class="flex flex-wrap gap-x-4 gap-y-2 text-[12px] text-ink-600 dark:text-[#b0b5b2]">
                                    <label class="inline-flex items-center gap-2">
                                        <input id="gis-cluster-points-toggle" type="checkbox" class="rounded border-paper-rule text-forest-700 focus:ring-forest-700" checked>
                                        <span>Show senior distribution points</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div id="gis-accessibility-point-display" style="display: none;">
                            <label class="inline-flex items-center gap-2 text-[12px] text-ink-600 dark:text-[#b0b5b2]">
                                <input id="gis-show-heatmap-senior-points" type="checkbox" class="rounded border-paper-rule text-forest-700 focus:ring-forest-700" checked>
                                <span>Show senior points on accessibility heatmap</span>
                            </label>
                        </div>

                        <div id="gis-risk-point-display" style="display: none;">
                            <label class="inline-flex items-center gap-2 text-[12px] text-ink-600 dark:text-[#b0b5b2]">
                                <input id="gis-show-risk-senior-points" type="checkbox" class="rounded border-paper-rule text-forest-700 focus:ring-forest-700" checked>
                                <span>Show senior points on risk heatmap</span>
                            </label>
                        </div>
                    </div>
                </details>

                <div class="relative">
                    <div id="gis-map"
                         class="rounded-2xl border border-paper-rule dark:border-[#2b3530] min-h-[420px] md:min-h-[520px]"
                         data-geojson-url="{{ route('api.gis.seniors', [], false) }}"
                         data-facilities-url="{{ route('api.gis.facilities', [], false) }}"
                         data-route-distance-url="{{ route('api.gis.route-distance', [], false) }}"
                         data-pagsanjan-boundary-url="{{ route('api.gis.boundary.pagsanjan', [], false) }}"
                         data-barangay-boundaries-url="{{ route('api.gis.boundary.barangays', [], false) }}">
                    </div>

                    {{-- Loading overlay — masks the basemap until GIS layers finish loading --}}
                    <div id="gis-map-loading"
                         class="absolute inset-0 z-[1200] flex flex-col items-center justify-center gap-3 rounded-2xl bg-[#f2efe9] dark:bg-[#161b18] text-ink-500 dark:text-[#8a958f] transition-opacity duration-300"
                         role="status" aria-live="polite">
                        <svg class="w-7 h-7 animate-spin motion-reduce:animate-none text-forest-600 dark:text-forest-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                        </svg>
                        <p class="text-[12.5px] font-medium">Loading map…</p>
                    </div>

                    {{-- Legend — floats over the map on desktop, flows below it on mobile. Collapsible,
                         and caps its own height with a Show all/less toggle once content grows past a
                         handful of rows (heatmap modes add facility + boundary + band rows).
                         #gis-map-legend stays the single element updateLegend(mode) rewrites. --}}
                    <div x-data="{ legendOpen: true, legendExpanded: false }"
                         class="mt-3 md:mt-0 md:absolute md:top-3 md:right-3 md:z-[1100] md:w-64 max-w-full rounded-xl border border-paper-rule dark:border-[#2b3530] bg-white/95 dark:bg-[#1a201d]/95 md:shadow-md overflow-hidden">
                        <button type="button" @click="legendOpen = !legendOpen"
                                class="w-full flex items-center justify-between gap-2 px-3 py-2 text-left">
                            <span class="inline-flex items-center gap-1.5">
                                <x-heroicon-o-map class="w-3.5 h-3.5 text-ink-400 dark:text-[#6b7570]" />
                                <span class="eyebrow">Legend</span>
                            </span>
                            <x-heroicon-o-chevron-down class="w-3.5 h-3.5 text-ink-400 transition-transform duration-150"
                                                        x-bind:class="legendOpen ? 'rotate-180' : ''" />
                        </button>
                        <div x-show="legendOpen" x-transition.opacity.duration.150ms class="px-3 pb-2.5">
                            <div class="relative">
                                <div :class="legendExpanded ? '' : 'max-h-32 overflow-hidden'" class="transition-[max-height] duration-200">
                                    <div id="gis-map-legend" class="flex flex-wrap md:flex-col gap-x-4 gap-y-1.5 text-[11.5px] text-ink-500 dark:text-[#6b7570]">
                                        <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-low-500 inline-block"></span>Low Risk</span>
                                        <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-moderate-500 inline-block"></span>Moderate Risk</span>
                                        <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-high-500 inline-block"></span>High Risk</span>
                                        <span class="inline-flex items-center gap-1.5">
                                            <svg width="10" height="10" viewBox="0 0 16 16" aria-hidden="true" class="flex-shrink-0"><circle cx="8" cy="8" r="7" fill="#527a9b" stroke="#ffffff" stroke-width="1"/><path d="M8 4.5v7M4.5 8h7" stroke="#ffffff" stroke-width="1.6" stroke-linecap="round"/></svg>
                                            Facilities
                                        </span>
                                        <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-moderate-100 inline-block"></span>Outer Zone</span>
                                    </div>
                                </div>
                                <div x-show="!legendExpanded"
                                     class="pointer-events-none absolute inset-x-0 bottom-0 h-6 bg-gradient-to-t from-white dark:from-[#1a201d] to-transparent"></div>
                            </div>
                            <button type="button" @click="legendExpanded = !legendExpanded"
                                    class="mt-1.5 text-[11px] font-semibold text-forest-700 dark:text-forest-400 hover:underline underline-offset-2">
                                <span x-text="legendExpanded ? 'Show less' : 'Show all'"></span>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="rounded-lg bg-paper-2 dark:bg-[#1f2622] px-3 py-2.5 text-[12px] text-ink-600 dark:text-[#b0b5b2] leading-relaxed">
                    <p>Points represent approximate barangay-level locations only. These do not indicate exact home addresses of senior citizens.</p>
                    <p class="mt-1 font-mono tnum text-[11.5px] text-ink-500 dark:text-[#8a958f]">
                        {{ number_format($geocodeStatus['total_seniors']) }} senior record{{ $geocodeStatus['total_seniors'] === 1 ? '' : 's' }} loaded ·
                        <span class="text-low-700 dark:text-low-100">{{ number_format($geocodeStatus['verified_coordinates']) }} verified</span> ·
                        <span class="text-info-700 dark:text-info-100">{{ number_format($geocodeStatus['approximate_coordinates']) }} approximate</span>
                        @if (($geocodeStatus['missing_coordinates'] ?? 0) > 0)
                            · <span class="text-high-700 dark:text-high-100">{{ number_format($geocodeStatus['missing_coordinates']) }} missing</span>
                        @endif
                    </p>
                </div>
                <p id="gis-map-status" class="text-[11.5px] text-ink-400 dark:text-[#6b7570]">Loading barangay-level GIS data...</p>
            </div>
        </div>

        {{-- Context sidebar --}}
        <div class="space-y-4">

            <div class="card">
                <div class="card-head">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-info-100 text-info-700 dark:bg-info-700/20 dark:text-info-100 flex-shrink-0">
                            <x-heroicon-o-information-circle class="w-4 h-4" />
                        </span>
                        <div class="card-title">What This Map Shows</div>
                    </div>
                </div>
                <div class="card-body">
                    <p class="text-[12.5px] text-ink-700 dark:text-[#b0b5b2] leading-relaxed">
                        This map displays senior citizen distribution using approximate barangay-level locations only. It does not show exact household locations.
                    </p>
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-forest-100 text-forest-700 dark:bg-forest-700/20 dark:text-forest-100 flex-shrink-0">
                            <x-heroicon-o-circle-stack class="w-4 h-4" />
                        </span>
                        <div class="card-title">Data Source</div>
                    </div>
                </div>
                <div class="card-body">
                    <p class="text-[12.5px] text-ink-700 dark:text-[#b0b5b2] leading-relaxed mb-2">
                        OSCA Senior Citizen Records <span id="gis-stat-source" class="text-ink-400 dark:text-[#6b7570]">(minimal data)</span>:
                    </p>
                    <ul class="space-y-1 text-[12.5px] text-ink-700 dark:text-[#b0b5b2] list-disc list-inside">
                        <li>Age</li>
                        <li>Address (Barangay only)</li>
                        <li>Family Composition</li>
                    </ul>
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-moderate-100 text-moderate-700 dark:bg-moderate-700/20 dark:text-moderate-100 flex-shrink-0">
                            <x-heroicon-o-arrow-path class="w-4 h-4" />
                        </span>
                        <div class="card-title">Bulk Geocode Status</div>
                    </div>
                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap {{ $geocodeTone }}">
                        {{ $geocodeStatus['status'] }}
                    </span>
                </div>
                <div class="card-body space-y-3">
                    <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-[12px]">
                        <div>
                            <dt class="text-ink-400 dark:text-[#6b7570]">Last Run</dt>
                            <dd class="font-semibold text-ink-800 dark:text-[#d8ddd9]">{{ $geocodeStatus['last_run_at'] ?? 'Not recorded' }}</dd>
                        </div>
                        <div>
                            <dt class="text-ink-400 dark:text-[#6b7570]">Mode</dt>
                            <dd class="font-semibold text-ink-800 dark:text-[#d8ddd9]">{{ $geocodeStatus['coordinate_mode'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-ink-400 dark:text-[#6b7570]">Total Seniors</dt>
                            <dd class="font-mono tnum font-semibold text-ink-800 dark:text-[#d8ddd9]">{{ number_format($geocodeStatus['total_seniors']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-ink-400 dark:text-[#6b7570]">Approximate</dt>
                            <dd class="font-mono tnum font-semibold text-ink-800 dark:text-[#d8ddd9]">{{ number_format($geocodeStatus['approximate_coordinates']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-ink-400 dark:text-[#6b7570]">Verified/Manual</dt>
                            <dd class="font-mono tnum font-semibold text-ink-800 dark:text-[#d8ddd9]">{{ number_format($geocodeStatus['verified_coordinates']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-ink-400 dark:text-[#6b7570]">Missing</dt>
                            <dd class="font-mono tnum font-semibold text-ink-800 dark:text-[#d8ddd9]">{{ number_format($geocodeStatus['missing_coordinates']) }}</dd>
                        </div>
                    </dl>

                    @if (($geocodeStatus['missing_coordinates'] ?? 0) > 0)
                        <x-alert type="warning">
                            <strong>{{ number_format($geocodeStatus['missing_coordinates']) }}</strong>
                            senior{{ $geocodeStatus['missing_coordinates'] === 1 ? '' : 's' }} changed barangay or {{ $geocodeStatus['missing_coordinates'] === 1 ? 'is' : 'are' }} not yet mapped.
                            @role('admin')
                                Run Bulk Geocode below to update {{ $geocodeStatus['missing_coordinates'] === 1 ? 'its' : 'their' }} map location.
                            @else
                                Ask an admin to run Bulk Geocode to update {{ $geocodeStatus['missing_coordinates'] === 1 ? 'its' : 'their' }} map location.
                            @endrole
                        </x-alert>
                    @endif

                    @role('admin')
                    <div x-data="{ open: false }">
                        <button type="button" @click="open = true" class="btn btn-primary w-full justify-center text-[12px] px-3 py-2">
                            <x-heroicon-o-arrow-path class="w-3.5 h-3.5" />
                            Run Bulk Geocode
                        </button>
                        <form x-ref="geocodeForm" method="POST" action="{{ route('reports.gis.geocode') }}" class="hidden">
                            @csrf
                        </form>
                        <x-confirm-modal show="open"
                                         title="Run bulk geocoding?"
                                         tone="primary"
                                         confirm="$refs.geocodeForm.requestSubmit()"
                                         confirm-label="Run geocoding">
                            <p>This assigns approximate barangay-level coordinates to seniors without coordinates so they can be mapped for planning. It will <strong class="text-ink-900 dark:text-[#e4e1d8]">not</strong> overwrite verified manual or GPS-captured pins.</p>
                        </x-confirm-modal>
                    </div>
                    @endrole
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-info-100 text-info-700 dark:bg-info-700/20 dark:text-info-100 flex-shrink-0">
                            <x-heroicon-o-map-pin class="w-4 h-4" />
                        </span>
                        <div class="card-title">About Coordinates</div>
                    </div>
                </div>
                <div class="card-body">
                    <p class="text-[12.5px] text-ink-700 dark:text-[#b0b5b2] leading-relaxed">
                        Coordinates are generated from barangay centroids or privacy-safe points inside each barangay boundary, for planning and accessibility context only.
                    </p>
                </div>
            </div>

        </div>
    </div>

    {{-- Bottom summary row --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">

        {{-- Senior Count Per Barangay — proportional data-bar list (same idiom as the
             dashboard's Barangay Breakdown), not a rigid table: it's inherently
             responsive since only the bar and truncated name flex with the card's
             width, instead of fighting for space across fixed table columns. --}}
        <div class="card overflow-hidden">
            <div class="card-head">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-forest-100 text-forest-700 dark:bg-forest-700/20 dark:text-forest-100 flex-shrink-0">
                        <x-heroicon-o-users class="w-4 h-4" />
                    </span>
                    <div class="card-title">Senior Count Per Barangay</div>
                </div>
            </div>
            <div class="card-body pb-3">
                @php $barangayMax = max(1, collect($barangayCounts)->max('count') ?: 1); @endphp
                <div class="relative">
                    {{-- scrollbar-thin + top/bottom fade keep the scroll affordance quiet
                         (a soft shadow, not a hard clip or a bulky native scrollbar). --}}
                    <div class="scrollbar-thin overflow-y-auto max-h-72 space-y-3 pr-1">
                        @forelse ($barangayCounts as $i => $row)
                            @php
                                $totalPct = round($row['count'] / $barangayMax * 100);
                                $highPct = round(($row['high_risk_count'] ?? 0) / $barangayMax * 100);
                            @endphp
                            <a href="{{ route('reports.barangay', $row['barangay']) }}"
                               class="flex items-center gap-2.5 group">
                                <span class="w-4 flex-shrink-0 text-[11px] text-ink-400 dark:text-[#6b7570] tnum">{{ $i + 1 }}</span>
                                <span class="w-[30%] sm:w-24 flex-shrink-0 truncate text-[12px] font-medium text-ink-800 dark:text-[#d8ddd9] group-hover:text-forest-700 dark:group-hover:text-forest-400 transition-colors"
                                      title="{{ $row['barangay'] }}">
                                    {{ $row['barangay'] }}
                                </span>
                                <span class="flex-1 relative h-2.5 rounded-full bg-paper-2 dark:bg-[#202a26] overflow-hidden">
                                    <span class="absolute inset-y-0 left-0 rounded-full bg-forest-300 dark:bg-forest-700" style="width: {{ max($totalPct, 3) }}%"></span>
                                    @if (($row['high_risk_count'] ?? 0) > 0)
                                        <span class="absolute inset-y-0 left-0 rounded-full bg-high-500" style="width: {{ max($highPct, 2) }}%"></span>
                                    @endif
                                </span>
                                <span class="w-10 flex-shrink-0 text-right font-mono tnum text-[12px] font-semibold text-ink-900 dark:text-[#e4e1d8]">{{ number_format($row['count']) }}</span>
                                <span class="w-11 flex-shrink-0 text-right font-mono tnum text-[11px] text-ink-400 dark:text-[#6b7570]">{{ number_format($row['percent'], 1) }}%</span>
                            </a>
                        @empty
                            <p class="text-center py-8 text-[12.5px] text-ink-400 dark:text-[#6b7570]">No barangay data available yet.</p>
                        @endforelse
                    </div>
                    <div class="pointer-events-none absolute inset-x-0 top-0 h-3 bg-gradient-to-b from-white dark:from-[#1a201d] to-transparent"></div>
                    <div class="pointer-events-none absolute inset-x-0 bottom-0 h-4 bg-gradient-to-t from-white dark:from-[#1a201d] to-transparent"></div>
                </div>

                @if (count($barangayCounts))
                    <div class="mt-3 pt-3 border-t border-paper-rule dark:border-[#2b3530] flex items-center justify-between gap-3">
                        <div class="flex items-center gap-4 text-[10.5px] text-ink-400 dark:text-[#6b7570]">
                            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-forest-300 dark:bg-forest-700"></span>Total seniors</span>
                            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-high-500"></span>High risk</span>
                        </div>
                        <span class="font-mono tnum text-[11.5px] font-semibold text-ink-700 dark:text-[#d8ddd9] whitespace-nowrap">
                            {{ number_format($stats['mapped_seniors']) }} total
                        </span>
                    </div>
                @endif
            </div>
            <div class="card-body pt-3 border-t border-paper-rule dark:border-[#2b3530]">
                <a href="{{ route('reports.barangay.index') }}" class="text-[12.5px] font-semibold text-forest-700 dark:text-forest-400 hover:underline underline-offset-2">
                    View Full Report →
                </a>
            </div>
        </div>

        {{-- Risk Level Distribution --}}
        <div class="card">
            <div class="card-head">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-high-100 text-high-700 dark:bg-high-700/20 dark:text-high-100 flex-shrink-0">
                        <x-heroicon-o-chart-pie class="w-4 h-4" />
                    </span>
                    <div class="card-title">Risk Level Distribution</div>
                </div>
            </div>
            <div class="card-body">
                <div class="h-44 relative">
                    <canvas id="gis-risk-doughnut" role="img" aria-label="Risk level distribution doughnut chart"></canvas>
                </div>
                <div class="mt-4 space-y-2 text-[12.5px]">
                    @php
                        $riskRows = [
                            ['label' => 'Low Risk', 'value' => $riskDistribution['low'], 'class' => 'bg-low-500'],
                            ['label' => 'Moderate Risk', 'value' => $riskDistribution['moderate'], 'class' => 'bg-moderate-500'],
                            ['label' => 'High Risk', 'value' => $riskDistribution['high'], 'class' => 'bg-high-500'],
                        ];
                        $riskGrandTotal = max($riskDistribution['total'], 1);
                    @endphp
                    @foreach ($riskRows as $row)
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full inline-block flex-shrink-0 {{ $row['class'] }}"></span>
                            <span class="text-ink-700 dark:text-[#b0b5b2]">{{ $row['label'] }}</span>
                            <span class="ml-auto font-mono tnum font-semibold text-ink-800 dark:text-[#d8ddd9]">
                                {{ number_format($row['value']) }} ({{ round($row['value'] / $riskGrandTotal * 100) }}%)
                            </span>
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 text-[11.5px] text-ink-400 dark:text-[#6b7570] leading-relaxed">
                    Risk classification is computed from the latest available ML assessment per senior.
                </p>
            </div>
        </div>

        {{-- Facility Accessibility Summary --}}
        <div class="card">
            <div class="card-head">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-info-100 text-info-700 dark:bg-info-700/20 dark:text-info-100 flex-shrink-0">
                        <x-heroicon-o-heart class="w-4 h-4" />
                    </span>
                    <div class="card-title">Facility Accessibility Summary</div>
                </div>
            </div>
            <div class="card-body space-y-3">
                @php
                    $facilityRows = [
                        ['label' => 'Nearest Health Center (Avg)', 'value' => $facilityAccessibility['health_center_km'], 'icon' => 'building-office-2', 'class' => 'bg-high-100 text-high-700 dark:bg-high-700/20 dark:text-high-100'],
                        ['label' => 'Nearest Barangay Hall (Avg)', 'value' => $facilityAccessibility['barangay_hall_km'], 'icon' => 'building-library', 'class' => 'bg-forest-100 text-forest-700 dark:bg-forest-700/20 dark:text-forest-100'],
                        ['label' => 'Nearest Pharmacy (Avg)', 'value' => $facilityAccessibility['pharmacy_km'], 'icon' => 'beaker', 'class' => 'bg-info-100 text-info-700 dark:bg-info-700/20 dark:text-info-100'],
                    ];
                @endphp
                @foreach ($facilityRows as $row)
                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg flex-shrink-0 {{ $row['class'] }}">
                            <x-dynamic-component :component="'heroicon-o-'.$row['icon']" class="w-4 h-4" />
                        </span>
                        <span class="text-[12.5px] text-ink-700 dark:text-[#b0b5b2] flex-1">{{ $row['label'] }}</span>
                        <span class="font-mono tnum font-semibold text-ink-900 dark:text-[#e4e1d8]">
                            {{ $row['value'] !== null ? number_format($row['value'], 2).' km' : '—' }}
                        </span>
                    </div>
                @endforeach

                <x-alert type="info">
                    Distances are calculated from approximate barangay-level locations, not exact addresses.
                </x-alert>
            </div>
        </div>

    </div>

</div>

@php
    $gisRiskChartJson = json_encode([
        'labels' => ['Low', 'Moderate', 'High'],
        'data' => [$riskDistribution['low'], $riskDistribution['moderate'], $riskDistribution['high']],
        'colors' => ['#4a8a68', '#c19a3b', '#e0621a'],
        'total' => $riskDistribution['total'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
@endphp
<script type="application/json" id="gis-risk-chart-data">{!! $gisRiskChartJson !!}</script>
@endsection

@push('scripts')
<script>
(function () {
    function isDark() {
        return document.documentElement.classList.contains('dark');
    }

    function prefersReducedMotion() {
        return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function upsert(id, config) {
        const canvas = document.getElementById(id);
        if (!canvas) return;
        const existing = Object.values(window.Chart.instances).find(c => c.canvas === canvas);
        if (existing) existing.destroy();
        new window.Chart(canvas, config);
    }

    // Center label: the running total + a small caption (matches dashboard.blade.php).
    function centerTextPlugin(caption) {
        return {
            id: 'gisCenterText',
            afterDraw(chart) {
                const ds = chart.data.datasets[0];
                if (!ds || !chart.chartArea) return;
                const total = ds.data.reduce((a, b) => a + (Number(b) || 0), 0);
                const { ctx, chartArea } = chart;
                const cx = (chartArea.left + chartArea.right) / 2;
                const cy = (chartArea.top + chartArea.bottom) / 2;
                const dark = isDark();
                ctx.save();
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillStyle = dark ? '#e4e1d8' : '#1a1d1a';
                ctx.font = "600 22px 'Source Serif 4', Georgia, serif";
                ctx.fillText(String(total), cx, cy - 6);
                ctx.fillStyle = dark ? '#8a9087' : '#8a8f86';
                try { ctx.letterSpacing = '1.2px'; } catch (e) {}
                ctx.font = "600 9px 'Plus Jakarta Sans', system-ui, sans-serif";
                ctx.fillText('TOTAL', cx, cy + 13);
                ctx.restore();
            },
        };
    }

    function render() {
        const el = document.getElementById('gis-risk-chart-data');
        if (!el) return;
        const p = JSON.parse(el.textContent);
        const reduced = prefersReducedMotion();

        upsert('gis-risk-doughnut', {
            type: 'doughnut',
            data: {
                labels: p.labels,
                datasets: [{
                    data: p.data,
                    backgroundColor: p.colors,
                    borderWidth: 2,
                    borderColor: isDark() ? '#1a201d' : '#ffffff',
                    hoverOffset: 8,
                    hoverBorderColor: isDark() ? '#1a201d' : '#ffffff',
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                animation: reduced ? { duration: 0 } : { animateRotate: true, animateScale: true, duration: 300, easing: 'easeOutQuart' },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: c => ` ${c.label}: ${c.parsed}` } },
                },
            },
            plugins: [centerTextPlugin('Total')],
        });
    }

    const boot = () => window.OSCA.charts().then(() => render());

    // Same once-guard idiom as dashboard.blade.php: the <script> tag re-executes
    // on every wire:navigate SPA navigation, but window survives, so document
    // listeners must only bind once per page session.
    if (!window.__oscaBound_gisRiskChart) {
        window.__oscaBound_gisRiskChart = true;
        document.addEventListener('livewire:navigated', () => setTimeout(boot, 0));
        if (document.readyState !== 'loading') setTimeout(boot, 0);
        document.addEventListener('DOMContentLoaded', boot);

        const html = document.documentElement;
        new MutationObserver((mutations) => {
            if (mutations.some(m => m.attributeName === 'class')) render();
        }).observe(html, { attributes: true });
    }
})();
</script>
@endpush

@push('styles')
<style>
.gis-recenter-control {
    background: white;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    color: #333;
    padding: 0;
}
.gis-recenter-control:hover {
    background: #f4f4f4;
    color: #000;
}
.dark .gis-recenter-control {
    background: #2b3530;
    color: #b0b5b2;
}
.dark .gis-recenter-control:hover {
    background: #3a4540;
    color: #fff;
}
/* The basemap is always the light tile layer. Give the map element a light,
   land-coloured background (matching the tiles) so the brief tile gaps during a
   zoom render as a soft land tone instead of Leaflet's default grey (#ddd) or
   the dark-theme panel colour. The ID selector outranks the Tailwind bg classes. */
#gis-map {
    background: #f2efe9;
}
</style>
@endpush

@push('scripts')
<script>
(function () {
    const MAP_ID = 'gis-map';
    const STATUS_ID = 'gis-map-status';
    const MODE_ID = 'gis-visualization-mode';
    const BARANGAY_FILTER_ID = 'gis-barangay-filter';
    const RISK_FILTER_ID = 'gis-risk-filter';
    const CLUSTER_FILTER_ID = 'gis-cluster-filter';
    const SEARCH_INPUT_ID = 'gis-senior-search';
    const SEARCH_RESULTS_ID = 'gis-senior-search-results';
    const CLUSTER_POINTS_TOGGLE_ID = 'gis-cluster-points-toggle';
    const SHOW_SENIOR_POINTS_TOGGLE_ID = 'gis-show-senior-points-toggle';
    const SHOW_BARANGAY_DENSITY_TOGGLE_ID = 'gis-show-barangay-density-toggle';
    const LAYER_OPTIONS_ID = 'gis-layer-options';
    const SHOW_HEATMAP_SENIOR_POINTS_ID = 'gis-show-heatmap-senior-points';
    const ACCESSIBILITY_POINT_DISPLAY_ID = 'gis-accessibility-point-display';
    const SHOW_RISK_SENIOR_POINTS_ID = 'gis-show-risk-senior-points';
    const RISK_POINT_DISPLAY_ID = 'gis-risk-point-display';
    const LEGEND_ID = 'gis-map-legend';
    const TOTAL_STAT_ID = 'gis-stat-total';
    const HIGH_RISK_STAT_ID = 'gis-stat-high-risk';
    const BARANGAY_STAT_ID = 'gis-stat-barangays';
    const SOURCE_STAT_ID = 'gis-stat-source';
    const PAGSANJAN_CENTER = [14.2708, 121.4560];
    const DEFAULT_ZOOM = 15;
    const MIN_ZOOM = 13;
    const MAX_ZOOM = 18;
    const DEFAULT_FOCUS_BOUNDS_COORDS = [
        [14.2180, 121.4230],
        [14.2845, 121.4685],
    ];
    const NAVIGATION_BOUNDS_COORDS = [
        [14.2160, 121.4210],
        [14.2865, 121.4710],
    ];
    const MAP_FIT_OPTIONS = {
        padding: [18, 18],
        maxZoom: 15,
        animate: false,
    };
    const MUNICIPAL_FOCUS_PADDING_RATIO = 0.03;
    const MUNICIPAL_NAVIGATION_PADDING_RATIO = 0.15;
    const HEATMAP_MODES = new Set([
        'senior-distribution-accessibility-heatmap',
        'risk-indicator-heatmap',
        'cluster-heatmap',
    ]);
    // Rich sequential ramp for the Risk Distribution raster-KDE surface so it
    // renders with the same smooth typhoon look as the cluster heatmap:
    // green (low risk) -> lime -> yellow -> orange -> red -> deep red (highest).
    const RISK_DISTRIBUTION_RAMP = {
        0.00: '#16a34a',
        0.18: '#22c55e',
        0.38: '#84cc16',
        0.55: '#facc15',
        0.72: '#fb923c',
        0.88: '#ef4444',
        1.00: '#b91c1c',
    };
    const ACCESSIBILITY_DISTRIBUTION_RAMP = {
        0.00: '#22c55e',
        0.25: '#84cc16',
        0.45: '#facc15',
        0.68: '#fb923c',
        0.85: '#ef4444',
        1.00: '#991b1b',
    };
    // Facility-access classification bands — single source of truth shared with the
    // profile card and backend (App\Support\AccessibilityBand). Ordered high → low.
    const ACCESSIBILITY_BANDS = @json(\App\Support\AccessibilityBand::all());
    const CLUSTER_HEATMAP_GRADIENT = {
        0.00: '#e8f4f8',
        0.25: '#74c2e8',
        0.50: '#f0e442',
        0.75: '#e67e22',
        1.00: '#c0392b',
    };
    const CLUSTER_HEATMAP_COLORS = {
        'Group 1': '#0ea5e9',
        'Group 2': '#10b981',
        'Group 3': '#f59e0b',
        'Group 4': '#ef4444',
    };
    const CLUSTER_HEATMAP_RAMPS = {
        1: {
            label: 'C1',
            title: 'C1 · High Functioning / Well-Supported Seniors',
            name: 'Cluster 1',
            stops: {
                0.00: '#dff7ff',
                0.14: '#aeeeff',
                0.32: '#67d8ff',
                0.52: '#14aee8',
                0.70: '#048dcc',
                0.86: '#0077b6',
                1.00: '#005f99',
            },
        },
        2: {
            label: 'C2',
            title: 'C2 · Stable Ageing / Moderate Support Needs',
            name: 'Cluster 2',
            stops: {
                0.00: '#e5ffe9',
                0.14: '#b9f8c7',
                0.32: '#74eba0',
                0.52: '#35d676',
                0.70: '#16b957',
                0.86: '#0fa64b',
                1.00: '#0b8f40',
            },
        },
        3: {
            label: 'C3',
            title: 'C3 · Environmentally and Financially Vulnerable Seniors',
            name: 'Cluster 3',
            stops: {
                0.00: '#fff8bf',
                0.14: '#fff178',
                0.32: '#ffdd34',
                0.52: '#ffc107',
                0.70: '#f59e00',
                0.86: '#df7f00',
                1.00: '#c26200',
            },
        },
        4: {
            label: 'C4',
            title: 'C4 · Low Functioning / Multi-Domain Priority Seniors',
            name: 'Cluster 4',
            stops: {
                0.00: '#ffe2e2',
                0.14: '#ffbdbd',
                0.32: '#ff8585',
                0.52: '#ff5252',
                0.70: '#ef2f38',
                0.86: '#d91f2b',
                1.00: '#b91625',
            },
        },
    };
    const BARANGAY_COLORS = [
        '#14b8a6', '#f97316', '#8b5cf6', '#22c55e',
        '#eab308', '#06b6d4', '#ef4444', '#84cc16',
        '#f59e0b', '#6366f1', '#ec4899', '#10b981',
        '#0ea5e9', '#a855f7', '#65a30d', '#dc2626',
    ];
    const ACCESSIBILITY_DISTANCE_CAP_METERS = 1500;
    const ROAD_ROUTE_SERVICE_URL = 'https://router.project-osrm.org/route/v1/driving';
    const TILE_LIGHT_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
    const TILE_LIGHT_ATTRIBUTION = '&copy; OpenStreetMap contributors';
    const ROUTE_SERVICE_CANDIDATE_LIMIT = 5;          // nearest facilities that get a live road route per popup
    const ROUTE_SERVICE_DISPLAY_LIMIT = 12;           // facilities listed in a popup (covers all senior-relevant types)
    const ROUTE_SERVICE_RESULT_LIMIT = ROUTE_SERVICE_DISPLAY_LIMIT;
    const SENIOR_RELEVANT_FACILITY_PRIORITY = [
        ['health center', 'hospital', 'clinic', 'rural health'],
        ['pharmacy', 'drugstore', 'medicine'],
        ['senior center', 'senior citizens', 'osca'],
        ['barangay hall', 'municipal hall'],
        ['public market', 'market', 'transport hub', 'terminal'],
        ['church', 'chapel'],
    ];
    const FACILITY_TYPE_COLORS = {
        'Health Center': '#16a34a',
        'Hospital': '#dc2626',
        'Pharmacy': '#7c3aed',
        'Senior Center': '#0f766e',
        'Barangay Hall': '#2563eb',
        'Municipal Hall': '#1d4ed8',
        'Government Office': '#64748b',
        'Police Station': '#1e3a8a',
        'Fire Station': '#ea580c',
        'Public Market': '#ca8a04',
        'Supermarket': '#f59e0b',
        'Community Store': '#84cc16',
        'Food Service': '#db2777',
        'Church': '#9333ea',
        'Emergency Service': '#f97316',
        'Community Facility': '#0891b2',
    };
    const DEFAULT_FACILITY_COLOR = '#0284c7';
    const routeDistanceCache = new Map();
    const warnedInvalidClusterValues = new Set();

    function debounce(fn, ms) {
        let timer;
        return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), ms);
        };
    }

    // --- Cooperative time-slicing ---------------------------------------
    // The KDE raster build is a few hundred ms to ~2s of synchronous canvas
    // work. To keep the UI responsive (no single long task / "page
    // unresponsive" dialog), the build yields to the event loop whenever a
    // time budget is exceeded. MessageChannel yields with ~0ms latency (vs
    // ~16ms for requestAnimationFrame), so total wall-time overhead is tiny.
    const __sliceChannel = (typeof MessageChannel !== 'undefined') ? new MessageChannel() : null;
    const __sliceWaiters = [];
    if (__sliceChannel) {
        __sliceChannel.port1.onmessage = () => {
            const resolve = __sliceWaiters.shift();
            if (resolve) resolve();
        };
    }

    function yieldToEventLoop() {
        return new Promise((resolve) => {
            if (!__sliceChannel) {
                setTimeout(resolve, 0);
                return;
            }
            __sliceWaiters.push(resolve);
            __sliceChannel.port2.postMessage(0);
        });
    }

    function nowMs() {
        return (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
    }

    // Returns a predicate that is true once `budgetMs` of wall-time has
    // elapsed since the last time it returned true (i.e. "time to yield").
    function makeSliceBudget(budgetMs = 10) {
        let last = nowMs();
        return () => {
            const now = nowMs();
            if (now - last >= budgetMs) {
                last = now;
                return true;
            }
            return false;
        };
    }

    // Monotonic token: bumped on every renderDataLayers call so an in-flight
    // async heatmap build from a superseded filter/mode state can detect it is
    // stale and skip mutating the map.
    let activeRenderToken = 0;

    let latestRequestId = 0;
    let latestSeniorGeoJson = null;
    let latestFacilityGeoJson = null;
    let latestMunicipalBoundaryGeoJson = null;
    let latestBarangayBoundaryGeoJson = null;
    let latestRouteDistanceUrl = null;

    // At 10k+ seniors, fetching one GeoJSON feature per senior on every page
    // load measured ~11MB / ~196MB peak PHP memory — a hard crash under the
    // common 128M memory_limit default (see GisApiController::seniors()). The
    // initial load instead fetches a small barangay-level aggregate; individual
    // senior markers are fetched once, on demand, the first time the user asks
    // for them (picks a specific barangay, or clicks a bubble/"View individual
    // seniors" prompt). latestSeniorGeoJson stays null while in aggregate mode
    // so the existing filter/render pipeline (which expects per-senior
    // features) safely no-ops until the upgrade fetch completes.
    let seniorDetailMode = 'pending'; // 'pending' | 'aggregate' | 'full'
    let seniorDetailUpgrading = false;
    let seniorAggregateLayerGroup = null;

    function getCanvasRenderer(map) {
        if (!map._gisCanvasRenderer) {
            const renderer = window.L.canvas({ padding: 0.5, pane: 'gis-senior-pane' });
            renderer.on('add', () => {
                if (renderer._container) {
                    renderer._container.style.pointerEvents = 'none';
                }
            });
            map._gisCanvasRenderer = renderer;
        }
        return map._gisCanvasRenderer;
    }

    function riskColor(level) {
        switch ((level || '').toUpperCase()) {
            case 'HIGH':
                return '#f97316';
            case 'MODERATE':
                return '#f59e0b';
            case 'LOW':
                return '#10b981';
            default:
                return '#64748b';
        }
    }

    function clusterColor(cluster, feature = null) {
        const number = clusterNumber(cluster, feature);
        return number ? CLUSTER_HEATMAP_COLORS[`Group ${number}`] : '#64748b';
    }

    function clusterLabel(feature) {
        const props = feature?.properties || {};
        const fields = [
            props.health_group,
            props.cluster,
            props.cluster_label,
            props.group,
            props.health_group_cluster,
            props.cluster_name,
            props.health_group_name,
        ];

        const value = fields.find((item) => item !== null && item !== undefined && String(item).trim() !== '');
        if (value !== undefined) {
            return String(value);
        }

        const number = clusterNumber('', feature);
        return number ? `Group ${number}` : 'Unassigned';
    }

    function clusterColorForLabel(label, feature = null) {
        return clusterColor(label, feature);
    }

    function clusterNumber(label, feature = null) {
        const props = feature?.properties || {};
        const namedId = numericValue(props.cluster_named_id ?? props.health_group_id);
        if (namedId !== null && namedId >= 1 && namedId <= 4) {
            return namedId;
        }

        const apiClusterId = numericValue(props.cluster_id);
        if (apiClusterId !== null && apiClusterId >= 1 && apiClusterId <= 4) {
            return apiClusterId;
        }

        const textCandidates = [
            label,
            props.health_group,
            props.cluster,
            props.cluster_label,
            props.group,
            props.health_group_cluster,
            props.cluster_name,
            props.health_group_name,
        ];

        for (const candidate of textCandidates) {
            const text = String(candidate ?? '').trim();
            if (!text) continue;

            const namedMatch = text.match(/(?:group|cluster|health\s*group|c)\s*#?\s*([1-4])\b/i);
            if (namedMatch) {
                return Number(namedMatch[1]);
            }

            if (/^[1-4]$/.test(text)) {
                return Number(text);
            }
        }

        const rawId = apiClusterId;
        if (rawId !== null && rawId >= 0 && rawId <= 3) {
            return rawId + 1;
        }

        if (namedId !== null && namedId === 0) {
            return 1;
        }

        return null;
    }

    function clusterRampForLabel(label, feature = null) {
        return CLUSTER_HEATMAP_RAMPS[clusterNumber(label, feature)] ?? null;
    }

    function clusterGradientForLabel(label, feature = null) {
        return clusterRampForLabel(label, feature)?.stops ?? CLUSTER_HEATMAP_GRADIENT;
    }

    function clusterLegendLabel(label) {
        const cluster = clusterRampForLabel(label);
        return cluster ? `${cluster.label} higher intensity within selected cluster` : 'Higher intensity within selected cluster';
    }

    function clusterDisplayName(featureOrNumber) {
        const number = typeof featureOrNumber === 'number'
            ? featureOrNumber
            : featureClusterNumber(featureOrNumber);
        return CLUSTER_HEATMAP_RAMPS[number]?.title ?? 'Unassigned';
    }

    function featureClusterNumber(feature) {
        const number = clusterNumber(clusterLabel(feature), feature);
        if (number === null) {
            const props = feature?.properties || {};
            const rawValue = props.health_group ?? props.cluster ?? props.cluster_label ?? props.group ?? props.health_group_cluster ?? props.cluster_id ?? 'missing';
            const warningKey = String(rawValue);
            if (warningKey && warningKey.toLowerCase() !== 'unassigned' && !warnedInvalidClusterValues.has(warningKey)) {
                warnedInvalidClusterValues.add(warningKey);
                console.warn('[GIS] Unrecognized senior cluster value; marker shown neutral:', rawValue, props);
            }
        }

        return number;
    }

    function featureMatchesSelectedCluster(feature, selectedCluster) {
        if (selectedCluster === 'all') {
            return true;
        }

        const props = feature.properties || {};
        const label = String(props.health_group || props.cluster || props.cluster_label || '');
        if (label === selectedCluster) {
            return true;
        }

        const selectedNumber = clusterNumber(selectedCluster);
        return selectedNumber !== null && featureClusterNumber(feature) === selectedNumber;
    }

    function riskWeight(level) {
        // Widen tier separation so the KDE surface reflects risk SEVERITY rather than
        // population density: a dense cluster of LOW-risk seniors should not out-weigh a
        // few HIGH-risk seniors. HIGH dominates; LOW contributes only a faint floor.
        switch ((level || '').toUpperCase()) {
            case 'HIGH':
                return 1.0;
            case 'MODERATE':
                return 0.4;
            case 'LOW':
                return 0.12;
            default:
                return null;
        }
    }

    function clusterWeight(cluster) {
        return clusterNumber(cluster) ? 1 : 0.4;
    }

    function riskTier(level) {
        switch ((level || '').toUpperCase()) {
            case 'HIGH':
                return 3;
            case 'MODERATE':
                return 2;
            case 'LOW':
                return 1;
            default:
                return 1;
        }
    }

    function clusterTier(cluster) {
        return clusterNumber(cluster) ?? 1;
    }

    function isAcceptedGeoJsonType(contentType) {
        const normalized = (contentType || '').toLowerCase();
        return normalized.includes('application/json') || normalized.includes('application/geo+json');
    }

    function setStatus(message, tone = 'neutral') {
        const statusEl = document.getElementById(STATUS_ID);
        if (!statusEl) return;

        statusEl.textContent = message;
        statusEl.className = 'text-[11.5px] mt-0';

        if (tone === 'success') {
            statusEl.classList.add('text-low-700', 'dark:text-[#4a8a68]');
        } else if (tone === 'error') {
            statusEl.classList.add('text-high-700', 'dark:text-[#e08070]');
        } else {
            statusEl.classList.add('text-ink-400', 'dark:text-[#6b7570]');
        }
    }

    // Toggle the map loading overlay. Shown while the initial GIS layers load so
    // the bare basemap doesn't flash; faded out once the data layers are rendered.
    let mapLoadingHideTimer = null;
    function setMapLoading(isLoading) {
        const overlay = document.getElementById('gis-map-loading');
        if (!overlay) return;
        // Cancel any in-flight fade-out so a re-show can't be clobbered by a stale
        // hide timer (two renderMap() calls within the fade window).
        if (mapLoadingHideTimer !== null) {
            window.clearTimeout(mapLoadingHideTimer);
            mapLoadingHideTimer = null;
        }
        if (isLoading) {
            overlay.style.display = 'flex';
            void overlay.offsetWidth; // reflow so opacity transitions back in on re-show
            overlay.style.opacity = '1';
            overlay.style.pointerEvents = 'auto';
        } else {
            overlay.style.opacity = '0';
            overlay.style.pointerEvents = 'none';
            const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            mapLoadingHideTimer = window.setTimeout(() => {
                overlay.style.display = 'none';
                mapLoadingHideTimer = null;
            }, reduce ? 0 : 320);
        }
    }

    function boundsFromCoords(coords) {
        return window.L.latLngBounds(coords[0], coords[1]);
    }

    function normalizedBounds(bounds, paddingRatio = 0) {
        if (!bounds?.isValid?.()) return null;

        const padded = paddingRatio > 0 ? bounds.pad(paddingRatio) : bounds;

        return window.L.latLngBounds(
            [padded.getSouth(), padded.getWest()],
            [padded.getNorth(), padded.getEast()]
        );
    }

    function hasBoundaryFeatures(geojson) {
        return Array.isArray(geojson?.features) && geojson.features.length > 0;
    }

    function geoJsonBounds(geojson) {
        if (!hasBoundaryFeatures(geojson)) {
            return null;
        }

        const bounds = window.L.geoJSON(geojson).getBounds();

        return bounds.isValid() ? bounds : null;
    }

    function municipalBoundaryBounds() {
        return geoJsonBounds(latestMunicipalBoundaryGeoJson);
    }

    function barangayBoundaryBounds() {
        return geoJsonBounds(latestBarangayBoundaryGeoJson);
    }

    function primaryBoundaryGeoJson() {
        if (hasBoundaryFeatures(latestMunicipalBoundaryGeoJson)) {
            return latestMunicipalBoundaryGeoJson;
        }

        if (hasBoundaryFeatures(latestBarangayBoundaryGeoJson)) {
            return latestBarangayBoundaryGeoJson;
        }

        return null;
    }

    function primaryBoundaryBounds() {
        return municipalBoundaryBounds() ?? barangayBoundaryBounds();
    }

    function updateLegend(mode) {
        const legendEl = document.getElementById(LEGEND_ID);
        if (!legendEl) return;

        const boundaryLegend = [
            hasBoundaryFeatures(latestMunicipalBoundaryGeoJson)
                ? '<span class="inline-flex items-center gap-1.5"><span class="w-3 h-0.5 rounded-full bg-slate-700 inline-block"></span>Municipal Boundary</span>'
                : '',
            hasBoundaryFeatures(latestBarangayBoundaryGeoJson)
                ? '<span class="inline-flex items-center gap-1.5"><span class="w-3 h-0.5 rounded-full bg-slate-400 inline-block"></span>Barangay Boundaries</span>'
                : '',
        ].filter(Boolean).join('');

        if (mode === 'markers') {
            legendEl.innerHTML = `
                <div class="flex w-full flex-wrap items-center gap-x-4 gap-y-2">
                    <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full border-2 border-dashed border-teal-500 bg-white inline-block"></span>Generalized barangay point</span>
                    <span class="inline-flex flex-wrap items-center gap-1.5 max-w-full">
                        <span>Lower count</span>
                        <span class="h-2.5 w-28 rounded-full inline-block border border-white/70" style="background:linear-gradient(90deg,#dbeafe 0%,#38bdf8 35%,#facc15 68%,#ef4444 100%);"></span>
                        <span>Higher count</span>
                    </span>
                </div>
                <div class="flex w-full flex-wrap items-center gap-x-4 gap-y-2">
                    ${facilityLegendHtml()}
                    ${boundaryLegend}
                </div>
            `;
            return;
        }

        const heatmapLabels = {
            'senior-distribution-accessibility-heatmap': ['Accessibility Heatmap', 'Better access', 'Greater access need'],
            'risk-indicator-heatmap': ['Risk Indicator Distribution', 'Lower risk indicator', 'Higher risk indicator'],
            'cluster-heatmap': ['Profile Groups Heatmap', 'Assigned group color', 'Stronger local concentration'],
        };
        const heatmapLabel = heatmapLabels[mode];
        const gradient = 'linear-gradient(90deg,#22c55e 0%,#facc15 48%,#fb923c 76%,#ef4444 100%)';

        if (heatmapLabel) {
            if (mode === 'cluster-heatmap') {
                const clusterLegend = Object.values(CLUSTER_HEATMAP_RAMPS).map((ramp) => `
                    <span class="inline-flex items-center gap-1.5">
                        <span class="h-2.5 w-10 rounded-full inline-block border border-white/70" style="background:${gradientCss(ramp.stops)};"></span>${escapeHtml(ramp.title)}
                    </span>
                `).join('');
                const selectedCluster = selectedClusterGroup();
                const selectedClusterScale = selectedCluster === 'all'
                    ? `<span class="inline-flex flex-wrap items-center gap-1.5 max-w-full">
                        <span>Lower local cluster density</span>
                        <span class="h-3 w-40 rounded-full inline-block border border-white/70" style="background:${gradientCss(CLUSTER_HEATMAP_GRADIENT)};"></span>
                        <span>Higher local cluster density</span>
                    </span>`
                    : `<span class="inline-flex flex-wrap items-center gap-1.5 max-w-full">
                        <span>Lower intensity within selected cluster</span>
                        <span class="h-3 w-40 rounded-full inline-block border border-white/70" style="background:${gradientCss(clusterGradientForLabel(selectedCluster))};"></span>
                        <span>${clusterLegendLabel(selectedCluster)}</span>
                    </span>`;

                legendEl.innerHTML = `
                    <div class="flex w-full flex-wrap items-center gap-x-4 gap-y-2">
                        <span class="font-semibold text-ink-700 dark:text-[#b0b5b2]">${heatmapLabel[0]}</span>
                        ${selectedClusterScale}
                        ${clusterLegend}
                        <span class="text-ink-400 dark:text-[#6b7570]">${selectedCluster === 'all'
                            ? 'All groups are shown as a continuous KDE heatmap surface. Each pixel keeps the locally strongest profile group color without blending groups. Markers show the actual senior profile group.'
                            : 'Selected Group view shows only the chosen group distribution. Contour lines represent equal KDE density levels. Markers show the actual senior profile group.'}</span>
                    </div>
                    <div class="flex w-full flex-wrap items-center gap-x-4 gap-y-2">
                        ${facilityLegendHtml()}
                        ${boundaryLegend}
                    </div>
                `;
                return;
            }

            const riskDotNote = mode === 'risk-indicator-heatmap'
                ? '<span class="text-ink-400 dark:text-[#6b7570]">Dots are individual seniors; color shows local risk density. A senior in a sparsely populated area may appear as a dot with little surrounding color.</span>'
                : '';

            // The accessibility surface is a continuous need gradient; annotate it with the
            // discrete access bands so the heatmap and the senior popups read consistently.
            const accessibilityBandRow = mode === 'senior-distribution-accessibility-heatmap'
                ? `<div class="flex w-full flex-wrap items-center gap-x-4 gap-y-2">${accessibilityBandLegendHtml()}</div>`
                : '';

            legendEl.innerHTML = `
                <div class="flex w-full flex-wrap items-center gap-x-4 gap-y-2">
                    <span class="font-semibold text-ink-700 dark:text-[#b0b5b2]">${heatmapLabel[0]}</span>
                    <span class="inline-flex flex-wrap items-center gap-1.5 max-w-full">
                        <span>${heatmapLabel[1]}</span>
                        <span class="h-2.5 w-28 rounded-full inline-block border border-white/70" style="background:${gradient};"></span>
                        <span>${heatmapLabel[2]}</span>
                    </span>
                    ${riskDotNote}
                </div>
                ${accessibilityBandRow}
                <div class="flex w-full flex-wrap items-center gap-x-4 gap-y-2">
                    ${facilityLegendHtml()}
                    ${boundaryLegend}
                </div>
            `;
            return;
        }

        legendEl.innerHTML = `
            <div class="flex w-full flex-wrap items-center gap-x-4 gap-y-2">
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500 inline-block"></span>Low</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-500 inline-block"></span>Moderate</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-orange-500 inline-block"></span>High</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-rose-400 inline-block"></span>Outer Zone</span>
            </div>
            <div class="flex w-full flex-wrap items-center gap-x-4 gap-y-2">
                ${facilityLegendHtml()}
                ${boundaryLegend}
            </div>
        `;
    }

    function facilityType(featureOrProperties) {
        const properties = featureOrProperties?.properties ?? featureOrProperties ?? {};
        const value = String(properties.type || 'Community Facility').trim();

        return value || 'Community Facility';
    }

    function facilityColor(typeOrFeature) {
        const type = typeof typeOrFeature === 'string' ? typeOrFeature : facilityType(typeOrFeature);

        return FACILITY_TYPE_COLORS[type] ?? DEFAULT_FACILITY_COLOR;
    }

    // Small legend-only glyph for a facility type: a colored circle (matching the
    // on-map marker color) with a simple straight-line pictogram — medical cross
    // for health-related facilities, a building for civic ones, a plain dot
    // otherwise. This only affects the legend swatch text; the actual on-map
    // marker shape (createFacilityIcon()) is unrelated and untouched.
    function facilityIconGlyph(type, color) {
        const t = String(type || '').toLowerCase();
        const isMedical = /health|hospital|pharmac|senior center|clinic/.test(t);
        const isCivic = /hall|government|police|fire|municipal/.test(t);

        if (isMedical) {
            return `<svg width="11" height="11" viewBox="0 0 16 16" aria-hidden="true" class="flex-shrink-0">
                <circle cx="8" cy="8" r="7" fill="${color}" stroke="#ffffff" stroke-width="1"/>
                <path d="M8 4.5v7M4.5 8h7" stroke="#ffffff" stroke-width="1.6" stroke-linecap="round"/>
            </svg>`;
        }

        if (isCivic) {
            return `<svg width="11" height="11" viewBox="0 0 16 16" aria-hidden="true" class="flex-shrink-0">
                <circle cx="8" cy="8" r="7" fill="${color}" stroke="#ffffff" stroke-width="1"/>
                <path d="M4.5 11V7L8 4.5 11.5 7v4" stroke="#ffffff" stroke-width="1.3" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M4.5 11h7" stroke="#ffffff" stroke-width="1.3" stroke-linecap="round"/>
            </svg>`;
        }

        return `<svg width="11" height="11" viewBox="0 0 16 16" aria-hidden="true" class="flex-shrink-0">
            <circle cx="8" cy="8" r="7" fill="${color}" stroke="#ffffff" stroke-width="1"/>
            <circle cx="8" cy="8" r="2.25" fill="#ffffff"/>
        </svg>`;
    }

    function facilityLegendHtml() {
        const features = latestFacilityGeoJson?.features || [];
        const types = [...new Set(features.map((feature) => facilityType(feature)))]
            .filter(Boolean)
            .sort((a, b) => a.localeCompare(b));

        if (!types.length) {
            return `<span class="inline-flex items-center gap-1.5">${facilityIconGlyph('Facility', DEFAULT_FACILITY_COLOR)}Facilities</span>`;
        }

        const items = types.map((type) => `
            <span class="inline-flex items-center gap-1.5">
                ${facilityIconGlyph(type, facilityColor(type))}${escapeHtml(type)}
            </span>
        `).join('');

        return `
            <span class="inline-flex items-center gap-1.5 font-semibold text-ink-700 dark:text-[#b0b5b2]">Facilities:</span>
            ${items}
        `;
    }

    // Swatch colors for the access bands — the design-system risk ramp -500 shades
    // keyed by each band's tone (low → moderate → high → critical).
    const ACCESSIBILITY_BAND_TONE_COLORS = {
        low: '#4a8a68',
        moderate: '#c19a3b',
        high: '#e0621a',
        critical: '#b94a3a',
    };

    function accessibilityBandLegendHtml() {
        const items = ACCESSIBILITY_BANDS.map((band) => {
            const color = ACCESSIBILITY_BAND_TONE_COLORS[band.tone] ?? '#6b7269';
            const range = band.min >= 75 ? '≥75%'
                : band.min >= 50 ? '50–74%'
                : band.min >= 25 ? '25–49%'
                : '<25%';
            return `<span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full inline-block border border-white/70" style="background:${color};"></span>${escapeHtml(band.short)} <span class="text-ink-400 dark:text-[#6b7570]">${range}</span></span>`;
        }).join('');

        return `
            <span class="inline-flex items-center gap-1.5 font-semibold text-ink-700 dark:text-[#b0b5b2]">Facility access:</span>
            ${items}
        `;
    }

    function sourceStatusText(geojson) {
        if (geojson?.source === 'database') {
            const total = geojson?.total ?? geojson.features?.length ?? 0;
            const barangayCount = Number(geojson?.metadata?.barangay_count ?? geojson.features?.length ?? 0);
            const unmatchedCount = Number(geojson?.metadata?.unmatched_senior_count ?? 0);

            return `${total} active senior GIS record(s) loaded across ${barangayCount} Pagsanjan barangay polygon(s). Stored coordinates are used when available; otherwise privacy-safe barangay points are shown. ${unmatchedCount} record(s) had unmatched barangay names.`;
        }

        return 'GIS boundary data loaded.';
    }

    function normalizeGeoJsonPayload(payload) {
        if (payload?.type === 'FeatureCollection' && Array.isArray(payload.features)) {
            return payload;
        }

        if (payload?.data?.type === 'FeatureCollection' && Array.isArray(payload.data.features)) {
            return {
                ...payload.data,
                source: payload.source ?? payload.data.source,
                note: payload.note ?? payload.data.note,
            };
        }

        return null;
    }

    function emptyFeatureCollection(source = 'database') {
        return {
            type: 'FeatureCollection',
            source,
            placement: null,
            total: 0,
            features: [],
        };
    }

    function uniqueSortedValues(features, key) {
        return [...new Set(features.map((feature) => feature.properties?.[key]).filter(Boolean))]
            .sort((a, b) => String(a).localeCompare(String(b)));
    }

    function uniqueSortedClusterValues(features) {
        const values = new Map();

        features.forEach((feature) => {
            const number = featureClusterNumber(feature);
            if (number !== null) {
                values.set(`Group ${number}`, `Group ${number}`);
                return;
            }

            const label = clusterLabel(feature);
            if (label && label.toLowerCase() !== 'unassigned') {
                values.set(label, label);
            }
        });

        return [...values.values()].sort((a, b) => {
            const numberA = clusterNumber(a);
            const numberB = clusterNumber(b);
            if (numberA !== null && numberB !== null) {
                return numberA - numberB;
            }

            return String(a).localeCompare(String(b));
        });
    }

    function setSelectOptions(selectId, defaultLabel, values) {
        const select = document.getElementById(selectId);
        if (!select) return;

        const entries = values.map((value) => (value && typeof value === 'object')
            ? { value: String(value.value), label: String(value.label) }
            : { value: String(value), label: String(value) });

        const currentValue = select.value || 'all';
        select.innerHTML = `<option value="all">${defaultLabel}</option>`;

        entries.forEach((entry) => {
            const option = document.createElement('option');
            option.value = entry.value;
            option.textContent = entry.label;
            select.appendChild(option);
        });

        select.value = entries.some((entry) => entry.value === currentValue) ? currentValue : 'all';
    }

    function initializeFilters(features) {
        setSelectOptions(BARANGAY_FILTER_ID, 'All Barangays', uniqueSortedValues(features, 'barangay'));
        setSelectOptions(RISK_FILTER_ID, 'All Risk Levels', uniqueSortedValues(features, 'risk_level'));
        setSelectOptions(CLUSTER_FILTER_ID, 'All Groups', uniqueSortedClusterValues(features).map((value) => ({
            value,
            label: CLUSTER_HEATMAP_RAMPS[clusterNumber(value, null)]?.title ?? value,
        })));
    }

    function getSelectedValue(selectId) {
        return document.getElementById(selectId)?.value ?? 'all';
    }

    function selectedBarangay() {
        return getSelectedValue(BARANGAY_FILTER_ID);
    }

    function shouldClusterMarkers() {
        return getSelectedValue(BARANGAY_FILTER_ID) === 'all';
    }

    function shouldShowClusterDistributionPoints() {
        return document.getElementById(CLUSTER_POINTS_TOGGLE_ID)?.checked !== false;
    }

    function shouldShowAccessibilitySeniorPoints() {
        return document.getElementById(SHOW_HEATMAP_SENIOR_POINTS_ID)?.checked !== false;
    }

    function shouldShowRiskSeniorPoints() {
        return document.getElementById(SHOW_RISK_SENIOR_POINTS_ID)?.checked !== false;
    }

    function syncLayerOptionsPanel() {
        const mode = document.getElementById(MODE_ID)?.value ?? 'markers';
        const wrapper = document.getElementById(LAYER_OPTIONS_ID);
        const markersPanel = document.getElementById('gis-layer-options-markers');
        const clusterPanel = document.getElementById('gis-layer-options-cluster');

        if (!wrapper) return;

        const showMarkers = mode === 'markers';
        const showCluster = mode === 'cluster-heatmap';

        wrapper.classList.toggle('hidden', !showMarkers && !showCluster);
        markersPanel?.classList.toggle('hidden', !showMarkers);
        clusterPanel?.classList.toggle('hidden', !showCluster);
    }

    function syncAccessibilityPointDisplay() {
        const control = document.getElementById(ACCESSIBILITY_POINT_DISPLAY_ID);
        if (!control) return;

        const mode = document.getElementById(MODE_ID)?.value ?? 'markers';
        control.style.display = mode === 'senior-distribution-accessibility-heatmap' ? '' : 'none';
    }

    function syncRiskPointDisplay() {
        const control = document.getElementById(RISK_POINT_DISPLAY_ID);
        if (!control) return;

        const mode = document.getElementById(MODE_ID)?.value ?? 'markers';
        control.style.display = mode === 'risk-indicator-heatmap' ? '' : 'none';
    }

    // The Risk Level and Cluster / Health Group filters share one slot that
    // adapts to the active visualization: Cluster mode shows the health-group
    // filter, every other mode shows the risk filter. The hidden filter is reset
    // to "all" so it never silently narrows another mode's results.
    function syncSecondaryFilter() {
        const mode = document.getElementById(MODE_ID)?.value ?? 'markers';
        const riskSelect = document.getElementById(RISK_FILTER_ID);
        const clusterSelect = document.getElementById(CLUSTER_FILTER_ID);
        const label = document.getElementById('gis-secondary-filter-label');
        if (!riskSelect || !clusterSelect) return;

        const showCluster = mode === 'cluster-heatmap';
        clusterSelect.classList.toggle('hidden', !showCluster);
        riskSelect.classList.toggle('hidden', showCluster);
        if (label) {
            label.textContent = showCluster ? 'Profile Group' : 'Risk Level';
        }

        const hiddenSelect = showCluster ? riskSelect : clusterSelect;
        if (hiddenSelect.value !== 'all') {
            hiddenSelect.value = 'all';
        }
    }

    function selectedClusterGroup() {
        return getSelectedValue(CLUSTER_FILTER_ID);
    }

    function filteredFeatures(features) {
        const selectedBarangay = getSelectedValue(BARANGAY_FILTER_ID);
        const selectedRisk = String(getSelectedValue(RISK_FILTER_ID)).toLowerCase();
        const selectedCluster = getSelectedValue(CLUSTER_FILTER_ID);

        return features.filter((feature) => {
            const props = feature.properties || {};

            if (selectedBarangay !== 'all' && normalizeBarangayName(props.barangay) !== normalizeBarangayName(selectedBarangay)) {
                return false;
            }

            if (selectedRisk !== 'all' && String(props.risk_level || '').toLowerCase() !== selectedRisk) {
                return false;
            }

            if (selectedCluster !== 'all' && !featureMatchesSelectedCluster(feature, selectedCluster)) {
                return false;
            }

            return true;
        });
    }

    function coordinateKind(feature) {
        const props = feature.properties || {};
        const status = String(props.location_status || '').toLowerCase();
        const source = String(props.location_source || '').toLowerCase();
        const accuracy = String(props.location_accuracy || '').toLowerCase();

        if (status === 'generalized' || source === 'generalized_barangay_fallback') {
            return 'fallback';
        }

        if (status === 'verified' || source === 'manual_pin' || source === 'gps_capture' || accuracy.includes('verified') || accuracy.includes('manual')) {
            return 'verified';
        }

        if (status === 'imported' || source) {
            return 'imported';
        }

        return 'fallback';
    }

    function isExactLocationFeature(feature) {
        return coordinateKind(feature) !== 'fallback';
    }

    function exactLocationFeatures(features) {
        return features.filter(isExactLocationFeature);
    }

    function coordinateStatusLabel(feature) {
        const kind = coordinateKind(feature);

        if (kind === 'verified') {
            return 'Stored GIS point';
        }

        if (kind === 'imported') {
            return 'Imported coordinates';
        }

        return 'Generalized barangay-level point';
    }

    function verifiedSkipText(skippedCount) {
        return skippedCount > 0
            ? ` ${skippedCount} senior record(s) skipped because they have no verified coordinates.`
            : '';
    }

    function validationStatusText(total, stats) {
        const seniorTotal = stats.visible.reduce((sum, feature) => sum + seniorCount(feature), 0);
        return `${seniorTotal} active senior GIS point(s) shown within Pagsanjan.`;
    }

    function featureLatLng(feature) {
        const coords = feature.geometry?.coordinates;
        if (!Array.isArray(coords) || coords.length < 2) return null;
        return window.L.latLng(Number(coords[1]), Number(coords[0]));
    }

    function isHeatmapMode(mode) {
        return HEATMAP_MODES.has(mode);
    }

    function numericValue(value) {
        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : null;
    }

    function seniorCount(feature) {
        return Math.max(0, numericValue(feature?.properties?.senior_count ?? feature?.properties?.total_seniors) ?? 1);
    }

    function clampUnit(value) {
        return Math.max(0, Math.min(1, value));
    }

    function accessibilityNeedWeight(properties, options = {}) {
        const concernScore = numericValue(properties?.accessibility_concern_score);
        if (concernScore !== null) {
            return clampUnit(concernScore <= 1 ? concernScore : concernScore / 100);
        }

        const surfaceWeight = numericValue(properties?.accessibility_surface_weight);
        if (surfaceWeight !== null) {
            return clampUnit(surfaceWeight <= 1 ? surfaceWeight : surfaceWeight / 100);
        }

        const backendNeedScore = numericValue(properties?.accessibility_need_score);
        if (backendNeedScore !== null) {
            return clampUnit(backendNeedScore <= 1 ? backendNeedScore : backendNeedScore / 100);
        }

        const proximityScore = numericValue(properties?.gis_proximity_score);
        if (proximityScore !== null) {
            return clampUnit(1 - (proximityScore / 100));
        }

        const accessibilityScore = numericValue(properties?.accessibility_score);
        if (accessibilityScore !== null) {
            const normalizedScore = accessibilityScore <= 1 ? accessibilityScore : accessibilityScore / 100;
            return clampUnit(1 - normalizedScore);
        }

        if (options.allowDistance === false) {
            return null;
        }

        const nearestDistance = numericValue(properties?.nearest_facility_distance_m);
        if (nearestDistance !== null) {
            return clampUnit(nearestDistance / ACCESSIBILITY_DISTANCE_CAP_METERS);
        }

        return null;
    }

    function accessibilityDistanceMeters(properties) {
        return numericValue(properties?.accessibility_distance_m ?? properties?.nearest_facility_distance_m);
    }

    // Heatmap "concern" weight + discrete band, unified onto the backend
    // AccessibilityBand classification (continuous score for intensity, band key for
    // the legend/level). No relative quantile bucketing — the score is absolute.
    function backendAccessibilityConcern(properties) {
        const score = accessibilityNeedWeight(properties);
        if (score === null) {
            return null;
        }

        return {
            score,
            level: properties?.accessibility_level || null,
        };
    }

    // Plain-language band label for a 0–100 percent (or 0–1 score), read from the
    // shared AccessibilityBand table so the map matches the profile and backend.
    function accessibilityStatus(score) {
        if (score === null || score === undefined || !Number.isFinite(Number(score))) {
            return 'No accessibility score available';
        }

        let value = Number(score);
        if (value <= 1) value *= 100;
        value = Math.max(0, Math.min(100, value));

        const band = ACCESSIBILITY_BANDS.find((b) => value >= b.min);
        return band ? band.short : 'No accessibility score available';
    }

    function heatmapWeight(feature, mode) {
        const props = feature.properties || {};

        if (mode === 'accessibility-heatmap' || mode === 'senior-distribution-accessibility-heatmap') {
            return accessibilityNeedWeight(props);
        }

        if (mode === 'risk-indicator-heatmap') {
            const score = normalizedRiskScore(props.risk_score);
            if (score !== null) {
                return score;
            }

            return riskWeight(props.risk_level);
        }

        if (mode === 'cluster-heatmap') {
            return 1;
        }

        return null;
    }

    function pointInRing(point, ring) {
        const x = point[0];
        const y = point[1];
        let inside = false;

        for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
            const xi = Number(ring[i][0]);
            const yi = Number(ring[i][1]);
            const xj = Number(ring[j][0]);
            const yj = Number(ring[j][1]);

            if (![xi, yi, xj, yj].every(Number.isFinite)) {
                continue;
            }

            const intersects = ((yi > y) !== (yj > y)) &&
                (x < ((xj - xi) * (y - yi)) / ((yj - yi) || Number.EPSILON) + xi);

            if (intersects) {
                inside = !inside;
            }
        }

        return inside;
    }

    function pointInPolygonCoordinates(point, polygonCoordinates) {
        if (!Array.isArray(polygonCoordinates) || !polygonCoordinates.length) {
            return false;
        }

        if (!pointInRing(point, polygonCoordinates[0])) {
            return false;
        }

        return !polygonCoordinates.slice(1).some((hole) => pointInRing(point, hole));
    }

    function pointInsideBoundary(point, boundaryGeoJson) {
        if (!hasBoundaryFeatures(boundaryGeoJson)) {
            return true;
        }

        return boundaryGeoJson.features.some((feature) => {
            const geometry = feature?.geometry;
            const coordinates = geometry?.coordinates;

            if (geometry?.type === 'Polygon') {
                return pointInPolygonCoordinates(point, coordinates);
            }

            if (geometry?.type === 'MultiPolygon') {
                return Array.isArray(coordinates) &&
                    coordinates.some((polygon) => pointInPolygonCoordinates(point, polygon));
            }

            return false;
        });
    }

    function normalizeBarangayName(name) {
        return String(name || '')
            .toLowerCase()
            .replace(/biã±an|biãƒâ±an/g, 'binan')
            .replace(/\bbrgy\.?\s*/g, '')
            .replace(/\(pob\.\)/g, '(poblacion)')
            .replace(/barangay i\s*\(pob\.\)/g, 'barangay i (poblacion)')
            .replace(/barangay ii\s*\(pob\.\)/g, 'barangay ii (poblacion)')
            .replace(/[^\p{L}\p{N}]+/gu, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function barangayNameFromBoundary(feature) {
        return boundaryLabel(feature?.properties);
    }

    function barangayColor(name) {
        const normalized = normalizeBarangayName(name);
        let hash = 0;
        for (let i = 0; i < normalized.length; i++) {
            hash = ((hash << 5) - hash) + normalized.charCodeAt(i);
            hash |= 0;
        }

        return BARANGAY_COLORS[Math.abs(hash) % BARANGAY_COLORS.length];
    }

    function colorWithAlpha(hex, alpha) {
        const [r, g, b] = hexToRgb(hex);
        return `rgba(${r},${g},${b},${alpha})`;
    }

    function rgbString(channels) {
        return `rgb(${channels.map((channel) => Math.round(channel)).join(',')})`;
    }

    function rgbaString(channels, alpha) {
        return `rgba(${channels.map((channel) => Math.round(channel)).join(',')},${alpha})`;
    }

    function selectedBarangayBoundaryFeature() {
        const selected = selectedBarangay();
        if (selected === 'all' || !hasBoundaryFeatures(latestBarangayBoundaryGeoJson)) {
            return null;
        }

        const normalizedSelected = normalizeBarangayName(selected);
        return latestBarangayBoundaryGeoJson.features.find((feature) =>
            normalizeBarangayName(barangayNameFromBoundary(feature)) === normalizedSelected
        ) ?? null;
    }

    function assignedBarangayBoundaryFeature(feature) {
        if (!hasBoundaryFeatures(latestBarangayBoundaryGeoJson)) {
            return null;
        }

        const normalizedBarangay = normalizeBarangayName(feature.properties?.barangay);

        return latestBarangayBoundaryGeoJson.features.find((boundaryFeature) =>
            normalizeBarangayName(barangayNameFromBoundary(boundaryFeature)) === normalizedBarangay
        ) ?? null;
    }

    function dataBarangayBoundaryGeoJson(features) {
        if (!hasBoundaryFeatures(latestBarangayBoundaryGeoJson) || !Array.isArray(features) || !features.length) {
            return null;
        }

        const barangaysWithData = new Set(
            features
                .map((feature) => normalizeBarangayName(feature?.properties?.barangay))
                .filter(Boolean)
        );

        if (!barangaysWithData.size) {
            return null;
        }

        const boundaryFeatures = latestBarangayBoundaryGeoJson.features.filter((feature) =>
            barangaysWithData.has(normalizeBarangayName(barangayNameFromBoundary(feature)))
        );

        return boundaryFeatures.length
            ? { type: 'FeatureCollection', features: boundaryFeatures }
            : null;
    }

    function featureCollectionFromFeature(feature) {
        return {
            type: 'FeatureCollection',
            features: feature ? [feature] : [],
        };
    }

    function featureBounds(feature) {
        if (!feature) return null;
        const bounds = window.L.geoJSON(feature).getBounds();
        return bounds.isValid() ? bounds : null;
    }

    function featureInsideBoundaryFeature(feature, boundaryFeature) {
        if (!boundaryFeature) return true;
        const coords = feature.geometry?.coordinates;
        if (!Array.isArray(coords) || coords.length < 2) return false;

        return pointInsideBoundary([Number(coords[0]), Number(coords[1])], featureCollectionFromFeature(boundaryFeature));
    }

    function featureInsidePrimaryBoundary(feature) {
        const coords = feature.geometry?.coordinates;
        const boundaryGeoJson = primaryBoundaryGeoJson();

        return Array.isArray(coords) &&
            coords.length >= 2 &&
            pointInsideBoundary([Number(coords[0]), Number(coords[1])], boundaryGeoJson);
    }

    function featureInsideAssignedBarangay(feature) {
        const boundaryFeature = assignedBarangayBoundaryFeature(feature);

        if (!boundaryFeature) {
            return true;
        }

        return featureInsideBoundaryFeature(feature, boundaryFeature);
    }

    // Computes each senior's boundary validity + coordinate kind once after data
    // load. Filter/mode switches then reuse these flags instead of re-running
    // point-in-polygon geometry for every senior on every interaction.
    function prevalidateAllFeatures(features) {
        if (!Array.isArray(features)) return;

        features.forEach((feature) => {
            feature.__gisValidity = {
                kind: coordinateKind(feature),
                insidePrimary: featureInsidePrimaryBoundary(feature),
                insideAssigned: featureInsideAssignedBarangay(feature),
            };
        });
    }

    function validatedFeatureSet(features, options = {}) {
        const exactOnly = Boolean(options.exactOnly);
        const stats = {
            visible: [],
            verifiedShown: 0,
            fallbackShown: 0,
            outsidePagsanjan: 0,
            mismatches: 0,
            skippedNoVerified: 0,
        };

        features.forEach((feature) => {
            const validity = feature.__gisValidity;
            const kind = validity ? validity.kind : coordinateKind(feature);
            const insidePrimary = validity ? validity.insidePrimary : featureInsidePrimaryBoundary(feature);
            const insideAssigned = validity ? validity.insideAssigned : featureInsideAssignedBarangay(feature);

            if (!insidePrimary) {
                stats.outsidePagsanjan++;
                return;
            }

            if (!insideAssigned) {
                stats.mismatches++;
                return;
            }

            if (exactOnly && kind === 'fallback') {
                stats.skippedNoVerified++;
                return;
            }

            stats.visible.push(feature);

            if (kind === 'fallback') {
                stats.fallbackShown++;
            } else {
                stats.verifiedShown++;
            }
        });

        return stats;
    }

    function featuresInsidePrimaryBoundary(features) {
        const boundaryGeoJson = primaryBoundaryGeoJson();
        if (!hasBoundaryFeatures(boundaryGeoJson)) {
            return features;
        }

        return features.filter((feature) => {
            const coords = feature.geometry?.coordinates;
            return Array.isArray(coords) &&
                coords.length >= 2 &&
                pointInsideBoundary([Number(coords[0]), Number(coords[1])], boundaryGeoJson);
        });
    }

    function heatmapPoints(features, mode) {
        return features
            .map((feature) => {
                const latlng = featureLatLng(feature);
                const weight = heatmapWeight(feature, mode);

                if (!latlng || weight === null || weight <= 0) {
                    return null;
                }

                return [latlng.lat, latlng.lng, weight];
            })
            .filter(Boolean);
    }

    function heatmapReferenceLatLng(map, features) {
        const selectedBounds = featureBounds(selectedBarangayBoundaryFeature());
        const bounds = selectedBounds ?? primaryBoundaryBounds() ?? combinedBoundsFromFeatures(features);

        if (bounds?.isValid?.()) {
            return bounds.getCenter();
        }

        return map?.getCenter?.() ?? window.L.latLng(PAGSANJAN_CENTER[0], PAGSANJAN_CENTER[1]);
    }

    function metersToPixelsAtLatLng(map, latlng, meters) {
        if (!map || !latlng || !Number.isFinite(meters) || meters <= 0) {
            return 34;
        }

        const destination = window.L.latLng(latlng.lat, latlng.lng + (meters / (111320 * Math.cos(latlng.lat * Math.PI / 180))));
        const centerPoint = map.latLngToLayerPoint(latlng);
        const destinationPoint = map.latLngToLayerPoint(destination);
        const pixels = Math.abs(destinationPoint.x - centerPoint.x);

        return Number.isFinite(pixels) && pixels > 0 ? pixels : 34;
    }

    function median(values) {
        const sorted = values
            .filter((value) => Number.isFinite(value) && value > 0)
            .sort((a, b) => a - b);

        if (!sorted.length) {
            return null;
        }

        const middle = Math.floor(sorted.length / 2);

        return sorted.length % 2 === 0
            ? (sorted[middle - 1] + sorted[middle]) / 2
            : sorted[middle];
    }

    function nearestNeighborDistanceMeters(features) {
        const points = features.map(featureLatLng).filter(Boolean);
        if (points.length < 2) {
            return null;
        }

        const distances = points.map((point, index) => {
            let nearest = Infinity;

            points.forEach((candidate, candidateIndex) => {
                if (candidateIndex === index) return;
                nearest = Math.min(nearest, point.distanceTo(candidate));
            });

            return nearest;
        });

        return median(distances);
    }

    function boundaryRadiusMeters() {
        const selectedBounds = featureBounds(selectedBarangayBoundaryFeature());
        const bounds = selectedBounds ?? primaryBoundaryBounds();

        if (!bounds?.isValid?.()) {
            return null;
        }

        const west = window.L.latLng(bounds.getCenter().lat, bounds.getWest());
        const east = window.L.latLng(bounds.getCenter().lat, bounds.getEast());
        const south = window.L.latLng(bounds.getSouth(), bounds.getCenter().lng);
        const north = window.L.latLng(bounds.getNorth(), bounds.getCenter().lng);
        const width = west.distanceTo(east);
        const height = south.distanceTo(north);
        const smallerSpan = Math.min(width, height);

        return Number.isFinite(smallerSpan) && smallerSpan > 0 ? smallerSpan * 0.18 : null;
    }

    function heatmapRadiusMeters(features, mode) {
        const spacingRadius = nearestNeighborDistanceMeters(features);
        const boundaryRadius = boundaryRadiusMeters();
        const fallbackRadius = mode === 'cluster-heatmap' ? 300 : 260;
        const derivedRadius = median([spacingRadius ? spacingRadius * 1.35 : null, boundaryRadius, fallbackRadius]);

        if (mode === 'accessibility-heatmap' || mode === 'senior-distribution-accessibility-heatmap') {
            return Math.max(180, Math.min(560, derivedRadius ?? fallbackRadius));
        }

        if (mode === 'cluster-heatmap') {
            return Math.max(300, Math.min(520, derivedRadius ?? fallbackRadius));
        }

        return Math.max(160, Math.min(480, derivedRadius ?? fallbackRadius));
    }

    function heatmapPixelOptions(map, features, mode, options = {}) {
        const meters = Number.isFinite(options.radiusMeters)
            ? options.radiusMeters
            : heatmapRadiusMeters(features, mode);
        const reference = heatmapReferenceLatLng(map, features);

        const rawRadius = metersToPixelsAtLatLng(map, reference, meters);

        if (mode === 'cluster-heatmap') {
            const radius = Math.round(Math.max(6, Math.min(52, rawRadius)));

            return {
                radius,
                blur: Math.round(Math.max(4, Math.min(32, radius * 0.72))),
                radius_meters: Math.round(meters),
            };
        }

        // Floor at 6px — the geographic radius (meters) already sets the true
        // spread; clamping at a tiny pixel floor avoids bleeding into empty
        // areas when zoomed out far.
        const radius = Math.round(Math.max(6, Math.min(160, rawRadius)));

        return {
            radius,
            blur: Math.round(Math.max(4, Math.min(88, radius * 0.65))),
            radius_meters: Math.round(meters),
        };
    }

    function heatmapMaxIntensity(points, mode) {
        return 1;
    }

    function heatmapNormalization(points, radius, mode) {
        if (!points.length) {
            return 1;
        }

        if (mode !== 'cluster-heatmap') {
            return heatmapMaxIntensity(points, mode);
        }

        // Health groups are categorical, so this layer represents density of the
        // selected group only instead of ranking groups by numeric cluster ID.
        return 1;
    }

    function hexToRgb(hex) {
        const normalized = String(hex || '').replace('#', '');
        const value = normalized.length === 3
            ? normalized.split('').map((part) => part + part).join('')
            : normalized;

        const parsed = Number.parseInt(value, 16);

        return Number.isFinite(parsed)
            ? [(parsed >> 16) & 255, (parsed >> 8) & 255, parsed & 255]
            : [239, 68, 68];
    }

    function gradientStops(mode) {
        return gradientStopsFromStops(heatmapGradient(mode));
    }

    function gradientStopsFromStops(stops) {
        return Object.entries(stops)
            .map(([stop, color]) => ({
                stop: Number(stop),
                color: hexToRgb(color),
            }))
            .sort((a, b) => a.stop - b.stop);
    }

    function gradientCss(stops) {
        return `linear-gradient(90deg,${Object.entries(stops)
            .sort(([a], [b]) => Number(a) - Number(b))
            .map(([stop, color]) => `${color} ${Math.round(Number(stop) * 100)}%`)
            .join(',')})`;
    }

    function colorForGradientValue(value, stops) {
        const clamped = clampUnit(value);
        let lower = stops[0];
        let upper = stops[stops.length - 1];

        for (let index = 1; index < stops.length; index++) {
            if (clamped <= stops[index].stop) {
                lower = stops[index - 1];
                upper = stops[index];
                break;
            }
        }

        const range = Math.max(upper.stop - lower.stop, Number.EPSILON);
        const ratio = clampUnit((clamped - lower.stop) / range);

        return lower.color.map((channel, index) => Math.round(channel + ((upper.color[index] - channel) * ratio)));
    }

    function canvasPixelInsideBoundary(map, x, y, boundaryGeoJson) {
        if (!hasBoundaryFeatures(boundaryGeoJson)) {
            return true;
        }

        const latlng = map.containerPointToLatLng([x, y]);
        return pointInsideBoundary([latlng.lng, latlng.lat], boundaryGeoJson);
    }

    function createCanvasKdeLayer(points, options) {
        const KdeLayer = window.L.Layer.extend({
            initialize() {
                this._points = points;
                this._options = options;
                this._stops = gradientStops(options.mode);
            },

            onAdd(map) {
                this._map = map;
                this._canvas = window.L.DomUtil.create('canvas', 'leaflet-layer gis-kde-canvas');
                this._canvas.style.pointerEvents = 'none';

                const pane = map.getPane('gis-heat-pane') ?? map.getPanes().overlayPane;
                pane.appendChild(this._canvas);

                map.on('moveend zoomend resize', this._reset, this);
                if (map.options.zoomAnimation && window.L.Browser.any3d) {
                    map.on('zoomanim', this._animateZoom, this);
                }
                this._reset();
            },

            onRemove(map) {
                if (this._canvas?.parentNode) {
                    this._canvas.parentNode.removeChild(this._canvas);
                }

                map.off('moveend zoomend resize', this._reset, this);
                if (map.options.zoomAnimation) {
                    map.off('zoomanim', this._animateZoom, this);
                }
            },

            _reset() {
                const size = this._map.getSize();
                const topLeft = this._map.containerPointToLayerPoint([0, 0]);

                window.L.DomUtil.setPosition(this._canvas, topLeft);
                this._canvas.width = size.x;
                this._canvas.height = size.y;
                this._redraw();
            },

            _animateZoom(event) {
                const scale = this._map.getZoomScale(event.zoom);
                const offset = this._map
                    ._getCenterOffset(event.center)
                    ._multiplyBy(-scale)
                    .subtract(this._map._getMapPanePos());

                if (window.L.DomUtil.setTransform) {
                    window.L.DomUtil.setTransform(this._canvas, offset, scale);
                    return;
                }

                this._canvas.style[window.L.DomUtil.TRANSFORM] = `${window.L.DomUtil.getTranslateString(offset)} scale(${scale})`;
            },

            _redraw() {
                const width = this._canvas.width;
                const height = this._canvas.height;
                // Recompute pixel radius from the stored geographic radius on every
                // redraw so the kernel never grows beyond its real geographic footprint
                // when zoomed out (was: fixed pixel radius set at layer-creation time).
                let radius = this._options.radius;
                let blur = Math.max(1, Math.min(radius, this._options.blur || radius * 0.65));
                if (this._options.radius_meters && this._map) {
                    const ref = this._map.getCenter();
                    const raw = metersToPixelsAtLatLng(this._map, ref, this._options.radius_meters);
                    radius = Math.round(Math.max(6, Math.min(160, raw)));
                    blur = Math.round(Math.max(4, Math.min(88, radius * 0.65)));
                }
                const max = Math.max(this._options.max || 1, Number.EPSILON);
                const densityCanvas = document.createElement('canvas');
                densityCanvas.width = width;
                densityCanvas.height = height;

                const densityContext = densityCanvas.getContext('2d');
                densityContext.clearRect(0, 0, width, height);
                densityContext.globalCompositeOperation = 'lighter';

                this._points.forEach(([lat, lng, weight]) => {
                    const point = this._map.latLngToContainerPoint([lat, lng]);
                    if (point.x < -radius || point.y < -radius || point.x > width + radius || point.y > height + radius) {
                        return;
                    }

                    const intensity = clampUnit((Number(weight) || 0) / max);
                    if (intensity <= 0) {
                        return;
                    }

                    // Tight typhoon-style kernel: strong core that drops sharply
                    // so isolated seniors produce a peaked spot and empty areas
                    // between data points stay near-transparent.
                    const coreAlpha = Math.min(0.90, intensity * 0.82);
                    const shoulderAlpha = Math.min(0.24, intensity * 0.20);
                    const edgeAlpha = Math.min(0.05, intensity * 0.04);
                    const gradient = densityContext.createRadialGradient(point.x, point.y, 0, point.x, point.y, radius);
                    gradient.addColorStop(0,    `rgba(0,0,0,${coreAlpha})`);
                    gradient.addColorStop(0.18, `rgba(0,0,0,${coreAlpha * 0.78})`);
                    gradient.addColorStop(0.40, `rgba(0,0,0,${shoulderAlpha})`);
                    gradient.addColorStop(0.68, `rgba(0,0,0,${edgeAlpha})`);
                    gradient.addColorStop(0.88, `rgba(0,0,0,${edgeAlpha * 0.22})`);
                    gradient.addColorStop(1,    'rgba(0,0,0,0)');

                    densityContext.fillStyle = gradient;
                    densityContext.beginPath();
                    densityContext.arc(point.x, point.y, radius, 0, Math.PI * 2);
                    densityContext.fill();
                });

                const densityImage = densityContext.getImageData(0, 0, width, height);
                const outputContext = this._canvas.getContext('2d');
                const outputImage = outputContext.createImageData(width, height);

                for (let index = 0; index < densityImage.data.length; index += 4) {
                    const alpha = densityImage.data[index + 3];
                    if (!alpha) {
                        continue;
                    }

                    const colorScaleMax = Math.max(this._options.colorScaleMax || 255, 1);
                    const normalized = clampUnit(alpha / colorScaleMax);
                    const minVisibleDensity = this._options.minVisibleDensity ?? 0;
                    if (normalized < minVisibleDensity) {
                        continue;
                    }

                    if (!canvasPixelInsideBoundary(this._map, (index / 4) % width, Math.floor((index / 4) / width), this._options.clipBoundary)) {
                        continue;
                    }

                    const [red, green, blue] = colorForGradientValue(normalized, this._stops);

                    outputImage.data[index] = red;
                    outputImage.data[index + 1] = green;
                    outputImage.data[index + 2] = blue;
                    const maxAlpha = Math.max(1, Math.min(255, this._options.outputMaxAlpha || 190));
                    const alphaBase = Math.max(1, Math.min(255, this._options.outputAlphaBase || 220));
                    const alphaPower = Number.isFinite(this._options.outputAlphaPower) ? this._options.outputAlphaPower : 0.94;
                    outputImage.data[index + 3] = Math.round(Math.min(maxAlpha, alphaBase * Math.pow(normalized, alphaPower)));
                }

                outputContext.clearRect(0, 0, width, height);
                outputContext.putImageData(outputImage, 0, 0);
            },
        });

        return new KdeLayer();
    }

    function createClusterDistributionKdeLayer(groups, options) {
        const ClusterDistributionLayer = window.L.Layer.extend({
            initialize() {
                this._groups = groups;
                this._options = options;
            },

            onAdd(map) {
                this._map = map;
                this._canvas = window.L.DomUtil.create('canvas', 'leaflet-layer gis-kde-canvas');
                this._canvas.style.pointerEvents = 'none';

                const pane = map.getPane('gis-heat-pane') ?? map.getPanes().overlayPane;
                pane.appendChild(this._canvas);

                map.on('moveend zoomend resize', this._reset, this);
                if (map.options.zoomAnimation && window.L.Browser.any3d) {
                    map.on('zoomanim', this._animateZoom, this);
                }

                this._reset();
            },

            onRemove(map) {
                if (this._canvas?.parentNode) {
                    this._canvas.parentNode.removeChild(this._canvas);
                }

                map.off('moveend zoomend resize', this._reset, this);
                if (map.options.zoomAnimation) {
                    map.off('zoomanim', this._animateZoom, this);
                }
            },

            _reset() {
                const size = this._map.getSize();
                const topLeft = this._map.containerPointToLayerPoint([0, 0]);

                window.L.DomUtil.setPosition(this._canvas, topLeft);
                this._canvas.width = size.x;
                this._canvas.height = size.y;
                this._redraw();
            },

            _animateZoom(event) {
                const scale = this._map.getZoomScale(event.zoom);
                const offset = this._map
                    ._getCenterOffset(event.center)
                    ._multiplyBy(-scale)
                    .subtract(this._map._getMapPanePos());

                if (window.L.DomUtil.setTransform) {
                    window.L.DomUtil.setTransform(this._canvas, offset, scale);
                    return;
                }

                this._canvas.style[window.L.DomUtil.TRANSFORM] = `${window.L.DomUtil.getTranslateString(offset)} scale(${scale})`;
            },

            _densityForGroup(group, width, height, radius) {
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;

                const context = canvas.getContext('2d');
                context.globalCompositeOperation = 'lighter';

                group.points.forEach(([lat, lng, weight]) => {
                    const point = this._map.latLngToContainerPoint([lat, lng]);
                    if (point.x < -radius || point.y < -radius || point.x > width + radius || point.y > height + radius) {
                        return;
                    }

                    const intensity = clampUnit(Number(weight) || 0);
                    if (intensity <= 0) {
                        return;
                    }

                    const coreAlpha = Math.min(0.82, intensity * 0.74);
                    const midAlpha = Math.min(0.38, intensity * 0.34);
                    const edgeAlpha = Math.min(0.10, intensity * 0.09);
                    const gradient = context.createRadialGradient(point.x, point.y, 0, point.x, point.y, radius);
                    gradient.addColorStop(0, `rgba(0,0,0,${coreAlpha})`);
                    gradient.addColorStop(0.34, `rgba(0,0,0,${midAlpha})`);
                    gradient.addColorStop(0.72, `rgba(0,0,0,${edgeAlpha})`);
                    gradient.addColorStop(1, 'rgba(0,0,0,0)');

                    context.fillStyle = gradient;
                    context.beginPath();
                    context.arc(point.x, point.y, radius, 0, Math.PI * 2);
                    context.fill();
                });

                return context.getImageData(0, 0, width, height).data;
            },

            _redraw() {
                const width = this._canvas.width;
                const height = this._canvas.height;
                const radius = this._options.radius;
                const dominancePower = this._options.dominancePower || 2.1;
                const groupImages = this._groups.map((group) => ({
                    label: group.label,
                    ramp: gradientStopsFromStops(group.stops),
                    data: this._densityForGroup(group, width, height, radius),
                }));
                const outputContext = this._canvas.getContext('2d');
                const outputImage = outputContext.createImageData(width, height);

                for (let index = 0; index < outputImage.data.length; index += 4) {
                    let dominantAlpha = 0;
                    let dominantGroup = null;
                    let totalAlpha = 0;

                    groupImages.forEach((group) => {
                        const alpha = group.data[index + 3];
                        totalAlpha += alpha;
                        if (alpha > dominantAlpha) {
                            dominantAlpha = alpha;
                            dominantGroup = group;
                        }
                    });

                    const colorScaleMax = Math.max(this._options.colorScaleMax || 255, 1);
                    const normalized = clampUnit(dominantAlpha / colorScaleMax);
                    const minVisibleDensity = this._options.minVisibleDensity ?? 0.16;
                    if (!dominantGroup || normalized < minVisibleDensity) {
                        continue;
                    }

                    if (!canvasPixelInsideBoundary(this._map, (index / 4) % width, Math.floor((index / 4) / width), this._options.clipBoundary)) {
                        continue;
                    }

                    const [red, green, blue] = colorForGradientValue(Math.pow(normalized, dominancePower), dominantGroup.ramp);
                    const opacityIntensity = clampUnit(totalAlpha / colorScaleMax);

                    // K=4 cluster raster: each pixel is colored by the dominant
                    // nearby real senior cluster surface and clipped to Pagsanjan.
                    // Weak kernel edges are dropped so barangays without senior
                    // point influence remain transparent instead of being painted.
                    outputImage.data[index] = red;
                    outputImage.data[index + 1] = green;
                    outputImage.data[index + 2] = blue;
                    outputImage.data[index + 3] = Math.round(Math.min(
                        this._options.outputMaxAlpha || 150,
                        (this._options.outputAlphaBase || 185) * Math.pow(opacityIntensity, this._options.outputAlphaPower || 1.05)
                    ));
                }

                outputContext.clearRect(0, 0, width, height);
                outputContext.putImageData(outputImage, 0, 0);
            },
        });

        return new ClusterDistributionLayer();
    }

    // Rasterizes a clip-boundary polygon into a flat inside/outside bitmap.
    // projectFn maps a (lat, lng) to canvas pixel coords for the target space.
    // Replaces per-pixel point-in-polygon ray-casting (which ran pixels ×
    // boundary-vertices times and froze the main thread) with an O(1) lookup.
    function buildBoundaryMask(width, height, projectFn, boundary) {
        if (!hasBoundaryFeatures(boundary) || width <= 0 || height <= 0) {
            return null;
        }

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#000000';

        const tracePolygon = (rings) => {
            if (!Array.isArray(rings) || !rings.length) return;
            ctx.beginPath();
            rings.forEach((ring) => {
                if (!Array.isArray(ring)) return;
                ring.forEach(([lng, lat], pointIndex) => {
                    const point = projectFn(Number(lat), Number(lng));
                    if (pointIndex === 0) {
                        ctx.moveTo(point.x, point.y);
                    } else {
                        ctx.lineTo(point.x, point.y);
                    }
                });
                ctx.closePath();
            });
            // even-odd so inner rings (holes) are carved out, matching
            // pointInsideBoundary's outer-ring / hole semantics.
            ctx.fill('evenodd');
        };

        boundary.features.forEach((feature) => {
            const geometry = feature?.geometry;
            const coordinates = geometry?.coordinates;
            if (!geometry || !Array.isArray(coordinates)) return;

            if (geometry.type === 'Polygon') {
                tracePolygon(coordinates);
            } else if (geometry.type === 'MultiPolygon') {
                coordinates.forEach((polygon) => tracePolygon(polygon));
            }
        });

        const data = ctx.getImageData(0, 0, width, height).data;
        const mask = new Uint8Array(width * height);
        for (let pixel = 0; pixel < mask.length; pixel++) {
            mask[pixel] = data[(pixel * 4) + 3] > 0 ? 1 : 0;
        }

        return mask;
    }

    // Raster-space masks are keyed by bounds + size; the municipal bounds are
    // constant across filters and zoom, so the mask is built at most once.
    const rasterBoundaryMaskCache = new Map();

    function getRasterBoundaryMask(bounds, width, height, boundary) {
        if (!hasBoundaryFeatures(boundary) || !bounds?.isValid?.()) {
            return null;
        }

        const key = [
            bounds.getSouth().toFixed(6),
            bounds.getWest().toFixed(6),
            bounds.getNorth().toFixed(6),
            bounds.getEast().toFixed(6),
            `${width}x${height}`,
        ].join('|');

        if (rasterBoundaryMaskCache.has(key)) {
            return rasterBoundaryMaskCache.get(key);
        }

        const mask = buildBoundaryMask(
            width,
            height,
            (lat, lng) => latLngToRasterPoint(lat, lng, bounds, width, height),
            boundary
        );
        rasterBoundaryMaskCache.set(key, mask);

        return mask;
    }

    function rasterSizeForBounds(bounds, options = {}) {
        if (!bounds?.isValid?.()) {
            return { width: 512, height: 512 };
        }

        const center = bounds.getCenter();
        const west = window.L.latLng(center.lat, bounds.getWest());
        const east = window.L.latLng(center.lat, bounds.getEast());
        const south = window.L.latLng(bounds.getSouth(), center.lng);
        const north = window.L.latLng(bounds.getNorth(), center.lng);
        const widthMeters = Math.max(1, west.distanceTo(east));
        const heightMeters = Math.max(1, south.distanceTo(north));
        const pixelRatio = Math.max(1, Math.min(options.pixelRatioCap ?? 1.5, window.devicePixelRatio || 1));
        const maxSide = Math.round((options.maxRasterSide ?? 900) * pixelRatio);
        const minSide = Math.round((options.minRasterSide ?? 560) * pixelRatio);

        if (widthMeters >= heightMeters) {
            return {
                width: maxSide,
                height: Math.max(minSide, Math.round(maxSide * (heightMeters / widthMeters))),
            };
        }

        return {
            width: Math.max(minSide, Math.round(maxSide * (widthMeters / heightMeters))),
            height: maxSide,
        };
    }

    function latLngToRasterPoint(lat, lng, bounds, width, height) {
        const west = bounds.getWest();
        const east = bounds.getEast();
        const south = bounds.getSouth();
        const north = bounds.getNorth();

        return {
            x: ((lng - west) / Math.max(east - west, Number.EPSILON)) * width,
            y: ((north - lat) / Math.max(north - south, Number.EPSILON)) * height,
        };
    }

    function rasterRadiusPixels(bounds, width, height, radiusMeters) {
        const center = bounds.getCenter();
        const west = window.L.latLng(center.lat, bounds.getWest());
        const east = window.L.latLng(center.lat, bounds.getEast());
        const south = window.L.latLng(bounds.getSouth(), center.lng);
        const north = window.L.latLng(bounds.getNorth(), center.lng);
        const metersPerPixelX = west.distanceTo(east) / Math.max(width, 1);
        const metersPerPixelY = south.distanceTo(north) / Math.max(height, 1);
        const metersPerPixel = Math.max(0.01, Math.min(metersPerPixelX, metersPerPixelY));

        return Math.max(16, Math.min(220, radiusMeters / metersPerPixel));
    }

    function smoothedRasterData(canvas, blurPixels) {
        const width = canvas.width;
        const height = canvas.height;
        const blur = Math.max(0, Math.round(blurPixels || 0));

        if (blur <= 0) {
            return canvas.getContext('2d').getImageData(0, 0, width, height).data;
        }

        const blurred = document.createElement('canvas');
        blurred.width = width;
        blurred.height = height;
        const context = blurred.getContext('2d');

        context.clearRect(0, 0, width, height);
        context.filter = `blur(${blur}px)`;
        context.drawImage(canvas, 0, 0);
        context.filter = 'none';

        return context.getImageData(0, 0, width, height).data;
    }

    function smoothScalarGrid(grid, width, height, passes = 1) {
        let source = grid;

        for (let pass = 0; pass < passes; pass += 1) {
            const target = new Float32Array(width * height);

            for (let y = 0; y < height; y += 1) {
                for (let x = 0; x < width; x += 1) {
                    let total = 0;
                    let weight = 0;

                    for (let offsetY = -1; offsetY <= 1; offsetY += 1) {
                        const sampleY = y + offsetY;
                        if (sampleY < 0 || sampleY >= height) {
                            continue;
                        }

                        for (let offsetX = -1; offsetX <= 1; offsetX += 1) {
                            const sampleX = x + offsetX;
                            if (sampleX < 0 || sampleX >= width) {
                                continue;
                            }

                            const sampleWeight = offsetX === 0 && offsetY === 0 ? 4 : (offsetX === 0 || offsetY === 0 ? 2 : 1);
                            total += source[(sampleY * width) + sampleX] * sampleWeight;
                            weight += sampleWeight;
                        }
                    }

                    target[(y * width) + x] = weight > 0 ? total / weight : source[(y * width) + x];
                }
            }

            source = target;
        }

        return source;
    }

    function smoothRasterColorEdges(imageData, width, height, passes = 1, strength = 0.32, radius = 1) {
        if (!passes || passes <= 0 || strength <= 0) return;

        const clampedStrength = Math.max(0, Math.min(0.72, strength));
        const kernelRadius = Math.max(1, Math.min(4, Math.round(radius)));
        const sigma = Math.max(0.85, kernelRadius * 0.72);

        for (let pass = 0; pass < passes; pass += 1) {
            const source = new Uint8ClampedArray(imageData.data);

            for (let y = 0; y < height; y += 1) {
                for (let x = 0; x < width; x += 1) {
                    const index = ((y * width) + x) * 4;
                    const alpha = source[index + 3];
                    if (alpha <= 0) continue;

                    let totalWeight = 0;
                    let red = 0;
                    let green = 0;
                    let blue = 0;

                    for (let offsetY = -kernelRadius; offsetY <= kernelRadius; offsetY += 1) {
                        const sampleY = y + offsetY;
                        if (sampleY < 0 || sampleY >= height) continue;

                        for (let offsetX = -kernelRadius; offsetX <= kernelRadius; offsetX += 1) {
                            const sampleX = x + offsetX;
                            if (sampleX < 0 || sampleX >= width) continue;

                            const sampleIndex = ((sampleY * width) + sampleX) * 4;
                            const sampleAlpha = source[sampleIndex + 3];
                            if (sampleAlpha <= 0) continue;

                            const distanceSquared = (offsetX * offsetX) + (offsetY * offsetY);
                            const distanceWeight = Math.exp(-distanceSquared / (2 * sigma * sigma));
                            const sampleWeight = distanceWeight * (sampleAlpha / 255);
                            totalWeight += sampleWeight;
                            red += source[sampleIndex] * sampleWeight;
                            green += source[sampleIndex + 1] * sampleWeight;
                            blue += source[sampleIndex + 2] * sampleWeight;
                        }
                    }

                    if (totalWeight <= 0) continue;

                    imageData.data[index] = Math.round(source[index] * (1 - clampedStrength) + (red / totalWeight) * clampedStrength);
                    imageData.data[index + 1] = Math.round(source[index + 1] * (1 - clampedStrength) + (green / totalWeight) * clampedStrength);
                    imageData.data[index + 2] = Math.round(source[index + 2] * (1 - clampedStrength) + (blue / totalWeight) * clampedStrength);
                    imageData.data[index + 3] = alpha;
                }
            }
        }
    }

    function contourEdgePoint(edge, x, y, step, values, level) {
        const interpolate = (start, end) => {
            const range = end - start;
            if (Math.abs(range) < Number.EPSILON) {
                return 0.5;
            }

            return Math.max(0, Math.min(1, (level - start) / range));
        };

        const [topLeft, topRight, bottomRight, bottomLeft] = values;

        if (edge === 'top') {
            return [x + (interpolate(topLeft, topRight) * step), y];
        }

        if (edge === 'right') {
            return [x + step, y + (interpolate(topRight, bottomRight) * step)];
        }

        if (edge === 'bottom') {
            return [x + (interpolate(bottomLeft, bottomRight) * step), y + step];
        }

        return [x, y + (interpolate(topLeft, bottomLeft) * step)];
    }

    function drawKdeContours(context, densityGrid, width, height, options = {}) {
        const levels = options.levels ?? [0.16, 0.27, 0.38, 0.49, 0.60, 0.71, 0.82, 0.93];
        const step = options.step ?? 1;
        const baseLineWidth = options.lineWidth ?? Math.max(0.8, Math.min(1.4, 220 / Math.min(width, height)));
        const edgePairs = [
            ['top', 0, 1],
            ['right', 1, 2],
            ['bottom', 3, 2],
            ['left', 0, 3],
        ];

        context.save();
        context.imageSmoothingEnabled = true;
        context.imageSmoothingQuality = 'high';
        context.lineCap = 'round';
        context.lineJoin = 'round';

        levels.forEach((level, levelIndex) => {
            const levelFrac = levels.length > 1 ? levelIndex / (levels.length - 1) : 0;
            const lineWidth = baseLineWidth * (0.80 + levelFrac * 0.72);
            context.lineWidth = lineWidth;
            context.beginPath();

            for (let y = 0; y < height - step; y += step) {
                for (let x = 0; x < width - step; x += step) {
                    const topLeft = densityGrid[y * width + x];
                    const topRight = densityGrid[y * width + x + step];
                    const bottomRight = densityGrid[(y + step) * width + x + step];
                    const bottomLeft = densityGrid[(y + step) * width + x];
                    const values = [topLeft, topRight, bottomRight, bottomLeft];
                    const above = values.filter((value) => value >= level).length;

                    if (above === 0 || above === 4) {
                        continue;
                    }

                    const points = [];
                    edgePairs.forEach(([edge, startIndex, endIndex]) => {
                        const start = values[startIndex];
                        const end = values[endIndex];
                        if ((start < level && end >= level) || (start >= level && end < level)) {
                            points.push(contourEdgePoint(edge, x, y, step, values, level));
                        }
                    });

                    if (points.length === 2) {
                        context.moveTo(points[0][0], points[0][1]);
                        context.lineTo(points[1][0], points[1][1]);
                    } else if (points.length === 4) {
                        context.moveTo(points[0][0], points[0][1]);
                        context.lineTo(points[1][0], points[1][1]);
                        context.moveTo(points[2][0], points[2][1]);
                        context.lineTo(points[3][0], points[3][1]);
                    }
                }
            }

            context.shadowColor = 'transparent';
            context.shadowBlur = 0;
            const haloLineWidth = options.haloLineWidth ?? 0.25;
            if (haloLineWidth > 0) {
                context.lineWidth = lineWidth + haloLineWidth;
                context.strokeStyle = `rgba(15,23,42,${options.haloOpacity ?? 0.06})`;
                context.stroke();
            }
            context.lineWidth = lineWidth;
            context.strokeStyle = `rgba(255,255,255,${Math.min(options.maxOpacity ?? 0.88, (options.opacityBase ?? 0.48) + (levelFrac * (options.opacityRange ?? 0.30)))})`;
            context.stroke();
        });

        context.restore();
    }

    async function createClusterDistributionRasterLayer(groups, options) {
        const bounds = options.bounds;
        if (!bounds?.isValid?.()) {
            return null;
        }

        const { width, height } = rasterSizeForBounds(bounds, options);
        const radius = rasterRadiusPixels(bounds, width, height, options.radius_meters);
        const peakRadiusMeters = options.peak_radius_meters ?? Math.max(80, Math.min(140, options.radius_meters * 0.38));
        const peakRadius = rasterRadiusPixels(bounds, width, height, peakRadiusMeters);
        const pointCoreRadiusMeters = options.point_core_radius_meters ?? Math.max(80, Math.min(130, options.radius_meters * 0.34));
        const pointCoreRadius = rasterRadiusPixels(bounds, width, height, pointCoreRadiusMeters);
        const smoothingPixels = Math.max(
            options.smoothingPixelMin ?? 14,
            Math.min(options.smoothingPixelMax ?? 36, radius * (options.smoothingPixelRatio ?? 0.52))
        );
        const peakSmoothingPixels = Math.max(
            options.peakSmoothingPixelMin ?? 8,
            Math.min(options.peakSmoothingPixelMax ?? 22, peakRadius * (options.peakSmoothingPixelRatio ?? 0.38))
        );
        const pointCoreSmoothingPixels = Math.max(
            options.pointCoreSmoothingPixelMin ?? 5,
            Math.min(options.pointCoreSmoothingPixelMax ?? 14, pointCoreRadius * (options.pointCoreSmoothingPixelRatio ?? 0.26))
        );
        const groupImages = [];
        for (const group of groups) {
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            const context = canvas.getContext('2d');
            context.globalCompositeOperation = 'lighter';
            const peakCanvas = document.createElement('canvas');
            peakCanvas.width = width;
            peakCanvas.height = height;
            const peakContext = peakCanvas.getContext('2d');
            peakContext.globalCompositeOperation = 'lighter';
            const pointCoreCanvas = document.createElement('canvas');
            pointCoreCanvas.width = width;
            pointCoreCanvas.height = height;
            const pointCoreContext = pointCoreCanvas.getContext('2d');
            pointCoreContext.globalCompositeOperation = 'source-over';

            group.points.forEach(([lat, lng, weight]) => {
                const point = latLngToRasterPoint(lat, lng, bounds, width, height);
                if (point.x < -radius || point.y < -radius || point.x > width + radius || point.y > height + radius) {
                    return;
                }

                const intensity = clampUnit(Number(weight) || 0);
                if (intensity <= 0) {
                    return;
                }

                const gradient = context.createRadialGradient(point.x, point.y, 0, point.x, point.y, radius);
                gradient.addColorStop(0,    `rgba(0,0,0,${Math.min(0.80, intensity * 0.74)})`);
                gradient.addColorStop(0.14, `rgba(0,0,0,${Math.min(0.68, intensity * 0.62)})`);
                gradient.addColorStop(0.30, `rgba(0,0,0,${Math.min(0.46, intensity * 0.42)})`);
                gradient.addColorStop(0.50, `rgba(0,0,0,${Math.min(0.20, intensity * 0.18)})`);
                gradient.addColorStop(0.70, `rgba(0,0,0,${Math.min(0.07, intensity * 0.06)})`);
                gradient.addColorStop(0.88, `rgba(0,0,0,${Math.min(0.016, intensity * 0.013)})`);
                gradient.addColorStop(1,    'rgba(0,0,0,0)');

                context.fillStyle = gradient;
                context.beginPath();
                context.arc(point.x, point.y, radius, 0, Math.PI * 2);
                context.fill();

                if (options.enablePeakSupport !== false) {
                    const peakGradient = peakContext.createRadialGradient(point.x, point.y, 0, point.x, point.y, peakRadius);
                    peakGradient.addColorStop(0, `rgba(0,0,0,${Math.min(0.68, intensity * 0.62)})`);
                    peakGradient.addColorStop(0.34, `rgba(0,0,0,${Math.min(0.34, intensity * 0.30)})`);
                    peakGradient.addColorStop(0.72, `rgba(0,0,0,${Math.min(0.08, intensity * 0.07)})`);
                    peakGradient.addColorStop(0.90, `rgba(0,0,0,${Math.min(0.018, intensity * 0.016)})`);
                    peakGradient.addColorStop(1, 'rgba(0,0,0,0)');

                    peakContext.fillStyle = peakGradient;
                    peakContext.beginPath();
                    peakContext.arc(point.x, point.y, peakRadius, 0, Math.PI * 2);
                    peakContext.fill();

                    const coreGradient = pointCoreContext.createRadialGradient(point.x, point.y, 0, point.x, point.y, pointCoreRadius);
                    coreGradient.addColorStop(0, `rgba(0,0,0,${Math.min(0.74, Math.max(0.58, intensity * 0.70))})`);
                    coreGradient.addColorStop(0.28, `rgba(0,0,0,${Math.min(0.42, Math.max(0.30, intensity * 0.38))})`);
                    coreGradient.addColorStop(0.62, `rgba(0,0,0,${Math.min(0.11, Math.max(0.07, intensity * 0.10))})`);
                    coreGradient.addColorStop(0.88, `rgba(0,0,0,${Math.min(0.025, Math.max(0.012, intensity * 0.020))})`);
                    coreGradient.addColorStop(1, 'rgba(0,0,0,0)');

                    pointCoreContext.fillStyle = coreGradient;
                    pointCoreContext.beginPath();
                    pointCoreContext.arc(point.x, point.y, pointCoreRadius, 0, Math.PI * 2);
                    pointCoreContext.fill();
                }
            });

            await yieldToEventLoop();
            const data = smoothedRasterData(canvas, smoothingPixels);
            await yieldToEventLoop();
            const peakData = options.enablePeakSupport === false
                ? null
                : smoothedRasterData(peakCanvas, peakSmoothingPixels);
            await yieldToEventLoop();
            const pointCoreData = options.enablePeakSupport === false
                ? null
                : smoothedRasterData(pointCoreCanvas, pointCoreSmoothingPixels);
            let strongestDensity = 0;
            for (let index = 3; index < data.length; index += 4) {
                strongestDensity = Math.max(strongestDensity, data[index]);
            }
            let strongestPeakDensity = 0;
            if (peakData) {
                for (let index = 3; index < peakData.length; index += 4) {
                    strongestPeakDensity = Math.max(strongestPeakDensity, peakData[index]);
                }
            }
            let strongestPointCoreDensity = 0;
            if (pointCoreData) {
                for (let index = 3; index < pointCoreData.length; index += 4) {
                    strongestPointCoreDensity = Math.max(strongestPointCoreDensity, pointCoreData[index]);
                }
            }

            groupImages.push({
                label: group.label,
                color: hexToRgb(group.color),
                ramp: gradientStopsFromStops(group.stops),
                pointCount: group.points.length,
                data,
                peakData,
                pointCoreData,
                strongestDensity,
                strongestPeakDensity,
                strongestPointCoreDensity,
            });
            await yieldToEventLoop();
        }

        const outputCanvas = document.createElement('canvas');
        outputCanvas.width = width;
        outputCanvas.height = height;
        const outputContext = outputCanvas.getContext('2d');
        const outputImage = outputContext.createImageData(width, height);
        const contourDensityGrid = new Float32Array(width * height);
        let strongestDensity = 0;
        groupImages.forEach((group) => {
            for (let index = 3; index < group.data.length; index += 4) {
                strongestDensity = Math.max(strongestDensity, group.data[index]);
            }
        });
        const maxGroupPointCount = Math.max(1, ...groupImages.map((group) => group.pointCount || 0));
        const colorScaleMax = options.adaptiveScale
            ? Math.max(42, Math.min(options.colorScaleMax || 255, strongestDensity * 0.82 || 42))
            : Math.max(72, Math.min(options.colorScaleMax || 255, strongestDensity * 1.05 || 72));
        const minVisibleDensity = options.minVisibleDensity ?? 0.22;
        const boundaryMask = getRasterBoundaryMask(bounds, width, height, options.clipBoundary);
        const rasterPixelInsideBoundary = (x, y) => {
            if (!boundaryMask) {
                return true;
            }

            return boundaryMask[(y * width) + x] === 1;
        };

        const __pixelSliceBudget = makeSliceBudget(10);
        for (let index = 0; index < outputImage.data.length; index += 4) {
            const pixel = index / 4;
            const x = pixel % width;
            const y = Math.floor(pixel / width);

            // Yield to the event loop every ~10ms so the per-pixel colorization
            // never becomes one multi-second main-thread task.
            if ((pixel & 8191) === 0 && __pixelSliceBudget()) {
                await yieldToEventLoop();
            }

            if (options.independentSurfaces === true) {
                let contourDensity = 0;
                const contributions = [];
                const anchoredContributions = [];
                let strongestDensityAtPixel = 0;
                let totalDensityAtPixel = 0;

                groupImages.forEach((group) => {
                    const alpha = group.data[index + 3];
                    const groupScaleMax = Math.max(12, group.strongestDensity * (options.perClusterScaleFactor ?? 0.88) || 12);
                    const density = clampUnit(alpha / groupScaleMax);
                    let peakDensity = 0;
                    if (group.peakData) {
                        const peakAlpha = group.peakData[index + 3];
                        const peakScaleMax = Math.max(10, group.strongestPeakDensity * (options.perClusterPeakScaleFactor ?? 0.84) || 10);
                        peakDensity = clampUnit(peakAlpha / peakScaleMax);
                    }
                    let pointCoreDensity = 0;
                    if (group.pointCoreData) {
                        const pointCoreAlpha = group.pointCoreData[index + 3];
                        const pointCoreScaleMax = Math.max(10, group.strongestPointCoreDensity * (options.perClusterCoreScaleFactor ?? 0.78) || 10);
                        pointCoreDensity = clampUnit(pointCoreAlpha / pointCoreScaleMax);
                    }

                    const pointDensity = Math.max(peakDensity, pointCoreDensity);
                    const localDensity = clampUnit(Math.max(
                        density * (options.broadSupportBoost ?? 0.62),
                        peakDensity * (options.peakSupportBoost ?? 1.28),
                        pointCoreDensity * (options.pointCoreBoost ?? 1.46)
                    ));
                    if (localDensity < minVisibleDensity && pointDensity < (options.peakMinVisibleDensity ?? 0.025)) {
                        return;
                    }

                    const colorDensity = clampUnit(Math.max(
                        density * (options.broadColorBoost ?? 0.48),
                        peakDensity * (options.peakColorBoost ?? 1.10),
                        pointCoreDensity * (options.pointCoreColorBoost ?? 1.18)
                    ));
                    if (colorDensity < (options.minColorDensity ?? 0.018)) {
                        return;
                    }

                    const score = clampUnit(
                        (density * (options.broadBlendWeight ?? 0.58)) +
                        (peakDensity * (options.peakBlendWeight ?? 1.20)) +
                        (pointCoreDensity * (options.pointCoreBlendWeight ?? 1.38))
                    );

                    if (pointCoreDensity >= (options.pointAnchorDensity ?? 0.028) || peakDensity >= (options.localGroupedPeakDensity ?? 0.035)) {
                        anchoredContributions.push({
                            group,
                            score: Math.max(
                                pointCoreDensity * (options.pointAnchorPriorityScale ?? 2.60),
                                peakDensity * (options.localGroupedPriorityScale ?? 1.80)
                            ),
                            colorDensity,
                            localDensity: clampUnit(Math.max(
                                localDensity,
                                pointCoreDensity * (options.pointCoreBoost ?? 1.46),
                                peakDensity * (options.peakSupportBoost ?? 1.28)
                            )),
                        });
                    }

                    strongestDensityAtPixel = Math.max(strongestDensityAtPixel, localDensity);
                    totalDensityAtPixel += localDensity;
                    contourDensity = Math.max(contourDensity, localDensity);
                    contributions.push({ group, score, colorDensity, localDensity });
                });

                if (!contributions.length) {
                    continue;
                }

                if (!rasterPixelInsideBoundary(x, y)) {
                    continue;
                }

                const dominantContribution = anchoredContributions.length
                    ? anchoredContributions.reduce((best, item) => item.score > best.score ? item : best, anchoredContributions[0])
                    : contributions.reduce((best, item) => item.score > best.score ? item : best, contributions[0]);
                const [red, green, blue] = colorForGradientValue(
                    Math.pow(dominantContribution.colorDensity, options.dominancePower ?? 0.74),
                    dominantContribution.group.ramp
                );
                const alphaDensity = clampUnit(Math.max(
                    dominantContribution.localDensity,
                    strongestDensityAtPixel,
                    totalDensityAtPixel * (options.totalDensityAlphaWeight ?? 0.24)
                ));
                const sourceAlpha = clampUnit(
                    (options.outputAlphaBase ?? 210) *
                    Math.pow(alphaDensity, options.outputAlphaPower ?? 0.88) /
                    255
                ) * (options.layerAlphaScale ?? 0.72);

                contourDensityGrid[pixel] = contourDensity;
                outputImage.data[index] = red;
                outputImage.data[index + 1] = green;
                outputImage.data[index + 2] = blue;
                outputImage.data[index + 3] = Math.round(Math.min(options.outputMaxAlpha ?? 190, sourceAlpha * 255));
                continue;
            }

            let totalDensity = 0;
            let winningDensity = 0;
            let winningAlphaDensity = 0;
            let winningBroadDensity = 0;
            let winningPeakDensity = 0;
            let winningCoreDensity = 0;
            let winningGroup = null;
            const colorContributions = [];

            groupImages.forEach((group) => {
                const alpha = group.data[index + 3];
                const groupScaleMax = Math.max(42, Math.min(colorScaleMax, group.strongestDensity * 0.88 || 42));
                const density = clampUnit(alpha / groupScaleMax);
                let peakDensity = 0;
                if (group.peakData) {
                    const peakAlpha = group.peakData[index + 3];
                    const peakScaleMax = Math.max(34, Math.min(colorScaleMax, group.strongestPeakDensity * 0.84 || 34));
                    peakDensity = clampUnit(peakAlpha / peakScaleMax);
                }
                let pointCoreDensity = 0;
                if (group.pointCoreData) {
                    const pointCoreAlpha = group.pointCoreData[index + 3];
                    const pointCoreScaleMax = Math.max(34, Math.min(colorScaleMax, group.strongestPointCoreDensity * 0.78 || 34));
                    pointCoreDensity = clampUnit(pointCoreAlpha / pointCoreScaleMax);
                }
                // Combine broad, peak, and point-core kernels instead of using a
                // hard max. This lets a senior from a minority cluster create a
                // smooth typhoon-style head inside a dominant cluster area.
                const localScore = clampUnit(
                    (density * (options.broadSupportBoost ?? 0.82)) +
                    (peakDensity * (options.peakSupportBoost ?? 2.8)) +
                    (pointCoreDensity * (options.pointCoreBoost ?? 3.8))
                );
                const groupColorDensity = clampUnit(Math.max(
                    density * (options.broadColorBoost ?? 0.42),
                    peakDensity * (options.peakColorBoost ?? 1.16),
                    pointCoreDensity * (options.pointCoreColorBoost ?? 0.88)
                ));
                if (options.softColorMix !== false && groupColorDensity > 0.002 && localScore > 0.002) {
                    colorContributions.push({
                        group,
                        localScore,
                        colorDensity: groupColorDensity,
                    });
                }
                totalDensity += density;
                if (localScore > winningDensity) {
                    winningDensity = localScore;
                    winningAlphaDensity = Math.max(density, peakDensity * 0.9, pointCoreDensity * 0.96);
                    winningGroup = group;
                    // Track unbooted densities separately for color and contour use.
                    winningBroadDensity = density;
                    winningPeakDensity = peakDensity;
                    winningCoreDensity = pointCoreDensity;
                }
            });

            const alphaIntensity = clampUnit(Math.max(winningAlphaDensity, totalDensity * 0.58));
            const peakVisible = winningAlphaDensity >= (options.peakMinVisibleDensity ?? 0.02);
            if (!winningGroup || (winningDensity < minVisibleDensity && !peakVisible) || alphaIntensity < (peakVisible ? options.peakMinVisibleDensity ?? 0.02 : minVisibleDensity)) {
                continue;
            }

            if (!rasterPixelInsideBoundary(x, y)) {
                continue;
            }

            // Contour scalar field: medium-kernel peak density creates local maxima
            // at each cluster's concentration centers (including minority patches),
            // while a small broad contribution provides the regional backdrop.
            // After smoothing this produces topographic rings for both dominant
            // zones and isolated minority patches inside other clusters.
            contourDensityGrid[pixel] = clampUnit(Math.max(winningBroadDensity * 0.3, winningPeakDensity));

            // Color uses unbooted broad+peak density so the gradient arc spans
            // yellow → orange → red (or green → cyan → blue) from edge to center
            // even for minority patches whose localScore was heavily boosted to win.
            // Use the broad KDE mostly for the pale outside band. Strong color
            // should come from the senior's local peak/core so each point has a
            // clean light -> medium -> dark cluster-colored typhoon transition.
            const colorDensity = clampUnit(Math.max(
                winningBroadDensity * (options.broadColorBoost ?? 0.42),
                winningPeakDensity * (options.peakColorBoost ?? 1.16),
                winningCoreDensity * (options.pointCoreColorBoost ?? 0.88)
            ));
            let [red, green, blue] = colorForGradientValue(
                Math.pow(colorDensity, options.dominancePower ?? 0.76),
                winningGroup.ramp
            );
            if (options.softColorMix !== false && colorContributions.length > 1) {
                let totalWeight = 0;
                let mixRed = 0;
                let mixGreen = 0;
                let mixBlue = 0;
                colorContributions.forEach((item) => {
                    const isWinner = item.group === winningGroup;
                    const weight = Math.pow(
                        item.localScore * (isWinner ? (options.winningColorBoost ?? 1.45) : 1),
                        options.colorMixPower ?? 2.35
                    );
                    const [itemRed, itemGreen, itemBlue] = colorForGradientValue(
                        Math.pow(item.colorDensity, options.dominancePower ?? 0.76),
                        item.group.ramp
                    );
                    totalWeight += weight;
                    mixRed += itemRed * weight;
                    mixGreen += itemGreen * weight;
                    mixBlue += itemBlue * weight;
                });
                if (totalWeight > 0) {
                    red = Math.round(mixRed / totalWeight);
                    green = Math.round(mixGreen / totalWeight);
                    blue = Math.round(mixBlue / totalWeight);
                }
            }

            // Geographic raster: per-cluster KDE surfaces compete internally,
            // then the locally strongest group writes its own fixed color.
            // This avoids muddy mixed colors when major and minor groups overlap.
            outputImage.data[index] = red;
            outputImage.data[index + 1] = green;
            outputImage.data[index + 2] = blue;
            outputImage.data[index + 3] = Math.round(Math.min(
                options.outputMaxAlpha ?? 150,
                (options.outputAlphaBase ?? 188) * Math.pow(alphaIntensity, options.outputAlphaPower ?? 1.08)
            ));
        }

        await yieldToEventLoop();
        if (options.independentSurfaces !== true) {
            smoothRasterColorEdges(
                outputImage,
                width,
                height,
                options.edgeSmoothPasses ?? 2,
                options.edgeSmoothStrength ?? 0.34,
                options.edgeSmoothRadius ?? 1
            );
        }
        outputContext.putImageData(outputImage, 0, 0);

        await yieldToEventLoop();

        // Run marching squares directly on the full-resolution contour density
        // grid (step=4 skips every 4px for speed while keeping contour shape).
        // Drawing at native canvas resolution avoids the scale-up blurring that
        // would make lines invisible when blitting from a small intermediate canvas.
        const contourSourceGrid = smoothScalarGrid(
            contourDensityGrid,
            width,
            height,
            options.contourSmoothPasses ?? 7
        );
        drawKdeContours(outputContext, contourSourceGrid, width, height, {
            step: options.contourStep ?? 5,
            levels: options.contourLevels ?? [0.14, 0.28, 0.44, 0.62, 0.80],
            lineWidth: options.contourLineWidth ?? 0.95,
        });

        await yieldToEventLoop();
        const blurredCanvas = document.createElement('canvas');
        blurredCanvas.width = width;
        blurredCanvas.height = height;
        const blurCtx = blurredCanvas.getContext('2d');
        blurCtx.filter = 'blur(5px)';
        blurCtx.drawImage(outputCanvas, 0, 0);

        return createSmoothHeatmapImageOverlay(blurredCanvas.toDataURL('image/png'), bounds, {
            pane: 'gis-heat-pane',
            opacity: 1,
            interactive: false,
        });
    }

    function heatmapGradient(mode) {
        if (mode === 'cluster-heatmap') {
            return CLUSTER_HEATMAP_GRADIENT;
        }

        return {
            0.15: '#10b981',
            0.45: '#facc15',
            0.72: '#fb923c',
            1.00: '#ef4444',
        };
    }

    function singleColorGradient(color) {
        return {
            0.12: color,
            0.45: color,
            0.78: color,
            1.00: color,
        };
    }

    function heatmapColorScaleMax(points, mode) {
        if (mode === 'cluster-heatmap') {
            return Math.max(210, Math.min(255, 190 + (Math.log2(points.length + 1) * 8)));
        }

        return 255;
    }

    function heatmapRenderOptions(mode) {
        if (mode === 'cluster-heatmap') {
            return {
                outputMaxAlpha: 132,
                outputAlphaBase: 168,
                outputAlphaPower: 1.08,
                minVisibleDensity: 0.22,
            };
        }

        // Power > 1 gives a concave alpha curve: weak kernel edges are nearly
        // transparent while dense cores stay vivid — the typhoon-style falloff.
        // minVisibleDensity cuts off residual kernel tails that would color
        // empty areas between senior clusters.
        return {
            outputMaxAlpha: 185,
            outputAlphaBase: 210,
            outputAlphaPower: 1.65,
            minVisibleDensity: 0.06,
        };
    }

    function buildHeatmapLayer(map, features, mode, options = {}) {
        const points = heatmapPoints(features, mode);

        const gradient = options.gradient ?? heatmapGradient(mode);
        const pixelOptions = heatmapPixelOptions(map, features, mode, options);
        const maxIntensity = heatmapNormalization(points, pixelOptions.radius, mode);
        const colorScaleMax = options.colorScaleMax ?? heatmapColorScaleMax(points, mode);
        const renderOptions = heatmapRenderOptions(mode);

        // KDE-style note: this is a browser-rendered, privacy-safe density surface.
        // The custom canvas layer draws smooth radial kernels around existing senior GIS points;
        // the point radius is derived from local GeoJSON bounds and senior spacing,
        // not from a QGIS-generated raster or external GIS preprocessing.
        return {
            points,
            radiusMeters: pixelOptions.radius_meters,
            colorScaleMax,
            layer: createCanvasKdeLayer(points, {
                pane: 'gis-heat-pane',
                mode,
                radius: pixelOptions.radius,
                blur: pixelOptions.blur,
                radius_meters: pixelOptions.radius_meters,
                maxZoom: map?.getZoom?.() ?? 17,
                minOpacity: 0.30,
                max: maxIntensity,
                colorScaleMax,
                outputMaxAlpha: options.outputMaxAlpha ?? renderOptions.outputMaxAlpha,
                outputAlphaBase: options.outputAlphaBase ?? renderOptions.outputAlphaBase,
                outputAlphaPower: options.outputAlphaPower ?? renderOptions.outputAlphaPower,
                minVisibleDensity: options.minVisibleDensity ?? renderOptions.minVisibleDensity,
                clipBoundary: options.clipBoundary ?? primaryBoundaryGeoJson(),
                gradient,
            }),
        };
    }

    function normalizedRiskScore(value) {
        const score = numericValue(value);
        if (score === null) {
            return null;
        }

        return clampUnit(score > 1 ? score / 100 : score);
    }

    function normalizedClusterWeight(props) {
        const number = clusterNumber(props.cluster_label || props.cluster || props.health_group || props.group || props.health_group_cluster, { properties: props });
        return number === null ? 0.4 : 1;
    }

    function riskScore(level) {
        switch ((level || '').toUpperCase()) {
            case 'HIGH':
                return 3;
            case 'MODERATE':
                return 2;
            case 'LOW':
                return 1;
            default:
                return null;
        }
    }

    function averageRiskLabel(score) {
        if (score === null || !Number.isFinite(score)) return 'N/A';
        if (score >= 2.5) return 'High';
        if (score >= 1.5) return 'Moderate';
        return 'Low';
    }

    function barangayStats(features) {
        const stats = new Map();

        features.forEach((feature) => {
            const barangay = feature.properties?.barangay || 'Unknown';
            const key = normalizeBarangayName(barangay);
            const count = seniorCount(feature);
            const current = stats.get(key) ?? {
                name: barangay,
                count: 0,
                verified: 0,
                riskTotal: 0,
                riskCount: 0,
                proximityTotal: 0,
                proximityCount: 0,
            };
            const risk = riskScore(feature.properties?.risk_level);
            const proximity = numericValue(feature.properties?.gis_proximity_score);

            current.count += count;
            if (isExactLocationFeature(feature)) current.verified++;
            if (risk !== null) {
                current.riskTotal += risk * Math.max(count, 1);
                current.riskCount += Math.max(count, 1);
            }
            if (proximity !== null) {
                current.proximityTotal += proximity * Math.max(count, 1);
                current.proximityCount += Math.max(count, 1);
            }

            stats.set(key, current);
        });

        return stats;
    }

    function densityColor(count, maxCount) {
        if (!maxCount || count <= 0) return 'rgba(219,234,254,0)';
        const ratio = Math.min(1, count / maxCount);
        // Smooth typhoon ramp: cyan → green → yellow → orange → red
        const stops = [
            { at: 0.00, r: 56,  g: 189, b: 248 },
            { at: 0.20, r: 74,  g: 222, b: 128 },
            { at: 0.45, r: 250, g: 204, b: 21  },
            { at: 0.70, r: 251, g: 146, b: 60  },
            { at: 1.00, r: 239, g: 68,  b: 68  },
        ];
        let lo = stops[0];
        let hi = stops[stops.length - 1];
        for (let i = 1; i < stops.length; i++) {
            if (ratio <= stops[i].at) { lo = stops[i - 1]; hi = stops[i]; break; }
        }
        const span = hi.at === lo.at ? 1 : hi.at - lo.at;
        const t = Math.max(0, Math.min(1, (ratio - lo.at) / span));
        return `rgb(${Math.round(lo.r + t * (hi.r - lo.r))},${Math.round(lo.g + t * (hi.g - lo.g))},${Math.round(lo.b + t * (hi.b - lo.b))})`;
    }

    function facilityLatLng(feature) {
        const coords = feature?.geometry?.coordinates;
        if (!Array.isArray(coords) || coords.length < 2) return null;

        const lng = Number(coords[0]);
        const lat = Number(coords[1]);

        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null;

        return window.L.latLng(lat, lng);
    }

    function formatServiceDistance(meters) {
        if (!Number.isFinite(meters)) return null;
        if (meters >= 1000) return `${(meters / 1000).toFixed(1)} km`;

        return `${Math.round(meters)} m`;
    }

    function formatRouteDuration(seconds) {
        if (!Number.isFinite(seconds)) return null;
        const minutes = Math.max(1, Math.round(seconds / 60));

        return `${minutes} min`;
    }

    function serviceLabel(feature, distance = null, options = {}) {
        const type = feature?.properties?.type || 'Service';
        const label = feature?.properties?.name || type;
        const barangay = feature?.properties?.barangay ? `, ${feature.properties.barangay}` : '';
        const durationText = options.duration !== null && options.duration !== undefined
            ? `, ~${formatRouteDuration(options.duration)}`
            : '';
        const providerText = options.provider
            ? ` via ${String(options.provider).toUpperCase()}`
            : '';
        const distanceLabel = options.route ? ' route' : '';
        const distanceText = distance !== null
            ? ` - ${formatServiceDistance(distance)}${distanceLabel}${durationText}${providerText}`
            : '';

        return `${label} (${type}${barangay})${distanceText}`;
    }

    function facilitySearchText(feature) {
        return [
            feature?.properties?.name,
            feature?.properties?.type,
            feature?.properties?.barangay,
        ].filter(Boolean).join(' ').toLowerCase();
    }

    function seniorFacilityPriority(feature) {
        const text = facilitySearchText(feature);
        const index = SENIOR_RELEVANT_FACILITY_PRIORITY.findIndex((keywords) =>
            keywords.some((keyword) => text.includes(keyword))
        );

        return index === -1 ? SENIOR_RELEVANT_FACILITY_PRIORITY.length : index;
    }

    function isSeniorRelevantFacility(feature) {
        return seniorFacilityPriority(feature) < SENIOR_RELEVANT_FACILITY_PRIORITY.length;
    }

    function routeCandidateFacilities(features) {
        const relevant = features.filter((item) => isSeniorRelevantFacility(item.facility));
        const source = relevant.length ? relevant : features;

        // `source` arrives sorted nearest-first. Keep the nearest facility of each
        // type, ordered by distance — the same per-type set the profile's Location
        // panel lists, so the two surfaces stay consistent.
        const nearestByType = new Map();
        for (const item of source) {
            const type = facilityType(item.facility);
            if (!nearestByType.has(type)) {
                nearestByType.set(type, item);
            }
        }

        return [...nearestByType.values()]
            .sort((a, b) => a.straightDistance - b.straightDistance)
            .slice(0, ROUTE_SERVICE_DISPLAY_LIMIT);
    }

    function routeCandidatesForFeature(feature) {
        const seniorPoint = featureLatLng(feature);
        const facilities = latestFacilityGeoJson?.features || [];

        if (!seniorPoint || !facilities.length) {
            return [];
        }

        return routeCandidateFacilities(facilities
            .map((facility) => {
                const facilityPoint = facilityLatLng(facility);
                if (!facilityPoint) return null;

                return {
                    facility,
                    facilityPoint,
                    straightDistance: seniorPoint.distanceTo(facilityPoint),
                };
            })
            .filter(Boolean)
            .sort((a, b) => a.straightDistance - b.straightDistance));
    }

    function serviceBaseLabel(feature) {
        const type = feature?.properties?.type || 'Service';
        const label = feature?.properties?.name || type;
        const barangay = feature?.properties?.barangay ? `, ${feature.properties.barangay}` : '';

        return `${label} (${type}${barangay})`;
    }

    function serviceListHtml(services) {
        const itemsSource = Array.isArray(services) ? services : null;
        const text = itemsSource ? '' : String(services || '').trim();

        if (!itemsSource && (!text || text === 'Calculating road-network distance...' || text === 'Road route unavailable for mapped services')) {
            return escapeHtml(text || 'No mapped services available');
        }

        const serviceItems = itemsSource ?? text
            .split(/\s*,\s+(?=[A-Z0-9][\s\S]*?\)\s+-\s+\d)/)
            .filter(Boolean);

        const items = serviceItems
            .slice(0, ROUTE_SERVICE_RESULT_LIMIT)
            .map((service) => `<li class="pl-1 leading-snug">${escapeHtml(service)}</li>`)
            .join('');

        return `<ul class="mt-1 ml-4 list-disc space-y-1">${items}</ul>`;
    }

    function routeLoadingListHtml(candidates) {
        if (!candidates.length) {
            return escapeHtml('No mapped senior services available');
        }

        const items = candidates
            .slice(0, ROUTE_SERVICE_DISPLAY_LIMIT)
            .map((candidate, index) => {
                const label = escapeHtml(serviceBaseLabel(candidate.facility));
                // The nearest few show "calculating" while their route loads; the
                // rest show straight-line immediately. Every row keeps a
                // data-gis-route-item slot so updateRoadNetworkServices can upgrade
                // it to a road route (cache-first, so usually free) once resolved.
                if (index < ROUTE_SERVICE_CANDIDATE_LIMIT) {
                    return `<li class="pl-1 leading-snug" data-gis-route-item="${index}">${label} - calculating route...</li>`;
                }
                const straight = escapeHtml(`${formatServiceDistance(candidate.straightDistance)} straight-line`);
                return `<li class="pl-1 leading-snug" data-gis-route-item="${index}">${label} - ${straight}</li>`;
            })
            .join('');

        return `<ul class="mt-1 ml-4 list-disc space-y-1">${items}</ul>`;
    }

    function servicesForBarangay(name) {
        const facilities = latestFacilityGeoJson?.features || [];
        const normalized = normalizeBarangayName(name);
        const services = facilities
            .filter((feature) => normalizeBarangayName(feature.properties?.barangay) === normalized)
            .map((feature) => serviceLabel(feature));

        return services.length ? services.slice(0, 4).join(', ') : 'No mapped services in this barangay';
    }

    function nearestServicesForFeature(feature) {
        const seniorPoint = featureLatLng(feature);
        const facilities = latestFacilityGeoJson?.features || [];

        if (!seniorPoint || !facilities.length) {
            return servicesForBarangay(feature?.properties?.barangay);
        }

        const ranked = facilities
            .map((facility) => {
                const facilityPoint = facilityLatLng(facility);
                if (!facilityPoint) return null;

                return {
                    facility,
                    distance: seniorPoint.distanceTo(facilityPoint),
                };
            })
            .filter(Boolean)
            .sort((a, b) => a.distance - b.distance);

        if (!ranked.length) {
            return servicesForBarangay(feature?.properties?.barangay);
        }

        return ranked
            .slice(0, 4)
            .map(({ facility, distance }) => serviceLabel(facility, distance))
            .join(', ');
    }

    function routeCacheKey(origin, destination) {
        const round = (value) => Number(value).toFixed(4);
        return `${round(origin.lng)},${round(origin.lat)};${round(destination.lng)},${round(destination.lat)}`;
    }

    function routeCoordinate(point) {
        return {
            lat: Number(point.lat),
            lng: Number(point.lng),
        };
    }

    async function roadRouteDistance(origin, destination, meta = {}) {
        // Route distance/time uses the GIS API proxy for OpenRouteService first,
        // then falls back to public OSRM if the configured key/service is unavailable.
        const routeOrigin = routeCoordinate(origin);
        const routeDestination = routeCoordinate(destination);
        const cacheKey = routeCacheKey(routeOrigin, routeDestination);
        if (routeDistanceCache.has(cacheKey)) {
            return routeDistanceCache.get(cacheKey);
        }

        const request = openRouteServiceDistance(routeOrigin, routeDestination, meta)
            .catch(() => osrmRouteDistance(routeOrigin, routeDestination));

        routeDistanceCache.set(cacheKey, request);

        return request;
    }

    async function openRouteServiceDistance(origin, destination, meta = {}) {
        if (!latestRouteDistanceUrl) {
            throw new Error('OpenRouteService proxy is unavailable');
        }

        const params = new URLSearchParams({
            origin_lat: origin.lat,
            origin_lng: origin.lng,
            destination_lat: destination.lat,
            destination_lng: destination.lng,
        });

        if (meta.seniorId) params.set('senior_id', meta.seniorId);
        if (meta.facilityId) params.set('facility_id', meta.facilityId);

        const response = await fetch(`${latestRouteDistanceUrl}?${params.toString()}`, {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            throw new Error(`OpenRouteService proxy returned ${response.status}`);
        }

        const payload = await response.json();
        if (!Number.isFinite(Number(payload.distance))) {
            throw new Error('OpenRouteService proxy returned no usable route');
        }

        return {
            distance: Number(payload.distance),
            duration: Number.isFinite(Number(payload.duration)) ? Number(payload.duration) : null,
            provider: payload.provider || 'openrouteservice',
        };
    }

    async function osrmRouteDistance(origin, destination) {
        const response = await fetch(`${ROAD_ROUTE_SERVICE_URL}/${origin.lng},${origin.lat};${destination.lng},${destination.lat}?overview=false&alternatives=false&steps=false`, {
            headers: {
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            throw new Error(`OSRM route service returned ${response.status}`);
        }

        const payload = await response.json();
        const route = payload?.routes?.[0];
        if (!route || !Number.isFinite(route.distance)) {
            throw new Error('OSRM route service returned no usable route');
        }

        return {
            distance: route.distance,
            duration: Number.isFinite(route.duration) ? route.duration : null,
            provider: 'osrm',
        };
    }

    async function roadNetworkServicesForFeature(feature) {
        const seniorPoint = featureLatLng(feature);

        if (!seniorPoint || !(latestFacilityGeoJson?.features || []).length) {
            return nearestServicesForFeature(feature);
        }

        const candidates = routeCandidatesForFeature(feature);

        if (!candidates.length) {
            return nearestServicesForFeature(feature);
        }

        const routed = (await Promise.all(candidates.map(async (candidate) => {
            try {
                const route = await roadRouteDistance(seniorPoint, candidate.facilityPoint, {
                    seniorId: feature?.properties?.senior_id,
                    facilityId: candidate.facility?.properties?.facility_id,
                });

                return {
                    ...candidate,
                    routeDistance: route.distance,
                    routeDuration: route.duration,
                    routeProvider: route.provider,
                };
            } catch (error) {
                return null;
            }
        })))
            .filter(Boolean)
            .sort((a, b) => a.routeDistance - b.routeDistance);

        if (!routed.length) {
            return 'Road route unavailable for mapped services';
        }

        return routed
            .slice(0, ROUTE_SERVICE_RESULT_LIMIT)
            .map((item) => serviceLabel(item.facility, item.routeDistance, {
                route: true,
                duration: item.routeDuration,
                provider: item.routeProvider,
            }));
    }

    function barangayDensityTooltip(name, stat) {
        const avgProximity = stat?.proximityCount ? `${(stat.proximityTotal / stat.proximityCount).toFixed(1)}%` : 'No score available';
        const status = stat?.proximityCount ? accessibilityStatus(stat.proximityTotal / stat.proximityCount) : 'No accessibility score available';
        const services = servicesForBarangay(name);

        return `
            <div class="space-y-1 text-[12px] leading-snug">
                <div><strong>Barangay:</strong> ${name}</div>
                <div><strong>Total Seniors:</strong> ${stat?.count ?? 0}</div>
                <div><strong>Accessibility Status:</strong> ${avgProximity} (${status})</div>
                <div><strong>Nearest services:</strong> ${services}</div>
            </div>
        `;
    }

    function buildBarangayDensityLayer(features) {
        if (!hasBoundaryFeatures(latestBarangayBoundaryGeoJson)) {
            return window.L.layerGroup();
        }

        const stats = barangayStats(features);
        const maxCount = Math.max(1, ...[...stats.values()].map((stat) => stat.count));
        const selected = selectedBarangay();
        const selectedNormalized = normalizeBarangayName(selected);

        return window.L.geoJSON(latestBarangayBoundaryGeoJson, {
            pane: 'gis-risk-pane',
            filter(feature) {
                return selected === 'all' ||
                    normalizeBarangayName(barangayNameFromBoundary(feature)) === selectedNormalized;
            },
            style(feature) {
                const name = barangayNameFromBoundary(feature);
                const stat = stats.get(normalizeBarangayName(name));
                const color = densityColor(stat?.count ?? 0, maxCount);

                // Scale fill opacity with density so zero-senior barangays are
                // fully transparent and high-density ones are most vivid.
                const densityRatio = maxCount > 0 ? Math.min(1, (stat?.count ?? 0) / maxCount) : 0;
                return {
                    color: selected === 'all' ? '#475569' : '#0f172a',
                    weight: selected === 'all' ? 1.4 : 2.8,
                    opacity: 0.9,
                    fillColor: color,
                    fillOpacity: selected === 'all'
                        ? Math.max(0, Math.min(0.65, densityRatio * 0.65))
                        : Math.max(0.12, Math.min(0.72, densityRatio * 0.60 + 0.18)),
                };
            },
            onEachFeature(feature, layer) {
                const name = barangayNameFromBoundary(feature);
                layer.bindTooltip(name, {
                    sticky: true,
                    direction: 'center',
                    opacity: 0.9,
                    className: 'gis-boundary-tooltip',
                });
                layer.bindPopup(barangayDensityTooltip(name, stats.get(normalizeBarangayName(name))));
                layer.on('click', (event) => {
                    if (event?.originalEvent) {
                        window.L.DomEvent.stopPropagation(event.originalEvent);
                    }
                    layer.openPopup(event.latlng);
                });
            },
        });
    }

    function computeCenter(features, mode) {
        const points = features
            .map((feature) => {
                const latlng = featureLatLng(feature);
                if (!latlng) return null;
                const props = feature.properties || {};
                const weight = mode === 'accessibility-zones'
                    ? clusterWeight(props.cluster)
                    : riskWeight(props.risk_level);

                return { latlng, weight };
            })
            .filter(Boolean);

        if (!points.length) return window.L.latLng(PAGSANJAN_CENTER[0], PAGSANJAN_CENTER[1]);

        const totals = points.reduce((acc, point) => {
            acc.lat += point.latlng.lat * point.weight;
            acc.lng += point.latlng.lng * point.weight;
            acc.weight += point.weight;
            return acc;
        }, { lat: 0, lng: 0, weight: 0 });

        return window.L.latLng(totals.lat / totals.weight, totals.lng / totals.weight);
    }

    function computeZoneRadii(centerPoint, features, mode) {
        const distances = features
            .map((feature) => {
                const latlng = featureLatLng(feature);
                if (!latlng) return null;

                const props = feature.properties || {};
                const tier = mode === 'accessibility-zones'
                    ? clusterTier(props.cluster)
                    : riskTier(props.risk_level);

                return {
                    distance: centerPoint.distanceTo(latlng),
                    tier,
                };
            })
            .filter(Boolean);

        if (!distances.length) {
            return { inner: 250, middle: 500, outer: 750 };
        }

        const maxDistance = Math.max(...distances.map((item) => item.distance), 300);
        const highTier = distances.filter((item) => item.tier >= 3).map((item) => item.distance);
        const midTier = distances.filter((item) => item.tier >= 2).map((item) => item.distance);

        const inner = Math.max(highTier.length ? Math.max(...highTier) + 60 : maxDistance * 0.3, 180);
        const middle = Math.max(midTier.length ? Math.max(...midTier) + 100 : maxDistance * 0.65, inner + 120);
        const outer = Math.max(maxDistance + 160, middle + 140);

        return { inner, middle, outer };
    }

    function buildZoneOverlay(map, features, mode) {
        const overlayGroup = window.L.layerGroup();
        const zoneCenter = computeCenter(features, mode);
        const radii = computeZoneRadii(zoneCenter, features, mode);
        const zoneColors = mode === 'accessibility-zones'
            ? {
                inner: '#10b981',
                middle: '#f59e0b',
                outer: '#fb7185',
            }
            : {
                inner: '#22c55e',
                middle: '#fb923c',
                outer: '#fb7185',
            };

        const circles = [
            { radius: radii.outer, color: zoneColors.outer, fillOpacity: 0.10, weight: 1.5 },
            { radius: radii.middle, color: zoneColors.middle, fillOpacity: 0.15, weight: 1.5 },
            { radius: radii.inner, color: zoneColors.inner, fillOpacity: 0.20, weight: 1.5 },
        ];

        circles.forEach((zone) => {
            window.L.circle(zoneCenter, {
                radius: zone.radius,
                color: zone.color,
                weight: zone.weight,
                fillColor: zone.color,
                fillOpacity: zone.fillOpacity,
                pane: 'gis-risk-pane',
            }).addTo(overlayGroup);
        });

        window.L.circleMarker(zoneCenter, {
            radius: 8,
            color: '#ffffff',
            weight: 2,
            fillColor: '#334155',
            fillOpacity: 0.95,
            pane: 'gis-risk-pane',
        }).bindPopup(
            mode === 'accessibility-zones'
                ? 'Cluster zone center for the active barangay-level points.'
                : 'Risk zone center for the active barangay-level points.'
        ).addTo(overlayGroup);

        const pointLayer = window.L.geoJSON({
            type: 'FeatureCollection',
            features,
        }, {
            pointToLayer(feature, latlng) {
                const color = mode === 'accessibility-zones'
                    ? clusterColor(feature.properties?.cluster)
                    : riskColor(feature.properties?.risk_level);

                return window.L.circleMarker(latlng, {
                    // Share the senior canvas (pointer-events:none) so zone dots
                    // stay click-through; clicks are dispatched via openSeniorPopupAt
                    // and facility markers underneath remain clickable.
                    renderer: getCanvasRenderer(map),
                    radius: 8,
                    fillColor: color,
                    color: '#ffffff',
                    weight: 2,
                    opacity: 1,
                    fillOpacity: 0.88,
                    pane: 'gis-senior-pane',
                });
            },
            onEachFeature(feature, layer) {
                // Privacy: popups only show anonymized, generalized GIS fields.
                attachSeniorPopup(layer, feature);
            },
        });

        return { overlayGroup, pointLayer };
    }

    function createFacilityIcon(color = DEFAULT_FACILITY_COLOR) {
        return window.L.divIcon({
            className: 'gis-facility-icon',
            html: `<span style="display:block;width:16px;height:16px;border-radius:4px;background:${color};border:2px solid #ffffff;box-shadow:0 4px 10px rgba(15,23,42,0.18);transform:rotate(45deg);"></span>`,
            iconSize: [16, 16],
            iconAnchor: [8, 8],
            popupAnchor: [0, -8],
        });
    }

    function clusterTone(markers) {
        const selected = selectedBarangay();
        if (selected !== 'all') {
            return barangayColor(selected);
        }

        const counts = new Map();
        markers.forEach((marker) => {
            const barangay = marker.options.gisBarangay;
            if (!barangay) return;

            const key = normalizeBarangayName(barangay);
            const current = counts.get(key) ?? { barangay, count: 0 };
            current.count++;
            counts.set(key, current);
        });

        const majority = [...counts.values()].sort((a, b) => b.count - a.count)[0];
        return majority ? barangayColor(majority.barangay) : '#f97316';
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function routeServicesElementId(feature) {
        const id = feature?.properties?.senior_id
            ?? feature?.properties?.osca_id
            ?? `${featureLatLng(feature)?.lat ?? 'x'}-${featureLatLng(feature)?.lng ?? 'y'}`;

        return `gis-route-services-${String(id).replace(/[^a-zA-Z0-9_-]/g, '-')}`;
    }

    function attachSeniorPopup(layer, feature) {
        layer._gisSeniorFeature = feature;
        layer.bindPopup(popupHtml(feature));
        layer.on('click', (event) => {
            if (event?.originalEvent) {
                window.L.DomEvent.stopPropagation(event.originalEvent);
            }
            layer.openPopup();
        });
        layer.on('popupopen', () => updateRoadNetworkServices(layer, feature));
    }

    function visibleSeniorLayerAtClick(map, event) {
        const seniorLayers = map?._gisLayerRegistry?.seniors;
        const clickPoint = event?.containerPoint
            ?? (event?.originalEvent ? map.mouseEventToContainerPoint(event.originalEvent) : null)
            ?? (event?.latlng ? map.latLngToContainerPoint(event.latlng) : null);

        if (!seniorLayers || !clickPoint) {
            return null;
        }

        let match = null;
        let bestDistance = Infinity;

        const inspectLayer = (layer) => {
            if (!layer) {
                return;
            }

            if (layer._gisSeniorFeature && map.hasLayer(layer) && typeof layer.getLatLng === 'function') {
                const markerPoint = map.latLngToContainerPoint(layer.getLatLng());
                const radius = Number(layer.getRadius?.() ?? 7);
                const hitRadius = Math.max(radius + 2, 9);
                const distance = clickPoint.distanceTo(markerPoint);

                if (distance <= hitRadius && distance < bestDistance) {
                    bestDistance = distance;
                    match = layer;
                }
            }

            if (typeof layer.eachLayer === 'function') {
                layer.eachLayer(inspectLayer);
            }
        };

        seniorLayers.eachLayer(inspectLayer);

        return match;
    }

    function openSeniorPopupAt(map, event) {
        const seniorLayer = visibleSeniorLayerAtClick(map, event);
        if (!seniorLayer) {
            return false;
        }

        seniorLayer.fire('click', {
            latlng: event.latlng,
            originalEvent: event.originalEvent,
        });

        return true;
    }

    async function updateRoadNetworkServices(layer, feature) {
        const popup = layer.getPopup?.();
        if (!popup) return;
        if (!accessibilityComputationEnabled()) return;

        const requestId = (layer._gisRouteRequestId || 0) + 1;
        layer._gisRouteRequestId = requestId;

        const elementId = routeServicesElementId(feature);
        const seniorPoint = featureLatLng(feature);
        const candidates = routeCandidatesForFeature(feature);
        const element = document.getElementById(elementId);
        if (element) {
            element.innerHTML = routeLoadingListHtml(candidates);
        }

        if (!seniorPoint || !candidates.length) {
            const fallbackServices = nearestServicesForFeature(feature);
            const currentElement = document.getElementById(elementId);
            if (currentElement) {
                currentElement.innerHTML = serviceListHtml(fallbackServices);
            }
            return;
        }

        // Resolve a road route for every displayed service, not just the nearest
        // few. The route-distance endpoint is cache-first and the cache is
        // precomputed for the nearest facility per type, so this is almost always
        // served from cache (near-zero live ORS calls) and matches the profile,
        // which now shows road distance for every facility it lists. Any row whose
        // route misses or fails degrades to the straight-line distance shown.
        const liveCandidates = candidates.slice(0, ROUTE_SERVICE_DISPLAY_LIMIT);

        await Promise.all(liveCandidates.map(async (candidate, index) => {
            let item = null;

            try {
                const route = await roadRouteDistance(seniorPoint, candidate.facilityPoint, {
                    seniorId: feature?.properties?.senior_id,
                    facilityId: candidate.facility?.properties?.facility_id,
                });
                item = {
                    ...candidate,
                    routeDistance: route.distance,
                    routeDuration: route.duration,
                    routeProvider: route.provider,
                };
            } catch (error) {
                item = null;
            }

            if (layer._gisRouteRequestId !== requestId || layer.isPopupOpen?.() === false) {
                return;
            }

            const currentElement = document.getElementById(elementId);
            const routeItem = currentElement?.querySelector(`[data-gis-route-item="${index}"]`);
            if (!routeItem) {
                return;
            }

            if (item) {
                routeItem.textContent = serviceLabel(item.facility, item.routeDistance, {
                    route: true,
                    duration: item.routeDuration,
                    provider: item.routeProvider,
                });
            } else {
                // Live route failed — fall back to the straight-line distance.
                routeItem.textContent = `${serviceBaseLabel(candidate.facility)} - ${formatServiceDistance(candidate.straightDistance)} straight-line`;
            }
        }));
    }

    function accessibilityComputationEnabled() {
        const mode = document.getElementById(MODE_ID)?.value ?? 'markers';
        return mode === 'markers' || mode === 'senior-distribution-accessibility-heatmap';
    }

    function popupHtml(featureOrProperties, routedServices = null) {
        const feature = featureOrProperties?.type === 'Feature'
            ? featureOrProperties
            : { type: 'Feature', properties: featureOrProperties || null };
        const p = feature.properties || {};
        const seniorName = escapeHtml(p.senior_name ?? 'Senior record');
        const oscaId = escapeHtml(p.osca_id ?? `#${p.senior_id ?? 'N/A'}`);
        const officialOscaId = escapeHtml(p.official_osca_id || 'Unassigned');
        const barangay = escapeHtml(p.barangay ?? 'N/A');
        const riskLevel = escapeHtml(p.risk_level ?? 'Unknown');
        const healthGroup = escapeHtml(clusterDisplayName(feature));
        const accessibility = p.gis_proximity_score !== null && p.gis_proximity_score !== undefined
            ? `${Number(p.gis_proximity_score).toFixed(1)}% (${escapeHtml(p.accessibility_status ?? accessibilityStatus(p.gis_proximity_score))})`
            : escapeHtml(p.accessibility_status ?? 'No accessibility score available');
        const showAccess = accessibilityComputationEnabled();
        const accessibilityRow = showAccess
            ? `<div><strong>Accessibility Status:</strong> ${accessibility}</div>`
            : '';
        const services = routedServices
            ? serviceListHtml(routedServices)
            : routeLoadingListHtml(routeCandidatesForFeature(feature));
        const servicesElementId = escapeHtml(routeServicesElementId(feature));
        const servicesBlock = showAccess
            ? `<div><strong>Nearby senior services:</strong><div id="${servicesElementId}">${services}</div></div>`
            : '';

        if (p.is_generalized_senior_point) {
            return `
                <div class="space-y-1 text-[12px] leading-snug">
                    <div><strong>Senior:</strong> ${seniorName}</div>
                    <div class="${p.official_osca_id ? 'font-semibold text-forest-800 dark:text-forest-300' : 'italic'}"><strong>OSCA ID:</strong> ${officialOscaId}</div>
                    <div class="text-[11px] opacity-75"><strong>System ID:</strong> ${oscaId}</div>
                    <div><strong>Barangay:</strong> ${barangay}</div>
                    <div><strong>Point Type:</strong> Generalized senior point</div>
                    <div><strong>Risk Indicator:</strong> ${riskLevel}</div>
                    <div><strong>Profile Group:</strong> ${healthGroup}</div>
                    ${accessibilityRow}
                    ${servicesBlock}
                </div>
            `;
        }

        return `
            <div class="space-y-1 text-[12px] leading-snug">
                <div><strong>Senior:</strong> ${seniorName}</div>
                <div class="${p.official_osca_id ? 'font-semibold text-forest-800 dark:text-forest-300' : 'italic'}"><strong>OSCA ID:</strong> ${officialOscaId}</div>
                <div class="text-[11px] opacity-75"><strong>System ID:</strong> ${oscaId}</div>
                <div><strong>Barangay:</strong> ${barangay}</div>
                <div><strong>Total Seniors:</strong> ${p.senior_count ?? p.total_seniors ?? 0}</div>
                <div><strong>Risk Indicator:</strong> ${riskLevel}</div>
                <div><strong>Profile Group:</strong> ${healthGroup}</div>
                ${accessibilityRow}
                ${servicesBlock}
            </div>
        `;
    }

    function facilityPopupHtml(properties) {
        const p = properties || {};
        const name = escapeHtml(p.name ?? 'N/A');
        const type = escapeHtml(p.type ?? 'N/A');
        const barangay = escapeHtml(p.barangay ?? 'N/A');
        const source = escapeHtml(p.source ?? 'N/A');

        return `
            <div class="space-y-1 text-[12px] leading-snug">
                <div><strong>Facility:</strong> ${name}</div>
                <div><strong>Type:</strong> ${type}</div>
                <div><strong>Barangay:</strong> ${barangay}</div>
                <div><strong>Source:</strong> ${source}</div>
            </div>
        `;
    }

    function updateSummaryCards(geojson, features) {
        const totalEl = document.getElementById(TOTAL_STAT_ID);
        const highRiskEl = document.getElementById(HIGH_RISK_STAT_ID);
        const barangayEl = document.getElementById(BARANGAY_STAT_ID);
        const sourceEl = document.getElementById(SOURCE_STAT_ID);

        if (totalEl) {
            const total = features.reduce((sum, feature) => sum + seniorCount(feature), 0);
            totalEl.textContent = new Intl.NumberFormat().format(total);
        }

        if (highRiskEl) {
            const highRiskCount = features.reduce((sum, feature) => sum + (numericValue(feature.properties?.high_risk_count) ?? 0), 0);
            highRiskEl.textContent = new Intl.NumberFormat().format(highRiskCount);
        }

        if (barangayEl) {
            const barangayCount = new Set(features.filter((feature) => seniorCount(feature) > 0).map((feature) => feature.properties?.barangay).filter(Boolean)).size;
            barangayEl.textContent = new Intl.NumberFormat().format(barangayCount);
        }

        if (sourceEl) {
            sourceEl.textContent = geojson?.source === 'database' ? 'Database' : 'Sample';
        }
    }

    function buildFacilityLayer(map, featureCollection) {
        const markerLayer = window.L.geoJSON(featureCollection, {
            pointToLayer(feature, latlng) {
                const marker = window.L.marker(latlng, {
                    icon: createFacilityIcon(facilityColor(feature)),
                    keyboard: false,
                    riseOnHover: true,
                    pane: 'gis-facility-pane',
                    // Recorded so the cluster bubble can be toned by the
                    // dominant facility category among its children.
                    gisFacilityType: facilityType(feature),
                });

                marker.bindPopup(facilityPopupHtml(feature.properties));
                marker.on('click', (event) => {
                    if (event?.originalEvent) {
                        window.L.DomEvent.stopPropagation(event.originalEvent);
                    }

                    if (openSeniorPopupAt(map, event)) {
                        return;
                    }

                    marker.openPopup();
                });

                return marker;
            },
        });

        // Cluster the 150+ facility diamonds so only a handful of DOM nodes
        // render when zoomed/panned out, and markercluster culls markers
        // outside the viewport (removeOutsideVisibleBounds) when zoomed in.
        // Individual diamonds reappear at zoom >= 16, matching senior markers.
        const clusterLayer = window.L.markerClusterGroup({
            clusterPane: 'gis-facility-pane',
            showCoverageOnHover: false,
            spiderfyOnMaxZoom: true,
            disableClusteringAtZoom: 16,
            maxClusterRadius: 28,
            iconCreateFunction(cluster) {
                const counts = new Map();

                cluster.getAllChildMarkers().forEach((marker) => {
                    const type = marker.options.gisFacilityType;
                    if (!type) return;

                    counts.set(type, (counts.get(type) ?? 0) + 1);
                });

                const majority = [...counts.entries()].sort((a, b) => b[1] - a[1])[0];
                const tone = majority ? facilityColor(majority[0]) : DEFAULT_FACILITY_COLOR;

                return makeFacilityClusterDivIcon(tone, cluster.getChildCount());
            },
        });

        clusterLayer.addLayer(markerLayer);

        return clusterLayer;
    }

    function boundaryLabel(properties) {
        const p = properties || {};
        return p.name || p.NAME || p.barangay || p.BARANGAY || p.brgy_name || p.BRGY_NAME || p.ADM4_EN || p.adm4_en || 'Barangay boundary';
    }

    function buildBoundaryLayer(featureCollection, options = {}) {
        return window.L.geoJSON(featureCollection, {
            pane: options.pane,
            style(feature) {
                return typeof options.style === 'function' ? options.style(feature) : options.style;
            },
            onEachFeature(feature, layer) {
                if (options.tooltip) {
                    layer.bindTooltip(boundaryLabel(feature.properties), {
                        sticky: true,
                        direction: 'center',
                        opacity: 0.9,
                        className: 'gis-boundary-tooltip',
                    });
                }

                if (typeof options.popup === 'function') {
                    layer.bindPopup(options.popup(feature));
                    layer.on('click', (event) => {
                        if (event?.originalEvent) {
                            window.L.DomEvent.stopPropagation(event.originalEvent);
                        }
                        layer.openPopup(event.latlng);
                    });
                }
            },
        });
    }

    function barangayFeatureAtLatLng(latlng) {
        if (!latlng || !hasBoundaryFeatures(latestBarangayBoundaryGeoJson)) {
            return null;
        }

        const point = [Number(latlng.lng), Number(latlng.lat)];
        if (!point.every(Number.isFinite)) {
            return null;
        }

        return (latestBarangayBoundaryGeoJson.features || []).find((feature) => {
            const geometry = feature?.geometry;
            const coordinates = geometry?.coordinates;

            if (geometry?.type === 'Polygon') {
                return pointInPolygonCoordinates(point, coordinates);
            }

            if (geometry?.type === 'MultiPolygon') {
                return Array.isArray(coordinates) &&
                    coordinates.some((polygon) => pointInPolygonCoordinates(point, polygon));
            }

            return false;
        }) || null;
    }

    function openBarangayPopupAt(map, latlng) {
        const feature = barangayFeatureAtLatLng(latlng);
        if (!feature) {
            return false;
        }

        const name = barangayNameFromBoundary(feature);
        const stats = barangayStats(filteredFeatures(latestSeniorGeoJson?.features || []));

        window.L.popup({
            maxWidth: 320,
            autoPan: true,
        })
            .setLatLng(latlng)
            .setContent(barangayDensityTooltip(name, stats.get(normalizeBarangayName(name))))
            .openOn(map);

        return true;
    }

    function ensureMapPanes(map) {
        if (!map.getPane('gis-heat-pane')) {
            map.createPane('gis-heat-pane');
            map.getPane('gis-heat-pane').style.zIndex = 370;
        }

        if (!map.getPane('gis-barangay-pane')) {
            map.createPane('gis-barangay-pane');
            map.getPane('gis-barangay-pane').style.zIndex = 380;
        }

        if (!map.getPane('gis-municipal-pane')) {
            map.createPane('gis-municipal-pane');
            map.getPane('gis-municipal-pane').style.zIndex = 390;
        }

        if (!map.getPane('gis-mask-pane')) {
            map.createPane('gis-mask-pane');
            const maskPane = map.getPane('gis-mask-pane');
            maskPane.style.zIndex = 590;
            maskPane.style.backgroundColor = maskFillColor();
        }

        if (!map.getPane('gis-risk-pane')) {
            map.createPane('gis-risk-pane');
            map.getPane('gis-risk-pane').style.zIndex = 420;
        }

        if (!map.getPane('gis-facility-pane')) {
            map.createPane('gis-facility-pane');
            map.getPane('gis-facility-pane').style.zIndex = 610;
        }

        if (!map.getPane('gis-senior-pane')) {
            map.createPane('gis-senior-pane');
            map.getPane('gis-senior-pane').style.zIndex = 620;
        }
    }

    function ensureLayerRegistry(map) {
        if (map._gisLayerRegistry) {
            return map._gisLayerRegistry;
        }

        const registry = {
            barangayBoundaries: window.L.layerGroup().addTo(map),
            municipalBoundary: window.L.layerGroup().addTo(map),
            municipalMask: window.L.layerGroup().addTo(map),
            heatmap: window.L.layerGroup().addTo(map),
            barangayDensity: window.L.layerGroup().addTo(map),
            riskOverlay: window.L.layerGroup().addTo(map),
            facilities: window.L.layerGroup().addTo(map),
            seniors: window.L.layerGroup().addTo(map),
        };

        map._gisLayerRegistry = registry;

        return registry;
    }

    function clearDynamicLayers(map) {
        const layers = ensureLayerRegistry(map);
        map._gisActiveHeatmap = null;
        layers.heatmap.clearLayers();
        layers.barangayDensity.clearLayers();
        layers.riskOverlay.clearLayers();
        layers.facilities.clearLayers();
        layers.seniors.clearLayers();
    }

    function clearHeatmapLayers(map) {
        const layers = ensureLayerRegistry(map);
        map._gisActiveHeatmap = null;
        layers.heatmap.clearLayers();
        layers.riskOverlay.clearLayers();
    }

    function heatmapFeaturesForMode(features, mode) {
        if (mode === 'cluster-heatmap') {
            const selectedCluster = selectedClusterGroup();
            return features.filter((feature) => {
                return featureClusterNumber(feature) !== null
                    && featureMatchesSelectedCluster(feature, selectedCluster);
            });
        }

        if (mode === 'senior-distribution-accessibility-heatmap') {
            return features.filter((feature) => accessibilityNeedWeight(feature.properties || {}) !== null);
        }

        return features;
    }

    function groupFeaturesByCluster(features) {
        const groups = new Map();

        heatmapFeaturesForMode(features, 'cluster-heatmap').forEach((feature) => {
            const number = featureClusterNumber(feature);
            if (number === null) {
                return;
            }

            const label = `Group ${number}`;
            const group = groups.get(label) ?? [];
            group.push(feature);
            groups.set(label, group);
        });

        return [...groups.entries()].sort(([a], [b]) => a.localeCompare(b));
    }

    async function buildClusterDistributionHeatmapLayer(map, features, options = {}) {
        const clusterGroups = groupFeaturesByCluster(features);
        const clusterFeatures = clusterGroups.flatMap(([, groupFeatures]) => groupFeatures);
        const selectedClusterMode = selectedClusterGroup() !== 'all' && clusterGroups.length === 1;
        const radiusMeters = Number.isFinite(options.radiusMeters)
            ? options.radiusMeters
            : heatmapRadiusMeters(clusterFeatures, 'cluster-heatmap');
        const bounds = primaryBoundaryBounds();
        const clusterClipBoundary = options.clipBoundary
            ?? dataBarangayBoundaryGeoJson(clusterFeatures)
            ?? primaryBoundaryGeoJson();
        const groups = clusterGroups
            .map(([label, groupFeatures]) => ({
                label,
                color: clusterColorForLabel(label, groupFeatures[0]),
                stops: clusterGradientForLabel(label, groupFeatures[0]),
                points: heatmapPoints(groupFeatures, 'cluster-heatmap'),
            }))
            .filter((group) => group.points.length > 0);
        const colorScaleMax = options.colorScaleMax ?? heatmapColorScaleMax(
            groups.flatMap((group) => group.points),
            'cluster-heatmap'
        );

        if (!groups.length) {
            return {
                layer: null,
                points: { length: 0 },
                groups: [],
                radiusMeters: Math.round(radiusMeters),
                colorScaleMax,
            };
        }

        // Renders independent filled-contour KDE surfaces per health group.
        // Hue stays tied to one assigned cluster per pixel; overlapping major
        // and minor groups never average into a blended color.
        const heatmapLayer = await createClusterDistributionRasterLayer(groups, {
            bounds,
            radius_meters: radiusMeters,
            maxRasterSide: options.maxRasterSide ?? 680,
            minRasterSide: options.minRasterSide ?? 380,
            pixelRatioCap: options.pixelRatioCap ?? 1,
            peak_radius_meters: options.peakRadiusMeters ?? Math.max(110, Math.min(185, radiusMeters * 0.42)),
            point_core_radius_meters: options.pointCoreRadiusMeters ?? Math.max(82, Math.min(142, radiusMeters * 0.32)),
            colorScaleMax,
            adaptiveScale: options.adaptiveScale ?? true,
            enablePeakSupport: options.enablePeakSupport ?? !selectedClusterMode,
            independentSurfaces: true,
            preserveMinorityOnTop: options.preserveMinorityOnTop ?? true,
            usePointHaloDensity: options.usePointHaloDensity ?? false,
            minorityPeakSupport: options.minorityPeakSupport ?? 0.92,
            minorityCoreSupport: options.minorityCoreSupport ?? 0.26,
            localGroupedBroadDensity: options.localGroupedBroadDensity ?? 0.030,
            localGroupedBroadSupport: options.localGroupedBroadSupport ?? 1.90,
            localGroupedBroadColorBoost: options.localGroupedBroadColorBoost ?? 1.70,
            localGroupedPeakDensity: options.localGroupedPeakDensity ?? 0.035,
            localGroupedPeakSupport: options.localGroupedPeakSupport ?? 1.12,
            localGroupedPriorityScale: options.localGroupedPriorityScale ?? 2.35,
            clusteredMinorityPeakSupport: options.clusteredMinorityPeakSupport ?? 1.18,
            groupedMinorityPriorityScale: options.groupedMinorityPriorityScale ?? 2.80,
            minVisibleDensity: options.minVisibleDensity ?? 0.058,
            minColorDensity: options.minColorDensity ?? 0.026,
            minBroadOnlyDensity: options.minBroadOnlyDensity ?? 0.078,
            minBroadOnlyColorDensity: options.minBroadOnlyColorDensity ?? 0.066,
            pointSignalDensity: options.pointSignalDensity ?? 0.022,
            peakMinVisibleDensity: options.peakMinVisibleDensity ?? 0.032,
            broadSupportBoost: options.broadSupportBoost ?? 0.88,
            peakSupportBoost: options.peakSupportBoost ?? 1.18,
            pointCoreBoost: options.pointCoreBoost ?? 1.36,
            broadColorBoost: options.broadColorBoost ?? 0.78,
            peakColorBoost: options.peakColorBoost ?? 1.10,
            pointCoreColorBoost: options.pointCoreColorBoost ?? 1.16,
            softColorMix: options.softColorMix ?? false,
            colorMixPower: options.colorMixPower ?? 2.35,
            winningColorBoost: options.winningColorBoost ?? 1.45,
            outputMaxAlpha: options.outputMaxAlpha ?? 218,
            outputAlphaBase: options.outputAlphaBase ?? 238,
            outputAlphaPower: options.outputAlphaPower ?? 0.82,
            layerAlphaScale: options.layerAlphaScale ?? 0.82,
            majorityPriorityScale: options.majorityPriorityScale ?? 0.92,
            minorityPriorityScale: options.minorityPriorityScale ?? 1.70,
            majorityAlphaScale: options.majorityAlphaScale ?? 0.88,
            minorityAlphaScale: options.minorityAlphaScale ?? 1.18,
            localMinorityBroadRatio: options.localMinorityBroadRatio ?? 0.96,
            minorityPointPresenceThreshold: options.minorityPointPresenceThreshold ?? 0.010,
            minorityPointPresenceScale: options.minorityPointPresenceScale ?? 7.6,
            majorityPointPresenceScale: options.majorityPointPresenceScale ?? 9.8,
            localMinorityPointPresenceScale: options.localMinorityPointPresenceScale ?? 12.0,
            pointAnchorDensity: options.pointAnchorDensity ?? 0.018,
            pointAnchorPriorityScale: options.pointAnchorPriorityScale ?? 3.20,
            pointAnchorCompeteRatio: options.pointAnchorCompeteRatio ?? 0.68,
            nonPointAnchorPriorityScale: options.nonPointAnchorPriorityScale ?? 0.14,
            broadOnlyPriorityScale: options.broadOnlyPriorityScale ?? 0.22,
            pointAnchoredOverrideRatio: options.pointAnchoredOverrideRatio ?? 0.42,
            minorityPointDensityLift: options.minorityPointDensityLift ?? 1.42,
            minorityPointColorLift: options.minorityPointColorLift ?? 1.62,
            localSameClusterBroadDensity: options.localSameClusterBroadDensity ?? 0.020,
            localSameClusterBroadRatio: options.localSameClusterBroadRatio ?? 0.98,
            localGroupedCoreDensity: options.localGroupedCoreDensity ?? 0.018,
            localSameClusterPriorityScale: options.localSameClusterPriorityScale ?? 3.75,
            broadBlendWeight: options.broadBlendWeight ?? 0.54,
            peakBlendWeight: options.peakBlendWeight ?? 1.22,
            pointCoreBlendWeight: options.pointCoreBlendWeight ?? 1.42,
            perClusterScaleFactor: options.perClusterScaleFactor ?? 0.88,
            perClusterPeakScaleFactor: options.perClusterPeakScaleFactor ?? 0.84,
            perClusterCoreScaleFactor: options.perClusterCoreScaleFactor ?? 0.78,
            totalDensityAlphaWeight: options.totalDensityAlphaWeight ?? 0.24,
            edgeSmoothPasses: options.edgeSmoothPasses ?? 1,
            edgeSmoothStrength: options.edgeSmoothStrength ?? 0.28,
            edgeSmoothRadius: options.edgeSmoothRadius ?? 1,
            dominancePower: options.dominancePower ?? 0.74,
            contourSmoothPasses: options.contourSmoothPasses ?? 14,
            contourStep: options.contourStep ?? 5,
            contourLevels: options.contourLevels ?? [0.12, 0.26, 0.44, 0.66, 0.84],
            contourLineWidth: options.contourLineWidth ?? 0.9,
            clipBoundary: clusterClipBoundary,
        });

        return {
            points: { length: groups.reduce((total, group) => total + group.points.length, 0) },
            groups: clusterGroups.map(([label]) => label),
            radiusMeters: Math.round(radiusMeters),
            peakRadiusMeters: !selectedClusterMode ? Math.round(options.peakRadiusMeters ?? Math.max(80, Math.min(140, radiusMeters * 0.38))) : null,
            pointCoreRadiusMeters: !selectedClusterMode ? Math.round(options.pointCoreRadiusMeters ?? Math.max(80, Math.min(130, radiusMeters * 0.34))) : null,
            colorScaleMax,
            layer: heatmapLayer,
        };
    }

    function riskDistributionWeight(feature) {
        // Real, data-driven weight: prefer the backend composite risk score
        // (props.risk_score, normalized to 0..1); fall back to the categorical
        // risk level. Returns null when a senior has no risk signal so it never
        // contributes false intensity.
        const props = feature.properties || {};
        const score = normalizedRiskScore(props.risk_score);
        if (score !== null) {
            // Lift the floor a little so low-risk seniors still register a faint
            // kernel but high-risk clearly dominates the surface.
            return clampUnit(0.15 + score * 0.85);
        }

        return riskWeight(props.risk_level);
    }

    function riskDistributionPoints(features) {
        return features
            .map((feature) => {
                const latlng = featureLatLng(feature);
                const weight = riskDistributionWeight(feature);

                if (!latlng || weight === null || weight <= 0) {
                    return null;
                }

                return [latlng.lat, latlng.lng, weight];
            })
            .filter(Boolean);
    }

    function riskDistributionRadiusMeters(features) {
        const base = heatmapRadiusMeters(features, 'risk-indicator-heatmap');
        // Bump the base spacing radius so the risk surface reads as a smooth,
        // blended typhoon-style field instead of disconnected dots.
        return Math.max(280, Math.min(560, base * 1.3));
    }

    // Reuses the cluster raster-KDE engine with a single risk-weighted surface,
    // so the Risk Distribution heatmap renders with the exact same smooth,
    // contoured, Pagsanjan-clipped look as the health-group heatmap.
    async function buildRiskDistributionRasterLayer(map, features, options = {}) {
        const points = riskDistributionPoints(features);
        const bounds = primaryBoundaryBounds();
        const radiusMeters = Number.isFinite(options.radiusMeters)
            ? options.radiusMeters
            : riskDistributionRadiusMeters(features);

        if (!points.length || !bounds?.isValid?.()) {
            return { layer: null, points: { length: 0 }, radiusMeters: Math.round(radiusMeters) };
        }

        const group = {
            label: 'Risk',
            stops: RISK_DISTRIBUTION_RAMP,
            points,
        };

        const layer = await createClusterDistributionRasterLayer([group], {
            bounds,
            radius_meters: radiusMeters,
            peak_radius_meters: options.peakRadiusMeters ?? Math.max(90, Math.min(160, radiusMeters * 0.40)),
            point_core_radius_meters: options.pointCoreRadiusMeters ?? Math.max(90, Math.min(150, radiusMeters * 0.36)),
            colorScaleMax: options.colorScaleMax ?? 255,
            adaptiveScale: options.adaptiveScale ?? true,
            enablePeakSupport: options.enablePeakSupport ?? true,
            // No-data guardrails: only pixels with real accumulated risk signal
            // are painted; faint kernel tails stay transparent.
            minVisibleDensity: options.minVisibleDensity ?? 0.10,
            peakMinVisibleDensity: options.peakMinVisibleDensity ?? 0.05,
            peakSupportBoost: options.peakSupportBoost ?? 2.4,
            pointCoreBoost: options.pointCoreBoost ?? 3.2,
            outputMaxAlpha: options.outputMaxAlpha ?? 205,
            outputAlphaBase: options.outputAlphaBase ?? 230,
            outputAlphaPower: options.outputAlphaPower ?? 0.95,
            dominancePower: options.dominancePower ?? 0.82,
            clipBoundary: options.clipBoundary ?? primaryBoundaryGeoJson(),
        });

        return {
            layer,
            points: { length: points.length },
            radiusMeters: Math.round(radiusMeters),
            colorScaleMax: options.colorScaleMax ?? 255,
        };
    }

    function accessibilityDistributionPoints(features, referenceFeatures = null) {
        return features
            .map((feature) => {
                const latlng = featureLatLng(feature);
                const concern = backendAccessibilityConcern(feature.properties || {});

                if (!latlng || !concern) {
                    return null;
                }

                // Color/intensity is based on backend-provided accessibility
                // fields from the senior GIS data; distance is only a fallback.
                return [latlng.lat, latlng.lng, clampUnit(0.05 + (concern.score * 0.95))];
            })
            .filter(Boolean);
    }

    function createAccessibilityHeatmapOverlay(points, bounds, options = {}) {
        if (!points.length || !bounds?.isValid?.()) {
            return null;
        }

        const stops = gradientStopsFromStops(ACCESSIBILITY_DISTRIBUTION_RAMP);
        const { width, height } = rasterSizeForBounds(bounds);
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d');

        // Radius in raster pixels via the shared helper, which uses the smaller of
        // the x/y meters-per-pixel so the blob stays circular for non-square bounds.
        const radiusMeters = options.radiusMeters ?? 620;
        const radius = Math.round(rasterRadiusPixels(bounds, width, height, radiusMeters));

        points
            .slice()
            .sort((a, b) => a[2] - b[2])
            .forEach(([lat, lng, score]) => {
                const point = latLngToRasterPoint(lat, lng, bounds, width, height);
                const [red, green, blue] = colorForGradientValue(score, stops);
                const gradient = context.createRadialGradient(point.x, point.y, 0, point.x, point.y, radius);
                gradient.addColorStop(0.00, `rgba(${red},${green},${blue},0.66)`);
                gradient.addColorStop(0.20, `rgba(${red},${green},${blue},0.48)`);
                gradient.addColorStop(0.48, `rgba(${red},${green},${blue},0.24)`);
                gradient.addColorStop(0.78, `rgba(${red},${green},${blue},0.08)`);
                gradient.addColorStop(1.00, `rgba(${red},${green},${blue},0)`);
                context.fillStyle = gradient;
                context.beginPath();
                context.arc(point.x, point.y, radius, 0, Math.PI * 2);
                context.fill();
            });

        // Clip to the municipal boundary using the precomputed cached mask.
        const boundary = options.clipBoundary ?? primaryBoundaryGeoJson();
        const mask = getRasterBoundaryMask(bounds, width, height, boundary);
        const image = context.getImageData(0, 0, width, height);
        const contourDensityGrid = new Float32Array(width * height);
        for (let index = 0; index < image.data.length; index += 4) {
            const pixel = index / 4;
            if (mask && mask[pixel] !== 1) {
                image.data[index + 3] = 0;
                continue;
            }
            contourDensityGrid[pixel] = clampUnit(image.data[index + 3] / 190);
        }
        context.putImageData(image, 0, 0);

        // KDE contour overlay (same levels/step as the old live layer).
        const contourSource = smoothScalarGrid(contourDensityGrid, width, height, 5);
        drawKdeContours(context, contourSource, width, height, {
            step: 4,
            levels: [0.10, 0.18, 0.28, 0.40, 0.54, 0.68, 0.82],
            lineWidth: 1.05,
            haloLineWidth: 0,
        });

        return createSmoothHeatmapImageOverlay(canvas.toDataURL('image/png'), bounds, {
            pane: 'gis-heat-pane',
            opacity: 1,
            interactive: false,
        });
    }

    function buildAccessibilityDistributionRasterLayer(map, features, options = {}) {
        const referenceFeatures = options.referenceFeatures || latestSeniorGeoJson?.features || features;
        const points = accessibilityDistributionPoints(features, referenceFeatures);
        const bounds = primaryBoundaryBounds();
        const radiusMeters = Number.isFinite(options.radiusMeters)
            ? options.radiusMeters
            : Math.max(520, Math.min(760, heatmapRadiusMeters(features, 'accessibility-heatmap') * 1.35));

        if (!points.length || !bounds?.isValid?.()) {
            return { layer: null, points: { length: 0 }, radiusMeters: Math.round(radiusMeters) };
        }

        const layer = createAccessibilityHeatmapOverlay(points, bounds, {
            radiusMeters,
            clipBoundary: options.clipBoundary ?? primaryBoundaryGeoJson(),
        });

        return {
            layer,
            points: { length: points.length },
            radiusMeters: Math.round(radiusMeters),
            colorScaleMax: options.colorScaleMax ?? 255,
        };
    }

    function clusterFlowPoints(features) {
        return features
            .map((feature) => {
                const latlng = featureLatLng(feature);
                const ramp = gradientStopsFromStops(clusterGradientForLabel(clusterLabel(feature), feature));

                if (!latlng || !ramp?.length) {
                    return null;
                }

                const groupNumber = featureClusterNumber(feature) ?? clusterNumber(clusterLabel(feature)) ?? 0;
                const color = colorForGradientValue(0.70, ramp);

                return [latlng.lat, latlng.lng, color, groupNumber];
            })
            .filter(Boolean);
    }

    function createClusterFlowHeatmapLayer(points, options = {}) {
        const HeatLayer = window.L.Layer.extend({
            initialize() {
                this._points = points;
                this._options = options;
                this._contourCache = null;
            },

            onAdd(map) {
                this._map = map;
                this._canvas = window.L.DomUtil.create('canvas', 'leaflet-layer gis-cluster-flow-heat-canvas');
                this._canvas.style.pointerEvents = 'none';
                (map.getPane('gis-heat-pane') ?? map.getPanes().overlayPane).appendChild(this._canvas);
                map.on('moveend zoomend resize', this._reset, this);
                this._reset();
            },

            onRemove(map) {
                if (this._canvas?.parentNode) {
                    this._canvas.parentNode.removeChild(this._canvas);
                }
                map.off('moveend zoomend resize', this._reset, this);
            },

            _reset() {
                const size = this._map.getSize();
                const ratio = Math.max(1, Math.min(1.5, window.devicePixelRatio || 1));
                const topLeft = this._map.containerPointToLayerPoint([0, 0]);
                window.L.DomUtil.setPosition(this._canvas, topLeft);
                this._canvas.style.width = `${size.x}px`;
                this._canvas.style.height = `${size.y}px`;
                this._canvas.width = Math.round(size.x * ratio);
                this._canvas.height = Math.round(size.y * ratio);
                this._ratio = ratio;
                this._redraw();
            },

            async _redraw() {
                const myRedraw = (this._redrawToken = (this._redrawToken || 0) + 1);
                const width = this._canvas.width;
                const height = this._canvas.height;
                const ratio = this._ratio || 1;
                const cssWidth = width / ratio;
                const cssHeight = height / ratio;
                const context = this._canvas.getContext('2d');
                context.clearRect(0, 0, width, height);

                const radiusMeters = this._options.radiusMeters ?? 620;
                const radius = Math.round(Math.max(18, Math.min(170, metersToPixelsAtLatLng(this._map, this._map.getCenter(), radiusMeters))) * ratio);
                const contourDensityGrid = new Float32Array(width * height);

                this._points
                    .slice()
                    .sort((a, b) => a[3] - b[3])
                    .forEach(([lat, lng, color]) => {
                        const mapPoint = this._map.latLngToContainerPoint([lat, lng]);
                        const point = {
                            x: mapPoint.x * ratio,
                            y: mapPoint.y * ratio,
                        };

                        if (mapPoint.x < -(radius / ratio) || mapPoint.y < -(radius / ratio) || mapPoint.x > cssWidth + (radius / ratio) || mapPoint.y > cssHeight + (radius / ratio)) {
                            return;
                        }

                        const [red, green, blue] = color;
                        const gradient = context.createRadialGradient(point.x, point.y, 0, point.x, point.y, radius);
                        gradient.addColorStop(0.00, `rgba(${red},${green},${blue},0.66)`);
                        gradient.addColorStop(0.20, `rgba(${red},${green},${blue},0.48)`);
                        gradient.addColorStop(0.48, `rgba(${red},${green},${blue},0.24)`);
                        gradient.addColorStop(0.78, `rgba(${red},${green},${blue},0.08)`);
                        gradient.addColorStop(1.00, `rgba(${red},${green},${blue},0)`);
                        context.fillStyle = gradient;
                        context.beginPath();
                        context.arc(point.x, point.y, radius, 0, Math.PI * 2);
                        context.fill();
                    });

                await yieldToEventLoop();
                if (myRedraw !== this._redrawToken) return;

                const boundary = this._options.clipBoundary ?? primaryBoundaryGeoJson();
                const image = context.getImageData(0, 0, width, height);
                const flowMask = hasBoundaryFeatures(boundary)
                    ? buildBoundaryMask(width, height, (lat, lng) => {
                        const containerPoint = this._map.latLngToContainerPoint([lat, lng]);
                        return { x: containerPoint.x * ratio, y: containerPoint.y * ratio };
                    }, boundary)
                    : null;
                const __flowSliceBudget = makeSliceBudget(10);
                for (let index = 0; index < image.data.length; index += 4) {
                    const pixel = index / 4;
                    if ((pixel & 8191) === 0 && __flowSliceBudget()) {
                        await yieldToEventLoop();
                        if (myRedraw !== this._redrawToken) return;
                    }
                    if (!image.data[index + 3]) continue;

                    if (flowMask && flowMask[pixel] !== 1) {
                        image.data[index + 3] = 0;
                        continue;
                    }

                    contourDensityGrid[pixel] = clampUnit(image.data[index + 3] / 190);
                }
                context.putImageData(image, 0, 0);

                await yieldToEventLoop();
                if (myRedraw !== this._redrawToken) return;
                const contourSourceGrid = smoothScalarGrid(contourDensityGrid, width, height, 5);
                drawKdeContours(context, contourSourceGrid, width, height, {
                    step: Math.max(3, Math.round(4 * ratio)),
                    levels: [0.10, 0.18, 0.28, 0.40, 0.54, 0.68, 0.82],
                    lineWidth: 1.05 * ratio,
                });
            },
        });

        return new HeatLayer();
    }

    function buildClusterFlowHeatmapLayer(map, features, options = {}) {
        const points = clusterFlowPoints(features);
        const radiusMeters = Number.isFinite(options.radiusMeters)
            ? options.radiusMeters
            : Math.max(520, Math.min(760, heatmapRadiusMeters(features, 'cluster-heatmap') * 1.35));

        if (!points.length) {
            return null;
        }

        return createClusterFlowHeatmapLayer(points, {
            radiusMeters,
            clipBoundary: options.clipBoundary ?? primaryBoundaryGeoJson(),
        });
    }

    function createSmoothHeatmapImageOverlay(dataUrl, bounds, options = {}) {
        const overlay = window.L.imageOverlay(dataUrl, bounds, {
            ...options,
            className: [options.className, 'gis-kde-raster'].filter(Boolean).join(' '),
        });
        const smoothImage = () => {
            const image = overlay.getElement?.();
            if (!image) {
                return;
            }

            image.style.imageRendering = 'auto';
            image.style.msInterpolationMode = 'bicubic';
            image.style.willChange = 'transform, opacity';
        };

        overlay.on('add load', smoothImage);

        return overlay;
    }

    function makeClusterDivIcon(tone, count) {
        return window.L.divIcon({
            html: `<div style="background:${tone};color:#fff;width:34px;height:34px;border-radius:9999px;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.95);box-shadow:0 8px 18px rgba(15,23,42,0.18);font-size:11px;font-weight:700;">${count}</div>`,
            className: 'gis-cluster-icon',
            iconSize: [34, 34],
        });
    }

    function makeFacilityClusterDivIcon(tone, count) {
        // Squared bubble (vs. the circular senior cluster) so facility clusters
        // read as facilities at a glance; tone = majority facility category color.
        return window.L.divIcon({
            html: `<div style="background:${tone};color:#fff;width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.95);box-shadow:0 6px 14px rgba(15,23,42,0.18);font-size:11px;font-weight:700;">${count}</div>`,
            className: 'gis-facility-cluster-icon',
            iconSize: [32, 32],
        });
    }

    function buildClusterDistributionPointLayer(map, features) {
        const markerLayer = window.L.geoJSON({
            type: 'FeatureCollection',
            features,
        }, {
            pointToLayer(feature, latlng) {
                const kind = coordinateKind(feature);
                const color = clusterColorForLabel(clusterLabel(feature), feature);
                const isFallback = kind === 'fallback';

                return window.L.circleMarker(latlng, {
                    renderer: getCanvasRenderer(map),
                    pane: 'gis-senior-pane',
                    radius: isFallback ? 5 : 7.5,
                    color: '#ffffff',
                    weight: isFallback ? 1 : 2,
                    opacity: 0.82,
                    fillColor: isFallback ? colorWithAlpha(color, 0.5) : color,
                    fillOpacity: isFallback ? 0.58 : 0.9,
                    interactive: true,
                    gisRiskLevel: feature.properties?.risk_level,
                    gisBarangay: feature.properties?.barangay,
                    gisCoordinateKind: kind,
                    gisCluster: clusterLabel(feature),
                });
            },
            onEachFeature(feature, layer) {
                attachSeniorPopup(layer, feature);
            },
        });

        if (! shouldClusterMarkers()) {
            return markerLayer;
        }

        const markerClusterLayer = window.L.markerClusterGroup({
            clusterPane: 'gis-senior-pane',
            showCoverageOnHover: false,
            spiderfyOnMaxZoom: true,
            disableClusteringAtZoom: 16,
            maxClusterRadius: 26,
            iconCreateFunction(cluster) {
                const markers = cluster.getAllChildMarkers();
                const counts = new Map();

                markers.forEach((marker) => {
                    const label = marker.options.gisCluster;
                    if (!label) return;

                    const key = clusterNumber(label) ?? label;
                    const current = counts.get(key) ?? { label, count: 0 };
                    current.count++;
                    counts.set(key, current);
                });

                const majority = [...counts.values()].sort((a, b) => b.count - a.count)[0];
                const tone = majority ? clusterColorForLabel(majority.label) : '#64748b';

                return makeClusterDivIcon(tone, cluster.getChildCount());
            },
        });

        markerClusterLayer.addLayer(markerLayer);

        return markerClusterLayer;
    }

    function buildRiskIdentityHaloLayer(map, features) {
        // Small senior dots (colored by real risk level) shown above the risk
        // KDE surface so markers stay visible and popups keep working.
        return window.L.geoJSON({
            type: 'FeatureCollection',
            features,
        }, {
            pointToLayer(feature, latlng) {
                const color = riskColor(feature.properties?.risk_level);

                return window.L.circleMarker(latlng, {
                    renderer: getCanvasRenderer(map),
                    pane: 'gis-senior-pane',
                    radius: 3.5,
                    color: '#ffffff',
                    weight: 1,
                    opacity: 0.82,
                    fillColor: color,
                    fillOpacity: 0.68,
                    interactive: true,
                });
            },
            onEachFeature(feature, layer) {
                attachSeniorPopup(layer, feature);
            },
        });
    }

    function accessibilityConcernColor(score) {
        return rgbString(colorForGradientValue(clampUnit(score ?? 0), gradientStopsFromStops(ACCESSIBILITY_DISTRIBUTION_RAMP)));
    }

    function accessibilityClusterTone(markers) {
        const scores = markers
            .map((marker) => numericValue(marker.options.gisAccessibilityConcernScore))
            .filter((score) => score !== null);

        if (!scores.length) {
            return '#64748b';
        }

        const average = scores.reduce((sum, score) => sum + score, 0) / scores.length;
        return accessibilityConcernColor(average);
    }

    function buildAccessibilitySeniorPointLayer(map, featureCollection) {
        const markerLayer = window.L.geoJSON(featureCollection, {
            pointToLayer(feature, latlng) {
                const kind = coordinateKind(feature);
                const concern = backendAccessibilityConcern(feature.properties || {});
                const colorChannels = concern
                    ? colorForGradientValue(concern.score, gradientStopsFromStops(ACCESSIBILITY_DISTRIBUTION_RAMP))
                    : hexToRgb(barangayColor(feature.properties?.barangay));
                const color = rgbString(colorChannels);
                const isFallback = kind === 'fallback';
                const marker = window.L.circleMarker(latlng, {
                    renderer: getCanvasRenderer(map),
                    pane: 'gis-senior-pane',
                    radius: isFallback ? 5 : 7.5,
                    fillColor: isFallback ? rgbaString(colorChannels, 0.5) : color,
                    fillOpacity: isFallback ? 0.58 : 0.9,
                    color: '#ffffff',
                    weight: isFallback ? 1 : 2,
                    gisRiskLevel: feature.properties?.risk_level,
                    gisBarangay: feature.properties?.barangay,
                    gisCoordinateKind: kind,
                    gisAccessibilityConcernScore: concern?.score ?? null,
                    gisAccessibilityConcernLevel: concern?.level ?? null,
                });

                attachSeniorPopup(marker, feature);

                return marker;
            },
        });

        if (! shouldClusterMarkers()) {
            return markerLayer;
        }

        const markerClusterLayer = window.L.markerClusterGroup({
            clusterPane: 'gis-senior-pane',
            showCoverageOnHover: false,
            spiderfyOnMaxZoom: true,
            disableClusteringAtZoom: 16,
            maxClusterRadius: 26,
            iconCreateFunction(cluster) {
                const markers = cluster.getAllChildMarkers();
                const tone = accessibilityClusterTone(markers);

                return makeClusterDivIcon(tone, cluster.getChildCount());
            },
        });

        markerClusterLayer.addLayer(markerLayer);

        return markerClusterLayer;
    }

    function setActiveHeatmapContext(map, mode, features, options = {}) {
        map._gisActiveHeatmap = {
            mode,
            features: [...features],
            radiusMeters: options.radiusMeters,
            colorScaleMax: options.colorScaleMax,
        };
    }

    function refreshActiveHeatmapRadius(map) {
        const context = map?._gisActiveHeatmap;
        if (!context || !isHeatmapMode(context.mode)) {
            return;
        }

        if (context.mode === 'cluster-heatmap') {
            return;
        }

        // Raster-KDE modes use a zoom-stable L.imageOverlay; rebuilding them as a
        // canvas layer on every zoom would both flicker and overwrite the raster.
        if (context.mode === 'risk-indicator-heatmap' || context.mode === 'senior-distribution-accessibility-heatmap') {
            return;
        }

        const layers = ensureLayerRegistry(map);
        layers.heatmap.clearLayers();

        const refreshOptions = {
            radiusMeters: context.radiusMeters,
            colorScaleMax: context.colorScaleMax,
        };

        const { layer: heatLayer, points, radiusMeters } = buildHeatmapLayer(map, context.features, context.mode, refreshOptions);

        if (points.length && heatLayer) {
            layers.heatmap.addLayer(heatLayer);
            context.radiusMeters = context.radiusMeters ?? radiusMeters;
        }
    }

    function refreshHeatmapLayersForZoom(map) {
        refreshActiveHeatmapRadius(map);
    }

    async function renderRiskHeatmap(map, features) {
        const myToken = activeRenderToken;
        clearHeatmapLayers(map);

        if (!window.L.Layer) {
            focusMapOnActiveLayer(map, features);
            setStatus('Leaflet is unavailable. Rebuild frontend assets to enable GIS heatmaps.', 'error');
            return;
        }

        const result = await buildRiskDistributionRasterLayer(map, features);
        if (myToken !== activeRenderToken) return;

        if (!result.layer || !result.points.length) {
            focusMapOnActiveLayer(map, features);
            setStatus('No senior records had risk indicator values for the selected filters.', 'neutral');
            return;
        }

        ensureLayerRegistry(map).heatmap.addLayer(result.layer);
        if (shouldShowRiskSeniorPoints()) {
            ensureLayerRegistry(map).seniors.addLayer(buildRiskIdentityHaloLayer(map, features));
        }
        setActiveHeatmapContext(map, 'risk-indicator-heatmap', features, {
            radiusMeters: result.radiusMeters,
            colorScaleMax: result.colorScaleMax,
        });
        focusMapOnActiveLayer(map, features);
        setStatus(`Risk Indicator Distribution renders ${result.points.length} senior GIS point(s) as a continuous KDE risk surface, weighted by composite risk score (falling back to risk level), clipped to Pagsanjan (${result.radiusMeters}m radius).`, 'success');
    }

    async function renderSeniorDistributionAccessibilityHeatmap(map, features) {
        clearHeatmapLayers(map);

        if (!window.L.Layer) {
            focusMapOnActiveLayer(map, features);
            setStatus('Leaflet is unavailable. Rebuild frontend assets to enable GIS heatmaps.', 'error');
            return;
        }

        const heatmapFeatures = heatmapFeaturesForMode(features, 'senior-distribution-accessibility-heatmap');
        const result = buildAccessibilityDistributionRasterLayer(map, heatmapFeatures);

        if (!result.layer || !result.points.length) {
            focusMapOnActiveLayer(map, features);
            setStatus('No senior records had nearest-facility distance data for the selected filters.', 'neutral');
            return;
        }

        const featureCollection = {
            type: 'FeatureCollection',
            features: heatmapFeatures,
        };

        const layers = ensureLayerRegistry(map);
        layers.heatmap.addLayer(result.layer);

        if (shouldShowAccessibilitySeniorPoints()) {
            layers.seniors.addLayer(buildAccessibilitySeniorPointLayer(map, featureCollection));
        }

        setActiveHeatmapContext(map, 'senior-distribution-accessibility-heatmap', heatmapFeatures, {
            radiusMeters: result.radiusMeters,
            colorScaleMax: result.colorScaleMax,
        });
        focusMapOnActiveLayer(map, heatmapFeatures);

        const clusterText = shouldShowAccessibilitySeniorPoints() && shouldClusterMarkers()
            ? ' Points are visually clustered when zoomed out, without changing the underlying senior coordinates.'
            : '';
        const pointText = shouldShowAccessibilitySeniorPoints()
            ? ` Senior distribution points are shown above the heatmap using the same barangay-level coordinates as Senior Distribution Points.${clusterText}`
            : ' Senior distribution points are hidden.';

        setStatus(`Senior Distribution and Accessibility Heatmap renders ${result.points.length} senior distribution point(s).${pointText} Heatmap color comes from backend accessibility/proximity data; points follow the senior GIS data available in the database.`, 'success');
    }

    async function renderClusterHeatmap(map, features) {
        const myToken = activeRenderToken;
        clearHeatmapLayers(map);

        const selectedCluster = selectedClusterGroup();
        const clusterFeatures = heatmapFeaturesForMode(features, 'cluster-heatmap');

        if (selectedCluster === 'all') {
            const layerGroup = ensureLayerRegistry(map).heatmap;
            layerGroup.clearLayers();
            const result = await buildClusterDistributionHeatmapLayer(map, features);
            if (myToken !== activeRenderToken) return;

            if (!result.layer || !result.points.length) {
                focusMapOnActiveLayer(map, features);
                setStatus('No senior records had profile group values for the selected filters.', 'neutral');
                return;
            }

            layerGroup.addLayer(result.layer);
            const pointRampLayer = buildClusterFlowHeatmapLayer(map, clusterFeatures, {
                radiusMeters: result.radiusMeters,
                clipBoundary: primaryBoundaryGeoJson(),
            });
            if (pointRampLayer) {
                layerGroup.addLayer(pointRampLayer);
            }
            if (shouldShowClusterDistributionPoints()) {
                ensureLayerRegistry(map).seniors.addLayer(buildClusterDistributionPointLayer(map, clusterFeatures));
            }
            setActiveHeatmapContext(map, 'cluster-heatmap', features, {
                radiusMeters: result.radiusMeters,
                colorScaleMax: result.colorScaleMax,
            });
            focusMapOnActiveLayer(map, clusterFeatures);
            setStatus(`Profile Group Distribution shows ${result.points.length} senior GIS point(s) across ${result.groups.length} group(s), rendered as a KDE density heatmap with non-blended group colors (${result.radiusMeters}m radius).`, 'success');
            return;
        }

        const result = await buildClusterDistributionHeatmapLayer(map, features);
        if (myToken !== activeRenderToken) return;

        if (!window.L.Layer) {
            focusMapOnActiveLayer(map, features);
            setStatus('Leaflet is unavailable. Rebuild frontend assets to enable GIS heatmaps.', 'error');
            return;
        }

        if (!result.layer || !result.points.length) {
            focusMapOnActiveLayer(map, features);
            setStatus('No senior records had profile group values for the selected filters.', 'neutral');
            return;
        }

        ensureLayerRegistry(map).heatmap.addLayer(result.layer);
        const pointRampLayer = buildClusterFlowHeatmapLayer(map, clusterFeatures, {
            radiusMeters: result.radiusMeters,
            clipBoundary: primaryBoundaryGeoJson(),
        });
        if (pointRampLayer) {
            ensureLayerRegistry(map).heatmap.addLayer(pointRampLayer);
        }
        if (shouldShowClusterDistributionPoints()) {
            ensureLayerRegistry(map).seniors.addLayer(buildClusterDistributionPointLayer(map, clusterFeatures));
        }
        setActiveHeatmapContext(map, 'cluster-heatmap', clusterFeatures, {
            radiusMeters: result.radiusMeters,
            colorScaleMax: result.colorScaleMax,
        });
        focusMapOnActiveLayer(map, clusterFeatures);
        setStatus(`Profile Group Distribution shows ${result.points.length} senior GIS point(s) in ${selectedCluster}, rendered as a clipped geographic KDE raster (${result.radiusMeters}m radius).`, 'success');
    }

    async function toggleGisLayer(map, mode, features) {
        if (mode === 'risk-indicator-heatmap') {
            await renderRiskHeatmap(map, features);
            return true;
        }

        if (mode === 'cluster-heatmap') {
            await renderClusterHeatmap(map, features);
            return true;
        }

        if (mode === 'senior-distribution-accessibility-heatmap') {
            await renderSeniorDistributionAccessibilityHeatmap(map, features);
            return true;
        }

        return false;
    }

    function renderBoundaryLayers(map, municipalGeoJson, barangayGeoJson) {
        const layers = ensureLayerRegistry(map);
        layers.municipalBoundary.clearLayers();
        layers.barangayBoundaries.clearLayers();
        layers.municipalMask.clearLayers();
        const selected = normalizeBarangayName(selectedBarangay());
        const boundaryStats = barangayStats(filteredFeatures(latestSeniorGeoJson?.features || []));

        if (hasBoundaryFeatures(barangayGeoJson)) {
            layers.barangayBoundaries.addLayer(buildBoundaryLayer(barangayGeoJson, {
                pane: 'gis-barangay-pane',
                tooltip: true,
                popup(feature) {
                    const name = barangayNameFromBoundary(feature);
                    return barangayDensityTooltip(name, boundaryStats.get(normalizeBarangayName(name)));
                },
                style(feature) {
                    const name = barangayNameFromBoundary(feature);
                    const isSelected = selected !== 'all' && normalizeBarangayName(name) === selected;
                    const color = barangayColor(name);

                    return {
                        color: isSelected ? '#0f172a' : color,
                        weight: isSelected ? 2.6 : 1.1,
                        opacity: isSelected ? 0.95 : 0.75,
                        fillColor: color,
                        fillOpacity: isSelected ? 0.20 : 0.075,
                    };
                },
            }));
        }

        refreshMunicipalMask(map);

        if (hasBoundaryFeatures(municipalGeoJson)) {
            layers.municipalBoundary.addLayer(buildBoundaryLayer(municipalGeoJson, {
                pane: 'gis-municipal-pane',
                tooltip: false,
                style: {
                    color: '#334155',
                    weight: 2.4,
                    opacity: 0.92,
                    fillColor: '#f8fafc',
                    fillOpacity: 0.05,
                },
            }));
        }
    }

    function combinedBoundsFromFeatures(features) {
        const points = features
            .map(featureLatLng)
            .filter(Boolean);

        return points.length ? window.L.latLngBounds(points) : null;
    }

    function isDarkMode() {
        return document.documentElement.classList.contains('dark');
    }

    function maskFillColor() {
        // The basemap tiles are always the light layer (TILE_LIGHT_URL), so the
        // mask covering everything outside Pagsanjan must stay light in both
        // themes. A dark fill here painted the exterior near-black over the light
        // tiles, which read as a blacked-out / "missing" map near the border.
        return '#ffffff';
    }

    function createRecenterControl(map) {
        const RecenterControl = window.L.Control.extend({
            options: { position: 'topleft' },
            onAdd() {
                const btn = window.L.DomUtil.create('button', 'leaflet-bar leaflet-control gis-recenter-control');
                btn.type = 'button';
                btn.title = 'Re-center map on Pagsanjan';
                btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/></svg>';
                window.L.DomEvent.on(btn, 'click', (e) => {
                    window.L.DomEvent.stopPropagation(e);
                    focusMapOnPagsanjan(map);
                });
                return btn;
            },
        });
        return new RecenterControl();
    }

    function createTileLayer() {
        return window.L.tileLayer(TILE_LIGHT_URL, {
            maxZoom: 19,
            attribution: TILE_LIGHT_ATTRIBUTION,
            updateWhenIdle: true,
            keepBuffer: 4,
            noWrap: true,
            // No `bounds` restriction: it limited tiles to the rectangular
            // navigation bounds, so the basemap rendered as a square that didn't
            // follow the town outline (obvious once the exterior mask was removed).
            // Panning is already constrained by the map's maxBounds, so tiles only
            // load around the viewable area regardless.
        });
    }

    function applyThemeToMap(map) {
        const maskPane = map.getPane('gis-mask-pane');
        if (maskPane) {
            maskPane.style.backgroundColor = maskFillColor();
        }

        if (latestMunicipalBoundaryGeoJson || latestBarangayBoundaryGeoJson) {
            renderBoundaryLayers(map, latestMunicipalBoundaryGeoJson, latestBarangayBoundaryGeoJson);
        }
    }

    function buildMunicipalMaskLayer(featureCollection, map) {
        if (!map || !hasBoundaryFeatures(featureCollection)) {
            return null;
        }

        const holes = [];

        featureCollection.features.forEach((feature) => {
            const geometry = feature?.geometry;
            const coordinates = geometry?.coordinates;

            if (!geometry || !Array.isArray(coordinates)) {
                return;
            }

            if (geometry.type === 'Polygon') {
                coordinates.forEach((ring) => {
                    if (Array.isArray(ring) && ring.length >= 4) {
                        holes.push(ring.map(([lng, lat]) => [lat, lng]));
                    }
                });
            }

            if (geometry.type === 'MultiPolygon') {
                coordinates.forEach((polygon) => {
                    if (!Array.isArray(polygon)) return;
                    polygon.forEach((ring) => {
                        if (Array.isArray(ring) && ring.length >= 4) {
                            holes.push(ring.map(([lng, lat]) => [lat, lng]));
                        }
                    });
                });
            }
        });

        if (!holes.length) {
            return null;
        }

        // The outer ring tracks the CURRENT viewport (padded), not a fixed giant
        // box. That keeps the projected coordinates roughly screen-sized, so the
        // Canvas renderer never hits its ~32k-pixel coordinate limit — that
        // overflow was what clipped the mask into a square and hid parts of the map
        // at higher zooms. refreshMunicipalMask() rebuilds this on move/zoom so it
        // always covers the screen, with the municipal boundary punched out as the
        // hole so only Pagsanjan shows through.
        const view = map.getBounds().pad(1.0);
        const outerRing = [
            [view.getSouth(), view.getWest()],
            [view.getSouth(), view.getEast()],
            [view.getNorth(), view.getEast()],
            [view.getNorth(), view.getWest()],
        ];

        return window.L.polygon([outerRing, ...holes], {
            renderer: getMaskRenderer(map),
            pane: 'gis-mask-pane',
            stroke: false,
            fillColor: maskFillColor(),
            fillOpacity: 1.0,
            interactive: false,
            bubblingMouseEvents: false,
        });
    }

    // Dedicated Canvas renderer for the mask, in its own pane so it layers above
    // the basemap/heat but below the facility and senior markers. Reused across
    // rebuilds so we don't leak a canvas element on every move/zoom.
    function getMaskRenderer(map) {
        if (!map._gisMaskRenderer) {
            map._gisMaskRenderer = window.L.canvas({ padding: 1.0, pane: 'gis-mask-pane' });
        }
        return map._gisMaskRenderer;
    }

    // Rebuild the exterior mask for the current viewport. Cheap (a single polygon)
    // and keeps coordinates small so the Canvas renderer stays within its limits.
    function refreshMunicipalMask(map) {
        if (!map) return;
        const layers = ensureLayerRegistry(map);
        layers.municipalMask.clearLayers();
        const primaryBoundary = primaryBoundaryGeoJson();
        if (!primaryBoundary) {
            return;
        }
        const maskLayer = buildMunicipalMaskLayer(primaryBoundary, map);
        if (maskLayer) {
            layers.municipalMask.addLayer(maskLayer);
        }
    }

    function mapFocusBounds() {
        const boundaryBounds = primaryBoundaryBounds();

        if (boundaryBounds) {
            return normalizedBounds(boundaryBounds, MUNICIPAL_FOCUS_PADDING_RATIO);
        }

        return boundsFromCoords(DEFAULT_FOCUS_BOUNDS_COORDS);
    }

    function mapNavigationBounds() {
        const boundaryBounds = primaryBoundaryBounds();

        if (boundaryBounds) {
            return normalizedBounds(boundaryBounds, MUNICIPAL_NAVIGATION_PADDING_RATIO);
        }

        return boundsFromCoords(NAVIGATION_BOUNDS_COORDS);
    }

    function applyMapBoundaryConstraints(map) {
        const navigationBounds = mapNavigationBounds();
        if (!navigationBounds) return;

        map.setMaxBounds(navigationBounds);
        map.options.maxBoundsViscosity = 1.0;
        map.panInsideBounds(navigationBounds, { animate: false });
    }

    function applyMapZoomConstraints(map) {
        map.setMinZoom(MIN_ZOOM);

        if (map.getZoom() < MIN_ZOOM) {
            map.setZoom(MIN_ZOOM, { animate: false });
        }
    }

    function focusMapOnPagsanjan(map) {
        const bounds = mapFocusBounds();
        if (bounds) {
            map.fitBounds(bounds, MAP_FIT_OPTIONS);
        }
    }

    function focusMapOnActiveLayer(map, activeFeatures) {
        const selectedFeature = selectedBarangayBoundaryFeature();
        const selectedBounds = featureBounds(selectedFeature);
        if (selectedBounds) {
            map.fitBounds(selectedBounds.pad(0.08), MAP_FIT_OPTIONS);
            return;
        }

        const boundaryBounds = primaryBoundaryBounds();
        if (boundaryBounds) {
            map.fitBounds(normalizedBounds(boundaryBounds, MUNICIPAL_FOCUS_PADDING_RATIO), MAP_FIT_OPTIONS);
            return;
        }

        if (activeFeatures.length === 1) {
            const point = featureLatLng(activeFeatures[0]);
            if (point) {
                map.setView(point, Math.max(DEFAULT_ZOOM, 15), { animate: false });
                return;
            }
        }

        const bounds = combinedBoundsFromFeatures(activeFeatures);
        if (bounds && bounds.isValid()) {
            map.fitBounds(bounds.pad(0.35), MAP_FIT_OPTIONS);
            return;
        }

        if (primaryBoundaryBounds()) {
            const fallbackBoundaryBounds = primaryBoundaryBounds();
            if (fallbackBoundaryBounds) {
                map.fitBounds(normalizedBounds(fallbackBoundaryBounds, MUNICIPAL_FOCUS_PADDING_RATIO), MAP_FIT_OPTIONS);
                return;
            }
        }

        focusMapOnPagsanjan(map);
    }

    async function renderDataLayers(map, seniorGeoJson, facilityGeoJson) {
        // Bump the render token so any in-flight async heatmap build from a
        // previous (now superseded) filter/mode state skips its map mutations.
        ++activeRenderToken;
        const mode = document.getElementById(MODE_ID)?.value ?? 'markers';
        syncSecondaryFilter();
        const activeFeatures = filteredFeatures(seniorGeoJson.features || []);
        const markerStats = validatedFeatureSet(activeFeatures, { exactOnly: false });
        const renderStats = markerStats;
        const facilityCollection = {
            type: 'FeatureCollection',
            features: facilityGeoJson?.features || [],
        };
        const layers = ensureLayerRegistry(map);
        clearDynamicLayers(map);
        renderBoundaryLayers(map, latestMunicipalBoundaryGeoJson, latestBarangayBoundaryGeoJson);
        syncAccessibilityPointDisplay();
        syncRiskPointDisplay();
        syncLayerOptionsPanel();
        updateLegend(mode);
        updateSummaryCards(seniorGeoJson, renderStats.visible);

        if (!activeFeatures.length) {
            focusMapOnPagsanjan(map);
            setStatus('No senior records matched the selected filters.', 'neutral');
            return;
        }

        const facilityLayer = buildFacilityLayer(map, facilityCollection);
        if (facilityGeoJson?.features?.length) {
            layers.facilities.addLayer(facilityLayer);
        }

        if (mode === 'markers') {
            const showDensityFill = document.getElementById(SHOW_BARANGAY_DENSITY_TOGGLE_ID)?.checked !== false;
            if (showDensityFill) {
                layers.barangayDensity.addLayer(buildBarangayDensityLayer(activeFeatures));
            }
        }


        if (mode !== 'markers' && !markerStats.visible.length) {
            focusMapOnActiveLayer(map, activeFeatures);
            setStatus('No barangay-level senior records matched this heatmap selection.', 'neutral');
            return;
        }

        const featureCollection = {
            type: 'FeatureCollection',
            features: mode === 'markers'
                ? markerStats.visible.filter((feature) => seniorCount(feature) > 0)
                : markerStats.visible,
        };

        if (mode === 'markers') {
            const markerLayer = window.L.geoJSON(featureCollection, {
                pointToLayer(feature, latlng) {
                    const kind = coordinateKind(feature);
                    const color = barangayColor(feature.properties?.barangay);
                    const isFallback = kind === 'fallback';
                    const marker = window.L.circleMarker(latlng, {
                        renderer: getCanvasRenderer(map),
                        radius: isFallback ? 5 : 7,
                        fillColor: isFallback ? colorWithAlpha(color, 0.5) : color,
                        fillOpacity: isFallback ? 0.6 : 0.9,
                        color: '#ffffff',
                        weight: isFallback ? 1 : 2,
                        gisRiskLevel: feature.properties?.risk_level,
                        gisBarangay: feature.properties?.barangay,
                        gisCoordinateKind: kind,
                    });

                    attachSeniorPopup(marker, feature);

                    return marker;
                },
            });

            const showSeniorPoints = document.getElementById(SHOW_SENIOR_POINTS_TOGGLE_ID)?.checked !== false;
            if (showSeniorPoints) {
                if (shouldClusterMarkers()) {
                    const markerClusterLayer = window.L.markerClusterGroup({
                        clusterPane: 'gis-senior-pane',
                        showCoverageOnHover: false,
                        spiderfyOnMaxZoom: true,
                        disableClusteringAtZoom: 16,
                        maxClusterRadius: 26,
                        iconCreateFunction(cluster) {
                            const markers = cluster.getAllChildMarkers();
                            const tone = clusterTone(markers);

                            return makeClusterDivIcon(tone, cluster.getChildCount());
                        },
                    });

                    markerClusterLayer.addLayer(markerLayer);
                    layers.seniors.addLayer(markerClusterLayer);
                } else {
                    layers.seniors.addLayer(markerLayer);
                }
            }

            focusMapOnActiveLayer(map, markerStats.visible.length ? markerStats.visible : activeFeatures);
            setStatus(`${validationStatusText(activeFeatures.length, markerStats)}`, 'success');
            return;
        }

        if (isHeatmapMode(mode)) {
            if (await toggleGisLayer(map, mode, markerStats.visible)) {
                return;
            }

            const { layer: heatLayer, points, radiusMeters } = buildHeatmapLayer(map, markerStats.visible, mode);

            if (!window.L.Layer) {
                focusMapOnActiveLayer(map, markerStats.visible);
                setStatus('Leaflet is unavailable. Rebuild frontend assets to enable GIS heatmaps.', 'error');
                return;
            }

            if (!points.length || !heatLayer) {
                focusMapOnActiveLayer(map, markerStats.visible);
                setStatus('No barangay-level records had enough data for the selected heatmap mode.', 'neutral');
                return;
            }

            layers.barangayDensity.addLayer(buildBarangayDensityLayer(activeFeatures));
            layers.heatmap.addLayer(heatLayer);
            setActiveHeatmapContext(map, mode, markerStats.visible);
            focusMapOnActiveLayer(map, markerStats.visible);
            setStatus(`Heatmap uses ${points.length} generalized barangay point(s), weighted by actual backend data. Radius is based on local GIS spacing/boundaries (${radiusMeters}m).`, 'success');
            return;
        }

        const { overlayGroup, pointLayer } = buildZoneOverlay(map, markerStats.visible, mode);
        layers.riskOverlay.addLayer(overlayGroup);
        if (facilityGeoJson?.features?.length) {
            layers.facilities.addLayer(facilityLayer);
        }
        layers.seniors.addLayer(pointLayer);
        focusMapOnActiveLayer(map, markerStats.visible);
        setStatus(`Overlay uses ${markerStats.visible.length} generalized barangay point(s).`, 'success');
    }

    function refreshRenderedLayer() {
        const el = document.getElementById(MAP_ID);
        if (!el || !el._leaflet_map_instance || !latestSeniorGeoJson) return;

        setStatus('Rendering...', 'neutral');
        setTimeout(() => {
            // Re-fetch the map inside the timeout: a Livewire navigation could
            // have torn down and recreated the map in the event-loop gap.
            const map = el._leaflet_map_instance;
            if (!map) return;
            Promise.resolve(renderDataLayers(map, latestSeniorGeoJson, latestFacilityGeoJson ?? emptyFeatureCollection()))
                .catch((error) => console.error('GIS render failed:', error));
        }, 0);
    }

    // ── Senior search (Name / System ID / OSCA-ID) ──────────────────────────
    // Operates entirely on the already-loaded latestSeniorGeoJson — no new
    // endpoint. Respects the same role-based coordinate precision as the map
    // (search results come from the same feature properties the pins use).

    const MAX_SEARCH_RESULTS = 8;

    function seniorSearchMatches(query) {
        const needle = query.trim().toLowerCase();
        if (!needle || !latestSeniorGeoJson?.features?.length) {
            return [];
        }

        return latestSeniorGeoJson.features
            .filter((feature) => {
                const props = feature.properties || {};
                const name = String(props.senior_name || '').toLowerCase();
                const oscaId = String(props.osca_id || '').toLowerCase();
                const officialOscaId = String(props.official_osca_id || '').toLowerCase();

                return name.includes(needle) || oscaId.includes(needle) || officialOscaId.includes(needle);
            })
            .slice(0, MAX_SEARCH_RESULTS);
    }

    function renderSeniorSearchResults(matches) {
        const list = document.getElementById(SEARCH_RESULTS_ID);
        if (!list) return;

        if (!matches.length) {
            list.innerHTML = '';
            list.classList.add('hidden');
            return;
        }

        list.innerHTML = matches.map((feature, index) => {
            const props = feature.properties || {};
            const name = escapeHtml(props.senior_name || 'Unnamed senior');
            const oscaId = escapeHtml(props.osca_id || `#${props.senior_id ?? 'N/A'}`);
            const barangay = escapeHtml(props.barangay || 'Unknown barangay');

            return `<li>
                <button type="button" data-gis-search-result="${index}"
                    class="w-full text-left px-3 py-2 hover:bg-paper-100 dark:hover:bg-[#242b27] transition-colors">
                    <span class="block font-medium text-ink-800 dark:text-[#d8ddd9]">${name}</span>
                    <span class="block text-[11.5px] text-ink-500 dark:text-[#8a958f] font-mono">${oscaId} &middot; ${barangay}</span>
                </button>
            </li>`;
        }).join('');
        list.classList.remove('hidden');

        list.querySelectorAll('[data-gis-search-result]').forEach((button) => {
            button.addEventListener('click', () => {
                const feature = matches[Number(button.dataset.gisSearchResult)];
                list.classList.add('hidden');
                const input = document.getElementById(SEARCH_INPUT_ID);
                if (input && feature) {
                    input.value = feature.properties?.senior_name || feature.properties?.osca_id || '';
                }
                if (feature) {
                    revealSeniorFeature(feature);
                }
            });
        });
    }

    function findSeniorMarkerLayer(rootLayer, seniorId) {
        if (!rootLayer || seniorId === undefined || seniorId === null) return null;

        let match = null;
        const inspect = (layer) => {
            if (match || !layer) return;

            if (layer._gisSeniorFeature?.properties?.senior_id === seniorId) {
                match = layer;
                return;
            }

            if (typeof layer.eachLayer === 'function') {
                layer.eachLayer(inspect);
            }
        };

        rootLayer.eachLayer(inspect);

        return match;
    }

    // Resolves a search result to its rendered marker and centers/opens it,
    // even if the senior is currently hidden by the barangay/risk/cluster
    // filters or tucked inside a marker cluster — search should always be
    // able to find a senior regardless of the current view.
    async function revealSeniorFeature(feature) {
        const map = document.getElementById(MAP_ID)?._leaflet_map_instance;
        if (!map || !feature) return;

        const seniorId = feature.properties?.senior_id;
        let filtersChanged = false;
        [BARANGAY_FILTER_ID, RISK_FILTER_ID, CLUSTER_FILTER_ID].forEach((id) => {
            const select = document.getElementById(id);
            if (select && select.value !== 'all') {
                select.value = 'all';
                filtersChanged = true;
            }
        });

        if (filtersChanged) {
            syncSecondaryFilter();
            setStatus('Rendering...', 'neutral');
            try {
                await renderDataLayers(map, latestSeniorGeoJson, latestFacilityGeoJson ?? emptyFeatureCollection());
            } catch (error) {
                console.error('GIS render failed:', error);
                return;
            }
        }

        const layers = ensureLayerRegistry(map);
        const marker = findSeniorMarkerLayer(layers.seniors, seniorId);
        if (!marker || typeof marker.getLatLng !== 'function') {
            setStatus('Could not locate that senior on the map.', 'error');
            return;
        }

        const revealAndOpen = () => {
            map.setView(marker.getLatLng(), Math.max(map.getZoom(), 17), { animate: true });
            marker.openPopup();
        };

        const clusterLayer = layers.seniors.getLayers().find((layer) => typeof layer.zoomToShowLayer === 'function');
        if (clusterLayer) {
            clusterLayer.zoomToShowLayer(marker, revealAndOpen);
        } else {
            revealAndOpen();
        }
    }

    function initSeniorSearch() {
        const input = document.getElementById(SEARCH_INPUT_ID);
        const list = document.getElementById(SEARCH_RESULTS_ID);
        if (!input || !list || input.dataset.gisSearchBound) return;
        input.dataset.gisSearchBound = 'true';

        const debouncedSearch = debounce(() => {
            renderSeniorSearchResults(seniorSearchMatches(input.value));
        }, 150);

        input.addEventListener('input', debouncedSearch);
        input.addEventListener('focus', () => {
            if (input.value.trim()) {
                renderSeniorSearchResults(seniorSearchMatches(input.value));
            }
        });
        document.addEventListener('click', (event) => {
            if (!list.contains(event.target) && event.target !== input) {
                list.classList.add('hidden');
            }
        });
    }

    function syncMapSize(map) {
        if (!map) return;

        map.invalidateSize({
            pan: false,
            debounceMoveend: true,
        });
    }

    function scheduleMapSizeSync(map) {
        window.requestAnimationFrame(() => syncMapSize(map));
        window.setTimeout(() => syncMapSize(map), 120);
        window.setTimeout(() => syncMapSize(map), 280);
    }

    function attachResizeObserver(map, el) {
        if (el._gisResizeObserver) {
            el._gisResizeObserver.disconnect();
        }

        if (typeof ResizeObserver === 'undefined') {
            return;
        }

        const observer = new ResizeObserver(() => syncMapSize(map));
        observer.observe(el);
        el._gisResizeObserver = observer;
    }

    async function fetchGeoJson(url, requestId, label, fallbackPayload = null) {
        const response = await fetch(url, {
            headers: {
                'Accept': 'application/json',
            },
        });

        if (requestId !== latestRequestId) {
            throw new Error(`Stale ${label} GIS request ignored.`);
        }

        const contentType = response.headers.get('content-type') || '';
        if (!response.ok) {
            const body = await response.text();
            if (fallbackPayload) {
                console.warn(`${label} GIS request failed with status ${response.status}. Falling back to empty layer.`, body.slice(0, 200));
                return fallbackPayload;
            }

            throw new Error(`${label} GIS API request failed with status ${response.status}. Body: ${body.slice(0, 200)}`);
        }

        if (!isAcceptedGeoJsonType(contentType)) {
            const body = await response.text();
            if (fallbackPayload) {
                console.warn(`${label} GIS API returned non-JSON content-type "${contentType}". Falling back to empty layer.`, body.slice(0, 200));
                return fallbackPayload;
            }

            throw new Error(`${label} GIS API returned non-JSON content-type "${contentType}". Body: ${body.slice(0, 200)}`);
        }

        const payload = await response.json();
        const geojson = normalizeGeoJsonPayload(payload);
        if (!geojson) {
            if (fallbackPayload) {
                console.warn(`${label} GIS API returned an invalid payload. Falling back to empty layer.`, payload);
                return fallbackPayload;
            }

            throw new Error(`${label} GIS API returned an invalid GeoJSON FeatureCollection.`);
        }

        return geojson;
    }

    /**
     * Lightweight overview layer for aggregate mode: one circle bubble per
     * barangay (radius by senior count, color by dominant risk), built from
     * GisApiController's SQL-only aggregate feed. Deliberately does not touch
     * renderDataLayers/prevalidateAllFeatures/initializeFilters — those assume
     * full per-senior features and only run once upgradeToFullSeniorDetail()
     * fetches the real per-senior dataset.
     */
    function renderAggregateBubbles(map, aggregateGeoJson) {
        if (seniorAggregateLayerGroup) {
            map.removeLayer(seniorAggregateLayerGroup);
            seniorAggregateLayerGroup = null;
        }

        const features = aggregateGeoJson.features || [];
        const maxCount = Math.max(1, ...features.map((f) => f.properties?.senior_count || 0));
        const layers = features.map((feature) => {
            const props = feature.properties || {};
            const [lng, lat] = feature.geometry.coordinates;
            const count = props.senior_count || 0;
            // Area-proportional radius (sqrt of count) so bubble size reads as
            // "amount of seniors", not a linear (visually misleading) scale.
            const radius = 10 + Math.round(28 * Math.sqrt(count / maxCount));

            const marker = window.L.circleMarker([lat, lng], {
                radius,
                weight: 2,
                color: '#ffffff',
                fillColor: riskColor(props.dominant_risk),
                fillOpacity: 0.75,
                renderer: getCanvasRenderer(map),
                pane: 'gis-senior-pane',
            });

            marker.bindPopup(`
                <div class="text-sm">
                    <div class="font-semibold mb-1">${escapeHtml(props.barangay || 'Unknown barangay')}</div>
                    <div>${count.toLocaleString()} senior${count === 1 ? '' : 's'}</div>
                    <div class="text-xs mt-1 space-y-0.5">
                        <div>High risk: ${(props.high_risk_count || 0).toLocaleString()}</div>
                        <div>Moderate risk: ${(props.moderate_risk_count || 0).toLocaleString()}</div>
                        <div>Low risk: ${(props.low_risk_count || 0).toLocaleString()}</div>
                    </div>
                    <button type="button" class="gis-bubble-drilldown mt-2 text-xs underline underline-offset-2 text-forest-700 dark:text-forest-400"
                        data-barangay="${escapeHtml(props.barangay || '')}">
                        View individual seniors &rarr;
                    </button>
                </div>
            `);

            return marker;
        });

        seniorAggregateLayerGroup = window.L.layerGroup(layers).addTo(map);

        // Popup drill-down button: pick this barangay in the filter and fetch
        // full per-senior detail. Delegated (popups are created/destroyed by
        // Leaflet, so a direct listener would be lost on re-open).
        map.getContainer().addEventListener('click', function (event) {
            const btn = event.target.closest?.('.gis-bubble-drilldown');
            if (!btn) return;
            const barangaySelect = document.getElementById(BARANGAY_FILTER_ID);
            if (barangaySelect && btn.dataset.barangay) {
                barangaySelect.value = btn.dataset.barangay;
            }
            map.closePopup();
            upgradeToFullSeniorDetail(map);
        });

        setStatus(`Showing barangay overview (${features.reduce((sum, f) => sum + (f.properties?.senior_count || 0), 0).toLocaleString()} seniors). Pick a barangay or click a bubble for individual markers.`, 'neutral');
    }

    /**
     * Fetches the full per-senior GeoJSON once and hands off to the existing,
     * unchanged rendering pipeline. Safe to call multiple times — no-ops after
     * the first successful upgrade or while one is already in flight.
     */
    async function upgradeToFullSeniorDetail(map) {
        if (seniorDetailMode === 'full' || seniorDetailUpgrading) return;

        // Defense in depth: a per-senior fetch is only ever safe when scoped to
        // one barangay (a few hundred rows). An unscoped fetch across all
        // barangays is exactly the ~10k-record request that crashes under the
        // default PHP memory_limit (see GisApiController::seniors()) — refuse
        // it here even if some future caller forgets to check first.
        const barangay = selectedBarangay();
        if (barangay === 'all') {
            console.warn('upgradeToFullSeniorDetail() called without a specific barangay selected; refusing unscoped fetch.');
            return;
        }

        seniorDetailUpgrading = true;

        const el = document.getElementById(MAP_ID);
        if (!el) { seniorDetailUpgrading = false; return; }

        setStatus('Loading individual senior markers...', 'neutral');
        const requestId = ++latestRequestId;
        const seniorUrl = el.dataset.geojsonUrl + (el.dataset.geojsonUrl.includes('?') ? '&' : '?') + 'barangay=' + encodeURIComponent(barangay);

        try {
            const seniorGeoJson = await fetchGeoJson(seniorUrl, requestId, 'Senior');
            if (requestId !== latestRequestId) return;

            if (seniorAggregateLayerGroup) {
                map.removeLayer(seniorAggregateLayerGroup);
                seniorAggregateLayerGroup = null;
            }

            latestSeniorGeoJson = seniorGeoJson;
            seniorDetailMode = 'full';
            prevalidateAllFeatures(seniorGeoJson.features || []);
            initializeFilters(seniorGeoJson.features || []);
            await Promise.resolve(renderDataLayers(map, seniorGeoJson, latestFacilityGeoJson ?? emptyFeatureCollection()));
            setMapLoading(false);
        } catch (error) {
            console.error('Failed to load individual senior markers:', error);
            setStatus('Could not load individual senior markers.', 'error');
        } finally {
            seniorDetailUpgrading = false;
        }
    }

    // Tears down a previous Leaflet instance on `el`, if any. Layers defined
    // elsewhere in this file (heatmap canvas overlays, etc.) have hand-written
    // onRemove() handlers — a throw from any of them used to abort map.remove()
    // partway through and skip everything below it (resize observer, the
    // window.__oscaMaps registry, clearing the container), leaving a dead map
    // instance and its listeners running against a detached element. The
    // try/catch isolates that failure to just the Leaflet-internal teardown;
    // the app-level cleanup after it always runs regardless.
    function destroyMap(el) {
        if (!el?._leaflet_id) return;

        const map = el._leaflet_map_instance;
        if (map) {
            try {
                map.off();
                map.remove();
            } catch (error) {
                console.warn('GIS map teardown threw (continuing cleanup):', error);
            }
            if (window.__oscaMaps) {
                const idx = window.__oscaMaps.indexOf(map);
                if (idx !== -1) window.__oscaMaps.splice(idx, 1);
            }
        }
        if (el._gisResizeObserver) {
            el._gisResizeObserver.disconnect();
            el._gisResizeObserver = null;
        }
        el._leaflet_map_instance = null;
        el.innerHTML = '';
    }

    function renderMap() {
        const el = document.getElementById(MAP_ID);
        if (!el || !window.L) { setMapLoading(false); return; }
        const requestId = ++latestRequestId;
        latestSeniorGeoJson = null;
        latestFacilityGeoJson = null;
        latestMunicipalBoundaryGeoJson = null;
        latestBarangayBoundaryGeoJson = null;
        latestRouteDistanceUrl = el.dataset.routeDistanceUrl || null;
        seniorDetailMode = 'pending';
        seniorDetailUpgrading = false;
        seniorAggregateLayerGroup = null;
        setStatus('Loading GIS layers for Pagsanjan...', 'neutral');
        setMapLoading(true);

        destroyMap(el);

        const map = window.L.map(el, {
            minZoom: MIN_ZOOM,
            maxZoom: MAX_ZOOM,
            zoomControl: true,
            zoomSnap: 0.5,
            zoomDelta: 0.5,
            zoomAnimation: false,
            fadeAnimation: false,
            markerZoomAnimation: false,
            preferCanvas: true,
            // Default canvas padding is only 0.1, so the canvas barely extends past
            // the viewport and its edge can appear as a square while dragging/zooming.
            // Enlarge it so canvas-rendered vectors always cover the screen.
            renderer: window.L.canvas({ padding: 0.5 }),
        }).setView(PAGSANJAN_CENTER, DEFAULT_ZOOM);
        (window.__oscaMaps = window.__oscaMaps || []).push(map);
        el._leaflet_map_instance = map;
        ensureMapPanes(map);
        ensureLayerRegistry(map);
        applyMapBoundaryConstraints(map);
        applyMapZoomConstraints(map);

        createTileLayer().addTo(map);
        createRecenterControl(map).addTo(map);
        document.getElementById('gis-recenter-btn')?.addEventListener('click', () => {
            focusMapOnPagsanjan(map);
        });

        map.on('zoomend moveend', debounce(() => {
            refreshHeatmapLayersForZoom(map);
            // Re-cut the exterior mask to the new viewport so it keeps covering the
            // screen (its outer ring is viewport-sized to stay canvas-safe).
            refreshMunicipalMask(map);
        }, 150));
        map.on('click', (event) => {
            if (openSeniorPopupAt(map, event)) {
                return;
            }
            openBarangayPopupAt(map, event.latlng);
        });

        focusMapOnPagsanjan(map);
        attachResizeObserver(map, el);
        scheduleMapSizeSync(map);

        // Initial load requests the cheap barangay-level aggregate (default
        // barangay filter is always 'all' on first paint — see BARANGAY_FILTER_ID's
        // markup). Individual senior markers are fetched on demand via
        // upgradeToFullSeniorDetail() once the user asks for them.
        const seniorUrl = el.dataset.geojsonUrl + (el.dataset.geojsonUrl.includes('?') ? '&' : '?') + 'aggregate=1';

        Promise.all([
            fetchGeoJson(seniorUrl, requestId, 'Senior'),
            fetchGeoJson(el.dataset.facilitiesUrl, requestId, 'Facility', emptyFeatureCollection('database')),
            fetchGeoJson(el.dataset.pagsanjanBoundaryUrl, requestId, 'Pagsanjan boundary', emptyFeatureCollection('file')),
            fetchGeoJson(el.dataset.barangayBoundariesUrl, requestId, 'Barangay boundaries', emptyFeatureCollection('file')),
        ])
            .then(([seniorGeoJson, facilityGeoJson, municipalBoundaryGeoJson, barangayBoundaryGeoJson]) => {
                if (requestId !== latestRequestId) return;

                latestFacilityGeoJson = facilityGeoJson;
                latestMunicipalBoundaryGeoJson = municipalBoundaryGeoJson;
                latestBarangayBoundaryGeoJson = barangayBoundaryGeoJson;
                renderBoundaryLayers(map, municipalBoundaryGeoJson, barangayBoundaryGeoJson);
                applyMapBoundaryConstraints(map);
                applyMapZoomConstraints(map);

                if (seniorGeoJson.metadata?.aggregation === 'barangay_aggregate') {
                    seniorDetailMode = 'aggregate';
                    setSelectOptions(BARANGAY_FILTER_ID, 'All Barangays', uniqueSortedValues(seniorGeoJson.features || [], 'barangay'));
                    renderAggregateBubbles(map, seniorGeoJson);
                    setMapLoading(false);
                } else {
                    // Fallback path (server declined aggregation, e.g. a barangay
                    // filter was already active): unchanged full-detail rendering.
                    latestSeniorGeoJson = seniorGeoJson;
                    seniorDetailMode = 'full';
                    prevalidateAllFeatures(seniorGeoJson.features || []);
                    initializeFilters(seniorGeoJson.features || []);
                    Promise.resolve(renderDataLayers(map, seniorGeoJson, facilityGeoJson))
                        .then(() => setMapLoading(false))
                        .catch((error) => {
                            setMapLoading(false);
                            console.error('GIS render failed:', error);
                        });
                }
                scheduleMapSizeSync(map);
            })
            .catch((error) => {
                if (requestId !== latestRequestId) return;

                setMapLoading(false);
                console.error('Failed to load GIS data:', error);
                setStatus('GIS data could not be loaded.', 'error');
            });
    }

    const debouncedRefresh = debounce(() => refreshRenderedLayer(), 120);
    const bootMap = () => window.OSCA.maps().then(() => {
        renderMap();
        initSeniorSearch();
    });

    // Persistent (document/window-level) registrations guarded so they bind
    // once per page session — the whole <script> tag re-executes on every
    // wire:navigate navigation, and every handler below re-reads the map
    // element / its dataset fresh via getElementById() at call time (renderMap
    // re-fetches GeoJSON live, refreshRenderedLayer and applyThemeToMap look up
    // the current Leaflet instance), so one bound listener/observer keeps
    // working correctly across every future navigation.
    if (!window.__oscaBound_gisMap) {
        window.__oscaBound_gisMap = true;

        document.addEventListener('change', function (event) {
            if ([MODE_ID, BARANGAY_FILTER_ID, RISK_FILTER_ID, CLUSTER_FILTER_ID, CLUSTER_POINTS_TOGGLE_ID, SHOW_HEATMAP_SENIOR_POINTS_ID, SHOW_RISK_SENIOR_POINTS_ID, SHOW_SENIOR_POINTS_TOGGLE_ID, SHOW_BARANGAY_DENSITY_TOGGLE_ID].includes(event.target?.id)) {
                if (event.target?.id === MODE_ID) {
                    // Swap the adaptive Risk/Health-Group filter immediately so the
                    // control updates before the debounced re-render runs.
                    syncSecondaryFilter();
                }

                // Still showing barangay bubbles (no per-senior data fetched yet).
                // Only a genuine barangay pick justifies a full-detail fetch — it's
                // bounded to that one barangay's seniors (a few hundred, safe).
                // Risk/cluster/mode/toggle changes have nothing to act on yet in
                // aggregate mode and must NOT trigger an unscoped fetch of every
                // senior (the exact 10k-record memory crash this fix exists to
                // avoid) — debouncedRefresh() below safely no-ops for them since
                // latestSeniorGeoJson is still null.
                if (seniorDetailMode === 'aggregate' && event.target?.id === BARANGAY_FILTER_ID && selectedBarangay() !== 'all') {
                    const map = document.getElementById(MAP_ID)?._leaflet_map_instance;
                    if (map) upgradeToFullSeniorDetail(map);

                    return;
                }

                debouncedRefresh();
            }
        });
        document.addEventListener('DOMContentLoaded', bootMap);
        document.addEventListener('livewire:navigated', () => setTimeout(bootMap, 0));
        // Without this, leaving GIS left the map instance, its resize
        // observer, and the window resize/theme listeners below all still
        // firing against a container that wire:navigate had already removed
        // from the DOM — renderMap()'s own teardown only runs on the *next*
        // visit to this page, not on navigate-away. destroyMap() no-ops
        // harmlessly if the map element is already gone.
        document.addEventListener('livewire:navigating', () => {
            destroyMap(document.getElementById(MAP_ID));
        });
        window.addEventListener('resize', () => {
            const map = document.getElementById(MAP_ID)?._leaflet_map_instance;
            syncMapSize(map);
        });

        const themeObserver = new MutationObserver(() => {
            const map = document.getElementById(MAP_ID)?._leaflet_map_instance;
            if (map) {
                applyThemeToMap(map);
            }
        });
        themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    }
})();
</script>
@endpush
