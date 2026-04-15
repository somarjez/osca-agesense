@extends('layouts.app')
@section('page-title', 'Dashboard')
@section('page-subtitle', 'Senior Citizen Analytics Overview — Pagsanjan, Laguna')

@section('content')
<div class="space-y-6" wire:poll.60s>

    {{-- ── KPI Cards ── --}}
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
        @php
        $cards = [
            ['label' => 'Total Seniors', 'value' => $stats['total'],      'icon' => '👥', 'color' => 'teal',   'sub' => 'Active records'],
            ['label' => 'Surveyed',       'value' => $stats['surveyed'],   'icon' => '📋', 'color' => 'blue',   'sub' => 'With QoL data'],
            ['label' => 'Critical Risk',  'value' => $stats['critical'],   'icon' => '🚨', 'color' => 'red',    'sub' => 'Need urgent care'],
            ['label' => 'High Risk',      'value' => $stats['highRisk'],   'icon' => '⚠️', 'color' => 'orange', 'sub' => 'Need intervention'],
            ['label' => 'Pending Recs',   'value' => $stats['pendingRecs'],'icon' => '💡', 'color' => 'amber',  'sub' => 'Actions pending'],
        ];
        $colorMap = [
            'teal'   => 'bg-teal-50 border-teal-200 text-teal-700',
            'blue'   => 'bg-blue-50 border-blue-200 text-blue-700',
            'red'    => 'bg-red-50 border-red-200 text-red-700',
            'orange' => 'bg-orange-50 border-orange-200 text-orange-700',
            'amber'  => 'bg-amber-50 border-amber-200 text-amber-700',
        ];
        @endphp

        @foreach ($cards as $card)
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between mb-3">
                <span class="text-2xl">{{ $card['icon'] }}</span>
                <span class="text-xs font-medium px-2 py-0.5 rounded-full border {{ $colorMap[$card['color']] }}">
                    {{ $card['sub'] }}
                </span>
            </div>
            <p class="text-3xl font-bold text-slate-800">{{ number_format($card['value']) }}</p>
            <p class="text-sm text-slate-500 mt-0.5">{{ $card['label'] }}</p>
        </div>
        @endforeach
    </div>

    {{-- ── Filter Bar ── --}}
    <div class="flex items-center gap-3">
        <select wire:model.live="selectedBarangay"
                class="text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-teal-500 shadow-sm">
            <option value="">All Barangays</option>
            @foreach (\App\Models\SeniorCitizen::barangayList() as $brgy)
            <option value="{{ $brgy }}">{{ $brgy }}</option>
            @endforeach
        </select>

        <select wire:model.live="selectedRisk"
                class="text-sm border border-slate-200 rounded-lg px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-teal-500 shadow-sm">
            <option value="">All Risk Levels</option>
            <option value="critical">Critical</option>
            <option value="high">High</option>
            <option value="moderate">Moderate</option>
            <option value="low">Low</option>
        </select>

        <div class="ml-auto flex items-center gap-2 text-xs">
            <span class="text-slate-500">ML Services:</span>
            @foreach ($mlHealth as $service => $status)
            <span class="px-2 py-1 rounded-full font-medium
                {{ $status === 'ok' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' }}">
                {{ ucfirst($service) }}: {{ $status }}
            </span>
            @endforeach
        </div>
    </div>

    {{-- ── Charts Row ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Risk Distribution Doughnut --}}
        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-700 mb-4">Risk Level Distribution</h3>
            <div class="relative h-48">
                <canvas id="riskChart"></canvas>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-1">
                @foreach ($riskDistribution['labels'] as $i => $label)
                <div class="flex items-center gap-1.5 text-xs text-slate-600">
                    <span class="w-2.5 h-2.5 rounded-full flex-shrink-0"
                          style="background: {{ $riskDistribution['colors'][$i] }}"></span>
                    {{ $label }}: <strong>{{ $riskDistribution['data'][$i] }}</strong>
                </div>
                @endforeach
            </div>
        </div>

        {{-- Cluster Distribution --}}
        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-700 mb-4">KMeans Cluster Distribution (K=3)</h3>
            <div class="relative h-48">
                <canvas id="clusterChart"></canvas>
            </div>
            <div class="mt-3 space-y-1">
                @foreach ($clusterDistribution['labels'] as $i => $label)
                <div class="flex items-center gap-1.5 text-xs text-slate-600">
                    <span class="w-2.5 h-2.5 rounded-full flex-shrink-0"
                          style="background: {{ $clusterDistribution['colors'][$i] }}"></span>
                    Cluster {{ $clusterDistribution['ids'][$i] ?? ($i + 1) }}: {{ $label }} ({{ $clusterDistribution['data'][$i] }})
                </div>
                @endforeach
            </div>
        </div>

        {{-- Domain Scores Radar --}}
        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-700 mb-4">Avg QoL Domain Scores (%)</h3>
            <div class="relative h-48">
                <canvas id="domainChart"></canvas>
            </div>
        </div>
    </div>

    {{-- ── Age Group + Barangay Row ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        {{-- Age Group Bar --}}
        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-700 mb-4">Age Group Distribution</h3>
            <div class="relative h-44">
                <canvas id="ageChart"></canvas>
            </div>
        </div>

        {{-- Barangay Table --}}
        <div class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-700 mb-4">Barangay Breakdown</h3>
            <div class="overflow-auto max-h-44">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="text-left text-slate-400 border-b border-slate-100">
                            <th class="pb-2 font-medium">Barangay</th>
                            <th class="pb-2 font-medium text-right">Total</th>
                            <th class="pb-2 font-medium text-right">Critical</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach ($barangayBreakdown as $row)
                        <tr class="hover:bg-slate-25">
                            <td class="py-1.5 text-slate-700">{{ $row['barangay'] }}</td>
                            <td class="py-1.5 text-right font-medium text-slate-800">{{ $row['total'] }}</td>
                            <td class="py-1.5 text-right">
                                @if ($row['critical'] > 0)
                                <span class="px-1.5 py-0.5 bg-red-100 text-red-700 rounded-full font-medium">
                                    {{ $row['critical'] }}
                                </span>
                                @else
                                <span class="text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ── Bottom Row: Recent Seniors + Pending Recs ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        {{-- Recent Seniors --}}
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-700">Recent Senior Records</h3>
                <a href="{{ route('seniors.index') }}" class="text-xs text-teal-600 hover:text-teal-700 font-medium">View all →</a>
            </div>
            <div class="divide-y divide-slate-50">
                @forelse ($recentSeniors as $senior)
                @php $ml = $senior->latestMlResult; @endphp
                <div class="px-5 py-3 flex items-center gap-3 hover:bg-slate-25 transition-colors">
                    <div class="w-8 h-8 rounded-full bg-teal-100 flex items-center justify-center flex-shrink-0">
                        <span class="text-xs font-bold text-teal-700">{{ substr($senior->first_name, 0, 1) }}</span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-slate-800 truncate">{{ $senior->full_name }}</p>
                        <p class="text-xs text-slate-400">{{ $senior->barangay }} · Age {{ $senior->age }}</p>
                    </div>
                    @if ($ml)
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full
                        {{ match($ml->overall_risk_level) {
                            'CRITICAL' => 'bg-red-100 text-red-700',
                            'HIGH'     => 'bg-orange-100 text-orange-700',
                            'MODERATE' => 'bg-amber-100 text-amber-700',
                            'LOW'      => 'bg-emerald-100 text-emerald-700',
                            default    => 'bg-slate-100 text-slate-600',
                        } }}">
                        {{ $ml->overall_risk_level }}
                    </span>
                    @else
                    <span class="text-xs text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full">No ML data</span>
                    @endif
                    <a href="{{ route('seniors.show', $senior->id) }}"
                       class="text-slate-400 hover:text-teal-600 transition-colors">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                        </svg>
                    </a>
                </div>
                @empty
                <div class="px-5 py-8 text-center text-sm text-slate-400">No senior records found.</div>
                @endforelse
            </div>
        </div>

        {{-- Pending Recommendations --}}
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-700">Urgent Pending Actions</h3>
                <a href="{{ route('recommendations.index') }}" class="text-xs text-teal-600 hover:text-teal-700 font-medium">View all →</a>
            </div>
            <div class="divide-y divide-slate-50">
                @forelse ($pendingRecs as $rec)
                <div class="px-5 py-3 hover:bg-slate-25 transition-colors">
                    <div class="flex items-start gap-2">
                        <span class="mt-0.5 w-5 h-5 flex-shrink-0 rounded-full flex items-center justify-center text-xs font-bold
                            {{ $rec->urgency === 'immediate' ? 'bg-red-500 text-white' : 'bg-orange-400 text-white' }}">
                            {{ $rec->priority }}
                        </span>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-medium text-slate-700">{{ $rec->seniorCitizen->full_name }}</p>
                            <p class="text-xs text-slate-500 mt-0.5 line-clamp-1">{{ $rec->action }}</p>
                        </div>
                        <span class="{{ $rec->urgency_badge }} text-xs px-1.5 py-0.5 rounded-full font-medium flex-shrink-0">
                            {{ ucfirst($rec->urgency) }}
                        </span>
                    </div>
                </div>
                @empty
                <div class="px-5 py-8 text-center">
                    <div class="text-2xl mb-2">✅</div>
                    <p class="text-sm text-slate-500">No urgent pending actions.</p>
                </div>
                @endforelse
            </div>
        </div>
    </div>

    <script type="application/json" id="dashboard-chart-data">
    {!! json_encode([
        'risk' => $riskDistribution,
        'cluster' => $clusterDistribution,
        'domain' => $domainScoreChart,
        'age' => $ageGroupChart,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
    </script>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const dashboardCharts = {};

    function readDashboardData() {
        const el = document.getElementById('dashboard-chart-data');
        return el ? JSON.parse(el.textContent) : null;
    }

    function upsertChart(key, canvasId, config) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) {
            return;
        }

        if (dashboardCharts[key]) {
            dashboardCharts[key].destroy();
        }

        dashboardCharts[key] = new Chart(canvas, config);
    }

    function renderDashboardCharts() {
        const payload = readDashboardData();
        if (!payload) {
            return;
        }

    const chartDefaults = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
    };

    upsertChart('risk', 'riskChart', {
        type: 'doughnut',
        data: {
            labels: payload.risk.labels,
            datasets: [{
                data: payload.risk.data,
                backgroundColor: payload.risk.colors,
                borderWidth: 2,
                borderColor: '#fff',
            }]
        },
        options: { ...chartDefaults, cutout: '65%' }
    });

    upsertChart('cluster', 'clusterChart', {
        type: 'doughnut',
        data: {
            labels: payload.cluster.labels.map((label, index) => {
                const clusterId = payload.cluster.ids[index] ?? (index + 1);
                return `Cluster ${clusterId}: ${label}`;
            }),
            datasets: [{
                data: payload.cluster.data,
                backgroundColor: payload.cluster.colors,
                borderWidth: 2,
                borderColor: '#fff',
            }]
        },
        options: { ...chartDefaults, cutout: '65%' }
    });

    upsertChart('domain', 'domainChart', {
        type: 'radar',
        data: {
            labels: payload.domain.labels,
            datasets: [{
                data: payload.domain.data,
                backgroundColor: 'rgba(20, 184, 166, 0.15)',
                borderColor: 'rgb(20, 184, 166)',
                pointBackgroundColor: 'rgb(20, 184, 166)',
                pointRadius: 3,
                borderWidth: 2,
            }]
        },
        options: {
            ...chartDefaults,
            scales: {
                r: {
                    min: 0, max: 100,
                    ticks: { stepSize: 25, font: { size: 9 } },
                    pointLabels: { font: { size: 9 } },
                    grid: { color: 'rgba(0,0,0,0.05)' },
                }
            }
        }
    });

    upsertChart('age', 'ageChart', {
        type: 'bar',
        data: {
            labels: payload.age.labels,
            datasets: [{
                data: payload.age.data,
                backgroundColor: 'rgba(20, 184, 166, 0.7)',
                borderRadius: 5,
                borderSkipped: false,
            }]
        },
        options: {
            ...chartDefaults,
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { font: { size: 10 } } },
                x: { grid: { display: false }, ticks: { font: { size: 10 } } },
            }
        }
    });

    }

    renderDashboardCharts();

    document.addEventListener('livewire:initialized', () => {
        if (!window.Livewire?.hook) {
            return;
        }

        window.Livewire.hook('morph.updated', ({ component }) => {
            if (component.name === 'dashboard.main-dashboard') {
                renderDashboardCharts();
            }
        });
    });
});
</script>
@endpush
