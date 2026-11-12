import test from 'node:test';
import assert from 'node:assert/strict';
import { ReadOnlyPoller, taskSnapshotMatches } from '../../resources/js/task-monitor.js';

const sha = 'a'.repeat(40);
const run = { run_id: 42, status: 'running', revision: null };

function harness({ request = async () => ({ active: true, poll_after_ms: 5000 }), keepPolling = result => result.active, apply, hidden = () => false } = {}) {
    const timers = new Map(), applied = [], statuses = [];
    let sequence = 0;
    const clock = { now: () => 100000, set: (fn, delay) => { const id = ++sequence; timers.set(id, { fn, delay }); return id; }, clear: id => timers.delete(id) };
    const poller = new ReadOnlyPoller({ request, keepPolling, apply: apply || (result => applied.push(result)), status: text => statuses.push(text), describe: result => result.active ? 'Working' : 'Complete', hidden, clock });
    return { poller, timers, applied, statuses };
}

test('the same active run may become a verified review or failed run', () => {
    assert.equal(taskSnapshotMatches(run, { ...run, active: true }), true);
    assert.equal(taskSnapshotMatches(run, { ...run, status: 'review', revision: sha, active: false }), true);
    assert.equal(taskSnapshotMatches(run, { ...run, status: 'failed', active: false }), true);
    assert.equal(taskSnapshotMatches({ ...run, status: 'queued' }, { ...run, active: true }), true);
});

test('new run IDs, backward phases, unexpected final states, and malformed revisions cannot replace a review', () => {
    for (const snapshot of [
        { ...run, run_id: 43, active: true },
        { ...run, status: 'queued', active: true },
        { ...run, status: 'merged', revision: sha, active: false },
        { ...run, status: 'review', revision: null, active: false },
        { ...run, status: 'failed', revision: 'malformed', active: false },
        { ...run, revision: sha, active: true },
        { ...run, active: false },
    ]) assert.equal(taskSnapshotMatches(run, snapshot), false);
    assert.equal(taskSnapshotMatches({ ...run, status: 'review', revision: sha }, { ...run, active: true }), false);
});

test('task completion is applied once, announced, and stops the task timer', async () => {
    const h = harness({ request: async () => ({ status: 'review', active: false, revision: sha }) });
    await h.poller.poll();
    assert.equal(h.applied.length, 1);
    assert.equal(h.poller.stopped, true);
    assert.equal(h.timers.size, 0);
    assert.equal(h.statuses.at(-1), 'Complete');
    await h.poller.poll();
    assert.equal(h.applied.length, 1);
});

test('idle queue polling continues and uses the server cadence before switching to active cadence', async () => {
    let active = false;
    const h = harness({ keepPolling: () => true, request: async () => ({ active, poll_after_ms: active ? 5000 : 15000 }) });
    await h.poller.poll();
    assert.equal(h.poller.stopped, false);
    assert.equal(h.poller.due, 115000);
    active = true;
    await h.poller.poll();
    assert.equal(h.poller.due, 105000);
});

test('manual navigation cancels a pending response and no request can overlap it', async () => {
    let resolve, signal, requests = 0;
    const h = harness({ request: controllerSignal => { signal = controllerSignal; requests += 1; return new Promise(done => { resolve = done; }); } });
    const pending = h.poller.poll();
    await h.poller.poll();
    assert.equal(requests, 1);
    h.poller.pause();
    assert.equal(signal.aborted, true);
    resolve({ active: false, revision: sha });
    await pending;
    assert.equal(h.applied.length, 0);
    assert.equal(h.timers.size, 0);
});

test('hidden pages wait until visible before scheduling or fetching', async () => {
    let hidden = true, calls = 0;
    const h = harness({ hidden: () => hidden, request: async () => { calls += 1; return { active: true }; } });
    h.poller.resume();
    await h.poller.poll();
    assert.equal(calls, 0);
    assert.equal(h.timers.size, 0);
    hidden = false;
    h.poller.resume();
    assert.equal(h.timers.size, 1);
});

test('stale sessions and stale runs stop while recoverable failures back off', async () => {
    for (const status of [403, 409, 419, 422]) {
        const h = harness({ request: async () => { throw Object.assign(new Error('Stale'), { status }); } });
        await h.poller.poll();
        assert.equal(h.poller.stopped, true);
        assert.equal(h.applied.length, 0);
    }
    const h = harness({ request: async () => { throw Object.assign(new Error('Busy'), { status: 503, retryAfter: '60' }); } });
    await h.poller.poll();
    assert.equal(h.poller.stopped, false);
    assert.equal(h.poller.due, 160000);
});

test('a refused DOM update preserves the existing review and stops retries', async () => {
    const h = harness({ apply: () => { throw Object.assign(new Error('Your edits are preserved; reload when ready.'), { reload: true }); } });
    await h.poller.poll();
    assert.equal(h.poller.stopped, true);
    assert.match(h.statuses.at(-1), /edits are preserved/);
    assert.equal(h.timers.size, 0);
});
