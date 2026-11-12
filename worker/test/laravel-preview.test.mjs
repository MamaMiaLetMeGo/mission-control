import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { prepareLaravelPreview } from '../dist/laravel-preview.js';
import { command, safeEnvironment } from '../dist/process.js';

async function fixture(t) {
  const root = await mkdtemp(path.join(path.resolve('test'), '.fixture-laravel-'));
  const repositoryPath = path.join(root, 'repository');
  const snapshotPath = path.join(root, 'snapshot');
  const runtimePath = path.join(root, 'runtime');
  const bin = path.join(root, 'bin');
  const application = path.join(snapshotPath, 'backend');
  await Promise.all([
    mkdir(path.join(repositoryPath, 'backend/vendor'), { recursive: true }),
    mkdir(application, { recursive: true }), mkdir(runtimePath), mkdir(bin),
  ]);
  await Promise.all([
    writeFile(path.join(repositoryPath, 'backend/composer.lock'), '{}'),
    writeFile(path.join(application, 'composer.lock'), '{}'),
    writeFile(path.join(repositoryPath, 'backend/vendor/autoload.php'), '<?php'),
    writeFile(path.join(application, 'artisan'), '<?php'),
  ]);
  const executable = async (name, code) => writeFile(path.join(bin, name), `#!/usr/bin/env node\n${code}\n`, { mode: 0o755 });
  await executable('composer', 'process.exit(0);');
  await executable('php', 'process.exit(0);');
  const originalPath = process.env.PATH;
  process.env.PATH = `${bin}${path.delimiter}${originalPath}`;
  t.after(async () => {
    process.env.PATH = originalPath;
    await rm(root, { recursive: true, force: true });
  });
  return { root, application, executable, input: { repositoryPath, snapshotPath, runtimePath, directory: 'backend' } };
}

test('Laravel preparation allows snapshot/runtime writes but denies writes outside the sandbox', async (t) => {
  const { root, application, executable, input } = await fixture(t);
  const outside = path.join(root, 'outside.txt');
  await executable('php', `import fs from 'node:fs';
if (process.argv.includes('migrate')) {
  fs.writeFileSync('inside.txt', 'snapshot');
  fs.writeFileSync(${JSON.stringify(path.join(input.runtimePath, 'inside.txt'))}, 'runtime');
  try { fs.writeFileSync(${JSON.stringify(outside)}, 'escaped'); }
  catch (error) { fs.writeFileSync(${JSON.stringify(path.join(input.runtimePath, 'denied.txt'))}, error.code); }
}`);

  const prepared = await prepareLaravelPreview(input);
  t.after(prepared.cleanup);

  assert.equal(await readFile(path.join(application, 'inside.txt'), 'utf8'), 'snapshot');
  assert.equal(await readFile(path.join(input.runtimePath, 'inside.txt'), 'utf8'), 'runtime');
  assert.match(await readFile(path.join(input.runtimePath, 'denied.txt'), 'utf8'), /EPERM|EACCES|EROFS/);
  await assert.rejects(readFile(outside), { code: 'ENOENT' });
});

test('cancellation during database creation drops the owned database and removes its ownership record', async (t) => {
  const { executable, input } = await fixture(t);
  await executable('createdb', `import fs from 'node:fs';
fs.writeFileSync('created.txt', process.argv.at(-1));
setInterval(() => {}, 1000);`);
  await executable('dropdb', `import fs from 'node:fs';
fs.writeFileSync('dropped.txt', process.argv.at(-1));
fs.rmSync('created.txt', { force: true });`);
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(new Error('fixture timeout')), 10000);
  let preparationError;
  const preparing = prepareLaravelPreview({
    ...input, database: { driver: 'pgsql', host: '/tmp', port: 5432, username: 'fixture' }, signal: controller.signal,
  }).catch((error) => { preparationError = error; });
  let created;
  try {
    while (!controller.signal.aborted && !preparationError) {
      try { created = await readFile(path.join(input.runtimePath, 'created.txt'), 'utf8'); break; }
      catch (error) { if (error.code !== 'ENOENT') throw error; }
      await delay(20);
    }
  } finally {
    clearTimeout(timeout);
    controller.abort(new Error('database creation interrupted'));
  }
  await preparing;

  assert.match(preparationError?.message ?? '', /database creation interrupted/);
  assert.match(created, /^mc_preview_[a-f0-9]{32}$/);
  assert.equal(await readFile(path.join(input.runtimePath, 'dropped.txt'), 'utf8'), created);
  await assert.rejects(readFile(path.join(input.runtimePath, 'database.json')), { code: 'ENOENT' });
  await assert.rejects(readFile(path.join(input.runtimePath, 'created.txt')), { code: 'ENOENT' });
});

test('preview preparation and child PHP processes receive 512M while retaining normal extensions and host limits', async (t) => {
  const describePhp = 'echo json_encode(["limit" => ini_get("memory_limit"), "extensions" => get_loaded_extensions()]);';
  const baselineEnvironment = safeEnvironment(process.cwd());
  const binary = await command(['php', '-r', 'echo PHP_BINARY;'], process.cwd(), baselineEnvironment);
  assert.equal(binary.exitCode, 0);
  const php = binary.output;
  const baseline = await command([php, '-r', describePhp], process.cwd(), baselineEnvironment);
  assert.equal(baseline.exitCode, 0);
  const baselineState = JSON.parse(baseline.output);
  const { executable, input } = await fixture(t);
  await executable('php', `import fs from 'node:fs';
import { spawnSync } from 'node:child_process';
const result = spawnSync(${JSON.stringify(php)}, ['-r', ${JSON.stringify(describePhp)}], { env: process.env });
if (result.status !== 0) { process.stderr.write(result.stderr); process.exit(1); }
fs.writeFileSync(${JSON.stringify(input.runtimePath)} + '/' + process.argv[3] + '.json', result.stdout);`);

  const prepared = await prepareLaravelPreview(input);
  t.after(prepared.cleanup);
  const childScript = `$child = proc_open([PHP_BINARY, "-r", ${JSON.stringify(describePhp)}], [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes); echo stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); exit(proc_close($child));`;
  const child = await command([php, '-r', childScript], input.snapshotPath, prepared.environment);

  assert.equal(child.exitCode, 0, child.output);
  for (const state of [
    JSON.parse(await readFile(path.join(input.runtimePath, 'package:discover.json'), 'utf8')),
    JSON.parse(await readFile(path.join(input.runtimePath, 'migrate.json'), 'utf8')),
    JSON.parse(child.output),
  ]) {
    assert.equal(state.limit, '512M');
    assert.deepEqual(state.extensions.sort(), [...baselineState.extensions].sort());
  }
  const after = await command([php, '-r', describePhp], process.cwd(), baselineEnvironment);
  assert.equal(after.exitCode, 0);
  assert.deepEqual(JSON.parse(after.output), baselineState);
});
