<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\EventFeedback;
use App\Models\Goal;
use App\Models\GoalPartnership;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GoalPartnershipApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_participant_can_list_goal_partnerships(): void
    {
        $owner = User::factory()->create();
        $partner = User::factory()->create();
        $goal = Goal::factory()->create(['user_id' => $owner->id]);

        $partnership = GoalPartnership::create([
            'goal_id' => $goal->id,
            'initiator_user_id' => $owner->id,
            'partner_user_id' => $partner->id,
            'status' => 'active',
            'role' => 'cheerleader',
            'notify_on_alerts' => false,
            'paused_at' => null,
        ]);

        Sanctum::actingAs($partner);

        $response = $this->getJson('/api/v1/partnerships');
        $response
            ->assertOk()
            ->assertJsonPath('partnerships.0.id', $partnership->id)
            ->assertJsonPath('partnerships.0.goal.id', $goal->id)
            ->assertJsonPath('partnerships.0.is_initiator', false)
            ->assertJsonPath('partnerships.0.notify_on_alerts', false)
            ->assertJsonMissingPath('partnerships.0.goal.user_id')
            ->assertJsonMissingPath('partnerships.0.counterparty.email');
    }

    public function test_initiator_can_list_goal_partnerships_with_counterparty_view(): void
    {
        $owner = User::factory()->create(['name' => 'Owner']);
        $partner = User::factory()->create(['name' => 'Partner']);
        $goal = Goal::factory()->create(['user_id' => $owner->id]);

        $partnership = GoalPartnership::create([
            'goal_id' => $goal->id,
            'initiator_user_id' => $owner->id,
            'partner_user_id' => $partner->id,
            'status' => 'active',
            'role' => 'silent',
            'notify_on_alerts' => true,
            'paused_at' => null,
        ]);

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/partnerships')
            ->assertOk()
            ->assertJsonPath('partnerships.0.id', $partnership->id)
            ->assertJsonPath('partnerships.0.is_initiator', true)
            ->assertJsonPath('partnerships.0.notify_on_alerts', true)
            ->assertJsonPath('partnerships.0.counterparty.id', $partner->id)
            ->assertJsonPath('partnerships.0.counterparty.name', 'Partner')
            ->assertJsonMissingPath('partnerships.0.counterparty.email');
    }

    public function test_partner_can_view_signals_only_snapshot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-07 08:00:00'));

        $owner = User::factory()->create();
        $partner = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $owner->id,
            'start_date' => Carbon::parse('2026-01-20'),
        ]);

        $event = Event::factory()->create([
            'goal_id' => $goal->id,
            'scheduled_for' => Carbon::parse('2026-01-20'),
            'repeat' => [
                'frequency' => 'weekly',
                'times_per_week' => 3,
                'duration_in_weeks' => 6,
            ],
            'points' => 2,
        ]);

        EventFeedback::create([
            'event_id' => $event->id,
            'user_id' => $owner->id,
            'note' => 'Private note',
            'status' => 'completed',
            'mood' => 'happy',
            'created_at' => Carbon::parse('2026-02-07 06:00:00'),
        ]);

        EventFeedback::create([
            'event_id' => $event->id,
            'user_id' => $owner->id,
            'note' => 'Another private note',
            'status' => 'partial',
            'mood' => 'good',
            'created_at' => Carbon::parse('2026-02-05 06:00:00'),
        ]);

        $partnership = GoalPartnership::create([
            'goal_id' => $goal->id,
            'initiator_user_id' => $owner->id,
            'partner_user_id' => $partner->id,
            'status' => 'active',
            'role' => 'drill_sergeant',
            'notify_on_alerts' => false,
            'paused_at' => null,
        ]);

        Sanctum::actingAs($partner);

        $response = $this->getJson("/api/v1/partnerships/{$partnership->id}/snapshot");
        $response
            ->assertOk()
            ->assertJsonStructure([
                'partnership' => ['id', 'status', 'role', 'goal', 'counterparty'],
                'snapshot' => [
                    'streak_length',
                    'last_log_at',
                    'inactivity_days',
                    'weekly_consistency_percent',
                    'rolling_consistency_percent',
                    'points_earned',
                    'points_expected_by_today',
                    'pace_status',
                    'pace_delta',
                    'next_scheduled_event_at',
                    'next_scheduled_event_label',
                    'momentum_trend',
                    'momentum_delta_percent',
                ],
            ])
            ->assertJsonPath('snapshot.streak_length', 2)
            ->assertJsonMissingPath('snapshot.note')
            ->assertJsonMissingPath('snapshot.mood')
            ->assertJsonMissingPath('snapshot.prompt_log')
            ->assertJsonPath('partnership.notify_on_alerts', false)
            ->assertJsonMissingPath('partnership.goal.user_id')
            ->assertJsonMissingPath('partnership.counterparty.email');

        $this->assertSame(
            '2026-02-10',
            Carbon::parse($response->json('snapshot.next_scheduled_event_at'))->toDateString()
        );
        $this->assertSame('active', $response->json('partnership.status'));

        Carbon::setTestNow();
    }

    public function test_today_without_log_does_not_break_streak_and_stays_as_next_occurrence(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-07 08:00:00'));

        $owner = User::factory()->create();
        $partner = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $owner->id,
            'start_date' => Carbon::parse('2026-01-20'),
        ]);

        $event = Event::factory()->create([
            'goal_id' => $goal->id,
            'scheduled_for' => Carbon::parse('2026-01-20'),
            'repeat' => [
                'frequency' => 'weekly',
                'times_per_week' => 3,
                'duration_in_weeks' => 6,
            ],
            'points' => 2,
        ]);

        EventFeedback::create([
            'event_id' => $event->id,
            'user_id' => $owner->id,
            'note' => 'Logged on the prior scheduled day',
            'status' => 'completed',
            'mood' => 'happy',
            'created_at' => Carbon::parse('2026-02-05 06:00:00'),
        ]);

        EventFeedback::create([
            'event_id' => $event->id,
            'user_id' => $owner->id,
            'note' => 'Logged on the prior scheduled day',
            'status' => 'struggled',
            'mood' => 'meh',
            'created_at' => Carbon::parse('2026-02-03 06:00:00'),
        ]);

        $partnership = GoalPartnership::create([
            'goal_id' => $goal->id,
            'initiator_user_id' => $owner->id,
            'partner_user_id' => $partner->id,
            'status' => 'active',
            'role' => 'cheerleader',
            'paused_at' => null,
        ]);

        Sanctum::actingAs($partner);

        $response = $this->getJson("/api/v1/partnerships/{$partnership->id}/snapshot");

        $response
            ->assertOk()
            ->assertJsonPath('snapshot.streak_length', 2);

        $this->assertSame(
            '2026-02-07',
            Carbon::parse($response->json('snapshot.next_scheduled_event_at'))->toDateString()
        );

        Carbon::setTestNow();
    }

    public function test_non_participant_cannot_view_partnership_snapshot(): void
    {
        $owner = User::factory()->create();
        $partner = User::factory()->create();
        $outsider = User::factory()->create();
        $goal = Goal::factory()->create(['user_id' => $owner->id]);

        $partnership = GoalPartnership::create([
            'goal_id' => $goal->id,
            'initiator_user_id' => $owner->id,
            'partner_user_id' => $partner->id,
            'status' => 'active',
            'role' => 'cheerleader',
            'paused_at' => null,
        ]);

        Sanctum::actingAs($outsider);
        $this->getJson("/api/v1/partnerships/{$partnership->id}/snapshot")->assertForbidden();
    }

    public function test_participant_can_toggle_notify_on_alerts_setting(): void
    {
        $owner = User::factory()->create();
        $partner = User::factory()->create();
        $goal = Goal::factory()->create(['user_id' => $owner->id]);

        $partnership = GoalPartnership::create([
            'goal_id' => $goal->id,
            'initiator_user_id' => $owner->id,
            'partner_user_id' => $partner->id,
            'status' => 'paused',
            'role' => 'cheerleader',
            'notify_on_alerts' => true,
            'paused_at' => Carbon::parse('2026-02-07 07:00:00'),
        ]);

        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/partnerships/{$partnership->id}", [
            'notify_on_alerts' => false,
        ])->assertOk()
            ->assertJsonPath('partnership.notify_on_alerts', false)
            ->assertJsonPath('partnership.status', 'paused');

        $partnership->refresh();
        $this->assertFalse($partnership->notify_on_alerts);
        $this->assertSame('paused', $partnership->status);
        $this->assertNotNull($partnership->paused_at);

        Sanctum::actingAs($partner);

        $this->patchJson("/api/v1/partnerships/{$partnership->id}", [
            'notify_on_alerts' => true,
        ])->assertOk()
            ->assertJsonPath('partnership.notify_on_alerts', true)
            ->assertJsonPath('partnership.status', 'paused');

        $this->assertTrue($partnership->fresh()->notify_on_alerts);
    }

    public function test_paused_partnership_still_allows_snapshot_access(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-07 08:00:00'));

        $owner = User::factory()->create();
        $partner = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $owner->id,
            'start_date' => Carbon::parse('2026-01-20'),
        ]);

        $event = Event::factory()->create([
            'goal_id' => $goal->id,
            'scheduled_for' => Carbon::parse('2026-01-20'),
            'repeat' => [
                'frequency' => 'weekly',
                'times_per_week' => 2,
                'duration_in_weeks' => 4,
            ],
            'points' => 2,
        ]);

        EventFeedback::create([
            'event_id' => $event->id,
            'user_id' => $owner->id,
            'note' => 'Private note',
            'status' => 'completed',
            'mood' => 'happy',
            'created_at' => Carbon::parse('2026-02-06 06:00:00'),
        ]);

        $partnership = GoalPartnership::create([
            'goal_id' => $goal->id,
            'initiator_user_id' => $owner->id,
            'partner_user_id' => $partner->id,
            'status' => 'paused',
            'role' => 'cheerleader',
            'paused_at' => Carbon::parse('2026-02-07 07:00:00'),
        ]);

        Sanctum::actingAs($partner);

        $this->getJson("/api/v1/partnerships/{$partnership->id}/snapshot")
            ->assertOk()
            ->assertJsonPath('partnership.status', 'paused')
            ->assertJsonMissingPath('partnership.goal.user_id')
            ->assertJsonMissingPath('partnership.counterparty.email');

        Carbon::setTestNow();
    }

    public function test_participants_can_pause_unpause_and_unlink_partnership(): void
    {
        $owner = User::factory()->create();
        $partner = User::factory()->create();
        $goal = Goal::factory()->create(['user_id' => $owner->id]);

        $partnership = GoalPartnership::create([
            'goal_id' => $goal->id,
            'initiator_user_id' => $owner->id,
            'partner_user_id' => $partner->id,
            'status' => 'active',
            'role' => 'cheerleader',
            'notify_on_alerts' => false,
            'paused_at' => null,
        ]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/partnerships/{$partnership->id}/pause")
            ->assertOk()
            ->assertJsonPath('partnership.status', 'paused')
            ->assertJsonPath('partnership.notify_on_alerts', false);

        $partnership->refresh();
        $this->assertSame('paused', $partnership->status);
        $this->assertNotNull($partnership->paused_at);

        Sanctum::actingAs($partner);
        $this->postJson("/api/v1/partnerships/{$partnership->id}/unpause")
            ->assertOk()
            ->assertJsonPath('partnership.status', 'active')
            ->assertJsonPath('partnership.notify_on_alerts', false);

        $partnership->refresh();
        $this->assertSame('active', $partnership->status);
        $this->assertNull($partnership->paused_at);

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/v1/partnerships/{$partnership->id}")->assertNoContent();

        $this->assertDatabaseMissing('goal_partnerships', [
            'id' => $partnership->id,
        ]);

        Sanctum::actingAs($partner);
        $this->getJson("/api/v1/partnerships/{$partnership->id}/snapshot")->assertNotFound();
    }

    public function test_non_participant_cannot_pause_unpause_or_unlink_partnership(): void
    {
        $owner = User::factory()->create();
        $partner = User::factory()->create();
        $outsider = User::factory()->create();
        $goal = Goal::factory()->create(['user_id' => $owner->id]);

        $partnership = GoalPartnership::create([
            'goal_id' => $goal->id,
            'initiator_user_id' => $owner->id,
            'partner_user_id' => $partner->id,
            'status' => 'active',
            'role' => 'cheerleader',
            'paused_at' => null,
        ]);

        Sanctum::actingAs($outsider);

        $this->patchJson("/api/v1/partnerships/{$partnership->id}", [
            'notify_on_alerts' => false,
        ])->assertForbidden();
        $this->postJson("/api/v1/partnerships/{$partnership->id}/pause")->assertForbidden();
        $this->postJson("/api/v1/partnerships/{$partnership->id}/unpause")->assertForbidden();
        $this->deleteJson("/api/v1/partnerships/{$partnership->id}")->assertForbidden();

        $this->assertDatabaseHas('goal_partnerships', [
            'id' => $partnership->id,
            'status' => 'active',
        ]);
    }

    public function test_partnership_notify_on_alerts_update_requires_boolean(): void
    {
        $owner = User::factory()->create();
        $partner = User::factory()->create();
        $goal = Goal::factory()->create(['user_id' => $owner->id]);

        $partnership = GoalPartnership::create([
            'goal_id' => $goal->id,
            'initiator_user_id' => $owner->id,
            'partner_user_id' => $partner->id,
            'status' => 'active',
            'role' => 'cheerleader',
            'notify_on_alerts' => true,
            'paused_at' => null,
        ]);

        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/partnerships/{$partnership->id}", [
            'notify_on_alerts' => 'maybe',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['notify_on_alerts']);
    }

    public function test_partner_cannot_access_owner_private_event_feedback(): void
    {
        $owner = User::factory()->create();
        $partner = User::factory()->create();
        $goal = Goal::factory()->create(['user_id' => $owner->id]);
        $event = Event::factory()->create(['goal_id' => $goal->id]);

        GoalPartnership::create([
            'goal_id' => $goal->id,
            'initiator_user_id' => $owner->id,
            'partner_user_id' => $partner->id,
            'status' => 'active',
            'role' => 'silent',
            'paused_at' => null,
        ]);

        EventFeedback::create([
            'event_id' => $event->id,
            'user_id' => $owner->id,
            'note' => 'Private log note',
            'status' => 'completed',
            'mood' => 'happy',
        ]);

        Sanctum::actingAs($partner);

        $this->getJson("/api/v1/events/{$event->id}/feedback")->assertForbidden();
        $this->getJson("/api/v1/goals/{$goal->id}/feedback")->assertForbidden();
        $this->postJson("/api/v1/events/{$event->id}/feedback", [
            'note' => 'Attempted write',
            'status' => 'completed',
            'mood' => 'happy',
        ])->assertForbidden();
    }
}
