<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\EventFeedback;
use App\Models\Goal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_users_cannot_create_events(): void
    {
        $response = $this->postJson('/api/v1/events', []);

        $response->assertUnauthorized();
    }

    public function test_can_create_event_for_active_goal(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-31 10:00:00'));

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $goal = Goal::factory()->create(['user_id' => $user->id, 'status' => 'active']);

        $response = $this->postJson('/api/v1/events', [
            'goal_id' => $goal->id,
            'title' => 'Daily Run',
            'description' => 'Run 20 minutes.',
            'frequency' => 'weekly',
            'times_per_week' => 3,
            'duration_in_weeks' => 4,
            'start_date' => null,
        ]);

        $response->assertOk();
        $this->assertSame('weekly', $response->json('repeat.frequency'));
        $this->assertSame('2026-01-31', Carbon::parse($response->json('scheduled_for'))->toDateString());

        $this->assertDatabaseHas('events', [
            'goal_id' => $goal->id,
            'title' => 'Daily Run',
        ]);

        Carbon::setTestNow();
    }

    public function test_cannot_create_event_for_completed_goal(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $goal = Goal::factory()->create([
            'user_id' => $user->id,
            'status' => 'completed',
        ]);

        $response = $this->postJson('/api/v1/events', [
            'goal_id' => $goal->id,
            'title' => 'Daily Run',
            'description' => 'Run 20 minutes.',
            'frequency' => 'weekly',
            'times_per_week' => 3,
            'duration_in_weeks' => 4,
            'start_date' => null,
        ]);

        $response->assertStatus(422);
        $this->assertSame('Cannot add events to a completed goal.', $response->json('message'));
    }

    public function test_store_event_validates_required_fields(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/events', []);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'goal_id',
                'title',
                'frequency',
                'times_per_week',
                'duration_in_weeks',
            ]);
    }

    public function test_store_event_feedback_creates_and_updates_same_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-31 10:00:00'));

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $event = Event::factory()->create();

        $response = $this->postJson("/api/v1/events/{$event->id}/feedback", [
            'note' => 'Felt great.',
            'status' => 'completed',
            'mood' => 'happy',
        ]);

        $response->assertCreated();
        $this->assertSame(1, EventFeedback::where('event_id', $event->id)->where('user_id', $user->id)->count());

        $response = $this->postJson("/api/v1/events/{$event->id}/feedback", [
            'note' => 'Updated note.',
            'status' => 'completed',
            'mood' => 'happy',
        ]);

        $response->assertOk();
        $this->assertSame(1, EventFeedback::where('event_id', $event->id)->where('user_id', $user->id)->count());
        $this->assertSame('Updated note.', EventFeedback::first()->note);

        Carbon::setTestNow();
    }

    public function test_get_event_feedback_returns_newest_first(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $event = Event::factory()->create();

        $older = EventFeedback::factory()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'note' => 'Old note',
            'created_at' => now()->subDays(2),
        ]);

        $newer = EventFeedback::factory()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'note' => 'New note',
            'created_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/events/{$event->id}/feedback");

        $response->assertOk();
        $this->assertSame($newer->id, $response->json('feedback.0.id'));
        $this->assertSame($older->id, $response->json('feedback.1.id'));
    }
}
