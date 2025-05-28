<?php

namespace Database\Factories;

use App\Models\AiPlan;
use App\Models\Goal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'goal_id' => Goal::factory(),
            'ai_plan_id' => AiPlan::factory(),
            'title' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'scheduled_for' => now()->addDays(fake()->numberBetween(1, 30)),
            'completed_at' => null,
            'points' => fake()->numberBetween(1, 10),
        ];
    }
}
