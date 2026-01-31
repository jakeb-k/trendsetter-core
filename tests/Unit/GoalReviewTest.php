<?php

namespace Tests\Unit;

use App\Models\Goal;
use App\Models\GoalReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class GoalReviewTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function goal_review_belongs_to_goal_and_casts_json_fields(): void
    {
        $user = User::factory()->create();
        $goal = Goal::factory()->create(['user_id' => $user->id]);

        $review = GoalReview::create([
            'goal_id' => $goal->id,
            'user_id' => $user->id,
            'outcome' => 'achieved',
            'feelings' => ['happy', 'proud'],
            'why' => 'Because I stayed consistent.',
            'wins' => 'Hit every milestone.',
            'obstacles' => 'None',
            'lessons' => 'Consistency wins.',
            'next_steps' => 'Set a new goal.',
            'advice' => 'Keep going.',
            'stats_snapshot' => ['streak' => 7],
        ]);

        $this->assertSame($goal->id, $review->goal->id);
        $this->assertSame(['happy', 'proud'], $review->feelings);
        $this->assertSame(['streak' => 7], $review->stats_snapshot);
    }
}
