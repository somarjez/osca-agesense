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
document.addEventListener('alpine:init', () => {
    let latestIntent = null
    // Guards against looping forever on a link that legitimately redirects
    // (e.g. an auth bounce): with only one navigation ever in flight, its
    // landed URL differing from what was requested is a real redirect, not
    // staleness — but this module has no way to tell that apart from a race
    // by URL alone. Re-correcting once is enough to fix any genuine race
    // (whichever request actually reflects the last click always lands
    // eventually); a second identical mismatch right after correcting for
    // the exact same intent means we're chasing a redirect, not a race, so
    // we stop rather than loop.
    let lastCorrectedFor = null

    document.addEventListener('alpine:navigate', (e) => {
        if (!e.detail?.url) return
        latestIntent = e.detail.url.pathname + e.detail.url.search
    })

    document.addEventListener('livewire:navigated', () => {
        if (!latestIntent) return // hard/initial load — nothing was clicked
        const landed = window.location.pathname + window.location.search
        if (landed !== latestIntent && latestIntent !== lastCorrectedFor) {
            lastCorrectedFor = latestIntent
            Alpine.navigate(latestIntent)
        }
    })

    // ── Immediate click feedback ───────────────────────────────────────────
    // The sidebar is @persist'd, so a click no longer produces any visible
    // DOM change until the response lands (previously the whole body,
    // including the sidebar, would morph and implicitly show "something is
    // happening"). Give nav links their own instant feedback instead.
    document.addEventListener('click', (e) => {
        // .nav-link is only ever used on the sidebar's own wire:navigate(.hover)
        // links (layouts/app.blade.php) — no need to also check for the
        // attribute, which would need escaping for the .hover-modified ones.
        const link = e.target.closest('.nav-link')
        if (!link) return
        link.classList.add('nav-link-pending')
        link.setAttribute('aria-busy', 'true')
    })
    document.addEventListener('livewire:navigated', () => {
        document.querySelectorAll('.nav-link-pending').forEach((el) => {
            el.classList.remove('nav-link-pending')
            el.removeAttribute('aria-busy')
        })
    })
})
