import path from 'node:path';
import type { MissionInput, Review } from './types.js';
import { validatePreviewConfig, type PreviewConfig } from './preview.js';

export function validateInput(value: unknown): MissionInput {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    throw new Error('The worker expects a JSON task object.');
  }
  const input = value as Record<string, unknown>;
  for (const name of ['title', 'request', 'acceptanceCriteria', 'repositoryPath', 'baseBranch', 'worktreeRoot', 'artifactRoot']) {
    if (typeof input[name] !== 'string' || !input[name].trim() || input[name].includes('\0')) {
      throw new Error(`A non-empty ${name} is required.`);
    }
  }
  for (const name of ['repositoryPath', 'worktreeRoot', 'artifactRoot']) {
    if (!path.isAbsolute(input[name] as string)) {
      throw new Error(`${name} must be an absolute local path.`);
    }
  }
  if (!Number.isSafeInteger(input.taskId) || (input.taskId as number) < 1) {
    throw new Error('taskId must be a positive integer.');
  }
  if (!Number.isSafeInteger(input.maxRuntimeSeconds) || (input.maxRuntimeSeconds as number) < 10 || (input.maxRuntimeSeconds as number) > 3600) {
    throw new Error('maxRuntimeSeconds must be between 10 and 3600.');
  }
  if (!Number.isSafeInteger(input.maxTokens) || (input.maxTokens as number) < 1 || (input.maxTokens as number) > 1000000) {
    throw new Error('maxTokens must be between 1 and 1000000.');
  }
  if (!Array.isArray(input.testCommand) || input.testCommand.length === 0 || input.testCommand.length > 100
    || input.testCommand.some((part) => typeof part !== 'string' || part.includes('\0'))
    || !input.testCommand[0]?.trim()) {
    throw new Error('testCommand must be a non-empty argument array, for example ["php", "artisan", "test"].');
  }
  if (typeof input.baseBranch === 'string' && input.baseBranch.startsWith('-')) {
    throw new Error('baseBranch cannot start with a dash.');
  }
  if (input.model !== undefined && (typeof input.model !== 'string' || input.model.includes('\0'))) {
    throw new Error('model must be a string.');
  }
  if (input.preview !== undefined) {
    const preview = input.preview as Record<string, unknown>;
    if (!preview || typeof preview !== 'object' || typeof preview.enabled !== 'boolean') throw new Error('preview.enabled must be a boolean.');
    if (preview.enabled && (typeof input.previewRoot !== 'string' || !path.isAbsolute(input.previewRoot) || input.previewRoot.includes('\0'))) {
      throw new Error('previewRoot must be an absolute local path when screenshots are enabled.');
    }
    if (preview.enabled) validatePreviewConfig(input.preview as PreviewConfig);
  }
  return input as unknown as MissionInput;
}

export function validateReview(value: unknown): Review {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    throw new Error('The reviewer did not return a review object.');
  }
  const review = value as Record<string, unknown>;
  if (!['pass', 'changes_requested'].includes(String(review.verdict))
    || typeof review.summary !== 'string' || !review.summary.trim()
    || !Array.isArray(review.findings) || review.findings.some((finding) => typeof finding !== 'string')) {
    throw new Error('The reviewer returned invalid evidence.');
  }
  if (review.verdict === 'pass' && review.findings.length > 0) {
    throw new Error('The reviewer reported a pass with unresolved findings.');
  }
  return review as unknown as Review;
}

export function redact(value: string, secret = ''): string {
  let output = secret ? value.split(secret).join('[REDACTED]') : value;
  output = output.replace(/\bsk-[A-Za-z0-9_-]{16,}\b/g, '[REDACTED]');
  return output;
}

export function isWithin(candidate: string, parent: string): boolean {
  const relative = path.relative(parent, candidate);
  return relative === '' || (!relative.startsWith(`..${path.sep}`) && relative !== '..' && !path.isAbsolute(relative));
}
