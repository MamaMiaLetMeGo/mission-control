<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Services\TokenBudget;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TokenBudgetTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_usage_counts_input_and_output_tokens_of_runs_created_today(): void
    {
        $this->freezeTime();
        AgentRun::factory()->create(['usage' => ['input_tokens' => 600, 'output_tokens' => 400]]);
        AgentRun::factory()->create(['usage' => ['input_tokens' => 1000, 'output_tokens' => 0]]);
        AgentRun::factory()->create(['usage' => ['input_tokens' => 5000, 'output_tokens' => 5000], 'created_at' => now()->subDay()]);
        AgentRun::factory()->create(['usage' => null]);
        config(['mission.daily_token_limit' => 2000]);

        $budget = app(TokenBudget::class);

        $this->assertSame(2000, $budget->usedToday());
        $this->assertSame(2000, $budget->limit());
        $this->assertTrue($budget->exhausted());
    }

    public function test_the_budget_is_open_below_the_limit(): void
    {
        AgentRun::factory()->create(['usage' => ['input_tokens' => 600, 'output_tokens' => 400]]);
        config(['mission.daily_token_limit' => 2000]);

        $this->assertFalse(app(TokenBudget::class)->exhausted());
    }
}
