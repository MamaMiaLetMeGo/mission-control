import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';

const root = fileURLToPath(new URL('../../', import.meta.url));
const manifest = JSON.parse(await readFile(resolve(root, 'public/build/manifest.json'), 'utf8'));
const script = manifest['resources/js/app.js'].file;
const stylesheet = manifest['resources/css/app.css'].file;
const revision = 'a'.repeat(40);
let polls = 0;
let actions = 0;

function staging(phase) {
    return `<section data-staging-publication class="panel mb-6"><div class="panel-heading" data-publication-live="heading"><h2>Fixture staging</h2><span>${phase === 0 ? 'Checks pending' : phase === 1 ? 'Deployment running' : 'Deployment succeeded'}</span></div><div class="p-5 flex flex-col gap-5"><div data-publication-live="progress">${phase === 0 ? 'Waiting for checks' : phase === 1 ? 'Checks passed. Deployment is running.' : 'Deployment succeeded for the reviewed revision.'}</div><div data-publication-live="remote-checks"><details class="publication-checks" data-publication-detail="remote-checks"><summary>Fixture check details</summary><ul><li>Backend ${phase ? 'passed' : 'pending'}</li>${phase === 2 ? '<li>App passed</li>' : ''}</ul></details></div><div data-publication-live="actions"><button type="button" disabled>Merge is never automatic</button><p>Poll responses: ${phase}</p></div><p data-publication-auto-status role="status" hidden></p><p data-publication-progress hidden></p></div></section>`;
}

function production(eligible) {
    return `<section data-production-publication class="panel mb-6"><div class="panel-heading" data-publication-live="heading"><h2>Fixture production planning</h2><span>${eligible ? 'Planning available' : 'Waiting for staging deployment'}</span></div><div class="p-5 flex flex-col gap-5"><div data-publication-live="scope-form"><details class="publication-checks" data-publication-detail="scope" open><summary>Fixture scope</summary><form class="p-4 flex flex-col gap-4" action="/forbidden" method="POST" data-publication-action="plan"><input type="hidden" name="revision" value="${revision}"><div class="field"><label for="fixture-note">Unsent release note</label><textarea id="fixture-note" name="additional_paths">Original note</textarea></div><button type="submit" class="button button-secondary" ${eligible ? '' : 'disabled'}><span data-publication-button-label>Fixture Plan release</span></button></form></details></div><div data-publication-live="local-verification"><details class="publication-checks" data-publication-detail="import-evidence"><summary>Fixture evidence</summary><form action="/forbidden" method="POST" class="p-4" data-publication-action="verify"><div class="field"><label for="fixture-evidence">Unsent evidence path</label><input id="fixture-evidence" name="evidence_file" value="original.json"></div><button type="submit" disabled>Fixture Import checks</button></form></details></div><p data-publication-auto-status role="status" hidden></p><p data-publication-progress hidden></p></div></section>`;
}

const server = createServer(async (request, response) => {
    const path = new URL(request.url, 'http://127.0.0.1:8766').pathname;
    if (request.method === 'GET' && path === '/fixture') {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        response.end(`<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="fixture-token"><title>Local publication polling fixture</title><link rel="stylesheet" href="/${stylesheet}"><script type="module" src="/${script}"></script></head><body class="bg-canvas"><main class="max-w-4xl mx-auto p-8"><h1 class="text-2xl font-semibold mb-4">Local polling test — no real tasks</h1><p class="mb-6">Only fake status responses. Do not submit action forms.</p><div data-publication-monitor data-poll-url="/poll" data-revision="${revision}" data-plan-hash="">${staging(0)}<script type="application/json" data-publication-polling>${JSON.stringify({ staging: { published: true, should_poll: true, poll_after_ms: 15000 }, production: { published: false, should_poll: false } })}</script></div><div class="field"><label for="fixture-outside">Unrelated page input</label><input id="fixture-outside" value="Keep this value"></div></main></body></html>`);
        return;
    }
    if (request.method === 'GET' && [script, stylesheet].includes(path.slice(1))) {
        response.setHeader('Content-Type', path.endsWith('.js') ? 'text/javascript' : 'text/css');
        response.end(await readFile(resolve(root, 'public/build', path.slice(1))));
        return;
    }
    if (request.method === 'POST' && path === '/poll') {
        let body = '';
        for await (const chunk of request) body += chunk;
        const input = JSON.parse(body);
        if (request.headers['x-csrf-token'] !== 'fixture-token' || input.revision !== revision || input.plan_hash !== null || input.environment !== 'staging') {
            response.writeHead(422).end();
            return;
        }
        const phase = Math.min(++polls, 2);
        console.log(JSON.stringify({ event: 'poll', polls, actions, phase }));
        await new Promise(resolve => setTimeout(resolve, 3000));
        response.setHeader('Content-Type', 'application/json');
        response.end(JSON.stringify({ revision, plan_hash: null, panels: { staging: staging(phase), production: production(phase === 2) }, polling: { staging: { published: true, should_poll: phase < 2, poll_after_ms: 15000, error: null }, production: { published: false, should_poll: false, poll_after_ms: 15000, error: null } }, busy: false }));
        return;
    }
    if (request.method === 'GET' && path === '/metrics') {
        response.setHeader('Content-Type', 'application/json');
        response.end(JSON.stringify({ polls, actions }));
        return;
    }
    if (request.method === 'POST') actions += 1;
    response.writeHead(404).end('Fixture route unavailable');
});

server.listen(8766, '127.0.0.1', () => console.log('Read-only browser fixture: http://127.0.0.1:8766/fixture'));
process.on('SIGINT', () => { console.log(JSON.stringify({ event: 'stopped', polls, actions })); server.close(); });
