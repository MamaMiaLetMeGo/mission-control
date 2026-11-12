import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash, randomUUID } from 'node:crypto';
import { mkdtemp, mkdir, readFile, readdir, rm, symlink, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { git, safeEnvironment } from '../dist/process.js';
import { capturePreview, readPreviewManifests, startPreview, statusPreview, stopPreview, validatePreviewConfig, validatePreviewPath } from '../dist/preview.js';

async function fixture(t) {
  const root = await mkdtemp(path.join(path.resolve('test'), '.fixture-preview-'));
  const source = path.join(root, 'source');
  const runtime = path.join(root, 'runtime');
  await mkdir(source);
  await mkdir(runtime);
  const environment = safeEnvironment(runtime);
  await git(['init', '-b', 'main'], source, environment);
  await writeFile(path.join(source, 'server.mjs'), `import http from 'node:http';
import fs from 'node:fs';
const message = fs.readFileSync('message.txt','utf8');
const server = http.createServer((req,res)=>{
 if(req.url==='/runtime'){ fs.writeFileSync(process.env.HOME+'/server-runtime.txt','owned-runtime'); }
 if(req.url==='/bad'){ res.writeHead(500); res.end('failed'); return; }
 if(req.url==='/redirect'){ res.writeHead(302,{Location:'/'}); res.end(); return; }
 res.setHeader('Content-Type','text/html');
 res.end('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Preview</title></head><body style="margin:0;min-height:'+(req.url==='/tall'?'21000':'1200')+'px;background:#faf7f2"><h1>'+message+'</h1><p>secret-file:'+fs.existsSync('.env')+'</p></body></html>');
});
server.listen(Number(process.argv[2]),'127.0.0.1');
`);
  await writeFile(path.join(source, 'message.txt'), 'committed-v1');
  await writeFile(path.join(source, '.env'), 'FAKE_TEST_SECRET=should-not-enter-preview');
  await git(['add', '.'], source, environment);
  await git(['-c', 'user.name=Test', '-c', 'user.email=test@localhost', 'commit', '--no-gpg-sign', '-m', 'Initial'], source, environment);
  const revision = await git(['rev-parse', 'HEAD'], source, environment);
  const request = {
    taskId: 1, revision, worktreePath: source, repositoryPath: source,
    artifactPath: path.join(root, 'artifacts'), previewRoot: path.join(root, 'previews'), phase: 'after',
    config: { enabled: true, driver: 'command', directory: '.', command: [process.execPath, 'server.mjs', '{port}'], paths: ['/'], ttlSeconds: 60 },
  };
  t.after(async () => {
    await stopPreview({ taskId: request.taskId, previewRoot: request.previewRoot });
    const deadline = Date.now() + 5000;
    while (Date.now() < deadline) {
      const manifests = await readPreviewManifests({ taskId: request.taskId, previewRoot: request.previewRoot });
      if (manifests.every((manifest) => ['stopped', 'failed'].includes(manifest.state))) break;
      await delay(25);
    }
    await rm(root, { recursive: true, force: true });
  });
  return { root, source, environment, request };
}

async function eventually(check, timeout = 5000) {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    if (await check()) return;
    await delay(25);
  }
  assert.fail('The expected preview lifecycle transition did not happen.');
}

test('serves exact committed snapshots, strips environment files, and captures immutable desktop/mobile evidence', async (t) => {
  const { source, request } = await fixture(t);
  await writeFile(path.join(source, 'message.txt'), 'dirty-user-work');
  const handle = await startPreview(request);
  assert.match(handle.id, /^[a-f0-9-]{36}$/);
  assert.match(handle.url, /^http:\/\/localhost:\d+$/);
  assert.deepEqual(Object.keys(handle).sort(), ['expiresAt', 'id', 'revision', 'url']);
  const html = await (await fetch(handle.url)).text();
  assert.match(html, /committed-v1/);
  assert.match(html, /secret-file:false/);
  assert.doesNotMatch(html, /dirty-user-work/);
  assert.equal(await readFile(path.join(source, 'message.txt'), 'utf8'), 'dirty-user-work');
  const shots = await capturePreview(handle, request);
  assert.equal(shots.length, 2);
  assert.deepEqual(shots.map((shot) => shot.viewport), ['desktop', 'mobile']);
  for (const shot of shots) {
    const bytes = await readFile(path.join(request.artifactPath, shot.file));
    assert.equal(shot.width, bytes.readUInt32BE(16));
    assert.equal(shot.height, bytes.readUInt32BE(20));
    assert.ok(shot.height >= 1200);
    assert.equal(shot.sha256, createHash('sha256').update(bytes).digest('hex'));
    assert.equal(shot.revision, request.revision);
  }
  await assert.rejects(capturePreview(handle, request), /EEXIST/);
  assert.equal(await statusPreview({ taskId: request.taskId, previewRoot: request.previewRoot }), null);
});

test('authenticated stop shuts down only the owned session and rejects an invalid control token', async (t) => {
  const { request } = await fixture(t);
  const handle = await startPreview(request);
  const [manifest] = await readPreviewManifests({ taskId: request.taskId, previewRoot: request.previewRoot, id: handle.id });
  assert.equal((await fetch(new URL('stop', manifest.controlUrl), { method: 'POST', headers: { Authorization: 'Bearer invalid' } })).status, 403);
  assert.equal((await statusPreview({ taskId: request.taskId, previewRoot: request.previewRoot })).id, handle.id);
  await stopPreview({ taskId: request.taskId, previewRoot: request.previewRoot, id: randomUUID() });
  assert.equal((await statusPreview({ taskId: request.taskId, previewRoot: request.previewRoot })).id, handle.id);
  await stopPreview({ taskId: request.taskId, previewRoot: request.previewRoot, id: handle.id });
  const sessionFiles = await readdir(path.join(request.previewRoot, `task-${request.taskId}`, handle.id));
  assert.ok(!sessionFiles.includes('snapshot'));
  assert.ok(!sessionFiles.includes('runtime'));
  assert.ok(sessionFiles.includes('manifest.json'));
  assert.ok(sessionFiles.includes('server.log'));
  await eventually(async () => {
    try { await fetch(handle.url, { signal: AbortSignal.timeout(500) }); return false; } catch { return true; }
  });
  assert.equal(await statusPreview({ taskId: request.taskId, previewRoot: request.previewRoot }), null);
});

test('expires the preview server after its TTL', async (t) => {
  const { request } = await fixture(t);
  request.config.ttlSeconds = 1;
  const handle = await startPreview(request);
  await eventually(async () => {
    try { await fetch(handle.url, { signal: AbortSignal.timeout(500) }); return false; } catch { return true; }
  });
  assert.equal(await statusPreview({ taskId: request.taskId, previewRoot: request.previewRoot }), null);
});

test('preview servers can write their owned runtime for SQLite journals and temporary application files', async (t) => {
  const { request } = await fixture(t);
  request.config.paths = ['/runtime'];

  const handle = await startPreview(request);

  const runtimeFile = path.join(request.previewRoot, `task-${request.taskId}`, handle.id, 'runtime/server-runtime.txt');
  assert.equal(await readFile(runtimeFile, 'utf8'), 'owned-runtime');
});

test('refuses escaping archive symlinks before starting any preview server', async (t) => {
  const { source, environment, request } = await fixture(t);
  await symlink('/etc', path.join(source, 'outside'));
  await git(['add', 'outside'], source, environment);
  await git(['-c', 'user.name=Test', '-c', 'user.email=test@localhost', 'commit', '--no-gpg-sign', '-m', 'Unsafe symlink'], source, environment);
  request.revision = await git(['rev-parse', 'HEAD'], source, environment);
  await assert.rejects(startPreview(request), /symlink that escapes/);
  assert.equal(await statusPreview({ taskId: request.taskId, previewRoot: request.previewRoot }), null);
});

test('failed readiness and redirected screenshot routes stop their owned preview', async (t) => {
  const { request } = await fixture(t);
  await assert.rejects(startPreview({ ...request, config: { ...request.config, paths: ['/bad'] } }), /HTTP 500/);
  const handle = await startPreview(request);
  await assert.rejects(capturePreview(handle, { ...request, config: { ...request.config, paths: ['/redirect'] } }), /redirected/);
  assert.equal(await statusPreview({ taskId: request.taskId, previewRoot: request.previewRoot }), null);
});

test('cancellation during startup stops the detached supervisor instead of leaving a preview', async (t) => {
  const { request } = await fixture(t);
  const controller = new AbortController();
  const starting = startPreview(request, controller.signal);
  controller.abort(new Error('cancelled by test'));
  await assert.rejects(starting, /cancelled by test/);
  await eventually(async () => {
    const manifests = await readPreviewManifests({ taskId: request.taskId, previewRoot: request.previewRoot });
    return manifests.length > 0 && manifests.every((manifest) => ['stopped', 'failed'].includes(manifest.state));
  });
  assert.equal(await statusPreview({ taskId: request.taskId, previewRoot: request.previewRoot }), null);
});

test('refuses oversized pages before generating screenshot artifacts and stops the preview', async (t) => {
  const { request } = await fixture(t);
  const handle = await startPreview(request);
  await assert.rejects(capturePreview(handle, { ...request, config: { ...request.config, paths: ['/tall'] } }), /20000px height/);
  assert.deepEqual(await readdir(request.artifactPath), []);
  assert.equal(await statusPreview({ taskId: request.taskId, previewRoot: request.previewRoot }), null);
});

test('validates path and command configuration without accepting external origins or shell strings', () => {
  for (const route of ['https://example.com', '//example.com', '/%2fexample.com', '/../private', '/%2e%2e/private', '/\\example.com']) {
    assert.throws(() => validatePreviewPath(route), /paths|origin/);
  }
  const base = { enabled: true, driver: 'command', directory: '.', command: ['node', 'server.mjs', '{port}'], paths: ['/'], ttlSeconds: 1800 };
  assert.throws(() => validatePreviewConfig({ ...base, directory: '../outside' }), /directory/);
  assert.throws(() => validatePreviewConfig({ ...base, command: 'npm run dev' }), /argument array/);
  assert.throws(() => validatePreviewConfig({ ...base, command: ['server', '--host=0.0.0.0', '{port}'] }), /public interface/);
  assert.throws(() => validatePreviewConfig({ ...base, command: ['server', '{port}', '{port}'] }), /exactly one/);
});
