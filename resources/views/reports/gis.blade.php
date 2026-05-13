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
                 data-geojson-url="{{ route('api.gis.seniors', [], false) }}">
            </div>
            <div>
                <p id="gis-map-status" class="text-xs text-slate-500">Loading sample generalized GIS data...</p>
            </div>
            <div id="gis-map-legend" class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-slate-500">
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500 inline-block"></span>Low</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-500 inline-block"></span>Moderate</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-orange-500 inline-block"></span>High</span>
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
    const center = [14.2708, 121.4560];
    const zoom = 15;
    const pagsanjanBounds = [
        [14.2635, 121.4485],
        [14.2768, 121.4638],
    ];
    let latestRequestId = 0;
    let latestGeoJson = null;

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

    function updateLegend(mode) {
        const legendEl = document.getElementById(LEGEND_ID);
        if (!legendEl) return;

        if (mode === 'markers') {
            legendEl.innerHTML = `
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500 inline-block"></span>Low</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-500 inline-block"></span>Moderate</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-orange-500 inline-block"></span>High</span>
            `;
            return;
        }

        legendEl.innerHTML = `
            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500 inline-block"></span>Low</span>
            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-500 inline-block"></span>Moderate</span>
            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-orange-500 inline-block"></span>High</span>
            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-rose-400 inline-block"></span>Outer Zone</span>
        `;
    }

    function sourceStatusText(geojson) {
        if (geojson?.source === 'database') {
            return `${geojson.features?.length ?? 0} senior records loaded using generalized barangay-based placement.`;
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

        if (!points.length) return window.L.latLng(center[0], center[1]);

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
            }).addTo(overlayGroup);
        });

        window.L.circleMarker(zoneCenter, {
            radius: 8,
            color: '#ffffff',
            weight: 2,
            fillColor: '#334155',
            fillOpacity: 0.95,
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
                });
            },
            onEachFeature(feature, layer) {
                layer.bindPopup(popupHtml(feature.properties));
            },
        });

        overlayGroup.addLayer(pointLayer);
        overlayGroup.addTo(map);

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

    function renderGeoJsonOnMap(map, geojson) {
        const mode = document.getElementById(MODE_ID)?.value ?? 'markers';
        const activeFeatures = filteredFeatures(geojson.features || []);

        if (map._gisLayerGroup) {
            map.removeLayer(map._gisLayerGroup);
            map._gisLayerGroup = null;
        }

        if (!activeFeatures.length) {
            map.fitBounds(pagsanjanBounds, { padding: [10, 10], maxZoom: 15 });
            updateSummaryCards(geojson, activeFeatures);
            setStatus('No senior records matched the selected filters.', 'neutral');
            return;
        }

        const featureCollection = {
            type: 'FeatureCollection',
            features: activeFeatures,
        };

        const layerGroup = window.L.layerGroup();
        map._gisLayerGroup = layerGroup;
        updateLegend(mode);
        updateSummaryCards(geojson, activeFeatures);

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
                    });

                    marker.bindPopup(popupHtml(feature.properties));

                    return marker;
                },
            });

            markerClusterLayer.addLayer(markerLayer);
            layerGroup.addLayer(markerClusterLayer).addTo(map);

            const bounds = markerLayer.getBounds();
            map.fitBounds(bounds.isValid() ? bounds.pad(0.08) : pagsanjanBounds, { padding: [16, 16], maxZoom: 15 });
            setStatus(sourceStatusText(geojson), 'success');
            return;
        }

        const { overlayGroup, pointLayer } = buildZoneOverlay(map, activeFeatures, mode);
        map._gisLayerGroup = overlayGroup;

        const bounds = pointLayer.getBounds();
        map.fitBounds(bounds.isValid() ? bounds.pad(0.08) : pagsanjanBounds, { padding: [16, 16], maxZoom: 15 });
        setStatus(sourceStatusText(geojson), 'success');
    }

    function refreshRenderedLayer() {
        const el = document.getElementById(MAP_ID);
        const map = el?._leaflet_map_instance;
        if (!el || !map || !latestGeoJson) return;

        renderGeoJsonOnMap(map, latestGeoJson);
    }

    function renderMap() {
        const el = document.getElementById(MAP_ID);
        if (!el || !window.L) return;
        const requestId = ++latestRequestId;
        latestGeoJson = null;
        setStatus('Loading sample generalized GIS data...', 'neutral');

        if (el._leaflet_id) {
            if (el._leaflet_map_instance) {
                el._leaflet_map_instance.off();
                el._leaflet_map_instance.remove();
            }
            el.innerHTML = '';
        }

        const map = window.L.map(el, {
            maxBounds: pagsanjanBounds,
            maxBoundsViscosity: 1.0,
            minZoom: 14,
        }).setView(center, zoom);
        el._leaflet_map_instance = map;

        window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors',
        }).addTo(map);

        fetch(el.dataset.geojsonUrl, {
            headers: {
                'Accept': 'application/json',
            },
        })
            .then(async (response) => {
                if (requestId !== latestRequestId) {
                    throw new Error('Stale GIS request ignored.');
                }

                const contentType = response.headers.get('content-type') || '';
                if (!response.ok) {
                    const body = await response.text();
                    throw new Error(`GIS API request failed with status ${response.status}. Body: ${body.slice(0, 200)}`);
                }
                if (!isAcceptedGeoJsonType(contentType)) {
                    const body = await response.text();
                    throw new Error(`GIS API returned non-JSON content-type "${contentType}". Body: ${body.slice(0, 200)}`);
                }
                return response.json();
            })
            .then((payload) => {
                if (requestId !== latestRequestId) return;

                const geojson = normalizeGeoJsonPayload(payload);
                if (!geojson) {
                    throw new Error('GIS API returned an invalid GeoJSON FeatureCollection.');
                }

                latestGeoJson = geojson;
                initializeFilters(geojson.features || []);
                renderGeoJsonOnMap(map, geojson);
            })
            .catch((error) => {
                if (requestId !== latestRequestId) return;

                console.error('Failed to load GIS sample data:', error);
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
})();
</script>
@endpush
