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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
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
        $this->assertCount(1, $context['owner']->fresh()->notifications);
        $this->assertSame(
            'youve_got_this',
            data_get($context['owner']->fresh()->notifications()->first(), 'data.preset_key')
        );
    }

    public function test_non_recipient_cannot_mark_read_or_send_encouragement_for_partner_notification(): void
    {
        $context = $this->createAlertNotificationContext();
        Sanctum::actingAs($context['owner']);

        $this->postJson("/api/v1/partner-notifications/{$context['notification_id']}/read")
            ->assertNotFound();

        $this->postJson("/api/v1/partner-notifications/{$context['notification_id']}/encouragement", [
            'preset_key' => 'youve_got_this',
        ])->assertNotFound();

        $sourceNotification = $context['partner']->fresh()->notifications()->firstWhere('id', $context['notification_id']);
        $this->assertNotNull($sourceNotification);
        $this->assertNull($sourceNotification->read_at);
        $this->assertCount(0, $context['owner']->fresh()->notifications);
    }

    public function test_send_encouragement_requires_authentication(): void
    {
        $context = $this->createAlertNotificationContext();

        $this->postJson("/api/v1/partner-notifications/{$context['notification_id']}/encouragement", [
            'preset_key' => 'youve_got_this',
        ])->assertUnauthorized();
    }

    public function test_partner_cannot_send_encouragement_with_invalid_preset(): void
    {
        $context = $this->createAlertNotificationContext();
        Sanctum::actingAs($context['partner']);

        $this->postJson("/api/v1/partner-notifications/{$context['notification_id']}/encouragement", [
            'preset_key' => 'invalid_preset',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['preset_key']);

        $sourceNotification = $context['partner']->fresh()->notifications()->firstWhere('id', $context['notification_id']);
        $this->assertFalse((bool) data_get($sourceNotification->data, 'encouragement.already_sent', false));
        $this->assertCount(0, $context['owner']->fresh()->notifications);
    }

    public function test_encouragement_send_failure_does_not_mark_source_notification_as_already_sent(): void
    {
        $context = $this->createAlertNotificationContext();
        Sanctum::actingAs($context['partner']);

        $service = Mockery::mock(GoalPartnershipNotificationService::class);
        $service->shouldReceive('sendPartnerEncouragement')
            ->once()
            ->andThrow(new \RuntimeException('Simulated notification dispatch failure'));

        $this->app->instance(GoalPartnershipNotificationService::class, $service);

        $response = $this->postJson("/api/v1/partner-notifications/{$context['notification_id']}/encouragement", [
            'preset_key' => 'youve_got_this',
        ]);

        $this->assertGreaterThanOrEqual(500, $response->status());
        $this->assertLessThan(600, $response->status());

        $sourceNotification = $context['partner']->fresh()->notifications()->firstWhere('id', $context['notification_id']);
        $this->assertNotNull($sourceNotification);
        $this->assertFalse((bool) data_get($sourceNotification->data, 'encouragement.already_sent', false));
        $this->assertCount(0, $context['owner']->fresh()->notifications);
    }

    public function test_mark_read_is_idempotent_for_same_notification(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10 08:00:00'));

        $context = $this->createAlertNotificationContext();
        Sanctum::actingAs($context['partner']);

        $this->postJson("/api/v1/partner-notifications/{$context['notification_id']}/read")
            ->assertOk();

        $firstReadAt = $context['partner']
            ->fresh()
            ->notifications()
            ->firstWhere('id', $context['notification_id'])
            ?->read_at;

        $this->assertNotNull($firstReadAt);

        Carbon::setTestNow(Carbon::parse('2026-02-10 10:00:00'));

        $this->postJson("/api/v1/partner-notifications/{$context['notification_id']}/read")
            ->assertOk()
            ->assertJsonPath('notification_id', $context['notification_id']);

        $secondReadAt = $context['partner']
            ->fresh()
            ->notifications()
            ->firstWhere('id', $context['notification_id'])
            ?->read_at;

        $this->assertNotNull($secondReadAt);
        $this->assertTrue($firstReadAt->equalTo($secondReadAt));

        Carbon::setTestNow();
    }

    public function test_partner_can_mark_all_partner_notifications_as_read_without_touching_other_types(): void
    {
        $context = $this->createAlertNotificationContext();
        $partner = $context['partner'];
        Sanctum::actingAs($partner);

        $encouragementNotificationId = $this->insertNotificationForUser(
            $partner,
            PartnerEncouragementNotification::DATABASE_TYPE
        );
        $otherNotificationId = $this->insertNotificationForUser(
            $partner,
            'non_partner_type'
        );

        $this->getJson('/api/v1/partner-notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 2);

        $this->postJson('/api/v1/partner-notifications/read-all')
            ->assertOk()
            ->assertJsonPath('updated_count', 2);

        $this->getJson('/api/v1/partner-notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $alertNotification = $partner->fresh()->notifications()->firstWhere('id', $context['notification_id']);
        $this->assertNotNull($alertNotification?->read_at);
        $this->assertNotNull(DB::table('notifications')->where('id', $encouragementNotificationId)->value('read_at'));
        $this->assertNull(DB::table('notifications')->where('id', $otherNotificationId)->value('read_at'));
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

    private function insertNotificationForUser(User $user, string $type): string
    {
        $notificationId = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $notificationId,
            'type' => $type,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode([
                'source' => 'test',
                'title' => 'Test Notification',
                'body' => 'Body',
            ], JSON_THROW_ON_ERROR),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $notificationId;
    }
}
