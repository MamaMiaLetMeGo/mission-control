const environments = ['staging', 'production'];

export function samePublicationIdentity(expected, actual) {
    return actual?.revision === expected.revision && (actual?.plan_hash || null) === (expected.plan_hash || null);
}

export function retryDelay(attempt, retryAfter = null, now = Date.now()) {
    const seconds = Number(retryAfter);
    const headerDelay = retryAfter === null ? 0 : (Number.isFinite(seconds) ? seconds * 1000 : Math.max(0, Date.parse(retryAfter) - now) || 0);
    return Math.max(Math.min(300000, 15000 * (2 ** Math.min(attempt, 5))), headerDelay);
}

function pollDelay(metadata) {
    return Math.max(15000, Math.min(300000, Number(metadata?.poll_after_ms) || 15000));
}

export class PublicationPoller {
    constructor({ identity, metadata, request, apply, status, hidden = () => document.hidden, clock = { now: () => Date.now(), set: (fn, ms) => setTimeout(fn, ms), clear: (id) => clearTimeout(id) } }) {
        Object.assign(this, { identity, request, apply, status, hidden, clock });
        this.records = Object.fromEntries(environments.map(environment => [environment, { metadata: metadata?.[environment] || {}, checked: false, failures: 0, due: clock.now() + 1000 }]));
        this.generation = 0;
        this.paused = false;
        this.stopped = false;
        this.inFlight = false;
    }

    pause() {
        this.paused = true;
        this.generation += 1;
        this.clock.clear(this.timer);
        this.controller?.abort();
    }

    resume() {
        this.paused = false;
        this.schedule();
    }

    stop(message) {
        this.stopped = true;
        this.pause();
        for (const environment of environments) {
            if (this.records[environment].metadata.published) this.status(environment, message);
        }
    }

    schedule() {
        this.clock.clear(this.timer);
        if (this.paused || this.stopped || this.inFlight || this.hidden()) return;
        const pending = environments.filter(environment => {
            const record = this.records[environment];
            return record.metadata.published && (!record.checked || record.metadata.should_poll);
        }).sort((a, b) => this.records[a].due - this.records[b].due);
        if (!pending.length) return;
        const environment = pending[0];
        this.timer = this.clock.set(() => this.poll(environment), Math.max(0, this.records[environment].due - this.clock.now()));
    }

    async poll(environment) {
        if (this.paused || this.stopped || this.inFlight || this.hidden()) return;
        const record = this.records[environment];
        const generation = this.generation;
        const controller = new AbortController();
        this.controller = controller;
        this.inFlight = true;
        const timeout = this.clock.set(() => controller.abort(), 60000);
        this.status(environment, 'Checking for updates…');
        try {
            const result = await this.request(environment, controller.signal);
            if (generation !== this.generation || this.paused || this.stopped || this.hidden()) return;
            if (!samePublicationIdentity(this.identity, result)) {
                this.stop('Automatic updates stopped because this review changed. Reload the page to review the current task and release plan.');
                return;
            }
            if (!result.panels || !result.polling || environments.some(name => !result.polling[name] || typeof result.polling[name].published !== 'boolean' || typeof result.polling[name].should_poll !== 'boolean')) {
                throw new Error('The status response was incomplete.');
            }
            if (result.busy) {
                record.due = this.clock.now() + pollDelay(result.polling[environment]);
                this.status(environment, 'Another operation is finishing. Automatic updates will retry shortly.');
                return;
            }
            this.apply(result.panels);
            for (const name of environments) this.records[name].metadata = result.polling[name];
            record.checked = true;
            record.failures = 0;
            record.due = this.clock.now() + pollDelay(record.metadata);
            for (const name of environments) {
                const metadata = this.records[name].metadata;
                if (!metadata.published) continue;
                this.status(name, !this.records[name].checked ? 'Automatic updates are on. The initial status check is pending.' : metadata.error
                    ? 'Status could not be updated. Retrying automatically in 60 seconds; manual refresh is also available.'
                    : metadata.should_poll ? 'Automatic updates are on.' : 'Status is up to date. Automatic checks are complete.');
            }
        } catch (error) {
            if (generation !== this.generation || this.paused || this.stopped || this.hidden()) return;
            if ([403, 409, 419, 422].includes(error.status)) {
                this.stop(error.status === 409
                    ? 'Automatic updates stopped because this review changed. Reload the page to review the current task and release plan.'
                    : 'Automatic updates stopped. Reload this page to restore the session and check the current review.');
                return;
            }
            record.failures += 1;
            const delay = retryDelay(record.failures, error.retryAfter, this.clock.now());
            record.due = this.clock.now() + delay;
            this.status(environment, `Status update unavailable. Retrying automatically in ${Math.ceil(delay / 1000)} seconds; manual refresh is also available.`);
        } finally {
            this.clock.clear(timeout);
            this.inFlight = false;
            if (this.controller === controller) this.controller = null;
            this.schedule();
        }
    }
}

function hasUnsubmittedInput(region) {
    return Array.from(region.querySelectorAll('input:not([type="hidden"]), textarea, select')).some(input => {
        if (input === document.activeElement) return true;
        if (input instanceof HTMLInputElement && ['checkbox', 'radio'].includes(input.type)) return input.checked !== input.defaultChecked;
        if (input instanceof HTMLSelectElement) return Array.from(input.options).some(option => option.selected !== option.defaultSelected);
        return input.value !== input.defaultValue;
    });
}

function syncPreservedControls(current, next) {
    for (const form of current.querySelectorAll('form[data-publication-action]')) {
        const replacement = Array.from(next.querySelectorAll('form[data-publication-action]')).find(candidate => candidate.action === form.action);
        for (const button of form.querySelectorAll('button[type="submit"]')) button.disabled = !replacement || Boolean(replacement.querySelector('button[type="submit"]')?.disabled);
    }
}

export function patchPublicationPanels(monitor, panels) {
    const hit = document.elementFromPoint(Math.min(window.innerWidth - 1, Math.max(1, window.innerWidth / 2)), Math.min(80, window.innerHeight - 1));
    const editing = document.activeElement?.matches('input:not([type="hidden"]), textarea, select');
    const anchor = editing ? document.activeElement : hit?.closest('[data-publication-live]') || hit;
    const anchorTop = anchor?.getBoundingClientRect().top;
    for (const environment of environments) {
        if (typeof panels[environment] !== 'string') continue;
        const selector = `[data-${environment}-publication]`;
        const parsed = new DOMParser().parseFromString(panels[environment], 'text/html');
        const nextPanel = parsed.querySelector(selector);
        if (!nextPanel) continue;
        const currentPanel = monitor.querySelector(selector);
        if (!currentPanel) {
            if (environment === 'production') monitor.querySelector('[data-staging-publication]')?.after(nextPanel);
            continue;
        }
        for (const current of currentPanel.querySelectorAll('[data-publication-live]')) {
            const next = Array.from(nextPanel.querySelectorAll('[data-publication-live]')).find(candidate => candidate.dataset.publicationLive === current.dataset.publicationLive);
            if (!next) continue;
            if (hasUnsubmittedInput(current)) {
                syncPreservedControls(current, next);
                continue;
            }
            const details = new Map(Array.from(current.querySelectorAll('[data-publication-detail]'), detail => [detail.dataset.publicationDetail, detail.open]));
            const focused = current.contains(document.activeElement) ? document.activeElement : null;
            const focusDetail = focused?.closest('[data-publication-detail]')?.dataset.publicationDetail;
            const focusAction = focused?.closest('form[data-publication-action]')?.getAttribute('action');
            const focusLink = focused instanceof HTMLAnchorElement ? focused.getAttribute('href') : null;
            current.replaceChildren(...Array.from(next.childNodes));
            current.hidden = next.hidden;
            for (const detail of current.querySelectorAll('[data-publication-detail]')) {
                if (details.has(detail.dataset.publicationDetail)) detail.open = details.get(detail.dataset.publicationDetail);
            }
            if (focusDetail) Array.from(current.querySelectorAll('[data-publication-detail]')).find(detail => detail.dataset.publicationDetail === focusDetail)?.querySelector('summary')?.focus({ preventScroll: true });
            else if (focusAction) Array.from(current.querySelectorAll('form')).find(form => form.getAttribute('action') === focusAction)?.querySelector('button')?.focus({ preventScroll: true });
            else if (focusLink) Array.from(current.querySelectorAll('a')).find(link => link.getAttribute('href') === focusLink)?.focus({ preventScroll: true });
        }
    }
    if (anchor?.isConnected && Number.isFinite(anchorTop)) {
        const movement = anchor.getBoundingClientRect().top - anchorTop;
        if (Math.abs(movement) > 0.5) window.scrollBy({ top: movement, behavior: 'instant' });
    }
}

export function mountPublicationMonitor(monitor) {
    if (!monitor) return null;
    let metadata;
    try { metadata = JSON.parse(monitor.querySelector('[data-publication-polling]')?.textContent || '{}'); } catch { return null; }
    const identity = { revision: monitor.dataset.revision, plan_hash: monitor.dataset.planHash || null };
    const poller = new PublicationPoller({
        identity, metadata,
        request: async (environment, signal) => {
            const response = await fetch(monitor.dataset.pollUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin', mode: 'same-origin', cache: 'no-store', signal,
                body: JSON.stringify({ environment, ...identity }),
            });
            if (!response.ok) {
                const error = new Error('Publication status unavailable');
                error.status = response.status;
                error.retryAfter = response.headers.get('Retry-After');
                throw error;
            }
            return response.json();
        },
        apply: panels => patchPublicationPanels(monitor, panels),
        status: (environment, message) => {
            const text = monitor.querySelector(`[data-${environment}-publication] [data-publication-auto-status]`);
            if (text) { text.hidden = false; text.textContent = message; }
        },
    });
    let actionPending = false;
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) poller.pause();
        else if (!actionPending) poller.resume();
    });
    window.addEventListener('pagehide', () => poller.pause());
    poller.resume();
    return {
        pauseForAction() { actionPending = true; poller.pause(); },
        resumeAfterNavigation() { actionPending = false; poller.resume(); },
    };
}
