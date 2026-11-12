import type { PreviewConfig, PreviewHandle, Screenshot } from './preview.js';
import type { PreviewDatabase } from './laravel-preview.js';

export type Role = 'implementation' | 'review';

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

export interface WorkerResult {
  type: 'result';
  success: boolean;
  branch: string;
  worktreePath: string;
  revision: string;
  baseRevision: string;
  artifactPath: string;
  summary: string;
  review: Review;
  tests: { command: string[]; exitCode: number; output: string };
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
}

export type AgentRunner = (request: AgentRequest) => Promise<{ response: string; usage: TokenUsage }>;

export const emptyUsage = (): TokenUsage => ({
  input_tokens: 0,
  cached_input_tokens: 0,
  cache_write_input_tokens: 0,
  output_tokens: 0,
  reasoning_output_tokens: 0,
});
