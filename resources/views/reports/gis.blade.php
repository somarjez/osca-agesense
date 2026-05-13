@extends('layouts.app')
@section('page-title', 'GIS Analytics')
@section('page-subtitle', 'Spatial visibility for senior distribution and community accessibility context')

@section('content')
<div class="space-y-5">

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        @foreach ([
            ['id' => 'gis-stat-total', 'label' => 'Total Mapped Seniors', 'value' => $stats['mapped_seniors'], 'tone' => 'bg-emerald-50 border-emerald-200 text-emerald-700', 'caption' => 'Current visible records'],
            ['id' => 'gis-stat-high-risk', 'label' => 'High Risk Seniors', 'value' => $stats['high_risk_mapped'], 'tone' => 'bg-orange-50 border-orange-200 text-orange-700', 'caption' => 'Current visible records'],
            ['id' => 'gis-stat-barangays', 'label' => 'Barangays Covered', 'value' => $stats['barangays_covered'], 'tone' => 'bg-sky-50 border-sky-200 text-sky-700', 'caption' => 'Distinct visible barangays'],
            ['id' => 'gis-stat-source', 'label' => 'Data Source', 'value' => 'Loading', 'tone' => 'bg-violet-50 border-violet-200 text-violet-700', 'caption' => 'API-driven GIS source'],
        ] as $card)
        <div class="{{ $card['tone'] }} border rounded-xl p-5">
            <p class="text-xs font-bold uppercase tracking-wider">{{ $card['label'] }}</p>
            <p id="{{ $card['id'] }}" class="text-3xl font-bold mt-2">{{ is_numeric($card['value']) ? number_format($card['value']) : $card['value'] }}</p>
            <p class="text-xs mt-1 opacity-75">{{ $card['caption'] }}</p>
        </div>
        @endforeach
    </div>

    <div class="bg-paper-2 border border-paper-rule rounded-2xl px-5 py-4 shadow-sm">
        <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-ink-500">Prototype Note</p>
        <p class="text-sm text-ink-700 mt-1 leading-relaxed">Map points are generalized for privacy and do not represent exact home addresses.</p>
    </div>

    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
        <div class="bg-slate-50 border-b border-slate-100 px-5 py-4">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h3 class="text-sm font-semibold text-slate-700">Senior Citizen Spatial Distribution</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Generalized senior distribution and accessibility context within Pagsanjan</p>
                </div>
                <span class="text-xs text-slate-500 whitespace-nowrap">Centered on Pagsanjan, Laguna</span>
            </div>
        </div>

        <div class="p-5 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-1.5">Visualization</span>
                    <select id="gis-visualization-mode"
                            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500/60 focus:border-teal-400">
                        <option value="markers">Senior Distribution Points</option>
                        <option value="risk-zones">Risk Zone Overlay</option>
                        <option value="accessibility-zones">Accessibility Coverage View</option>
                    </select>
                </label>
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-1.5">Barangay</span>
                    <select id="gis-barangay-filter"
                            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500/60 focus:border-teal-400">
                        <option value="all">All Barangays</option>
                    </select>
                </label>
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-1.5">Risk Level</span>
                    <select id="gis-risk-filter"
                            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500/60 focus:border-teal-400">
                        <option value="all">All Risk Levels</option>
                        <option value="low">Low</option>
                        <option value="moderate">Moderate</option>
                        <option value="high">High</option>
                    </select>
                </label>
                <label class="block">
                    <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-1.5">Cluster / Health Group</span>
                    <select id="gis-cluster-filter"
                            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500/60 focus:border-teal-400">
                        <option value="all">All Groups</option>
                    </select>
                </label>
            </div>

            <div id="gis-map"
                 class="rounded-2xl border border-slate-200 bg-slate-50 min-h-[420px] md:min-h-[460px]"
                 data-geojson-url="{{ route('api.gis.seniors', [], false) }}"
                 data-facilities-url="{{ route('api.gis.facilities', [], false) }}"
                 data-pagsanjan-boundary-url="{{ route('api.gis.boundary.pagsanjan', [], false) }}"
                 data-barangay-boundaries-url="{{ route('api.gis.boundary.barangays', [], false) }}">
            </div>
            <div>
                <p id="gis-map-status" class="text-xs text-slate-500">Loading sample generalized GIS data...</p>
            </div>
            <div id="gis-map-legend" class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-slate-500">
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500 inline-block"></span>Low</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-500 inline-block"></span>Moderate</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-orange-500 inline-block"></span>High</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-sky-600 inline-block"></span>Facilities</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-rose-400 inline-block"></span>Outer Zone</span>
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
    const MIN_ZOOM = 13;
    const MAX_ZOOM = 18;
    const DEFAULT_FOCUS_BOUNDS_COORDS = [
        [14.2585, 121.4425],
        [14.2835, 121.4685],
    ];
    const NAVIGATION_BOUNDS_COORDS = [
        [14.2470, 121.4300],
        [14.2950, 121.4815],
    ];
    const MAP_FIT_OPTIONS = {
        padding: [24, 24],
        maxZoom: 15,
        animate: false,
    };
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
                return 0.7;
            case 'LOW':
                return 0.45;
            default:
                return 0.4;
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
        statusEl.className = 'text-xs mt-0';

        if (tone === 'success') {
            statusEl.classList.add('text-emerald-600');
        } else if (tone === 'error') {
            statusEl.classList.add('text-red-600');
        } else {
            statusEl.classList.add('text-slate-500');
        }
    }

    function boundsFromCoords(coords) {
        return window.L.latLngBounds(coords[0], coords[1]);
    }

    function hasBoundaryFeatures(geojson) {
        return Array.isArray(geojson?.features) && geojson.features.length > 0;
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
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500 inline-block"></span>Low</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-500 inline-block"></span>Moderate</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-orange-500 inline-block"></span>High</span>
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
            return `${geojson?.total ?? geojson.features?.length ?? 0} senior records loaded using generalized barangay-based placement.`;
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

    function featureLatLng(feature) {
        const coords = feature.geometry?.coordinates;
        if (!Array.isArray(coords) || coords.length < 2) return null;
        return window.L.latLng(Number(coords[1]), Number(coords[0]));
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

    function createMarkerIcon(color) {
        return window.L.divIcon({
            className: 'gis-marker-icon',
            html: `<span style="display:block;width:14px;height:14px;border-radius:9999px;background:${color};border:2px solid #ffffff;box-shadow:0 4px 10px rgba(15,23,42,0.18);"></span>`,
            iconSize: [14, 14],
            iconAnchor: [7, 7],
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

    function popupHtml(properties) {
        const p = properties || {};
        return `
            <div class="space-y-1 text-[12px] leading-snug">
                <div><strong>ID:</strong> ${p.anonymized_id ?? 'N/A'}</div>
                <div><strong>Barangay:</strong> ${p.barangay ?? 'N/A'}</div>
                <div><strong>Age:</strong> ${p.age ?? 'N/A'}</div>
                <div><strong>Risk Level:</strong> ${p.risk_level ?? 'N/A'}</div>
                <div><strong>Cluster:</strong> ${p.cluster ?? 'N/A'}</div>
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
            style() {
                return options.style;
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
        if (!map.getPane('gis-barangay-pane')) {
            map.createPane('gis-barangay-pane');
            map.getPane('gis-barangay-pane').style.zIndex = 380;
        }

        if (!map.getPane('gis-municipal-pane')) {
            map.createPane('gis-municipal-pane');
            map.getPane('gis-municipal-pane').style.zIndex = 390;
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
            riskOverlay: window.L.layerGroup().addTo(map),
            facilities: window.L.layerGroup().addTo(map),
            seniors: window.L.layerGroup().addTo(map),
        };

        map._gisLayerRegistry = registry;

        return registry;
    }

    function clearDynamicLayers(map) {
        const layers = ensureLayerRegistry(map);
        layers.riskOverlay.clearLayers();
        layers.facilities.clearLayers();
        layers.seniors.clearLayers();
    }

    function renderBoundaryLayers(map, municipalGeoJson, barangayGeoJson) {
        const layers = ensureLayerRegistry(map);
        layers.municipalBoundary.clearLayers();
        layers.barangayBoundaries.clearLayers();

        if (hasBoundaryFeatures(barangayGeoJson)) {
            layers.barangayBoundaries.addLayer(buildBoundaryLayer(barangayGeoJson, {
                pane: 'gis-barangay-pane',
                tooltip: true,
                style: {
                    color: '#94a3b8',
                    weight: 1,
                    opacity: 0.65,
                    fillColor: '#cbd5e1',
                    fillOpacity: 0.06,
                },
            }));
        }

        if (hasBoundaryFeatures(municipalGeoJson)) {
            layers.municipalBoundary.addLayer(buildBoundaryLayer(municipalGeoJson, {
                pane: 'gis-municipal-pane',
                tooltip: false,
                style: {
                    color: '#334155',
                    weight: 2,
                    opacity: 0.8,
                    fillOpacity: 0,
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

    function focusMapOnPagsanjan(map) {
        map.fitBounds(boundsFromCoords(DEFAULT_FOCUS_BOUNDS_COORDS), MAP_FIT_OPTIONS);
    }

    function focusMapOnActiveLayer(map, activeFeatures) {
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

        if (hasBoundaryFeatures(latestMunicipalBoundaryGeoJson)) {
            const municipalBounds = window.L.geoJSON(latestMunicipalBoundaryGeoJson).getBounds();
            if (municipalBounds.isValid()) {
                map.fitBounds(municipalBounds.pad(0.08), MAP_FIT_OPTIONS);
                return;
            }
        }

        focusMapOnPagsanjan(map);
    }

    function renderDataLayers(map, seniorGeoJson, facilityGeoJson) {
        const mode = document.getElementById(MODE_ID)?.value ?? 'markers';
        const activeFeatures = filteredFeatures(seniorGeoJson.features || []);
        const facilityCollection = {
            type: 'FeatureCollection',
            features: facilityGeoJson?.features || [],
        };
        const layers = ensureLayerRegistry(map);
        clearDynamicLayers(map);
        updateLegend(mode);
        updateSummaryCards(seniorGeoJson, activeFeatures);

        if (!activeFeatures.length) {
            updateSummaryCards(seniorGeoJson, activeFeatures);
            focusMapOnPagsanjan(map);
            setStatus('No senior records matched the selected filters.', 'neutral');
            return;
        }

        const featureCollection = {
            type: 'FeatureCollection',
            features: activeFeatures,
        };
        const facilityLayer = buildFacilityLayer(facilityCollection);

        if (mode === 'markers') {
            const markerClusterLayer = window.L.markerClusterGroup({
                showCoverageOnHover: false,
                spiderfyOnMaxZoom: true,
                disableClusteringAtZoom: 17,
                maxClusterRadius: 40,
                iconCreateFunction(cluster) {
                    const markers = cluster.getAllChildMarkers();
                    const hasHigh = markers.some((marker) => (marker.options.gisRiskLevel || '').toUpperCase() === 'HIGH');
                    const hasModerate = markers.some((marker) => (marker.options.gisRiskLevel || '').toUpperCase() === 'MODERATE');
                    const tone = hasHigh ? '#f97316' : (hasModerate ? '#f59e0b' : '#10b981');

                    return window.L.divIcon({
                        html: `<div style="background:${tone};color:#fff;width:38px;height:38px;border-radius:9999px;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.95);box-shadow:0 8px 18px rgba(15,23,42,0.18);font-size:12px;font-weight:700;">${cluster.getChildCount()}</div>`,
                        className: 'gis-cluster-icon',
                        iconSize: [38, 38],
                    });
                },
            });

            const markerLayer = window.L.geoJSON(featureCollection, {
                pointToLayer(feature, latlng) {
                    const marker = window.L.marker(latlng, {
                        icon: createMarkerIcon(riskColor(feature.properties?.risk_level)),
                        gisRiskLevel: feature.properties?.risk_level,
                        pane: 'gis-senior-pane',
                    });

                    marker.bindPopup(popupHtml(feature.properties));

                    return marker;
                },
            });

            markerClusterLayer.addLayer(markerLayer);
            layers.seniors.addLayer(markerClusterLayer);
            if (facilityGeoJson?.features?.length) {
                layers.facilities.addLayer(facilityLayer);
            }

            focusMapOnActiveLayer(map, activeFeatures);
            setStatus(sourceStatusText(seniorGeoJson), 'success');
            return;
        }

        const { overlayGroup, pointLayer } = buildZoneOverlay(map, activeFeatures, mode);
        layers.riskOverlay.addLayer(overlayGroup);
        if (facilityGeoJson?.features?.length) {
            layers.facilities.addLayer(facilityLayer);
        }
        layers.seniors.addLayer(pointLayer);
        focusMapOnActiveLayer(map, activeFeatures);
        setStatus(sourceStatusText(seniorGeoJson), 'success');
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
        map.setMaxBounds(boundsFromCoords(NAVIGATION_BOUNDS_COORDS));
        map.options.maxBoundsViscosity = 0.25;

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
