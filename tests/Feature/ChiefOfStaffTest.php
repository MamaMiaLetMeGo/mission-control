<?php

namespace Tests\Feature;

use App\Jobs\PlanGoal;
use App\Models\AgentRun;
use App\Models\Goal;
use App\Models\Lead;
use App\Services\ChiefOfStaff;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class ChiefOfStaffTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_goal_is_queued_with_a_settings_snapshot_and_planning_is_dispatched(): void
    {
        Queue::fake();
        $this->readyEnvironment();

        $goal = app(ChiefOfStaff::class)->start('Ship a better onboarding.');

        $this->assertSame('queued', $goal->status);
        $this->assertSame('Ship a better onboarding.', $goal->request);
        $this->assertSame(realpath(base_path()), $goal->settings['repository_path']);
        $this->assertSame('main', $goal->settings['base_branch']);
        Queue::assertPushed(PlanGoal::class, fn (PlanGoal $job): bool => $job->goalId === $goal->id);
    }

    public function test_a_goal_is_rejected_when_the_app_is_not_ready(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        config(['mission.openai_api_key' => '']);

        $this->assertRejected('Add the OpenAI API key');
        $this->assertDatabaseCount('goals', 0);
        Queue::assertNothingPushed();
    }

    public function test_a_goal_is_rejected_when_the_token_limit_is_reached(): void
    {
        $this->readyEnvironment();
        config(['mission.daily_token_limit' => 10000]);
        AgentRun::factory()->create(['usage' => ['input_tokens' => 9000, 'output_tokens' => 1000]]);

        $this->assertRejected('The daily token limit of 10000 has been reached. Raise it in Settings or wait until tomorrow.');
        $this->assertDatabaseCount('goals', 0);
    }

    public function test_a_goal_is_rejected_without_an_active_lead(): void
    {
        $this->readyEnvironment();
        Lead::query()->update(['is_active' => false]);

        $this->assertRejected('Add an active lead in Settings before giving a goal.');
        $this->assertDatabaseCount('goals', 0);
    }

    public function test_a_plan_becomes_linked_backlog_tasks(): void
    {
        [$first, $second] = $this->leadKeys();
        $goal = Goal::factory()->planning()->create();

        app(ChiefOfStaff::class)->createTasks($goal, $this->plan([
            $this->brief($first, 'First', 'urgent'),
            $this->brief($second, 'Second', 'low', [1]),
            $this->brief($first, 'Third', 'normal', [1, 2]),
        ]));

        $goal->refresh();
        $tasks = $goal->tasks;
        $this->assertSame('planned', $goal->status);
        $this->assertSame('The plan.', $goal->overview);
        $this->assertNotNull($goal->finished_at);
        $this->assertSame(['First', 'Second', 'Third'], $tasks->pluck('title')->all());
        $this->assertSame([1, 2, 3], $tasks->pluck('goal_position')->all());
        $this->assertSame(['backlog'], $tasks->pluck('status')->unique()->all());
        $this->assertSame([$first, $second, $first], $tasks->map(fn ($task) => $task->lead->key)->all());
        $this->assertNull($tasks[0]->depends_on);
        $this->assertSame([$tasks[0]->id], $tasks[1]->depends_on);
        $this->assertSame([$tasks[0]->id, $tasks[1]->id], $tasks[2]->depends_on);
        $this->assertSame('urgent', $tasks[0]->priority);
    }

    public function test_an_unusable_plan_creates_nothing(): void
    {
        [$first] = $this->leadKeys();
        $inactive = Lead::factory()->inactive()->create();
        $good = $this->brief($first, 'Fine', 'normal');
        $cases = [
            'unknown lead' => [[$good, $this->brief('nobody', 'Bad', 'normal')]],
            'inactive lead' => [[$good, $this->brief($inactive->key, 'Bad', 'normal')]],
            'no briefs' => [[]],
            'too many briefs' => [array_fill(0, 9, $good)],
            'bad priority' => [[$this->brief($first, 'Bad', 'whenever')]],
            'empty title' => [[$this->brief($first, '', 'normal')]],
            'long title' => [[$this->brief($first, str_repeat('a', 201), 'normal')]],
            'self dependency' => [[$good, $this->brief($first, 'Bad', 'normal', [2])]],
            'forward dependency' => [[$this->brief($first, 'Bad', 'normal', [2]), $good]],
            'zero dependency' => [[$good, $this->brief($first, 'Bad', 'normal', [0])]],
            'duplicate dependency' => [[$good, $this->brief($first, 'Bad', 'normal', [1, 1])]],
            'string dependency' => [[$good, $this->brief($first, 'Bad', 'normal', ['1'])]],
        ];

        foreach ($cases as $name => [$briefs]) {
            $goal = Goal::factory()->planning()->create();
            try {
                app(ChiefOfStaff::class)->createTasks($goal, $this->plan($briefs));
                $this->fail("The plan was accepted: {$name}.");
            } catch (InvalidArgumentException) {
                $this->assertSame('planning', $goal->refresh()->status, $name);
                $this->assertSame(0, $goal->tasks()->count(), $name);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        app(ChiefOfStaff::class)->createTasks(Goal::factory()->planning()->create(), ['overview' => '', 'briefs' => [$good]]);
    }

    /** @return list<string> */
    private function leadKeys(): array
    {
        return Lead::query()->active()->limit(2)->pluck('key')->all();
    }

    /**
     * @param  list<array<string, mixed>>  $briefs
     * @return array<string, mixed>
     */
    private function plan(array $briefs): array
    {
        return ['type' => 'plan', 'success' => true, 'overview' => 'The plan.', 'briefs' => $briefs];
    }

    /**
     * @param  list<mixed>  $after
     * @return array<string, mixed>
     */
    private function brief(string $lead, string $title, string $priority, array $after = []): array
    {
        return ['lead' => $lead, 'title' => $title, 'request' => 'Do it.', 'acceptanceCriteria' => 'It works.', 'priority' => $priority, 'after' => $after];
    }

    private function assertRejected(string $message): void
    {
        try {
            app(ChiefOfStaff::class)->start('A goal');
            $this->fail('The goal was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($message, $exception->errors()['goal'][0]);
        }
    }

    private function readyEnvironment(): void
    {
        config(['mission.openai_api_key' => 'test-key-not-a-real-secret', 'mission.repository_path' => base_path(), 'mission.base_branch' => 'main', 'mission.worker_path' => base_path('artisan'), 'mission.test_command' => ['php', 'artisan', 'test']]);
    }
}
