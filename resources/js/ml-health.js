// Single shared ML service health poller for the whole SPA session.
//
// Why this exists: the topbar dot used to do its own one-shot x-init fetch
// (broken by @persist('topbar') — it only ran once per hard page load) and
// ml-service-guard.js polled independently every 45s into its own Alpine
// scope. Both read the same server truth but never told each other about
// it, so a "services are back online" toast could sit right next to a dot
// that was still red until the user hit F5. This module is the single
// source of truth both listen to, so a fix reaches every surface at once.
//
// Deliberately NOT an Alpine component — a module-level singleton has none
// of the @persist / re-init / leaked-timer failure modes ml-service-guard.js
// has already been bitten by twice (see its own history). There is exactly
// one timer for the entire tab session, started once at import.
const CACHE_KEY = 'osca_nav_health'
const URL = '/ml/nav-health'
const INTERVAL_DOWN_MS = 10000
const INTERVAL_OK_MS = 30000

function readSeed() {
    try {
        const cached = JSON.parse(sessionStorage.getItem(CACHE_KEY) || 'null')
        if (cached && typeof cached.dot === 'string') {
            return { dot: cached.dot, title: cached.title || '' }
        }
    } catch (e) { /* corrupt/unavailable sessionStorage — fall through */ }
    return { dot: 'checking', title: 'Checking analysis services…' }
}

function writeSeed(dot, title) {
    try {
        sessionStorage.setItem(CACHE_KEY, JSON.stringify({ dot, title, ts: Date.now() }))
    } catch (e) { /* sessionStorage unavailable — state still updates in-memory */ }
}

const seed = readSeed()

const mlHealth = {
    dot: seed.dot,
    title: seed.title,
    _timer: null,
    _inFlight: false,

    /**
     * Fetch fresh status, broadcast it, and reschedule the next check.
     * force=true skips nothing server-side (the server has its own cache) —
     * it's here so callers right after start/stop/wake don't have to guess
     * whether a poll is already due.
     */
    refresh({ force = false } = {}) {
        if (this._inFlight && !force) return
        this._inFlight = true

        return fetch(URL, { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : Promise.reject()))
            .then((d) => ({ dot: d.dot, title: d.title }))
            .catch(() => ({ dot: 'err', title: 'Status unavailable' }))
            .then(({ dot, title }) => {
                this._inFlight = false
                const previous = this.dot
                this.dot = dot
                this.title = title
                writeSeed(dot, title)
                window.dispatchEvent(new CustomEvent('osca:ml-health', { detail: { dot, title, previous } }))
                this._reschedule()
                return { dot, title, previous }
            })
    },

    _reschedule() {
        clearTimeout(this._timer)
        const delay = this.dot === 'ok' ? INTERVAL_OK_MS : INTERVAL_DOWN_MS
        this._timer = setTimeout(() => this.refresh(), delay)
    },

    _start() {
        this.refresh()
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) this.refresh({ force: true })
        })
    },
}

mlHealth._start()

window.OSCA = window.OSCA || {}
window.OSCA.mlHealth = mlHealth

export default mlHealth
