import './bootstrap'
import Chart from 'chart.js/auto'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import 'leaflet.markercluster'
import 'leaflet.markercluster/dist/MarkerCluster.css'
import 'leaflet.markercluster/dist/MarkerCluster.Default.css'
import 'leaflet.heat'

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

    // ── Hover tooltip ─────────────────────────────────────────────────────────
    // Reliable replacement for x-teleport/x-show/x-transition (which were flaky
    // under Livewire's bundled Alpine). On hover we move the panel to <body> and
    // position it `fixed` so it escapes the scrollable main content / sidebar
    // overflow clipping, clamped to the viewport so it never lands off-screen.
    Alpine.data('hoverTip', () => ({
        show() {
            const trigger = this.$refs.trigger
            const panel = this.$refs.panel
            if (!trigger || !panel) return
            if (panel.parentElement !== document.body) document.body.appendChild(panel)

            panel.style.display = 'block'          // make measurable
            const r = trigger.getBoundingClientRect()
            const pw = panel.offsetWidth
            const ph = panel.offsetHeight
            const pos = panel.dataset.pos || 'top'
            const gap = 6
            let top, left

            if (pos === 'bottom')      { left = r.left + r.width / 2 - pw / 2; top = r.bottom + gap }
            else if (pos === 'left')   { left = r.left - gap - pw;             top = r.top + r.height / 2 - ph / 2 }
            else if (pos === 'right')  { left = r.right + gap;                 top = r.top + r.height / 2 - ph / 2 }
            else                       { left = r.left + r.width / 2 - pw / 2; top = r.top - gap - ph }

            // Clamp inside the viewport (8px margin) so it's always fully visible.
            left = Math.max(8, Math.min(left, window.innerWidth - pw - 8))
            top = Math.max(8, Math.min(top, window.innerHeight - ph - 8))
            panel.style.left = left + 'px'
            panel.style.top = top + 'px'
        },
        hide() {
            if (this.$refs.panel) this.$refs.panel.style.display = 'none'
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
// Entry animation default — Chart.js ships 1000ms. On GPU-less devices, six
// dashboard charts animating at once (and re-animating on every filter) is the
// worst CPU spike on the page. 300ms keeps a snappy reveal everywhere without
// changing chart type, data, or interactivity. Per-chart configs inherit this.
Chart.defaults.animation.duration             = 300

// Make Chart.js available globally for Blade scripts
window.Chart = Chart
window.L = L

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

// ── KPI count-up ──────────────────────────────────────────────────────────────
// Elements with [data-countup] tween from 0 to their server-rendered integer on
// page load / navigation (not on Livewire filter updates — re-animating every
// filter change would be noise). Thousands separators are preserved. Skipped
// under prefers-reduced-motion and for non-numeric content ("—", percentages).
function runCountups() {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return
    document.querySelectorAll('[data-countup]:not([data-counted])').forEach((el) => {
        const raw = el.textContent.trim()
        if (!/^[\d,]+$/.test(raw)) return
        const num = Number(raw.replace(/,/g, ''))
        if (!Number.isInteger(num) || num <= 0 || num > 999999) return
        el.dataset.counted = 'true'
        const useCommas = raw.includes(',')
        const start = performance.now()
        const dur = 650
        const tick = (now) => {
            const t = Math.min(1, (now - start) / dur)
            const eased = 1 - Math.pow(1 - t, 4) // easeOutQuart
            const val = Math.round(num * eased)
            el.textContent = useCommas ? val.toLocaleString('en-US') : String(val)
            if (t < 1) requestAnimationFrame(tick)
        }
        requestAnimationFrame(tick)
    })
}
document.addEventListener('DOMContentLoaded', runCountups)
document.addEventListener('livewire:navigated', runCountups)

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
        const map = { 1: '#2ecc71', 2: '#3498db', 3: '#f39c12', 4: '#e74c3c' }
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

// ── Double-submit guard for plain (non-Livewire) form POSTs ───────────────────
// Native form submits navigate away, so a slow server lets users double-click
// archive / restore / delete / export buttons. On submit we disable the submit
// control(s) and (optionally) swap to a loading label. Livewire forms manage
// their own state via wire:loading and are skipped. Opt out with data-no-loading.
document.addEventListener('submit', function (e) {
    const form = e.target
    if (!(form instanceof HTMLFormElement)) return
    if (form.hasAttribute('data-no-loading')) return
    // Skip Livewire-managed forms (wire:submit / wire:submit.prevent / .live …)
    const isLivewire = Array.from(form.attributes).some((a) => a.name.startsWith('wire:submit'))
    if (isLivewire) return

    const controls = form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])')
    controls.forEach((btn) => {
        if (btn.dataset.noLoading !== undefined || btn.disabled) return
        btn.disabled = true
        btn.setAttribute('aria-busy', 'true')
        const label = btn.dataset.loading
        if (label && btn.tagName === 'BUTTON') {
            btn.dataset.origHtml = btn.innerHTML
            btn.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span> ' + label
        }
    })
}, true)

// ── Force light theme when printing ───────────────────────────────────────────
// Dark backgrounds waste ink, and most browsers drop background colors on print,
// which leaves light-on-dark text invisible. Drop the `dark` class before the
// print snapshot is taken, then restore the user's saved preference afterward.
window.addEventListener('beforeprint', function () {
    document.documentElement.classList.remove('dark')
})
window.addEventListener('afterprint', function () {
    try {
        if (localStorage.getItem('darkMode') === 'true') {
            document.documentElement.classList.add('dark')
        }
    } catch (e) { /* localStorage unavailable — nothing to restore */ }
})
