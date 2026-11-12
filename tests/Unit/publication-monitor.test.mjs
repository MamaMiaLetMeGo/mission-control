import test from 'node:test';
import assert from 'node:assert/strict';
import { PublicationPoller, retryDelay, samePublicationIdentity } from '../../resources/js/publication-monitor.js';

const identity = { revision: 'reviewed-task', plan_hash: 'reviewed-plan' };
const active = { published: true, should_poll: true, poll_after_ms: 15000, error: null };
const complete = { ...active, should_poll: false };
const absent = { published: false, should_poll: false };

function response(overrides = {}) {
    return { ...identity, panels: { staging: '<section></section>', production: null }, polling: { staging: active, production: absent }, busy: false, ...overrides };
}

function harness({ metadata = { staging: active, production: absent }, request = async () => response(), hidden = () => false } = {}) {
    let now = 100000;
    let sequence = 0;
    const timers = new Map();
    const applied = [];
    const messages = [];
    const clock = { now: () => now, set: (fn, ms) => { const id = ++sequence; timers.set(id, { fn, at: now + ms }); return id; }, clear: id => timers.delete(id) };
    const poller = new PublicationPoller({ identity, metadata, request, apply: panels => applied.push(panels), status: (environment, text) => messages.push({ environment, text }), hidden, clock });
    return { poller, timers, applied, messages, advance: ms => { now += ms; } };
}

test('a response must match both the task revision and the displayed plan', () => {
    assert.equal(samePublicationIdentity(identity, response()), true);
    assert.equal(samePublicationIdentity(identity, response({ revision: 'new-task' })), false);
    assert.equal(samePublicationIdentity(identity, response({ plan_hash: 'new-plan' })), false);
    assert.equal(samePublicationIdentity({ revision: 'task', plan_hash: null }, { revision: 'task', plan_hash: null }), true);
});

test('busy snapshots are not rendered and do not consume the initial terminal check', async () => {
    const h = harness({ metadata: { staging: complete, production: absent }, request: async () => response({ polling: { staging: complete, production: absent }, busy: true }) });
    await h.poller.poll('staging');
    assert.equal(h.applied.length, 0);
    assert.equal(h.poller.records.staging.checked, false);
    assert.equal([...h.timers.values()][0].at, 115000);
});

test('a published terminal record is checked once, then stops scheduling', async () => {
    const h = harness({ metadata: { staging: complete, production: absent }, request: async () => response({ polling: { staging: complete, production: absent } }) });
    h.poller.resume();
    assert.equal(h.timers.size, 1);
    h.timers.clear();
    await h.poller.poll('staging');
    assert.equal(h.applied.length, 1);
    assert.equal(h.poller.records.staging.checked, true);
    assert.equal(h.timers.size, 0);
});

test('no second request overlaps a pending request, and manual pause ignores its late response', async () => {
    let finish;
    let signal;
    let requests = 0;
    const h = harness({ request: (_, controllerSignal) => { requests += 1; signal = controllerSignal; return new Promise(resolve => { finish = resolve; }); } });
    const pending = h.poller.poll('staging');
    await h.poller.poll('staging');
    assert.equal(requests, 1);
    h.poller.pause();
    assert.equal(signal.aborted, true);
    finish(response());
    await pending;
    assert.equal(h.applied.length, 0);
    assert.equal(h.timers.size, 0);
});

test('hidden tabs do not schedule or send a request, and resume checks when visible', async () => {
    let hidden = true;
    let requests = 0;
    const h = harness({ hidden: () => hidden, request: async () => { requests += 1; return response(); } });
    h.poller.resume();
    await h.poller.poll('staging');
    assert.equal(requests, 0);
    assert.equal(h.timers.size, 0);
    hidden = false;
    h.poller.resume();
    assert.equal(h.timers.size, 1);
});

test('changed response pins stop updates before any fragment is applied', async () => {
    const h = harness({ request: async () => response({ plan_hash: 'changed-plan' }) });
    await h.poller.poll('staging');
    assert.equal(h.poller.stopped, true);
    assert.equal(h.applied.length, 0);
    assert.equal(h.timers.size, 0);
    assert.match(h.messages.at(-1).text, /Reload the page/);
});

test('HTTP identity and session failures stop, while transient failures back off and honor Retry-After', async () => {
    for (const status of [403, 409, 419, 422]) {
        const h = harness({ request: async () => { throw Object.assign(new Error('Unavailable'), { status }); } });
        await h.poller.poll('staging');
        assert.equal(h.poller.stopped, true);
        assert.equal(h.applied.length, 0);
    }
    const h = harness({ request: async () => { throw Object.assign(new Error('Rate limited'), { status: 429, retryAfter: '90' }); } });
    await h.poller.poll('staging');
    assert.equal(h.poller.stopped, false);
    assert.equal(h.poller.records.staging.due, 190000);
    assert.equal(retryDelay(1), 30000);
    assert.equal(retryDelay(3), 120000);
    assert.equal(retryDelay(9), 300000);
    assert.equal(retryDelay(1, new Date(220000).toUTCString(), 100000), 120000);
});

test('passive metadata never labels another environment complete before its first check', async () => {
    const h = harness({ metadata: { staging: active, production: complete }, request: async () => response({ polling: { staging: complete, production: complete } }) });
    await h.poller.poll('staging');
    assert.equal(h.poller.records.production.checked, false);
    assert.match(h.messages.findLast(message => message.environment === 'production').text, /initial status check is pending/);
    assert.equal(h.timers.size, 1);
});

test('deployment failures remain eligible for the server-directed slower retry cadence', async () => {
    const h = harness({ request: async () => response({ polling: { staging: { ...active, poll_after_ms: 60000 }, production: absent } }) });
    await h.poller.poll('staging');
    assert.equal(h.poller.records.staging.due, 160000);
    assert.equal(h.timers.size, 1);
});
