import test from 'node:test';
import assert from 'node:assert/strict';
import { scanDiff, validateSecurityReview } from '../dist/security.js';

const patchFor = (file, lines) => [
  `diff --git a/${file} b/${file}`,
  'index 0000000..1111111 100644',
  `--- a/${file}`,
  `+++ b/${file}`,
  '@@ -1,2 +1,2 @@',
  ...lines,
  '',
].join('\n');

/** Each entry: an added line, the secret inside it, and the note the scan must give. */
const secrets = [
  ['-----BEGIN RSA PRIVATE KEY-----', 'RSA PRIVATE KEY', 'A private key was added.'],
  ['-----BEGIN PRIVATE KEY-----', 'PRIVATE KEY', 'A private key was added.'],
  ['const id = "AKIAABCDEFGHIJKLMNOP";', 'AKIAABCDEFGHIJKLMNOP', 'An AWS access key ID was added.'],
  [`token: ghp_${'a'.repeat(36)}`, `ghp_${'a'.repeat(36)}`, 'A GitHub token was added.'],
  [`const gh = "gho_${'B1'.repeat(20)}";`, `gho_${'B1'.repeat(20)}`, 'A GitHub token was added.'],
  ['API_KEY = "abcdefghijklmnop1234"', 'abcdefghijklmnop1234', 'A hard-coded credential may have been added.'],
  ["'password': 'correct-horse-battery-staple',", 'correct-horse-battery-staple', 'A hard-coded credential may have been added.'],
  ['Secret="0123456789abcdefXYZ"', '0123456789abcdefXYZ', 'A hard-coded credential may have been added.'],
];

for (const [line, secret, note] of secrets) {
  test(`an added line with a secret is a high finding: ${note}`, () => {
    const findings = scanDiff(patchFor('src/config.js', [' context', `+${line}`]), ['src/config.js']);
    assert.deepEqual(findings, [{ severity: 'high', file: 'src/config.js', note }]);
    assert.ok(!JSON.stringify(findings).includes(secret), 'the note must not quote the secret');
  });

  test(`a removed or context line with a secret is ignored: ${note}`, () => {
    assert.deepEqual(scanDiff(patchFor('src/config.js', [`-${line}`, ` ${line}`, '+harmless']), ['src/config.js']), []);
  });
}

test('findings name the file each added line belongs to and are reported once per file and note', () => {
  const key = 'AKIAABCDEFGHIJKLMNOP';
  const patch = patchFor('a.txt', [`+${key}`, `+${key.replace('P', 'Q')}`]) + patchFor('b.txt', [`+${key}`]);
  assert.deepEqual(scanDiff(patch, ['a.txt', 'b.txt']), [
    { severity: 'high', file: 'a.txt', note: 'An AWS access key ID was added.' },
    { severity: 'high', file: 'b.txt', note: 'An AWS access key ID was added.' },
  ]);
});

test('short or unquoted credential-looking values are not flagged', () => {
  assert.deepEqual(scanDiff(patchFor('a.php', ['+$token = "short";', '+password: $request->input("password")']), ['a.php']), []);
});

test('CI workflows and dependency manifests are medium findings', () => {
  const files = ['.github/workflows/ci.yml', 'composer.json', 'worker/package-lock.json', 'Cargo.toml', 'docs/package.json.md', 'src/app.ts'];
  assert.deepEqual(scanDiff('', files), [
    { severity: 'medium', file: '.github/workflows/ci.yml', note: 'A CI workflow changed.' },
    { severity: 'medium', file: 'composer.json', note: 'Dependencies changed.' },
    { severity: 'medium', file: 'worker/package-lock.json', note: 'Dependencies changed.' },
    { severity: 'medium', file: 'Cargo.toml', note: 'Dependencies changed.' },
  ]);
});

test('a clean diff has no findings', () => {
  assert.deepEqual(scanDiff(patchFor('feature.txt', ['-old', '+implemented']), ['feature.txt']), []);
});

test('a valid security review is returned with only the known fields', () => {
  assert.deepEqual(
    validateSecurityReview({ verdict: 'fail', summary: 'SQL injection.', findings: [{ severity: 'high', file: 'a.php', note: 'Unescaped input.', extra: 1 }] }),
    { verdict: 'fail', summary: 'SQL injection.', findings: [{ severity: 'high', file: 'a.php', note: 'Unescaped input.' }] },
  );
  assert.deepEqual(validateSecurityReview({ verdict: 'pass', summary: 'Fine.', findings: [] }), { verdict: 'pass', summary: 'Fine.', findings: [] });
});

test('an invalid security review is refused with the pinned message', () => {
  const finding = { severity: 'low', file: 'a.php', note: 'Look.' };
  for (const value of [
    null, [], 'pass',
    { verdict: 'changes_requested', summary: 'x', findings: [] },
    { verdict: 'pass', summary: ' ', findings: [] },
    { verdict: 'pass', summary: 'x'.repeat(4001), findings: [] },
    { verdict: 'pass', summary: 'x', findings: 'none' },
    { verdict: 'pass', summary: 'x', findings: Array(51).fill(finding) },
    { verdict: 'pass', summary: 'x', findings: [{ ...finding, severity: 'critical' }] },
    { verdict: 'pass', summary: 'x', findings: [{ ...finding, file: 'f'.repeat(301) }] },
    { verdict: 'pass', summary: 'x', findings: [{ ...finding, note: 'n'.repeat(1001) }] },
    { verdict: 'pass', summary: 'x', findings: ['a string'] },
  ]) {
    assert.throws(() => validateSecurityReview(value), { message: 'The security reviewer returned invalid evidence.' });
  }
  assert.doesNotThrow(() => validateSecurityReview({ verdict: 'pass', summary: 'x'.repeat(4000), findings: Array(50).fill(finding) }));
});
