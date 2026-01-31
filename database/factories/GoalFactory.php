<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventFeedback;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Goal>
 */
class GoalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'category' => fake()->randomElement(['fitness', 'music', 'business']),
            'status' => 'active',
            'start_date' => now(),
            'end_date' => now()->addWeeks(4),
        ];

    }

    public function readyForCompletion(): self
    {
        return $this
            ->state(fn () => [
                'start_date' => Carbon::now()->subWeeks(4),
                'end_date' => Carbon::now()->subDays(2),
                'status' => 'active',
            ])
            ->afterCreating(function ($goal) {
                $events = Event::factory()
                    ->count(2)
                    ->for($goal)
                    ->create();

                foreach ($events as $event) {
                    EventFeedback::factory()
                        ->for($event)
                        ->for($goal->user)
                        ->create([
                            'status' => 'nailed_it',
                            'mood' => 'happy',
                            'created_at' => Carbon::now()->subDays(1),
                        ]);
                }
            });
    }
}
