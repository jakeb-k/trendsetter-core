<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventFeedback;
use App\Models\Goal;
use App\Models\GoalPartnership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class GoalPartnerSnapshotService
{
    private const SUCCESS_STATUSES = ['completed', 'partial', 'struggled', 'nailed_it'];
    private const PACE_TOLERANCE = 0.5;
    private const MOMENTUM_THRESHOLD = 5.0;

    /**
     * Build a safe, signals-only snapshot for a partnered goal.
     *
     * @param GoalPartnership $partnership
     * @return array<string,mixed>
     */
    public function buildSnapshot(GoalPartnership $partnership): array
    {
        $partnership->loadMissing('goal.events');

        /** @var Goal $goal */
        $goal = $partnership->goal;
        $events = $goal->events;
        $ownerUserId = $goal->user_id;

        $now = CarbonImmutable::now();
        $today = $now->startOfDay();
        $goalStartDate = $this->resolveGoalStartDate($goal, $today);

        if ($events->isEmpty()) {
            return $this->buildEmptySnapshot($goalStartDate, $today);
        }

        $eventIds = $events->pluck('id')->all();
        $feedback = EventFeedback::query()
            ->whereIn('event_id', $eventIds)
            ->where('user_id', $ownerUserId)
            ->orderBy('created_at')
            ->get(['event_id', 'status', 'created_at']);

        // Get array of all logged feedback for the current goal and return in date => true array 
        // E.g. [{eventID}|{date} => true]
        $loggedFeedbackLookup = $this->buildFeedbackLookup($feedback);
        $successfulFeedbackLookup = $this->buildFeedbackLookup($feedback, self::SUCCESS_STATUSES);

        $lastLogAt = $feedback->max('created_at') ? CarbonImmutable::parse($feedback->max('created_at')) : null;
        $inactivityDays = $lastLogAt ? $lastLogAt->startOfDay()->diffInDays($today) : null;

        $scheduledOccurrencesToDate = $this->buildScheduledOccurrences($events, $goalStartDate, $today);

        $streakLength = $this->calculateStreakLength($scheduledOccurrencesToDate, $loggedFeedbackLookup, $today);
        $weeklyWindow = $this->buildWindowMetrics($events, $loggedFeedbackLookup, $today->subDays(6), $today);
        $rollingWindow = $this->buildWindowMetrics($events, $loggedFeedbackLookup, $today->subDays(27), $today);
        $previousWeekWindow = $this->buildWindowMetrics($events, $loggedFeedbackLookup, $today->subDays(13), $today->subDays(7));

        $pointsByEventId = $events->mapWithKeys(function (Event $event): array {
            return [$event->id => $this->resolveEventPointValue($event)];
        });

        $pointsEarned = round((float) $scheduledOccurrencesToDate->sum(function (array $occurrence) use ($pointsByEventId, $successfulFeedbackLookup): float {
            $lookupKey = $this->buildFeedbackLookupKey($occurrence['event_id'], $occurrence['at']->toDateString());

            return isset($successfulFeedbackLookup[$lookupKey])
                ? (float) ($pointsByEventId[$occurrence['event_id']] ?? 1.0)
                : 0.0;
        }), 2);

        $pointsExpectedByToday = round($this->calculateExpectedPoints($events, $goalStartDate, $today), 2);
        $paceDelta = round($pointsEarned - $pointsExpectedByToday, 2);
        $paceStatus = $this->determinePaceStatus($paceDelta);

        $nextScheduledOccurrence = $this->resolveNextScheduledOccurrence($events, $now, $loggedFeedbackLookup);

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
     * Resolve the start date used for expected-value calculations.
     *
     * @param Goal $goal
     * @param CarbonImmutable $today
     * @return CarbonImmutable
     */
    private function resolveGoalStartDate(Goal $goal, CarbonImmutable $today): CarbonImmutable
    {
        if ($goal->start_date !== null) {
            return CarbonImmutable::parse($goal->start_date)->startOfDay();
        }

        return CarbonImmutable::parse($goal->created_at ?? $today)->startOfDay();
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
            $lookupKey = $this->buildFeedbackLookupKey($occurrence['event_id'], $occurrenceDate);

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

        $scheduledOccurrences = $this->buildScheduledOccurrences($events, $from, $to);
        $completed = (float) $scheduledOccurrences->filter(function (array $occurrence) use ($loggedFeedbackLookup): bool {
            $lookupKey = $this->buildFeedbackLookupKey($occurrence['event_id'], $occurrence['at']->toDateString());

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
        return (float) $this->buildScheduledOccurrencesForEvent($event, $from, $to)->count();
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

    /**
     * Build an event/date lookup table from feedback rows.
     *
     * @param Collection<int,EventFeedback> $feedback
     * @param array<int,string>|null $statuses
     * @return array<string,bool>
     */
    private function buildFeedbackLookup(Collection $feedback, ?array $statuses = null): array
    {
        return $feedback
            ->filter(function (EventFeedback $entry) use ($statuses): bool {
                return $statuses === null || in_array($entry->status, $statuses, true);
            })
            ->mapWithKeys(function (EventFeedback $entry): array {
                return [
                    $this->buildFeedbackLookupKey(
                        (int) $entry->event_id,
                        CarbonImmutable::parse($entry->created_at)->toDateString()
                    ) => true,
                ];
            })
            ->all();
    }

    /**
     * Build scheduled occurrences across a collection of events for a date window.
     *
     * @param Collection<int,Event> $events
     * @param CarbonImmutable $from
     * @param CarbonImmutable $to
     * @return Collection<int,array{event_id:int,at:CarbonImmutable,label:string}>
     */
    private function buildScheduledOccurrences(Collection $events, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $events
            ->flatMap(function (Event $event) use ($from, $to): Collection {
                return $this->buildScheduledOccurrencesForEvent($event, $from, $to);
            })
            ->values();
    }

    /**
     * Build the scheduled occurrence dates for a single event in a date window.
     *
     * @param Event $event
     * @param CarbonImmutable $from
     * @param CarbonImmutable $to
     * @return Collection<int,array{event_id:int,at:CarbonImmutable,label:string}>
     */
    private function buildScheduledOccurrencesForEvent(Event $event, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $eventStart = CarbonImmutable::parse($event->scheduled_for)->startOfDay();
        $eventEnd = $this->resolveEventEndDate($event);

        $windowStart = $from->greaterThan($eventStart) ? $from->startOfDay() : $eventStart;
        $windowEnd = $to->lessThan($eventEnd) ? $to->startOfDay() : $eventEnd;

        if ($windowStart->greaterThan($windowEnd)) {
            return collect();
        }

        $label = $event->title;
        $frequency = (string) data_get($event->repeat, 'frequency', '');

        if ($frequency === '' || $event->repeat === null) {
            return collect([[
                'event_id' => (int) $event->id,
                'at' => $eventStart,
                'label' => $label,
            ]])->filter(function (array $occurrence) use ($windowStart, $windowEnd): bool {
                return $occurrence['at']->greaterThanOrEqualTo($windowStart)
                    && $occurrence['at']->lessThanOrEqualTo($windowEnd);
            })->values();
        }

        return match ($frequency) {
            'daily' => $this->buildDailyOccurrences($event, $windowStart, $windowEnd),
            'monthly' => $this->buildMonthlyOccurrences($event, $windowStart, $windowEnd),
            default => $this->buildWeeklyOccurrences($event, $windowStart, $windowEnd),
        };
    }

    /**
     * Build daily scheduled occurrences for an event.
     *
     * @param Event $event
     * @param CarbonImmutable $windowStart
     * @param CarbonImmutable $windowEnd
     * @return Collection<int,array{event_id:int,at:CarbonImmutable,label:string}>
     */
    private function buildDailyOccurrences(Event $event, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): Collection
    {
        $eventStart = CarbonImmutable::parse($event->scheduled_for)->startOfDay();
        $cursor = $windowStart->greaterThan($eventStart) ? $windowStart : $eventStart;
        $occurrences = collect();

        while ($cursor->lessThanOrEqualTo($windowEnd)) {
            $occurrences->push([
                'event_id' => (int) $event->id,
                'at' => $cursor,
                'label' => $event->title,
            ]);

            $cursor = $cursor->addDay();
        }

        return $occurrences;
    }

    /**
     * Build weekly scheduled occurrences for an event.
     *
     * @param Event $event
     * @param CarbonImmutable $windowStart
     * @param CarbonImmutable $windowEnd
     * @return Collection<int,array{event_id:int,at:CarbonImmutable,label:string}>
     */
    private function buildWeeklyOccurrences(Event $event, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): Collection
    {
        $eventStart = CarbonImmutable::parse($event->scheduled_for)->startOfDay();
        $eventEnd = $this->resolveEventEndDate($event);
        $timesPerWeek = max(1, min(7, (int) data_get($event->repeat, 'times_per_week', 1)));
        $weekOffsets = $this->buildWeeklyOccurrenceOffsets($timesPerWeek);
        $weekCursor = $eventStart;
        $occurrences = collect();

        while ($weekCursor->lessThanOrEqualTo($eventEnd)) {
            foreach ($weekOffsets as $offsetDays) {
                $occurrenceAt = $weekCursor->addDays($offsetDays);

                if ($occurrenceAt->greaterThan($eventEnd)) {
                    break;
                }

                if ($occurrenceAt->lessThan($windowStart) || $occurrenceAt->greaterThan($windowEnd)) {
                    continue;
                }

                $occurrences->push([
                    'event_id' => (int) $event->id,
                    'at' => $occurrenceAt,
                    'label' => $event->title,
                ]);
            }

            $weekCursor = $weekCursor->addWeek();
        }

        return $occurrences;
    }

    /**
     * Build monthly scheduled occurrences for an event.
     *
     * @param Event $event
     * @param CarbonImmutable $windowStart
     * @param CarbonImmutable $windowEnd
     * @return Collection<int,array{event_id:int,at:CarbonImmutable,label:string}>
     */
    private function buildMonthlyOccurrences(Event $event, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): Collection
    {
        $eventStart = CarbonImmutable::parse($event->scheduled_for)->startOfDay();
        $eventEnd = $this->resolveEventEndDate($event);
        $cursor = $eventStart;
        $occurrences = collect();

        while ($cursor->lessThanOrEqualTo($eventEnd)) {
            if ($cursor->greaterThanOrEqualTo($windowStart) && $cursor->lessThanOrEqualTo($windowEnd)) {
                $occurrences->push([
                    'event_id' => (int) $event->id,
                    'at' => $cursor,
                    'label' => $event->title,
                ]);
            }

            $cursor = $cursor->addMonthNoOverflow()->startOfDay();
        }

        return $occurrences;
    }

    /**
     * Determine the next scheduled occurrence that still matters to the user today.
     *
     * @param Collection<int,Event> $events
     * @param CarbonImmutable $now
     * @param array<string,bool> $loggedFeedbackLookup
     * @return array{at:CarbonImmutable|null,label:string|null}
     */
    private function resolveNextScheduledOccurrence(
        Collection $events,
        CarbonImmutable $now,
        array $loggedFeedbackLookup
    ): array {
        $today = $now->startOfDay();
        $nextOccurrence = $events
            ->flatMap(function (Event $event) use ($today): Collection {
                return $this->buildScheduledOccurrencesForEvent($event, $today, $this->resolveEventEndDate($event));
            })
            ->sortBy(function (array $occurrence): int {
                return $occurrence['at']->getTimestamp();
            })
            ->first(function (array $occurrence) use ($loggedFeedbackLookup, $today): bool {
                $occurrenceDate = $occurrence['at']->toDateString();
                $lookupKey = $this->buildFeedbackLookupKey($occurrence['event_id'], $occurrenceDate);

                return $occurrenceDate !== $today->toDateString() || !isset($loggedFeedbackLookup[$lookupKey]);
            });

        return [
            'at' => $nextOccurrence['at'] ?? null,
            'label' => $nextOccurrence['label'] ?? null,
        ];
    }

    /**
     * Resolve the last date an event should generate scheduled occurrences.
     *
     * @param Event $event
     * @return CarbonImmutable
     */
    private function resolveEventEndDate(Event $event): CarbonImmutable
    {
        $eventStart = CarbonImmutable::parse($event->scheduled_for)->startOfDay();
        $durationWeeks = max(1, (int) data_get($event->repeat, 'duration_in_weeks', 1));

        return $eventStart->addWeeks($durationWeeks)->subDay();
    }

    /**
     * Build evenly distributed offsets for weekly recurring events.
     *
     * @param int $timesPerWeek
     * @return array<int,int>
     */
    private function buildWeeklyOccurrenceOffsets(int $timesPerWeek): array
    {
        $offsets = [];

        for ($index = 0; $index < $timesPerWeek; $index++) {
            $offsets[] = (int) floor(($index * 7) / $timesPerWeek);
        }

        return array_values(array_unique($offsets));
    }

    /**
     * Build the lookup key used to match scheduled occurrences against feedback.
     *
     * @param int $eventId
     * @param string $date
     * @return string
     */
    private function buildFeedbackLookupKey(int $eventId, string $date): string
    {
        return $eventId.'|'.$date;
    }
}
