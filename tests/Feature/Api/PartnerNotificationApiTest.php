<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\Goal;
use App\Models\GoalPartnership;
use App\Models\GoalPartnershipAlertEvent;
use App\Models\User;
use App\Services\GoalPartnershipNotificationService;
use App\Services\PartnerAlertNotification;
use App\Services\PartnerEncouragementNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PartnerNotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_can_list_mark_read_and_clear_partner_notifications(): void
    {
        $context = $this->createAlertNotificationContext();
        Sanctum::actingAs($context['partner']);

        $this->getJson('/api/v1/partner-notifications')
            ->assertOk()
            ->assertJsonPath('notifications.0.id', $context['notification_id'])
            ->assertJsonPath('notifications.0.type', PartnerAlertNotification::DATABASE_TYPE)
            ->assertJsonPath('notifications.0.partnership.id', $context['partnership']->id)
            ->assertJsonMissingPath('notifications.0.partnership.goal.user_id');

        $this->getJson('/api/v1/partner-notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 1);

        $this->postJson("/api/v1/partner-notifications/{$context['notification_id']}/read")
            ->assertOk()
            ->assertJsonPath('notification_id', $context['notification_id']);

        $this->getJson('/api/v1/partner-notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->deleteJson('/api/v1/partner-notifications/clear-read')
            ->assertOk()
            ->assertJsonPath('deleted_count', 1);

        $this->assertCount(0, $context['partner']->fresh()->notifications);
    }

    public function test_partner_can_send_a_single_encouragement_for_an_alert(): void
    {
        $context = $this->createAlertNotificationContext();
        Sanctum::actingAs($context['partner']);

        $this->postJson("/api/v1/partner-notifications/{$context['notification_id']}/encouragement", [
            'preset_key' => 'youve_got_this',
        ])->assertCreated()
            ->assertJsonPath('encouragement.source_notification_id', $context['notification_id'])
            ->assertJsonPath('encouragement.preset_key', 'youve_got_this');

        $ownerNotification = $context['owner']->fresh()->notifications()->first();
        $this->assertNotNull($ownerNotification);
        $this->assertSame(PartnerEncouragementNotification::DATABASE_TYPE, $ownerNotification->type);
        $this->assertSame($context['notification_id'], data_get($ownerNotification->data, 'source_alert_notification_id'));
        $this->assertSame('youve_got_this', data_get($ownerNotification->data, 'preset_key'));

        $this->postJson("/api/v1/partner-notifications/{$context['notification_id']}/encouragement", [
            'preset_key' => 'small_steps_count',
        ])->assertUnprocessable();

        $sourceNotification = $context['partner']->fresh()->notifications()->firstWhere('id', $context['notification_id']);
        $this->assertTrue((bool) data_get($sourceNotification->data, 'encouragement.already_sent'));
    }

    public function test_feedback_submission_triggers_partner_alert_evaluation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10 08:00:00'));

        $owner = User::factory()->create();
        $partner = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $owner->id,
            'start_date' => Carbon::parse('2026-02-10'),
        ]);

        $event = Event::factory()->create([
            'goal_id' => $goal->id,
            'scheduled_for' => Carbon::parse('2026-02-10'),
            'repeat' => [
                'frequency' => 'weekly',
                'times_per_week' => 1,
                'duration_in_weeks' => 4,
            ],
            'points' => 2,
        ]);

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

        $this->postJson("/api/v1/events/{$event->id}/feedback", [
            'note' => 'Checked in today',
            'status' => 'completed',
            'mood' => 'happy',
        ])->assertCreated();

        $this->assertDatabaseHas('goal_partnership_alert_events', [
            'partnership_id' => $partnership->id,
            'evaluation_source' => 'log_submit',
        ]);

        Carbon::setTestNow();
    }

    /**
     * @return array{owner:User,partner:User,partnership:GoalPartnership,notification_id:string}
     */
    private function createAlertNotificationContext(): array
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

        $alertEvent = GoalPartnershipAlertEvent::create([
            'partnership_id' => $partnership->id,
            'goal_id' => $goal->id,
            'subject_user_id' => $owner->id,
            'recipient_user_id' => $partner->id,
            'evaluation_source' => 'scheduled_scan',
            'selected_alert_type' => 'consecutive_misses',
            'candidate_types' => ['consecutive_misses'],
            'outcome' => 'generated',
            'reason_codes' => ['alert_generated'],
            'dedupe_key' => 'test-dedupe-key',
            'signal_date' => '2026-02-10',
            'snapshot_excerpt' => [
                'consecutive_misses' => 2,
                'inactivity_days' => 3,
                'pace_delta' => 0,
            ],
            'evaluated_at' => now(),
        ]);

        $notificationId = app(GoalPartnershipNotificationService::class)->sendPartnerAlert(
            $partnership->load('goal'),
            $alertEvent->load('recipientUser'),
            $alertEvent->snapshot_excerpt ?? []
        );

        $alertEvent->update([
            'notification_id' => $notificationId,
        ]);

        return [
            'owner' => $owner,
            'partner' => $partner,
            'partnership' => $partnership,
            'notification_id' => $notificationId,
        ];
    }
}
