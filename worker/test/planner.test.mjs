import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, stat, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { git, safeEnvironment } from '../dist/process.js';
import { runPlanning, validatePlan, validatePlanInput } from '../dist/planner.js';
import { emptyUsage } from '../dist/types.js';

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
    root, environment, repositoryPath,
    input: {
      mode: 'plan', goalId: 7, goal: 'Add invoicing.',
      leads: [{ key: 'backend', name: 'Backend Lead', instructions: 'Owns PHP.' }, { key: 'frontend', name: 'Frontend Lead', instructions: 'Owns UI.' }],
      repositoryPath, baseBranch: 'main', worktreeRoot: path.join(root, 'worktrees'), maxRuntimeSeconds: 60, maxTokens: 1000,
    },
  };
}

const brief = (overrides = {}) => ({ lead: 'backend', title: 'Add model', request: 'Create the model.', acceptanceCriteria: 'It exists.', priority: 'normal', after: [], ...overrides });
const plan = (briefs = [brief()]) => ({ overview: 'Do it in order.', briefs });
const agentReturning = (value, usage = emptyUsage()) => async () => ({ response: JSON.stringify(value), usage });

async function planWorktrees(repositoryPath, environment) {
  return (await git(['worktree', 'list', '--porcelain'], repositoryPath, environment)).split('\n').filter((line) => line.includes('/plan-'));
}

test('a valid plan round-trips from a read-only planning agent in a temporary worktree', async (t) => {
  const { input, repositoryPath, environment } = await fixture(t);
  const second = brief({ lead: 'frontend', title: 'Add page', priority: 'high', after: [1] });
  const seen = [];
  const events = [];
  const result = await runPlanning(input, { emit: (event) => events.push(event), agent: async (request) => {
    seen.push(request);
    assert.equal((await stat(request.workingDirectory)).isDirectory(), true);
    return { response: JSON.stringify(plan([brief(), second])), usage: emptyUsage() };
  } });
  assert.equal(result.success, true, result.error);
  assert.equal(result.type, 'plan');
  assert.deepEqual(result.briefs, [brief(), second]);
  assert.equal(result.overview, 'Do it in order.');
  assert.equal(seen.length, 1);
  assert.equal(seen[0].role, 'planning');
  assert.notEqual(seen[0].workingDirectory, repositoryPath);
  assert.match(path.basename(seen[0].workingDirectory), /^plan-7-/);
  assert.ok(seen[0].outputSchema);
  assert.match(seen[0].prompt, /You are the Chief of Staff for Mission Control\./);
  assert.match(seen[0].prompt, /- backend \(Backend Lead\): Owns PHP\./);
  assert.match(seen[0].prompt, /Goal:\nAdd invoicing\.$/);
  assert.ok(events.some((event) => event.type === 'phase' && event.role === 'planning' && event.message === 'The Chief of Staff is reading the repository and writing briefs.'));
  await assert.rejects(stat(seen[0].workingDirectory));
  assert.deepEqual(await planWorktrees(repositoryPath, environment), []);
});

test('the planning worktree is removed when the agent throws', async (t) => {
  const { input, repositoryPath, environment } = await fixture(t);
  const result = await runPlanning(input, { agent: async () => { throw new Error('agent crashed'); } });
  assert.equal(result.success, false);
  assert.match(result.error, /agent crashed/);
  assert.deepEqual(result.briefs, []);
  assert.deepEqual(await planWorktrees(repositoryPath, environment), []);
});

for (const [name, value] of [
  ['an unknown lead', plan([brief({ lead: 'ghost' })])],
  ['nine briefs', plan(Array.from({ length: 9 }, () => brief()))],
  ['a brief that comes after itself', plan([brief({ after: [1] })])],
  ['a brief that comes after a later brief', plan([brief({ after: [2] }), brief()])],
  ['no briefs', plan([])],
]) {
  test(`rejects ${name}`, async (t) => {
    const { input } = await fixture(t);
    const result = await runPlanning(input, { agent: agentReturning(value) });
    assert.equal(result.success, false);
    assert.match(result.error, /^The Chief of Staff returned a plan that could not be used: .+\.$/);
    assert.deepEqual(result.briefs, []);
  });
}

test('rejects an unparseable agent response', async (t) => {
  const { input } = await fixture(t);
  const result = await runPlanning(input, { agent: async () => ({ response: 'not json', usage: emptyUsage() }) });
  assert.equal(result.success, false);
  assert.match(result.error, /^The Chief of Staff returned a plan that could not be used: /);
});

test('validatePlan enforces field limits', () => {
  const keys = ['backend'];
  assert.throws(() => validatePlan({ overview: '', briefs: [brief()] }, keys), /could not be used/);
  assert.throws(() => validatePlan(plan([brief({ priority: 'asap' })]), keys), /could not be used/);
  assert.throws(() => validatePlan(plan([brief({ title: 'x'.repeat(201) })]), keys), /could not be used/);
  assert.throws(() => validatePlan(plan([brief({ title: 'bad\0title' })]), keys), /could not be used/);
  assert.throws(() => validatePlan(plan([brief(), brief({ after: [1, 1] })]), keys), /could not be used/);
  assert.throws(() => validatePlan(plan([brief(), brief({ after: [0] })]), keys), /could not be used/);
  assert.equal(validatePlan(plan([brief({ title: '😀'.repeat(200) })]), keys).briefs.length, 1);
});

test('validatePlanInput rejects an oversized goal and an empty leads list', async (t) => {
  const { input } = await fixture(t);
  assert.equal(validatePlanInput(input).goalId, 7);
  assert.throws(() => validatePlanInput({ ...input, goal: 'x'.repeat(5001) }), /goal/);
  assert.throws(() => validatePlanInput({ ...input, leads: [] }), /leads/);
  assert.throws(() => validatePlanInput({ ...input, leads: Array.from({ length: 21 }, (_, i) => ({ key: `k${i}`, name: 'n', instructions: 'i' })) }), /leads/);
});

test('exceeding the token allowance fails the plan', async (t) => {
  const { input, repositoryPath, environment } = await fixture(t);
  const result = await runPlanning(input, { agent: agentReturning(plan(), { ...emptyUsage(), input_tokens: 900, output_tokens: 200 }) });
  assert.equal(result.success, false);
  assert.match(result.error, /token allowance/);
  assert.equal(result.usage.input_tokens, 900);
  assert.deepEqual(await planWorktrees(repositoryPath, environment), []);
});

test('requires an API key when no agent is injected', async (t) => {
  const { input } = await fixture(t);
  const result = await runPlanning(input, { apiKey: '' });
  assert.equal(result.success, false);
  assert.match(result.error, /OPENAI_API_KEY is missing/);
});
