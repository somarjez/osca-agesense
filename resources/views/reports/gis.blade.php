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
    const CLUSTER_HEATMAP_GRADIENT = {
        0.15: '#0ea5e9',
        0.40: '#10b981',
        0.65: '#f59e0b',
        1.00: '#f43f5e',
    };
    const CLUSTER_HEATMAP_COLORS = {
        'Group 1': '#10b981',
        'Group 2': '#8b5cf6',
        'Group 3': '#f43f5e',
    };
    const BARANGAY_COLORS = [
        '#14b8a6', '#f97316', '#8b5cf6', '#22c55e',
        '#eab308', '#06b6d4', '#ef4444', '#84cc16',
        '#f59e0b', '#6366f1', '#ec4899', '#10b981',
        '#0ea5e9', '#a855f7', '#65a30d', '#dc2626',
    ];
    const ACCESSIBILITY_DISTANCE_CAP_METERS = 1500;
    const ROAD_ROUTE_SERVICE_URL = 'https://router.project-osrm.org/route/v1/driving';
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

    function clusterColor(cluster) {
        const normalized = (cluster || '').toLowerCase();
        if (normalized.includes('group 1')) return CLUSTER_HEATMAP_COLORS['Group 1'];
        if (normalized.includes('group 2')) return CLUSTER_HEATMAP_COLORS['Group 2'];
        if (normalized.includes('group 3')) return CLUSTER_HEATMAP_COLORS['Group 3'];
        return '#64748b';
    }

    function clusterLabel(feature) {
        return String(feature?.properties?.cluster || feature?.properties?.cluster_label || 'Unassigned');
    }

    function clusterColorForLabel(label) {
        return CLUSTER_HEATMAP_COLORS[label] ?? clusterColor(label);
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
        const normalized = (cluster || '').toLowerCase();
        if (normalized.includes('group 3')) return 1.0;
        if (normalized.includes('group 2')) return 0.75;
        if (normalized.includes('group 1')) return 0.55;
        return 0.4;
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
        const normalized = (cluster || '').toLowerCase();
        if (normalized.includes('group 3')) return 3;
        if (normalized.includes('group 2')) return 2;
        if (normalized.includes('group 1')) return 1;
        return 1;
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
                legendEl.innerHTML = `
                    <span class="font-semibold text-ink-700 dark:text-[#b0b5b2]">${heatmapLabel[0]}</span>
                    <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full inline-block" style="background:${CLUSTER_HEATMAP_COLORS['Group 1']};"></span>Group 1</span>
                    <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full inline-block" style="background:${CLUSTER_HEATMAP_COLORS['Group 2']};"></span>Group 2</span>
                    <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full inline-block" style="background:${CLUSTER_HEATMAP_COLORS['Group 3']};"></span>Group 3</span>
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
        setSelectOptions(CLUSTER_FILTER_ID, 'All Groups', uniqueSortedValues(features, 'cluster'));
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

            if (selectedCluster !== 'all' && props.cluster !== selectedCluster) {
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
        const fallbackRadius = mode === 'density-heatmap' ? 360 : 260;
        const derivedRadius = median([spacingRadius ? spacingRadius * 1.35 : null, boundaryRadius, fallbackRadius]);

        if (mode === 'density-heatmap') {
            return Math.max(220, Math.min(720, derivedRadius ?? fallbackRadius));
        }

        if (mode === 'accessibility-heatmap') {
            return Math.max(180, Math.min(560, derivedRadius ?? fallbackRadius));
        }

        if (mode === 'cluster-heatmap') {
            return Math.max(110, Math.min(320, derivedRadius ?? fallbackRadius));
        }

        return Math.max(160, Math.min(480, derivedRadius ?? fallbackRadius));
    }

    function heatmapPixelOptions(map, features, mode) {
        const meters = heatmapRadiusMeters(features, mode);
        const reference = heatmapReferenceLatLng(map, features);
        const rawRadius = metersToPixelsAtLatLng(map, reference, meters);
        // Keep the browser KDE surface concentrated: nearby points blend, while
        // isolated records stay light and empty areas remain transparent.
        const radius = Math.round(Math.max(24, Math.min(170, rawRadius)));

        return {
            radius,
            blur: Math.round(Math.max(16, Math.min(95, radius * 0.72))),
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
        return Object.entries(heatmapGradient(mode))
            .map(([stop, color]) => ({
                stop: Number(stop),
                color: hexToRgb(color),
            }))
            .sort((a, b) => a.stop - b.stop);
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
                const radius = this._options.radius;
                const blur = Math.max(1, Math.min(radius, this._options.blur || radius * 0.72));
                const coreStop = clampUnit((radius - blur) / radius);
                const shoulderStop = clampUnit(coreStop + ((1 - coreStop) * 0.45));
                const edgeStop = clampUnit(coreStop + ((1 - coreStop) * 0.82));
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

                    const coreAlpha = Math.min(0.72, intensity * 0.62);
                    const shoulderAlpha = Math.min(0.34, intensity * 0.30);
                    const edgeAlpha = Math.min(0.12, intensity * 0.11);
                    const gradient = densityContext.createRadialGradient(point.x, point.y, 0, point.x, point.y, radius);
                    gradient.addColorStop(0, `rgba(0,0,0,${coreAlpha})`);
                    gradient.addColorStop(Math.max(0.01, coreStop), `rgba(0,0,0,${coreAlpha * 0.72})`);
                    gradient.addColorStop(shoulderStop, `rgba(0,0,0,${shoulderAlpha})`);
                    gradient.addColorStop(edgeStop, `rgba(0,0,0,${edgeAlpha})`);
                    gradient.addColorStop(1, 'rgba(0,0,0,0)');

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

                    const normalized = clampUnit(alpha / 255);
                    const [red, green, blue] = colorForGradientValue(normalized, this._stops);

                    outputImage.data[index] = red;
                    outputImage.data[index + 1] = green;
                    outputImage.data[index + 2] = blue;
                    outputImage.data[index + 3] = Math.round(Math.min(190, 220 * Math.pow(normalized, 0.94)));
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
                const dominancePower = this._options.dominancePower || 1.7;
                const groupImages = this._groups.map((group) => ({
                    label: group.label,
                    color: hexToRgb(group.color),
                    data: this._densityForGroup(group, width, height, radius),
                }));
                const outputContext = this._canvas.getContext('2d');
                const outputImage = outputContext.createImageData(width, height);

                for (let index = 0; index < outputImage.data.length; index += 4) {
                    let dominantAlpha = 0;
                    let totalAlpha = 0;
                    let weightedRed = 0;
                    let weightedGreen = 0;
                    let weightedBlue = 0;
                    let totalColorWeight = 0;

                    groupImages.forEach((group) => {
                        const alpha = group.data[index + 3];
                        totalAlpha += alpha;
                        if (alpha > dominantAlpha) {
                            dominantAlpha = alpha;
                        }

                        const colorWeight = Math.pow(clampUnit(alpha / 255), dominancePower);
                        weightedRed += group.color[0] * colorWeight;
                        weightedGreen += group.color[1] * colorWeight;
                        weightedBlue += group.color[2] * colorWeight;
                        totalColorWeight += colorWeight;
                    });

                    if (dominantAlpha < 3 || totalColorWeight <= 0) {
                        continue;
                    }

                    const normalized = clampUnit(totalAlpha / 255);

                    // Health group heatmap: colors are assigned per real group, then
                    // blended only where group density surfaces meet. The exponent
                    // keeps each group's core true to its assigned color while making
                    // shared borders transition smoothly like a real heatmap surface.
                    outputImage.data[index] = Math.round(weightedRed / totalColorWeight);
                    outputImage.data[index + 1] = Math.round(weightedGreen / totalColorWeight);
                    outputImage.data[index + 2] = Math.round(weightedBlue / totalColorWeight);
                    outputImage.data[index + 3] = Math.round(Math.min(205, 225 * Math.pow(normalized, 0.9)));
                }

                outputContext.clearRect(0, 0, width, height);
                outputContext.putImageData(outputImage, 0, 0);
            },
        });

        return new ClusterDistributionLayer();
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

    function buildHeatmapLayer(map, features, mode, options = {}) {
        const points = heatmapPoints(features, mode);

        const gradient = options.gradient ?? heatmapGradient(mode);
        const pixelOptions = heatmapPixelOptions(map, features, mode);
        const maxIntensity = heatmapNormalization(points, pixelOptions.radius, mode);

        // KDE-style note: this is a browser-rendered, privacy-safe density surface.
        // The custom canvas layer draws smooth radial kernels around existing senior GIS points;
        // the point radius is derived from local GeoJSON bounds and senior spacing,
        // not from a QGIS-generated raster or external GIS preprocessing.
        return {
            points,
            radiusMeters: pixelOptions.radius_meters,
            layer: createCanvasKdeLayer(points, {
                pane: 'gis-heat-pane',
                mode,
                radius: pixelOptions.radius,
                blur: pixelOptions.blur,
                maxZoom: map?.getZoom?.() ?? 17,
                minOpacity: 0.22,
                max: maxIntensity,
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
        const clusterId = numericValue(props.cluster_id);
        if (clusterId !== null) {
            return clampUnit(0.35 + (Math.min(Math.max(clusterId, 1), 5) - 1) * 0.16);
        }

        return clusterWeight(props.cluster_label || props.cluster);
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
        if (!maxCount || count <= 0) return '#dbeafe';
        const ratio = count / maxCount;
        if (ratio >= 0.75) return '#ef4444';
        if (ratio >= 0.50) return '#fb923c';
        if (ratio >= 0.25) return '#facc15';
        return '#38bdf8';
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
        const distanceLabel = options.route ? ' route' : '';
        const distanceText = distance !== null
            ? ` - ${formatServiceDistance(distance)}${distanceLabel}${durationText}`
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

                return {
                    color: selected === 'all' ? '#475569' : '#0f172a',
                    weight: selected === 'all' ? 1.4 : 2.8,
                    opacity: 0.9,
                    fillColor: color,
                    fillOpacity: selected === 'all' ? 0.24 : 0.36,
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
            map.getPane('gis-mask-pane').style.zIndex = 590;
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
                const props = feature.properties || {};
                const clusterLabel = String(props.cluster || props.cluster_label || '');

                return clusterLabel.toLowerCase() !== 'unassigned'
                    && (selectedCluster === 'all' || clusterLabel === selectedCluster);
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
            const label = clusterLabel(feature);
            if (!label || label.toLowerCase() === 'unassigned') {
                return;
            }

            const group = groups.get(label) ?? [];
            group.push(feature);
            groups.set(label, group);
        });

        return [...groups.entries()].sort(([a], [b]) => a.localeCompare(b));
    }

    function buildClusterDistributionHeatmapLayer(map, features) {
        const clusterGroups = groupFeaturesByCluster(features);
        const clusterFeatures = clusterGroups.flatMap(([, groupFeatures]) => groupFeatures);
        const pixelOptions = heatmapPixelOptions(map, clusterFeatures, 'cluster-heatmap');
        const groups = clusterGroups
            .map(([label, groupFeatures]) => ({
                label,
                color: clusterColorForLabel(label),
                points: heatmapPoints(groupFeatures, 'cluster-heatmap'),
            }))
            .filter((group) => group.points.length > 0);

        if (!groups.length) {
            return {
                layer: null,
                points: { length: 0 },
                groups: [],
                radiusMeters: pixelOptions.radius_meters,
            };
        }

        return {
            points: { length: groups.reduce((total, group) => total + group.points.length, 0) },
            groups: groups.map((group) => group.label),
            radiusMeters: pixelOptions.radius_meters,
            layer: createClusterDistributionKdeLayer(groups, {
                radius: Math.max(18, Math.round(pixelOptions.radius * 0.78)),
                radius_meters: pixelOptions.radius_meters,
                dominancePower: 1.7,
            }),
        };
    }

    function setActiveHeatmapContext(map, mode, features) {
        map._gisActiveHeatmap = {
            mode,
            features: [...features],
        };
    }

    function refreshActiveHeatmapRadius(map) {
        const context = map?._gisActiveHeatmap;
        if (!context || !isHeatmapMode(context.mode)) {
            return;
        }

        const layers = ensureLayerRegistry(map);
        layers.heatmap.clearLayers();

        if (context.mode === 'cluster-heatmap' && selectedClusterGroup() === 'all') {
            const result = buildClusterDistributionHeatmapLayer(map, context.features);
            if (result.layer && result.points.length) {
                layers.heatmap.addLayer(result.layer);
                context.radiusMeters = result.radiusMeters;
            }
            return;
        }

        const { layer: heatLayer, points, radiusMeters } = buildHeatmapLayer(map, context.features, context.mode);

        if (points.length && heatLayer) {
            layers.heatmap.addLayer(heatLayer);
            context.radiusMeters = radiusMeters;
        }
    }

    function setKdeOverlayContext(map, mode, features) {
        map._gisKdeOverlayContexts = map._gisKdeOverlayContexts || {};
        map._gisKdeOverlayContexts[mode] = {
            mode,
            features: [...features],
        };
    }

    function renderKdeOverlayHeatmap(map, mode, features) {
        const layerGroup = kdeLayerForMode(map, mode);
        if (!layerGroup) {
            return null;
        }

        layerGroup.clearLayers();

        if (mode === 'cluster-heatmap') {
            if (selectedClusterGroup() === 'all') {
                const result = buildClusterDistributionHeatmapLayer(map, features);
                if (!result.layer || !result.points.length) return null;

                layerGroup.addLayer(result.layer);
                setKdeOverlayContext(map, mode, features);

                return result;
            }

            const heatmapFeatures = heatmapFeaturesForMode(features, mode);
            const selectedColor = clusterColorForLabel(selectedClusterGroup());
            const { layer: heatLayer, points, radiusMeters } = buildHeatmapLayer(map, heatmapFeatures, mode, {
                gradient: singleColorGradient(selectedColor),
            });

            if (!points.length || !heatLayer) {
                return null;
            }

            layerGroup.addLayer(heatLayer);
            setKdeOverlayContext(map, mode, heatmapFeatures);

            return { points, radiusMeters, groups: [selectedClusterGroup()] };
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

            layerGroup.clearLayers();

            if (context.mode === 'cluster-heatmap' && selectedClusterGroup() === 'all') {
                const result = buildClusterDistributionHeatmapLayer(map, context.features);
                if (result.layer && result.points.length) {
                    layerGroup.addLayer(result.layer);
                    context.radiusMeters = result.radiusMeters;
                }
                return;
            }

            const { layer: heatLayer, points, radiusMeters } = buildHeatmapLayer(map, context.features, context.mode);

            if (points.length && heatLayer) {
                layerGroup.addLayer(heatLayer);
                context.radiusMeters = radiusMeters;
            }
        });
    }

    function refreshHeatmapLayersForZoom(map) {
        refreshActiveHeatmapRadius(map);
        refreshKdeOverlayHeatmaps(map);
    }

    function renderRiskHeatmap(map, features) {
        clearHeatmapLayers(map);

        const { layer: heatLayer, points, radiusMeters } = buildHeatmapLayer(map, features, 'risk-indicator-heatmap');

        if (!window.L.Layer) {
            focusMapOnActiveLayer(map, features);
            setStatus('Leaflet is unavailable. Rebuild frontend assets to enable GIS heatmaps.', 'error');
            return;
        }

        if (!points.length || !heatLayer) {
            focusMapOnActiveLayer(map, features);
            setStatus('No senior records had risk indicator values for the selected filters.', 'neutral');
            return;
        }

        ensureLayerRegistry(map).heatmap.addLayer(heatLayer);
        setActiveHeatmapContext(map, 'risk-indicator-heatmap', features);
        focusMapOnActiveLayer(map, features);
        setStatus(`Risk Indicator Distribution uses ${points.length} senior GIS point(s), weighted by existing risk score or risk level. Radius is based on local GIS spacing/boundaries (${radiusMeters}m).`, 'success');
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
            setActiveHeatmapContext(map, 'cluster-heatmap', features);
            focusMapOnActiveLayer(map, clusterFeatures);
            setStatus(`Health Group Cluster Distribution shows ${result.points.length} senior GIS point(s) across ${result.groups.length} group(s). Each pixel uses the strongest nearby group color, so nearby groups remain distinct.`, 'success');
            return;
        }

        const clusterColorValue = CLUSTER_HEATMAP_COLORS[selectedCluster] ?? clusterColor(selectedCluster);
        const { layer: heatLayer, points, radiusMeters } = buildHeatmapLayer(map, clusterFeatures, 'cluster-heatmap', {
            gradient: singleColorGradient(clusterColorValue),
        });

        if (!window.L.Layer) {
            focusMapOnActiveLayer(map, features);
            setStatus('Leaflet is unavailable. Rebuild frontend assets to enable GIS heatmaps.', 'error');
            return;
        }

        if (!points.length || !heatLayer) {
            focusMapOnActiveLayer(map, features);
            setStatus('No senior records had health group cluster values for the selected filters.', 'neutral');
            return;
        }

        ensureLayerRegistry(map).heatmap.addLayer(heatLayer);
        setActiveHeatmapContext(map, 'cluster-heatmap', clusterFeatures);
        focusMapOnActiveLayer(map, clusterFeatures);
        setStatus(`Health Group Cluster Distribution shows ${points.length} senior GIS point(s) in ${selectedCluster}. Radius is based on local GIS spacing/boundaries (${radiusMeters}m).`, 'success');
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
            fillColor: '#ffffff',
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
        const kdeOverlayResults = renderKdeOverlayHeatmaps(map, markerStats.visible);

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

        window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors',
            updateWhenIdle: true,
            keepBuffer: 4,
        }).addTo(map);

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
        if ([MODE_ID, BARANGAY_FILTER_ID, RISK_FILTER_ID, CLUSTER_FILTER_ID].includes(event.target?.id) || event.target?.matches?.(KDE_OVERLAY_SELECTOR)) {
            refreshRenderedLayer();
        }
    });
    document.addEventListener('DOMContentLoaded', renderMap);
    document.addEventListener('livewire:navigated', () => setTimeout(renderMap, 0));
    window.addEventListener('resize', () => {
        const map = document.getElementById(MAP_ID)?._leaflet_map_instance;
        syncMapSize(map);
    });
})();
</script>
@endpush
