<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\MissionTask;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class GoalTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_goal_tasks_are_ordered_by_position(): void
    {
        $goal = Goal::factory()->create();
        $third = MissionTask::factory()->forGoal($goal, 3)->create();
        $first = MissionTask::factory()->forGoal($goal, 1)->create();
        $second = MissionTask::factory()->forGoal($goal, 2)->create();

        $this->assertSame([$first->id, $second->id, $third->id], $goal->tasks()->pluck('id')->all());
    }

    public function test_a_goal_without_a_source_belongs_to_the_owner(): void
    {
        $goal = Goal::create(['request' => 'Add a thing', 'settings' => []]);

        $this->assertSame('owner', $goal->fresh()->source);
    }

    public function test_the_ideas_factory_state_marks_the_goal_as_ideas(): void
    {
        $goal = Goal::factory()->ideas()->create();

        $this->assertSame('ideas', $goal->source);
        $this->assertSame('Ideas from the leads', $goal->request);
    }

    public function test_deleting_a_goal_keeps_its_tasks(): void
    {
        $goal = Goal::factory()->create();
        $task = MissionTask::factory()->forGoal($goal)->create();

        $goal->delete();

        $this->assertNull($task->fresh()->goal_id);
    }

    public function test_waiting_on_lists_unmerged_dependencies(): void
    {
        $merged = MissionTask::factory()->create(['status' => 'merged']);
        $review = MissionTask::factory()->readyForReview()->create();
        $task = MissionTask::factory()->create(['depends_on' => [$merged->id, $review->id]]);

        $this->assertSame([$review->id], $task->waitingOn()->pluck('id')->all());
    }

    public function test_waiting_on_is_empty_without_dependencies(): void
    {
        $this->assertTrue(MissionTask::factory()->create()->waitingOn()->isEmpty());
        $this->assertTrue(MissionTask::factory()->create(['depends_on' => []])->waitingOn()->isEmpty());
    }
}
