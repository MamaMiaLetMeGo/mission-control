<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class GitHubProcess
{
    public function __construct(private GitRepository $git) {}

    /** @param list<string> $arguments
     * @param  list<int>  $allowedExitCodes
     */
    public function command(string $repository, array $arguments, ?string $input = null, array $allowedExitCodes = [0]): string
    {
        $environment = [...$this->git->networkEnvironment(), 'GH_HOST' => 'github.com', 'GH_PROMPT_DISABLED' => '1', 'GH_PAGER' => 'cat', 'GH_BROWSER' => '/usr/bin/false'];
        $process = new Process([config('mission.github_binary'), ...$arguments, '--repo', $repository], base_path(), $environment, $input, 30);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw ValidationException::withMessages(['publication' => 'GitHub timed out. Refresh publication status before retrying.']);
        }
        if (! in_array($process->getExitCode(), $allowedExitCodes, true)) {
            throw ValidationException::withMessages(['publication' => 'GitHub could not complete this action. Check saved gh authentication, repository access, and branch protections, then refresh before retrying.']);
        }

        return trim($process->getOutput());
    }

    /** @param list<string> $arguments
     * @param  list<int>  $allowedExitCodes
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function json(string $repository, array $arguments, array $allowedExitCodes = [0]): array
    {
        $output = $this->command($repository, $arguments, allowedExitCodes: $allowedExitCodes);
        if ($output === '' && array_slice($arguments, 0, 2) === ['pr', 'checks'] && in_array('--required', $arguments, true)) {
            return [];
        }
        $result = json_decode($output, true);
        if (! is_array($result)) {
            throw ValidationException::withMessages(['publication' => 'GitHub returned an incomplete status. Refresh before continuing.']);
        }

        return $result;
    }
}
