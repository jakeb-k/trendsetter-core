<?php

namespace Database\Factories;

use App\Models\Goal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AiPlan>
 */
class AiPlanFactory extends Factory
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
            'version' => 1,
            'prompt_log' => json_encode(['prompt' => 'How do I get better at X?']),
            'response' => json_encode(['plan' => 'Do this for 4 weeks...']),
        ];
    }
}
