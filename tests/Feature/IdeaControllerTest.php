<?php

namespace Tests\Feature;

use App\Jobs\PlanGoal;
use App\Models\Decision;
use App\Models\Goal;
use App\Models\Lead;
use App\Models\MissionTask;
use App\Services\ChiefOfStaff;
use App\Services\MissionSettings;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IdeaControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_ideas_page_shows_the_empty_state(): void
    {
        $this->get(route('ideas.index'))->assertOk()
            ->assertSee('Ideas')
            ->assertSee('Small pieces of work your leads propose. They are built after your own goals and wait for your review.')
            ->assertSee('Get ideas now')
            ->assertSee('No ideas yet. Your leads propose some once a day.')
            ->assertDontSee('Daily ideas are switched off in Settings.')
            ->assertDontSee('http-equiv="refresh"', false);
    }

    public function test_the_ideas_page_lists_idea_tasks_only(): void
    {
        $lead = Lead::factory()->create(['name' => 'Growth Lead']);
        $ideas = Goal::factory()->ideas()->create(['overview' => 'Two small wins.', 'created_at' => now()->subHours(2)]);
        $owner = Goal::factory()->create();
        $idea = MissionTask::factory()->forGoal($ideas)->create(['title' => 'Tidy the empty state <b>now</b>', 'lead_id' => $lead->id, 'status' => 'review']);
        MissionTask::factory()->forGoal($owner)->create(['title' => 'Owner goal work']);
        MissionTask::factory()->create(['title' => 'Loose task']);

        $this->get(route('ideas.index'))->assertOk()
            ->assertSee('Tidy the empty state <b>now</b>')
            ->assertDontSee('Tidy the empty state <b>now</b>', false)
            ->assertSee(route('tasks.show', $idea))
            ->assertSee('MC-'.str_pad((string) $idea->id, 3, '0', STR_PAD_LEFT))
            ->assertSee('Growth Lead')
            ->assertSee('Needs review')
            ->assertSee('Two small wins.')
            ->assertSee('2 hours ago')
            ->assertDontSee('Owner goal work')
            ->assertDontSee('Loose task')
            ->assertDontSee('No ideas yet.');
    }

    public function test_only_a_backlog_idea_can_be_dismissed_or_started(): void
    {
        $ideas = Goal::factory()->ideas()->create();
        $backlog = MissionTask::factory()->forGoal($ideas, 1)->create(['status' => 'backlog']);
        $review = MissionTask::factory()->forGoal($ideas, 2)->create(['status' => 'review']);

        $response = $this->get(route('ideas.index'))->assertOk()
            ->assertSee('Dismiss')->assertSee('Start now')
            ->assertSee(route('tasks.decide', $backlog))->assertSee(route('tasks.run', $backlog))
            ->assertSee('placeholder="Why not? This teaches the Brain."', false)
            ->assertDontSee(route('tasks.decide', $review))->assertDontSee(route('tasks.run', $review));
        $this->assertSame(1, substr_count($response->getContent(), '>Dismiss</button>'));
    }

    public function test_a_planning_ideas_run_refreshes_the_page(): void
    {
        Goal::factory()->ideas()->planning()->create();

        $this->get(route('ideas.index'))
            ->assertSee('http-equiv="refresh" content="5"', false)
            ->assertSee('The leads are looking at the project. This page refreshes automatically.');
    }

    public function test_a_working_ideas_run_hides_the_actions_on_earlier_ideas(): void
    {
        $earlier = Goal::factory()->ideas()->create();
        MissionTask::factory()->forGoal($earlier)->create(['title' => 'Earlier idea', 'status' => 'backlog']);
        Goal::factory()->ideas()->planning()->create();

        $this->get(route('ideas.index'))
            ->assertSee('http-equiv="refresh" content="5"', false)
            ->assertSee('Earlier idea')
            ->assertDontSee('Dismiss')
            ->assertDontSee('Start now');
    }

    public function test_a_rejected_dismissal_keeps_the_reason_only_in_its_own_row(): void
    {
        $ideas = Goal::factory()->ideas()->create();
        $first = MissionTask::factory()->forGoal($ideas, 1)->create(['status' => 'backlog']);
        $second = MissionTask::factory()->forGoal($ideas, 2)->create(['status' => 'backlog']);

        $response = $this->withSession(['_old_input' => ['idea' => (string) $second->id, 'action' => 'reject', 'feedback' => 'Half-typed reason']])
            ->get(route('ideas.index'))->assertOk();

        $this->assertMatchesRegularExpression('/id="dismiss-feedback-'.$second->id.'"[^>]*value="Half-typed reason"/', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/id="dismiss-feedback-'.$first->id.'"[^>]*value="Half-typed reason"/', $response->getContent());
        $this->assertSame(1, substr_count($response->getContent(), 'Half-typed reason'));
    }

    public function test_the_dismiss_form_names_its_idea_and_the_decision_accepts_it(): void
    {
        $task = MissionTask::factory()->forGoal(Goal::factory()->ideas()->create())->create(['status' => 'backlog']);

        $this->get(route('ideas.index'))->assertSee('<input type="hidden" name="idea" value="'.$task->id.'">', false);
        $this->post(route('tasks.decide', $task), ['idea' => (string) $task->id, 'action' => 'reject', 'feedback' => 'Not now'])->assertRedirect(route('tasks.show', $task));
        $this->assertSame('rejected', $task->fresh()->status);
    }

    public function test_a_failed_ideas_run_shows_its_error(): void
    {
        Goal::factory()->ideas()->failed()->create(['error' => 'The leads could not agree on ideas.']);

        $this->get(route('ideas.index'))
            ->assertSee('The last ideas run needs attention')
            ->assertSee('The leads could not agree on ideas.')
            ->assertDontSee('http-equiv="refresh"', false);
    }

    public function test_the_switched_off_line_appears_only_when_daily_ideas_are_off(): void
    {
        $this->get(route('ideas.index'))->assertDontSee('Daily ideas are switched off in Settings.');

        app(MissionSettings::class)->update(['ideas_enabled' => false]);

        $this->get(route('ideas.index'))->assertSee('Daily ideas are switched off in Settings.');
    }

    public function test_asking_for_ideas_queues_an_ideas_goal(): void
    {
        Queue::fake();
        $this->readyEnvironment();

        $this->post(route('ideas.store'))
            ->assertRedirect(route('ideas.index'))
            ->assertSessionHas('status', 'Asked the leads for ideas. They will appear here shortly.');

        $goal = Goal::query()->sole();
        $this->assertSame('ideas', $goal->source);
        $this->assertSame('queued', $goal->status);
        Queue::assertPushed(PlanGoal::class);
    }

    public function test_asking_for_ideas_while_the_leads_are_working_shows_why(): void
    {
        Queue::fake();
        $this->readyEnvironment();
        Goal::factory()->ideas()->planning()->create();

        $this->from(route('ideas.index'))->post(route('ideas.store'))
            ->assertRedirect(route('ideas.index'))
            ->assertSessionHasErrors(['ideas' => 'The leads are already working on ideas.']);
        $this->followingRedirects()->from(route('ideas.index'))->post(route('ideas.store'))->assertSee('The leads are already working on ideas.');
        $this->assertDatabaseCount('goals', 1);
        Queue::assertNothingPushed();
    }

    public function test_asking_for_ideas_while_the_app_is_busy_shows_the_busy_message(): void
    {
        $this->mock(ChiefOfStaff::class, function ($mock): void {
            $mock->shouldReceive('propose')->once()->with(true)->andThrow(new LockTimeoutException);
        });

        $this->from(route('ideas.index'))->post(route('ideas.store'))
            ->assertRedirect(route('ideas.index'))
            ->assertSessionHasErrors(['workflow' => 'Another Mission Control operation is in progress. Wait a moment, then try again.']);
    }

    public function test_the_dismiss_form_rejects_a_backlog_idea_with_its_reason(): void
    {
        $task = MissionTask::factory()->forGoal(Goal::factory()->ideas()->create())->create(['status' => 'backlog']);

        $this->from(route('ideas.index'))->post(route('tasks.decide', $task), ['action' => 'reject', 'feedback' => 'Too risky'])
            ->assertRedirect(route('tasks.show', $task));

        $this->assertSame('rejected', $task->fresh()->status);
        $decision = Decision::query()->sole();
        $this->assertSame('reject', $decision->action);
        $this->assertSame('Too risky', $decision->feedback);
    }

    public function test_dismissing_without_a_reason_returns_to_the_ideas_page_with_the_error(): void
    {
        $task = MissionTask::factory()->forGoal(Goal::factory()->ideas()->create())->create(['status' => 'backlog']);

        $this->from(route('ideas.index'))->post(route('tasks.decide', $task), ['action' => 'reject', 'feedback' => ''])
            ->assertRedirect(route('ideas.index'))
            ->assertSessionHasErrors('feedback');
        $this->assertSame('backlog', $task->fresh()->status);
    }

    public function test_the_sidebar_links_to_the_ideas_page_between_brain_and_settings(): void
    {
        $this->get(route('dashboard'))->assertSeeInOrder(['>Brain<', route('ideas.index'), '>Ideas<', '>Settings<'], false);
        $this->get(route('ideas.index'))->assertSee('nav-item nav-active" href="'.route('ideas.index').'"', false);
    }

    public function test_the_goal_page_of_an_ideas_run_points_back_to_ideas(): void
    {
        $goal = Goal::factory()->ideas()->planning()->create();

        $this->get(route('goals.show', $goal))->assertOk()
            ->assertSee('Back to ideas')->assertSee(route('ideas.index'))
            ->assertSee('<p class="eyebrow mb-0">Ideas</p>', false)
            ->assertSee('The leads are looking at the project. This page refreshes automatically.')
            ->assertDontSee('Back to queue')
            ->assertDontSee('The Chief of Staff is working on this.');
    }

    private function readyEnvironment(): void
    {
        config(['mission.openai_api_key' => 'test-key-not-a-real-secret', 'mission.repository_path' => base_path(), 'mission.base_branch' => 'main', 'mission.worker_path' => base_path('artisan'), 'mission.test_command' => ['php', 'artisan', 'test']]);
    }
}
