<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Goal;
use App\Models\GoalPartnership;
use App\Models\GoalPartnershipAlertEvent;
use App\Models\User;
use App\Services\GoalPartnershipAlertEvaluator;
use App\Services\PartnershipAlertEvaluationSource;
use App\Services\PartnerAlertNotification;
use App\Services\PartnerEncouragementNotification;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class PartnerAlertConsoleCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluate_alerts_command_processes_only_eligible_partnerships_and_continues_after_failures(): void
    {
        config()->set('partner_alerts.scan_chunk_size', 1);

        $eligibleWithFailure = $this->createPartnershipWithEvent();
        $eligibleSuccess = $this->createPartnershipWithEvent();
        $this->createPartnershipWithEvent(['status' => 'paused']);
        $this->createPartnershipWithEvent(['notify_on_alerts' => false]);
        $this->createPartnershipWithEvent([], ['status' => 'completed']);
        $this->createPartnershipWithEvent([], [], false);

        $attemptedPartnershipIds = [];

        $mock = Mockery::mock(GoalPartnershipAlertEvaluator::class);
        $mock->shouldReceive('evaluate')
            ->twice()
            ->andReturnUsing(function (
                GoalPartnership $partnership,
                PartnershipAlertEvaluationSource $evaluationSource
            ) use (&$attemptedPartnershipIds, $eligibleWithFailure): GoalPartnershipAlertEvent {
                $attemptedPartnershipIds[] = $partnership->id;
                $this->assertSame(PartnershipAlertEvaluationSource::ScheduledScan, $evaluationSource);

                if ($partnership->id === $eligibleWithFailure->id) {
                    throw new \RuntimeException('Simulated evaluator failure');
                }

                return new GoalPartnershipAlertEvent();
            });

        $this->app->instance(GoalPartnershipAlertEvaluator::class, $mock);

        $this->artisan('goal-partnerships:evaluate-alerts')
            ->expectsOutput('Evaluated 1 goal partnership(s) for partner alerts.')
            ->assertSuccessful();

        sort($attemptedPartnershipIds);
        $expectedEvaluatedPartnershipIds = [$eligibleWithFailure->id, $eligibleSuccess->id];
        sort($expectedEvaluatedPartnershipIds);

        $this->assertSame($expectedEvaluatedPartnershipIds, $attemptedPartnershipIds);
    }

    public function test_partner_notifications_prune_command_respects_retention_rules(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10 08:00:00'));
        config()->set('partner_alerts.suppressed_retention_days', 7);
        config()->set('partner_alerts.read_notification_retention_days', 7);

        $partnership = $this->createPartnershipWithEvent();

        $oldSuppressed = $this->createAlertEvent($partnership, [
            'outcome' => 'suppressed_no_signal',
            'evaluated_at' => Carbon::now()->subDays(8),
        ]);
        $recentSuppressed = $this->createAlertEvent($partnership, [
            'outcome' => 'suppressed_grace',
            'evaluated_at' => Carbon::now()->subDays(2),
        ]);
        $oldGenerated = $this->createAlertEvent($partnership, [
            'outcome' => 'generated',
            'selected_alert_type' => 'consecutive_misses',
            'candidate_types' => ['consecutive_misses'],
            'dedupe_key' => 'generated-row',
            'evaluated_at' => Carbon::now()->subDays(10),
        ]);

        $partner = $partnership->partner()->firstOrFail();

        $oldReadAlertNotificationId = $this->insertPartnerNotification(
            $partner,
            PartnerAlertNotification::DATABASE_TYPE,
            Carbon::now()->subDays(9)
        );
        $oldReadEncouragementNotificationId = $this->insertPartnerNotification(
            $partner,
            PartnerEncouragementNotification::DATABASE_TYPE,
            Carbon::now()->subDays(8)
        );
        $recentReadAlertNotificationId = $this->insertPartnerNotification(
            $partner,
            PartnerAlertNotification::DATABASE_TYPE,
            Carbon::now()->subDays(2)
        );
        $unreadAlertNotificationId = $this->insertPartnerNotification(
            $partner,
            PartnerAlertNotification::DATABASE_TYPE,
            null
        );
        $oldReadNonPartnerNotificationId = $this->insertPartnerNotification(
            $partner,
            'system_message',
            Carbon::now()->subDays(10)
        );

        $this->artisan('partner-notifications:prune')
            ->expectsOutput('Deleted 1 suppressed alert event(s).')
            ->expectsOutput('Deleted 2 read partner notification(s).')
            ->assertSuccessful();

        $this->assertDatabaseMissing('goal_partnership_alert_events', ['id' => $oldSuppressed->id]);
        $this->assertDatabaseHas('goal_partnership_alert_events', ['id' => $recentSuppressed->id]);
        $this->assertDatabaseHas('goal_partnership_alert_events', ['id' => $oldGenerated->id]);

        $this->assertDatabaseMissing('notifications', ['id' => $oldReadAlertNotificationId]);
        $this->assertDatabaseMissing('notifications', ['id' => $oldReadEncouragementNotificationId]);
        $this->assertDatabaseHas('notifications', ['id' => $recentReadAlertNotificationId]);
        $this->assertDatabaseHas('notifications', ['id' => $unreadAlertNotificationId]);
        $this->assertDatabaseHas('notifications', ['id' => $oldReadNonPartnerNotificationId]);

        Carbon::setTestNow();
    }

    public function test_partner_scheduled_commands_include_overlap_guards(): void
    {
        $scheduledCommands = collect(app(Schedule::class)->events());
        $guardedCommands = [
            'partner-invites:prune',
            'goal-partnerships:evaluate-alerts',
            'partner-notifications:prune',
        ];

        foreach ($guardedCommands as $commandName) {
            $scheduledEvent = $scheduledCommands->first(
                fn (ScheduledEvent $event): bool => str_contains($event->command, $commandName)
            );

            $this->assertNotNull($scheduledEvent, "Expected [{$commandName}] to be scheduled.");
            $this->assertTrue(
                $scheduledEvent->withoutOverlapping,
                "Expected [{$commandName}] to be configured with withoutOverlapping()."
            );
        }
    }

    /**
     * @param array<string,mixed> $partnershipOverrides
     * @param array<string,mixed> $goalOverrides
     * @param bool $withEvent
     * @return GoalPartnership
     */
    private function createPartnershipWithEvent(
        array $partnershipOverrides = [],
        array $goalOverrides = [],
        bool $withEvent = true
    ): GoalPartnership {
        $owner = User::factory()->create();
        $partner = User::factory()->create();
        $goal = Goal::factory()->create(array_merge([
            'user_id' => $owner->id,
            'status' => 'active',
            'start_date' => Carbon::parse('2026-02-01'),
        ], $goalOverrides));

        if ($withEvent) {
            Event::factory()->create([
                'goal_id' => $goal->id,
                'scheduled_for' => Carbon::parse('2026-02-01'),
                'repeat' => [
                    'frequency' => 'weekly',
                    'times_per_week' => 1,
                    'duration_in_weeks' => 4,
                ],
                'points' => 2,
            ]);
        }

        return GoalPartnership::create(array_merge([
            'goal_id' => $goal->id,
            'initiator_user_id' => $owner->id,
            'partner_user_id' => $partner->id,
            'status' => 'active',
            'role' => 'cheerleader',
            'notify_on_alerts' => true,
            'paused_at' => null,
        ], $partnershipOverrides));
    }

    /**
     * @param GoalPartnership $partnership
     * @param array<string,mixed> $overrides
     * @return GoalPartnershipAlertEvent
     */
    private function createAlertEvent(GoalPartnership $partnership, array $overrides = []): GoalPartnershipAlertEvent
    {
        $ownerUserId = $partnership->goal()->value('user_id');

        return GoalPartnershipAlertEvent::create(array_merge([
            'partnership_id' => $partnership->id,
            'goal_id' => $partnership->goal_id,
            'subject_user_id' => $ownerUserId,
            'recipient_user_id' => $partnership->partner_user_id,
            'evaluation_source' => PartnershipAlertEvaluationSource::ScheduledScan->value,
            'selected_alert_type' => null,
            'candidate_types' => [],
            'outcome' => 'suppressed_no_signal',
            'reason_codes' => ['no_alert_conditions_met'],
            'dedupe_key' => null,
            'signal_date' => null,
            'snapshot_excerpt' => [],
            'evaluated_at' => Carbon::now(),
        ], $overrides));
    }

    private function insertPartnerNotification(User $user, string $type, ?Carbon $readAt): string
    {
        $notificationId = (string) Str::uuid();
        $createdAt = Carbon::now();

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
            'read_at' => $readAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $notificationId;
    }
}
