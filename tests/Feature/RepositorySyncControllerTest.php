<?php

namespace Tests\Feature;

use App\Models\MissionTask;
use App\Services\GitRepository;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RepositorySyncControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_status_does_not_fetch_and_check_updates_only_remote_tracking(): void
    {
        [$repository, $publisher] = $this->repository();
        $before = $this->git($repository, ['rev-parse', 'HEAD']);
        $remote = $this->publish($publisher);
        file_put_contents($repository.'/draft.txt', "Uncommitted user work\n");

        $this->getJson(route('repository.status'))->assertOk()->assertJsonPath('repository.state', 'dirty')->assertJsonPath('repository.last_checked_at', null)->assertJsonPath('repository.remote_revision', $before);
        $response = $this->postJson(route('repository.check'));

        $response->assertOk()->assertJsonPath('repository.remote_revision', $remote)->assertJsonPath('repository.behind', 1)->assertJsonPath('repository.dirty_count', 1)->assertJsonPath('repository.can_sync', false);
        $this->assertNotNull($response->json('repository.last_checked_at'));
        $this->assertSame($before, $this->git($repository, ['rev-parse', 'HEAD']));
        $this->assertSame("Uncommitted user work\n", file_get_contents($repository.'/draft.txt'));
    }

    public function test_sync_fast_forwards_only_the_checked_revision_and_disables_hooks(): void
    {
        [$repository, $publisher] = $this->repository();
        $remote = $this->publish($publisher);
        file_put_contents($repository.'/.git/hooks/post-merge', "#!/bin/sh\ntouch hook-ran\n");
        chmod($repository.'/.git/hooks/post-merge', 0700);
        $expected = $this->checked();

        $response = $this->postJson(route('repository.sync'), $expected);

        $response->assertOk()->assertJsonPath('repository.local_revision', $remote)->assertJsonPath('repository.state', 'up_to_date')->assertJsonPath('repository.can_sync', false);
        $this->assertSame($remote, $this->git($repository, ['rev-parse', 'HEAD']));
        $this->assertFileDoesNotExist($repository.'/hook-ran');
    }

    public function test_remote_changes_after_check_require_a_new_review_before_sync(): void
    {
        [$repository, $publisher] = $this->repository();
        $this->publish($publisher);
        $expected = $this->checked();
        $remote = $this->publish($publisher, 'second.txt');

        $response = $this->postJson(route('repository.sync'), $expected);

        $response->assertUnprocessable()->assertJsonValidationErrors('repository');
        $this->assertStringContainsString('remote changed', $response->json('errors.repository.0'));
        $this->assertSame($expected['expected_head'], $this->git($repository, ['rev-parse', 'HEAD']));
        $this->assertSame($remote, $this->git($repository, ['rev-parse', 'origin/main']));
    }

    public function test_sync_refuses_a_stale_local_revision_without_rewriting_it(): void
    {
        [$repository, $publisher] = $this->repository();
        $remote = $this->publish($publisher);
        $expected = $this->checked();
        $this->git($repository, ['merge', '--ff-only', $remote]);

        $response = $this->postJson(route('repository.sync'), $expected);

        $response->assertUnprocessable()->assertJsonValidationErrors('repository');
        $this->assertStringContainsString('local revision changed', $response->json('errors.repository.0'));
        $this->assertSame($remote, $this->git($repository, ['rev-parse', 'HEAD']));
    }

    public function test_sync_preserves_uncommitted_files(): void
    {
        [$repository, $publisher] = $this->repository();
        $this->publish($publisher);
        $expected = $this->checked();
        file_put_contents($repository.'/lesson.txt', "Uncommitted lesson\n");

        $response = $this->postJson(route('repository.sync'), $expected);

        $response->assertUnprocessable()->assertJsonValidationErrors('repository');
        $this->assertSame("Uncommitted lesson\n", file_get_contents($repository.'/lesson.txt'));
        $this->assertSame($expected['expected_head'], $this->git($repository, ['rev-parse', 'HEAD']));
    }

    public function test_sync_preserves_ignored_files_that_the_remote_would_overwrite(): void
    {
        [$repository, $publisher] = $this->repository();
        $this->publish($publisher);
        file_put_contents($repository.'/.git/info/exclude', "remote.txt\n", FILE_APPEND);
        file_put_contents($repository.'/remote.txt', "User-owned ignored file\n");
        $expected = $this->checked();

        $this->postJson(route('repository.sync'), $expected)->assertUnprocessable()->assertJsonValidationErrors('repository');

        $this->assertSame("User-owned ignored file\n", file_get_contents($repository.'/remote.txt'));
        $this->assertSame($expected['expected_head'], $this->git($repository, ['rev-parse', 'HEAD']));
    }

    public function test_sync_refuses_the_wrong_branch_even_at_the_same_revision(): void
    {
        [$repository, $publisher] = $this->repository();
        $this->publish($publisher);
        $expected = $this->checked();
        $this->git($repository, ['checkout', '-b', 'user-work']);

        $this->postJson(route('repository.sync'), $expected)->assertUnprocessable()->assertJsonValidationErrors('repository');

        $this->assertSame('user-work', $this->git($repository, ['branch', '--show-current']));
        $this->assertSame($expected['expected_head'], $this->git($repository, ['rev-parse', 'HEAD']));
    }

    public function test_active_tasks_block_sync(): void
    {
        [$repository, $publisher] = $this->repository();
        $this->publish($publisher);
        $expected = $this->checked();
        MissionTask::factory()->create(['status' => 'running']);

        $this->postJson(route('repository.sync'), $expected)->assertUnprocessable()->assertJsonValidationErrors('repository');

        $this->assertSame($expected['expected_head'], $this->git($repository, ['rev-parse', 'HEAD']));
    }

    public function test_diverged_history_cannot_be_synced(): void
    {
        [$repository, $publisher] = $this->repository();
        $this->publish($publisher);
        file_put_contents($repository.'/local.txt', "Local commit\n");
        $this->git($repository, ['add', '.']);
        $this->git($repository, ['commit', '-m', 'Local change']);
        $expected = $this->checked();

        $this->getJson(route('repository.status'))->assertOk()->assertJsonPath('repository.state', 'diverged')->assertJsonPath('repository.ahead', 1)->assertJsonPath('repository.behind', 1);
        $this->postJson(route('repository.sync'), $expected)->assertUnprocessable()->assertJsonValidationErrors('repository');

        $this->assertSame($expected['expected_head'], $this->git($repository, ['rev-parse', 'HEAD']));
    }

    public function test_sync_rejects_changed_settings_and_missing_revision_inputs(): void
    {
        [$repository, $publisher] = $this->repository();
        $this->publish($publisher);
        $expected = $this->checked();

        $this->postJson(route('repository.sync'), [])->assertUnprocessable()->assertJsonValidationErrors(['expected_repository', 'expected_base', 'expected_head', 'expected_remote']);
        $this->postJson(route('repository.sync'), [...$expected, 'expected_repository' => '/different/repository'])->assertUnprocessable()->assertJsonValidationErrors('repository');

        $this->assertSame($expected['expected_head'], $this->git($repository, ['rev-parse', 'HEAD']));
    }

    public function test_network_git_uses_user_auth_configuration_without_app_secrets(): void
    {
        [$repository] = $this->repository();
        $home = dirname($repository).'/git-home';
        mkdir($home, 0700);
        file_put_contents($home.'/inspect.php', '<?php echo json_encode(["hasKey" => getenv("OPENAI_API_KEY") !== false, "home" => getenv("HOME")]);');
        file_put_contents($home.'/.gitconfig', "[credential]\n helper = fixture-helper\n[alias]\n inspect = !php ".$home."/inspect.php\n");
        $oldHome = getenv('HOME');
        $oldKey = getenv('OPENAI_API_KEY');
        putenv('HOME='.$home);
        putenv('OPENAI_API_KEY=fake-key-for-git-test');
        try {
            $git = app(GitRepository::class);
            $helper = $git->command($repository, ['config', '--global', '--get', 'credential.helper'], true);
            $environment = json_decode($git->command($repository, ['inspect'], true), true);

            $this->assertSame('fixture-helper', $helper);
            $this->assertSame(['hasKey' => false, 'home' => $home], $environment);
        } finally {
            putenv($oldHome === false ? 'HOME' : 'HOME='.$oldHome);
            putenv($oldKey === false ? 'OPENAI_API_KEY' : 'OPENAI_API_KEY='.$oldKey);
        }
    }

    private function checked(): array
    {
        $status = $this->postJson(route('repository.check'))->assertOk()->json('repository');

        return ['expected_repository' => $status['repository_path'], 'expected_base' => $status['base_branch'], 'expected_head' => $status['local_revision'], 'expected_remote' => $status['remote_revision']];
    }

    private function repository(): array
    {
        $root = storage_path('framework/testing/repository-sync-'.Str::uuid());
        $this->directories[] = $root;
        mkdir($root, 0700, true);
        $origin = $root.'/origin.git';
        $publisher = $root.'/publisher';
        $repository = $root.'/checkout';
        $this->git($root, ['init', '--bare', '-b', 'main', $origin]);
        $this->git($root, ['clone', $origin, $publisher]);
        $this->identity($publisher);
        file_put_contents($publisher.'/lesson.txt', "Initial lesson\n");
        $this->git($publisher, ['add', '.']);
        $this->git($publisher, ['commit', '-m', 'Initial lesson']);
        $this->git($publisher, ['push', '-u', 'origin', 'main']);
        $this->git($root, ['clone', $origin, $repository]);
        $this->identity($repository);
        config(['mission.repository_path' => $repository, 'mission.base_branch' => 'main']);

        return [$repository, $publisher];
    }

    private function publish(string $publisher, string $file = 'remote.txt'): string
    {
        file_put_contents($publisher.'/'.$file, "New remote work\n");
        $this->git($publisher, ['add', '.']);
        $this->git($publisher, ['commit', '-m', 'Remote update']);
        $this->git($publisher, ['push', 'origin', 'main']);

        return $this->git($publisher, ['rev-parse', 'HEAD']);
    }

    private function identity(string $repository): void
    {
        $this->git($repository, ['config', 'user.name', 'Mission Control Test']);
        $this->git($repository, ['config', 'user.email', 'test@example.test']);
    }

    private function git(string $repository, array $arguments): string
    {
        $process = new Process(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgsign=false', '-C', $repository, ...$arguments]);
        $process->setTimeout(10);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
