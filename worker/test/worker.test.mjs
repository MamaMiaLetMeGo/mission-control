import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { command, git, safeEnvironment } from '../dist/process.js';
import { runMission } from '../dist/runner.js';
import { emptyUsage } from '../dist/types.js';
import { redact, validateInput } from '../dist/validation.js';

async function fixture(t) {
  const root = await mkdtemp(path.join(path.resolve('test'), '.fixture-'));
  t.after(() => rm(root, { recursive: true, force: true }));
  const repositoryPath = path.join(root, 'source');
  const home = path.join(root, 'home');
  await mkdir(repositoryPath);
  await mkdir(home);
  const environment = safeEnvironment(home);
  await git(['init', '-b', 'main'], repositoryPath, environment);
  await writeFile(path.join(repositoryPath, 'original.txt'), 'committed content\n');
  await git(['add', '.'], repositoryPath, environment);
  await git(['-c', 'user.name=Test', '-c', 'user.email=test@localhost', 'commit', '--no-gpg-sign', '-m', 'Initial'], repositoryPath, environment);
  return {
    root, environment,
    input: {
      taskId: 1, title: 'Add a feature', request: 'Create feature.txt.', acceptanceCriteria: 'The file contains implemented.',
      repositoryPath, baseBranch: 'main', testCommand: [process.execPath, '-e', 'process.stdout.write("tests passed")'],
      maxRuntimeSeconds: 60, maxTokens: 1000, worktreeRoot: path.join(root, 'worktrees'), artifactRoot: path.join(root, 'artifacts'),
    },
  };
}

function successfulAgent(overrides = {}) {
  return async (request) => {
    if (request.role === 'implementation') {
      await writeFile(path.join(request.workingDirectory, 'feature.txt'), 'implemented  \n\n');
      return { response: 'Implemented the feature.', usage: emptyUsage(), ...overrides.implementation };
    }
    return { response: JSON.stringify({ verdict: 'pass', summary: 'The change meets the criteria.', findings: [] }), usage: emptyUsage(), ...overrides.review };
  };
}

test('creates a tested review revision without altering dirty source files or the source branch', async (t) => {
  const { input, environment } = await fixture(t);
  await writeFile(path.join(input.repositoryPath, 'original.txt'), 'uncommitted user work\n');
  const sourceRevision = await git(['rev-parse', 'HEAD'], input.repositoryPath, environment);
  const result = await runMission(input, { agent: successfulAgent() });
  assert.equal(result.success, true, `${result.error}\n${result.tests.output}`);
  assert.equal(result.baseRevision, sourceRevision);
  assert.notEqual(result.revision, sourceRevision);
  assert.equal(result.tests.exitCode, 0);
  assert.match(result.tests.output, /tests passed/);
  assert.equal(await readFile(path.join(input.repositoryPath, 'original.txt'), 'utf8'), 'uncommitted user work\n');
  assert.equal(await git(['rev-parse', 'HEAD'], input.repositoryPath, environment), sourceRevision);
  assert.equal(await git(['branch', '--show-current'], input.repositoryPath, environment), 'main');
  assert.equal(await readFile(path.join(result.worktreePath, 'original.txt'), 'utf8'), 'committed content\n');
  assert.match(await readFile(path.join(result.artifactPath, 'diff.patch'), 'utf8'), /feature\.txt/);
  assert.equal(
    await readFile(path.join(result.artifactPath, 'diff.patch'), 'utf8'),
    await git(['diff', '--binary', result.baseRevision, result.revision], result.worktreePath, environment, undefined, 65536, false),
  );
  assert.equal(JSON.parse(await readFile(path.join(result.artifactPath, 'review.json'), 'utf8')).verdict, 'pass');
  assert.deepEqual(await readdir(path.join(input.worktreeRoot, '.locks')), []);
});

test('retains failed test evidence and never requests a passing review after test failure', async (t) => {
  const { input } = await fixture(t);
  input.testCommand = [process.execPath, '-e', 'process.stderr.write("missing dependency"); process.exit(7)'];
  let reviews = 0;
  const base = successfulAgent();
  const result = await runMission(input, { agent: async (request) => {
    if (request.role === 'review') reviews++;
    return base(request);
  } });
  assert.equal(result.success, false);
  assert.equal(result.tests.exitCode, 7);
  assert.equal(reviews, 0);
  assert.match(result.error, /tests failed/);
  assert.match(await readFile(path.join(result.artifactPath, 'tests.txt'), 'utf8'), /missing dependency/);
  assert.match(result.revision, /^[a-f0-9]{40,64}$/);
});

test('rejects a worktree changed during independent review', async (t) => {
  const { input } = await fixture(t);
  const base = successfulAgent();
  const result = await runMission(input, { agent: async (request) => {
    if (request.role === 'review') await writeFile(path.join(request.workingDirectory, 'feature.txt'), 'changed after tests\n');
    return base(request);
  } });
  assert.equal(result.success, false);
  assert.match(result.error, /changed after its review revision/);
});

test('refuses to commit when an implementation switches branches without changing the commit hash', async (t) => {
  const { input, environment } = await fixture(t);
  const base = successfulAgent();
  const result = await runMission(input, { agent: async (request) => {
    await git(['checkout', '-b', 'unexpected-branch'], request.workingDirectory, environment);
    return base(request);
  } });
  assert.equal(result.success, false);
  assert.match(result.error, /switched branches/);
  assert.equal(result.revision, '');
  assert.equal(await git(['rev-parse', result.branch], input.repositoryPath, environment), result.baseRevision);
});

test('refuses a reviewer pass that still includes unresolved findings', async (t) => {
  const { input } = await fixture(t);
  const result = await runMission(input, { agent: successfulAgent({ review: {
    response: JSON.stringify({ verdict: 'pass', summary: 'Almost ready.', findings: ['The acceptance criterion is missing.'] }),
  } }) });
  assert.equal(result.success, false);
  assert.match(result.error, /pass with unresolved findings/);
});

test('a failed review remains a non-successful result with its findings preserved', async (t) => {
  const { input } = await fixture(t);
  const result = await runMission(input, { agent: successfulAgent({ review: {
    response: JSON.stringify({ verdict: 'changes_requested', summary: 'Fix the edge case.', findings: ['Empty input is not handled.'] }),
  } }) });
  assert.equal(result.success, false);
  assert.equal(result.review.verdict, 'changes_requested');
  assert.deepEqual(result.review.findings, ['Empty input is not handled.']);
});

test('requires an explicit API key and does not fall back to personal Codex authentication', async (t) => {
  const { input } = await fixture(t);
  const result = await runMission(input, { apiKey: '' });
  assert.equal(result.success, false);
  assert.match(result.error, /OPENAI_API_KEY is missing/);
  assert.equal(result.worktreePath, '');
});

test('serializes concurrent work on one repository and releases the lock on completion', async (t) => {
  const { input } = await fixture(t);
  let enterImplementation;
  const entered = new Promise((resolve) => { enterImplementation = resolve; });
  let resumeImplementation;
  const resume = new Promise((resolve) => { resumeImplementation = resolve; });
  const base = successfulAgent();
  const first = runMission(input, { agent: async (request) => {
    if (request.role === 'implementation') {
      enterImplementation();
      await resume;
    }
    return base(request);
  } });
  await entered;
  const second = await runMission({ ...input, taskId: 2 }, { agent: successfulAgent() });
  assert.equal(second.success, false);
  assert.match(second.error, /active worker lock/);
  resumeImplementation();
  assert.equal((await first).success, true);
  assert.deepEqual(await readdir(path.join(input.worktreeRoot, '.locks')), []);
});

test('stops before the next agent when a completed turn exceeds the soft token allowance', async (t) => {
  const { input } = await fixture(t);
  input.maxTokens = 20;
  let calls = 0;
  const base = successfulAgent({ implementation: { usage: { ...emptyUsage(), input_tokens: 21 } } });
  const result = await runMission(input, { agent: async (request) => { calls++; return base(request); } });
  assert.equal(result.success, false);
  assert.equal(calls, 1);
  assert.equal(result.usage.input_tokens, 21);
  assert.match(result.error, /soft limit, not a billing ceiling/);
});

test('runs test code inside a sandbox that cannot modify the original checkout', async (t) => {
  const { input } = await fixture(t);
  input.testCommand = [process.execPath, '-e', `require('node:fs').writeFileSync(${JSON.stringify(path.join(input.repositoryPath, 'original.txt'))}, 'unauthorized')`];
  const result = await runMission(input, { agent: successfulAgent() });
  assert.equal(result.success, false);
  assert.notEqual(result.tests.exitCode, 0);
  assert.equal(await readFile(path.join(input.repositoryPath, 'original.txt'), 'utf8'), 'committed content\n');
});

test('does not commit or publish a proposed API key leak', async (t) => {
  const { input } = await fixture(t);
  const fakeSecret = 'fake-test-credential-do-not-use';
  const result = await runMission(input, { apiKey: fakeSecret, agent: async (request) => {
    await writeFile(path.join(request.workingDirectory, 'leak.txt'), fakeSecret);
    return { response: 'Done.', usage: emptyUsage() };
  } });
  assert.equal(result.success, false);
  assert.match(result.error, /contains the API key/);
  assert.equal(result.revision, '');
  assert.ok(!(await readFile(path.join(result.artifactPath, 'result.json'), 'utf8')).includes(fakeSecret));
  assert.ok(!(await readdir(result.artifactPath)).includes('diff.patch'));
});

test('rejects shell-string configuration and relative output paths', async (t) => {
  const { input } = await fixture(t);
  assert.throws(() => validateInput({ ...input, testCommand: 'npm test; echo wrong' }), /argument array/);
  assert.throws(() => validateInput({ ...input, worktreeRoot: '../outside' }), /absolute local path/);
  assert.throws(() => validateInput({ ...input, baseBranch: '--help' }), /dash/);
});

test('strips credentials from child environments and log messages', () => {
  const environment = safeEnvironment('/tmp/isolated-home');
  assert.equal(environment.OPENAI_API_KEY, undefined);
  assert.equal(environment.CODEX_API_KEY, undefined);
  assert.equal(environment.HOME, '/tmp/isolated-home');
  assert.equal(redact('failure secret-value', 'secret-value'), 'failure [REDACTED]');
  assert.equal(redact('sk-proj-abcdefghijklmnopqrstuvwxyz'), '[REDACTED]');
  const taskPath = '/storage/mission-control/worktrees/task-3-23f40236-56db-4dcb-a588-8770f8f2251a';
  assert.equal(redact(`${taskPath} sk-proj-abcdefghijklmnopqrstuvwxyz`), `${taskPath} [REDACTED]`);
});

test('aborting a command stops its process and rejects without a success result', async (t) => {
  const { input, environment } = await fixture(t);
  const controller = new AbortController();
  const running = command([process.execPath, '-e', 'setInterval(() => {}, 10000)'], input.repositoryPath, environment, controller.signal);
  controller.abort(new Error('test cancellation'));
  await assert.rejects(running, /test cancellation/);
});
