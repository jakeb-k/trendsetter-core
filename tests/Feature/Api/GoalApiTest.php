<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\EventFeedback;
use App\Models\Goal;
use App\Models\GoalReview;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GoalApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_goal_requires_fields(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/goals', []);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'description', 'end_date']);
    }

    public function test_store_goal_creates_goal(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-31 10:00:00'));

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/goals', [
            'title' => 'Run a 5k',
            'description' => 'Train to run a 5k in 8 weeks.',
            'end_date' => now()->addDays(10)->toDateString(),
        ]);

        $response->assertOk();

        $goal = Goal::first();
        $this->assertNotNull($goal);
        $this->assertSame('Run a 5k', $goal->title);
        $this->assertSame('active', $goal->status);
        $this->assertSame('User Created', $goal->category);
        $this->assertSame('2026-01-31', $goal->start_date->toDateString());
        $this->assertSame('2027-01-31', $goal->end_date->toDateString());

        Carbon::setTestNow();
    }

    public function test_get_goals_returns_goals_with_events_and_feedback(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $goal = Goal::factory()->create(['user_id' => $user->id]);
        $event = Event::factory()->create(['goal_id' => $goal->id]);
        EventFeedback::factory()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);

        $response = $this->getJson('/api/v1/goals');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'goals' => [
                    ['id', 'events' => [['id', 'feedback']]],
                ],
            ]);

        $this->assertCount(1, $response->json('goals'));
    }

    public function test_get_goal_event_feedback_returns_only_events_with_feedback(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $goal = Goal::factory()->create(['user_id' => $user->id]);
        $eventWithFeedback = Event::factory()->create([
            'goal_id' => $goal->id,
            'title' => 'DailyRun',
        ]);
        $eventWithoutFeedback = Event::factory()->create([
            'goal_id' => $goal->id,
            'title' => 'Stretch',
        ]);

        EventFeedback::factory()->create([
            'event_id' => $eventWithFeedback->id,
            'user_id' => $user->id,
        ]);

        $response = $this->getJson("/api/v1/goals/{$goal->id}/feedback");

        $response->assertOk();
        $this->assertCount(1, $response->json('feedback'));
        $this->assertSame($eventWithFeedback->id, $response->json('feedback.DailyRun.0.event_id'));
        $this->assertNull($response->json('feedback.Stretch'));
    }

    public function test_complete_goal_updates_status_and_reason(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-31 10:00:00'));

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $goal = Goal::factory()->create(['user_id' => $user->id, 'status' => 'active']);

        $response = $this->postJson("/api/v1/goals/{$goal->id}/complete", [
            'completion_reasons' => ['Finished early', 'Hit milestone'],
        ]);

        $response->assertOk();

        $goal->refresh();
        $this->assertSame('completed', $goal->status);
        $this->assertSame('Finished early,Hit milestone', $goal->completion_reason);
        $this->assertSame('2026-01-31', $goal->completed_at->toDateString());

        Carbon::setTestNow();
    }

    public function test_complete_goal_requires_owner(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user);

        $goal = Goal::factory()->create(['user_id' => $otherUser->id, 'status' => 'active']);

        $response = $this->postJson("/api/v1/goals/{$goal->id}/complete", []);

        $response->assertForbidden();
    }

    public function test_create_goal_review_requires_completed_goal(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $goal = Goal::factory()->create(['user_id' => $user->id, 'status' => 'active']);

        $response = $this->postJson("/api/v1/goals/{$goal->id}/review", [
            'outcome' => 'achieved',
            'feelings' => ['happy'],
            'why' => 'Reason',
            'wins' => 'Wins',
            'obstacles' => 'Obstacles',
            'lessons' => 'Lessons',
            'next_steps' => 'Next steps',
        ]);

        $response->assertStatus(422);
    }

    public function test_create_goal_review_creates_review(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-31 10:00:00'));

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $goal = Goal::factory()->create([
            'user_id' => $user->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/goals/{$goal->id}/review", [
            'outcome' => 'achieved',
            'feelings' => ['happy', 'proud'],
            'why' => 'Because I stayed consistent.',
            'wins' => 'Hit every milestone.',
            'obstacles' => 'None',
            'lessons' => 'Consistency wins.',
            'next_steps' => 'Set a new goal.',
            'advice' => 'Keep going.',
            'stats_snapshot' => ['streak' => 12],
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('goal_reviews', [
            'goal_id' => $goal->id,
            'user_id' => $user->id,
            'outcome' => 'achieved',
        ]);

        Carbon::setTestNow();
    }

    public function test_create_goal_review_prevents_duplicate(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $goal = Goal::factory()->create([
            'user_id' => $user->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        GoalReview::create([
            'goal_id' => $goal->id,
            'user_id' => $user->id,
            'outcome' => 'achieved',
            'feelings' => ['happy'],
            'why' => 'Why',
            'wins' => 'Wins',
            'obstacles' => 'Obstacles',
            'lessons' => 'Lessons',
            'next_steps' => 'Next',
            'advice' => null,
            'stats_snapshot' => null,
        ]);

        $response = $this->postJson("/api/v1/goals/{$goal->id}/review", [
            'outcome' => 'achieved',
            'feelings' => ['happy'],
            'why' => 'Why',
            'wins' => 'Wins',
            'obstacles' => 'Obstacles',
            'lessons' => 'Lessons',
            'next_steps' => 'Next',
        ]);

        $response->assertStatus(409);
    }

    public function test_get_goal_review_returns_review(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $goal = Goal::factory()->create([
            'user_id' => $user->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $review = GoalReview::create([
            'goal_id' => $goal->id,
            'user_id' => $user->id,
            'outcome' => 'achieved',
            'feelings' => ['happy'],
            'why' => 'Why',
            'wins' => 'Wins',
            'obstacles' => 'Obstacles',
            'lessons' => 'Lessons',
            'next_steps' => 'Next',
            'advice' => null,
            'stats_snapshot' => ['streak' => 10],
        ]);

        $response = $this->getJson("/api/v1/goals/{$goal->id}/review");

        $response->assertOk();
        $this->assertSame($review->id, $response->json('review.id'));
    }

    public function test_get_goal_review_requires_owner(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user);

        $goal = Goal::factory()->create([
            'user_id' => $otherUser->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/goals/{$goal->id}/review");

        $response->assertForbidden();
    }

    public function test_get_goal_review_returns_404_when_missing(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $goal = Goal::factory()->create([
            'user_id' => $user->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/goals/{$goal->id}/review");

        $response->assertNotFound();
    }

    public function test_get_completed_goals_returns_review_summary(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $recentGoal = Goal::factory()->create([
            'user_id' => $user->id,
            'status' => 'completed',
            'completed_at' => now()->subDay(),
        ]);

        $olderGoal = Goal::factory()->create([
            'user_id' => $user->id,
            'status' => 'completed',
            'completed_at' => now()->subDays(10),
        ]);

        GoalReview::create([
            'goal_id' => $recentGoal->id,
            'user_id' => $user->id,
            'outcome' => 'achieved',
            'feelings' => ['happy'],
            'why' => 'Why',
            'wins' => 'Wins',
            'obstacles' => 'Obstacles',
            'lessons' => 'Lessons',
            'next_steps' => 'Next',
            'advice' => null,
            'stats_snapshot' => null,
        ]);

        $response = $this->getJson('/api/v1/goals/completed');

        $response->assertOk();
        $this->assertSame($recentGoal->id, $response->json('goals.0.id'));
        $this->assertSame('achieved', $response->json('goals.0.review_summary.outcome'));
        $this->assertNull($response->json('goals.1.review_summary'));
    }
}
