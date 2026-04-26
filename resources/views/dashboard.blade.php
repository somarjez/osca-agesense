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

        // Risk distribution — doughnut
        upsert('riskChart', {
            type: 'doughnut',
            data: {
                labels: p.risk.labels,
                datasets: [{
                    data: p.risk.data,
                    backgroundColor: recolor(p.risk.colors),
                    borderWidth: 2,
                    borderColor: '#fff',
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
                    borderColor: '#fff',
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
                    borderColor: '#2f6552',
                    borderWidth: 2,
                    pointBackgroundColor: '#2f6552',
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
                        ticks: { stepSize: 25, font: { size: 10 } },
                        grid: { color: 'rgba(0,0,0,0.06)' },
                        pointLabels: { font: { size: 11 } },
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
                    backgroundColor: '#2f6552',
                    borderRadius: 4,
                    borderSkipped: false,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' } },
                },
            },
        });
    }

    document.addEventListener('livewire:navigated', () => setTimeout(render, 0));
    document.addEventListener('livewire:updated', render);
})();
</script>
@endpush
