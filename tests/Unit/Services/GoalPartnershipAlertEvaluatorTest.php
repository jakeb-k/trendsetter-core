<?php

namespace Tests\Unit\Services;

use App\Models\Event;
use App\Models\Goal;
use App\Models\GoalPartnership;
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

    /**
     * @param array<string,mixed> $partnershipOverrides
     * @return GoalPartnership
     */
    private function createPartneredGoal(array $partnershipOverrides = []): GoalPartnership
    {
        $owner = User::factory()->create();
        $partner = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $owner->id,
            'start_date' => Carbon::parse('2026-02-01'),
        ]);

        Event::factory()->create([
            'goal_id' => $goal->id,
            'scheduled_for' => Carbon::parse('2026-02-01'),
            'repeat' => [
                'frequency' => 'weekly',
                'times_per_week' => 2,
                'duration_in_weeks' => 4,
            ],
            'points' => 2,
        ]);

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
}
