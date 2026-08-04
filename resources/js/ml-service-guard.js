// Global "ML services are off" guard — mounted once in layouts/app.blade.php via
// <x-ml-service-guard />. Polls the same cached ml.nav-health endpoint the topbar
// status dot already uses, and surfaces a blocking warning modal (once per tab
// session) plus live toasts on later state changes. Alpine is Livewire 3's bundled
// copy — do NOT import Alpine here (mirrors resources/js/app.js).
document.addEventListener('alpine:init', () => {
    Alpine.data('mlServiceGuard', (cfg) => ({
        dot: null,
        modalOpen: false,
        pollTimer: null,
        starting: false,

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
        wakeTicker: null,
        wakePoller: null,
        wakeStartedAt: null,
        wakeMaxSeconds: 420,

        init() {
            this.checkHealth();
            this.pollTimer = setInterval(() => this.checkHealth(), 45000);

            document.addEventListener('visibilitychange', this._onVisible = () => {
                if (!document.hidden) this.checkHealth();
            });
            document.addEventListener('livewire:navigating', this._onNavigating = () => this.destroy());
        },

        destroy() {
            clearInterval(this.pollTimer);
            this.stopWake();
            clearTimeout(this.toast.timer);
            if (this._onVisible) document.removeEventListener('visibilitychange', this._onVisible);
            if (this._onNavigating) document.removeEventListener('livewire:navigating', this._onNavigating);
        },

        checkHealth() {
            fetch(cfg.navHealthUrl, { headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? r.json() : Promise.reject()))
                .then((d) => this._applyHealth(d.dot, d.title))
                .catch(() => this._applyHealth('err', 'Status unavailable'));
        },

        _applyHealth(newDot, title) {
            const previous = this.dot;
            this.dot = newDot;

            const wasOk = previous === 'ok';
            const isOk = newDot === 'ok';

            if (isOk) {
                if (previous !== null && !wasOk) {
                    this.showToast('success', 'Analysis services are back online.');
                }
                if (this.modalOpen || this.waking) {
                    this.modalOpen = false;
                    this.stopWake();
                }
                return;
            }

            // Down (or still down). First-ever check (previous === null) and any
            // ok → not-ok transition both count as "just went down".
            if (previous === null || wasOk) {
                if (sessionStorage.getItem('mlGuardModalShown') === '1') {
                    this.showToast('warning', title || 'Analysis services are unavailable.');
                } else {
                    sessionStorage.setItem('mlGuardModalShown', '1');
                    this.modalOpen = true;
                }
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

        // ── Wake flow (hosted / Render) — ported from ml/status.blade.php ────────
        startWake() {
            if (this.waking) return;
            this.waking = true;
            this.wakeDone = false;
            this.wakeGaveUp = false;
            this.wakeError = null;
            this.wakeStartedAt = Date.now();
            this.wakeElapsed = 0;
            this.wakeFailCount = 0;

            // Bonus fast path only — see status.blade.php's startWake() for why
            // this isn't depended on (client network may block *.onrender.com).
            fetch(cfg.preprocessUrl + '/health', { mode: 'no-cors' }).catch(() => {});
            fetch(cfg.inferenceUrl + '/health', { mode: 'no-cors' }).catch(() => {});

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
                    this.wakeRequestInFlight = false;
                    if (d.ready && this.waking) this.finishWake();
                })
                .catch((err) => {
                    this.wakeRequestInFlight = false;
                    if (err && err.httpError) {
                        this.stopWake();
                        this.wakeError = 'Lost connection to the server while waking services — please reload the page.';
                    }
                });
        },

        checkStatus() {
            fetch(cfg.wakeStatusUrl, { headers: { Accept: 'application/json' } })
                .then((r) => {
                    if (!r.ok) throw new Error('status check failed: ' + r.status);
                    return r.json();
                })
                .then((d) => {
                    this.wakeFailCount = 0;
                    if (d.mode === 'http') this.finishWake();
                })
                .catch(() => {
                    this.wakeFailCount++;
                    if (this.wakeFailCount >= 3) {
                        this.stopWake();
                        this.wakeError = 'Lost connection to the server while waking services — please reload the page.';
                    }
                });
        },

        // Unlike the status page, we don't force a reload — the guard mounts on
        // every page and a reload here would interrupt whatever the user is
        // doing. Just reflect the recovered state and let the next natural
        // navigation refresh the topbar dot.
        finishWake() {
            this.stopWake();
            this.wakeDone = true;
            this.dot = 'ok';
            this.showToast('success', 'Analysis services are back online.');
            setTimeout(() => { this.modalOpen = false; }, 900);
        },

        stopWake() {
            this.waking = false;
            clearInterval(this.wakeTicker);
            clearInterval(this.wakePoller);
        },

        fmt(s) {
            const m = Math.floor(s / 60);
            const sec = s % 60;
            return m > 0 ? `${m}m ${sec}s` : `${sec}s`;
        },
    }));
});
