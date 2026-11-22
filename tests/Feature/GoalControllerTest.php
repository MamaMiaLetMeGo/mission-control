<?php

namespace Tests\Feature;

use App\Jobs\PlanGoal;
use App\Models\Goal;
use App\Models\Lead;
use App\Models\MissionTask;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GoalControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_giving_a_goal_creates_a_queued_goal_and_dispatches_planning(): void
    {
        Queue::fake();
        $this->readyEnvironment();

        $response = $this->post(route('goals.store'), ['request' => 'Make onboarding friendlier.']);

        $goal = Goal::query()->firstOrFail();
        $response->assertRedirect(route('goals.show', $goal))->assertSessionHas('status', 'Goal given to the Chief of Staff. Briefs will appear here shortly.');
        $this->assertSame('queued', $goal->status);
        Queue::assertPushed(PlanGoal::class);
    }

    public function test_the_dashboard_shows_why_a_goal_was_rejected(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        config(['mission.openai_api_key' => '']);

        $this->from(route('dashboard'))->post(route('goals.store'), ['request' => 'A goal.'])->assertRedirect(route('dashboard'))->assertSessionHasErrors('goal');
        $this->followingRedirects()->from(route('dashboard'))->post(route('goals.store'), ['request' => 'A goal.'])->assertSee('Add the OpenAI API key');
        $this->assertDatabaseCount('goals', 0);
    }

    public function test_a_goal_over_5000_characters_is_rejected(): void
    {
        Queue::fake();
        $this->readyEnvironment();

        $this->post(route('goals.store'), ['request' => str_repeat('a', 5001)])->assertSessionHasErrors('request');
        $this->post(route('goals.store'), ['request' => ''])->assertSessionHasErrors('request');
        $this->assertDatabaseCount('goals', 0);
    }

    public function test_the_goal_page_shows_the_overview_and_tasks_by_lead(): void
    {
        $goal = Goal::factory()->create(['request' => 'Ship onboarding.', 'overview' => 'Two briefs, in order.', 'usage' => ['input_tokens' => 1200, 'output_tokens' => 345]]);
        $engineering = Lead::factory()->create(['name' => 'Engineering Lead']);
        $design = Lead::factory()->create(['name' => 'Design Lead']);
        $first = MissionTask::factory()->forGoal($goal, 1)->create(['title' => 'Build the form', 'lead_id' => $engineering->id]);
        MissionTask::factory()->forGoal($goal, 2)->create(['title' => 'Polish the form', 'lead_id' => $design->id, 'depends_on' => [$first->id]]);

        $this->get(route('goals.show', $goal))->assertOk()
            ->assertSee('Ship onboarding.')->assertSee('Two briefs, in order.')->assertSee('Planned')
            ->assertSee('1,545')->assertSee('Engineering Lead')->assertSee('Design Lead')
            ->assertSeeInOrder(['Build the form', 'Polish the form'])
            ->assertSee('Waiting for MC-'.str_pad((string) $first->id, 3, '0', STR_PAD_LEFT))
            ->assertDontSee('http-equiv="refresh"', false);
    }

    public function test_a_planning_goal_reloads_itself_and_a_failed_goal_shows_its_error(): void
    {
        $this->get(route('goals.show', Goal::factory()->planning()->create()))->assertSee('http-equiv="refresh" content="5"', false);
        $this->get(route('goals.show', Goal::factory()->failed()->create(['error' => 'The plan was not valid.'])))->assertSee('The plan was not valid.')->assertSee('Needs attention')->assertDontSee('http-equiv="refresh"', false);
    }

    public function test_the_dashboard_lists_recent_goals(): void
    {
        $goal = Goal::factory()->planning()->create(['request' => 'Improve search.']);

        $this->get(route('dashboard'))->assertOk()->assertSee('Give the Chief of Staff a goal')->assertSee('Improve search.')->assertSee('Planning')->assertSee(route('goals.show', $goal));
    }

    public function test_the_dashboard_lists_owner_goals_but_not_ideas_runs(): void
    {
        $owner = Goal::factory()->create(['request' => 'Improve search.']);
        $ideas = Goal::factory()->ideas()->create();

        $this->get(route('dashboard'))->assertOk()->assertSee(route('goals.show', $owner))->assertDontSee(route('goals.show', $ideas));
    }

    public function test_the_goal_page_of_an_owner_goal_points_back_to_the_queue(): void
    {
        $this->get(route('goals.show', Goal::factory()->create()))->assertSee('Back to queue')->assertSee('<p class="eyebrow mb-0">Goal</p>', false)->assertDontSee('Back to ideas');

        $failed = Goal::factory()->failed()->create(['error' => 'It broke.', 'usage' => ['input_tokens' => 1000, 'output_tokens' => 234]]);
        $this->get(route('goals.show', $failed))->assertSee('The plan needs attention')->assertSee('1,234 tokens used planning')->assertDontSee('looking for ideas');
    }

    public function test_the_task_page_and_queue_link_to_the_goal(): void
    {
        $goal = Goal::factory()->create(['request' => 'Improve search.']);
        $dependency = MissionTask::factory()->forGoal($goal, 1)->create();
        $task = MissionTask::factory()->forGoal($goal, 2)->create(['depends_on' => [$dependency->id]]);

        $this->get(route('tasks.show', $task))->assertOk()->assertSee('Part of goal:')->assertSee('Improve search.')->assertSee(route('goals.show', $goal))->assertSee('Waiting for MC-'.str_pad((string) $dependency->id, 3, '0', STR_PAD_LEFT));
        $this->get(route('dashboard'))->assertSee('queue-goal-tag', false);
    }

    public function test_a_blank_title_task_submission_keeps_its_text_out_of_the_goal_form(): void
    {
        $response = $this->followingRedirects()->from(route('dashboard'))->post(route('tasks.store'), ['title' => '', 'request' => 'Task body text.', 'acceptance_criteria' => 'Criteria.', 'lead' => 'engineering', 'priority' => 'normal']);

        $response->assertSee('<textarea id="goal-request" name="request" rows="3" maxlength="5000" required placeholder="Describe the outcome you want. The Chief of Staff will plan it into briefs for your leads."></textarea>', false)
            ->assertSee('>Task body text.</textarea>', false);
        $this->assertMatchesRegularExpression('/<details id="new-task"[^>]*\sopen\s*>/', $response->getContent());
    }

    public function test_a_rejected_goal_keeps_its_text_only_in_the_goal_form(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        config(['mission.openai_api_key' => '']);

        $response = $this->followingRedirects()->from(route('dashboard'))->post(route('goals.store'), ['request' => 'My goal text.', 'goal_form' => '1']);

        $response->assertSee('>My goal text.</textarea>', false)->assertSee('Add the OpenAI API key');
        $this->assertSame(1, substr_count($response->getContent(), 'My goal text.'));
        $this->assertDoesNotMatchRegularExpression('/<details id="new-task"[^>]*\sopen\s*>/', $response->getContent());
    }

    private function readyEnvironment(): void
    {
        config(['mission.openai_api_key' => 'test-key-not-a-real-secret', 'mission.repository_path' => base_path(), 'mission.base_branch' => 'main', 'mission.worker_path' => base_path('artisan'), 'mission.test_command' => ['php', 'artisan', 'test']]);
    }
}
