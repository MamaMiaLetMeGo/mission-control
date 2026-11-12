<?php

namespace App\Services;

use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

class ProductionVerification
{
    public function __construct(private GitRepository $git) {}

    /** @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public function read(string $repository, array $plan, string $file, ?string $digest = null): array
    {
        $root = realpath(config('mission.production_verification_root'));
        $path = ProductionReleasePlan::isPath($file) && $root ? realpath($root.'/'.$file) : false;
        if (! $path || ! is_file($path) || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || filesize($path) > 1_000_000) {
            $this->reject('Choose a verification receipt inside the local verification directory.');
        }
        $hash = hash_file('sha256', $path);
        if ($digest !== null && ! hash_equals($digest, $hash)) {
            $this->reject('The verification receipt changed after it was imported.');
        }
        $receipt = json_decode(file_get_contents($path), true);
        if (! is_array($receipt) || ($receipt['version'] ?? null) !== 1 || ($receipt['planHash'] ?? null) !== $plan['hash'] || ($receipt['revision'] ?? null) !== $plan['release_revision'] || ($receipt['tree'] ?? null) !== $plan['tree_revision'] || ! is_array($receipt['checks'] ?? null) || ! is_string($receipt['completedAt'] ?? null)) {
            $this->reject('Verification must identify this exact plan, release revision, and source tree.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $receipt['completedAt'])) {
            $this->reject('Verification completion must be an ISO timestamp.');
        }
        try {
            $completed = new DateTimeImmutable($receipt['completedAt']);
        } catch (\Exception) {
            $this->reject('Verification completion must be a valid timestamp.');
        }
        $worktree = is_string($receipt['worktreePath'] ?? null) ? realpath($receipt['worktreePath']) : false;
        if (! $worktree || $this->git->command($worktree, ['rev-parse', 'HEAD']) !== $plan['release_revision'] || $this->git->command($worktree, ['rev-parse', 'HEAD^{tree}']) !== $plan['tree_revision'] || $this->git->command($worktree, ['status', '--porcelain']) !== '' || realpath($this->git->command($worktree, ['rev-parse', '--path-format=absolute', '--git-common-dir'])) !== realpath($this->git->command($repository, ['rev-parse', '--path-format=absolute', '--git-common-dir']))) {
            $this->reject('The verified release worktree is missing, dirty, or no longer at the pinned revision.');
        }
        $required = config('mission.production_required_checks');
        $seen = [];
        $checks = [];
        foreach ($receipt['checks'] as $check) {
            if (! is_array($check)) {
                $this->reject('Every verification check must be a structured result.');
            }
            $name = $check['name'] ?? null;
            if (! is_string($name) || isset($seen[$name]) || ($check['exitCode'] ?? null) !== 0 || ! is_array($check['command'] ?? null) || ! array_is_list($check['command']) || $check['command'] === [] || count(array_filter($check['command'], 'is_string')) !== count($check['command']) || ! is_string($check['directory'] ?? null)) {
                $this->reject('Every verification check needs a unique name and successful recorded command.');
            }
            $seen[$name] = true;
            if (isset($required[$name]) && ($required[$name]['command'] !== $check['command'] || $required[$name]['directory'] !== $check['directory'])) {
                $this->reject('A required check command does not match the production verification requirements.');
            }
            $outputFile = $check['outputFile'] ?? '';
            $output = is_string($outputFile) && ProductionReleasePlan::isPath($outputFile) ? realpath(dirname($path).'/'.$outputFile) : false;
            $outputHash = $check['outputSha256'] ?? '';
            if (! $output || ! is_file($output) || ! str_starts_with($output, dirname($path).DIRECTORY_SEPARATOR) || ! is_string($outputHash) || ! preg_match('/^[a-f0-9]{64}$/', $outputHash) || ! hash_equals($outputHash, hash_file('sha256', $output))) {
                $this->reject('Verification output is missing, changed, or outside the receipt directory.');
            }
            $checks[] = ['name' => $name, 'directory' => $check['directory'], 'command' => $check['command'], 'exit_code' => 0, 'output_file' => $outputFile, 'output_sha256' => $outputHash];
        }
        if (array_diff(array_keys($required), array_keys($seen))) {
            $this->reject('The receipt is missing required production checks.');
        }

        return ['state' => 'passed', 'plan_hash' => $plan['hash'], 'head_revision' => $plan['release_revision'], 'receipt_file' => $file, 'receipt_digest' => $hash, 'completed_at' => $completed->format(DATE_ATOM), 'checks' => $checks];
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['production' => $message]);
    }
}
