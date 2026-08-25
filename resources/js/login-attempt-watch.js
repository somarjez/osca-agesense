// Notifies the currently-active session when someone else tries to sign in
// to the same account while SingleSession (app/Support/SingleSession.php)
// blocks them. That blocked attempt is written to a short-lived cache entry
// (routes/auth.php's blocked-login branch); this poller reads-and-clears it
// via /account/login-attempt-check (routes/web.php) so it only ever fires
// once per attempt. Modeled on the shared-poller pattern in ml-health.js,
// but deliberately does NOT seed from sessionStorage — this is
// security-sensitive and transient by design; a stale seed showing (or
// hiding) an attempt across a reload would be actively misleading.
const URL = '/account/login-attempt-check'
const INTERVAL_MS = 20000

const loginAttemptWatch = {
    _timer: null,
    _inFlight: false,

    _check() {
        if (this._inFlight || document.hidden) return
        this._inFlight = true

        fetch(URL, { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : Promise.reject()))
            .then((data) => {
                if (data.attempt) {
                    window.dispatchEvent(new CustomEvent('osca:login-attempt', { detail: data.attempt }))
                }
            })
            .catch(() => { /* transient network hiccup — next tick retries */ })
            .finally(() => { this._inFlight = false })
    },

    _start() {
        // The alert component registers its window listener while Livewire
        // initializes. Waiting for that lifecycle event prevents a fast
        // read-and-clear response from being dispatched before the listener
        // exists and silently losing the notification.
        document.addEventListener('livewire:initialized', () => this._check(), { once: true })
        this._timer = setInterval(() => this._check(), INTERVAL_MS)
        // Catch an attempt that happened while this tab was backgrounded,
        // instead of waiting up to INTERVAL_MS after regaining focus.
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) this._check()
        })
    },
}

loginAttemptWatch._start()

export default loginAttemptWatch
