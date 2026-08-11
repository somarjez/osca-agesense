// Idle auto-logout — mounted once in layouts/app.blade.php via <x-idle-warning />,
// @persist'd (see that file) so it survives wire:navigate untouched instead of
// tearing down and re-registering its document-level listeners on every
// navigation (the exact bug class documented in ml-service-guard.blade.php).
// Alpine is Livewire 3's bundled copy — do NOT import Alpine here (mirrors
// resources/js/app.js and ml-service-guard.js).
//
// Idle time is tracked from REAL user input only (mouse/keyboard/scroll/touch
// on `document`), never from network activity. This matters: the dashboard's
// own wire:poll + 18s version-check interval would otherwise look like
// constant "activity" and the timer would never fire — see config/auth.php's
// idle_logout_minutes doc block for the full explanation of why this is a
// separate concept from single_session_idle_minutes.
document.addEventListener('alpine:init', () => {
    Alpine.data('idleLogout', (cfg) => ({
        warning: false,
        remaining: cfg.warningSeconds,
        announceText: '',

        _idleMs: cfg.idleMinutes * 60000,
        _warnMs: cfg.warningSeconds * 1000,
        _lastActivity: Date.now(),
        _lastBucket: null,
        _ticker: null,
        _onActivity: null,
        _onStorage: null,

        init() {
            // Seed from another tab's last-known activity, if it's fresher
            // than "now" (e.g. this tab was just opened from a bookmark).
            try {
                const stored = Number(localStorage.getItem('osca:lastActivity'));
                if (stored && stored > this._lastActivity) this._lastActivity = stored;
            } catch (e) { /* localStorage unavailable — timer still works, just not cross-tab */ }

            // Throttled to ~1/s — resetting state on every raw mousemove is wasteful.
            this._onActivity = this._throttle(() => this._touch(), 1000);
            ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach((evt) => {
                document.addEventListener(evt, this._onActivity, { passive: true });
            });

            // Cross-tab sync: activity in any tab keeps every tab's clock fresh,
            // and dismisses a warning already showing in this tab.
            this._onStorage = (e) => {
                if (e.key !== 'osca:lastActivity' || !e.newValue) return;
                const ts = Number(e.newValue);
                if (ts > this._lastActivity) this._lastActivity = ts;
                if (this.warning) this._reset();
            };
            window.addEventListener('storage', this._onStorage);

            // Interval-based, not a setTimeout chain — a laptop sleeping/waking
            // resolves correctly on the next tick instead of firing a queued
            // timeout late (or all at once) on resume.
            this._ticker = setInterval(() => this._tick(), 1000);
        },

        stay() {
            this._touch();
        },

        _touch() {
            this._lastActivity = Date.now();
            try { localStorage.setItem('osca:lastActivity', String(this._lastActivity)); } catch (e) {}
            if (this.warning) this._reset();
        },

        _reset() {
            this.warning = false;
            this._lastBucket = null;
        },

        _tick() {
            const elapsed = Date.now() - this._lastActivity;

            if (elapsed >= this._idleMs) {
                this._logout();
                return;
            }

            if (elapsed >= this._idleMs - this._warnMs) {
                const secsLeft = Math.max(0, Math.ceil((this._idleMs - elapsed) / 1000));
                this.remaining = secsLeft;
                this.warning = true;

                // Announce roughly every 10s, not every second — an aria-live
                // region that updates every tick just chatters for screen readers.
                const bucket = Math.ceil(secsLeft / 10) * 10;
                if (bucket !== this._lastBucket) {
                    this._lastBucket = bucket;
                    this.announceText = bucket > 0
                        ? `You will be signed out in about ${bucket} seconds due to inactivity.`
                        : 'Signing out now due to inactivity.';
                }
            }
        },

        _logout() {
            clearInterval(this._ticker);
            // A real form submit (not fetch) — it follows the 302 to /login and
            // renders the flashed status message. fetch() would follow the
            // redirect internally and consume the flash before it ever painted.
            this.$refs.logoutForm.submit();
        },

        _throttle(fn, ms) {
            let last = 0;
            return (...args) => {
                const now = Date.now();
                if (now - last >= ms) {
                    last = now;
                    fn(...args);
                }
            };
        },
    }));
});
