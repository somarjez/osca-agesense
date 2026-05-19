{{-- resources/views/dashboard.blade.php --}}
@extends('layouts.app')
@section('page-title', 'Dashboard')
@section('page-subtitle', 'Senior Citizen Analytics Overview — Pagsanjan, Laguna')

@section('content')
<livewire:dashboard.main-dashboard />
@endsection

@push('scripts')
<script>
(function () {
    const PALETTE = {
        critical: '#b94a3a',
        high:     '#c47832',
        moderate: '#c19a3b',
        low:      '#4a8a68',
        forest:   '#2f6552',
    };

    function recolor(arr) {
        return arr.map(c => PALETTE[c] ?? c);
    }

    function isDark() {
        return document.documentElement.classList.contains('dark');
    }

    function chartColors() {
        const dark = isDark();
        return {
            grid:        dark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)',
            gridY:       dark ? 'rgba(255,255,255,0.04)' : 'rgba(0,0,0,0.04)',
            tick:        dark ? '#6b7570' : '#8a8f86',
            pointLabel:  dark ? '#8a9087' : '#6b7269',
            doughnutBorder: dark ? '#1a201d' : '#ffffff',
        };
    }

    function upsert(id, config) {
        const canvas = document.getElementById(id);
        if (!canvas) return;
        const existing = Object.values(Chart.instances).find(c => c.canvas === canvas);
        if (existing) existing.destroy();
        new Chart(canvas, config);
    }

    function render() {
        const el = document.getElementById('dashboard-chart-data');
        if (!el) return;
        const p = JSON.parse(el.textContent);
        const C = chartColors();

        // Risk distribution — doughnut
        upsert('riskChart', {
            type: 'doughnut',
            data: {
                labels: p.risk.labels,
                datasets: [{
                    data: p.risk.data,
                    backgroundColor: recolor(p.risk.colors),
                    borderWidth: 2,
                    borderColor: C.doughnutBorder,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: c => ` ${c.label}: ${c.parsed}` } },
                },
            },
        });

        // K-Means clusters — doughnut
        upsert('clusterChart', {
            type: 'doughnut',
            data: {
                labels: p.cluster.labels,
                datasets: [{
                    data: p.cluster.data,
                    backgroundColor: recolor(p.cluster.colors),
                    borderWidth: 2,
                    borderColor: C.doughnutBorder,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: c => ` ${c.label}: ${c.parsed}` } },
                },
            },
        });

        // Domain scores — radar
        upsert('domainChart', {
            type: 'radar',
            data: {
                labels: p.domain.labels,
                datasets: [{
                    data: p.domain.data,
                    backgroundColor: 'rgba(47, 101, 82, 0.15)',
                    borderColor: '#3f8068',
                    borderWidth: 2,
                    pointBackgroundColor: '#3f8068',
                    pointRadius: 3,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    r: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            stepSize: 25,
                            font: { size: 10 },
                            color: C.tick,
                            backdropColor: 'transparent',
                        },
                        grid: { color: C.grid },
                        angleLines: { color: C.grid },
                        pointLabels: {
                            font: { size: 11 },
                            color: C.pointLabel,
                        },
                    },
                },
                plugins: { legend: { display: false } },
            },
        });

        // Age group distribution — vertical bar
        upsert('ageChart', {
            type: 'bar',
            data: {
                labels: p.age.labels,
                datasets: [{
                    data: p.age.data,
                    backgroundColor: '#3f8068',
                    borderRadius: 6,
                    borderSkipped: false,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: C.tick, font: { size: 11 } },
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: C.gridY },
                        ticks: { color: C.tick, font: { size: 11 } },
                    },
                },
            },
        });
    }

    // Re-render charts when dark mode changes (Alpine dispatches a custom event)
    function observeDark() {
        const html = document.documentElement;
        const observer = new MutationObserver((mutations) => {
            for (const m of mutations) {
                if (m.attributeName === 'class') {
                    setTimeout(render, 50);
                    break;
                }
            }
        });
        observer.observe(html, { attributes: true });
    }

    document.addEventListener('livewire:navigated', () => setTimeout(render, 0));
    document.addEventListener('livewire:updated', render);
    document.addEventListener('DOMContentLoaded', () => {
        observeDark();
    });
})();
</script>
@endpush
