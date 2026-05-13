@extends('layouts.app')
@section('page-title', 'GIS Analytics')
@section('page-subtitle', 'Spatial visibility for senior distribution and community accessibility context')

@section('content')
<div class="space-y-5">

    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        @foreach ([
            ['label' => 'Total Mapped Seniors', 'value' => $stats['mapped_seniors'], 'tone' => 'bg-emerald-50 border-emerald-200 text-emerald-700'],
            ['label' => 'High Risk Mapped Seniors', 'value' => $stats['high_risk_mapped'], 'tone' => 'bg-orange-50 border-orange-200 text-orange-700'],
            ['label' => 'Barangays Covered', 'value' => $stats['barangays_covered'], 'tone' => 'bg-sky-50 border-sky-200 text-sky-700'],
            ['label' => 'Facilities Recorded', 'value' => $stats['facilities_recorded'], 'tone' => 'bg-violet-50 border-violet-200 text-violet-700'],
        ] as $card)
        <div class="{{ $card['tone'] }} border rounded-xl p-5">
            <p class="text-xs font-bold uppercase tracking-wider">{{ $card['label'] }}</p>
            <p class="text-3xl font-bold mt-2">{{ number_format($card['value']) }}</p>
            <p class="text-xs mt-1 opacity-75">Placeholder summary for Phase 1</p>
        </div>
        @endforeach
    </div>

    <div class="bg-amber-50 border border-amber-200 rounded-xl px-5 py-4">
        <p class="text-sm font-medium text-amber-900">Prototype note</p>
        <p class="text-sm text-amber-800 mt-1">This GIS map currently uses sample and generalized location data for prototype testing. No real names, contact numbers, or exact home addresses are exposed.</p>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="bg-slate-50 border-b border-slate-100 px-5 py-3">
            <div class="flex items-center justify-between gap-4">
                <h3 class="text-sm font-semibold text-slate-700">Senior Citizen Spatial Distribution</h3>
                <span class="text-xs text-slate-500">Centered on Pagsanjan, Laguna</span>
            </div>
        </div>

        <div class="p-5">
            <div id="gis-map"
                 class="rounded-xl border border-slate-200 bg-slate-50 min-h-[460px]"
                 data-geojson-url="{{ route('api.gis.seniors', [], false) }}">
            </div>
            <div class="mt-3">
                <p id="gis-map-status" class="text-xs text-slate-500">Loading sample generalized GIS data...</p>
                <p id="gis-map-error" class="hidden text-sm text-red-600 mt-1">Unable to load sample GIS data.</p>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500 inline-block"></span>Low</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-500 inline-block"></span>Moderate</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-orange-500 inline-block"></span>High</span>
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
    const ERROR_ID = 'gis-map-error';
    const center = [14.2702, 121.4560];
    const zoom = 14;
    const pagsanjanBounds = [
        [14.2580, 121.4450],
        [14.2795, 121.4685],
    ];
    let latestRequestId = 0;

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

    function showError(message) {
        const errorEl = document.getElementById(ERROR_ID);
        if (!errorEl) return;

        errorEl.textContent = message;
        errorEl.classList.remove('hidden');
    }

    function hideError() {
        const errorEl = document.getElementById(ERROR_ID);
        if (!errorEl) return;

        errorEl.classList.add('hidden');
        errorEl.textContent = 'Unable to load sample GIS data.';
    }

    function renderMap() {
        const el = document.getElementById(MAP_ID);
        if (!el || !window.L) return;
        const requestId = ++latestRequestId;
        hideError();
        setStatus('Loading sample generalized GIS data...', 'neutral');

        if (el._leaflet_id) {
            el._leaflet_map_instance?.remove();
            el.innerHTML = '';
        }

        const map = window.L.map(el, {
            maxBounds: pagsanjanBounds,
            maxBoundsViscosity: 1.0,
            minZoom: 13,
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
            .then((geojson) => {
                if (requestId !== latestRequestId) return;

                if (geojson?.type !== 'FeatureCollection' || !Array.isArray(geojson.features)) {
                    throw new Error('GIS API returned an invalid GeoJSON FeatureCollection.');
                }

                const layer = window.L.geoJSON(geojson, {
                    pointToLayer(feature, latlng) {
                        return window.L.circleMarker(latlng, {
                            radius: 7,
                            fillColor: riskColor(feature.properties?.risk_level),
                            color: '#ffffff',
                            weight: 2,
                            opacity: 1,
                            fillOpacity: 0.9,
                        });
                    },
                    onEachFeature(feature, layer) {
                        const p = feature.properties || {};
                        layer.bindPopup(`
                            <div class="space-y-1">
                                <div><strong>ID:</strong> ${p.anonymized_id ?? 'N/A'}</div>
                                <div><strong>Barangay:</strong> ${p.barangay ?? 'N/A'}</div>
                                <div><strong>Age:</strong> ${p.age ?? 'N/A'}</div>
                                <div><strong>Risk Level:</strong> ${p.risk_level ?? 'N/A'}</div>
                                <div><strong>Cluster:</strong> ${p.cluster ?? 'N/A'}</div>
                            </div>
                        `);
                    },
                }).addTo(map);

                const bounds = layer.getBounds();
                if (bounds.isValid()) {
                    map.fitBounds(bounds.pad(0.2));
                } else {
                    map.fitBounds(pagsanjanBounds);
                }

                hideError();
                setStatus('Sample generalized GIS data loaded for prototype testing.', 'success');
            })
            .catch((error) => {
                if (requestId !== latestRequestId) return;

                console.error('Failed to load GIS sample data:', error);
                showError('Unable to load sample GIS data.');
                setStatus('GIS sample data could not be loaded.', 'error');
            });
    }

    document.addEventListener('DOMContentLoaded', renderMap);
    document.addEventListener('livewire:navigated', () => setTimeout(renderMap, 0));
})();
</script>
@endpush
