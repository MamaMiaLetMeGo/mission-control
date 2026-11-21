import { createHash, randomUUID } from 'node:crypto';
import { mkdir, realpath, rm } from 'node:fs/promises';
import path from 'node:path';
import { createCodexAgent } from './agents.js';
import { git, safeEnvironment } from './process.js';
import { acquireRepositoryLock, addUsage, enforceTokenLimit, repositoryLockTimeoutMs, type RunOptions } from './runner.js';
import { emptyUsage, type Emit, type TokenUsage } from './types.js';
import { codePoints, isWithin, redact } from './validation.js';

export interface PlanLead {
  key: string;
  name: string;
  instructions: string;
}

export interface PlanInput {
  mode: 'plan';
  goalId: number;
  goal: string;
  leads: PlanLead[];
  repositoryPath: string;
  baseBranch: string;
  worktreeRoot: string;
  maxRuntimeSeconds: number;
  maxTokens: number;
  model?: string;
}

export type BriefPriority = 'urgent' | 'high' | 'normal' | 'low';

export interface Brief {
  lead: string;
  title: string;
  request: string;
  acceptanceCriteria: string;
  priority: BriefPriority;
  /** 1-based indexes of earlier briefs that must be merged first. */
  after: number[];
}

export interface PlanResult {
  type: 'plan';
  success: boolean;
  overview: string;
  briefs: Brief[];
  usage: TokenUsage;
  error?: string;
}

const priorities: BriefPriority[] = ['urgent', 'high', 'normal', 'low'];

const planSchema = {
  type: 'object',
  properties: {
    overview: { type: 'string' },
    briefs: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          lead: { type: 'string' },
          title: { type: 'string' },
          request: { type: 'string' },
          acceptanceCriteria: { type: 'string' },
          priority: { type: 'string', enum: priorities },
          after: { type: 'array', items: { type: 'integer' } },
        },
        required: ['lead', 'title', 'request', 'acceptanceCriteria', 'priority', 'after'],
        additionalProperties: false,
      },
    },
  },
  required: ['overview', 'briefs'],
  additionalProperties: false,
};

export function validatePlanInput(value: unknown): PlanInput {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    throw new Error('The worker expects a JSON plan object.');
  }
  const input = value as Record<string, unknown>;
  if (input.mode !== 'plan') {
    throw new Error('mode must be "plan".');
  }
  if (!Number.isSafeInteger(input.goalId) || (input.goalId as number) < 1) {
    throw new Error('goalId must be a positive integer.');
  }
  if (typeof input.goal !== 'string' || !input.goal.trim() || codePoints(input.goal) > 5000 || input.goal.includes('\0')) {
    throw new Error('A non-empty goal of at most 5000 characters is required.');
  }
  if (!Array.isArray(input.leads) || input.leads.length < 1 || input.leads.length > 20) {
    throw new Error('leads must list between 1 and 20 leads.');
  }
  for (const lead of input.leads as unknown[]) {
    const entry = lead as Record<string, unknown>;
    if (!entry || typeof entry !== 'object' || Array.isArray(entry)
      || ['key', 'name', 'instructions'].some((name) => typeof entry[name] !== 'string' || !(entry[name] as string).trim() || (entry[name] as string).includes('\0'))) {
      throw new Error('Each lead needs a key, name and instructions.');
    }
  }
  for (const name of ['repositoryPath', 'baseBranch', 'worktreeRoot']) {
    if (typeof input[name] !== 'string' || !input[name].trim() || input[name].includes('\0')) {
      throw new Error(`A non-empty ${name} is required.`);
    }
  }
  for (const name of ['repositoryPath', 'worktreeRoot']) {
    if (!path.isAbsolute(input[name] as string)) {
      throw new Error(`${name} must be an absolute local path.`);
    }
  }
  if ((input.baseBranch as string).startsWith('-')) {
    throw new Error('baseBranch cannot start with a dash.');
  }
  if (!Number.isSafeInteger(input.maxRuntimeSeconds) || (input.maxRuntimeSeconds as number) < 10 || (input.maxRuntimeSeconds as number) > 3600) {
    throw new Error('maxRuntimeSeconds must be between 10 and 3600.');
  }
  if (!Number.isSafeInteger(input.maxTokens) || (input.maxTokens as number) < 1 || (input.maxTokens as number) > 1000000) {
    throw new Error('maxTokens must be between 1 and 1000000.');
  }
  if (input.model !== undefined && (typeof input.model !== 'string' || input.model.includes('\0'))) {
    throw new Error('model must be a string.');
  }
  return input as unknown as PlanInput;
}

function boundedText(value: unknown, name: string, max: number): string {
  if (typeof value !== 'string' || !value.trim() || codePoints(value) > max || value.includes('\0')) {
    throw new Error(`${name} must be 1 to ${max} characters`);
  }
  return value;
}

/**
 * Checks the planning agent's JSON against the limits the dashboard enforces, so a plan that is accepted here can be stored as-is.
 */
export function validatePlan(value: unknown, leadKeys: string[]): { overview: string; briefs: Brief[] } {
  try {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
      throw new Error('the response is not a plan object');
    }
    const plan = value as Record<string, unknown>;
    const overview = boundedText(plan.overview, 'the overview', 4000);
    if (!Array.isArray(plan.briefs) || plan.briefs.length < 1 || plan.briefs.length > 8) {
      throw new Error('the plan must contain between 1 and 8 briefs');
    }
    const briefs = plan.briefs.map((entry: unknown, index: number): Brief => {
      const position = index + 1;
      if (!entry || typeof entry !== 'object' || Array.isArray(entry)) {
        throw new Error(`brief ${position} is not an object`);
      }
      const brief = entry as Record<string, unknown>;
      if (typeof brief.lead !== 'string' || !leadKeys.includes(brief.lead)) {
        throw new Error(`brief ${position} names a lead that is not available`);
      }
      const title = boundedText(brief.title, `the title of brief ${position}`, 200);
      const request = boundedText(brief.request, `the request of brief ${position}`, 20000);
      const acceptanceCriteria = boundedText(brief.acceptanceCriteria, `the acceptance criteria of brief ${position}`, 10000);
      if (typeof brief.priority !== 'string' || !priorities.includes(brief.priority as BriefPriority)) {
        throw new Error(`brief ${position} has an unknown priority`);
      }
      if (!Array.isArray(brief.after)
        || brief.after.some((after: unknown) => !Number.isSafeInteger(after) || (after as number) < 1 || (after as number) >= position)
        || new Set(brief.after).size !== brief.after.length) {
        throw new Error(`brief ${position} must only follow earlier briefs, each once`);
      }
      return { lead: brief.lead, title, request, acceptanceCriteria, priority: brief.priority as BriefPriority, after: brief.after as number[] };
    });
    return { overview, briefs };
  } catch (error) {
    throw new Error(`The Chief of Staff returned a plan that could not be used: ${(error instanceof Error ? error.message : String(error)).replace(/\.$/, '')}.`);
  }
}

function planningPrompt(input: PlanInput): string {
  return [
    'You are the Chief of Staff for Mission Control. Split the goal below into briefs for the leads listed.',
    'Read the repository to ground each brief in the code that exists. Do not modify files.',
    'Write between 1 and 8 briefs. Each brief must be a bounded, reviewable change that one sub-agent can finish alone, with concrete acceptance criteria.',
    'Give each brief to the lead whose instructions fit it best, using the lead key exactly as listed. Use "after" only when a brief cannot be built until an earlier brief is merged; list the 1-based numbers of those earlier briefs.',
    'The overview is two to five sentences for the human owner: what will be done, in what order, and any risk.',
    `Leads:\n${input.leads.map((lead) => `- ${lead.key} (${lead.name}): ${lead.instructions}`).join('\n')}`,
    `Goal:\n${input.goal}`,
  ].join('\n\n');
}

/**
 * Turns a goal into briefs with one read-only agent working in a throwaway worktree. Never throws; failures become `success: false`.
 */
export async function runPlanning(input: PlanInput, options: RunOptions = {}): Promise<PlanResult> {
  const result: PlanResult = { type: 'plan', success: false, overview: '', briefs: [], usage: emptyUsage() };
  const secret = options.apiKey || '';
  const emit: Emit = (event) => options.emit?.(JSON.parse(redact(JSON.stringify(event), secret)));
  const lockTimeoutMs = options.repositoryLockTimeoutMs ?? repositoryLockTimeoutMs;
  let worktreePath = '';
  let repositoryPath = '';
  let lockPath = '';
  let environment: Record<string, string> = {};
  try {
    validatePlanInput(input);
    if (!options.agent && !secret.trim()) {
      throw new Error('OPENAI_API_KEY is missing. Add it to the app environment before running a task.');
    }
    const timeoutSignal = AbortSignal.timeout(input.maxRuntimeSeconds * 1000);
    const signal = options.signal ? AbortSignal.any([options.signal, timeoutSignal]) : timeoutSignal;
    repositoryPath = await realpath(input.repositoryPath);
    await mkdir(input.worktreeRoot, { recursive: true, mode: 0o700 });
    const worktreeRoot = await realpath(input.worktreeRoot);
    if (isWithin(worktreeRoot, repositoryPath)) {
      throw new Error('Worktrees must be stored outside the source repository.');
    }
    const runtimeHome = path.join(worktreeRoot, '.runtime');
    await mkdir(path.join(runtimeHome, '.codex'), { recursive: true, mode: 0o700 });
    environment = safeEnvironment(runtimeHome);
    environment.CODEX_HOME = path.join(runtimeHome, '.codex');
    const sourceRoot = await realpath(await git(['rev-parse', '--show-toplevel'], repositoryPath, environment, signal));
    if (sourceRoot !== repositoryPath) {
      throw new Error('repositoryPath must be the root of a Git working tree.');
    }
    await git(['check-ref-format', '--branch', input.baseBranch], repositoryPath, environment, signal);
    const commonDirectory = await realpath(path.resolve(repositoryPath, await git(['rev-parse', '--git-common-dir'], repositoryPath, environment, signal)));
    const lockRoot = path.join(worktreeRoot, '.locks');
    await mkdir(lockRoot, { recursive: true, mode: 0o700 });
    lockPath = path.join(lockRoot, `repo-${createHash('sha256').update(commonDirectory).digest('hex')}`);
    const baseRevision = await git(['rev-parse', '--verify', '--end-of-options', `refs/heads/${input.baseBranch}^{commit}`], repositoryPath, environment, signal);
    const created = path.join(worktreeRoot, `plan-${input.goalId}-${randomUUID()}`);
    emit({ type: 'phase', role: 'planning', message: 'The Chief of Staff is reading the repository and writing briefs.' });
    worktreePath = created;
    await withRepositoryLock(lockPath, lockTimeoutMs, signal, () => git(['worktree', 'add', '--detach', created, baseRevision], repositoryPath, environment, signal));
    const agent = options.agent || createCodexAgent(secret, runtimeHome, emit);
    const planning = await agent({
      role: 'planning', workingDirectory: worktreePath, model: input.model, signal, outputSchema: planSchema, prompt: planningPrompt(input),
    });
    addUsage(result.usage, planning.usage);
    enforceTokenLimit(result.usage, input.maxTokens);
    let parsed: unknown;
    try {
      parsed = JSON.parse(redact(planning.response, secret));
    } catch {
      throw new Error('The Chief of Staff returned a plan that could not be used: the response is not valid JSON.');
    }
    const plan = validatePlan(parsed, input.leads.map((lead) => lead.key));
    result.overview = plan.overview;
    result.briefs = plan.briefs;
    result.success = true;
  } catch (error) {
    result.success = false;
    result.overview = '';
    result.briefs = [];
    result.error = redact(error instanceof Error ? error.message : String(error), secret);
  } finally {
    if (worktreePath) {
      const cleanupSignal = new AbortController().signal;
      try {
        await withRepositoryLock(lockPath, lockTimeoutMs, cleanupSignal, () => git(['worktree', 'remove', '--force', worktreePath], repositoryPath, environment));
      } catch {
        // The worktree may be half-created (the run was aborted during `git worktree add`) or the lock may have timed out; the fallback below deletes the directory and prunes its record.
      }
      await rm(worktreePath, { recursive: true, force: true });
      try {
        await withRepositoryLock(lockPath, lockTimeoutMs, cleanupSignal, () => git(['worktree', 'prune'], repositoryPath, environment));
      } catch {
        // A failed prune only leaves a stale record that the next prune clears.
      }
    }
  }
  return result;
}

async function withRepositoryLock<T>(lockPath: string, timeoutMs: number, signal: AbortSignal, action: () => Promise<T>): Promise<T> {
  const held = await acquireRepositoryLock(lockPath, timeoutMs, signal);
  try {
    return await action();
  } finally {
    await rm(held, { recursive: true, force: true });
  }
}
