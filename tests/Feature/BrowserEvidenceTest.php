<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\MissionTask;
use App\Services\GitRepository;
use App\Services\MissionSettings;
use App\Services\PreviewProcess;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class BrowserEvidenceTest extends TestCase
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

    public function test_complete_revision_bound_screenshots_allow_approval(): void
    {
        $task = $this->capturedTask();
        $this->mock(GitRepository::class, function ($mock): void {
            $mock->shouldReceive('assertReviewedRevision')->once();
            $mock->shouldReceive('assertBaseRevision')->once();
        });

        $response = $this->post(route('tasks.decide', $task), ['action' => 'approve', 'revision' => $task->revision]);

        $response->assertRedirect(route('tasks.show', $task));
        $this->assertSame('approved', $task->refresh()->status);
        $this->assertDatabaseHas('decisions', ['mission_task_id' => $task->id, 'action' => 'approve', 'revision' => $task->revision]);
    }

    public function test_disabling_current_capture_settings_cannot_weaken_a_run_requirement(): void
    {
        $task = $this->capturedTask();
        app(MissionSettings::class)->update(['preview_enabled' => false]);
        $data = $task->review_data;
        array_pop($data['browser']['screenshots']);
        $task->update(['review_data' => $data]);
        $this->mock(GitRepository::class, fn ($mock) => $mock->shouldReceive('assertReviewedRevision')->once());

        $this->post(route('tasks.decide', $task), ['action' => 'approve', 'revision' => $task->revision])->assertSessionHasErrors('task');

        $this->assertSame('changes_requested', $task->refresh()->status);
        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_browser_evidence_for_another_revision_cannot_be_approved(): void
    {
        $task = $this->capturedTask();
        $data = $task->review_data;
        $data['browser']['revision'] = str_repeat('c', 40);
        $task->update(['review_data' => $data]);
        $this->mock(GitRepository::class, fn ($mock) => $mock->shouldReceive('assertReviewedRevision')->once());

        $this->post(route('tasks.decide', $task), ['action' => 'approve', 'revision' => $task->revision])->assertSessionHasErrors('task');

        $this->assertSame('changes_requested', $task->refresh()->status);
        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_changed_image_bytes_block_approval_and_image_serving(): void
    {
        $task = $this->capturedTask();
        $screenshot = $task->review_data['browser']['screenshots'][0];
        file_put_contents($task->artifact_path.'/'.$screenshot['file'], 'changed', FILE_APPEND);
        $this->mock(GitRepository::class, fn ($mock) => $mock->shouldReceive('assertReviewedRevision')->once());

        $this->post(route('tasks.decide', $task), ['action' => 'approve', 'revision' => $task->revision])->assertSessionHasErrors('task');
        $this->get(route('tasks.screenshot', [$task, $screenshot['file']]))->assertNotFound();

        $this->assertSame('changes_requested', $task->refresh()->status);
    }

    public function test_missing_screenshot_blocks_merge_before_git_or_preview_changes(): void
    {
        $task = $this->capturedTask();
        $task->update(['status' => 'approved']);
        unlink($task->artifact_path.'/before-mobile-0.png');
        $this->mock(GitRepository::class, fn ($mock) => $mock->shouldNotReceive('merge'));
        $this->mock(PreviewProcess::class, fn ($mock) => $mock->shouldNotReceive('execute'));

        $this->post(route('tasks.merge', $task), ['revision' => $task->revision])->assertSessionHasErrors('task');

        $this->assertSame('changes_requested', $task->refresh()->status);
        $this->assertDatabaseCount('decisions', 0);
    }

    public function test_screenshot_response_is_inline_png_and_cache_is_bound_to_its_hash(): void
    {
        $task = $this->capturedTask();
        $screenshot = $task->review_data['browser']['screenshots'][0];

        $response = $this->get(route('tasks.screenshot', ['task' => $task, 'file' => $screenshot['file'], 'sha256' => $screenshot['sha256']]));

        $response->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Reviewed-Revision', $task->revision);
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $this->get(route('tasks.screenshot', ['task' => $task, 'file' => $screenshot['file'], 'sha256' => str_repeat('f', 64)]))->assertNotFound();
    }

    public function test_unlisted_png_files_are_not_served(): void
    {
        $task = $this->capturedTask();
        copy($task->artifact_path.'/before-mobile-0.png', $task->artifact_path.'/private.png');

        $this->get(route('tasks.screenshot', [$task, 'private.png']))->assertNotFound();
    }

    public function test_a_screenshot_symlink_cannot_escape_its_artifact_directory(): void
    {
        $task = $this->capturedTask();
        $file = $task->artifact_path.'/before-desktop-0.png';
        $outside = dirname($task->artifact_path).'/private.png';
        rename($file, $outside);
        symlink($outside, $file);

        $this->get(route('tasks.screenshot', [$task, 'before-desktop-0.png']))->assertNotFound();
    }

    public function test_non_png_bytes_cannot_be_served_even_with_a_matching_recorded_hash(): void
    {
        $task = $this->capturedTask();
        $file = $task->artifact_path.'/before-desktop-0.png';
        file_put_contents($file, '<svg onload="alert(1)"></svg>');
        $data = $task->review_data;
        $data['browser']['screenshots'][0]['sha256'] = hash_file('sha256', $file);
        $task->update(['review_data' => $data]);

        $this->get(route('tasks.screenshot', [$task, 'before-desktop-0.png']))->assertNotFound();
    }

    public function test_partial_failed_captures_remain_visible_as_evidence(): void
    {
        $task = $this->capturedTask();
        $data = $task->review_data;
        $data['browser']['status'] = 'failed';
        $task->update(['status' => 'failed', 'review_data' => $data]);

        $this->get(route('tasks.screenshot', [$task, 'before-desktop-0.png']))->assertOk();
    }

    private function capturedTask(): MissionTask
    {
        $root = storage_path('framework/testing/browser-'.Str::uuid());
        $this->directories[] = $root;
        mkdir($root.'/task', 0700, true);
        config(['mission.artifact_root' => $root]);
        $task = MissionTask::factory()->readyForReview()->create(['artifact_path' => $root.'/task']);
        $screenshots = [];
        foreach (['before', 'after'] as $phase) {
            foreach (['desktop', 'mobile'] as $viewport) {
                $file = $phase.'-'.$viewport.'-0.png';
                file_put_contents($task->artifact_path.'/'.$file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aIh8AAAAASUVORK5CYII='));
                $screenshots[] = ['file' => $file, 'phase' => $phase, 'viewport' => $viewport, 'path' => '/', 'width' => 1, 'height' => 1, 'revision' => $phase === 'before' ? $task->base_revision : $task->revision, 'sha256' => hash_file('sha256', $task->artifact_path.'/'.$file)];
            }
        }
        $task->update(['review_data' => [...$task->review_data, 'browser' => ['status' => 'passed', 'revision' => $task->revision, 'baseRevision' => $task->base_revision, 'screenshots' => $screenshots]]]);
        AgentRun::factory()->for($task, 'task')->create(['status' => 'completed', 'settings' => ['preview_enabled' => true, 'preview_paths' => ['/']]]);

        return $task;
    }
}
