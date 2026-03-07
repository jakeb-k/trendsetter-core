<?php

namespace App\Services;

use App\Models\Event;
use App\Models\GoalPartnership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class GoalPartnerSnapshotService
{
    private const SUCCESS_STATUSES = ['completed', 'partial', 'struggled', 'nailed_it'];
    private const PACE_TOLERANCE = 0.5;
    private const MOMENTUM_THRESHOLD = 5.0;

    public function __construct(
        private readonly GoalOccurrenceService $goalOccurrenceService
    ) {
    }

    /**
     * Build a safe, signals-only snapshot for a partnered goal.
     *
     * @param GoalPartnership $partnership
     * @return array<string,mixed>
     */
    public function buildSnapshot(GoalPartnership $partnership): array
    {
        $partnership->loadMissing('goal.events');

        $goal = $partnership->goal;
        $events = $goal->events;
        $ownerUserId = $goal->user_id;

        $now = CarbonImmutable::now();
        $today = $now->startOfDay();
        $goalStartDate = $this->goalOccurrenceService->resolveGoalStartDate($goal, $today);

        if ($events->isEmpty()) {
            return $this->buildEmptySnapshot($goalStartDate, $today);
        }

        $feedback = $this->goalOccurrenceService->loadFeedbackRowsForEvents($events, (int) $ownerUserId);

        // Get array of all logged feedback for the current goal and return in date => true array 
        // E.g. [{eventID}|{date} => true]
        $loggedFeedbackLookup = $this->goalOccurrenceService->buildFeedbackLookup($feedback);
        $successfulFeedbackLookup = $this->goalOccurrenceService->buildFeedbackLookup($feedback, self::SUCCESS_STATUSES);

        $lastLogAt = $feedback->max('created_at') ? CarbonImmutable::parse($feedback->max('created_at')) : null;
        $inactivityDays = $lastLogAt ? $lastLogAt->startOfDay()->diffInDays($today) : null;

        $scheduledOccurrencesToDate = $this->goalOccurrenceService->buildScheduledOccurrences($events, $goalStartDate, $today);

        $streakLength = $this->calculateStreakLength($scheduledOccurrencesToDate, $loggedFeedbackLookup, $today);
        $weeklyWindow = $this->buildWindowMetrics($events, $loggedFeedbackLookup, $today->subDays(6), $today);
        $rollingWindow = $this->buildWindowMetrics($events, $loggedFeedbackLookup, $today->subDays(27), $today);
        $previousWeekWindow = $this->buildWindowMetrics($events, $loggedFeedbackLookup, $today->subDays(13), $today->subDays(7));

        $pointsByEventId = $events->mapWithKeys(function (Event $event): array {
            return [$event->id => $this->resolveEventPointValue($event)];
        });

        $pointsEarned = round((float) $scheduledOccurrencesToDate->sum(function (array $occurrence) use ($pointsByEventId, $successfulFeedbackLookup): float {
            $lookupKey = $this->goalOccurrenceService->buildFeedbackLookupKey(
                $occurrence['event_id'],
                $occurrence['at']->toDateString()
            );

            return isset($successfulFeedbackLookup[$lookupKey])
                ? (float) ($pointsByEventId[$occurrence['event_id']] ?? 1.0)
                : 0.0;
        }), 2);

        $pointsExpectedByToday = round($this->calculateExpectedPoints($events, $goalStartDate, $today), 2);
        $paceDelta = round($pointsEarned - $pointsExpectedByToday, 2);
        $paceStatus = $this->determinePaceStatus($paceDelta);

        $nextScheduledOccurrence = $this->goalOccurrenceService->resolveNextScheduledOccurrence(
            $events,
            $now,
            $loggedFeedbackLookup
        );

        $weeklyConsistency = $weeklyWindow['consistency_percent'];
        $previousWeeklyConsistency = $previousWeekWindow['consistency_percent'];
        $momentumDelta = ($weeklyConsistency !== null && $previousWeeklyConsistency !== null)
            ? round($weeklyConsistency - $previousWeeklyConsistency, 2)
            : null;

        return [
            'streak_length' => $streakLength,
            'last_log_at' => $lastLogAt?->toIso8601String(),
            'inactivity_days' => $inactivityDays,
            'weekly_consistency_percent' => $weeklyConsistency,
            'rolling_consistency_percent' => $rollingWindow['consistency_percent'],
            'points_earned' => $pointsEarned,
            'points_expected_by_today' => $pointsExpectedByToday,
            'pace_status' => $paceStatus,
            'pace_delta' => $paceDelta,
            'next_scheduled_event_at' => $nextScheduledOccurrence['at']?->toIso8601String(),
            'next_scheduled_event_label' => $nextScheduledOccurrence['label'] ?? null,
            'momentum_trend' => $this->determineMomentumTrend($momentumDelta),
            'momentum_delta_percent' => $momentumDelta,
        ];
    }

    /**
     * Build the normalized snapshot payload stored on alert events.
     *
     * @param array<string,mixed> $snapshot
     * @param int $consecutiveMissCount
     * @return array<string,mixed>
     */
    public function buildSnapshotExcerpt(array $snapshot, int $consecutiveMissCount): array
    {
        return [
            'streak_length' => (int) ($snapshot['streak_length'] ?? 0),
            'last_log_at' => $snapshot['last_log_at'] ?? null,
            'inactivity_days' => (int) ($snapshot['inactivity_days'] ?? 0),
            'weekly_consistency_percent' => (float) ($snapshot['weekly_consistency_percent'] ?? 0),
            'rolling_consistency_percent' => (float) ($snapshot['rolling_consistency_percent'] ?? 0),
            'points_earned' => (float) ($snapshot['points_earned'] ?? 0),
            'points_expected_by_today' => (float) ($snapshot['points_expected_by_today'] ?? 0),
            'pace_status' => $snapshot['pace_status'] ?? null,
            'pace_delta' => (float) ($snapshot['pace_delta'] ?? 0),
            'next_scheduled_event_at' => $snapshot['next_scheduled_event_at'] ?? null,
            'next_scheduled_event_label' => $snapshot['next_scheduled_event_label'] ?? null,
            'momentum_trend' => $snapshot['momentum_trend'] ?? null,
            'momentum_delta_percent' => (float) ($snapshot['momentum_delta_percent'] ?? 0),
            'consecutive_misses' => $consecutiveMissCount,
        ];
    }

    /**
     * Resolve the inactivity threshold-crossing date used for alert dedupe keys.
     *
     * @param array<string,mixed> $snapshot
     * @param CarbonImmutable $goalStartDate
     * @param int $thresholdDays
     * @return string
     */
    public function resolveInactivityBoundaryDate(
        array $snapshot,
        CarbonImmutable $goalStartDate,
        int $thresholdDays
    ): string {
        $days = max(0, $thresholdDays);
        $referenceDate = $snapshot['last_log_at'] ?? null;

        if ($referenceDate !== null) {
            return CarbonImmutable::parse($referenceDate)->startOfDay()->addDays($days)->toDateString();
        }

        return $goalStartDate->addDays($days)->toDateString();
    }

    /**
     * Build a default snapshot shape when a goal has no events yet.
     *
     * @param CarbonImmutable $goalStartDate
     * @param CarbonImmutable $today
     * @return array<string,mixed>
     */
    private function buildEmptySnapshot(CarbonImmutable $goalStartDate, CarbonImmutable $today): array
    {
        return [
            'streak_length' => 0,
            'last_log_at' => null,
            'inactivity_days' => $goalStartDate->diffInDays($today),
            'weekly_consistency_percent' => null,
            'rolling_consistency_percent' => null,
            'points_earned' => 0.0,
            'points_expected_by_today' => 0.0,
            'pace_status' => 'on_track',
            'pace_delta' => 0.0,
            'next_scheduled_event_at' => null,
            'next_scheduled_event_label' => null,
            'momentum_trend' => 'stable',
            'momentum_delta_percent' => null,
        ];
    }

    /**
     * Calculate the current streak across scheduled occurrences with submitted logs.
     *
     * @param Collection<int,array{event_id:int,at:CarbonImmutable,label:string}> $scheduledOccurrences
     * @param array<string,bool> $loggedFeedbackLookup
     * @param CarbonImmutable $today
     * @return int
     */
    private function calculateStreakLength(
        Collection $scheduledOccurrences,
        array $loggedFeedbackLookup,
        CarbonImmutable $today
    ): int
    {
        $streak = 0;
        $todayDateString = $today->toDateString();

        foreach ($scheduledOccurrences->sortByDesc(function (array $occurrence): int {
            return $occurrence['at']->getTimestamp();
        }) as $occurrence) {
            $occurrenceDate = $occurrence['at']->toDateString();
            $lookupKey = $this->goalOccurrenceService->buildFeedbackLookupKey($occurrence['event_id'], $occurrenceDate);

            if ($occurrenceDate === $todayDateString && !isset($loggedFeedbackLookup[$lookupKey])) {
                continue;
            }

            if (!isset($loggedFeedbackLookup[$lookupKey])) {
                break;
            }

            $streak++;
        }

        return $streak;
    }

    /**
     * Build consistency metrics for a date window.
     *
     * @param Collection<int,Event> $events
     * @param array<string,bool> $loggedFeedbackLookup
     * @param CarbonImmutable $from
     * @param CarbonImmutable $to
     * @return array{completed:float,expected:float,consistency_percent:float|null}
     */
    private function buildWindowMetrics(
        Collection $events,
        array $loggedFeedbackLookup,
        CarbonImmutable $from,
        CarbonImmutable $to
    ): array {
        if ($from->greaterThan($to)) {
            return [
                'completed' => 0.0,
                'expected' => 0.0,
                'consistency_percent' => null,
            ];
        }

        $scheduledOccurrences = $this->goalOccurrenceService->buildScheduledOccurrences($events, $from, $to);
        $completed = (float) $scheduledOccurrences->filter(function (array $occurrence) use ($loggedFeedbackLookup): bool {
            $lookupKey = $this->goalOccurrenceService->buildFeedbackLookupKey(
                $occurrence['event_id'],
                $occurrence['at']->toDateString()
            );

            return isset($loggedFeedbackLookup[$lookupKey]);
        })->count();

        $expected = (float) $scheduledOccurrences->count();
        $consistency = $expected <= 0
            ? null
            : round(min(100.0, ($completed / $expected) * 100), 2);

        return [
            'completed' => $completed,
            'expected' => $expected,
            'consistency_percent' => $consistency,
        ];
    }

    /**
     * Calculate expected points across all events for a date window.
     *
     * @param Collection<int,Event> $events
     * @param CarbonImmutable $from
     * @param CarbonImmutable $to
     * @return float
     */
    private function calculateExpectedPoints(Collection $events, CarbonImmutable $from, CarbonImmutable $to): float
    {
        return (float) $events->sum(function (Event $event) use ($from, $to): float {
            return $this->calculateExpectedOccurrencesForEvent($event, $from, $to) * $this->resolveEventPointValue($event);
        });
    }

    /**
     * Calculate expected occurrences for a single event in a date window.
     *
     * @param Event $event
     * @param CarbonImmutable $from
     * @param CarbonImmutable $to
     * @return float
     */
    private function calculateExpectedOccurrencesForEvent(Event $event, CarbonImmutable $from, CarbonImmutable $to): float
    {
        return (float) $this->goalOccurrenceService->buildScheduledOccurrencesForEvent($event, $from, $to)->count();
    }

    /**
     * Resolve points for an event, with a minimum value of one.
     *
     * @param Event $event
     * @return float
     */
    private function resolveEventPointValue(Event $event): float
    {
        return max(1.0, (float) $event->points);
    }

    /**
     * Convert pace delta into a pace status label.
     *
     * @param float $paceDelta
     * @return string
     */
    private function determinePaceStatus(float $paceDelta): string
    {
        if (abs($paceDelta) <= self::PACE_TOLERANCE) {
            return 'on_track';
        }

        return $paceDelta > 0 ? 'ahead' : 'behind';
    }

    /**
     * Convert momentum delta into an up/down/stable trend label.
     *
     * @param float|null $momentumDelta
     * @return string
     */
    private function determineMomentumTrend(?float $momentumDelta): string
    {
        if ($momentumDelta === null || abs($momentumDelta) < self::MOMENTUM_THRESHOLD) {
            return 'stable';
        }

        return $momentumDelta > 0 ? 'up' : 'down';
    }

}
