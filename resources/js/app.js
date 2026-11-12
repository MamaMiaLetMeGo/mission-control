import { mountTaskAndQueueMonitors } from './task-monitor';
import { mountPublicationMonitor } from './publication-monitor';

const taskPanel = document.getElementById('new-task');

document.addEventListener('click', (event) => {
    const trigger = event.target instanceof Element ? event.target.closest('[data-open-task]') : null;
    if (!trigger || !(taskPanel instanceof HTMLDetailsElement)) return;
    event.preventDefault();
    taskPanel.open = true;
    taskPanel.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth', block: 'start' });
    taskPanel.querySelector('input')?.focus({ preventScroll: true });
});

const navigationToggle = document.querySelector('[data-toggle-nav]');
const sidebar = document.getElementById('sidebar');
const mobileNavigation = window.matchMedia('(max-width: 760px)');

function syncNavigationAccessibility() {
    if (!sidebar) return;

    const open = sidebar.classList.contains('is-open');
    const hidden = mobileNavigation.matches && !open;

    if (hidden && sidebar.contains(document.activeElement)) {
        navigationToggle?.focus();
    }

    sidebar.toggleAttribute('inert', hidden);
    if (hidden) {
        sidebar.setAttribute('aria-hidden', 'true');
    } else {
        sidebar.removeAttribute('aria-hidden');
    }
    navigationToggle?.setAttribute('aria-expanded', String(mobileNavigation.matches && open));
}

function closeNavigation() {
    sidebar?.classList.remove('is-open');
    syncNavigationAccessibility();
}

navigationToggle?.addEventListener('click', () => {
    sidebar?.classList.toggle('is-open');
    syncNavigationAccessibility();
});

mobileNavigation.addEventListener('change', closeNavigation);
syncNavigationAccessibility();

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeNavigation();
});

document.addEventListener('click', (event) => {
    if (sidebar?.classList.contains('is-open') && !sidebar.contains(event.target) && !navigationToggle?.contains(event.target)) {
        closeNavigation();
    }
});

document.addEventListener('change', (event) => {
    const decisionAction = event.target;
    if (!(decisionAction instanceof HTMLSelectElement) || !decisionAction.matches('[data-decision-action]')) return;
    const decisionFeedback = decisionAction.closest('form')?.querySelector('[data-decision-feedback]');
    if (!(decisionFeedback instanceof HTMLTextAreaElement)) return;
    decisionFeedback.required = decisionAction.value !== 'defer';
    decisionFeedback.placeholder = decisionAction.value === 'defer' ? 'Optional: when should we revisit this?' : 'What needs to change, and why?';
});

const captureToggle = document.querySelector('[data-preview-enabled]');
const captureSettings = document.querySelector('[data-preview-settings]');
const previewDriver = document.querySelector('[data-preview-driver]');
const previewCommandField = document.querySelector('[data-preview-command-field]');
const previewLaravelNote = document.querySelector('[data-preview-laravel-note]');

function syncCaptureSettings() {
    if (captureToggle instanceof HTMLInputElement && captureSettings) {
        captureSettings.hidden = !captureToggle.checked;
        captureToggle.setAttribute('aria-expanded', String(captureToggle.checked));
    }
    if (previewDriver instanceof HTMLSelectElement) {
        if (previewCommandField) previewCommandField.hidden = previewDriver.value !== 'command';
        if (previewLaravelNote) previewLaravelNote.hidden = previewDriver.value !== 'laravel';
    }
}

captureToggle?.addEventListener('change', syncCaptureSettings);
previewDriver?.addEventListener('change', syncCaptureSettings);
syncCaptureSettings();

const previewLifecycles = new WeakMap();
function initializePreviewCards(root = document) {
for (const previewCard of root.querySelectorAll('[data-working-preview]')) {
    if (previewLifecycles.has(previewCard)) continue;
    let disposed = false;
    const liveSection = previewCard.querySelector('[data-preview-live]');
    const offlineSection = previewCard.querySelector('[data-preview-offline]');
    const link = previewCard.querySelector('[data-preview-link]');
    const state = previewCard.querySelector('[data-preview-state]');
    const expiry = previewCard.querySelector('[data-preview-expires]');
    const previewMessage = previewCard.querySelector('[data-preview-message]');
    const actionProgress = previewCard.querySelector('[data-preview-action-progress]');
    const actionProgressText = previewCard.querySelector('[data-preview-action-progress-text]');
    const actionError = previewCard.querySelector('[data-preview-action-error]');
    const actionForms = previewCard.querySelectorAll('form[data-preview-action]');
    let expiryTimer;
    let refreshTimer;
    let refreshController;
    let refreshing = false;
    let actionPending = false;

    function displayPreview(handle, verificationFailed = false) {
        if (disposed || !previewCard.isConnected || actionPending) return;
        if (previewMessage) previewMessage.hidden = false;
        clearTimeout(expiryTimer);
        let live = false;
        let url;
        try {
            url = new URL(handle?.url);
            live = handle?.revision === previewCard.dataset.previewRevision
                && Date.parse(handle.expiresAt) > Date.now()
                && url.protocol === 'http:'
                && ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)
                && !url.username && !url.password;
        } catch {
            live = false;
        }

        if (liveSection) liveSection.hidden = !live;
        if (offlineSection) offlineSection.hidden = live;
        if (!live && previewMessage) {
            previewMessage.textContent = verificationFailed
                ? 'The preview could not be verified. Refresh this page or start it again.'
                : 'The preview is stopped or has expired. Start it again from the reviewed revision.';
        }
        if (state) {
            state.textContent = live ? 'Live' : 'Offline';
            state.classList.toggle('status-merged', live);
        }
        if (link instanceof HTMLAnchorElement) {
            link.removeAttribute('aria-disabled');
            if (live) link.href = url.href;
            else link.removeAttribute('href');
        }
        if (live && expiry) {
            expiry.dateTime = handle.expiresAt;
            expiry.textContent = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZoneName: 'short' }).format(new Date(handle.expiresAt));
            expiryTimer = setTimeout(() => displayPreview(null), Math.min(Date.parse(handle.expiresAt) - Date.now(), 2147483647));
        }
    }

    async function refreshPreview() {
        clearTimeout(refreshTimer);
        if (disposed || !previewCard.isConnected || refreshing || actionPending) return;
        if (document.hidden) {
            refreshTimer = setTimeout(refreshPreview, 15000);
            return;
        }
        refreshing = true;
        const controller = new AbortController();
        refreshController = controller;
        const timeout = setTimeout(() => controller.abort(), 5000);
        try {
            const response = await fetch(previewCard.dataset.previewStatusUrl, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
            });
            if (!response.ok) throw new Error('Preview status unavailable');
            const result = await response.json();
            displayPreview(result.preview);
        } catch {
            displayPreview(null, true);
        } finally {
            clearTimeout(timeout);
            refreshing = false;
            if (refreshController === controller) refreshController = undefined;
            if (!disposed && previewCard.isConnected && !document.hidden && !actionPending) refreshTimer = setTimeout(refreshPreview, 15000);
        }
    }

    for (const form of actionForms) {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (actionPending) return;

            const action = form.dataset.previewAction;
            const starting = action === 'start';
            actionPending = true;
            if (previewMessage) previewMessage.hidden = true;
            clearTimeout(refreshTimer);
            clearTimeout(expiryTimer);
            refreshController?.abort();

            if (actionError) {
                actionError.hidden = true;
                actionError.textContent = '';
            }
            if (actionProgress) actionProgress.hidden = false;
            if (actionProgressText) {
                actionProgressText.textContent = starting
                    ? 'Preparing preview… The first startup can take a few minutes.'
                    : 'Stopping preview…';
            }
            if (state) {
                state.textContent = starting ? 'Preparing…' : 'Stopping…';
                state.classList.remove('status-merged');
            }
            if (link instanceof HTMLAnchorElement) {
                link.removeAttribute('href');
                link.setAttribute('aria-disabled', 'true');
            }
            for (const actionForm of actionForms) {
                for (const button of actionForm.querySelectorAll('button[type="submit"]')) {
                    button.disabled = true;
                    button.setAttribute('aria-busy', 'true');
                }
            }
            const buttonLabel = form.querySelector('[data-preview-button-label]');
            const originalLabel = buttonLabel?.textContent;
            if (buttonLabel) buttonLabel.textContent = starting ? 'Preparing preview…' : 'Stopping…';

            let succeeded = false;
            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(form),
                    credentials: 'same-origin',
                    mode: 'same-origin',
                    cache: 'no-store',
                });
                const payload = response.headers.get('Content-Type')?.includes('application/json')
                    ? await response.json()
                    : null;

                if (!response.ok) {
                    const validationMessages = payload?.errors && typeof payload.errors === 'object'
                        ? Object.values(payload.errors).flat().filter((message) => typeof message === 'string')
                        : [];
                    const message = response.status === 419
                        ? 'Your session has expired. Refresh this page and try again.'
                        : validationMessages.join('\n') || (typeof payload?.message === 'string' ? payload.message : '')
                            || `The preview could not be ${starting ? 'started' : 'stopped'}. Refresh the page to check its status, then try again.`;
                    throw new Error(message);
                }

                succeeded = true;
                if (actionProgressText) actionProgressText.textContent = starting ? 'Preview ready. Refreshing…' : 'Preview stopped. Refreshing…';
                window.location.reload();
            } catch (error) {
                if (actionError) {
                    actionError.textContent = error instanceof TypeError
                        ? 'The connection was interrupted. Check the preview status before trying again.'
                        : error.message || 'The request could not be completed. Check the preview status before trying again.';
                    actionError.hidden = false;
                }
            } finally {
                if (!succeeded) {
                    actionPending = false;
                    if (previewMessage) previewMessage.hidden = false;
                    if (actionProgress) actionProgress.hidden = true;
                    if (buttonLabel) buttonLabel.textContent = originalLabel;
                    for (const actionForm of actionForms) {
                        for (const button of actionForm.querySelectorAll('button[type="submit"]')) {
                            button.disabled = false;
                            button.removeAttribute('aria-busy');
                        }
                    }
                    await refreshPreview();
                }
            }
        });
    }

    const visibilityChanged = () => {
        if (document.hidden) {
            clearTimeout(refreshTimer);
            refreshController?.abort();
        } else refreshPreview();
    };
    document.addEventListener('visibilitychange', visibilityChanged);
    previewLifecycles.set(previewCard, () => {
        disposed = true;
        clearTimeout(refreshTimer);
        clearTimeout(expiryTimer);
        refreshController?.abort();
        document.removeEventListener('visibilitychange', visibilityChanged);
        previewLifecycles.delete(previewCard);
    });
    refreshPreview();
}
}
function disposePreviewCards(root) {
    for (const card of root.querySelectorAll('[data-working-preview]')) previewLifecycles.get(card)?.();
}
initializePreviewCards();

for (const repositoryPanel of document.querySelectorAll('[data-repository-panel]')) {
    const forms = repositoryPanel.querySelectorAll('form[data-repository-action]');
    const progress = repositoryPanel.querySelector('[data-repository-progress]');
    const errorMessage = repositoryPanel.querySelector('[data-repository-error]');
    const successMessage = repositoryPanel.querySelector('[data-repository-success]');
    const states = {
        unchecked: ['Unchecked', '', 'Check the remote to compare this local branch with its upstream.'],
        up_to_date: ['Up to date', 'status-merged', 'Local and remote commits matched at the last remote check.'],
        behind: ['Behind remote', 'status-queued', 'The local branch can be updated with commits from its upstream.'],
        ahead: ['Ahead of remote', 'status-queued', 'The local branch contains commits that are not on its upstream.'],
        diverged: ['Diverged', 'status-deferred', 'Local and remote branches have both moved. Resolve the divergence before syncing.'],
        dirty: ['Uncommitted edits', 'status-deferred', 'This checkout has uncommitted edits. Syncing is blocked while they are present.'],
        wrong_branch: ['Different branch', 'status-deferred', 'The checked-out branch differs from the configured base branch.'],
        unavailable: ['Unavailable', '', 'Repository status could not be determined. Check the remote for details.'],
    };
    let repository = {};
    try {
        repository = JSON.parse(repositoryPanel.dataset.repositoryStatus || '{}');
    } catch {
        repository = {};
    }
    let pendingAction = null;

    function setRepositoryText(selector, value) {
        const element = repositoryPanel.querySelector(selector);
        if (element) element.textContent = value;
    }

    function renderRepository(status) {
        repository = status;
        const [label, badgeClass, summary] = states[status.state] || states.unavailable;
        const badge = repositoryPanel.querySelector('[data-repository-state]');
        if (badge) {
            badge.textContent = label;
            badge.classList.remove('status-merged', 'status-queued', 'status-deferred');
            if (badgeClass) badge.classList.add(badgeClass);
        }
        setRepositoryText('[data-repository-summary]', summary);
        setRepositoryText('[data-repository-path]', status.repository_path || 'Not configured');
        setRepositoryText('[data-repository-local-branch]', status.current_branch || '—');
        setRepositoryText('[data-repository-upstream]', status.upstream || '—');
        for (const [name, revision] of [['local', status.local_revision], ['remote', status.remote_revision]]) {
            const element = repositoryPanel.querySelector(`[data-repository-${name}-revision]`);
            if (element) {
                element.textContent = typeof revision === 'string' ? revision.slice(0, 12) : '—';
                element.title = typeof revision === 'string' ? revision : '';
            }
        }
        setRepositoryText('[data-repository-ahead]', Number.isInteger(status.ahead) ? String(status.ahead) : '—');
        setRepositoryText('[data-repository-behind]', Number.isInteger(status.behind) ? String(status.behind) : '—');
        setRepositoryText('[data-repository-working-tree]', Number.isInteger(status.dirty_count)
            ? (status.dirty_count === 0 ? 'Clean' : `${status.dirty_count} uncommitted entries`)
            : 'Unchecked');

        const lastChecked = repositoryPanel.querySelector('[data-repository-last-checked]');
        if (lastChecked) {
            if (status.last_checked_at && Number.isFinite(Date.parse(status.last_checked_at))) {
                lastChecked.dateTime = status.last_checked_at;
                lastChecked.textContent = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', timeZoneName: 'short' }).format(new Date(status.last_checked_at));
            } else {
                lastChecked.removeAttribute('datetime');
                lastChecked.textContent = 'Not checked yet';
            }
        }

        const blockers = repositoryPanel.querySelector('[data-repository-blockers]');
        const blockerList = repositoryPanel.querySelector('[data-repository-blocker-list]');
        if (blockers) blockers.hidden = status.can_sync === true;
        if (blockerList) {
            const messages = Array.isArray(status.blockers) ? status.blockers.filter((message) => typeof message === 'string') : [];
            if (!messages.length) messages.push(status.state === 'up_to_date' ? 'Already up to date. There is nothing to pull.' : 'Check the remote and resolve any blockers before syncing.');
            blockerList.replaceChildren(...messages.map((message) => {
                const item = document.createElement('li');
                item.textContent = message;
                return item;
            }));
        }

        for (const form of forms) {
            const isSync = form.dataset.repositoryAction === 'sync';
            const values = { expected_head: status.local_revision, expected_remote: status.remote_revision, expected_base: status.base_branch, expected_repository: status.repository_path };
            if (isSync) {
                for (const [name, value] of Object.entries(values)) {
                    const input = form.querySelector(`input[name="${name}"]`);
                    if (input) input.value = value || '';
                }
            }
            const button = form.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = pendingAction !== null || (isSync && status.can_sync !== true);
                if (pendingAction) button.setAttribute('aria-busy', 'true');
                else button.removeAttribute('aria-busy');
            }
            const buttonLabel = form.querySelector('[data-repository-button-label]');
            if (buttonLabel) {
                buttonLabel.textContent = pendingAction === form.dataset.repositoryAction
                    ? (isSync ? 'Syncing…' : 'Checking remote…')
                    : (isSync ? (status.upstream ? `Sync with ${status.upstream}` : 'Sync local branch') : 'Check remote');
            }
        }
    }

    for (const form of forms) {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (pendingAction) return;
            const action = form.dataset.repositoryAction;
            if (action === 'sync' && repository.can_sync !== true) return;
            pendingAction = action;
            if (errorMessage) errorMessage.hidden = true;
            if (successMessage) successMessage.hidden = true;
            if (progress) {
                progress.hidden = false;
                progress.textContent = action === 'sync'
                    ? 'Checking the remote again, then fast-forwarding the local branch…'
                    : 'Checking the remote for the latest commits…';
            }
            renderRepository(repository);

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(form),
                    credentials: 'same-origin',
                    mode: 'same-origin',
                    cache: 'no-store',
                });
                const payload = response.headers.get('Content-Type')?.includes('application/json') ? await response.json() : null;
                if (!response.ok) {
                    const validationMessages = payload?.errors && typeof payload.errors === 'object'
                        ? Object.values(payload.errors).flat().filter((message) => typeof message === 'string')
                        : [];
                    const message = response.status === 419
                        ? 'Your session has expired. Refresh the page and try again.'
                        : validationMessages.join('\n') || (typeof payload?.message === 'string' ? payload.message : '')
                            || 'The repository operation could not be completed. Check the remote again before syncing.';
                    throw new Error(message);
                }
                if (!payload?.repository || typeof payload.repository.state !== 'string') {
                    throw new Error('No repository status was returned. Refresh the page before trying again.');
                }
                renderRepository(payload.repository);
                if (successMessage) {
                    successMessage.textContent = typeof payload.message === 'string' ? payload.message : 'Repository status updated.';
                    successMessage.hidden = false;
                }
            } catch (error) {
                if (errorMessage) {
                    errorMessage.textContent = error instanceof TypeError
                        ? 'The connection was interrupted. Check the remote again before syncing.'
                        : error instanceof Error ? error.message : 'The repository operation failed. Check the remote again.';
                    errorMessage.hidden = false;
                }
                try {
                    const response = await fetch(repositoryPanel.dataset.repositoryStatusUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin', mode: 'same-origin', cache: 'no-store' });
                    if (!response.ok) throw new Error('Status unavailable');
                    const payload = await response.json();
                    if (!payload.repository || typeof payload.repository.state !== 'string') throw new Error('Status unavailable');
                    renderRepository(payload.repository);
                } catch {
                    renderRepository({ ...repository, can_sync: false, blockers: ['Check the remote again before syncing.'] });
                }
            } finally {
                pendingAction = null;
                if (progress) progress.hidden = true;
                renderRepository(repository);
            }
        });
    }
}

const publicationMonitor = mountPublicationMonitor(document.querySelector('[data-publication-monitor]'));
let publicationPending = false;
const publicationButtonState = new Map();

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-publication-action]') || event.defaultPrevented) return;
    const publicationPanel = form.closest('[data-staging-publication], [data-production-publication]');
    if (!publicationPanel) return;
    const button = form.querySelector('button[type="submit"]');
    if (publicationPending || button?.disabled) {
        event.preventDefault();
        return;
    }
    publicationPending = true;
    publicationMonitor?.pauseForAction();
    const isProductionRelease = publicationPanel.hasAttribute('data-production-publication');
    const action = form.dataset.publicationAction;
    const labels = { plan: 'Preparing release plan…', verify: 'Importing local checks…', publish: 'Publishing…', ready: 'Marking release ready…', refresh: 'Refreshing…', merge: isProductionRelease ? 'Merging into production…' : 'Merging into staging…', 'close-conflict': 'Closing pull request…' };
    const messages = isProductionRelease ? {
        plan: 'Preparing a local release plan and its complete file scope…',
        verify: 'Validating local check evidence against the saved release plan…',
        publish: 'Publishing the saved release plan as a draft production pull request…',
        ready: 'Verifying the release and marking its production pull request ready…',
        refresh: 'Checking the production pull request, checks, and deployment…',
        merge: 'Verifying and merging the reviewed release into production…',
        'close-conflict': 'Checking the selected pull request revision and closing it…',
    } : {
        publish: 'Publishing the approved revision and preparing its staging pull request…',
        refresh: 'Checking the pull request, checks, and staging deployment…',
        merge: 'Verifying and merging the approved pull request into staging…',
    };
    for (const actionButton of document.querySelectorAll('[data-publication-action] button[type="submit"]')) {
        const label = actionButton.querySelector('[data-publication-button-label]');
        publicationButtonState.set(actionButton, { disabled: actionButton.disabled, label, text: label?.textContent });
        actionButton.disabled = true;
        actionButton.setAttribute('aria-busy', 'true');
    }
    const label = form.querySelector('[data-publication-button-label]');
    if (label) label.textContent = labels[action] || 'Working…';
    const progress = publicationPanel.querySelector('[data-publication-progress]');
    if (progress) {
        progress.textContent = messages[action] || 'Updating publication status…';
        progress.hidden = false;
    }
});

window.addEventListener('pageshow', () => {
    publicationPending = false;
    for (const progress of document.querySelectorAll('[data-publication-progress]')) progress.hidden = true;
    for (const [button, initial] of publicationButtonState) {
        button.disabled = initial.disabled;
        button.removeAttribute('aria-busy');
        if (initial.label) initial.label.textContent = initial.text;
    }
    publicationButtonState.clear();
    publicationMonitor?.resumeAfterNavigation();
});

mountTaskAndQueueMonitors({ initialize: initializePreviewCards, dispose: disposePreviewCards });
