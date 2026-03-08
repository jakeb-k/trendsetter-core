<?php

namespace Tests\Unit\Services;

use App\Models\Event;
use App\Models\EventFeedback;
use App\Models\Goal;
use App\Models\GoalPartnership;
use App\Models\GoalPartnershipAlertEvent;
use App\Models\User;
use App\Services\GoalPartnershipAlertEvaluator;
use App\Services\PartnershipAlertEvaluationSource;
use App\Services\PartnerAlertNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalPartnershipAlertEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluator_generates_partner_alert_notification_for_consecutive_misses(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10 08:00:00'));

        $partnership = $this->createPartneredGoal();
        $alertEvent = app(GoalPartnershipAlertEvaluator::class)->evaluate(
            $partnership,
            PartnershipAlertEvaluationSource::ScheduledScan
        );

        $this->assertSame('generated', $alertEvent->outcome);
        $this->assertSame('consecutive_misses', $alertEvent->selected_alert_type->value);
        $this->assertNotNull($alertEvent->notification_id);
        $this->assertContains('consecutive_misses', $alertEvent->candidate_types);

        $partnerNotification = $partnership->partner->notifications()->first();
        $this->assertNotNull($partnerNotification);
        $this->assertSame(PartnerAlertNotification::DATABASE_TYPE, $partnerNotification->type);
        $this->assertSame('consecutive_misses', $partnerNotification->data['partner_alert_type']);

        Carbon::setTestNow();
    }

    public function test_evaluator_suppresses_duplicate_alerts_for_same_signal(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10 08:00:00'));

        $partnership = $this->createPartneredGoal();
        $evaluator = app(GoalPartnershipAlertEvaluator::class);

        $firstAlertEvent = $evaluator->evaluate($partnership, PartnershipAlertEvaluationSource::ScheduledScan);
        $secondAlertEvent = $evaluator->evaluate($partnership, PartnershipAlertEvaluationSource::ScheduledScan);

        $this->assertSame('generated', $firstAlertEvent->outcome);
        $this->assertSame('suppressed_duplicate', $secondAlertEvent->outcome);
        $this->assertCount(1, $partnership->partner->fresh()->notifications);

        Carbon::setTestNow();
    }

    public function test_evaluator_suppresses_when_notifications_are_disabled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10 08:00:00'));

        $partnership = $this->createPartneredGoal([
            'notify_on_alerts' => false,
        ]);

        $alertEvent = app(GoalPartnershipAlertEvaluator::class)->evaluate(
            $partnership,
            PartnershipAlertEvaluationSource::ScheduledScan
        );

        $this->assertSame('suppressed_notify_disabled', $alertEvent->outcome);
        $this->assertCount(0, $partnership->partner->fresh()->notifications);

        Carbon::setTestNow();
    }

    public function test_evaluator_suppresses_when_recent_generated_alert_exists_within_rate_limit_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10 08:00:00'));

        $partnership = $this->createPartneredGoal();

        $this->createGeneratedAlertEvent($partnership, [
            'dedupe_key' => 'older-generated-alert',
            'signal_date' => '2026-02-07',
            'evaluated_at' => Carbon::parse('2026-02-10 07:00:00'),
        ]);

        $alertEvent = app(GoalPartnershipAlertEvaluator::class)->evaluate(
            $partnership,
            PartnershipAlertEvaluationSource::ScheduledScan
        );

        $this->assertSame('suppressed_rate_limited', $alertEvent->outcome);
        $this->assertSame(['cooldown_active'], $alertEvent->reason_codes);
        $this->assertCount(0, $partnership->partner->fresh()->notifications);

        Carbon::setTestNow();
    }

    public function test_evaluator_suppresses_when_partnership_is_paused(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10 08:00:00'));

        $partnership = $this->createPartneredGoal([
            'status' => 'paused',
            'paused_at' => Carbon::parse('2026-02-09 09:00:00'),
        ]);

        $alertEvent = app(GoalPartnershipAlertEvaluator::class)->evaluate(
            $partnership,
            PartnershipAlertEvaluationSource::ScheduledScan
        );

        $this->assertSame('suppressed_paused', $alertEvent->outcome);
        $this->assertSame(['partnership_paused'], $alertEvent->reason_codes);
        $this->assertCount(0, $partnership->partner->fresh()->notifications);

        Carbon::setTestNow();
    }

    public function test_evaluator_suppresses_when_goal_is_completed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10 08:00:00'));

        $partnership = $this->createPartneredGoal(
            [],
            [
                'status' => 'completed',
            ]
        );

        $alertEvent = app(GoalPartnershipAlertEvaluator::class)->evaluate(
            $partnership,
            PartnershipAlertEvaluationSource::ScheduledScan
        );

        $this->assertSame('suppressed_goal_completed', $alertEvent->outcome);
        $this->assertSame(['goal_completed'], $alertEvent->reason_codes);
        $this->assertCount(0, $partnership->partner->fresh()->notifications);

        Carbon::setTestNow();
    }

    public function test_evaluator_generates_streak_broken_alert_when_streak_breaks_without_trailing_misses(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10 08:00:00'));

        $partnership = $this->createPartneredGoal(
            [],
            [
                'start_date' => Carbon::parse('2026-02-07'),
            ],
            [
                'scheduled_for' => Carbon::parse('2026-02-07'),
                'repeat' => [
                    'frequency' => 'daily',
                    'duration_in_weeks' => 2,
                ],
                'points' => 2,
            ]
        );

        $this->createFeedbackForGoalOwner($partnership, '2026-02-07');
        $this->createFeedbackForGoalOwner($partnership, '2026-02-09');

        $alertEvent = app(GoalPartnershipAlertEvaluator::class)->evaluate(
            $partnership,
            PartnershipAlertEvaluationSource::ScheduledScan
        );

        $this->assertSame('generated', $alertEvent->outcome);
        $this->assertSame('streak_broken', $alertEvent->selected_alert_type->value);
        $this->assertContains('streak_broken', $alertEvent->candidate_types);

        $partnerNotification = $partnership->partner->notifications()->first();
        $this->assertNotNull($partnerNotification);
        $this->assertSame('streak_broken', $partnerNotification->data['partner_alert_type']);

        Carbon::setTestNow();
    }

    public function test_evaluator_generates_inactivity_alert_when_last_log_crosses_threshold(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10 08:00:00'));

        $partnership = $this->createPartneredGoal(
            [],
            [
                'start_date' => Carbon::parse('2026-01-01'),
            ],
            [
                'scheduled_for' => Carbon::parse('2026-01-01'),
                'repeat' => [
                    'frequency' => 'monthly',
                    'duration_in_weeks' => 12,
                ],
                'points' => 2,
            ]
        );

        $this->createFeedbackForGoalOwner($partnership, '2026-02-01');

        $alertEvent = app(GoalPartnershipAlertEvaluator::class)->evaluate(
            $partnership,
            PartnershipAlertEvaluationSource::ScheduledScan
        );

        $this->assertSame('generated', $alertEvent->outcome);
        $this->assertSame('inactivity', $alertEvent->selected_alert_type->value);
        $this->assertContains('inactivity', $alertEvent->candidate_types);
        $this->assertGreaterThanOrEqual(3, (int) data_get($alertEvent->snapshot_excerpt, 'inactivity_days'));

        $partnerNotification = $partnership->partner->notifications()->first();
        $this->assertNotNull($partnerNotification);
        $this->assertSame('inactivity', $partnerNotification->data['partner_alert_type']);

        Carbon::setTestNow();
    }

    public function test_evaluator_generates_behind_pace_alert_without_higher_priority_candidates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10 08:00:00'));

        $partnership = $this->createPartneredGoal(
            [],
            [
                'start_date' => Carbon::parse('2026-02-07'),
            ],
            [
                'scheduled_for' => Carbon::parse('2026-02-07'),
                'repeat' => [
                    'frequency' => 'daily',
                    'duration_in_weeks' => 2,
                ],
                'points' => 2,
            ]
        );

        $this->createFeedbackForGoalOwner($partnership, '2026-02-07');
        $this->createFeedbackForGoalOwner($partnership, '2026-02-08');
        $this->createFeedbackForGoalOwner($partnership, '2026-02-09');

        $alertEvent = app(GoalPartnershipAlertEvaluator::class)->evaluate(
            $partnership,
            PartnershipAlertEvaluationSource::ScheduledScan
        );

        $this->assertSame('generated', $alertEvent->outcome);
        $this->assertSame('behind_pace', $alertEvent->selected_alert_type->value);
        $this->assertContains('behind_pace', $alertEvent->candidate_types);
        $this->assertLessThanOrEqual(-2.0, (float) data_get($alertEvent->snapshot_excerpt, 'pace_delta'));

        $partnerNotification = $partnership->partner->notifications()->first();
        $this->assertNotNull($partnerNotification);
        $this->assertSame('behind_pace', $partnerNotification->data['partner_alert_type']);

        Carbon::setTestNow();
    }

    public function test_evaluator_suppresses_when_missed_occurrence_is_still_within_grace_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10 04:00:00'));

        $partnership = $this->createPartneredGoal(
            [],
            [
                'start_date' => Carbon::parse('2026-02-09'),
            ],
            [
                'scheduled_for' => Carbon::parse('2026-02-09'),
                'repeat' => null,
                'points' => 2,
            ]
        );

        $alertEvent = app(GoalPartnershipAlertEvaluator::class)->evaluate(
            $partnership,
            PartnershipAlertEvaluationSource::ScheduledScan
        );

        $this->assertSame('suppressed_grace', $alertEvent->outcome);
        $this->assertSame(['miss_within_grace_window'], $alertEvent->reason_codes);
        $this->assertCount(0, $partnership->partner->fresh()->notifications);

        Carbon::setTestNow();
    }

    /**
     * @param array<string,mixed> $partnershipOverrides
     * @param array<string,mixed> $goalOverrides
     * @param array<string,mixed> $eventOverrides
     * @return GoalPartnership
     */
    private function createPartneredGoal(
        array $partnershipOverrides = [],
        array $goalOverrides = [],
        array $eventOverrides = []
    ): GoalPartnership
    {
        $owner = User::factory()->create();
        $partner = User::factory()->create();
        $goal = Goal::factory()->create(array_merge([
            'user_id' => $owner->id,
            'status' => 'active',
            'start_date' => Carbon::parse('2026-02-01'),
        ], $goalOverrides));

        Event::factory()->create(array_merge([
            'goal_id' => $goal->id,
            'scheduled_for' => Carbon::parse('2026-02-01'),
            'repeat' => [
                'frequency' => 'weekly',
                'times_per_week' => 2,
                'duration_in_weeks' => 4,
            ],
            'points' => 2,
        ], $eventOverrides));

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

    private function createFeedbackForGoalOwner(
        GoalPartnership $partnership,
        string $date,
        string $status = 'completed'
    ): void
    {
        $event = Event::query()->where('goal_id', $partnership->goal_id)->firstOrFail();
        $ownerUserId = $partnership->goal()->value('user_id');

        EventFeedback::create([
            'event_id' => $event->id,
            'user_id' => $ownerUserId,
            'note' => 'Logged progress',
            'status' => $status,
            'mood' => 'good',
            'created_at' => Carbon::parse($date.' 07:00:00'),
            'updated_at' => Carbon::parse($date.' 07:00:00'),
        ]);
    }

    /**
     * @param GoalPartnership $partnership
     * @param array<string,mixed> $overrides
     * @return GoalPartnershipAlertEvent
     */
    private function createGeneratedAlertEvent(GoalPartnership $partnership, array $overrides = []): GoalPartnershipAlertEvent
    {
        $ownerUserId = $partnership->goal()->value('user_id');

        return GoalPartnershipAlertEvent::create(array_merge([
            'partnership_id' => $partnership->id,
            'goal_id' => $partnership->goal_id,
            'subject_user_id' => $ownerUserId,
            'recipient_user_id' => $partnership->partner_user_id,
            'evaluation_source' => PartnershipAlertEvaluationSource::ScheduledScan->value,
            'selected_alert_type' => 'consecutive_misses',
            'candidate_types' => ['consecutive_misses'],
            'outcome' => 'generated',
            'reason_codes' => ['alert_generated'],
            'dedupe_key' => 'generated-alert',
            'signal_date' => '2026-02-08',
            'snapshot_excerpt' => [
                'consecutive_misses' => 2,
                'inactivity_days' => 2,
                'pace_delta' => 0,
            ],
            'evaluated_at' => Carbon::now(),
        ], $overrides));
    }
}
