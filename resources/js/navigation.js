// resources/js/navigation.js
//
// Fixes a real race in Livewire 3.8's wire:navigate: click Dashboard, then
// immediately click Senior Records before Dashboard's response lands, and
// whichever fetch resolves SECOND wins — even if that's Dashboard, the page
// you already navigated away from. Confirmed by reading Livewire's navigate
// plugin source (vendor/livewire/livewire/dist/livewire.esm.js):
// performFetch() (js/plugins/navigate/fetch.js) has no AbortController, and
// navigateTo() applies whichever response arrives, whenever it arrives, with
// no check for whether a newer navigation has since started. Each rapid
// click starts its own independent fetch-then-swap sequence; nothing cancels
// the earlier one.
//
// Aborting the earlier fetch was considered and rejected: Livewire's
// prefetchHtml() (js/plugins/navigate/prefetch.js) registers
// prefetches[uri] = { finished: false, ... } BEFORE the fetch starts, and
// only ever clears it via the fetch's own .then() callback. An aborted
// fetch never runs that callback, so the entry is left permanently
// unfinished — and getPrefetchedHtmlOr() then attaches any future click on
// that same link to a promise that will never resolve, hanging that link
// until a hard reload. So this fixes the *outcome* instead, using only
// Livewire's public Alpine.navigate() API: every wire:navigate/back-forward
// navigation fires a real `alpine:navigate` DOM event before its fetch
// starts (confirmed for all three of Livewire's navigation code paths —
// click-through, popstate, and programmatic Alpine.navigate()), carrying
// the destination URL. We track the most recent one; if a landing doesn't
// match it, a stale response won the race and we re-issue navigation to
// wherever the user actually last clicked.
export function shouldHandleNavigationClick(event, link) {
    const target = (link.getAttribute('target') || '').toLowerCase()

    return (event.button ?? 0) === 0
        && !event.altKey
        && !event.ctrlKey
        && !event.metaKey
        && !event.shiftKey
        && !link.hasAttribute('download')
        && (target === '' || target === '_self')
}

export function shouldBlockNavigationKeydown(event, link, isNavigating) {
    return isNavigating && event.key === 'Enter' && Boolean(link)
}

export function bypassWireNavigation(event, link) {
    const target = (link?.getAttribute('target') || '').trim().toLowerCase()
    const usesBrowserNavigation = Boolean(link)
        && (link.hasAttribute('download') || (target !== '' && target !== '_self'))
    const isActivationEvent = event.type !== 'keydown' || event.key === 'Enter'

    if (!usesBrowserNavigation || !isActivationEvent) return false

    // Livewire 3.8 does not exclude target/download links from wire:navigate.
    // Stop its handlers while leaving the browser's default action intact.
    event.stopImmediatePropagation()
    return true
}

for (const eventName of ['mousedown', 'click', 'keydown']) {
    document.addEventListener(eventName, (event) => {
        const link = event.target.closest?.('[wire\\:navigate]')
        bypassWireNavigation(event, link)
    }, true)
}

document.addEventListener('alpine:init', () => {
    let latestIntent = null

    // ── Stranded-prefetch watchdog ──────────────────────────────────────────
    // Separate bug from the race above, same root file (Livewire's navigate
    // plugin has no AbortController — see the file-level comment). Confirmed
    // by reading js/plugins/navigate/prefetch.js/fetch.js:
    // performFetch() has no .catch(); prefetchHtml() registers
    // prefetches[uri] = { finished: false } BEFORE the fetch starts and only
    // ever flips it to finished via that fetch's own .then(). If a hover- or
    // mousedown-triggered prefetch request fails or is dropped (flaky wifi,
    // dev server hiccup, offline blip), that entry is stranded
    // `finished: false` with no TTL. getPretchedHtmlOr() then attaches every
    // future click on that same link to a `whenFinished` callback that will
    // never run — prefetchHtml() itself no-ops on a second call for a URI
    // that's already in the map (`if (prefetches[uri]) return`), so retrying
    // the click doesn't help either. The link is permanently dead until a
    // hard reload: the progress bar starts and nothing else ever happens,
    // matching the reported "stuck on Dashboard" symptom.
    //
    // Can't fix this from outside Livewire (prefetches is module-private,
    // and aborting the fetch ourselves is what creates a stranded entry in
    // the first place — see the file-level comment). Fix the outcome
    // instead: alpine:navigate fires synchronously on click release, before
    // the (possibly hung) fetch — if no livewire:navigated follows within a
    // generous window, force a real page load to the intended URL. A full
    // load bypasses Livewire's in-memory prefetch cache entirely, so it
    // can't hang the same way twice.
    let watchdogTimer = null
    const WATCHDOG_MS = 10000 // comfortably longer than a slow/cold-start render

    document.addEventListener('alpine:navigate', () => {
        document.body.classList.add('is-navigating')
        clearTimeout(watchdogTimer)
        watchdogTimer = setTimeout(() => {
            const landed = window.location.pathname + window.location.search
            if (landed !== latestIntent) window.location.assign(latestIntent)
        }, WATCHDOG_MS)
    })
    document.addEventListener('livewire:navigated', () => clearTimeout(watchdogTimer))

    // Guards against looping forever on a link that legitimately redirects
    // (e.g. an auth bounce): with only one navigation ever in flight, its
    // landed URL differing from what was requested is a real redirect, not
    // staleness — but this module has no way to tell that apart from a race
    // by URL alone. Re-correcting once is enough to fix any genuine race
    // (whichever request actually reflects the last click always lands
    // eventually); a second identical mismatch right after correcting for
    // the exact same intent means we're chasing a redirect, not a race, so
    // we stop rather than loop for that intent.
    //
    // This latch must be RESET once we land somewhere correctly (below) —
    // originally it was set-once-and-never-cleared, so it only protected the
    // very first race of the session; every race after that (e.g. click
    // Dashboard then Senior Records a second time) silently went uncorrected
    // and the user was left stuck on the stale page.
    let lastCorrectedFor = null

    document.addEventListener('alpine:navigate', (e) => {
        if (!e.detail?.url) return
        latestIntent = e.detail.url.pathname + e.detail.url.search
    })

    document.addEventListener('livewire:navigated', () => {
        if (!latestIntent) return // hard/initial load — nothing was clicked
        const landed = window.location.pathname + window.location.search
        if (landed === latestIntent) {
            lastCorrectedFor = null // arrived cleanly — re-arm the guard for the next race
            return
        }
        if (latestIntent !== lastCorrectedFor) {
            lastCorrectedFor = latestIntent
            Alpine.navigate(latestIntent)
        }
    })

    // ── Immediate click feedback + navigation lock ─────────────────────────
    // The sidebar is @persist'd, so a click no longer produces any visible
    // DOM change until the response lands (previously the whole body,
    // including the sidebar, would morph and implicitly show "something is
    // happening"). Give every wire:navigate link instant feedback instead,
    // and disable ALL of them for the duration of the transition — without
    // this, a second click (same link, a different sidebar item, or a
    // different senior's row) starts an overlapping navigation that the
    // race-correction logic above has to clean up after the fact. Locking
    // up front avoids that entirely. The attribute selector (not a
    // per-page CSS class) means this applies everywhere wire:navigate is
    // used — sidebar, senior-record rows/actions, pagination — with no
    // per-template opt-in required.
    document.addEventListener('click', (e) => {
        const link = e.target.closest('[wire\\:navigate]')
        if (!link || !shouldHandleNavigationClick(e, link)) return
        link.classList.add('wire-nav-pending')
        link.setAttribute('aria-busy', 'true')

        // Same data-loading convention as the global non-Livewire form
        // submit-guard below, extended to links: opt in per-link with
        // data-loading="Label" to swap its content for a spinner while the
        // navigation is in flight (e.g. "Clear" filter links, "View →" table
        // row links) instead of relying on the opacity dim alone.
        if (link.dataset.loading) {
            link.dataset.origHtml = link.innerHTML
            link.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span> ' + link.dataset.loading
        }
    })
    document.addEventListener('keydown', (e) => {
        const link = e.target.closest?.('[wire\\:navigate]')
        if (!shouldBlockNavigationKeydown(e, link, document.body.classList.contains('is-navigating'))) return

        e.preventDefault()
        e.stopImmediatePropagation()
    }, true)
    document.addEventListener('livewire:navigated', () => {
        document.querySelectorAll('.wire-nav-pending').forEach((el) => {
            el.classList.remove('wire-nav-pending')
            el.removeAttribute('aria-busy')
            if (el.dataset.origHtml !== undefined) {
                el.innerHTML = el.dataset.origHtml
                delete el.dataset.origHtml
            }
        })
        document.body.classList.remove('is-navigating')
    })
})
