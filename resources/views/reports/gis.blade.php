@extends('layouts.app')
@section('page-title', 'GIS Analytics')
@section('page-subtitle', 'Spatial visibility for senior distribution and community accessibility context')

@section('content')
<div class="space-y-5">

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        @php
        $gisCards = [
            ['id' => 'gis-stat-total',    'label' => 'Total Mapped Seniors', 'value' => $stats['mapped_seniors'],   'rule' => 'bg-low-500',      'caption' => 'Current visible records'],
            ['id' => 'gis-stat-high-risk','label' => 'High Risk Seniors',    'value' => $stats['high_risk_mapped'], 'rule' => 'bg-high-500',     'caption' => 'Current visible records'],
            ['id' => 'gis-stat-barangays','label' => 'Barangays Covered',    'value' => $stats['barangays_covered'],'rule' => 'bg-info-500',     'caption' => 'Distinct visible barangays'],
            ['id' => 'gis-stat-source',   'label' => 'Data Source',          'value' => 'Loading',                  'rule' => 'bg-forest-500',   'caption' => 'API-driven GIS source'],
        ];
        @endphp
        @foreach ($gisCards as $card)
        <div class="kpi">
            <div class="kpi-rule {{ $card['rule'] }}"></div>
            <div class="kpi-label">{{ $card['label'] }}</div>
            <div id="{{ $card['id'] }}" class="kpi-value">{{ is_numeric($card['value']) ? number_format($card['value']) : $card['value'] }}</div>
            <div class="kpi-delta">{{ $card['caption'] }}</div>
        </div>
        @endforeach
    </div>

    <div class="card card-body py-3">
        <p class="eyebrow">Prototype Note</p>
        <p class="text-sm text-ink-700 dark:text-[#b0b5b2] mt-1 leading-relaxed">Each senior is visualized as a generalized point within their recorded barangay because available address data only contains barangay information. Points do not represent exact household locations.</p>
    </div>

        
    @php
        $geocodeTone = match ($geocodeStatus['status'] ?? 'Pending') {
            'Completed' => 'text-low-700 bg-low-50 border-low-200',
            'Needs Update' => 'text-moderate-700 bg-moderate-50 border-moderate-200',
            default => 'text-high-700 bg-high-50 border-high-200',
        };
    @endphp
    <div class="card card-body">
        <div class="flex flex-col gap-3">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2 min-w-0">
                    <p class="eyebrow">Bulk Geocode Status</p>
                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $geocodeTone }}">
                        {{ $geocodeStatus['status'] }}
                    </span>
                </div>
                @role('admin')
                <form method="POST" action="{{ route('reports.gis.geocode') }}"
                      class="shrink-0 sm:ml-auto"
                      onsubmit="return confirm('Run bulk barangay-level geocoding now? This will not overwrite verified manual/GPS coordinates.');">
                    @csrf
                    <button type="submit" class="btn text-[12px] px-3 py-2 whitespace-nowrap">
                        Run Bulk Geocode
                    </button>
                </form>
                @endrole
            </div>
            <div class="border-t border-paper-rule dark:border-[#2b3530]"></div>
            <p class="text-sm text-ink-700 dark:text-[#b0b5b2] leading-relaxed">
                Bulk geocoding assigns approximate coordinates inside each senior's barangay so records can be mapped for barangay-level planning. These are not exact home locations.
            </p>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-6 gap-3 mt-4 text-sm">
            <div>
                <div class="text-[11px] uppercase tracking-wide text-ink-400">Coordinate Mode</div>
                <div class="font-semibold text-ink-800 dark:text-[#d8ddd9]">{{ $geocodeStatus['coordinate_mode'] }}</div>
            </div>
            <div>
                <div class="text-[11px] uppercase tracking-wide text-ink-400">Total Seniors</div>
                <div class="font-semibold text-ink-800 dark:text-[#d8ddd9]">{{ number_format($geocodeStatus['total_seniors']) }}</div>
            </div>
            <div>
                <div class="text-[11px] uppercase tracking-wide text-ink-400">Approximate</div>
                <div class="font-semibold text-ink-800 dark:text-[#d8ddd9]">{{ number_format($geocodeStatus['approximate_coordinates']) }}</div>
            </div>
            <div>
                <div class="text-[11px] uppercase tracking-wide text-ink-400">Verified/Manual</div>
                <div class="font-semibold text-ink-800 dark:text-[#d8ddd9]">{{ number_format($geocodeStatus['verified_coordinates']) }}</div>
            </div>
            <div>
                <div class="text-[11px] uppercase tracking-wide text-ink-400">Missing</div>
                <div class="font-semibold text-ink-800 dark:text-[#d8ddd9]">{{ number_format($geocodeStatus['missing_coordinates']) }}</div>
            </div>
            <div>
                <div class="text-[11px] uppercase tracking-wide text-ink-400">Last Run</div>
                <div class="font-semibold text-ink-800 dark:text-[#d8ddd9]">{{ $geocodeStatus['last_run_at'] ?? 'Not recorded' }}</div>
            </div>
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="card-head">
            <div>
                <div class="card-title">Senior Citizen Spatial Distribution</div>
                <div class="card-sub">Generalized senior distribution and accessibility context within Pagsanjan</div>
            </div>
            <span class="text-[11.5px] text-ink-400 dark:text-[#6b7570] whitespace-nowrap">Centered on Pagsanjan, Laguna</span>
        </div>

        <div class="card-body space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
                <label class="block">
                    <span class="eyebrow block mb-1.5">Visualization</span>
                    <select id="gis-visualization-mode" class="form-select">
                        <option value="markers">Senior Distribution Points</option>
                        <option value="density-heatmap">Barangay-Level Senior Heatmap</option>
                        <option value="risk-heatmap">Generalized Barangay-Based Heatmap</option>
                        <option value="accessibility-heatmap">Senior Distribution and Accessibility Heatmap</option>
                        <option value="barangay-density">Barangay Density View</option>
                        <option value="risk-indicator-heatmap">Risk Indicator Distribution</option>
                        <option value="cluster-heatmap">Health Group Cluster Distribution</option>
                    </select>
                </label>
                <label class="block">
                    <span class="eyebrow block mb-1.5">Barangay</span>
                    <select id="gis-barangay-filter" class="form-select">
                        <option value="all">All Barangays</option>
                    </select>
                </label>
                <label class="block">
                    <span class="eyebrow block mb-1.5">Risk Level</span>
                    <select id="gis-risk-filter" class="form-select">
                        <option value="all">All Risk Levels</option>
                        <option value="low">Low</option>
                        <option value="moderate">Moderate</option>
                        <option value="high">High</option>
                    </select>
                </label>
                <label class="block">
                    <span class="eyebrow block mb-1.5">Cluster / Health Group</span>
                    <select id="gis-cluster-filter" class="form-select">
                        <option value="all">All Groups</option>
                    </select>
                </label>
            </div>

            <div class="border border-paper-rule dark:border-[#2b3530] rounded-lg px-3 py-2">
                <div class="eyebrow mb-2">KDE Heatmap Overlays</div>
                <div class="flex flex-wrap gap-x-4 gap-y-2 text-[12px] text-ink-600 dark:text-[#b0b5b2]">
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" class="rounded border-paper-rule text-forest-700 focus:ring-forest-700" data-gis-kde-overlay value="risk-indicator-heatmap">
                        <span>Risk Distribution Heatmap</span>
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" class="rounded border-paper-rule text-forest-700 focus:ring-forest-700" data-gis-kde-overlay value="cluster-heatmap">
                        <span>Health Group / Cluster Heatmap</span>
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" class="rounded border-paper-rule text-forest-700 focus:ring-forest-700" data-gis-kde-overlay value="accessibility-heatmap">
                        <span>Accessibility / Facility Proximity Heatmap</span>
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input id="gis-cluster-points-toggle" type="checkbox" class="rounded border-paper-rule text-forest-700 focus:ring-forest-700" checked>
                        <span>Show cluster senior points</span>
                    </label>
                </div>
            </div>

            <div id="gis-map"
                 class="rounded-2xl border border-paper-rule dark:border-[#2b3530] bg-paper-2 dark:bg-[#1a201d] min-h-[420px] md:min-h-[460px]"
                 data-geojson-url="{{ route('api.gis.seniors', [], false) }}"
                 data-facilities-url="{{ route('api.gis.facilities', [], false) }}"
                 data-route-distance-url="{{ route('api.gis.route-distance', [], false) }}"
                 data-pagsanjan-boundary-url="{{ route('api.gis.boundary.pagsanjan', [], false) }}"
                 data-barangay-boundaries-url="{{ route('api.gis.boundary.barangays', [], false) }}">
            </div>
            <div>
                <p id="gis-map-status" class="text-[11.5px] text-ink-400 dark:text-[#6b7570]">Loading barangay-level GIS data...</p>
            </div>
            <div id="gis-map-legend" class="flex flex-wrap items-center gap-x-4 gap-y-2 text-[11.5px] text-ink-500 dark:text-[#6b7570]">
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-low-500 inline-block"></span>Low</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-moderate-500 inline-block"></span>Moderate</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-high-500 inline-block"></span>High</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-info-500 inline-block"></span>Facilities</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-moderate-100 inline-block"></span>Outer Zone</span>
            </div>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
(function () {
    const MAP_ID = 'gis-map';
    const STATUS_ID = 'gis-map-status';
    const MODE_ID = 'gis-visualization-mode';
    const BARANGAY_FILTER_ID = 'gis-barangay-filter';
    const RISK_FILTER_ID = 'gis-risk-filter';
    const CLUSTER_FILTER_ID = 'gis-cluster-filter';
    const CLUSTER_POINTS_TOGGLE_ID = 'gis-cluster-points-toggle';
    const KDE_OVERLAY_SELECTOR = '[data-gis-kde-overlay]';
    const LEGEND_ID = 'gis-map-legend';
    const TOTAL_STAT_ID = 'gis-stat-total';
    const HIGH_RISK_STAT_ID = 'gis-stat-high-risk';
    const BARANGAY_STAT_ID = 'gis-stat-barangays';
    const SOURCE_STAT_ID = 'gis-stat-source';
    const PAGSANJAN_CENTER = [14.2708, 121.4560];
    const DEFAULT_ZOOM = 15;
    const MIN_ZOOM = 8;
    const MAX_ZOOM = 18;
    const DEFAULT_FOCUS_BOUNDS_COORDS = [
        [14.2598, 121.4442],
        [14.2824, 121.4668],
    ];
    const NAVIGATION_BOUNDS_COORDS = [
        [14.2555, 121.4395],
        [14.2868, 121.4715],
    ];
    const MAP_FIT_OPTIONS = {
        padding: [18, 18],
        maxZoom: 15,
        animate: false,
    };
    const MUNICIPAL_FOCUS_PADDING_RATIO = 0.03;
    const MUNICIPAL_NAVIGATION_PADDING_RATIO = 1.25;
    const HEATMAP_MODES = new Set([
        'density-heatmap',
        'risk-heatmap',
        'accessibility-heatmap',
        'risk-indicator-heatmap',
        'cluster-heatmap',
    ]);
    const RISK_HEATMAP_GRADIENT = {
        0.12: '#22c55e',
        0.48: '#facc15',
        0.76: '#fb923c',
        1.00: '#ef4444',
    };
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
    const CLUSTER_HEATMAP_GRADIENT = {
        0.00: '#253494',
        0.10: '#2166ac',
        0.22: '#1d91c0',
        0.34: '#41b6c4',
        0.46: '#35d07f',
        0.58: '#a6e22e',
        0.70: '#fff238',
        0.80: '#fdae21',
        0.90: '#f46d43',
        1.00: '#d7191c',
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
            name: 'Cluster 1',
            stops: {
                0.00: '#dff7ff',
                0.14: '#aeeeff',
                0.32: '#67d8ff',
                0.52: '#22b8ee',
                0.70: '#0796d6',
                0.86: '#0077b6',
                1.00: '#005f99',
            },
        },
        2: {
            label: 'C2',
            name: 'Cluster 2',
            stops: {
                0.00: '#e5ffe9',
                0.14: '#b9f8c7',
                0.32: '#74eba0',
                0.52: '#35d676',
                0.70: '#16b957',
                0.86: '#079640',
                1.00: '#057a35',
            },
        },
        3: {
            label: 'C3',
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
    const ROUTE_SERVICE_CANDIDATE_LIMIT = 5;
    const ROUTE_SERVICE_RESULT_LIMIT = ROUTE_SERVICE_CANDIDATE_LIMIT;
    const SENIOR_RELEVANT_FACILITY_PRIORITY = [
        ['health center', 'hospital', 'clinic', 'rural health'],
        ['pharmacy', 'drugstore', 'medicine'],
        ['senior center', 'senior citizens', 'osca'],
        ['barangay hall', 'municipal hall'],
        ['public market', 'market', 'transport hub', 'terminal'],
        ['church', 'chapel'],
    ];
    const routeDistanceCache = new Map();
    const warnedInvalidClusterValues = new Set();
    let latestRequestId = 0;
    let latestSeniorGeoJson = null;
    let latestFacilityGeoJson = null;
    let latestMunicipalBoundaryGeoJson = null;
    let latestBarangayBoundaryGeoJson = null;
    let latestRouteDistanceUrl = null;

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
        switch ((level || '').toUpperCase()) {
            case 'HIGH':
                return 1.0;
            case 'MODERATE':
                return 0.6;
            case 'LOW':
                return 0.3;
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
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full border-2 border-dashed border-teal-500 bg-white inline-block"></span>Generalized barangay point</span>
                <span class="inline-flex items-center gap-2 min-w-[260px]">
                    <span>Lower count</span>
                    <span class="h-2.5 w-28 rounded-full inline-block border border-white/70" style="background:linear-gradient(90deg,#dbeafe 0%,#38bdf8 35%,#facc15 68%,#ef4444 100%);"></span>
                    <span>Higher count</span>
                </span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-[3px] bg-sky-600 inline-block rotate-45"></span>Facility</span>
                ${boundaryLegend}
            `;
            return;
        }

        const heatmapLabels = {
            'density-heatmap': ['Barangay-Level Senior Heatmap', 'Low concentration', 'High concentration'],
            'risk-heatmap': ['Generalized Barangay-Based Heatmap', 'Low risk intensity', 'High risk intensity'],
            'accessibility-heatmap': ['Senior Distribution and Accessibility Heatmap', 'Better access', 'Greater access need'],
            'risk-indicator-heatmap': ['Risk Indicator Distribution', 'Lower risk indicator', 'Higher risk indicator'],
            'cluster-heatmap': ['Health Group Cluster Distribution', 'Assigned group color', 'Stronger local concentration'],
        };
        const heatmapLabel = heatmapLabels[mode];
        const gradient = 'linear-gradient(90deg,#22c55e 0%,#facc15 48%,#fb923c 76%,#ef4444 100%)';

        if (heatmapLabel) {
            if (mode === 'cluster-heatmap') {
                const clusterLegend = Object.values(CLUSTER_HEATMAP_RAMPS).map((ramp) => `
                    <span class="inline-flex items-center gap-1.5">
                        <span class="h-2.5 w-10 rounded-full inline-block border border-white/70" style="background:${gradientCss(ramp.stops)};"></span>${ramp.label}
                    </span>
                `).join('');
                const selectedCluster = selectedClusterGroup();
                const selectedClusterScale = selectedCluster === 'all'
                    ? `<span class="inline-flex items-center gap-2 min-w-[320px]">
                        <span>Lower local cluster density</span>
                        <span class="h-3 w-40 rounded-full inline-block border border-white/70" style="background:${gradientCss(CLUSTER_HEATMAP_GRADIENT)};"></span>
                        <span>Higher local cluster density</span>
                    </span>`
                    : `<span class="inline-flex items-center gap-2 min-w-[320px]">
                        <span>Lower intensity within selected cluster</span>
                        <span class="h-3 w-40 rounded-full inline-block border border-white/70" style="background:${gradientCss(clusterGradientForLabel(selectedCluster))};"></span>
                        <span>${clusterLegendLabel(selectedCluster)}</span>
                    </span>`;

                legendEl.innerHTML = `
                    <span class="font-semibold text-ink-700 dark:text-[#b0b5b2]">${heatmapLabel[0]}</span>
                    ${selectedClusterScale}
                    ${clusterLegend}
                    <span class="text-ink-400 dark:text-[#6b7570]">${selectedCluster === 'all'
                        ? 'All groups are shown as a continuous KDE heatmap surface. Each pixel keeps the locally strongest health group color without blending groups. Markers show the actual senior health group.'
                        : 'Selected Group view shows only the chosen group distribution. Contour lines represent equal KDE density levels. Markers show the actual senior health group.'}</span>
                    <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-sky-600 inline-block"></span>Facilities</span>
                    ${boundaryLegend}
                `;
                return;
            }

            legendEl.innerHTML = `
                <span class="font-semibold text-ink-700 dark:text-[#b0b5b2]">${heatmapLabel[0]}</span>
                <span class="inline-flex items-center gap-2 min-w-[260px]">
                    <span>${heatmapLabel[1]}</span>
                    <span class="h-2.5 w-28 rounded-full inline-block border border-white/70" style="background:${gradient};"></span>
                    <span>${heatmapLabel[2]}</span>
                </span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-sky-600 inline-block"></span>Facilities</span>
                ${boundaryLegend}
            `;
            return;
        }

        if (mode === 'barangay-density') {
            legendEl.innerHTML = `
                <span class="font-semibold text-ink-700 dark:text-[#b0b5b2]">Barangay Density View</span>
                <span class="inline-flex items-center gap-2 min-w-[260px]">
                    <span>Lower count</span>
                    <span class="h-2.5 w-28 rounded-full inline-block border border-white/70" style="background:linear-gradient(90deg,#dbeafe 0%,#38bdf8 35%,#facc15 68%,#ef4444 100%);"></span>
                    <span>Higher count</span>
                </span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-sky-600 inline-block"></span>Facilities</span>
                ${boundaryLegend}
            `;
            return;
        }

        legendEl.innerHTML = `
            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500 inline-block"></span>Low</span>
            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-500 inline-block"></span>Moderate</span>
            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-orange-500 inline-block"></span>High</span>
            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-sky-600 inline-block"></span>Facilities</span>
            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-rose-400 inline-block"></span>Outer Zone</span>
            ${boundaryLegend}
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

        const currentValue = select.value || 'all';
        select.innerHTML = `<option value="all">${defaultLabel}</option>`;

        values.forEach((value) => {
            const option = document.createElement('option');
            option.value = String(value);
            option.textContent = String(value);
            select.appendChild(option);
        });

        select.value = values.includes(currentValue) ? currentValue : 'all';
    }

    function initializeFilters(features) {
        setSelectOptions(BARANGAY_FILTER_ID, 'All Barangays', uniqueSortedValues(features, 'barangay'));
        setSelectOptions(RISK_FILTER_ID, 'All Risk Levels', uniqueSortedValues(features, 'risk_level'));
        setSelectOptions(CLUSTER_FILTER_ID, 'All Groups', uniqueSortedClusterValues(features));
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

    function shouldShowClusterSeniorPoints() {
        return document.getElementById(CLUSTER_POINTS_TOGGLE_ID)?.checked !== false;
    }

    function selectedKdeOverlayModes() {
        return [...document.querySelectorAll(`${KDE_OVERLAY_SELECTOR}:checked`)]
            .map((input) => input.value)
            .filter((mode) => ['risk-indicator-heatmap', 'cluster-heatmap', 'accessibility-heatmap'].includes(mode));
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

    function accessibilityNeedWeight(properties) {
        const proximityScore = numericValue(properties?.gis_proximity_score);
        if (proximityScore !== null) {
            return clampUnit(1 - (proximityScore / 100));
        }

        const accessibilityScore = numericValue(properties?.accessibility_score);
        if (accessibilityScore !== null) {
            const normalizedScore = accessibilityScore <= 1 ? accessibilityScore : accessibilityScore / 100;
            return clampUnit(1 - normalizedScore);
        }

        const nearestDistance = numericValue(properties?.nearest_facility_distance_m);
        if (nearestDistance !== null) {
            return clampUnit(nearestDistance / ACCESSIBILITY_DISTANCE_CAP_METERS);
        }

        return null;
    }

    function accessibilityStatus(score) {
        if (score === null || score === undefined || !Number.isFinite(Number(score))) {
            return 'No accessibility score available';
        }

        const value = Number(score);
        if (value >= 75) return 'Good';
        if (value >= 50) return 'Moderate';
        return 'Needs attention';
    }

    function heatmapWeight(feature, mode) {
        const props = feature.properties || {};

        if (mode === 'density-heatmap') {
            return seniorCount(feature);
        }

        if (mode === 'risk-heatmap') {
            const count = seniorCount(feature);
            return count > 0 ? clampUnit((numericValue(props.high_risk_count) ?? 0) / count) : null;
        }

        if (mode === 'accessibility-heatmap') {
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

    function hexToRgb(hex) {
        const normalized = String(hex || '').replace('#', '');
        if (!/^[0-9a-f]{6}$/i.test(normalized)) {
            return [100, 116, 139];
        }

        return [
            parseInt(normalized.slice(0, 2), 16),
            parseInt(normalized.slice(2, 4), 16),
            parseInt(normalized.slice(4, 6), 16),
        ];
    }

    function colorWithAlpha(hex, alpha) {
        const [r, g, b] = hexToRgb(hex);
        return `rgba(${r},${g},${b},${alpha})`;
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
            const kind = coordinateKind(feature);

            if (!featureInsidePrimaryBoundary(feature)) {
                stats.outsidePagsanjan++;
                return;
            }

            if (!featureInsideAssignedBarangay(feature)) {
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
        const fallbackRadius = mode === 'density-heatmap' ? 360 : (mode === 'cluster-heatmap' ? 300 : 260);
        const derivedRadius = median([spacingRadius ? spacingRadius * 1.35 : null, boundaryRadius, fallbackRadius]);

        if (mode === 'density-heatmap') {
            return Math.max(220, Math.min(720, derivedRadius ?? fallbackRadius));
        }

        if (mode === 'accessibility-heatmap') {
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
            // Floor at 6px so the kernel never inflates beyond its geographic radius
            // when zoomed out, preventing false cluster color in empty areas.
            const radius = Math.round(Math.max(6, Math.min(42, rawRadius)));

            return {
                radius,
                blur: Math.round(Math.max(4, Math.min(24, radius * 0.52))),
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
        if (mode === 'density-heatmap') {
            return Math.max(1, ...points.map((point) => Number(point[2]) || 0));
        }

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
        const pixelRatio = Math.max(1, Math.min(options.pixelRatioCap ?? 2, window.devicePixelRatio || 1));
        const maxSide = Math.round((options.maxRasterSide ?? 1280) * pixelRatio);
        const minSide = Math.round((options.minRasterSide ?? 720) * pixelRatio);

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
            context.lineWidth = baseLineWidth * (0.70 + levelFrac * 0.60);
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

            const opacity = 0.18 + levelFrac * 0.26;
            context.shadowColor = 'rgba(255,255,255,0.18)';
            context.shadowBlur = 1.2;
            context.strokeStyle = `rgba(255,255,255,${Math.min(0.48, opacity)})`;
            context.stroke();
        });

        context.restore();
    }

    function createClusterDistributionRasterLayer(groups, options) {
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
        const smoothingPixels = Math.max(14, Math.min(36, radius * 0.34));
        const peakSmoothingPixels = Math.max(8, Math.min(22, peakRadius * 0.24));
        const pointCoreSmoothingPixels = Math.max(5, Math.min(14, pointCoreRadius * 0.16));
        const groupImages = groups.map((group) => {
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

            const data = smoothedRasterData(canvas, smoothingPixels);
            const peakData = options.enablePeakSupport === false
                ? null
                : smoothedRasterData(peakCanvas, peakSmoothingPixels);
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

            return {
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
            };
        });

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
        const rasterPixelInsideBoundary = (x, y) => {
            if (!hasBoundaryFeatures(options.clipBoundary)) {
                return true;
            }

            const lng = bounds.getWest() + ((x + 0.5) / width) * (bounds.getEast() - bounds.getWest());
            const lat = bounds.getNorth() - ((y + 0.5) / height) * (bounds.getNorth() - bounds.getSouth());

            return pointInsideBoundary([lng, lat], options.clipBoundary);
        };

        for (let index = 0; index < outputImage.data.length; index += 4) {
            const pixel = index / 4;
            const x = pixel % width;
            const y = Math.floor(pixel / width);

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

        // Run marching squares directly on the full-resolution contour density
        // grid (step=4 skips every 4px for speed while keeping contour shape).
        // Drawing at native canvas resolution avoids the scale-up blurring that
        // would make lines invisible when blitting from a small intermediate canvas.
        const contourSourceGrid = smoothScalarGrid(
            contourDensityGrid,
            width,
            height,
            options.contourSmoothPasses ?? 5
        );
        drawKdeContours(outputContext, contourSourceGrid, width, height, {
            step: options.contourStep ?? 4,
            levels: options.contourLevels ?? [0.08, 0.16, 0.24, 0.32, 0.40, 0.50, 0.60, 0.70, 0.80, 0.90],
            lineWidth: options.contourLineWidth ?? 1.1,
        });

        return createSmoothHeatmapImageOverlay(outputCanvas.toDataURL('image/png'), bounds, {
            pane: 'gis-heat-pane',
            opacity: 1,
            interactive: false,
        });
    }

    function heatmapGradient(mode) {
        if (mode === 'cluster-heatmap') {
            return CLUSTER_HEATMAP_GRADIENT;
        }

        if (mode === 'accessibility-heatmap') {
            return {
                0.15: '#10b981',
                0.45: '#facc15',
                0.72: '#fb923c',
                1.00: '#ef4444',
            };
        }

        return RISK_HEATMAP_GRADIENT;
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
                minOpacity: 0.22,
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

        // Candidate ranking favors senior-needed services first, then nearby
        // facilities. The final displayed order is based on road-route distance.
        return source
            .sort((a, b) =>
                seniorFacilityPriority(a.facility) - seniorFacilityPriority(b.facility)
                || a.straightDistance - b.straightDistance
            )
            .slice(0, ROUTE_SERVICE_CANDIDATE_LIMIT);
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
            .slice(0, ROUTE_SERVICE_RESULT_LIMIT)
            .map((candidate, index) => {
                const label = escapeHtml(serviceBaseLabel(candidate.facility));
                return `<li class="pl-1 leading-snug" data-gis-route-item="${index}">${label} - calculating route...</li>`;
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

    function createMarkerIcon(color, kind = 'verified') {
        const isFallback = kind === 'fallback';
        const background = isFallback ? colorWithAlpha(color, 0.16) : color;
        const border = isFallback ? `2px dashed ${color}` : '2px solid #ffffff';
        const size = isFallback ? 13 : 14;

        return window.L.divIcon({
            className: 'gis-marker-icon',
            html: `<span style="display:block;width:${size}px;height:${size}px;border-radius:9999px;background:${background};border:${border};box-shadow:0 4px 10px rgba(15,23,42,0.18);"></span>`,
            iconSize: [size, size],
            iconAnchor: [size / 2, size / 2],
            popupAnchor: [0, -8],
        });
    }

    function createFacilityIcon() {
        return window.L.divIcon({
            className: 'gis-facility-icon',
            html: `<span style="display:block;width:16px;height:16px;border-radius:4px;background:#0284c7;border:2px solid #ffffff;box-shadow:0 4px 10px rgba(15,23,42,0.18);transform:rotate(45deg);"></span>`,
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
        layer.bindPopup(popupHtml(feature));
        layer.on('click', (event) => {
            if (event?.originalEvent) {
                window.L.DomEvent.stopPropagation(event.originalEvent);
            }
            layer.openPopup();
        });
        layer.on('popupopen', () => updateRoadNetworkServices(layer, feature));
    }

    async function updateRoadNetworkServices(layer, feature) {
        const popup = layer.getPopup?.();
        if (!popup) return;

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

        const routed = [];

        await Promise.all(candidates.map(async (candidate, index) => {
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

            if (item && routeItem) {
                routeItem.textContent = serviceLabel(item.facility, item.routeDistance, {
                    route: true,
                    duration: item.routeDuration,
                    provider: item.routeProvider,
                });
            } else if (routeItem) {
                routeItem.textContent = `${serviceBaseLabel(candidate.facility)} - route unavailable`;
            }

            if (item) {
                routed.push(item);
            }
        }));

        if (layer._gisRouteRequestId !== requestId || layer.isPopupOpen?.() === false) {
            return;
        }

        const currentElement = document.getElementById(elementId);
        if (!currentElement) {
            return;
        }

        if (!routed.length) {
            currentElement.innerHTML = serviceListHtml('Road route unavailable for mapped services');
            return;
        }

        currentElement.innerHTML = serviceListHtml(routed
            .sort((a, b) => a.routeDistance - b.routeDistance)
            .map((item) => serviceLabel(item.facility, item.routeDistance, {
                route: true,
                duration: item.routeDuration,
                provider: item.routeProvider,
            })));
    }

    function popupHtml(featureOrProperties, routedServices = null) {
        const feature = featureOrProperties?.type === 'Feature'
            ? featureOrProperties
            : { type: 'Feature', properties: featureOrProperties || null };
        const p = feature.properties || {};
        const seniorName = escapeHtml(p.senior_name ?? 'Senior record');
        const oscaId = escapeHtml(p.osca_id ?? `#${p.senior_id ?? 'N/A'}`);
        const barangay = escapeHtml(p.barangay ?? 'N/A');
        const riskLevel = escapeHtml(p.risk_level ?? 'Unknown');
        const healthGroup = escapeHtml(p.cluster_label ?? p.cluster ?? 'Unassigned');
        const accessibility = p.gis_proximity_score !== null && p.gis_proximity_score !== undefined
            ? `${Number(p.gis_proximity_score).toFixed(1)}% (${escapeHtml(p.accessibility_status ?? accessibilityStatus(p.gis_proximity_score))})`
            : escapeHtml(p.accessibility_status ?? 'No accessibility score available');
        const services = routedServices
            ? serviceListHtml(routedServices)
            : routeLoadingListHtml(routeCandidatesForFeature(feature));
        const servicesElementId = escapeHtml(routeServicesElementId(feature));

        if (p.is_generalized_senior_point) {
            return `
                <div class="space-y-1 text-[12px] leading-snug">
                    <div><strong>Senior:</strong> ${seniorName}</div>
                    <div><strong>OSCA ID:</strong> ${oscaId}</div>
                    <div><strong>Barangay:</strong> ${barangay}</div>
                    <div><strong>Point Type:</strong> Generalized senior point</div>
                    <div><strong>Risk Indicator:</strong> ${riskLevel}</div>
                    <div><strong>Health Group:</strong> ${healthGroup}</div>
                    <div><strong>Accessibility Status:</strong> ${accessibility}</div>
                    <div>
                        <strong>Nearby senior services:</strong>
                        <div id="${servicesElementId}">${services}</div>
                    </div>
                </div>
            `;
        }

        return `
            <div class="space-y-1 text-[12px] leading-snug">
                <div><strong>Senior:</strong> ${seniorName}</div>
                <div><strong>OSCA ID:</strong> ${oscaId}</div>
                <div><strong>Barangay:</strong> ${barangay}</div>
                <div><strong>Total Seniors:</strong> ${p.senior_count ?? p.total_seniors ?? 0}</div>
                <div><strong>Risk Indicator:</strong> ${riskLevel}</div>
                <div><strong>Health Group:</strong> ${healthGroup}</div>
                <div><strong>Accessibility Status:</strong> ${accessibility}</div>
                <div>
                    <strong>Nearby senior services:</strong>
                    <div id="${servicesElementId}">${services}</div>
                </div>
            </div>
        `;
    }

    function facilityPopupHtml(properties) {
        const p = properties || {};
        return `
            <div class="space-y-1 text-[12px] leading-snug">
                <div><strong>Facility:</strong> ${p.name ?? 'N/A'}</div>
                <div><strong>Type:</strong> ${p.type ?? 'N/A'}</div>
                <div><strong>Barangay:</strong> ${p.barangay ?? 'N/A'}</div>
                <div><strong>Source:</strong> ${p.source ?? 'N/A'}</div>
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

    function buildFacilityLayer(featureCollection) {
        return window.L.geoJSON(featureCollection, {
            pointToLayer(feature, latlng) {
                const marker = window.L.marker(latlng, {
                    icon: createFacilityIcon(),
                    keyboard: false,
                    pane: 'gis-facility-pane',
                });

                marker.bindPopup(facilityPopupHtml(feature.properties));

                return marker;
            },
        });
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
            kdeRiskHeatmap: window.L.layerGroup().addTo(map),
            kdeClusterHeatmap: window.L.layerGroup().addTo(map),
            kdeAccessibilityHeatmap: window.L.layerGroup().addTo(map),
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
        clearKdeOverlayLayers(map);
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

    function kdeLayerForMode(map, mode) {
        const layers = ensureLayerRegistry(map);

        if (mode === 'risk-indicator-heatmap') {
            return layers.kdeRiskHeatmap;
        }

        if (mode === 'cluster-heatmap') {
            return layers.kdeClusterHeatmap;
        }

        if (mode === 'accessibility-heatmap') {
            return layers.kdeAccessibilityHeatmap;
        }

        return null;
    }

    function clearKdeOverlayLayers(map) {
        const layers = ensureLayerRegistry(map);
        map._gisKdeOverlayContexts = {};
        layers.kdeRiskHeatmap.clearLayers();
        layers.kdeClusterHeatmap.clearLayers();
        layers.kdeAccessibilityHeatmap.clearLayers();
    }

    function heatmapFeaturesForMode(features, mode) {
        if (mode === 'cluster-heatmap') {
            const selectedCluster = selectedClusterGroup();
            return features.filter((feature) => {
                return featureClusterNumber(feature) !== null
                    && featureMatchesSelectedCluster(feature, selectedCluster);
            });
        }

        if (mode === 'accessibility-heatmap') {
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

    function buildClusterDistributionHeatmapLayer(map, features, options = {}) {
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
        const heatmapLayer = createClusterDistributionRasterLayer(groups, {
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
            contourSmoothPasses: options.contourSmoothPasses ?? 12,
            contourStep: options.contourStep ?? 3,
            contourLevels: options.contourLevels ?? [0.06, 0.11, 0.17, 0.25, 0.34, 0.45, 0.57, 0.70, 0.84],
            contourLineWidth: options.contourLineWidth ?? 0.58,
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
    function buildRiskDistributionRasterLayer(map, features, options = {}) {
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

        const layer = createClusterDistributionRasterLayer([group], {
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

    function buildClusterPointRampLayer(features, options = {}) {
        const groups = groupFeaturesByCluster(features)
            .map(([label, groupFeatures]) => {
                const groupNumber = featureClusterNumber(groupFeatures[0]);
                const resolvedLabel = groupNumber ? `Group ${groupNumber}` : label;

                return {
                    label: resolvedLabel,
                    color: clusterColorForLabel(resolvedLabel, groupFeatures[0]),
                    ramp: gradientStopsFromStops(clusterGradientForLabel(resolvedLabel, groupFeatures[0])),
                    points: groupFeatures
                        .map((feature) => {
                            const latlng = featureLatLng(feature);
                            return latlng
                                ? [latlng.lat, latlng.lng, Math.max(1, seniorCount(feature)), normalizeBarangayName(feature.properties?.barangay)]
                                : null;
                        })
                        .filter(Boolean),
                };
            })
            .filter((group) => group.points.length);

        if (!groups.length) {
            return null;
        }

        const PointRampLayer = window.L.Layer.extend({
            initialize() {
                this._groups = groups;
                this._options = options;
            },

            onAdd(map) {
                this._map = map;
                this._canvas = window.L.DomUtil.create('canvas', 'leaflet-layer gis-cluster-point-ramp');
                this._canvas.style.pointerEvents = 'none';

                const pane = map.getPane('gis-heat-pane') ?? map.getPanes().overlayPane;
                pane.appendChild(this._canvas);

                map.on('moveend zoomend resize', this._scheduleReset, this);
                if (map.options.zoomAnimation && window.L.Browser.any3d) {
                    map.on('zoomanim', this._animateZoom, this);
                }

                this._reset();
            },

            onRemove(map) {
                if (this._canvas?.parentNode) {
                    this._canvas.parentNode.removeChild(this._canvas);
                }

                if (this._resetFrame) {
                    window.cancelAnimationFrame(this._resetFrame);
                    this._resetFrame = null;
                }
                map.off('moveend zoomend resize', this._scheduleReset, this);
                if (map.options.zoomAnimation) {
                    map.off('zoomanim', this._animateZoom, this);
                }
            },

            _scheduleReset() {
                if (this._resetFrame) {
                    window.cancelAnimationFrame(this._resetFrame);
                }

                this._resetFrame = window.requestAnimationFrame(() => {
                    this._resetFrame = null;
                    this._reset();
                });
            },

            _reset() {
                const size = this._map.getSize();
                const topLeft = this._map.containerPointToLayerPoint([0, 0]);
                this._pixelScale = 1;

                window.L.DomUtil.setPosition(this._canvas, topLeft);
                this._canvas.style.width = '';
                this._canvas.style.height = '';
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
                const maxWeight = Math.max(1, ...group.points.map((point) => point[2] || 1));
                const barangayGroupCounts = group.points.reduce((counts, point) => {
                    const barangay = point[3] || '';
                    if (barangay) {
                        counts.set(barangay, (counts.get(barangay) || 0) + 1);
                    }

                    return counts;
                }, new Map());
                const barangayBoostFor = (barangay) => {
                    const count = barangayGroupCounts.get(barangay) || 0;
                    if (count >= 6) return 1.58;
                    if (count >= 4) return 1.42;
                    if (count >= 3) return 1.28;
                    return 1;
                };
                const projectedPoints = [];

                group.points.forEach(([lat, lng, weight, barangay]) => {
                    const point = this._map.latLngToContainerPoint([lat, lng]);
                    point.x *= this._pixelScale;
                    point.y *= this._pixelScale;
                    const barangayBoost = barangayBoostFor(barangay);
                    const localRadius = radius * barangayBoost;
                    if (point.x < -localRadius || point.y < -localRadius || point.x > width + localRadius || point.y > height + localRadius) {
                        return;
                    }

                    const intensity = clampUnit(Math.max(barangayBoost > 1 ? 0.82 : 0.70, (Number(weight) || 1) / maxWeight));
                    projectedPoints.push({ x: point.x, y: point.y, intensity, barangay, barangayBoost });
                    const gradient = context.createRadialGradient(point.x, point.y, 0, point.x, point.y, localRadius);
                    gradient.addColorStop(0.00, `rgba(0,0,0,${0.96 * intensity})`);
                    gradient.addColorStop(0.14, `rgba(0,0,0,${0.86 * intensity})`);
                    gradient.addColorStop(0.30, `rgba(0,0,0,${0.58 * intensity})`);
                    gradient.addColorStop(0.50, `rgba(0,0,0,${0.30 * intensity})`);
                    gradient.addColorStop(0.72, `rgba(0,0,0,${0.11 * intensity})`);
                    gradient.addColorStop(0.90, `rgba(0,0,0,${0.026 * intensity})`);
                    gradient.addColorStop(1.00, 'rgba(0,0,0,0)');

                    context.fillStyle = gradient;
                    context.beginPath();
                    context.arc(point.x, point.y, localRadius, 0, Math.PI * 2);
                    context.fill();
                });

                const groupNumber = clusterNumber(group.label);
                const compactGroup = projectedPoints.length >= 3;
                const bridgeBoost = compactGroup ? 1.34 : 1;
                const bridgeDistance = radius * 1.65 * bridgeBoost;
                const bridgeDistanceSquared = bridgeDistance * bridgeDistance;
                const bridgeKernelRadius = Math.max(10, radius * (compactGroup ? 0.62 : 0.48));

                for (let firstIndex = 0; firstIndex < projectedPoints.length; firstIndex += 1) {
                    const first = projectedPoints[firstIndex];
                    for (let secondIndex = firstIndex + 1; secondIndex < projectedPoints.length; secondIndex += 1) {
                        const second = projectedPoints[secondIndex];
                        const sameBoostedBarangayPair = first.barangay && first.barangay === second.barangay && Math.max(first.barangayBoost, second.barangayBoost) > 1;
                        const pairBoost = sameBoostedBarangayPair ? Math.max(first.barangayBoost, second.barangayBoost) : 1;
                        const dx = second.x - first.x;
                        const dy = second.y - first.y;
                        const distanceSquared = (dx * dx) + (dy * dy);
                        const pairBridgeDistance = bridgeDistance * pairBoost;
                        const pairBridgeDistanceSquared = pairBridgeDistance * pairBridgeDistance;
                        if (distanceSquared > pairBridgeDistanceSquared) {
                            continue;
                        }

                        const distance = Math.sqrt(distanceSquared);
                        const closeness = clampUnit(1 - (distance / pairBridgeDistance));
                        const bridgeAlphaCap = sameBoostedBarangayPair ? 0.56 : (compactGroup ? 0.46 : 0.32);
                        const bridgeAlphaBase = sameBoostedBarangayPair ? 0.18 : (compactGroup ? 0.13 : 0.08);
                        const bridgeAlphaRange = sameBoostedBarangayPair ? 0.38 : (compactGroup ? 0.33 : 0.24);
                        const bridgeAlpha = Math.min(bridgeAlphaCap, (bridgeAlphaBase + (closeness * bridgeAlphaRange)) * Math.min(first.intensity, second.intensity));
                        const midpointX = (first.x + second.x) / 2;
                        const midpointY = (first.y + second.y) / 2;
                        const blobCount = sameBoostedBarangayPair ? 4 : (compactGroup ? 3 : 2);

                        for (let blobIndex = 1; blobIndex <= blobCount; blobIndex += 1) {
                            const t = blobIndex / (blobCount + 1);
                            const centerX = first.x + (dx * t);
                            const centerY = first.y + (dy * t);
                            const wobble = Math.sin((first.x + second.y + blobIndex * 31) * 0.017) * bridgeKernelRadius * 0.14;
                            const angle = Math.atan2(dy, dx) + (Math.PI / 2);
                            const blobX = blobIndex === Math.ceil(blobCount / 2)
                                ? midpointX
                                : centerX + Math.cos(angle) * wobble;
                            const blobY = blobIndex === Math.ceil(blobCount / 2)
                                ? midpointY
                                : centerY + Math.sin(angle) * wobble;
                            const kernelRadius = bridgeKernelRadius * (sameBoostedBarangayPair ? 1.22 : 1) * (0.74 + (closeness * 0.34));
                            const gradient = context.createRadialGradient(blobX, blobY, 0, blobX, blobY, kernelRadius);

                            gradient.addColorStop(0.00, `rgba(0,0,0,${bridgeAlpha * 0.78})`);
                            gradient.addColorStop(0.34, `rgba(0,0,0,${bridgeAlpha * 0.42})`);
                            gradient.addColorStop(0.68, `rgba(0,0,0,${bridgeAlpha * 0.12})`);
                            gradient.addColorStop(1.00, 'rgba(0,0,0,0)');

                            context.fillStyle = gradient;
                            context.beginPath();
                            context.arc(blobX, blobY, kernelRadius, 0, Math.PI * 2);
                            context.fill();
                        }
                    }
                }

                const componentDistance = radius * (compactGroup ? 2.35 : 1.95);
                const componentDistanceSquared = componentDistance * componentDistance;
                const visited = new Set();

                projectedPoints.forEach((startPoint, startIndex) => {
                    if (visited.has(startIndex)) {
                        return;
                    }

                    const queue = [startIndex];
                    const componentIndexes = [];
                    visited.add(startIndex);

                    while (queue.length) {
                        const currentIndex = queue.shift();
                        const current = projectedPoints[currentIndex];
                        componentIndexes.push(currentIndex);

                        projectedPoints.forEach((candidate, candidateIndex) => {
                            if (visited.has(candidateIndex)) {
                                return;
                            }

                            const sameBoostedBarangayPair = candidate.barangay
                                && candidate.barangay === current.barangay
                                && Math.max(candidate.barangayBoost, current.barangayBoost) > 1;
                            const dx = candidate.x - current.x;
                            const dy = candidate.y - current.y;
                            const distanceSquared = (dx * dx) + (dy * dy);
                            const localComponentDistance = componentDistance * (sameBoostedBarangayPair ? Math.max(candidate.barangayBoost, current.barangayBoost) : 1);
                            if (distanceSquared > localComponentDistance * localComponentDistance) {
                                return;
                            }

                            visited.add(candidateIndex);
                            queue.push(candidateIndex);
                        });
                    }

                    if (componentIndexes.length < 2) {
                        return;
                    }

                    const component = componentIndexes.map((index) => projectedPoints[index]);
                    const componentBarangays = component.reduce((counts, point) => {
                        if (point.barangay) {
                            counts.set(point.barangay, (counts.get(point.barangay) || 0) + 1);
                        }

                        return counts;
                    }, new Map());
                    const strongestBarangay = [...componentBarangays.entries()].sort((a, b) => b[1] - a[1])[0];
                    const componentBarangayBoost = strongestBarangay
                        ? barangayBoostFor(strongestBarangay[0])
                        : 1;
                    const isBoostedBarangayComponent = componentBarangayBoost > 1
                        && strongestBarangay[1] >= Math.max(2, Math.ceil(component.length * 0.5));
                    const centerX = component.reduce((total, point) => total + point.x, 0) / component.length;
                    const centerY = component.reduce((total, point) => total + point.y, 0) / component.length;
                    const extent = component.reduce((max, point) => {
                        const dx = point.x - centerX;
                        const dy = point.y - centerY;
                        return Math.max(max, Math.sqrt((dx * dx) + (dy * dy)));
                    }, 0);
                    const cloudRadius = Math.max(
                        radius * (isBoostedBarangayComponent ? 1.72 : (component.length >= 3 ? 1.28 : 1.06)),
                        extent + (radius * (isBoostedBarangayComponent ? 1.08 : 0.78))
                    );
                    const cloudAlpha = Math.min(
                        isBoostedBarangayComponent ? 0.46 : (component.length >= 3 ? 0.34 : 0.24),
                        (isBoostedBarangayComponent ? 0.13 : 0.08) + (component.length * (isBoostedBarangayComponent ? 0.055 : (component.length >= 3 ? 0.045 : 0.035)))
                    );
                    const gradient = context.createRadialGradient(centerX, centerY, 0, centerX, centerY, cloudRadius);

                    gradient.addColorStop(0.00, `rgba(0,0,0,${cloudAlpha})`);
                    gradient.addColorStop(0.34, `rgba(0,0,0,${cloudAlpha * 0.52})`);
                    gradient.addColorStop(0.66, `rgba(0,0,0,${cloudAlpha * 0.18})`);
                    gradient.addColorStop(1.00, 'rgba(0,0,0,0)');

                    context.fillStyle = gradient;
                    context.beginPath();
                    context.arc(centerX, centerY, cloudRadius, 0, Math.PI * 2);
                    context.fill();
                });

                return context.getImageData(0, 0, width, height).data;
            },

            _redraw() {
                const width = this._canvas.width;
                const height = this._canvas.height;
                const radiusMeters = this._options.radiusMeters ?? 230;
                const pixelScale = this._pixelScale || 1;
                const rawRadius = metersToPixelsAtLatLng(this._map, this._map.getCenter(), radiusMeters);
                const zoom = this._map.getZoom();
                const minScreenRadius = zoom <= 11 ? 4 : (zoom <= 13 ? 8 : 3);
                const maxScreenRadius = zoom <= 11 ? 18 : (zoom <= 13 ? 42 : 260);
                const screenRadius = Math.max(minScreenRadius, Math.min(maxScreenRadius, rawRadius));
                const radius = Math.max(2, Math.round(screenRadius * pixelScale));
                const groupImages = this._groups.map((group) => ({
                    ...group,
                    data: this._densityForGroup(group, width, height, radius),
                }));
                const anchorGroupIndexes = new Int16Array(width * height);
                const anchorScores = new Float32Array(width * height);
                anchorGroupIndexes.fill(-1);
                const protectedRadius = Math.round(Math.max(3, Math.min(90, radius * 0.46)));
                const protectedRadiusSquared = protectedRadius * protectedRadius;

                groupImages.forEach((group, groupIndex) => {
                    group.points.forEach(([lat, lng]) => {
                        const point = this._map.latLngToContainerPoint([lat, lng]);
                        point.x *= pixelScale;
                        point.y *= pixelScale;
                        const startX = Math.max(0, Math.floor(point.x - protectedRadius));
                        const endX = Math.min(width - 1, Math.ceil(point.x + protectedRadius));
                        const startY = Math.max(0, Math.floor(point.y - protectedRadius));
                        const endY = Math.min(height - 1, Math.ceil(point.y + protectedRadius));

                        for (let y = startY; y <= endY; y += 1) {
                            const dy = y - point.y;
                            for (let x = startX; x <= endX; x += 1) {
                                const dx = x - point.x;
                                const distanceSquared = (dx * dx) + (dy * dy);
                                if (distanceSquared > protectedRadiusSquared) {
                                    continue;
                                }

                                const pixel = (y * width) + x;
                                const score = Math.pow(1 - Math.sqrt(distanceSquared) / protectedRadius, 2.20);
                                if (score > anchorScores[pixel]) {
                                    anchorScores[pixel] = score;
                                    anchorGroupIndexes[pixel] = groupIndex;
                                }
                            }
                        }
                    });
                });
                const outputContext = this._canvas.getContext('2d');
                const outputImage = outputContext.createImageData(width, height);

                for (let index = 0; index < outputImage.data.length; index += 4) {
                    const pixel = index / 4;
                    const x = pixel % width;
                    const y = Math.floor(pixel / width);

                    let winningGroup = null;
                    let winningAlpha = 0;
                    let secondAlpha = 0;

                    for (const group of groupImages) {
                        const alpha = group.data[index + 3];
                        if (alpha > winningAlpha) {
                            secondAlpha = winningAlpha;
                            winningAlpha = alpha;
                            winningGroup = group;
                        } else if (alpha > secondAlpha) {
                            secondAlpha = alpha;
                        }
                    }

                    const anchorGroupIndex = anchorGroupIndexes[pixel];
                    if (anchorGroupIndex >= 0 && anchorScores[pixel] >= 0.08) {
                        winningGroup = groupImages[anchorGroupIndex];
                        winningAlpha = Math.max(
                            winningAlpha,
                            255 * (0.12 + (anchorScores[pixel] * 0.84))
                        );
                    }

                    const normalized = clampUnit(winningAlpha / 255);
                    const supportAlpha = groupImages.reduce((total, group) => total + group.data[index + 3], 0);
                    const supportDensity = clampUnit(supportAlpha / 255);
                    const minVisibleDensity = zoom <= 11 ? 0.115 : (zoom <= 13 ? 0.062 : (this._options.minVisibleDensity ?? 0.004));
                    const minSupportDensity = zoom <= 11 ? 0.34 : (zoom <= 13 ? 0.24 : 0);
                    if (!winningGroup || normalized < minVisibleDensity) {
                        continue;
                    }

                    if (supportDensity < minSupportDensity) {
                        continue;
                    }

                    const screenX = x / pixelScale;
                    const screenY = y / pixelScale;

                    if (!canvasPixelInsideBoundary(this._map, screenX, screenY, this._options.clipBoundary)) {
                        continue;
                    }

                    const fixedRampFloor = this._options.fixedRampFloor ?? 0.004;
                    const rampDensity = clampUnit((normalized - fixedRampFloor) / (1 - fixedRampFloor));
                    const [red, green, blue] = colorForGradientValue(
                        Math.pow(rampDensity, this._options.dominancePower ?? 0.86),
                        winningGroup.ramp
                    );

                    outputImage.data[index] = red;
                    outputImage.data[index + 1] = green;
                    outputImage.data[index + 2] = blue;
                    const competition = secondAlpha > 0
                        ? clampUnit((winningAlpha - secondAlpha) / Math.max(winningAlpha, 1))
                        : 1;
                    const supportFeather = zoom <= 13 ? clampUnit((supportDensity - minSupportDensity) / Math.max(0.18, 1 - minSupportDensity)) : 1;
                    const edgeFeather = (0.58 + (Math.pow(competition, 0.42) * 0.42)) * (0.52 + (supportFeather * 0.48));
                    outputImage.data[index + 3] = Math.round(Math.min(
                        this._options.outputMaxAlpha ?? 255,
                        (this._options.outputAlphaBase ?? 255) * Math.pow(rampDensity, this._options.outputAlphaPower ?? 0.58) * edgeFeather
                    ));
                }

                outputContext.clearRect(0, 0, width, height);
                outputContext.putImageData(outputImage, 0, 0);
            },
        });

        return new PointRampLayer();
    }

    function buildClusterIdentityHaloLayer(features) {
        return window.L.geoJSON({
            type: 'FeatureCollection',
            features,
        }, {
            pointToLayer(feature, latlng) {
                const color = clusterColorForLabel(clusterLabel(feature), feature);

                return window.L.circleMarker(latlng, {
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

    function buildRiskIdentityHaloLayer(features) {
        // Small senior dots (colored by real risk level) shown above the risk
        // KDE surface so markers stay visible and popups keep working.
        return window.L.geoJSON({
            type: 'FeatureCollection',
            features,
        }, {
            pointToLayer(feature, latlng) {
                const color = riskColor(feature.properties?.risk_level);

                return window.L.circleMarker(latlng, {
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
        if (context.mode === 'risk-indicator-heatmap') {
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

    function setKdeOverlayContext(map, mode, features, options = {}) {
        map._gisKdeOverlayContexts = map._gisKdeOverlayContexts || {};
        map._gisKdeOverlayContexts[mode] = {
            mode,
            features: [...features],
            radiusMeters: options.radiusMeters,
            colorScaleMax: options.colorScaleMax,
        };
    }

    function renderKdeOverlayHeatmap(map, mode, features) {
        const layerGroup = kdeLayerForMode(map, mode);
        if (!layerGroup) {
            return null;
        }

        layerGroup.clearLayers();

        if (mode === 'cluster-heatmap') {
            const result = buildClusterDistributionHeatmapLayer(map, features);
            if (!result.layer || !result.points.length) return null;

            const clusterFeatures = heatmapFeaturesForMode(features, 'cluster-heatmap');
            layerGroup.addLayer(result.layer);
            const pointRampLayer = buildClusterPointRampLayer(clusterFeatures, {
                clipBoundary: primaryBoundaryGeoJson(),
            });
            if (pointRampLayer) {
                layerGroup.addLayer(pointRampLayer);
            }
            setKdeOverlayContext(map, mode, features, {
                radiusMeters: result.radiusMeters,
                colorScaleMax: result.colorScaleMax,
            });

            return result;
        }

        if (mode === 'risk-indicator-heatmap') {
            // Same smooth raster-KDE engine as the cluster overlay so the
            // "Risk Distribution Heatmap" checkbox matches the typhoon style.
            const result = buildRiskDistributionRasterLayer(map, features);
            if (!result.layer || !result.points.length) return null;

            layerGroup.addLayer(result.layer);
            setKdeOverlayContext(map, mode, features, {
                radiusMeters: result.radiusMeters,
                colorScaleMax: result.colorScaleMax,
            });

            return result;
        }

        const heatmapFeatures = heatmapFeaturesForMode(features, mode);
        const { layer: heatLayer, points, radiusMeters } = buildHeatmapLayer(map, heatmapFeatures, mode);

        if (!points.length || !heatLayer) {
            return null;
        }

        layerGroup.addLayer(heatLayer);
        setKdeOverlayContext(map, mode, heatmapFeatures);

        return { points, radiusMeters };
    }

    function renderKdeOverlayHeatmaps(map, features) {
        clearKdeOverlayLayers(map);

        const modes = selectedKdeOverlayModes();
        const results = modes
            .map((mode) => renderKdeOverlayHeatmap(map, mode, features))
            .filter(Boolean);

        return results;
    }

    function refreshKdeOverlayHeatmaps(map) {
        const contexts = map?._gisKdeOverlayContexts || {};

        Object.values(contexts).forEach((context) => {
            const layerGroup = kdeLayerForMode(map, context.mode);
            if (!layerGroup) return;

            // Raster-KDE overlays (cluster + risk) are zoom-stable image overlays
            // and must not be rebuilt as canvas layers on zoom.
            if (context.mode === 'cluster-heatmap' || context.mode === 'risk-indicator-heatmap') {
                return;
            }

            layerGroup.clearLayers();

            const refreshOptions = {
                radiusMeters: context.radiusMeters,
                colorScaleMax: context.colorScaleMax,
            };

            const { layer: heatLayer, points, radiusMeters } = buildHeatmapLayer(map, context.features, context.mode, refreshOptions);

            if (points.length && heatLayer) {
                layerGroup.addLayer(heatLayer);
                context.radiusMeters = context.radiusMeters ?? radiusMeters;
            }
        });
    }

    function refreshHeatmapLayersForZoom(map) {
        refreshActiveHeatmapRadius(map);
        refreshKdeOverlayHeatmaps(map);
    }

    function renderRiskHeatmap(map, features) {
        clearHeatmapLayers(map);

        if (!window.L.Layer) {
            focusMapOnActiveLayer(map, features);
            setStatus('Leaflet is unavailable. Rebuild frontend assets to enable GIS heatmaps.', 'error');
            return;
        }

        const result = buildRiskDistributionRasterLayer(map, features);

        if (!result.layer || !result.points.length) {
            focusMapOnActiveLayer(map, features);
            setStatus('No senior records had risk indicator values for the selected filters.', 'neutral');
            return;
        }

        ensureLayerRegistry(map).heatmap.addLayer(result.layer);
        ensureLayerRegistry(map).seniors.addLayer(buildRiskIdentityHaloLayer(features));
        setActiveHeatmapContext(map, 'risk-indicator-heatmap', features, {
            radiusMeters: result.radiusMeters,
            colorScaleMax: result.colorScaleMax,
        });
        focusMapOnActiveLayer(map, features);
        setStatus(`Risk Indicator Distribution renders ${result.points.length} senior GIS point(s) as a continuous KDE risk surface, weighted by composite risk score (falling back to risk level), clipped to Pagsanjan (${result.radiusMeters}m radius).`, 'success');
    }

    function renderClusterHeatmap(map, features) {
        clearHeatmapLayers(map);

        const selectedCluster = selectedClusterGroup();
        const clusterFeatures = heatmapFeaturesForMode(features, 'cluster-heatmap');

        if (selectedCluster === 'all') {
            const layerGroup = ensureLayerRegistry(map).heatmap;
            layerGroup.clearLayers();
            const result = buildClusterDistributionHeatmapLayer(map, features);

            if (!result.layer || !result.points.length) {
                focusMapOnActiveLayer(map, features);
                setStatus('No senior records had health group cluster values for the selected filters.', 'neutral');
                return;
            }

            layerGroup.addLayer(result.layer);
            const pointRampLayer = buildClusterPointRampLayer(clusterFeatures, {
                clipBoundary: primaryBoundaryGeoJson(),
            });
            if (pointRampLayer) {
                layerGroup.addLayer(pointRampLayer);
            }
            if (shouldShowClusterSeniorPoints()) {
                ensureLayerRegistry(map).seniors.addLayer(buildClusterIdentityHaloLayer(clusterFeatures));
            }
            setActiveHeatmapContext(map, 'cluster-heatmap', features, {
                radiusMeters: result.radiusMeters,
                colorScaleMax: result.colorScaleMax,
            });
            focusMapOnActiveLayer(map, clusterFeatures);
            setStatus(`Health Group Cluster Distribution shows ${result.points.length} senior GIS point(s) across ${result.groups.length} group(s), rendered as a KDE density heatmap with non-blended group colors (${result.radiusMeters}m radius).`, 'success');
            return;
        }

        const result = buildClusterDistributionHeatmapLayer(map, features);

        if (!window.L.Layer) {
            focusMapOnActiveLayer(map, features);
            setStatus('Leaflet is unavailable. Rebuild frontend assets to enable GIS heatmaps.', 'error');
            return;
        }

        if (!result.layer || !result.points.length) {
            focusMapOnActiveLayer(map, features);
            setStatus('No senior records had health group cluster values for the selected filters.', 'neutral');
            return;
        }

        ensureLayerRegistry(map).heatmap.addLayer(result.layer);
        const pointRampLayer = buildClusterPointRampLayer(clusterFeatures, {
            clipBoundary: primaryBoundaryGeoJson(),
        });
        if (pointRampLayer) {
            ensureLayerRegistry(map).heatmap.addLayer(pointRampLayer);
        }
        if (shouldShowClusterSeniorPoints()) {
            ensureLayerRegistry(map).seniors.addLayer(buildClusterIdentityHaloLayer(clusterFeatures));
        }
        setActiveHeatmapContext(map, 'cluster-heatmap', clusterFeatures, {
            radiusMeters: result.radiusMeters,
            colorScaleMax: result.colorScaleMax,
        });
        focusMapOnActiveLayer(map, clusterFeatures);
        setStatus(`Health Group Cluster Distribution shows ${result.points.length} senior GIS point(s) in ${selectedCluster}, rendered as a clipped geographic KDE raster (${result.radiusMeters}m radius).`, 'success');
    }

    function toggleGisLayer(map, mode, features) {
        if (mode === 'risk-indicator-heatmap') {
            renderRiskHeatmap(map, features);
            return true;
        }

        if (mode === 'cluster-heatmap') {
            renderClusterHeatmap(map, features);
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

        const primaryBoundary = primaryBoundaryGeoJson();

        if (primaryBoundary) {
            const maskLayer = buildMunicipalMaskLayer(primaryBoundary);
            if (maskLayer) {
                layers.municipalMask.addLayer(maskLayer);
            }
        }

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
        return isDarkMode() ? '#131917' : '#ffffff';
    }

    function createTileLayer() {
        return window.L.tileLayer(TILE_LIGHT_URL, {
            maxZoom: 19,
            attribution: TILE_LIGHT_ATTRIBUTION,
            updateWhenIdle: true,
            keepBuffer: 4,
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

    function buildMunicipalMaskLayer(featureCollection) {
        if (!hasBoundaryFeatures(featureCollection)) {
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

        const outerRing = [
            [-90, -360],
            [-90, 360],
            [90, 360],
            [90, -360],
        ];

        return window.L.polygon([outerRing, ...holes], {
            pane: 'gis-mask-pane',
            stroke: false,
            fillColor: maskFillColor(),
            fillOpacity: 1.0,
            interactive: false,
            bubblingMouseEvents: false,
        });
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

    function renderDataLayers(map, seniorGeoJson, facilityGeoJson) {
        const mode = document.getElementById(MODE_ID)?.value ?? 'markers';
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
        updateLegend(mode);
        updateSummaryCards(seniorGeoJson, mode === 'barangay-density' ? activeFeatures : renderStats.visible);

        if (!activeFeatures.length) {
            focusMapOnPagsanjan(map);
            setStatus('No senior records matched the selected filters.', 'neutral');
            return;
        }

        const facilityLayer = buildFacilityLayer(facilityCollection);
        if (facilityGeoJson?.features?.length) {
            layers.facilities.addLayer(facilityLayer);
        }

        if (mode === 'markers') {
            layers.barangayDensity.addLayer(buildBarangayDensityLayer(activeFeatures));
        }

        if (mode === 'barangay-density') {
            layers.barangayDensity.addLayer(buildBarangayDensityLayer(activeFeatures));
            const kdeOverlayResults = renderKdeOverlayHeatmaps(map, markerStats.visible);
            focusMapOnActiveLayer(map, markerStats.visible.length ? markerStats.visible : activeFeatures);
            const overlayText = kdeOverlayResults.length ? ` ${kdeOverlayResults.length} KDE heatmap overlay(s) active.` : '';
            setStatus(`${validationStatusText(activeFeatures.length, markerStats)} Barangay density uses backend senior counts.${overlayText}`, 'success');
            return;
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
        const kdeOverlayResults = mode === 'cluster-heatmap'
            ? []
            : renderKdeOverlayHeatmaps(map, markerStats.visible);

        if (mode === 'markers') {
            const markerLayer = window.L.geoJSON(featureCollection, {
                pointToLayer(feature, latlng) {
                    const kind = coordinateKind(feature);
                    const color = barangayColor(feature.properties?.barangay);
                    const marker = window.L.marker(latlng, {
                        icon: createMarkerIcon(color, kind),
                        gisRiskLevel: feature.properties?.risk_level,
                        gisBarangay: feature.properties?.barangay,
                        gisCoordinateKind: kind,
                        pane: 'gis-senior-pane',
                    });

                    attachSeniorPopup(marker, feature);

                    return marker;
                },
            });

            if (shouldClusterMarkers()) {
                const markerClusterLayer = window.L.markerClusterGroup({
                    showCoverageOnHover: false,
                    spiderfyOnMaxZoom: true,
                    disableClusteringAtZoom: 16,
                    maxClusterRadius: 26,
                    iconCreateFunction(cluster) {
                        const markers = cluster.getAllChildMarkers();
                        const tone = clusterTone(markers);

                        return window.L.divIcon({
                            html: `<div style="background:${tone};color:#fff;width:34px;height:34px;border-radius:9999px;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.95);box-shadow:0 8px 18px rgba(15,23,42,0.18);font-size:11px;font-weight:700;">${cluster.getChildCount()}</div>`,
                            className: 'gis-cluster-icon',
                            iconSize: [34, 34],
                        });
                    },
                });

                markerClusterLayer.addLayer(markerLayer);
                layers.seniors.addLayer(markerClusterLayer);
            } else {
                layers.seniors.addLayer(markerLayer);
            }

            focusMapOnActiveLayer(map, markerStats.visible.length ? markerStats.visible : activeFeatures);
            const overlayText = kdeOverlayResults.length ? ` ${kdeOverlayResults.length} KDE heatmap overlay(s) active.` : '';
            setStatus(`${validationStatusText(activeFeatures.length, markerStats)}${overlayText}`, 'success');
            return;
        }

        if (isHeatmapMode(mode)) {
            if (toggleGisLayer(map, mode, markerStats.visible)) {
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
            const overlayText = kdeOverlayResults.length ? ` ${kdeOverlayResults.length} KDE heatmap overlay(s) also active.` : '';
            setStatus(`Heatmap uses ${points.length} generalized barangay point(s), weighted by actual backend senior counts. Radius is based on local GIS spacing/boundaries (${radiusMeters}m).${overlayText}`, 'success');
            return;
        }

        const { overlayGroup, pointLayer } = buildZoneOverlay(map, markerStats.visible, mode);
        layers.riskOverlay.addLayer(overlayGroup);
        if (facilityGeoJson?.features?.length) {
            layers.facilities.addLayer(facilityLayer);
        }
        layers.seniors.addLayer(pointLayer);
        focusMapOnActiveLayer(map, markerStats.visible);
        const overlayText = kdeOverlayResults.length ? ` ${kdeOverlayResults.length} KDE heatmap overlay(s) active.` : '';
        setStatus(`Overlay uses ${markerStats.visible.length} generalized barangay point(s).${overlayText}`, 'success');
    }

    function refreshRenderedLayer() {
        const el = document.getElementById(MAP_ID);
        const map = el?._leaflet_map_instance;
        if (!el || !map || !latestSeniorGeoJson) return;

        renderDataLayers(map, latestSeniorGeoJson, latestFacilityGeoJson ?? emptyFeatureCollection());
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

    function renderMap() {
        const el = document.getElementById(MAP_ID);
        if (!el || !window.L) return;
        const requestId = ++latestRequestId;
        latestSeniorGeoJson = null;
        latestFacilityGeoJson = null;
        latestMunicipalBoundaryGeoJson = null;
        latestBarangayBoundaryGeoJson = null;
        latestRouteDistanceUrl = el.dataset.routeDistanceUrl || null;
        setStatus('Loading GIS layers for Pagsanjan...', 'neutral');

        if (el._leaflet_id) {
            if (el._leaflet_map_instance) {
                el._leaflet_map_instance.off();
                el._leaflet_map_instance.remove();
            }
            if (el._gisResizeObserver) {
                el._gisResizeObserver.disconnect();
                el._gisResizeObserver = null;
            }
            el.innerHTML = '';
        }

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
        }).setView(PAGSANJAN_CENTER, DEFAULT_ZOOM);
        el._leaflet_map_instance = map;
        ensureMapPanes(map);
        ensureLayerRegistry(map);
        applyMapBoundaryConstraints(map);
        applyMapZoomConstraints(map);

        createTileLayer().addTo(map);

        map.on('zoomend moveend', () => refreshHeatmapLayersForZoom(map));
        map.on('click', (event) => {
            openBarangayPopupAt(map, event.latlng);
        });

        focusMapOnPagsanjan(map);
        attachResizeObserver(map, el);
        scheduleMapSizeSync(map);

        Promise.all([
            fetchGeoJson(el.dataset.geojsonUrl, requestId, 'Senior'),
            fetchGeoJson(el.dataset.facilitiesUrl, requestId, 'Facility', emptyFeatureCollection('database')),
            fetchGeoJson(el.dataset.pagsanjanBoundaryUrl, requestId, 'Pagsanjan boundary', emptyFeatureCollection('file')),
            fetchGeoJson(el.dataset.barangayBoundariesUrl, requestId, 'Barangay boundaries', emptyFeatureCollection('file')),
        ])
            .then(([seniorGeoJson, facilityGeoJson, municipalBoundaryGeoJson, barangayBoundaryGeoJson]) => {
                if (requestId !== latestRequestId) return;

                latestSeniorGeoJson = seniorGeoJson;
                latestFacilityGeoJson = facilityGeoJson;
                latestMunicipalBoundaryGeoJson = municipalBoundaryGeoJson;
                latestBarangayBoundaryGeoJson = barangayBoundaryGeoJson;
                initializeFilters(seniorGeoJson.features || []);
                renderBoundaryLayers(map, municipalBoundaryGeoJson, barangayBoundaryGeoJson);
                applyMapBoundaryConstraints(map);
                applyMapZoomConstraints(map);
                renderDataLayers(map, seniorGeoJson, facilityGeoJson);
                scheduleMapSizeSync(map);
            })
            .catch((error) => {
                if (requestId !== latestRequestId) return;

                console.error('Failed to load GIS data:', error);
                setStatus('GIS data could not be loaded.', 'error');
            });
    }

    document.addEventListener('change', function (event) {
        if ([MODE_ID, BARANGAY_FILTER_ID, RISK_FILTER_ID, CLUSTER_FILTER_ID, CLUSTER_POINTS_TOGGLE_ID].includes(event.target?.id) || event.target?.matches?.(KDE_OVERLAY_SELECTOR)) {
            refreshRenderedLayer();
        }
    });
    document.addEventListener('DOMContentLoaded', renderMap);
    document.addEventListener('livewire:navigated', () => setTimeout(renderMap, 0));
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
})();
</script>
@endpush
