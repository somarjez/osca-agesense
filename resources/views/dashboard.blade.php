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
        forest:   '#2657aa',
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

    // Center label for doughnuts: the running total + a small caption.
    function centerTextPlugin(caption) {
        return {
            id: 'centerText',
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
                ctx.font = "600 24px 'Source Serif 4', Georgia, serif";
                ctx.fillText(String(total), cx, cy - 6);
                ctx.fillStyle = dark ? '#8a9087' : '#8a8f86';
                try { ctx.letterSpacing = '1.3px'; } catch (e) {}
                ctx.font = "600 9px 'Plus Jakarta Sans', system-ui, sans-serif";
                ctx.fillText(caption.toUpperCase(), cx, cy + 13);
                ctx.restore();
            },
        };
    }

    const doughnutAnim = { animateRotate: true, animateScale: true, duration: 300, easing: 'easeOutQuart' };

    // Draw the value above each bar.
    const barValuePlugin = {
        id: 'barValue',
        afterDatasetsDraw(chart) {
            const meta = chart.getDatasetMeta(0);
            if (!meta || meta.hidden) return;
            const ctx = chart.ctx;
            ctx.save();
            ctx.fillStyle = isDark() ? '#b0b5b2' : '#383d36';
            ctx.font = "600 11px 'Plus Jakarta Sans', system-ui, sans-serif";
            ctx.textAlign = 'center';
            meta.data.forEach((bar, i) => {
                const v = chart.data.datasets[0].data[i];
                if (v == null || v === 0) return;
                ctx.fillText(String(v), bar.x, bar.y - 6);
            });
            ctx.restore();
        },
    };

    // Vertical accent gradient for bars (falls back to a flat colour pre-layout).
    function barGradient(context) {
        const { chart } = context;
        const { ctx, chartArea } = chart;
        if (!chartArea) return '#3a6fc4';
        const g = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
        g.addColorStop(0, '#2657aa');
        g.addColorStop(1, '#5689d6');
        return g;
    }

    // Horizontal accent gradient (left→right) for horizontal bars.
    function barGradientH(context) {
        const { chart } = context;
        const { ctx, chartArea } = chart;
        if (!chartArea) return '#3a6fc4';
        const g = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0);
        g.addColorStop(0, '#2657aa');
        g.addColorStop(1, '#5689d6');
        return g;
    }

    // Center value + caption for the semicircular wellbeing gauge.
    // Reads arc.circumference each frame so the number counts up with the animation.
    function gaugeTextPlugin(targetVal, has) {
        return {
            id: 'gaugeText',
            afterDraw(chart) {
                const arc = chart.getDatasetMeta(0).data[0];
                if (!arc || !chart.chartArea) return;
                // arc.circumference animates 0 → (targetVal/100)*π during Chart.js fill
                const animated = has
                    ? Math.round(Math.max(0, Math.min(1, arc.circumference / Math.PI)) * 100)
                    : 0;
                const displayText = has ? String(animated) : '—';
                const ctx = chart.ctx;
                const dark = isDark();
                ctx.save();
                ctx.textAlign = 'center';
                ctx.textBaseline = 'alphabetic';
                ctx.fillStyle = has ? (dark ? '#e4e1d8' : '#1a1d1a') : (dark ? '#8a9087' : '#a8aca5');
                ctx.font = "600 27px 'Source Serif 4', Georgia, serif";
                ctx.fillText(displayText, arc.x, arc.y - 16);
                ctx.fillStyle = dark ? '#8a9087' : '#8a8f86';
                try { ctx.letterSpacing = '1.2px'; } catch (e) {}
                ctx.font = "600 9px 'Plus Jakarta Sans', system-ui, sans-serif";
                ctx.fillText('OUT OF 100', arc.x, arc.y - 2);
                ctx.restore();
            },
        };
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
                    hoverOffset: 8,
                    hoverBorderColor: C.doughnutBorder,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                animation: doughnutAnim,
                onHover: (e, els) => { if (e.native) e.native.target.style.cursor = els.length ? 'pointer' : 'default'; },
                onClick: (e, els, chart) => {
                    if (!els.length) return;
                    const label = String(chart.data.labels[els[0].index] || '');
                    const level = label.toLowerCase().replace('risk', '').trim();
                    if (window.Livewire && ['high', 'moderate', 'low'].includes(level)) {
                        window.Livewire.dispatch('dashboard-filter-risk', { level });
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: c => ` ${c.label}: ${c.parsed}` } },
                },
            },
            plugins: [centerTextPlugin('Seniors')],
        });

        // Health groups — doughnut (proportion = group size, matches Risk Distribution)
        upsert('clusterChart', {
            type: 'doughnut',
            data: {
                labels: p.cluster.labels,
                datasets: [{
                    data: p.cluster.data,
                    backgroundColor: recolor(p.cluster.colors).map(c => c + 'cc'),
                    borderWidth: 2,
                    borderColor: C.doughnutBorder,
                    hoverOffset: 8,
                    hoverBorderColor: C.doughnutBorder,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                animation: doughnutAnim,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: c => ` ${c.label}: ${c.parsed}` } },
                },
            },
            plugins: [centerTextPlugin('Seniors')],
        });

        // Domain scores — radar (full-width slot; shows the WHO-domain profile shape)
        upsert('domainChart', {
            type: 'radar',
            data: {
                labels: p.domain.labels,
                datasets: [{
                    data: p.domain.data,
                    backgroundColor: 'rgba(58, 111, 196, 0.15)',
                    borderColor: '#3a6fc4',
                    borderWidth: 2,
                    pointBackgroundColor: '#3a6fc4',
                    pointRadius: 3,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 300, easing: 'easeOutQuart' },
                scales: {
                    r: {
                        beginAtZero: true,
                        max: 100,
                        ticks: { stepSize: 25, font: { size: 10 }, color: C.tick, backdropColor: 'transparent' },
                        grid: { color: C.grid },
                        angleLines: { color: C.grid },
                        pointLabels: { font: { size: 11 }, color: C.pointLabel },
                    },
                },
                plugins: { legend: { display: false } },
            },
        });

        // Age group distribution — vertical bar (accent gradient + value labels)
        upsert('ageChart', {
            type: 'bar',
            data: {
                labels: p.age.labels,
                datasets: [{
                    data: p.age.data,
                    backgroundColor: barGradient,
                    hoverBackgroundColor: '#2657aa',
                    borderRadius: 6,
                    borderSkipped: false,
                    maxBarThickness: 46,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 300, easing: 'easeOutQuart' },
                layout: { padding: { top: 18 } },
                plugins: { legend: { display: false } },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: C.tick, font: { size: 11 } },
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: C.gridY },
                        ticks: { color: C.tick, font: { size: 11 }, precision: 0 },
                    },
                },
            },
            plugins: [barValuePlugin],
        });

        // Wellbeing index — semicircular gauge
        const wb = p.wellbeing;
        const hasWb = wb !== null && wb !== undefined;
        const wbVal = hasWb ? Math.max(0, Math.min(100, wb)) : 0;
        const wbColor = !hasWb ? '#a8aca5' : (wbVal >= 70 ? '#4a8a68' : wbVal >= 50 ? '#c19a3b' : '#e0621a');
        const wbTrack = isDark() ? '#222a27' : '#ecebe1';
        upsert('wellbeingGauge', {
            type: 'doughnut',
            data: {
                labels: ['Wellbeing', 'Remaining'],
                datasets: [{
                    data: [wbVal, 100 - wbVal],
                    backgroundColor: [wbColor, wbTrack],
                    borderWidth: 0,
                    borderRadius: 6,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                rotation: -90,
                circumference: 180,
                cutout: '70%',
                animation: { duration: 400, easing: 'easeOutQuart' },
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
            },
            plugins: [gaugeTextPlugin(hasWb ? Math.round(wb) : 0, hasWb)],
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
