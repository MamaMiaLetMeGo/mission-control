<?php

namespace Tests\Feature;

use App\Jobs\PlanGoal;
use App\Models\AgentRun;
use App\Models\Goal;
use App\Models\Lead;
use App\Models\MissionTask;
use App\Services\ChiefOfStaff;
use App\Services\MissionSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class ChiefOfStaffIdeasTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_an_automatic_call_queues_one_ideas_goal_and_dispatches_planning(): void
    {
        Queue::fake();
        $this->readyEnvironment();

        $goal = app(ChiefOfStaff::class)->propose();

        $this->assertNotNull($goal);
        $this->assertSame('ideas', $goal->source);
        $this->assertSame('Ideas from the leads', $goal->request);
        $this->assertSame('queued', $goal->status);
        $this->assertSame([], $goal->events);
        $this->assertSame(realpath(base_path()), $goal->settings['repository_path']);
        $this->assertSame(3, $goal->settings['ideas_per_run']);
        $this->assertDatabaseCount('goals', 1);
        Queue::assertPushed(PlanGoal::class, fn (PlanGoal $job): bool => $job->goalId === $goal->id);
    }

    public function test_an_automatic_call_waits_23_hours_after_any_ideas_goal(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        $chief = app(ChiefOfStaff::class);
        $first = $chief->propose();

        $this->assertNull($chief->propose());
        $first->update(['status' => 'failed']);
        $this->assertNull($chief->propose());
        $this->assertDatabaseCount('goals', 1);

        $this->travel(24)->hours();

        $this->assertNotNull($chief->propose());
        $this->assertDatabaseCount('goals', 2);
    }

    public function test_owner_goals_do_not_delay_an_automatic_call(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        Goal::factory()->create();

        $this->assertNotNull(app(ChiefOfStaff::class)->propose());
    }

    public function test_disabled_ideas_stop_automatic_calls_but_not_manual_ones(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        app(MissionSettings::class)->update(['ideas_enabled' => false]);

        $this->assertNull(app(ChiefOfStaff::class)->propose());
        $this->assertDatabaseCount('goals', 0);

        $goal = app(ChiefOfStaff::class)->propose(manual: true);

        $this->assertSame('ideas', $goal->source);
        Queue::assertPushed(PlanGoal::class, 1);
    }

    public function test_a_manual_call_within_23_hours_is_allowed(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        Goal::factory()->ideas()->create();

        $this->assertNotNull(app(ChiefOfStaff::class)->propose(manual: true));
        $this->assertDatabaseCount('goals', 2);
    }

    public function test_nothing_starts_while_an_ideas_goal_is_being_planned(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        Goal::factory()->ideas()->planning()->create(['created_at' => now()->subDays(2)]);

        $this->assertNull(app(ChiefOfStaff::class)->propose());
        $this->assertRefused('The leads are already working on ideas.');
        $this->assertDatabaseCount('goals', 1);
        Queue::assertNothingPushed();
    }

    public function test_ten_open_idea_tasks_refuse_more_ideas(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        $goal = Goal::factory()->ideas()->create(['created_at' => now()->subDays(2)]);
        MissionTask::factory()->forGoal($goal)->count(ChiefOfStaff::MAX_OPEN_IDEAS)->create(['status' => 'review']);

        $this->assertNull(app(ChiefOfStaff::class)->propose());
        $this->assertRefused('10 ideas are still open. Review or dismiss some before asking for more.');
        $this->assertDatabaseCount('goals', 1);
    }

    public function test_merged_rejected_and_owner_tasks_are_not_open_ideas(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        $ideas = Goal::factory()->ideas()->create(['created_at' => now()->subDays(2)]);
        MissionTask::factory()->forGoal($ideas)->count(9)->create();
        MissionTask::factory()->forGoal($ideas)->create(['status' => 'merged']);
        MissionTask::factory()->forGoal($ideas)->create(['status' => 'rejected']);
        MissionTask::factory()->forGoal(Goal::factory()->create())->count(5)->create();
        MissionTask::factory()->count(5)->create();

        $this->assertNotNull(app(ChiefOfStaff::class)->propose());
    }

    public function test_an_unready_app_refuses_ideas(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        config(['mission.openai_api_key' => '']);

        $this->assertNull(app(ChiefOfStaff::class)->propose());
        $this->assertRefused('Add the OpenAI API key');
        $this->assertDatabaseCount('goals', 0);
    }

    public function test_an_exhausted_token_budget_refuses_ideas(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        config(['mission.daily_token_limit' => 10000]);
        AgentRun::factory()->create(['usage' => ['input_tokens' => 9000, 'output_tokens' => 1000]]);

        $this->assertNull(app(ChiefOfStaff::class)->propose());
        $this->assertRefused('The daily token limit of 10000 has been reached. Raise it in Settings or wait until tomorrow.');
        $this->assertDatabaseCount('goals', 0);
    }

    public function test_no_active_lead_refuses_ideas(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        Lead::query()->update(['is_active' => false]);

        $this->assertNull(app(ChiefOfStaff::class)->propose());
        $this->assertRefused('Add an active lead in Settings before asking for ideas.');
        $this->assertDatabaseCount('goals', 0);
    }

    public function test_an_ideas_plan_keeps_the_run_size_and_drops_dependencies_and_urgency(): void
    {
        [$first, $second] = $this->leadKeys();
        $goal = Goal::factory()->ideas()->planning()->create(['settings' => ['ideas_per_run' => 3]]);

        app(ChiefOfStaff::class)->createTasks($goal, $this->plan([
            $this->brief($first, 'One', 'urgent'),
            $this->brief($second, 'Two', 'low', [1]),
            $this->brief($first, 'Three', 'normal', [1, 2]),
            $this->brief($second, 'Four', 'high'),
            $this->brief($first, 'Five', 'high'),
        ]));

        $tasks = $goal->refresh()->tasks;
        $this->assertSame('planned', $goal->status);
        $this->assertSame(['One', 'Two', 'Three'], $tasks->pluck('title')->all());
        $this->assertSame([null, null, null], $tasks->pluck('depends_on')->all());
        $this->assertSame(['high', 'low', 'normal'], $tasks->pluck('priority')->all());
    }

    public function test_an_ideas_plan_ignores_invalid_dependencies_on_kept_briefs(): void
    {
        [$first, $second] = $this->leadKeys();
        $goal = Goal::factory()->ideas()->planning()->create(['settings' => ['ideas_per_run' => 3]]);
        $notAList = [...$this->brief($first, 'Three', 'normal'), 'after' => 'first'];

        app(ChiefOfStaff::class)->createTasks($goal, $this->plan([
            $this->brief($first, 'One', 'normal'),
            $this->brief($second, 'Two', 'normal', [3]),
            $notAList,
        ]));

        $tasks = $goal->refresh()->tasks;
        $this->assertSame('planned', $goal->status);
        $this->assertSame(['One', 'Two', 'Three'], $tasks->pluck('title')->all());
        $this->assertSame([null, null, null], $tasks->pluck('depends_on')->all());
    }

    public function test_an_ideas_plan_without_a_list_of_briefs_names_the_ideas_limit(): void
    {
        $goal = Goal::factory()->ideas()->planning()->create(['settings' => ['ideas_per_run' => 3]]);

        try {
            app(ChiefOfStaff::class)->createTasks($goal, ['overview' => 'The ideas.', 'briefs' => 'none']);
            $this->fail('The plan without briefs was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('0 to 3 ideas', $exception->getMessage());
        }
    }

    public function test_an_ideas_plan_never_exceeds_the_maximum_run_size(): void
    {
        [$first] = $this->leadKeys();
        $goal = Goal::factory()->ideas()->planning()->create(['settings' => ['ideas_per_run' => 50]]);

        app(ChiefOfStaff::class)->createTasks($goal, $this->plan(array_fill(0, 8, $this->brief($first, 'Idea', 'normal'))));

        $this->assertSame(ChiefOfStaff::MAX_IDEAS, $goal->tasks()->count());
    }

    public function test_an_ideas_plan_may_have_no_briefs(): void
    {
        $goal = Goal::factory()->ideas()->planning()->create(['settings' => ['ideas_per_run' => 3]]);

        app(ChiefOfStaff::class)->createTasks($goal, $this->plan([]));

        $this->assertSame('planned', $goal->refresh()->status);
        $this->assertSame(0, $goal->tasks()->count());
    }

    public function test_an_owner_plan_still_needs_briefs(): void
    {
        $goal = Goal::factory()->planning()->create();

        try {
            app(ChiefOfStaff::class)->createTasks($goal, $this->plan([]));
            $this->fail('The empty owner plan was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame('planning', $goal->refresh()->status);
        }
    }

    public function test_the_ideas_command_reports_what_it_did(): void
    {
        Queue::fake();
        $this->readyEnvironment();

        $this->artisan('mission:ideas')->expectsOutput('Asked the leads for ideas.')->assertExitCode(0);
        $this->artisan('mission:ideas')->expectsOutput('No ideas run is due.')->assertExitCode(0);
        $this->assertDatabaseCount('goals', 1);
    }

    public function test_the_ideas_command_treats_a_busy_workflow_as_nothing_started(): void
    {
        $this->mock(ChiefOfStaff::class, function ($mock): void {
            $mock->shouldReceive('propose')->once()->andThrow(new LockTimeoutException);
        });

        $this->artisan('mission:ideas')->expectsOutput('No ideas run is due.')->assertExitCode(0);
    }

    public function test_the_ideas_command_is_scheduled_hourly_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'mission:ideas'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(5, $event->expiresAt);
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
        return ['type' => 'plan', 'success' => true, 'overview' => 'The ideas.', 'briefs' => $briefs];
    }

    /**
     * @param  list<mixed>  $after
     * @return array<string, mixed>
     */
    private function brief(string $lead, string $title, string $priority, array $after = []): array
    {
        return ['lead' => $lead, 'title' => $title, 'request' => 'Do it.', 'acceptanceCriteria' => 'It works.', 'priority' => $priority, 'after' => $after];
    }

    private function assertRefused(string $message): void
    {
        try {
            app(ChiefOfStaff::class)->propose(manual: true);
            $this->fail('The ideas run was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($message, $exception->errors()['ideas'][0]);
        }
    }

    private function readyEnvironment(): void
    {
        config(['mission.openai_api_key' => 'test-key-not-a-real-secret', 'mission.repository_path' => base_path(), 'mission.base_branch' => 'main', 'mission.worker_path' => base_path('artisan'), 'mission.test_command' => ['php', 'artisan', 'test']]);
    }
}
