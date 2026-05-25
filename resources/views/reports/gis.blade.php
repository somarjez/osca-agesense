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
        <p class="text-sm text-ink-700 dark:text-[#b0b5b2] mt-1 leading-relaxed">Map points are generalized for privacy and do not represent exact home addresses.</p>
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
                        <option value="density-heatmap">Senior Density Heatmap</option>
                        <option value="risk-heatmap">Risk Intensity Heatmap</option>
                        <option value="accessibility-heatmap">Accessibility Need Heatmap</option>
                        <option value="barangay-density">Barangay Density View</option>
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

            <div id="gis-map"
                 class="rounded-2xl border border-paper-rule dark:border-[#2b3530] bg-paper-2 dark:bg-[#1a201d] min-h-[420px] md:min-h-[460px]"
                 data-geojson-url="{{ route('api.gis.seniors', [], false) }}"
                 data-facilities-url="{{ route('api.gis.facilities', [], false) }}"
                 data-pagsanjan-boundary-url="{{ route('api.gis.boundary.pagsanjan', [], false) }}"
                 data-barangay-boundaries-url="{{ route('api.gis.boundary.barangays', [], false) }}">
            </div>
            <div>
                <p id="gis-map-status" class="text-[11.5px] text-ink-400 dark:text-[#6b7570]">Loading sample generalized GIS data...</p>
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
    const HEATMAP_MODES = new Set(['density-heatmap', 'risk-heatmap', 'accessibility-heatmap']);
    const HEATMAP_GRADIENT = {
        0.15: '#2563eb',
        0.35: '#22c55e',
        0.60: '#facc15',
        0.78: '#fb923c',
        1.00: '#ef4444',
    };
    const BARANGAY_COLORS = [
        '#14b8a6', '#f97316', '#8b5cf6', '#22c55e',
        '#eab308', '#06b6d4', '#ef4444', '#84cc16',
        '#f59e0b', '#6366f1', '#ec4899', '#10b981',
        '#0ea5e9', '#a855f7', '#65a30d', '#dc2626',
    ];
    const ACCESSIBILITY_DISTANCE_CAP_METERS = 1500;
    let latestRequestId = 0;
    let latestSeniorGeoJson = null;
    let latestFacilityGeoJson = null;
    let latestMunicipalBoundaryGeoJson = null;
    let latestBarangayBoundaryGeoJson = null;

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
        if (normalized.includes('group 1')) return '#10b981';
        if (normalized.includes('group 2')) return '#f59e0b';
        if (normalized.includes('group 3')) return '#f43f5e';
        return '#64748b';
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
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-teal-500 border border-white inline-block"></span>Verified senior location</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full border-2 border-dashed border-teal-500 bg-white inline-block"></span>Generalized fallback location</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-[3px] bg-sky-600 inline-block rotate-45"></span>Facility</span>
                ${boundaryLegend}
            `;
            return;
        }

        const heatmapLabels = {
            'density-heatmap': ['Senior Density Heatmap', 'Low concentration', 'High concentration'],
            'risk-heatmap': ['Risk Intensity Heatmap', 'Low risk intensity', 'High risk intensity'],
            'accessibility-heatmap': ['Accessibility Need Heatmap', 'Better access', 'Greater access need'],
        };
        const heatmapLabel = heatmapLabels[mode];

        if (heatmapLabel) {
            legendEl.innerHTML = `
                <span class="font-semibold text-ink-700 dark:text-[#b0b5b2]">${heatmapLabel[0]}</span>
                <span class="inline-flex items-center gap-2 min-w-[260px]">
                    <span>${heatmapLabel[1]}</span>
                    <span class="h-2.5 w-28 rounded-full inline-block border border-white/70" style="background:linear-gradient(90deg,#2563eb 0%,#22c55e 30%,#facc15 58%,#fb923c 78%,#ef4444 100%);"></span>
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
            const exactCount = Number(geojson?.metadata?.exact_coordinate_count ?? 0);
            const generalizedCount = Number(geojson?.metadata?.generalized_coordinate_count ?? 0);
            const needsManualPinCount = Number(geojson?.metadata?.needs_manual_pin_count ?? 0);
            const total = geojson?.total ?? geojson.features?.length ?? 0;

            if (geojson?.metadata?.exact_only) {
                if (exactCount > 0) {
                    return `${exactCount} exact saved senior pin(s) loaded. ${needsManualPinCount} record(s) still need manual location pins.`;
                }

                return `No exact saved senior pins yet. ${needsManualPinCount || generalizedCount} record(s) still need manual location pins.`;
            }

            if (exactCount > 0) {
                return `${total} senior records loaded: ${exactCount} exact saved pin(s), ${generalizedCount} generalized fallback point(s).`;
            }

            return `${total} senior records loaded using generalized barangay-based placement.`;
        }

        return 'Sample generalized GIS data loaded for prototype testing.';
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

    function filteredFeatures(features) {
        const selectedBarangay = getSelectedValue(BARANGAY_FILTER_ID);
        const selectedRisk = getSelectedValue(RISK_FILTER_ID);
        const selectedCluster = getSelectedValue(CLUSTER_FILTER_ID);

        return features.filter((feature) => {
            const props = feature.properties || {};

            if (selectedBarangay !== 'all' && props.barangay !== selectedBarangay) {
                return false;
            }

            if (selectedRisk !== 'all' && props.risk_level !== selectedRisk) {
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
            return 'Verified exact pin';
        }

        if (kind === 'imported') {
            return 'Imported coordinates';
        }

        return 'Generalized fallback';
    }

    function verifiedSkipText(skippedCount) {
        return skippedCount > 0
            ? ` ${skippedCount} senior record(s) skipped because they have no verified coordinates.`
            : '';
    }

    function validationStatusText(total, stats) {
        return `${total} senior records loaded. ${stats.verifiedShown} verified points shown. ${stats.fallbackShown} generalized fallback points shown. ${stats.outsidePagsanjan} outside Pagsanjan hidden. ${stats.mismatches} coordinate mismatches hidden.`;
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

    function heatmapWeight(feature, mode) {
        const props = feature.properties || {};

        if (mode === 'density-heatmap') {
            return 1;
        }

        if (mode === 'risk-heatmap') {
            return riskWeight(props.risk_level);
        }

        if (mode === 'accessibility-heatmap') {
            return accessibilityNeedWeight(props);
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
            .replace(/\(pob\.\)/g, '(poblacion)')
            .replace(/barangay i\s*\(pob\.\)/g, 'barangay i (poblacion)')
            .replace(/barangay ii\s*\(pob\.\)/g, 'barangay ii (poblacion)')
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

    function buildHeatmapLayer(features, mode) {
        const points = heatmapPoints(features, mode);

        if (!window.L.heatLayer) {
            return { layer: null, points };
        }

        const max = mode === 'density-heatmap'
            ? Math.max(1, Math.min(8, Math.ceil(points.length / 12)))
            : 1;

        return {
            points,
            layer: window.L.heatLayer(points, {
                pane: 'gis-heat-pane',
                radius: 34,
                blur: 28,
                maxZoom: 17,
                minOpacity: 0.25,
                max,
                gradient: HEATMAP_GRADIENT,
            }),
        };
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

            current.count++;
            if (isExactLocationFeature(feature)) current.verified++;
            if (risk !== null) {
                current.riskTotal += risk;
                current.riskCount++;
            }
            if (proximity !== null) {
                current.proximityTotal += proximity;
                current.proximityCount++;
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

    function barangayDensityTooltip(name, stat) {
        const avgRisk = stat?.riskCount ? averageRiskLabel(stat.riskTotal / stat.riskCount) : 'N/A';
        const avgProximity = stat?.proximityCount ? `${(stat.proximityTotal / stat.proximityCount).toFixed(1)}` : 'N/A';

        return `
            <div class="space-y-1 text-[12px] leading-snug">
                <div><strong>Barangay:</strong> ${name}</div>
                <div><strong>Senior Count:</strong> ${stat?.count ?? 0}</div>
                <div><strong>Verified Coordinates:</strong> ${stat?.verified ?? 0}</div>
                <div><strong>Average Risk:</strong> ${avgRisk}</div>
                <div><strong>Average GIS Proximity:</strong> ${avgProximity}</div>
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
                ? 'Cluster zone center for the active sample points.'
                : 'Risk zone center for the active sample points.'
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
                layer.bindPopup(popupHtml(feature.properties));
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

    function popupHtml(properties) {
        const p = properties || {};
        return `
            <div class="space-y-1 text-[12px] leading-snug">
                <div><strong>ID:</strong> ${p.anonymized_id ?? 'N/A'}</div>
                <div><strong>Barangay:</strong> ${p.barangay ?? 'N/A'}</div>
                <div><strong>Age:</strong> ${p.age ?? 'N/A'}</div>
                <div><strong>Risk Level:</strong> ${p.risk_level ?? 'N/A'}</div>
                <div><strong>Cluster:</strong> ${p.cluster ?? 'N/A'}</div>
                <div><strong>Coordinate Source:</strong> ${p.location_source ?? 'N/A'}</div>
                <div><strong>Location Status:</strong> ${coordinateStatusLabel({ properties: p })}</div>
                ${p.composite_risk !== null && p.composite_risk !== undefined ? `<div><strong>Composite Risk:</strong> ${Number(p.composite_risk).toFixed(2)}</div>` : ''}
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
            totalEl.textContent = new Intl.NumberFormat().format(features.length);
        }

        if (highRiskEl) {
            const highRiskCount = features.filter((feature) => (feature.properties?.risk_level || '').toUpperCase() === 'HIGH').length;
            highRiskEl.textContent = new Intl.NumberFormat().format(highRiskCount);
        }

        if (barangayEl) {
            const barangayCount = new Set(features.map((feature) => feature.properties?.barangay).filter(Boolean)).size;
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
            },
        });
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
            map.getPane('gis-mask-pane').style.zIndex = 385;
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
        layers.heatmap.clearLayers();
        layers.barangayDensity.clearLayers();
        layers.riskOverlay.clearLayers();
        layers.facilities.clearLayers();
        layers.seniors.clearLayers();
    }

    function renderBoundaryLayers(map, municipalGeoJson, barangayGeoJson) {
        const layers = ensureLayerRegistry(map);
        layers.municipalBoundary.clearLayers();
        layers.barangayBoundaries.clearLayers();
        layers.municipalMask.clearLayers();
        const selected = normalizeBarangayName(selectedBarangay());

        if (hasBoundaryFeatures(barangayGeoJson)) {
            layers.barangayBoundaries.addLayer(buildBoundaryLayer(barangayGeoJson, {
                pane: 'gis-barangay-pane',
                tooltip: true,
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
            fillColor: '#e5e7eb',
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
        const exactStats = validatedFeatureSet(activeFeatures, { exactOnly: true });
        const renderStats = mode === 'markers' || mode === 'barangay-density' ? markerStats : exactStats;
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

        if (mode === 'barangay-density') {
            layers.barangayDensity.addLayer(buildBarangayDensityLayer(activeFeatures));
            focusMapOnActiveLayer(map, markerStats.visible.length ? markerStats.visible : activeFeatures);
            setStatus(`${validationStatusText(activeFeatures.length, markerStats)} Barangay density uses filtered senior counts; tooltip separates verified coordinate counts.`, 'success');
            return;
        }

        if (mode !== 'markers' && !exactStats.visible.length) {
            focusMapOnActiveLayer(map, activeFeatures);
            setStatus(`No verified senior coordinates matched this heatmap selection.${verifiedSkipText(exactStats.skippedNoVerified)} ${exactStats.outsidePagsanjan} outside Pagsanjan hidden. ${exactStats.mismatches} coordinate mismatches hidden.`, 'neutral');
            return;
        }

        const featureCollection = {
            type: 'FeatureCollection',
            features: mode === 'markers' ? markerStats.visible : exactStats.visible,
        };

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

                    marker.bindPopup(popupHtml(feature.properties));

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
            setStatus(validationStatusText(activeFeatures.length, markerStats), 'success');
            return;
        }

        if (isHeatmapMode(mode)) {
            const { layer: heatLayer, points } = buildHeatmapLayer(exactStats.visible, mode);

            if (!window.L.heatLayer) {
                focusMapOnActiveLayer(map, exactStats.visible);
                setStatus('Heatmap plugin is unavailable. Rebuild frontend assets to enable GIS heatmaps.', 'error');
                return;
            }

            if (!points.length || !heatLayer) {
                focusMapOnActiveLayer(map, exactStats.visible);
                setStatus(`No exact saved pins had enough data for the selected heatmap mode.${verifiedSkipText(exactStats.skippedNoVerified)}`, 'neutral');
                return;
            }

            layers.heatmap.addLayer(heatLayer);
            focusMapOnActiveLayer(map, exactStats.visible);
            setStatus(`Heatmap uses ${points.length} verified coordinate point(s). ${exactStats.skippedNoVerified} generalized fallback record(s) skipped. ${exactStats.outsidePagsanjan} outside Pagsanjan hidden. ${exactStats.mismatches} coordinate mismatches hidden.`, 'success');
            return;
        }

        const { overlayGroup, pointLayer } = buildZoneOverlay(map, exactStats.visible, mode);
        layers.riskOverlay.addLayer(overlayGroup);
        if (facilityGeoJson?.features?.length) {
            layers.facilities.addLayer(facilityLayer);
        }
        layers.seniors.addLayer(pointLayer);
        focusMapOnActiveLayer(map, exactStats.visible);
        setStatus(`Overlay uses ${exactStats.visible.length} verified coordinate point(s).${verifiedSkipText(exactStats.skippedNoVerified)}`, 'success');
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
        if ([MODE_ID, BARANGAY_FILTER_ID, RISK_FILTER_ID, CLUSTER_FILTER_ID].includes(event.target?.id)) {
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
