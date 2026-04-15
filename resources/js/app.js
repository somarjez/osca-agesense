import './bootstrap'
import Alpine from 'alpinejs'
import Chart from 'chart.js/auto'

// ── Alpine.js setup ──────────────────────────────────────────────────────────
window.Alpine = Alpine
Alpine.start()

// ── Chart.js global defaults ─────────────────────────────────────────────────
Chart.defaults.font.family = "'DM Sans', system-ui, sans-serif"
Chart.defaults.font.size   = 11
Chart.defaults.color       = '#64748b'
Chart.defaults.plugins.legend.display = false
Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(15, 23, 42, 0.9)'
Chart.defaults.plugins.tooltip.padding         = 10
Chart.defaults.plugins.tooltip.cornerRadius    = 8
Chart.defaults.plugins.tooltip.titleFont       = { weight: '600', size: 12 }
Chart.defaults.plugins.tooltip.bodyFont        = { size: 11 }
Chart.defaults.scale.grid.color               = 'rgba(0, 0, 0, 0.04)'
Chart.defaults.scale.ticks.color              = '#94a3b8'

// Make Chart.js available globally for Blade scripts
window.Chart = Chart

// ── Livewire chart re-init hook ───────────────────────────────────────────────
// Destroy and re-create charts after Livewire re-renders
document.addEventListener('livewire:navigated', () => {
    Chart.helpers.each(Chart.instances, (instance) => {
        instance.destroy()
    })
})

// ── OSCA Helper utilities ─────────────────────────────────────────────────────
window.OSCA = {
    /**
     * Format a 0-1 risk score as a percentage string with color class.
     */
    riskColor(score) {
        if (score > 0.75) return 'text-red-600'
        if (score > 0.65) return 'text-orange-600'
        if (score > 0.45) return 'text-amber-600'
        return 'text-emerald-600'
    },

    /**
     * Map cluster named_id to a CSS color token.
     */
    clusterColor(clusterId) {
        const map = { 1: '#10b981', 2: '#f59e0b', 3: '#f43f5e' }
        return map[clusterId] ?? '#94a3b8'
    },

    /**
     * Build a minimal doughnut chart with center-text.
     */
    buildDoughnut(canvasId, labels, data, colors) {
        const ctx = document.getElementById(canvasId)
        if (!ctx) return null
        return new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{ data, backgroundColor: colors, borderWidth: 2, borderColor: '#fff' }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (c) => ` ${c.label}: ${c.parsed}` } },
                },
            },
        })
    },

    /**
     * Build a horizontal bar chart.
     */
    buildHBar(canvasId, labels, data, color = '#14b8a6') {
        const ctx = document.getElementById(canvasId)
        if (!ctx) return null
        return new Chart(ctx, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    data,
                    backgroundColor: color,
                    borderRadius: 4,
                    borderSkipped: false,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' } },
                    y: { grid: { display: false } },
                },
            },
        })
    },
}
