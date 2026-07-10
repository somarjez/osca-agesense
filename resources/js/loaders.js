// resources/js/loaders.js
// Memoized dynamic-import loaders. Heavy libs (Chart.js, Leaflet stack) are
// split out of the global bundle and fetched only when a page actually needs
// them. Both loaders set the same window globals (window.Chart / window.L)
// that existing inline blade scripts already depend on, so call sites only
// need to await the promise before running.

let chartsPromise = null
let mapsPromise = null

export function loadCharts() {
    if (chartsPromise) return chartsPromise
    chartsPromise = import('chart.js/auto').then(({ default: Chart }) => {
        // Global defaults previously lived at the top of app.js.
        Chart.defaults.font.family = "'DM Sans', system-ui, sans-serif"
        Chart.defaults.font.size = 11
        Chart.defaults.color = '#64748b'
        Chart.defaults.plugins.legend.display = false
        Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(15, 23, 42, 0.9)'
        Chart.defaults.plugins.tooltip.padding = 10
        Chart.defaults.plugins.tooltip.cornerRadius = 8
        Chart.defaults.plugins.tooltip.titleFont = { weight: '600', size: 12 }
        Chart.defaults.plugins.tooltip.bodyFont = { size: 11 }
        Chart.defaults.scale.grid.color = 'rgba(0, 0, 0, 0.04)'
        Chart.defaults.scale.ticks.color = '#94a3b8'
        Chart.defaults.animation.duration = 300
        window.Chart = Chart
        return Chart
    })
    return chartsPromise
}

export function loadMaps() {
    if (mapsPromise) return mapsPromise
    mapsPromise = import('leaflet').then(async ({ default: L }) => {
        await import('leaflet/dist/leaflet.css')
        await import('leaflet.markercluster')
        await import('leaflet.markercluster/dist/MarkerCluster.css')
        await import('leaflet.markercluster/dist/MarkerCluster.Default.css')
        await import('leaflet.heat')
        window.L = L
        return L
    })
    return mapsPromise
}
