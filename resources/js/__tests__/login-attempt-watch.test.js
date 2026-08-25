import assert from 'node:assert/strict'
import test from 'node:test'

globalThis.document = Object.assign(new EventTarget(), { hidden: false })
globalThis.window = new EventTarget()
globalThis.CustomEvent = class CustomEvent extends Event {
    constructor(type, options = {}) {
        super(type)
        this.detail = options.detail
    }
}

let requestCount = 0
globalThis.fetch = async () => {
    requestCount++

    return {
        ok: true,
        json: async () => ({ attempt: { ip: '127.0.0.1' } }),
    }
}
globalThis.setInterval = () => 1

let deliveredAttempt = null
window.addEventListener('osca:login-attempt', (event) => {
    deliveredAttempt = event.detail
})

await import('../login-attempt-watch.js')
await new Promise((resolve) => setImmediate(resolve))

test('the first check waits until Livewire listeners are initialized', async () => {
    assert.equal(requestCount, 0)

    document.dispatchEvent(new Event('livewire:initialized'))
    await new Promise((resolve) => setImmediate(resolve))

    assert.equal(requestCount, 1)
    assert.deepEqual(deliveredAttempt, { ip: '127.0.0.1' })
})
