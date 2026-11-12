<?php

namespace Tests\Feature;

use App\Models\MissionTask;
use App\Services\MissionPreview;
use App\Services\RepositorySync;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class WorkflowBusyResponseTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_preview_lock_contention_returns_retryable_409_without_exposing_exception_details(): void
    {
        $task = MissionTask::factory()->create(['status' => 'review', 'revision' => str_repeat('a', 40)]);
        $this->mock(MissionPreview::class, function ($mock): void {
            $mock->shouldReceive('start')->once()->andThrow(new LockTimeoutException('Private lock internals'));
        });

        $this->postJson(route('tasks.preview.start', $task), ['revision' => $task->revision])
            ->assertStatus(409)
            ->assertExactJson(['busy' => true, 'message' => 'Another Mission Control operation is in progress. Wait a moment, then try again.'])
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Retry-After', '3')
            ->assertSessionMissing('_old_input');

        $this->assertSame('review', $task->fresh()->status);
        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_browser_lock_contention_redirects_with_form_input_but_never_flashes_credentials(): void
    {
        $task = MissionTask::factory()->create(['status' => 'review', 'revision' => str_repeat('a', 40)]);
        $this->mock(MissionPreview::class, function ($mock): void {
            $mock->shouldReceive('start')->once()->andThrow(new LockTimeoutException);
        });

        $this->from(route('tasks.show', $task))
            ->post(route('tasks.preview.start', $task), ['revision' => $task->revision, 'password' => 'private-password', 'api_key' => 'private-key', 'unexpected' => 'private-value', '_token' => 'private-csrf'])
            ->assertRedirect(route('tasks.show', $task))
            ->assertSessionHasErrors(['workflow' => 'Another Mission Control operation is in progress. Wait a moment, then try again.'])
            ->assertSessionHas('_old_input', ['revision' => $task->revision]);

        $this->assertSame('review', $task->fresh()->status);
        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_repository_check_uses_the_same_global_busy_response(): void
    {
        $this->mock(RepositorySync::class, function ($mock): void {
            $mock->shouldReceive('check')->once()->andThrow(new LockTimeoutException);
        });

        $this->postJson(route('repository.check'))->assertStatus(409)->assertJsonPath('busy', true)->assertJsonPath('message', 'Another Mission Control operation is in progress. Wait a moment, then try again.');
    }
}
