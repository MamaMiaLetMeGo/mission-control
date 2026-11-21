<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Preference;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PreferenceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_factory_makes_an_active_manual_global_preference(): void
    {
        $preference = Preference::factory()->create();

        $this->assertSame('active', $preference->status);
        $this->assertSame('manual', $preference->source);
        $this->assertNull($preference->lead_id);
        $this->assertFalse($preference->isNew());
    }

    public function test_a_learned_preference_is_new_until_it_has_been_seen(): void
    {
        $preference = Preference::factory()->learned()->create();

        $this->assertTrue($preference->isNew());

        $preference->update(['seen_at' => now()]);

        $this->assertFalse($preference->fresh()->isNew());
    }

    public function test_the_active_scope_excludes_off_preferences(): void
    {
        $active = Preference::factory()->create();
        Preference::factory()->off()->create();

        $this->assertSame([$active->id], Preference::query()->active()->pluck('id')->all());
    }

    public function test_evidence_is_cast_to_an_array_and_the_lead_is_nulled_when_deleted(): void
    {
        $lead = Lead::factory()->create();
        $preference = Preference::factory()->forLead($lead)->create(['evidence' => [3, 4]]);

        $this->assertSame([3, 4], $preference->fresh()->evidence);
        $this->assertTrue($preference->lead->is($lead));

        $lead->delete();

        $this->assertNull($preference->fresh()->lead_id);
    }
}
