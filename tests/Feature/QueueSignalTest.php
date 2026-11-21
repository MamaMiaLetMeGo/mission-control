<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\MissionTask;
use App\Services\QueueSignal;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class QueueSignalTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @param array<string, mixed> $reviewData */
    private function reviewed(array $reviewData = [], string $status = 'review', array $attributes = []): MissionTask
    {
        $base = ['tests' => ['exitCode' => 0], 'review' => ['verdict' => 'pass'], 'security' => ['verdict' => 'pass', 'summary' => 'Fine.', 'findings' => []], 'diff' => ['files' => ['app/Foo.php'], 'additions' => 10, 'deletions' => 5], 'reviewRounds' => 0];

        return MissionTask::factory()->create(['status' => $status, 'review_data' => array_merge($base, $reviewData)] + $attributes + ['revision' => str_repeat('a', 40)]);
    }

    private function signal(MissionTask $task): array
    {
        return QueueSignal::for($task);
    }

    public function test_failed_tasks_are_red_with_the_error_cut_to_200_characters(): void
    {
        $task = MissionTask::factory()->create(['status' => 'failed', 'error' => str_repeat('x', 300)]);
        $this->assertSame(['signal' => 'red', 'reason' => str_repeat('x', 200)], $this->signal($task));

        $task = MissionTask::factory()->create(['status' => 'failed']);
        $this->assertSame(['signal' => 'red', 'reason' => 'The run failed.'], $this->signal($task));
    }

    public function test_changes_requested_is_red_only_with_an_error(): void
    {
        $this->assertSame(['signal' => 'red', 'reason' => 'Tests broke.'], $this->signal(MissionTask::factory()->create(['status' => 'changes_requested', 'error' => 'Tests broke.'])));
        $this->assertSame(['signal' => null, 'reason' => null], $this->signal(MissionTask::factory()->create(['status' => 'changes_requested'])));
    }

    public function test_review_approved_and_deferred_with_an_error_are_red(): void
    {
        foreach (['review', 'approved', 'deferred'] as $status) {
            $task = $this->reviewed([], $status, ['error' => 'Publishing failed.']);
            $this->assertSame(['signal' => 'red', 'reason' => 'Publishing failed.'], $this->signal($task));
        }
    }

    public function test_a_task_without_a_reviewed_revision_is_yellow(): void
    {
        foreach (['review', 'approved', 'deferred'] as $status) {
            $task = MissionTask::factory()->create(['status' => $status, 'revision' => null, 'review_data' => null]);
            $this->assertSame(['signal' => 'yellow', 'reason' => 'No reviewed revision yet.'], $this->signal($task), $status);
            $this->assertSame('yellow', $task->refresh()->signal);
        }
    }

    public function test_other_statuses_have_no_signal(): void
    {
        foreach (['backlog', 'queued', 'running', 'merged', 'rejected'] as $status) {
            $this->assertSame(['signal' => null, 'reason' => null], $this->signal(MissionTask::factory()->create(['status' => $status, 'error' => 'ignored'])));
        }
    }

    public function test_a_clean_small_reviewed_task_is_green(): void
    {
        $this->assertSame(['signal' => 'green', 'reason' => 'Passed tests, review and security check.'], $this->signal($this->reviewed()));
    }

    public function test_missing_security_data_is_yellow(): void
    {
        $task = MissionTask::factory()->create(['status' => 'review', 'revision' => str_repeat('a', 40), 'review_data' => ['diff' => ['files' => [], 'additions' => 1, 'deletions' => 1]]]);
        $this->assertSame(['signal' => 'yellow', 'reason' => 'Reviewed before security checks existed.'], $this->signal($task));
    }

    public function test_security_findings_are_yellow(): void
    {
        $task = $this->reviewed(['security' => ['verdict' => 'pass', 'summary' => 'x', 'findings' => [['severity' => 'low', 'file' => 'a', 'note' => 'b']]]]);
        $this->assertSame(['signal' => 'yellow', 'reason' => 'The security check left notes.'], $this->signal($task));
    }

    public function test_anything_short_of_a_full_pass_is_red(): void
    {
        $security = 'The security check did not pass.';
        $this->assertSame(['signal' => 'red', 'reason' => $security], $this->signal($this->reviewed(['security' => ['verdict' => 'fail', 'summary' => 'x', 'findings' => []]])));
        $this->assertSame(['signal' => 'red', 'reason' => $security], $this->signal($this->reviewed(['security' => []])));
        $this->assertSame(['signal' => 'red', 'reason' => $security], $this->signal($this->reviewed(['security' => 'pass'])));
        $other = 'Tests or the independent review did not pass.';
        $this->assertSame(['signal' => 'red', 'reason' => $other], $this->signal($this->reviewed(['tests' => ['exitCode' => 1]])));
        $this->assertSame(['signal' => 'red', 'reason' => $other], $this->signal($this->reviewed(['tests' => ['exitCode' => '0']])));
        $this->assertSame(['signal' => 'red', 'reason' => $other], $this->signal($this->reviewed(['review' => ['verdict' => 'changes_requested']])));
    }

    public function test_a_refresh_is_yellow(): void
    {
        $this->assertSame(['signal' => 'yellow', 'reason' => 'Re-checked after the base moved.'], $this->signal($this->reviewed(['mode' => 'refresh'])));
    }

    public function test_the_400_line_boundary(): void
    {
        $this->assertSame('green', $this->signal($this->reviewed(['diff' => ['files' => ['a.php'], 'additions' => 300, 'deletions' => 100]]))['signal']);
        $this->assertSame(['signal' => 'yellow', 'reason' => 'Large change (401 lines).'], $this->signal($this->reviewed(['diff' => ['files' => ['a.php'], 'additions' => 300, 'deletions' => 101]])));
    }

    public function test_sensitive_paths_are_yellow(): void
    {
        foreach (['database/migrations/x.php', '.github/workflows/ci.yml', 'config/app.php', 'routes/web.php', 'app/Http/OAuthController.php', 'app/AUTH.php'] as $path) {
            $this->assertSame(['signal' => 'yellow', 'reason' => 'Touches sensitive files.'], $this->signal($this->reviewed(['diff' => ['files' => [$path], 'additions' => 1, 'deletions' => 0]])), $path);
        }
        $this->assertSame('green', $this->signal($this->reviewed(['diff' => ['files' => ['app/routes/x.php', 'docs/config/a.md'], 'additions' => 1, 'deletions' => 0]]))['signal']);
    }

    public function test_reviewer_fix_rounds_are_yellow(): void
    {
        $this->assertSame(['signal' => 'yellow', 'reason' => 'Needed reviewer fixes.'], $this->signal($this->reviewed(['reviewRounds' => 1])));
    }

    public function test_yellow_reasons_apply_in_order(): void
    {
        $everything = ['mode' => 'refresh', 'reviewRounds' => 2, 'diff' => ['files' => ['config/a.php'], 'additions' => 900, 'deletions' => 0], 'security' => ['verdict' => 'pass', 'summary' => '', 'findings' => [['severity' => 'low', 'file' => 'a', 'note' => 'b']]]];
        $this->assertSame('The security check left notes.', $this->signal($this->reviewed($everything))['reason']);
        unset($everything['security']['findings']);
        $everything['security']['findings'] = [];
        $this->assertSame('Re-checked after the base moved.', $this->signal($this->reviewed($everything))['reason']);
        $everything['mode'] = 'implement';
        $this->assertSame('Large change (900 lines).', $this->signal($this->reviewed($everything))['reason']);
        $everything['diff']['additions'] = 1;
        $this->assertSame('Touches sensitive files.', $this->signal($this->reviewed($everything))['reason']);
        $everything['diff']['files'] = ['a.php'];
        $this->assertSame('Needed reviewer fixes.', $this->signal($this->reviewed($everything))['reason']);
    }

    public function test_saving_a_task_stores_its_signal(): void
    {
        $task = $this->reviewed();
        $this->assertSame('green', $task->fresh()->signal);
        $this->assertSame('Passed tests, review and security check.', $task->fresh()->signal_reason);

        $task->update(['status' => 'failed', 'error' => 'Boom']);
        $this->assertSame('red', $task->fresh()->signal);
        $this->assertSame('Boom', $task->fresh()->signal_reason);

        $task->update(['status' => 'merged']);
        $this->assertNull($task->fresh()->signal);
        $this->assertNull($task->fresh()->signal_reason);
    }

    public function test_the_queue_lists_red_then_yellow_then_green_then_none(): void
    {
        $none = MissionTask::factory()->create(['title' => 'None task', 'status' => 'backlog']);
        $green = $this->reviewed([], 'review', ['title' => 'Green task']);
        $yellow = $this->reviewed(['reviewRounds' => 1], 'review', ['title' => 'Yellow task']);
        $red = MissionTask::factory()->create(['title' => 'Red task', 'status' => 'failed', 'error' => 'Broke.']);

        $this->get(route('dashboard'))->assertOk()->assertSeeInOrder(['Red task', 'Yellow task', 'Green task', 'None task']);
    }

    public function test_goal_position_breaks_ties_with_nulls_last(): void
    {
        $goal = Goal::factory()->create();
        $loose = MissionTask::factory()->create(['title' => 'Loose task']);
        MissionTask::factory()->forGoal($goal, 2)->create(['title' => 'Second task']);
        MissionTask::factory()->forGoal($goal, 1)->create(['title' => 'First task']);

        $this->get(route('dashboard'))->assertSeeInOrder(['First task', 'Second task', 'Loose task']);
    }

    public function test_the_reason_and_dot_render_in_the_queue_the_task_page_and_the_goal_page(): void
    {
        $goal = Goal::factory()->create();
        $task = MissionTask::factory()->forGoal($goal)->create(['status' => 'failed', 'error' => 'Tests broke badly.']);

        $this->get(route('dashboard'))->assertSee('signal-dot signal-red', false)->assertSee('Tests broke badly.');
        $this->get(route('tasks.show', $task))->assertSee('signal-dot signal-red', false)->assertSee('Tests broke badly.');
        $this->get(route('goals.show', $goal))->assertSee('signal-dot signal-red', false)->assertSee('Tests broke badly.');
    }
}
