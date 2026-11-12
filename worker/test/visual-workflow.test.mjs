import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { git, safeEnvironment } from '../dist/process.js';
import { runMission } from '../dist/runner.js';
import { stopPreview, statusPreview } from '../dist/preview.js';
import { emptyUsage } from '../dist/types.js';

test('captures both revisions and viewport sizes, gives images to review, and keeps the reviewed preview live', { timeout: 60000 }, async (t) => {
  const root = await mkdtemp(path.join(path.resolve('test'), '.visual-'));
  const source = path.join(root, 'source');
  const home = path.join(root, 'home');
  const previewRoot = path.join(root, 'previews');
  await mkdir(source); await mkdir(home);
  t.after(async () => { await stopPreview({ taskId: 7, previewRoot }); await rm(root, { recursive: true, force: true }); });
  const environment = safeEnvironment(home);
  await writeFile(path.join(source, 'index.html'), '<!doctype html><meta name="viewport" content="width=device-width"><h1>Before the change</h1>');
  await writeFile(path.join(source, 'server.mjs'), "import http from 'node:http'; import {readFileSync} from 'node:fs'; http.createServer((req,res)=>{res.setHeader('Content-Type','text/html');res.end(readFileSync('index.html'));}).listen(Number(process.argv[2]),'127.0.0.1');\n");
  await git(['init', '-b', 'main'], source, environment);
  await git(['add', '.'], source, environment);
  await git(['-c', 'user.name=Test', '-c', 'user.email=test@localhost', 'commit', '--no-gpg-sign', '-m', 'Before'], source, environment);
  let reviewImages = [];
  const result = await runMission({
    taskId: 7, title: 'Change heading', request: 'Update the heading.', acceptanceCriteria: 'Heading is After the change.',
    repositoryPath: source, baseBranch: 'main', testCommand: [process.execPath, '-e', 'process.exit(0)'],
    maxRuntimeSeconds: 50, maxTokens: 1000, worktreeRoot: path.join(root, 'worktrees'), artifactRoot: path.join(root, 'artifacts'), previewRoot,
    preview: { enabled: true, driver: 'command', directory: '.', command: [process.execPath, 'server.mjs', '{port}'], paths: ['/'], ttlSeconds: 60 },
  }, { agent: async (request) => {
    if (request.role === 'implementation') {
      await writeFile(path.join(request.workingDirectory, 'index.html'), '<!doctype html><meta name="viewport" content="width=device-width"><h1>After the change</h1>');
      return { response: 'Updated the heading.', usage: emptyUsage() };
    }
    reviewImages = request.images;
    return { response: JSON.stringify({ verdict: 'pass', summary: 'Fixture reviewer passed.', findings: [] }), usage: emptyUsage() };
  } });
  assert.equal(result.success, true, result.error);
  assert.equal(result.browser.status, 'passed');
  assert.equal(result.browser.screenshots.length, 4);
  assert.equal(reviewImages.length, 4);
  for (const viewport of ['desktop', 'mobile']) {
    const before = result.browser.screenshots.find((image) => image.phase === 'before' && image.viewport === viewport);
    const after = result.browser.screenshots.find((image) => image.phase === 'after' && image.viewport === viewport);
    assert.equal(before.revision, result.baseRevision);
    assert.equal(after.revision, result.revision);
    assert.notEqual(before.sha256, after.sha256);
    assert.equal((await readFile(path.join(result.artifactPath, after.file))).subarray(1,4).toString(), 'PNG');
  }
  assert.equal((await statusPreview({ taskId: 7, previewRoot, revision: result.revision })).id, result.preview.id);
  assert.match(await (await fetch(result.preview.url)).text(), /After the change/);
  assert.match(await readFile(path.join(source, 'index.html'), 'utf8'), /Before the change/);
});
