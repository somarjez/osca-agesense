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
// Fresh (uncached — see MlController::wakeStatus()'s docblock), used only by
// checkNow() below for a pre-operation health check right before an
// ML-dependent action starts. Deliberately NOT the cached nav-health URL:
// "it was up 10 minutes ago" must never be trusted before a critical
// operation like Batch/Individual Analysis, Re-run Assessment, or the
// ML-dependent stage of Bulk Upload.
const CHECK_NOW_URL = '/ml/wake-status'
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
    _inFlightPromise: null,
    _checkNowPromise: null,

    /**
     * Fetch fresh status, broadcast it, and reschedule the next check.
     * force=true skips nothing server-side (the server has its own cache) —
     * it's here so callers right after start/stop/wake don't have to guess
     * whether a poll is already due.
     *
     * Always returns a promise — a call that lands while one is already in
     * flight returns THAT SAME promise (deduped) rather than undefined, so
     * every caller can safely `await` this without knowing whether a poll
     * happened to already be in progress.
     */
    refresh({ force = false } = {}) {
        if (this._inFlight && !force) return this._inFlightPromise

        this._inFlight = true
        this._inFlightPromise = fetch(URL, { headers: { Accept: 'application/json' } })
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
        return this._inFlightPromise
    },

    /**
     * Fresh, UNCACHED health check for the moment right before an
     * ML-dependent operation starts (Batch/Individual Analysis, Re-run
     * Assessment, Bulk Upload's ML stage) — see window.OSCA.requireMl() in
     * app.js and window.OSCA.mlGate in ml-service-guard.js. Deduped through
     * its own pending promise: several simultaneous pre-flight checks (e.g.
     * the background monitor ticking at the same moment a user clicks Run
     * Analysis) collapse into one request, not several.
     *
     * Updates dot/title and broadcasts osca:ml-health exactly like a normal
     * poll tick on completion, so the topbar dot, the guard modal, and every
     * pre-flight caller can never end up disagreeing about current status.
     *
     * @returns {Promise<boolean>} true only if both Python services actually answered healthy right now.
     */
    checkNow() {
        if (this._checkNowPromise) return this._checkNowPromise

        this._checkNowPromise = fetch(CHECK_NOW_URL, { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : Promise.reject()))
            .then((health) => {
                const active = health.mode === 'http'
                const dot = active ? 'ok' : (health.local_runner === 'available' ? 'warn' : 'err')
                const title = active
                    ? 'HTTP services online'
                    : (dot === 'warn' ? 'HTTP services offline — using local fallback' : 'All analysis services unavailable')
                return { active, dot, title }
            })
            .catch(() => ({ active: false, dot: 'err', title: 'Status unavailable' }))
            .then(({ active, dot, title }) => {
                this._checkNowPromise = null
                const previous = this.dot
                this.dot = dot
                this.title = title
                writeSeed(dot, title)
                window.dispatchEvent(new CustomEvent('osca:ml-health', { detail: { dot, title, previous } }))
                return active
            })
        return this._checkNowPromise
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
