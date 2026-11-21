<?php

namespace Tests\Feature;

use App\Models\BrainRun;
use App\Models\Decision;
use App\Models\Lead;
use App\Models\Preference;
use App\Services\Brain;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class BrainTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_clean_replaces_control_characters_collapses_whitespace_and_truncates(): void
    {
        $brain = app(Brain::class);

        $this->assertSame('a b c d', $brain->clean("  a\nb\u{2028}c\t\t  d\x00 "));
        $this->assertSame(300, mb_strlen($brain->clean(str_repeat('é', 400))));
    }

    public function test_clean_handles_next_line_delete_invalid_utf8_and_never_ends_in_a_space(): void
    {
        $brain = app(Brain::class);

        $this->assertSame('a b c', $brain->clean("a\u{0085}b\x7Fc"));
        $this->assertSame('', $brain->clean("\xB1\x31"));
        $this->assertSame(299, mb_strlen($brain->clean(str_repeat('a', 299).' b')));
        $this->assertSame(str_repeat('a', 299), $brain->clean(str_repeat('a', 299).' b'));
    }

    public function test_for_prompt_returns_global_and_the_leads_own_preferences_only(): void
    {
        $lead = Lead::factory()->create();
        $other = Lead::factory()->create();
        Preference::factory()->create(['text' => 'Global one']);
        Preference::factory()->forLead($lead)->create(['text' => "Mine\nfirst\u{2028}line"]);
        Preference::factory()->forLead($other)->create(['text' => 'Theirs']);
        Preference::factory()->off()->create(['text' => 'Switched off']);

        $this->assertSame(['Global one', 'Mine first line'], app(Brain::class)->forPrompt($lead));
        $this->assertSame(['Global one'], app(Brain::class)->forPrompt());
    }

    public function test_for_prompt_is_capped_and_drops_blank_text(): void
    {
        Preference::factory()->count(25)->create();
        Preference::factory()->create(['text' => "\n"]);

        $this->assertCount(20, app(Brain::class)->forPrompt());

        Preference::query()->delete();
        Preference::factory()->create(['text' => "\n\t"]);

        $this->assertSame([], app(Brain::class)->forPrompt());
    }

    public function test_for_planner_lists_every_active_preference_with_its_lead_key(): void
    {
        $lead = Lead::factory()->create(['key' => 'brain-design']);
        Preference::factory()->create(['text' => 'Global one']);
        Preference::factory()->forLead($lead)->create(['text' => 'Design one']);
        Preference::factory()->off()->create();
        Preference::factory()->create(['text' => "\n\t"]);

        $this->assertSame(
            [['text' => 'Global one', 'lead' => null], ['text' => 'Design one', 'lead' => 'brain-design']],
            app(Brain::class)->forPlanner(),
        );
    }

    public function test_pending_decisions_only_include_human_actions_with_feedback(): void
    {
        $keep = Decision::factory()->create(['action' => 'reject', 'feedback' => 'Too risky.']);
        Decision::factory()->create(['action' => 'merge', 'feedback' => 'Merged.']);
        Decision::factory()->create(['action' => 'publish', 'feedback' => 'Published.']);
        Decision::factory()->create(['action' => 'approve', 'feedback' => '  ']);
        Decision::factory()->create(['action' => 'approve', 'feedback' => null]);
        Decision::factory()->create(['action' => 'approve', 'feedback' => "\n\t "]);

        $pending = app(Brain::class)->pendingDecisions();

        $this->assertSame([$keep->id], $pending->pluck('id')->all());
        $this->assertTrue($pending->first()->relationLoaded('task'));
        $this->assertTrue($pending->first()->task->relationLoaded('lead'));
    }

    public function test_pending_decisions_start_after_the_last_completed_run_and_ignore_failed_runs(): void
    {
        $old = Decision::factory()->create();
        $new = Decision::factory()->create();
        BrainRun::factory()->create(['last_decision_id' => $old->id]);
        BrainRun::factory()->failed()->create(['last_decision_id' => $new->id + 100]);

        $this->assertSame([$new->id], app(Brain::class)->pendingDecisions()->pluck('id')->all());

        BrainRun::factory()->create(['last_decision_id' => $new->id]);

        $this->assertCount(0, app(Brain::class)->pendingDecisions());
    }

    public function test_pending_decisions_are_capped_at_fifty_oldest_first(): void
    {
        $decisions = Decision::factory()->count(55)->create();

        $pending = app(Brain::class)->pendingDecisions();

        $this->assertCount(50, $pending);
        $this->assertSame($decisions->take(50)->pluck('id')->all(), $pending->pluck('id')->all());
    }

    public function test_whitespace_only_feedback_does_not_use_up_the_cap(): void
    {
        Decision::factory()->count(60)->create(['feedback' => "\n\t "]);
        $real = Decision::factory()->count(52)->create();

        $pending = app(Brain::class)->pendingDecisions();

        $this->assertSame($real->take(50)->pluck('id')->all(), $pending->pluck('id')->all());
    }
}
