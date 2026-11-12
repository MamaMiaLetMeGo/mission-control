<?php

namespace Tests\Feature;

use App\Services\GitHubProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GitHubProcessTest extends TestCase
{
    public function test_github_child_uses_saved_user_auth_without_app_or_token_environment(): void
    {
        $directory = storage_path('framework/testing/github-process-'.Str::uuid());
        mkdir($directory, 0700, true);
        $executable = $directory.'/gh-fixture';
        file_put_contents($executable, '#!'.PHP_BINARY."\n".<<<'PHP'
<?php
echo json_encode(['hasOpenaiKey' => getenv('OPENAI_API_KEY') !== false, 'hasGithubToken' => getenv('GH_TOKEN') !== false, 'home' => getenv('HOME'), 'host' => getenv('GH_HOST'), 'prompt' => getenv('GH_PROMPT_DISABLED'), 'arguments' => array_slice($argv, 1), 'input' => stream_get_contents(STDIN)]);
PHP);
        chmod($executable, 0700);
        config(['mission.github_binary' => $executable]);
        $oldKey = getenv('OPENAI_API_KEY');
        $oldToken = getenv('GH_TOKEN');
        putenv('OPENAI_API_KEY=fake-api-key');
        putenv('GH_TOKEN=fake-github-token');
        try {
            $result = json_decode(app(GitHubProcess::class)->command('owner/repository', ['pr', 'create', '--body-file', '-'], "Summary\n\nValidation"), true);

            $this->assertFalse($result['hasOpenaiKey']);
            $this->assertFalse($result['hasGithubToken']);
            $this->assertSame(getenv('HOME'), $result['home']);
            $this->assertSame('github.com', $result['host']);
            $this->assertSame('1', $result['prompt']);
            $this->assertSame(['pr', 'create', '--body-file', '-', '--repo', 'owner/repository'], $result['arguments']);
            $this->assertSame("Summary\n\nValidation", $result['input']);
        } finally {
            putenv($oldKey === false ? 'OPENAI_API_KEY' : 'OPENAI_API_KEY='.$oldKey);
            putenv($oldToken === false ? 'GH_TOKEN' : 'GH_TOKEN='.$oldToken);
            File::deleteDirectory($directory);
        }
    }

    public function test_remote_errors_do_not_expose_command_output_or_credentials(): void
    {
        $directory = storage_path('framework/testing/github-process-'.Str::uuid());
        mkdir($directory, 0700, true);
        $executable = $directory.'/gh-fixture';
        file_put_contents($executable, '#!'.PHP_BINARY."\n".'<?php fwrite(STDERR, "secret-test-token"); exit(1);');
        chmod($executable, 0700);
        config(['mission.github_binary' => $executable]);
        try {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('GitHub could not complete this action.');

            app(GitHubProcess::class)->command('owner/repository', ['pr', 'view', '1']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_required_checks_with_no_reported_jobs_return_an_empty_check_list(): void
    {
        $directory = storage_path('framework/testing/github-process-'.Str::uuid());
        mkdir($directory, 0700, true);
        $executable = $directory.'/gh-fixture';
        file_put_contents($executable, '#!'.PHP_BINARY."\n".'<?php fwrite(STDERR, "no checks reported on this branch"); exit(1);');
        chmod($executable, 0700);
        config(['mission.github_binary' => $executable]);
        try {
            $checks = app(GitHubProcess::class)->json('owner/repository', ['pr', 'checks', '12', '--required', '--json', 'name,bucket,link'], [0, 1, 8]);

            $this->assertSame([], $checks);
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
