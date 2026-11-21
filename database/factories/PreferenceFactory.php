<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\Preference;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Preference> */
class PreferenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'text' => fake()->sentence(),
            'lead_id' => null,
            'status' => 'active',
            'source' => 'manual',
        ];
    }

    public function learned(): static
    {
        return $this->state(fn () => ['source' => 'learned', 'seen_at' => null]);
    }

    public function off(): static
    {
        return $this->state(fn () => ['status' => 'off']);
    }

    public function forLead(Lead $lead): static
    {
        return $this->state(fn () => ['lead_id' => $lead->id]);
    }
}
