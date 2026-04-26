import './bootstrap'
import Chart from 'chart.js/auto'

// Alpine.js is managed by Livewire 3's bundled copy — do NOT import or start it
// here. Importing a second Alpine instance breaks wire:click / wire:model.

// ── App layout state (sidebar collapse + dark mode) ───────────────────────────
document.addEventListener('alpine:init', () => {
    Alpine.data('appLayout', () => ({
        sidebarOpen: localStorage.getItem('sidebarCollapsed') !== 'true',
        dark: localStorage.getItem('darkMode') === 'true',
        toggleSidebar() {
            this.sidebarOpen = !this.sidebarOpen
            localStorage.setItem('sidebarCollapsed', String(!this.sidebarOpen))
        },
        toggleDark() {
            this.dark = !this.dark
            localStorage.setItem('darkMode', String(this.dark))
        },
    }))
})

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

// ── Livewire scroll preservation ─────────────────────────────────────────────
// The layout uses <main class="overflow-y-auto"> as the scroll container.
// Livewire only preserves window scroll — not custom overflow containers.
// Capture scroll before each DOM morph and restore it after, so Livewire
// re-renders don't snap the user back to the top.
document.addEventListener('livewire:before-update', function () {
    const main = document.querySelector('main')
    if (main) window.__livewireMainScroll = main.scrollTop
})

document.addEventListener('livewire:updated', function () {
    const main = document.querySelector('main')
    if (main && window.__livewireMainScroll !== undefined) {
        main.scrollTop = window.__livewireMainScroll
        delete window.__livewireMainScroll
    }
})

// Dispatched by QolSurveyForm when the step changes — intentionally scroll top.
document.addEventListener('qol-step-changed', function () {
    const main = document.querySelector('main')
    if (main) main.scrollTop = 0
    delete window.__livewireMainScroll  // cancel any pending restoration
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
