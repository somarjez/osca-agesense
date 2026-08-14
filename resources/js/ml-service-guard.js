// Global "ML services are off" guard — mounted once in layouts/app.blade.php via
// <x-ml-service-guard />. Listens to the single shared poller in
// resources/js/ml-health.js (the topbar dot listens to the same broadcast — see
// that module's header comment for why this used to be two independent pollers
// writing to two different Alpine scopes) and surfaces a blocking warning modal
// plus live toasts on later state changes. Alpine is Livewire 3's bundled copy —
// do NOT import Alpine here (mirrors resources/js/app.js).
//
// Also exposes window.OSCA.mlGate — the single shared pre-flight gate every
// ML-dependent operation (Batch/Individual Analysis, Re-run Assessment, Bulk
// Upload's ML stage) awaits before it starts. See require() below and
// window.OSCA.requireMl() in app.js.
document.addEventListener('alpine:init', () => {
    Alpine.data('mlServiceGuard', (cfg) => ({
        dot: 'checking',
        title: 'Checking analysis services…',
        services: { preprocessor: 'checking', inference: 'checking' },
        modalOpen: false,
        starting: false,
        // True while the currently-open modal exists because a pending
        // ML-dependent operation explicitly required it (require(), below),
        // as opposed to the background health monitor noticing an outage on
        // its own. Both look the same to the user (same modal, same copy) —
        // this only affects what happens on Dismiss (see require()).
        requiredMode: false,

        toast: { show: false, type: 'warning', message: '', timer: null },

        // Wake state — ported from resources/views/ml/status.blade.php's inline
        // x-data (see that file for the original, fuller comments on *why*).
        waking: false,
        wakeDone: false,
        wakeGaveUp: false,
        wakeError: null,
        wakeElapsed: 0,
        wakeFailCount: 0,
        wakeRequestInFlight: false,
        statusCheckInFlight: false,
        wakeGeneration: 0,
        wakeTicker: null,
        wakePoller: null,
        wakeStartedAt: null,
        wakeMaxSeconds: 420,

        // Pending require() promise plumbing — see require()/_settleRequire().
        _requirePromise: null,
        _requireResolve: null,

        // Last RAW health reading seen (regardless of whether it was trusted
        // enough to update `dot`/close the modal) — see _applyHealth()'s
        // recovery-confirmation check below.
        _rawDot: null,

        // Explicit wake flow's confirmation counterpart — see _noteWakeSignal().
        _lastWakeSignalPositive: false,
        _recoveryConfirmationTimer: null,

        init() {
            // Do not decide whether to open the login modal from the cached
            // session seed. Only the fresh check below may leave checking.
            window.addEventListener('osca:ml-health', this._onHealth = (e) => {
                this._applyHealth(e.detail.dot, e.detail.title, e.detail.services);
            });

            // Every way the modal can close — the Dismiss button, ESC
            // (x-modal's own @keydown.escape.window="modalOpen = false"),
            // and a backdrop click (x-modal's own @click="modalOpen =
            // false") — funnels through here, so a pending require()
            // promise always gets settled no matter which the user used.
            // A native Alpine $watch, not a manual document listener — it
            // needs no explicit teardown (unlike _onHealth/_onNavigating
            // above), since Alpine owns its own effect lifecycle.
            this.$watch('modalOpen', (open) => {
                if (!open) this._settleRequire(false);
            });

            // Deliberately assigned only here, never removed in destroy():
            // window.OSCA.requireMl() (app.js) treats a MISSING mlGate as
            // "nothing to check, proceed" — so deleting this on every
            // Livewire navigation (destroy() runs on every one, persisted
            // element or not) would silently turn every pre-flight check
            // into a no-op for the rest of the session. This component's
            // reactive `this` is the same persisted instance across
            // navigations (Livewire's persist plugin keeps the original DOM
            // node + its bound Alpine scope, never re-running init()), so
            // the closure below stays valid regardless of how many times
            // destroy() has run.
            window.OSCA.mlGate = { require: () => this.require() };

            // One-time FRESH check at session start (init() only runs once
            // per tab — this element is @persist'd, so Livewire navigations
            // don't re-run it). The seed above can come from
            // sessionStorage (ml-health.js's readSeed()), which has NO
            // staleness check — if the tab has been open a while and the
            // services went idle since the last poll, the seed can report a
            // stale "ok" and this modal would never appear even though the
            // services are genuinely down right now. Reported as "the
            // wake-up modal at the start of a session is gone" — it WAS
            // still working later, when the QoL submit flow's requireMl()
            // ran its own fresh check, which is what made this seed-only
            // gap visible: same outage, but one path caught it and one
            // didn't. checkNow() broadcasts osca:ml-health on completion
            // exactly like a normal poll tick, so _onHealth above (wired
            // just above this call) picks up the result and opens the
            // modal the same way any other detected outage does — no
            // special-case handling needed here beyond firing the check.
            window.OSCA.mlHealth.checkNow();
        },

        destroy() {
            this.stopWake();
            clearTimeout(this.toast.timer);
            clearTimeout(this._recoveryConfirmationTimer);
            if (this._onHealth) window.removeEventListener('osca:ml-health', this._onHealth);
        },

        _applyHealth(newDot, title, services = {}) {
            const wasKnownDown = this.dot === 'err' || this.dot === 'warn';
            const previousRaw = this._rawDot;
            this._rawDot = newDot;
            this.title = title || this.title;
            this.services = { ...this.services, ...services };

            const isOk = newDot === 'ok';
            const isDown = newDot === 'err' || newDot === 'warn';

            if (isOk) {
                // A single successful reading right after a known outage can
                // be a Render cold-start false positive — the container's
                // port is bound and it answers /health once, but the model
                // isn't actually loaded yet, or it re-sleeps moments later.
                // Trusting that one reading used to flash the modal straight
                // to "services are ready" (closing it) only for the very
                // next check, seconds later, to say the opposite — reported
                // as "it shows Services are Enabled but it's not". Require
                // the PREVIOUS raw reading to have ALSO been ok before
                // treating a post-outage recovery as real; a fresh page load
                // (previousRaw === null) or a recovery when nothing was ever
                // confirmed down needs no such corroboration.
                if (wasKnownDown && previousRaw !== 'ok') {
                    clearTimeout(this._recoveryConfirmationTimer);
                    this._recoveryConfirmationTimer = setTimeout(
                        () => window.OSCA.mlHealth.checkNow(),
                        3000,
                    );
                    return; // wait for the next tick to corroborate — dot/modal stay exactly as they were
                }

                if (wasKnownDown) {
                    this.showToast('success', 'Analysis services are back online.');
                }
                this.dot = newDot;
                if (this.modalOpen || this.waking) {
                    this.modalOpen = false;
                    this.stopWake();
                }
                this._settleRequire(true);
                return;
            }

            this.dot = newDot;

            // (Re)open the modal only on a GENUINE transition into a down
            // state — previous confirmed reading was ok, or this is the
            // first confirmed reading of the session ('checking'/null don't
            // count as "known down", so a page load that resolves straight
            // to down still opens the modal once). Ticks that arrive while
            // ALREADY down (wasKnownDown === true) intentionally do
            // nothing — that's what keeps a dismissed warning from being
            // reopened every 10s by the background poller for the rest of
            // this SAME outage, without needing any "shown once" flag that
            // would (as before) survive into the NEXT outage too.
            if (isDown && !wasKnownDown) {
                this.modalOpen = true;
            }
        },

        showToast(type, message) {
            clearTimeout(this.toast.timer);
            this.toast.type = type;
            this.toast.message = message;
            this.toast.show = true;
            this.toast.timer = setTimeout(() => { this.toast.show = false; }, 5000);
        },

        startLocal() {
            this.starting = true;
            this.$refs.startForm?.submit();
        },

        // ── Pre-flight gate ───────────────────────────────────────────────
        // Awaited by window.OSCA.requireMl() (app.js) before Batch/
        // Individual Analysis, Re-run Assessment, and Bulk Upload's ML
        // stage. Resolves true only once a FRESH (uncached) health check
        // confirms the services are actually up — never on an assumed wake
        // success, and never on a stale earlier reading.
        //
        // A call that lands while one is already pending returns the SAME
        // promise instead of starting a second check/modal — this is what
        // guarantees only one shared modal even if several operations (or
        // the background monitor) all notice an outage at once.
        require() {
            if (this._requirePromise) return this._requirePromise;

            this._requirePromise = new Promise((resolve) => {
                this._requireResolve = resolve;
            }).finally(() => {
                this._requirePromise = null;
                this.requiredMode = false;
            });

            window.OSCA.mlHealth.checkNow().then((active) => {
                if (active && this.dot === 'ok') {
                    this._settleRequire(true);
                    return;
                }
                // Still down: (re)open the modal even if it's already open
                // from background detection, and even if the user already
                // dismissed an earlier background warning during this same
                // outage — a REQUESTED operation that needs the service
                // must never be silently allowed to proceed against a dead
                // one just because an informational warning was dismissed
                // earlier.
                this.requiredMode = true;
                this.modalOpen = true;
            });

            return this._requirePromise;
        },

        _settleRequire(result) {
            if (!this._requireResolve) return;
            const resolve = this._requireResolve;
            this._requireResolve = null; // guards against a second, later settle attempt
            resolve(result);
        },

        // ── Wake flow (hosted / Render) — ported from ml/status.blade.php ────────
        startWake() {
            if (this.waking) return;
            this.wakeGeneration++;
            this.waking = true;
            this.wakeDone = false;
            this.wakeGaveUp = false;
            this.wakeError = null;
            this.wakeStartedAt = Date.now();
            this.wakeElapsed = 0;
            this.wakeFailCount = 0;
            this.wakeRequestInFlight = false;
            this.statusCheckInFlight = false;
            this._lastWakeSignalPositive = false;

            this.wakeTicker = setInterval(() => {
                this.wakeElapsed = Math.floor((Date.now() - this.wakeStartedAt) / 1000);
            }, 1000);
            this.wakeLoop();
            this.pollWake();
        },

        pollWake() {
            this.wakePoller = setInterval(() => {
                if (this.wakeElapsed >= this.wakeMaxSeconds) {
                    this.stopWake();
                    this.wakeGaveUp = true;
                    return;
                }
                this.checkStatus();
                this.wakeLoop();
            }, 3000);
        },

        wakeLoop() {
            if (this.wakeRequestInFlight || !this.waking) return;
            const generation = this.wakeGeneration;
            this.wakeRequestInFlight = true;
            fetch(cfg.wakeUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': cfg.csrfToken, Accept: 'application/json' },
            })
                .then((r) => {
                    if (!r.ok) { const e = new Error('wake request failed: ' + r.status); e.httpError = true; throw e; }
                    return r.json();
                })
                .then((d) => {
                    if (generation !== this.wakeGeneration || !this.waking) return;
                    this.wakeRequestInFlight = false;
                    this._noteWakeSignal(!!d.ready);
                })
                .catch((err) => {
                    if (generation !== this.wakeGeneration || !this.waking) return;
                    this.wakeRequestInFlight = false;
                    if (err && err.httpError) {
                        this.stopWake();
                        this.wakeError = 'Lost connection to the server while waking services — please reload the page.';
                    }
                });
        },

        checkStatus() {
            if (this.statusCheckInFlight) return;
            const generation = this.wakeGeneration;
            this.statusCheckInFlight = true;
            window.OSCA.mlHealth.checkNow()
                .then((active) => {
                    if (generation !== this.wakeGeneration || !this.waking) return;
                    const states = Object.values(window.OSCA.mlHealth.services || {});
                    const networkFailed = states.length > 0
                        && states.every((state) => state === 'network_error');
                    this.wakeFailCount = networkFailed ? this.wakeFailCount + 1 : 0;
                    if (this.wakeFailCount >= 3) {
                        this.stopWake();
                        this.wakeError = 'Unable to check the analysis services. Please try again.';
                        return;
                    }
                    this._noteWakeSignal(active);
                })
                .catch(() => {
                    if (generation !== this.wakeGeneration || !this.waking) return;
                    this.wakeFailCount++;
                    if (this.wakeFailCount >= 3) {
                        this.stopWake();
                        this.wakeError = 'Unable to check the analysis services. Please try again.';
                    }
                })
                .finally(() => {
                    if (generation === this.wakeGeneration) this.statusCheckInFlight = false;
                });
        },

        // Requires TWO consecutive positive readings (each from the existing
        // ~3s wake-poll cadence — see pollWake()) before declaring the
        // services actually ready. A single positive from either the POST
        // /ml/wake response or the GET /ml/wake-status check can be a
        // Render cold-start false positive (container port bound, model not
        // loaded yet, or it re-sleeps moments later); trusting it
        // immediately used to close the modal on a wake that wasn't
        // actually done. A negative reading resets the streak, so it takes
        // two IN A ROW, not just two total.
        _noteWakeSignal(positive) {
            if (positive && this._lastWakeSignalPositive) {
                this.finishWake();
            } else {
                this._lastWakeSignalPositive = positive;
            }
        },

        // Unlike the status page, we don't force a reload — the guard mounts on
        // every page and a reload here would interrupt whatever the user is
        // doing. Set the local dot immediately for this modal, and also force
        // the shared poller to refresh (bypassing its in-flight guard) so the
        // topbar dot and every other listener flip green without waiting for
        // the next scheduled tick. The shared poller still deduplicates this
        // against any check already in flight.
        finishWake() {
            this.stopWake();
            this.wakeDone = true;
            this.dot = 'ok';
            this.showToast('success', 'Analysis services are back online.');
            window.OSCA.mlHealth.refresh({ force: true });
            this._settleRequire(true);
            setTimeout(() => { this.modalOpen = false; }, 900);
        },

        stopWake() {
            this.wakeGeneration++;
            this.waking = false;
            this.wakeRequestInFlight = false;
            this.statusCheckInFlight = false;
            clearInterval(this.wakeTicker);
            clearInterval(this.wakePoller);
        },

        // Single state machine driving the modal's copy/buttons — see
        // components/ml-service-guard.blade.php. Collapses the various flags
        // above into the four states the task spec calls for (plus
        // 'checking', shown as nothing/idle) instead of the view branching
        // on raw flags directly, so there is exactly one place that decides
        // "what is the guard currently doing".
        get phase() {
            if (this.waking) return 'waking';
            if (this.dot === 'ok') return 'active';
            if (this.wakeGaveUp || this.wakeError) return 'error';
            if (this.dot === null || this.dot === 'checking') return 'checking';
            return 'inactive';
        },

        serviceLabel(state) {
            if (state === 'ok' || state === 'ready') return 'Ready';
            if (state === 'warming' || state === 'warming_up') return 'Warming Up';
            if (this.waking && ['checking', 'unreachable', 'offline', 'error', 'network_error'].includes(state)) return 'Starting';
            if (state === 'unreachable' || state === 'offline') return 'Offline';
            if (state === 'error' || state === 'server_error' || state === 'network_error') return 'Error';
            return 'Checking';
        },

        serviceTone(state) {
            const label = this.serviceLabel(state);
            if (label === 'Ready') return 'text-low-700 dark:text-low-300';
            if (label === 'Error' || label === 'Offline') return 'text-critical-700 dark:text-[#e08070]';
            return 'text-moderate-700 dark:text-moderate-300';
        },

        fmt(s) {
            const m = Math.floor(s / 60);
            const sec = s % 60;
            return m > 0 ? `${m}m ${sec}s` : `${sec}s`;
        },
    }));
});
