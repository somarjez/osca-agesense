import assert from 'node:assert/strict'
import test from 'node:test'

class BrowserCustomEvent extends Event {
    constructor(type, options = {}) {
        super(type)
        this.detail = options.detail
    }
}

function deferred() {
    let resolve
    const promise = new Promise((done) => { resolve = done })
    return { promise, resolve }
}

globalThis.CustomEvent = BrowserCustomEvent
globalThis.document = Object.assign(new EventTarget(), { hidden: false })
globalThis.window = new EventTarget()
globalThis.sessionStorage = {
    getItem: () => null,
    setItem: () => {},
}

const cachedRequest = deferred()
const firstFreshRequest = deferred()
const secondFreshRequest = deferred()
const freshRequests = [firstFreshRequest, secondFreshRequest]
const requestedUrls = []
globalThis.fetch = (url) => {
    requestedUrls.push(url)
    return url === '/ml/nav-health' ? cachedRequest.promise : freshRequests.shift().promise
}

const { default: health } = await import('../ml-health.js')

test('a stale cached response cannot overwrite a newer fresh ready response', async () => {
    const firstCheck = health._checkNowPromise
    const duplicateCheck = health.checkNow()

    assert.equal(firstCheck, duplicateCheck)
    assert.equal(requestedUrls.filter((url) => url === '/ml/wake-status').length, 1)

    firstFreshRequest.resolve({
        ok: true,
        json: async () => ({
            mode: 'http',
            preprocessor: 'ok',
            inference: 'ok',
            local_runner: 'unavailable',
        }),
    })
    assert.equal(await firstCheck, true)
    assert.equal(health.dot, 'ok')

    const staleRefresh = health.refresh()
    const newerCheck = health.checkNow()
    secondFreshRequest.resolve({
        ok: true,
        json: async () => ({
            mode: 'http',
            preprocessor: 'ok',
            inference: 'ok',
            local_runner: 'unavailable',
        }),
    })
    assert.equal(await newerCheck, true)

    cachedRequest.resolve({
        ok: true,
        json: async () => ({
            dot: 'err',
            title: 'Stale offline result',
            services: { preprocessor: 'unreachable', inference: 'unreachable' },
        }),
    })
    await staleRefresh

    assert.equal(health.dot, 'ok')
    assert.deepEqual(health.services, { preprocessor: 'ok', inference: 'ok' })
    clearTimeout(health._timer)
})
