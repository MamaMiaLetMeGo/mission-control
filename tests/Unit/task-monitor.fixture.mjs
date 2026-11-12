import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';

const root = fileURLToPath(new URL('../../', import.meta.url));
const manifest = JSON.parse(await readFile(resolve(root, 'public/build/manifest.json'), 'utf8'));
const assets = [manifest['resources/js/app.js'].file, manifest['resources/css/app.css'].file];
const assetContents = new Map(await Promise.all(assets.map(async path => [path, await readFile(resolve(root, 'public/build', path))])));
const revision = 'a'.repeat(40);
const metrics = { progress: 0, queue: 0, preview: 0, actions: 0 };
let finished = false;

function task(status) {
    const complete = status === 'review';
    return `<div data-task-content data-run-id="42" data-task-status="${status}" data-revision="${complete ? revision : ''}">
    <div data-task-live="heading"><h2>Fixture task — ${complete ? 'Needs review' : status === 'queued' ? 'Queued' : 'In progress'}</h2></div>
    <div data-task-live="phase">${complete ? '' : 'Running the configured tests.'}</div>
    <section data-task-live="runs"><details data-task-detail="run-42"><summary>Fixture run history</summary><pre data-task-log="run-42" style="height:80px;overflow:auto">${Array.from({ length: 25 }, (_, i) => `Run event ${i + 1}`).join('\n')}\n${complete ? 'Independent review passed.' : 'Tests running.'}</pre></details></section>
    <div data-task-live="next-step">${complete ? 'Ready for your decision.' : 'Your team is working.'}</div>
    ${complete ? `<div class="panel p-5 mt-4"><h3>Review package ready</h3><p>Tests passed. Independent review passed.</p><a href="#evidence">Before and after screenshots</a>
    <form action="/forbidden" method="POST"><input type="hidden" name="revision" value="${revision}"><button type="submit" class="button">Approve for merge</button></form>
    <form action="/forbidden" method="POST" class="field"><label for="fixture-decision">Another decision</label><select id="fixture-decision" data-decision-action><option value="request_changes">Request changes</option><option value="defer">Defer for later</option></select><label for="fixture-feedback">Your feedback</label><textarea id="fixture-feedback" data-decision-feedback required></textarea></form></div>
    <section class="panel p-5 mt-4" data-working-preview data-preview-status-url="/preview" data-preview-revision="${revision}"><h3>Working preview</h3><span data-preview-state>Offline</span><div data-preview-live hidden><a data-preview-link>Open working preview</a><time data-preview-expires></time></div><div data-preview-offline><p data-preview-message>Preview stopped.</p></div></section>` : ''}
    </div>`;
}

const server = createServer(async (request, response) => {
    const url = new URL(request.url, 'http://127.0.0.1:8766');
    response.setHeader('Cache-Control', 'no-store');
    const json = value => { response.setHeader('Content-Type', 'application/json'); response.end(JSON.stringify(value)); };
    if (request.method === 'GET' && assets.includes(url.pathname.slice(1))) {
        response.setHeader('Content-Type', url.pathname.endsWith('.js') ? 'text/javascript' : 'text/css');
        response.end(assetContents.get(url.pathname.slice(1)));
        return;
    }
    if (request.method === 'GET' && url.pathname === '/fixture') {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        response.end(`<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="fixture"><title>Task and queue polling fixture</title><link rel="stylesheet" href="/${assets[1]}"><script type="module" src="/${assets[0]}"></script></head><body class="bg-canvas"><main class="max-w-4xl mx-auto p-8"><h1>Task and queue polling fixture</h1><p>Isolated fake data. No real task actions.</p><button type="button" id="finish-fixture" class="button button-secondary">Finish fixture run</button><span data-review-count hidden>0</span>
        <section data-queue-monitor data-status-url="/queue" class="panel p-5 mt-4"><h2>Fixture review queue <span data-queue-total>1</span></h2><div data-queue-stats>0 ready for review</div><p data-queue-update-status role="status">Queue updates automatically.</p><time data-queue-checked></time><label for="fixture-filter">Unsubmitted focus filter</label><select id="fixture-filter"><option value="engineering" selected>Engineering</option><option value="quality">Quality</option></select><div data-queue-list><a href="#task-fixture">Fixture task · Queued</a></div></section>
        <details id="new-task" class="panel p-5 mt-4"><summary>Fixture new task draft</summary><form action="/forbidden" method="POST" class="field"><label for="fixture-draft">Draft title</label><input id="fixture-draft" value=""><button type="submit">Never submit fixture</button></form></details>
        <section id="task-fixture" class="panel p-5 mt-4" data-task-monitor data-status-url="/progress" data-run-id="42" data-revision=""><p data-task-update-status role="status">Progress updates automatically.</p><time data-task-checked></time>${task('queued')}</section>
        </main><script>document.getElementById('finish-fixture').addEventListener('click', async () => {await fetch('/finish',{method:'POST'});document.getElementById('finish-fixture').textContent='Fixture completion queued';});</script></body></html>`);
        return;
    }
    if (request.method === 'POST' && url.pathname === '/finish') { finished = true; json({ finished }); return; }
    if (request.method === 'GET' && url.pathname === '/progress') {
        metrics.progress += 1;
        if (url.searchParams.get('run_id') !== '42') { response.writeHead(409); json({ reload: true }); return; }
        json({ run_id: 42, status: finished ? 'review' : 'running', revision: finished ? revision : null, active: !finished, poll_after_ms: 5000, checked_at: new Date().toISOString(), html: task(finished ? 'review' : 'running') });
        return;
    }
    if (request.method === 'GET' && url.pathname === '/queue') {
        metrics.queue += 1;
        if (url.searchParams.get('area') !== 'engineering' || url.searchParams.get('page') !== '2') { response.writeHead(422); json({ message: 'Filters were not preserved' }); return; }
        json({ html: { stats: `${finished ? 1 : 0} ready for review`, queue: `<a href="#task-fixture">Fixture task · ${finished ? 'Needs review' : 'In progress'}</a><a href="/fixture?area=engineering&page=1">Page 1</a>` }, counts: { review: finished ? 1 : 0, running: finished ? 0 : 1 }, active: true, poll_after_ms: 5000, checked_at: new Date().toISOString() });
        return;
    }
    if (request.method === 'GET' && url.pathname === '/preview') { metrics.preview += 1; json({ preview: { id: '11111111-1111-4111-8111-111111111111', revision, url: 'http://127.0.0.1:9999', expiresAt: new Date(Date.now() + 1800000).toISOString() } }); return; }
    if (request.method === 'GET' && url.pathname === '/metrics') { json(metrics); return; }
    if (request.method === 'POST') metrics.actions += 1;
    response.writeHead(404).end();
});
server.listen(8766, '127.0.0.1', () => console.log('Fixture ready: http://127.0.0.1:8766/fixture?area=engineering&page=2'));
process.on('SIGINT', () => { console.log(JSON.stringify(metrics)); server.close(() => process.exit(0)); });
