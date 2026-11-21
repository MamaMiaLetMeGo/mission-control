import path from 'node:path';
import type { SecurityCheck, SecurityFinding } from './types.js';
import { codePoints } from './validation.js';

export type { SecurityCheck, SecurityFinding } from './types.js';

export const invalidSecurityReview = 'The security reviewer returned invalid evidence.';

export const securityReviewSchema = {
  type: 'object',
  properties: {
    verdict: { type: 'string', enum: ['pass', 'fail'] },
    summary: { type: 'string' },
    findings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          severity: { type: 'string', enum: ['high', 'medium', 'low'] },
          file: { type: 'string' },
          note: { type: 'string' },
        },
        required: ['severity', 'file', 'note'],
        additionalProperties: false,
      },
    },
  },
  required: ['verdict', 'summary', 'findings'],
  additionalProperties: false,
};

/** Secret patterns checked on every added line. Notes describe the kind of secret and never quote the match. */
const secretPatterns: { pattern: RegExp; note: string }[] = [
  { pattern: /-----BEGIN [A-Z ]*PRIVATE KEY-----/, note: 'A private key was added.' },
  { pattern: /\bAKIA[0-9A-Z]{16}\b/, note: 'An AWS access key ID was added.' },
  { pattern: /\bgh[pousr]_[A-Za-z0-9]{36,}\b/, note: 'A GitHub token was added.' },
  { pattern: /(api[_-]?key|secret|passw(or)?d|token)["']?\s*[:=]\s*["'][^"'\s]{16,}["']/i, note: 'A hard-coded credential may have been added.' },
];

const dependencyManifests = new Set([
  'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'requirements.txt',
  'Gemfile', 'Gemfile.lock', 'go.mod', 'go.sum', 'Cargo.toml', 'Cargo.lock',
]);

/**
 * Scans a unified diff for secrets on added lines and flags CI and dependency changes among the changed files.
 * Inside a hunk every `+` line is an added line; outside one, `+++ b/<path>` names the file the next hunks belong to.
 * Each file and note is reported once.
 */
export function scanDiff(patch: string, files: string[]): SecurityFinding[] {
  const findings: SecurityFinding[] = [];
  const seen = new Set<string>();
  const add = (finding: SecurityFinding): void => {
    const key = JSON.stringify([finding.file, finding.note]);
    if (!seen.has(key)) {
      seen.add(key);
      findings.push(finding);
    }
  };
  let file = '';
  let inHunk = false;
  for (const line of patch.split('\n')) {
    if (line.startsWith('diff --git ')) {
      inHunk = false;
    } else if (line.startsWith('@@')) {
      inHunk = true;
    } else if (!inHunk && line.startsWith('+++ ')) {
      file = /^\+\+\+ "?b\/(.*?)"?$/.exec(line)?.[1] ?? '';
    } else if (line.startsWith('+')) {
      for (const { pattern, note } of secretPatterns) {
        if (pattern.test(line.slice(1))) {
          add({ severity: 'high', file, note });
        }
      }
    }
  }
  for (const changed of files) {
    if (changed.startsWith('.github/workflows/')) {
      add({ severity: 'medium', file: changed, note: 'A CI workflow changed.' });
    }
    if (dependencyManifests.has(path.posix.basename(changed))) {
      add({ severity: 'medium', file: changed, note: 'Dependencies changed.' });
    }
  }
  return findings;
}

/** Checks the security reviewer's output against the schema and its limits, keeping only the known fields. */
export function validateSecurityReview(value: unknown): SecurityCheck {
  const text = (item: unknown, min: number, max: number): item is string => typeof item === 'string' && item.trim().length >= min && codePoints(item) <= max && !item.includes('\0');
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    throw new Error(invalidSecurityReview);
  }
  const review = value as Record<string, unknown>;
  if ((review.verdict !== 'pass' && review.verdict !== 'fail') || !text(review.summary, 1, 4000)
    || !Array.isArray(review.findings) || review.findings.length > 50) {
    throw new Error(invalidSecurityReview);
  }
  const findings = review.findings.map((item): SecurityFinding => {
    const finding = item as Record<string, unknown>;
    if (!finding || typeof finding !== 'object' || Array.isArray(finding)
      || !['high', 'medium', 'low'].includes(finding.severity as string) || !text(finding.file, 0, 300) || !text(finding.note, 1, 1000)) {
      throw new Error(invalidSecurityReview);
    }
    return { severity: finding.severity as SecurityFinding['severity'], file: finding.file, note: finding.note };
  });
  return { verdict: review.verdict, summary: review.summary, findings };
}

/**
 * Combines the automatic scan with the reviewer's check: the scan findings come first, and any high finding fails the check.
 * When only the scan caused the fail, the summary says so before the reviewer's own summary.
 */
export function finalSecurityCheck(scan: SecurityFinding[], reviewer: SecurityCheck): SecurityCheck {
  const findings = [...scan, ...reviewer.findings];
  const verdict = reviewer.verdict === 'fail' || findings.some((finding) => finding.severity === 'high') ? 'fail' : 'pass';
  const scanForcedFail = reviewer.verdict === 'pass' && scan.some((finding) => finding.severity === 'high');
  return { verdict, summary: scanForcedFail ? `The automatic scan found a high-severity problem. ${reviewer.summary}` : reviewer.summary, findings };
}
