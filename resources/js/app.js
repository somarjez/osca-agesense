import './bootstrap'
import './ml-health'
import './ml-service-guard'
import './idle-logout'
import './login-attempt-watch'
import './navigation'
import { loadCharts, loadMaps } from './loaders'

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

    // ── Restore dark mode after SPA navigation ────────────────────────────────
    // wire:navigate replaces <html>'s attributes with the freshly-fetched page's
    // raw (non-Alpine-evaluated) attributes (Livewire's replaceHtmlAttributes()),
    // which strips the `dark` class Alpine's :class="{ dark }" binding had added.
    // Alpine's reactive `dark` state isn't reset, but nothing re-triggers its
    // effect, so the DOM class stays stripped until the user toggles dark mode
    // again. Same fix as the afterprint restore below: reapply from localStorage.
    document.addEventListener('livewire:navigated', function () {
        try {
            document.documentElement.classList.toggle('dark', localStorage.getItem('darkMode') === 'true')
        } catch (e) { /* localStorage unavailable */ }
    })

    // ── Active sidebar link, for the @persist'd sidebar ───────────────────────
    // The sidebar and topbar are @persist'd in layouts/app.blade.php — their DOM
    // survives wire:navigate untouched instead of being destroyed and rebuilt
    // every click (the single biggest source of per-navigation cost: ~20
    // role-gated links re-parsed/re-bound, plus the Services link's x-init
    // firing its ml.nav-health fetch again every time). The trade-off: nothing
    // inside a persisted element gets fresh server-rendered HTML again after
    // the first load, so anything that used to depend on that (which link is
    // "active") needs a client-side source of truth instead.
    //
    // This mirrors Livewire's own documented pattern for exactly this problem
    // (see their SupportNavigate test fixture navbar-sidebar.blade.php): an
    // Alpine.reactive() object holds the current path, updated once per
    // navigation, and a magic method reads it reactively so :class bindings
    // just re-evaluate on their own — no manual classList poking needed.
    const navState = Alpine.reactive({ path: window.location.pathname })
    document.addEventListener('livewire:navigated', () => {
        navState.path = window.location.pathname
    })
    // path: a single path string, or an array of path strings when a nav item
    // should also light up on an unrelated URL tree (e.g. Drafts also
    // highlighting while continuing a draft under /surveys/profile/drafts/*).
    // prefix modes:
    //   false (default): exact match only (e.g. Dashboard, GIS Analytics —
    //     pages with no nested sub-routes).
    //   true: highlights on the path itself or any deeper path under it (e.g.
    //     User Management also matches /users/create) — the client-side
    //     equivalent of the old request()->routeIs('name.*') wildcard checks.
    //   'resource': highlights on the path itself or a UUID-keyed child
    //     route one level down (e.g. Senior Records also matches
    //     /seniors/{uuid} and /seniors/{uuid}/edit — SeniorCitizen's route
    //     key is 'uuid', not an auto-increment id, see
    //     app/Models/SeniorCitizen.php's getRouteKeyName()) WITHOUT also
    //     matching static sibling routes like /seniors/create or
    //     /seniors/drafts, which a blanket prefix would.
    const UUID_SEGMENT = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'
    Alpine.magic('navActive', () => (paths, prefix = false) => {
        const list = Array.isArray(paths) ? paths : [paths]
        return list.some(p => {
            if (navState.path === p) return true
            if (prefix === 'resource') return new RegExp('^' + p + '/' + UUID_SEGMENT + '(/edit)?$').test(navState.path)
            return prefix && navState.path.startsWith(p + '/')
        })
    })

    // ── Persisted topbar content sync ─────────────────────────────────────────
    // Page title is server-rendered into the now-persisted topbar, so it'd
    // otherwise freeze at whatever it was on the very first page load. <head>
    // is NOT persisted — Livewire merges it fresh on every navigation — so the
    // page-title <meta> tag (layouts/app.blade.php) is always current; copy it
    // into the frozen <h1>.
    document.addEventListener('livewire:navigated', () => {
        const titleEl = document.getElementById('topbar-page-title')
        const metaTitle = document.querySelector('meta[name="page-title"]')
        if (titleEl && metaTitle) titleEl.textContent = metaTitle.content
    })

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

    // ── Mutually-exclusive checkbox groups (ProfileSurvey) ───────────────────
    // The "none/healthy" option can't coexist with real selections. Runs fully
    // client-side against Livewire 3's reactive $wire proxy — no network
    // request; the deferred wire:model payload carries the corrected array on
    // the next Livewire call. Server-side sanitizeExclusiveGroups() stays the
    // authority if JS ever fails.
    Alpine.data('exclusiveGroup', (prop, exclusive) => ({
        onChange(e) {
            const el = e.target
            if (el.type !== 'checkbox' || !el.checked) return // unchecking always allowed (empty group OK)
            if (el.value === exclusive) {
                this.$wire[prop] = [exclusive]
            } else {
                const cur = Array.from(this.$wire[prop] ?? [])
                if (cur.includes(exclusive)) {
                    this.$wire[prop] = cur.filter(v => v !== exclusive)
                }
            }
        },
    }))

    // ── Family composition cross-field guard (ProfileSurvey Step 2) ──────────
    // Working Children must not exceed Number of Children, and Financially
    // Supported by Children can't be Yes/Occasional when Number of Children
    // is 0. Reads $wire directly like exclusiveGroup above — plain
    // (undecorated) wire:model already updates $wire reactively on every
    // change, so no local state mirror or network round trip is needed for
    // the live clamp/grayout. ProfileSurvey::step2Rules() is the actual
    // enforcement authority server-side (lte:numChildren + a closure rule).
    Alpine.data('familyCompositionGuard', () => ({
        get numChildren() {
            return Number(this.$wire.numChildren) || 0
        },
        get workingChildrenExceeds() {
            return Number(this.$wire.numWorkingChildren) > this.numChildren
        },
        get supportBlockedByZeroChildren() {
            return this.numChildren === 0
        },
        // Spouse/Partner Working's valid options depend on Marital Status
        // (Step 1) — every status now has its own allowed set, not just
        // Single. maritalStatus lives on Step 1 but $wire is shared across
        // the whole component, so it's readable here even while viewing
        // Step 2. Mirrors ProfileSurvey::spouseWorkingAllowedValues() (the
        // server-side enforcement authority) exactly.
        spouseWorkingAllowed() {
            switch (this.$wire.maritalStatus) {
                case 'Single': return ['N/A']
                case 'Widowed': return ['Deceased']
                case 'Married': return ['Yes', 'No', 'Deceased']
                case 'Separated': return ['Yes', 'No', 'N/A']
                default: return null
            }
        },
        // Same not-already-selected deadlock guard as dependencyCrossGuard
        // below: an option currently selected is never force-disabled, so a
        // legacy bulk-imported contradictory record stays editable.
        spouseWorkingOptionDisabled(option) {
            const allowed = this.spouseWorkingAllowed()
            return allowed !== null && !allowed.includes(option) && this.$wire.spouseWorking !== option
        },
        get spouseWorkingBlockedMessage() {
            switch (this.$wire.maritalStatus) {
                case 'Single': return 'Only "N/A" applies when marital status is Single.'
                case 'Widowed': return 'Only "Deceased" applies when marital status is Widowed.'
                case 'Married': return '"N/A" is unavailable when marital status is Married.'
                case 'Separated': return '"Deceased" is unavailable when marital status is Separated.'
                default: return ''
            }
        },
        clampWorkingChildren(e) {
            const max = this.numChildren
            const v = Number(e.target.value)
            if (v > max) {
                e.target.value = max
                this.$wire.numWorkingChildren = max
            }
        },
        // Household Size must be 1 while Living With (Step 4) is "Alone" —
        // same cross-step $wire read as spouseWorkingAllowed() above.
        get aloneRequiresSingleHousehold() {
            return (this.$wire.livingWith ?? []).includes('Alone')
        },
        clampHouseholdSize(e) {
            if (this.aloneRequiresSingleHousehold) {
                e.target.value = 1
                this.$wire.householdSize = 1
            }
        },
    }))

    // ── Dependency cross-field guard (ProfileSurvey Step 4) ──────────────────
    // livingWith's "Alone" and householdCondition's "Overcrowded in home" /
    // "Shared with relatives" are mutually exclusive. Unlike exclusiveGroup()
    // (same-property, silently auto-unchecks), this is cross-property and
    // grays out + disables the contradicting checkbox instead of silently
    // correcting it — the user must uncheck one side first. A checkbox is
    // only disabled when it is NOT already checked, so a pre-existing
    // contradictory legacy record (e.g. from BulkUploadController, which
    // bypasses this validation entirely) never gets permanently deadlocked —
    // either side can always be unchecked. ProfileSurvey::step4Rules() is the
    // server-side enforcement authority.
    Alpine.data('dependencyCrossGuard', () => ({
        conflictingConditions: ['Overcrowded in home', 'Shared with relatives'],
        get aloneSelected() {
            return (this.$wire.livingWith ?? []).includes('Alone')
        },
        get conflictingConditionSelected() {
            const hc = this.$wire.householdCondition ?? []
            return this.conflictingConditions.some((c) => hc.includes(c))
        },
        aloneDisabled() {
            return !this.aloneSelected && this.conflictingConditionSelected
        },
        conditionDisabled(option) {
            return this.conflictingConditions.includes(option)
                && !(this.$wire.householdCondition ?? []).includes(option)
                && this.aloneSelected
        },
        // "Spouse" can't be a living arrangement when there's no spouse
        // (Single/Widowed) or the spouse is deceased. Same not-already-checked
        // deadlock guard as aloneDisabled()/conditionDisabled() above.
        get spouseLivingBlocked() {
            return ['Single', 'Widowed'].includes(this.$wire.maritalStatus) || this.$wire.spouseWorking === 'Deceased'
        },
        spouseOptionDisabled() {
            return !(this.$wire.livingWith ?? []).includes('Spouse') && this.spouseLivingBlocked
        },
        // Informational note only (the actual clamp lives on Household Size
        // in familyCompositionGuard(), Step 2) — lets Step 4 explain why
        // Household Size will be forced to 1 once "Alone" is checked.
        get aloneRequiresSingleHousehold() {
            return (this.$wire.livingWith ?? []).includes('Alone')
        },
    }))

    // ── Income source cross-field guard (ProfileSurvey Step 5) ───────────────
    // "Spouse salary"/"Spouse pension" require an actual spouse — grayed out
    // and disabled when Marital Status (Step 1) is Single, same
    // not-already-checked deadlock guard as dependencyCrossGuard above so a
    // legacy bulk-imported contradictory record stays editable.
    // ProfileSurvey::spouseIncomeSourceRule() is the server-side enforcement
    // authority.
    Alpine.data('incomeSourceGuard', () => ({
        spouseOptions: ['Spouse salary', 'Spouse pension'],
        get spouseIncomeBlocked() {
            return ['Single', 'Widowed'].includes(this.$wire.maritalStatus)
        },
        incomeOptionDisabled(option) {
            return this.spouseOptions.includes(option)
                && !(this.$wire.incomeSource ?? []).includes(option)
                && this.spouseIncomeBlocked
        },
    }))

    // ── Real-time name field validation (ProfileSurvey: New Profile / Edit) ──
    // Client-side mirror of App\Support\NameRules — the ONE source of truth
    // for the pattern strings, seeded in via @js($this->nameGuardConfig()).
    // Purely for instant inline feedback; ProfileSurvey::step1Rules() (server)
    // independently enforces the exact same pattern and is the actual
    // authority — a request that skips this JS entirely is still rejected.
    // wire:model on these four fields stays deferred (no `.live`): Alpine
    // already gives immediate feedback without a network round trip, so
    // there is no need to pay for one on every keystroke.
    Alpine.data('nameGuard', (cfg) => ({
        errors: {},
        // property => which compiled pattern applies. Suffix (Jr., Sr., II,
        // III, IV, V) intentionally has no apostrophe allowance, unlike the
        // three real name fields — see NameRules' docblock.
        fieldKind: {
            firstName: 'person',
            middleName: 'person',
            lastName: 'person',
            nameExtension: 'suffix',
        },
        re: null,

        init() {
            // Compiled once per form load, not per keystroke.
            this.re = {
                person: new RegExp(cfg.personPattern, 'u'),
                suffix: new RegExp(cfg.suffixPattern, 'u'),
            }
            // Validate whatever the server seeded (Edit loads an existing
            // senior's values) immediately, so a legacy invalid name already
            // in the database surfaces its error on page load rather than
            // only after the user touches the field.
            Object.entries(cfg.values || {}).forEach(([field, value]) => this.check(field, value))
        },

        check(field, value) {
            const kind = this.fieldKind[field]
            if (!kind) return
            const v = (value ?? '').trim()
            // Emptiness is a `required`/`nullable` concern, already handled
            // server-side at Next/Save — don't show a character-format error
            // on a blank optional field (middleName, nameExtension).
            if (v === '') {
                delete this.errors[field]
                return
            }
            if (this.re[kind].test(v)) {
                delete this.errors[field]
            } else {
                this.errors[field] = kind === 'suffix' ? cfg.suffixMessage : cfg.personMessage
            }
        },

        get hasNameError() {
            return Object.keys(this.errors).length > 0
        },
    }))
})


// ── Livewire scroll preservation ─────────────────────────────────────────────
// The layout uses <main class="overflow-y-auto"> as the scroll container.
// Livewire only preserves window scroll — not custom overflow containers.
// Capture scroll before each DOM morph and restore it after, so Livewire
// re-renders don't snap the user back to the top.
document.addEventListener('livewire:before-update', function () {
    const main = document.querySelector('main')
    if (main) window.__livewireMainScroll = main.scrollTop
})

// Orphaned tooltip guard: hoverTip (components/tooltip.blade.php) moves its
// panel to document.body on hover so it isn't clipped by a scrolling
// ancestor. If a Livewire morph removes/recreates the trigger mid-hover
// (e.g. clicking a sortable column header that also carries a tooltip), the
// moved panel loses its only mouseleave listener and is never told to close
// — it just stays stuck open. Force every body-appended tooltip panel closed
// before every commit so this can't happen. Uses Livewire.hook('commit', ...)
// rather than the 'livewire:before-update' DOM event above — that event does
// not fire in this app's Livewire v3 setup (confirmed empirically; see the
// 'morphed' hook used for chart re-renders in dashboard.blade.php /
// cluster-analysis.blade.php for the same reason), so a handler registered
// on it silently never runs.
Livewire.hook('commit', function () {
    document.querySelectorAll('body > [data-tooltip-panel]').forEach(function (panel) {
        panel.style.display = 'none'
    })
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

// ── SPA navigation teardown ───────────────────────────────────────────────────
// wire:navigate morphs the <body> without a full reload. Destroy live Chart.js
// instances and Leaflet maps for the outgoing page so they don't leak or leave
// a "canvas already in use" error when the next page re-inits on the same id.
document.addEventListener('livewire:navigating', function () {
    if (window.Chart && window.Chart.instances) {
        Object.values(window.Chart.instances).forEach((c) => { try { c.destroy() } catch (e) {} })
    }
    if (window.__oscaMaps) {
        window.__oscaMaps.forEach((m) => { try { m.remove() } catch (e) {} })
        window.__oscaMaps = []
    }
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
// Merge onto window.OSCA rather than replacing it — ./ml-health (imported
// above) already attached window.OSCA.mlHealth as a side effect, and a plain
// reassignment here would silently drop it.
window.OSCA = Object.assign(window.OSCA || {}, {
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

    /** Lazy-load Chart.js (memoized). Resolves after window.Chart is set. */
    charts() { return loadCharts() },

    /** Lazy-load Leaflet + plugins (memoized). Resolves after window.L is set. */
    maps() { return loadMaps() },

    /**
     * fetch() a same-origin POST endpoint (with an X-HTTP-METHOD-OVERRIDE
     * header for DELETE-style actions Laravel routes as POST), read its
     * `{ success, redirect }` JSON contract, then hand the trip off to
     * Livewire.navigate() so the @persist'd sidebar/topbar shell survives
     * instead of a full document reload.
     *
     * Shared by seniors/index.blade.php's seniorIndex() and
     * seniors/archives.blade.php's archiveIndex() — both pages' archive /
     * restore / delete actions hit the same response contract (see
     * SeniorCitizenController::stateRedirect()), so this used to be a
     * ~24-line block copy-pasted verbatim between the two Alpine
     * components. Any caller MUST treat the returned boolean as the only
     * source of truth for "did this succeed" — do not clear a selection,
     * close a modal, or show a "done" state off of anything else, since
     * Livewire.navigate() below always runs (success or failure) to resync
     * the list from the server and surface the flashed toast either way.
     *
     * @param {string} url
     * @param {string} csrfToken - `{{ csrf_token() }}`, read server-side per Blade view (kept out of this shared file on purpose).
     * @param {{method?: string, body?: object|null}} [options]
     * @returns {Promise<boolean>} true only if the server actually confirmed success.
     */
    async postAction(url, csrfToken, { method = 'POST', body = null } = {}) {
        const headers = { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }
        if (method !== 'POST') headers['X-HTTP-METHOD-OVERRIDE'] = method
        if (body) headers['Content-Type'] = 'application/json'

        let ok = false
        let target = window.location.href
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers,
                credentials: 'same-origin',
                body: body ? JSON.stringify(body) : null,
            })
            const data = await res.json().catch(() => null)
            ok = res.ok && !!(data && data.success)
            if (data && data.redirect) target = data.redirect
        } catch (e) {
            console.error('postAction request failed', e)
        }
        Livewire.navigate(target)
        return ok
    },

    /**
     * Build a minimal doughnut chart with center-text.
     */
    buildDoughnut(canvasId, labels, data, colors) {
        if (!window.Chart) return null
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
     * Pre-flight gate for every ML-dependent operation (Batch/Individual
     * Analysis, Re-run Assessment, Bulk Upload's ML stage): resolves true
     * only once a FRESH health check confirms the analysis services are up,
     * showing the shared wake-up modal (ml-service-guard.js) and waiting on
     * it if they're not. Resolves false if the user dismisses that modal —
     * callers MUST treat false as "do not start the operation".
     *
     * window.OSCA.mlGate is set up by <x-ml-service-guard /> in
     * layouts/app.blade.php, which is deliberately absent on /ml/status
     * (see that component's own docblock) — a missing gate there means
     * there is nothing to check, so this resolves true rather than wedging
     * navigation on the one page that IS the services diagnostic itself.
     */
    async requireMl() {
        return window.OSCA.mlGate ? window.OSCA.mlGate.require() : true
    },

    /**
     * Build a horizontal bar chart.
     */
    buildHBar(canvasId, labels, data, color = '#14b8a6') {
        if (!window.Chart) return null
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
})

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
