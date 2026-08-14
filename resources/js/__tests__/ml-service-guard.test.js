import assert from 'node:assert/strict'
import test from 'node:test'

class BrowserCustomEvent extends Event {
    constructor(type, options = {}) {
        super(type)
        this.detail = options.detail
    }
}

globalThis.CustomEvent = BrowserCustomEvent
globalThis.document = new EventTarget()
globalThis.window = new EventTarget()

let guardFactory
globalThis.Alpine = {
    data(name, factory) {
        if (name === 'mlServiceGuard') guardFactory = factory
    },
}

await import('../ml-service-guard.js')
document.dispatchEvent(new Event('alpine:init'))

function makeGuard(health) {
    window.OSCA = { mlHealth: health }
    const guard = {
        ...guardFactory({
            preprocessUrl: 'https://preprocess.test',
            inferenceUrl: 'https://inference.test',
            wakeUrl: '/ml/wake',
            wakeStatusUrl: '/ml/wake-status',
            csrfToken: 'token',
        }),
        $watch() {},
        $refs: {},
    }
    guard.init()
    return guard
}

test('fresh health events dispatched on window open the guard when a service is down', () => {
    const guard = makeGuard({
        dot: 'checking',
        title: 'Checking services',
        services: {},
        checkNow: () => new Promise(() => {}),
    })

    window.dispatchEvent(new CustomEvent('osca:ml-health', {
        detail: {
            dot: 'err',
            title: 'Analysis services unavailable',
            services: { preprocessor: 'unreachable', inference: 'ok' },
        },
    }))

    assert.equal(guard.modalOpen, true)
    assert.deepEqual(guard.services, { preprocessor: 'unreachable', inference: 'ok' })
    guard.destroy()
})

test('cached down state does not open the modal before the fresh login check completes', () => {
    const guard = makeGuard({
        dot: 'err',
        title: 'Previously unavailable',
        services: { preprocessor: 'unreachable', inference: 'unreachable' },
        checkNow: () => new Promise(() => {}),
    })

    assert.equal(guard.modalOpen, false)
    assert.equal(guard.dot, 'checking')
    guard.destroy()
})

test('fresh ready state after login does not open the modal', () => {
    const guard = makeGuard({
        dot: 'checking',
        title: 'Checking services',
        services: {},
        checkNow: () => new Promise(() => {}),
    })

    window.dispatchEvent(new CustomEvent('osca:ml-health', {
        detail: {
            dot: 'ok',
            title: 'HTTP services online',
            services: { preprocessor: 'ok', inference: 'ok' },
        },
    }))

    assert.equal(guard.modalOpen, false)
    assert.equal(guard.dot, 'ok')
    guard.destroy()
})

test('fresh warming state after login opens the modal without reporting ready', () => {
    const guard = makeGuard({
        dot: 'checking',
        title: 'Checking services',
        services: {},
        checkNow: () => new Promise(() => {}),
    })

    window.dispatchEvent(new CustomEvent('osca:ml-health', {
        detail: {
            dot: 'err',
            title: 'Analysis services are warming up',
            services: { preprocessor: 'warming', inference: 'ok' },
        },
    }))

    assert.equal(guard.modalOpen, true)
    assert.equal(guard.serviceLabel(guard.services.preprocessor), 'Warming Up')
    guard.destroy()
})

test('repeated wake clicks start only one wake request', () => {
    const requestedUrls = []
    globalThis.fetch = (url) => {
        requestedUrls.push(url)
        return url === '/ml/wake' ? new Promise(() => {}) : Promise.resolve({})
    }
    const guard = makeGuard({
        dot: 'err',
        title: 'Unavailable',
        services: { preprocessor: 'unreachable', inference: 'unreachable' },
        checkNow: () => new Promise(() => {}),
    })

    guard.startWake()
    guard.startWake()

    assert.equal(requestedUrls.filter((url) => url === '/ml/wake').length, 1)
    guard.destroy()
})

test('readiness polling retries the bounded wake attempt while services are unavailable', () => {
    const guard = makeGuard({
        dot: 'err',
        title: 'Unavailable',
        services: { preprocessor: 'warming', inference: 'warming' },
        checkNow: () => new Promise(() => {}),
    })
    let statusChecks = 0
    let wakeRequests = 0
    guard.waking = true
    guard.checkStatus = () => { statusChecks++ }
    guard.wakeLoop = () => { wakeRequests++ }

    const originalSetInterval = globalThis.setInterval
    globalThis.setInterval = (callback) => {
        callback()
        return 1
    }
    try {
        guard.pollWake()
    } finally {
        globalThis.setInterval = originalSetInterval
        guard.destroy()
    }

    assert.equal(statusChecks, 1)
    assert.equal(wakeRequests, 1)
})

test('overlapping status polls count one shared response only once', async () => {
    let resolveCheck
    const pending = new Promise((resolve) => { resolveCheck = resolve })
    const guard = makeGuard({
        dot: 'err',
        title: 'Unavailable',
        services: { preprocessor: 'warming', inference: 'warming' },
        checkNow: () => pending,
    })
    let positiveSignals = 0
    guard.waking = true
    guard.wakeGeneration = 1
    guard._noteWakeSignal = (positive) => { if (positive) positiveSignals++ }

    guard.checkStatus()
    guard.checkStatus()
    resolveCheck(true)
    await pending
    await new Promise((resolve) => setTimeout(resolve, 0))

    assert.equal(positiveSignals, 1)
    assert.equal(guard.statusCheckInFlight, false)
    guard.destroy()
})

test('an operation waits for corroboration after a known outage', async () => {
    let resolveCheck
    const guard = makeGuard({
        dot: 'err',
        title: 'Unavailable',
        services: { preprocessor: 'unreachable', inference: 'unreachable' },
        checkNow: () => new Promise((resolve) => { resolveCheck = resolve }),
    })
    guard.dot = 'err'
    guard._rawDot = 'err'

    let settled = false
    const required = guard.require().then((ready) => {
        settled = true
        return ready
    })
    window.dispatchEvent(new CustomEvent('osca:ml-health', {
        detail: {
            dot: 'ok',
            title: 'HTTP services online',
            services: { preprocessor: 'ok', inference: 'ok' },
        },
    }))
    resolveCheck(true)
    await Promise.resolve()
    await Promise.resolve()

    assert.equal(settled, false)
    guard._applyHealth('ok', 'HTTP services online', { preprocessor: 'ok', inference: 'ok' })
    assert.equal(await required, true)
    guard.destroy()
})
