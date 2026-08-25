import assert from 'node:assert/strict'
import test from 'node:test'

globalThis.document = new EventTarget()

const { bypassWireNavigation, shouldBlockNavigationKeydown, shouldHandleNavigationClick } = await import('../navigation.js')

function link({ target = '', download = false } = {}) {
    return {
        getAttribute: (name) => (name === 'target' ? target : null),
        hasAttribute: (name) => name === 'download' && download,
    }
}

function click(overrides = {}) {
    return {
        button: 0,
        altKey: false,
        ctrlKey: false,
        metaKey: false,
        shiftKey: false,
        ...overrides,
    }
}

test('plain same-tab clicks participate in the navigation lock', () => {
    assert.equal(shouldHandleNavigationClick(click(), link()), true)
})

test('modified, non-primary, new-tab, and download clicks do not lock the current tab', () => {
    assert.equal(shouldHandleNavigationClick(click({ ctrlKey: true }), link()), false)
    assert.equal(shouldHandleNavigationClick(click({ metaKey: true }), link()), false)
    assert.equal(shouldHandleNavigationClick(click({ shiftKey: true }), link()), false)
    assert.equal(shouldHandleNavigationClick(click({ altKey: true }), link()), false)
    assert.equal(shouldHandleNavigationClick(click({ button: 1 }), link()), false)
    assert.equal(shouldHandleNavigationClick(click(), link({ target: '_blank' })), false)
    assert.equal(shouldHandleNavigationClick(click(), link({ download: true })), false)
})

test('Enter is blocked only when another wire navigation is already running', () => {
    assert.equal(shouldBlockNavigationKeydown({ key: 'Enter' }, link(), true), true)
    assert.equal(shouldBlockNavigationKeydown({ key: 'Enter' }, link(), false), false)
    assert.equal(shouldBlockNavigationKeydown({ key: 'Escape' }, link(), true), false)
    assert.equal(shouldBlockNavigationKeydown({ key: 'Enter' }, null, true), false)
})

test('new-tab and download events bypass Livewire while preserving browser defaults', () => {
    for (const specialLink of [link({ target: '_blank' }), link({ download: true })]) {
        for (const event of [{ type: 'mousedown' }, { type: 'click' }, { type: 'keydown', key: 'Enter' }]) {
            let stopped = false
            const handled = bypassWireNavigation({
                ...event,
                stopImmediatePropagation: () => { stopped = true },
            }, specialLink)

            assert.equal(handled, true)
            assert.equal(stopped, true)
        }
    }
})

test('ordinary navigation and unrelated key presses stay with Livewire', () => {
    let stopped = false
    const stopImmediatePropagation = () => { stopped = true }

    assert.equal(bypassWireNavigation({ type: 'click', stopImmediatePropagation }, link()), false)
    assert.equal(bypassWireNavigation({ type: 'keydown', key: 'Escape', stopImmediatePropagation }, link({ target: '_blank' })), false)
    assert.equal(stopped, false)
})
