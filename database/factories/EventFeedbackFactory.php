<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EventFeedback>
 */
class EventFeedbackFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'user_id' => User::factory(),
            'note' => fake()->sentence(),
            'status' => fake()->randomElement(['completed', 'skipped', 'partial', 'struggled', 'nailed_it']),
            'mood' => fake()->randomElement(['happy', 'meh', 'frustrated']),
        ];
    }
}
