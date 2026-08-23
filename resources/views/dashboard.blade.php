{{-- resources/views/dashboard.blade.php --}}
@extends('layouts.app')
@section('page-title', 'Dashboard')
@section('page-subtitle', 'Senior Citizen Analytics Overview — Pagsanjan, Laguna')

@section('content')
<livewire:dashboard.main-dashboard lazy />
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

    // Largest-remainder rounding: each value's share of the total rounds to
    // a whole percent while the parts still sum to exactly 100 — independent
    // Math.round(share * 100) per value can drift off 100 (e.g. counts
    // 1/1/4 of 6 round independently to 17/17/67 = 101%). Mirrors
    // App\Support\Percentages::apportion() on the PHP side.
    function apportionPercentages(values) {
        const total = values.reduce((a, b) => a + (Number(b) || 0), 0);
        if (total <= 0) return values.map(() => 0);

        const shares = values.map(v => (Number(v) || 0) / total * 100);
        const floors = shares.map(Math.floor);
        const remainders = shares.map((s, i) => ({ i, r: s - floors[i] }));
        remainders.sort((a, b) => b.r - a.r);

        let pointsToDistribute = 100 - floors.reduce((a, b) => a + b, 0);
        for (const { i } of remainders) {
            if (pointsToDistribute <= 0) break;
            floors[i]++;
            pointsToDistribute--;
        }

        return floors;
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

    // Count + share drawn at the end of each horizontal bar. `pcts` is
    // pre-apportioned (see apportionPercentages()) so every bar's share and
    // the tooltip callback that also reads it stay in agreement and sum to 100.
    function hBarValuePlugin(pcts) {
        return {
            id: 'hBarValue',
            afterDatasetsDraw(chart) {
                const meta = chart.getDatasetMeta(0);
                if (!meta || meta.hidden) return;
                const ctx = chart.ctx;
                ctx.save();
                ctx.fillStyle = isDark() ? '#b0b5b2' : '#383d36';
                ctx.font = "600 10.5px 'Plus Jakarta Sans', system-ui, sans-serif";
                ctx.textAlign = 'left';
                ctx.textBaseline = 'middle';
                meta.data.forEach((bar, i) => {
                    const v = chart.data.datasets[0].data[i];
                    if (v == null) return;
                    const pct = pcts[i] ?? 0;
                    ctx.fillText(`${v} · ${pct}%`, bar.x + 6, bar.y);
                });
                ctx.restore();
            },
        };
    }

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

        // Profile Groups — horizontal bars ranked by size. Long group names get
        // room on the left (wrapped ticks); count + share sit at each bar end.
        const pg = p.cluster.labels
            .map((label, i) => ({ label, value: p.cluster.data[i] ?? 0, color: recolor(p.cluster.colors)[i] ?? '#94a3b8' }))
            .sort((a, b) => b.value - a.value);
        const pgPcts = apportionPercentages(pg.map(g => g.value));
        upsert('clusterChart', {
            type: 'bar',
            data: {
                labels: pg.map(g => g.label),
                datasets: [{
                    data: pg.map(g => g.value),
                    backgroundColor: pg.map(g => g.color + 'cc'),
                    hoverBackgroundColor: pg.map(g => g.color),
                    borderRadius: 6,
                    borderSkipped: false,
                    maxBarThickness: 42,
                    categoryPercentage: 0.82,
                    barPercentage: 0.92,
                }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 300, easing: 'easeOutQuart' },
                layout: { padding: { right: 56 } },
                scales: {
                    x: { display: false, beginAtZero: true },
                    y: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: {
                            autoSkip: false,
                            color: C.pointLabel,
                            font: { size: 10 },
                            callback(value) {
                                const label = String(this.getLabelForValue(value));
                                const words = label.split(' ');
                                const lines = [];
                                let line = '';
                                for (const w of words) {
                                    if (line && (line + ' ' + w).length > 16) { lines.push(line); line = w; }
                                    else line = line ? line + ' ' + w : w;
                                }
                                if (line) lines.push(line);
                                return lines.slice(0, 3);
                            },
                        },
                    },
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        // Floats the tooltip ABOVE the hovered row (in the gap
                        // between bars) instead of beside it, so it never
                        // competes for the same narrow horizontal space as the
                        // wrapped y-axis labels on the left or
                        // hBarValuePlugin's permanent "count · pct%" label on
                        // the right. The row's own y-axis label already names
                        // the group, so the redundant title is dropped too —
                        // shrinking the box down to just the count/share line.
                        yAlign: 'bottom',
                        callbacks: {
                            title: () => '',
                            label: c => ` ${c.parsed.x} seniors · ${pgPcts[c.dataIndex] ?? 0}%`,
                        },
                    },
                },
            },
            plugins: [hBarValuePlugin(pgPcts)],
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
                plugins: {
                    legend: { display: false },
                    // barValuePlugin always draws the value just above the bar
                    // (bar.y - 6); keep the hover tooltip below the bar's top so
                    // the two labels don't sit on top of each other.
                    tooltip: { yAlign: 'bottom' },
                },
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
                // Purely decorative — no tooltip, no click action. Chart.js still
                // auto-darkens arc segments on hover by default even with the
                // tooltip disabled; events: [] stops all hover/click listeners
                // on the canvas so neither segment reacts to the cursor at all.
                events: [],
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
                    setTimeout(boot, 50);
                    break;
                }
            }
        });
        observer.observe(html, { attributes: true });
    }

    const boot = () => window.OSCA.charts().then(() => render());

    // Persistent (document-level) registrations must only bind once per page
    // session: window survives wire:navigate SPA navigation, but this whole
    // <script> tag re-executes on every navigation, so without this guard
    // these listeners/observer would accumulate unboundedly. render() reads
    // #dashboard-chart-data fresh at call time, so a single bound listener
    // stays correct across every future navigation.
    if (!window.__oscaBound_dashboard) {
        window.__oscaBound_dashboard = true;
        document.addEventListener('livewire:navigated', () => setTimeout(boot, 0));
        // 'livewire:updated' is a Livewire v2 event that Livewire v3 never
        // dispatches — that listener was silently dead, so charts never
        // repainted after an in-place filter change (wire:model.live on the
        // barangay/risk selects): the canvas kept showing whichever data it
        // was last drawn with, even though #dashboard-chart-data itself had
        // already been morphed to the fresh, correctly-filtered JSON. v3's
        // real per-component "just finished updating" signal is the
        // Livewire.hook('morphed', ...) hook.
        Livewire.hook('morphed', () => boot());
        // MainDashboard is `lazy` (main-dashboard.blade.php), so on a fresh
        // load #dashboard-chart-data isn't in the DOM yet at DOMContentLoaded
        // — render() below bails out, and without the hook above the next
        // thing to trigger a repaint would be the component's own
        // wire:poll.300s (5 minutes later). The component dispatches this
        // event itself via Alpine x-init once its lazy placeholder is
        // replaced by the real content, so boot() runs as soon as the chart
        // data actually exists.
        window.addEventListener('osca:dashboard-hydrated', () => setTimeout(boot, 0));
        // Immediate paint when first reached via SPA nav (document already
        // loaded, so DOMContentLoaded won't refire); matches the sibling report
        // scripts. On a full load readyState is 'loading', so DOMContentLoaded
        // handles it and this line is skipped — no double render.
        if (document.readyState !== 'loading') setTimeout(boot, 0);
        document.addEventListener('DOMContentLoaded', () => {
            observeDark();
            boot();
        });
    }
})();
</script>
@endpush
