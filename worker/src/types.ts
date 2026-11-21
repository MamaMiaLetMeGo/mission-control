import type { PreviewConfig, PreviewHandle, Screenshot } from './preview.js';
import type { PreviewDatabase } from './laravel-preview.js';

export type Role = 'implementation' | 'review' | 'planning' | 'security' | 'brain';

export interface MissionInput {
  taskId: number;
  title: string;
  request: string;
  acceptanceCriteria: string;
  repositoryPath: string;
  baseBranch: string;
  model?: string;
  testCommand: string[];
  maxRuntimeSeconds: number;
  maxTokens: number;
  worktreeRoot: string;
  artifactRoot: string;
  preview?: PreviewConfig;
  previewRoot?: string;
  previewDatabase?: PreviewDatabase;
  lead?: { name: string; instructions: string };
  /** The request of the goal this task belongs to. */
  goal?: string;
  /** The other tasks under the same goal, shown to the implementation agent. */
  team?: { title: string; lead: string; status: string; summary: string; files: string[] }[];
  /** `refresh` replays `sourceRevision` onto the current base instead of calling the implementation agent. Defaults to `implement`. */
  mode?: 'implement' | 'refresh';
  /** The already-reviewed commit to replay. Full 40-64 character hex hash; required in refresh mode and rejected otherwise. */
  sourceRevision?: string;
  /** The summary of the run that produced `sourceRevision`, reused as the refreshed run's summary. */
  previousSummary?: string;
  /** How many times a sub-agent may fix the reviewer's findings before the run stops. Integer 0-3, defaults to 0; ignored in refresh mode. */
  reviewRounds?: number;
}

export interface TokenUsage {
  input_tokens: number;
  cached_input_tokens: number;
  cache_write_input_tokens: number;
  output_tokens: number;
  reasoning_output_tokens: number;
}

export interface WorkerEvent {
  type: 'phase' | 'thread' | 'usage' | 'message';
  role?: Role;
  stage?: 'coordinate' | 'code' | 'chase' | 'test' | 'security' | 'review';
  message?: string;
  threadId?: string;
  usage?: TokenUsage;
}

export type Emit = (event: WorkerEvent) => void;

export interface Review {
  verdict: 'pass' | 'changes_requested';
  summary: string;
  findings: string[];
}

export interface SecurityFinding {
  severity: 'high' | 'medium' | 'low';
  file: string;
  note: string;
}

export interface SecurityCheck {
  verdict: 'pass' | 'fail';
  summary: string;
  findings: SecurityFinding[];
}

export interface WorkerResult {
  type: 'result';
  success: boolean;
  mode: 'implement' | 'refresh';
  diff: { files: string[]; additions: number; deletions: number };
  branch: string;
  worktreePath: string;
  revision: string;
  baseRevision: string;
  artifactPath: string;
  summary: string;
  review: Review;
  /** How many fix rounds a sub-agent used on the reviewer's findings. */
  reviewRounds: number;
  /** Each earlier `changes_requested` review, oldest first. `review` is always the final one. */
  reviewHistory: Review[];
  tests: { command: string[]; exitCode: number; output: string };
  /** The security check of the final revision: the automatic diff scan plus the read-only security reviewer. */
  security: SecurityCheck;
  usage: TokenUsage;
  browser?: {
    status: 'passed' | 'failed' | 'skipped';
    revision: string;
    baseRevision: string;
    screenshots: Screenshot[];
    error?: string;
  };
  preview?: PreviewHandle | null;
  error?: string;
}

export interface AgentRequest {
  role: Role;
  prompt: string;
  workingDirectory: string;
  model?: string;
  signal: AbortSignal;
  outputSchema?: unknown;
  images?: string[];
  /** Lets the agent run in a directory that is not a Git repository. Only the Brain sets it. */
  skipGitRepoCheck?: boolean;
}

export type AgentRunner = (request: AgentRequest) => Promise<{ response: string; usage: TokenUsage }>;

export const emptyUsage = (): TokenUsage => ({
  input_tokens: 0,
  cached_input_tokens: 0,
  cache_write_input_tokens: 0,
  output_tokens: 0,
  reasoning_output_tokens: 0,
});
