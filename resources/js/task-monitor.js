import { retryDelay } from './publication-monitor.js';

export function taskSnapshotMatches(expected, snapshot) {
    const active = ['queued', 'running'].includes(snapshot?.status);
    return String(snapshot?.run_id) === String(expected.run_id)
        && ['queued', 'running'].includes(expected.status)
        && ['queued', 'running', 'review', 'failed'].includes(snapshot?.status)
        && snapshot.active === active
        && !(expected.status === 'running' && snapshot.status === 'queued')
        && (!active || !snapshot.revision)
        && (!snapshot.revision || /^[a-f0-9]{40,64}$/.test(snapshot.revision))
        && (snapshot.status !== 'review' || /^[a-f0-9]{40,64}$/.test(snapshot.revision || ''))
        && (!expected.revision || expected.revision === snapshot.revision);
}

export class ReadOnlyPoller {
    constructor({ request, apply, keepPolling, status, describe, hidden = () => document.hidden, clock = { now: () => Date.now(), set: (fn, delay) => setTimeout(fn, delay), clear: id => clearTimeout(id) } }) {
        Object.assign(this, { request, apply, keepPolling, status, describe, hidden, clock });
        this.due = clock.now() + 1000;
        this.generation = 0;
        this.failures = 0;
        this.paused = false;
        this.stopped = false;
        this.inFlight = false;
    }
    pause() { this.paused = true; this.generation += 1; this.clock.clear(this.timer); this.controller?.abort(); }
    resume() { this.paused = false; this.schedule(); }
    stop(message) { this.stopped = true; this.pause(); if (message) this.status(message); }
    schedule() {
        this.clock.clear(this.timer);
        if (!this.paused && !this.stopped && !this.inFlight && !this.hidden()) this.timer = this.clock.set(() => this.poll(), Math.max(0, this.due - this.clock.now()));
    }
    async poll() {
        if (this.paused || this.stopped || this.inFlight || this.hidden()) return;
        this.inFlight = true;
        const generation = this.generation;
        const controller = new AbortController();
        this.controller = controller;
        const timeout = this.clock.set(() => controller.abort(), 15000);
        try {
            const result = await this.request(controller.signal);
            if (generation !== this.generation || this.paused || this.stopped || this.hidden()) return;
            this.apply(result);
            this.failures = 0;
            this.status(this.describe(result));
            if (!this.keepPolling(result)) { this.stop(); return; }
            this.due = this.clock.now() + Math.max(5000, Math.min(60000, Number(result.poll_after_ms) || (result.active ? 5000 : 15000)));
        } catch (error) {
            if (generation !== this.generation || this.paused || this.stopped || this.hidden()) return;
            if (error.reload || [403, 409, 419, 422].includes(error.status)) {
                this.stop(error.reload ? error.message : 'Updates paused because this task or session changed. Reload to see the current state.');
                return;
            }
            const delay = retryDelay(++this.failures, error.retryAfter, this.clock.now());
            this.due = this.clock.now() + delay;
            this.status(`Updates are temporarily unavailable. Retrying in ${Math.ceil(delay / 1000)} seconds.`);
        } finally {
            this.clock.clear(timeout);
            this.inFlight = false;
            if (this.controller === controller) this.controller = null;
            this.schedule();
        }
    }
}

function needsReload(message) { throw Object.assign(new Error(message), { reload: true }); }

function hasEditedControls(root) {
    return Array.from(root.querySelectorAll('input:not([type="hidden"]),textarea,select')).some(input => {
        if (input === document.activeElement) return true;
        if (input instanceof HTMLInputElement && ['radio', 'checkbox'].includes(input.type)) return input.checked !== input.defaultChecked;
        if (input instanceof HTMLSelectElement) {
            const original = Array.from(input.options).filter(option => option.defaultSelected).map(option => option.value);
            return input.value !== (original[0] ?? input.options[0]?.value);
        }
        return input.value !== input.defaultValue;
    });
}

function preserveView(change) {
    const focused = document.activeElement;
    const point = document.elementFromPoint(window.innerWidth / 2, Math.min(80, window.innerHeight - 1));
    const anchor = focused?.matches('input,textarea,select') ? focused : point?.closest('[data-task-detail],[data-task-live],[id]') || point;
    const top = anchor?.getBoundingClientRect().top;
    const key = anchor?.dataset.taskDetail ? `[data-task-detail="${CSS.escape(anchor.dataset.taskDetail)}"]` : anchor?.dataset.taskLive ? `[data-task-live="${CSS.escape(anchor.dataset.taskLive)}"]` : anchor?.id ? `#${CSS.escape(anchor.id)}` : null;
    const x = window.scrollX, y = window.scrollY;
    change();
    const after = anchor?.isConnected ? anchor : key ? document.querySelector(key) : null;
    window.scrollTo({ left: x, top: y + (after && Number.isFinite(top) ? after.getBoundingClientRect().top - top : 0), behavior: 'instant' });
}

function transferViewState(current, next) {
    const detailKey = detail => detail.dataset.taskDetail || detail.id || detail.querySelector('summary')?.textContent.trim();
    const details = new Map(Array.from(current.querySelectorAll('details'), detail => [detailKey(detail), detail.open]));
    for (const detail of next.querySelectorAll('details')) if (details.has(detailKey(detail))) detail.open = details.get(detailKey(detail));
    const logs = new Map(Array.from(current.querySelectorAll('[data-task-log]'), log => [log.dataset.taskLog, { top: log.scrollTop, follow: log.scrollHeight - log.clientHeight - log.scrollTop < 3 }]));
    const focused = current.contains(document.activeElement) ? document.activeElement : null;
    const focusedDetail = focused?.closest('[data-task-detail]')?.dataset.taskDetail;
    const focusedLink = focused?.closest('a[href]')?.getAttribute('href');
    return () => {
        for (const log of next.querySelectorAll('[data-task-log]')) {
            const saved = logs.get(log.dataset.taskLog);
            if (saved) log.scrollTop = saved.follow ? log.scrollHeight : saved.top;
        }
        if (focusedLink !== null && focusedLink !== undefined) {
            Array.from(next.querySelectorAll('a[href]')).find(link => link.getAttribute('href') === focusedLink)?.focus({ preventScroll: true });
        } else if (focusedDetail) next.querySelector(`[data-task-detail="${CSS.escape(focusedDetail)}"] summary`)?.focus({ preventScroll: true });
    };
}

function setStatus(node, text) { if (node && node.textContent !== text) node.textContent = text; }
function setChecked(node, value) {
    const date = new Date(value);
    if (!node || Number.isNaN(date.getTime())) return;
    node.dateTime = date.toISOString();
    node.textContent = `Last checked ${date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}`;
}
async function getSnapshot(url, signal) {
    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', mode: 'same-origin', cache: 'no-store', signal });
    if (!response.ok) throw Object.assign(new Error('Snapshot unavailable'), { status: response.status, retryAfter: response.headers.get('Retry-After') });
    return response.json();
}

export function mountTaskAndQueueMonitors({ initialize = () => {}, dispose = () => {} } = {}) {
    const pollers = [];
    const queue = document.querySelector('[data-queue-monitor]');
    if (queue) {
        const url = new URL(queue.dataset.statusUrl, window.location.href);
        const submitted = new URLSearchParams(window.location.search);
        for (const key of ['q', 'status', 'area', 'page']) if (submitted.has(key)) url.searchParams.set(key, submitted.get(key));
        const poller = new ReadOnlyPoller({
            request: signal => getSnapshot(url, signal), keepPolling: () => true,
            status: text => setStatus(queue.querySelector('[data-queue-update-status]'), text),
            describe: result => `${Number(result.counts.review || 0)} ready for review · ${Number(result.counts.running || 0) + Number(result.counts.queued || 0)} active. Queue updates automatically.`,
            apply: result => {
                if (typeof result.html?.stats !== 'string' || typeof result.html?.queue !== 'string' || !result.counts || typeof result.active !== 'boolean') throw new Error('Incomplete queue response');
                preserveView(() => {
                    for (const [key, selector] of [['stats', '[data-queue-stats]'], ['queue', '[data-queue-list]']]) {
                        const current = queue.querySelector(selector);
                        const focusLink = current?.contains(document.activeElement) ? document.activeElement?.closest('a')?.getAttribute('href') : null;
                        const parsed = new DOMParser().parseFromString(result.html[key], 'text/html');
                        current?.replaceChildren(...Array.from(parsed.body.childNodes));
                        if (focusLink) Array.from(current.querySelectorAll('a')).find(link => link.getAttribute('href') === focusLink)?.focus({ preventScroll: true });
                    }
                    setStatus(queue.querySelector('[data-queue-total]'), String(Object.values(result.counts).reduce((sum, count) => sum + Number(count || 0), 0)));
                    const badge = document.querySelector('[data-review-count]');
                    if (badge) { badge.hidden = !Number(result.counts.review); setStatus(badge, String(result.counts.review || 0)); }
                });
                setChecked(queue.querySelector('[data-queue-checked]'), result.checked_at);
            },
        });
        pollers.push(poller);
    }
    const task = document.querySelector('[data-task-monitor]');
    if (task) {
        const expected = { run_id: task.dataset.runId, revision: task.dataset.revision || null, status: task.querySelector('[data-task-content]')?.dataset.taskStatus };
        const url = new URL(task.dataset.statusUrl, window.location.href);
        url.searchParams.set('run_id', expected.run_id);
        let phase = 'Your team is working. Progress updates automatically.';
        const poller = new ReadOnlyPoller({
            request: signal => getSnapshot(url, signal), keepPolling: result => result.active,
            status: text => setStatus(task.querySelector('[data-task-update-status]'), text),
            describe: result => result.active ? phase : result.status === 'review' ? 'Run complete. Ready for your review.' : 'Run finished and needs attention.',
            apply: result => {
                if (!taskSnapshotMatches(expected, result) || typeof result.html !== 'string') needsReload('Updates paused because this run changed. Reload to review the current task.');
                const parsed = new DOMParser().parseFromString(result.html, 'text/html');
                const next = parsed.querySelector('[data-task-content]');
                const current = task.querySelector('[data-task-content]');
                if (!current || !next || next.dataset.runId !== String(result.run_id) || next.dataset.taskStatus !== result.status || (next.dataset.revision || null) !== (result.revision || null)) needsReload('The updated review could not be verified. Reload before reviewing it.');
                if (hasEditedControls(current)) needsReload('New task information is available. Your edits are preserved; reload when you are ready to review it.');
                preserveView(() => {
                    if (result.active) {
                        for (const name of ['heading', 'phase', 'runs', 'next-step']) {
                            const oldRegion = current.querySelector(`[data-task-live="${name}"]`);
                            const newRegion = next.querySelector(`[data-task-live="${name}"]`);
                            if (oldRegion && newRegion) { const restore = transferViewState(oldRegion, newRegion); oldRegion.replaceWith(newRegion); restore(); }
                        }
                    } else {
                        const restore = transferViewState(current, next);
                        dispose(current);
                        current.replaceWith(next);
                        restore();
                        initialize(next);
                    }
                });
                expected.status = result.status;
                expected.revision = result.revision || null;
                phase = task.querySelector('[data-task-live="phase"]')?.textContent.trim() || phase;
                setChecked(task.querySelector('[data-task-checked]'), result.checked_at);
            },
        });
        pollers.push(poller);
    }
    let navigating = false;
    document.addEventListener('submit', event => { if (!event.defaultPrevented) { navigating = true; for (const poller of pollers) poller.pause(); } });
    document.addEventListener('visibilitychange', () => { for (const poller of pollers) document.hidden || navigating ? poller.pause() : poller.resume(); });
    window.addEventListener('pagehide', () => { for (const poller of pollers) poller.pause(); });
    window.addEventListener('pageshow', () => { navigating = false; for (const poller of pollers) poller.resume(); });
    for (const poller of pollers) poller.resume();
    return pollers;
}
