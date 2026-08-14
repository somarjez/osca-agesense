// One shared service-health source for the authenticated browser session.
// Cached nav checks drive the topbar; checkNow() is always uncached and gates
// login initialization plus every ML-dependent action.
const CACHE_KEY = 'osca_nav_health'
const URL = '/ml/nav-health'
const CHECK_NOW_URL = '/ml/wake-status'
const INTERVAL_DOWN_MS = 10000
const INTERVAL_OK_MS = 30000

const unavailableServices = () => ({
    preprocessor: 'network_error',
    inference: 'network_error',
})

function readSeed() {
    try {
        const cached = JSON.parse(sessionStorage.getItem(CACHE_KEY) || 'null')
        if (cached && typeof cached.dot === 'string') {
            return {
                dot: cached.dot,
                title: cached.title || '',
                services: cached.services || {},
            }
        }
    } catch (e) { /* unavailable/corrupt sessionStorage */ }
    return { dot: 'checking', title: 'Checking analysis services…', services: {} }
}

function writeSeed(snapshot) {
    try {
        sessionStorage.setItem(CACHE_KEY, JSON.stringify({ ...snapshot, ts: Date.now() }))
    } catch (e) { /* in-memory state remains authoritative */ }
}

function freshSnapshot(health) {
    const services = health.services || {
        preprocessor: health.preprocessor || 'unreachable',
        inference: health.inference || 'unreachable',
    }
    const active = health.mode === 'http'
        && services.preprocessor === 'ok'
        && services.inference === 'ok'
    const warming = Object.values(services).some((state) => ['starting', 'warming', 'warming_up'].includes(state))
    const dot = active ? 'ok' : (health.local_runner === 'available' ? 'warn' : 'err')
    const title = active
        ? 'HTTP services online'
        : (warming
            ? 'Analysis services are warming up'
            : (dot === 'warn' ? 'HTTP services offline — using local fallback' : 'Analysis services unavailable'))

    return { active, dot, title, services }
}

const seed = readSeed()

const mlHealth = {
    dot: seed.dot,
    title: seed.title,
    services: seed.services,
    _timer: null,
    _inFlight: false,
    _inFlightPromise: null,
    _checkNowPromise: null,
    _requestVersion: 0,

    _commit(snapshot, requestVersion) {
        // A slower response started before a newer check must never replace
        // newer READY/OFFLINE truth.
        if (requestVersion !== this._requestVersion) return false

        const previous = this.dot
        this.dot = snapshot.dot
        this.title = snapshot.title
        this.services = snapshot.services || {}
        writeSeed({ dot: this.dot, title: this.title, services: this.services })
        window.dispatchEvent(new CustomEvent('osca:ml-health', {
            detail: { dot: this.dot, title: this.title, services: this.services, previous },
        }))
        this._reschedule()
        return true
    },

    refresh() {
        if (this._checkNowPromise) {
            return this._checkNowPromise.then(() => ({
                dot: this.dot,
                title: this.title,
                services: this.services,
            }))
        }
        if (this._inFlight) return this._inFlightPromise

        this._inFlight = true
        const requestVersion = ++this._requestVersion
        this._inFlightPromise = fetch(URL, { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : Promise.reject()))
            .then((data) => ({
                dot: data.dot,
                title: data.title,
                services: data.services || {},
            }))
            .catch(() => ({ dot: 'err', title: 'Status unavailable', services: unavailableServices() }))
            .then((snapshot) => {
                this._inFlight = false
                this._commit(snapshot, requestVersion)
                return snapshot
            })

        return this._inFlightPromise
    },

    checkNow() {
        if (this._checkNowPromise) return this._checkNowPromise

        const requestVersion = ++this._requestVersion
        const pending = fetch(CHECK_NOW_URL, { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : Promise.reject()))
            .then(freshSnapshot)
            .catch(() => ({
                active: false,
                dot: 'err',
                title: 'Status unavailable',
                services: unavailableServices(),
            }))
            .then((snapshot) => {
                this._commit(snapshot, requestVersion)
                return snapshot.active
            })
            .finally(() => {
                if (this._checkNowPromise === pending) this._checkNowPromise = null
            })

        this._checkNowPromise = pending
        return pending
    },

    _reschedule() {
        clearTimeout(this._timer)
        const delay = this.dot === 'ok' ? INTERVAL_OK_MS : INTERVAL_DOWN_MS
        this._timer = setTimeout(() => this.refresh(), delay)
    },

    _start() {
        // The guard also calls checkNow() when it mounts; the shared promise
        // collapses both startup paths into one fresh request.
        this.checkNow()
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) this.checkNow()
        })
    },
}

mlHealth._start()

window.OSCA = window.OSCA || {}
window.OSCA.mlHealth = mlHealth

export default mlHealth
