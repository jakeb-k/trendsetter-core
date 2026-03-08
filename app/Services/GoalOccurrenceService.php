<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventFeedback;
use App\Models\Goal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class GoalOccurrenceService
{
    /**
     * Load feedback rows for a goal owner's events.
     *
     * @param Collection<int,Event> $events
     * @param int $userId
     * @return Collection<int,EventFeedback>
     */
    public function loadFeedbackRowsForEvents(Collection $events, int $userId): Collection
    {
        return EventFeedback::query()
            ->select(['event_id', 'status', 'created_at'])
            ->where('user_id', $userId)
            ->whereIn('event_id', $events->pluck('id'))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Build an event/date lookup table from feedback rows.
     *
     * @param Collection<int,EventFeedback> $feedbackRows
     * @param array<int,string>|null $statuses
     * @return array<string,bool>
     */
    public function buildFeedbackLookup(Collection $feedbackRows, ?array $statuses = null): array
    {
        return $feedbackRows
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
     * Resolve the start date used for goal-level occurrence calculations.
     *
     * @param Goal $goal
     * @param CarbonImmutable $fallback
     * @return CarbonImmutable
     */
    public function resolveGoalStartDate(Goal $goal, CarbonImmutable $fallback): CarbonImmutable
    {
        if ($goal->start_date !== null) {
            return CarbonImmutable::parse($goal->start_date)->startOfDay();
        }

        return CarbonImmutable::parse($goal->created_at ?? $fallback)->startOfDay();
    }

    /**
     * Build scheduled occurrences across a collection of events for a date window.
     *
     * @param Collection<int,Event> $events
     * @param CarbonImmutable $from
     * @param CarbonImmutable $to
     * @return Collection<int,array{event_id:int,at:CarbonImmutable,label:string}>
     */
    public function buildScheduledOccurrences(Collection $events, CarbonImmutable $from, CarbonImmutable $to): Collection
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
    public function buildScheduledOccurrencesForEvent(Event $event, CarbonImmutable $from, CarbonImmutable $to): Collection
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
     * Determine the next scheduled occurrence that still matters to the user today.
     *
     * @param Collection<int,Event> $events
     * @param CarbonImmutable $now
     * @param array<string,bool> $loggedFeedbackLookup
     * @return array{at:CarbonImmutable|null,label:string|null}
     */
    public function resolveNextScheduledOccurrence(
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
     * Return scheduled occurrences that are old enough to evaluate for misses.
     *
     * @param Collection<int,Event> $events
     * @param CarbonImmutable $from
     * @param CarbonImmutable $now
     * @param int $graceHours
     * @return Collection<int,array{event_id:int,at:CarbonImmutable,label:string}>
     */
    public function buildEligibleOccurrences(
        Collection $events,
        CarbonImmutable $from,
        CarbonImmutable $now,
        int $graceHours
    ): Collection {
        return $this->buildScheduledOccurrences($events, $from, $now->startOfDay())
            ->filter(function (array $occurrence) use ($now, $graceHours): bool {
                return $this->isOccurrenceEligibleForMissEvaluation($occurrence['at'], $now, $graceHours);
            })
            ->values();
    }

    /**
     * Return scheduled occurrences that are old enough to judge as missed.
     *
     * @param Collection<int,Event> $events
     * @param CarbonImmutable $from
     * @param CarbonImmutable $now
     * @param array<string,bool> $loggedFeedbackLookup
     * @param int $graceHours
     * @return Collection<int,array{event_id:int,at:CarbonImmutable,label:string}>
     */
    public function buildEligibleMissedOccurrences(
        Collection $events,
        CarbonImmutable $from,
        CarbonImmutable $now,
        array $loggedFeedbackLookup,
        int $graceHours
    ): Collection {
        return $this->buildEligibleOccurrences($events, $from, $now, $graceHours)
            ->filter(function (array $occurrence) use ($loggedFeedbackLookup): bool {
                $lookupKey = $this->buildFeedbackLookupKey(
                    $occurrence['event_id'],
                    $occurrence['at']->toDateString()
                );

                return !isset($loggedFeedbackLookup[$lookupKey]);
            })
            ->values();
    }

    /**
     * Count the current trailing run of missed eligible occurrences.
     *
     * @param Collection<int,array{event_id:int,at:CarbonImmutable,label:string}> $eligibleOccurrences
     * @param array<string,bool> $loggedFeedbackLookup
     * @return int
     */
    public function determineTrailingMissCount(Collection $eligibleOccurrences, array $loggedFeedbackLookup): int
    {
        $missCount = 0;

        foreach ($eligibleOccurrences->sortByDesc(function (array $occurrence): int {
            return $occurrence['at']->getTimestamp();
        }) as $occurrence) {
            $lookupKey = $this->buildFeedbackLookupKey(
                (int) $occurrence['event_id'],
                $occurrence['at']->toDateString()
            );

            if (isset($loggedFeedbackLookup[$lookupKey])) {
                break;
            }

            $missCount++;
        }

        return $missCount;
    }

    /**
     * Resolve the latest eligible missed occurrence.
     *
     * @param Collection<int,array{event_id:int,at:CarbonImmutable,label:string}> $eligibleOccurrences
     * @param array<string,bool> $loggedFeedbackLookup
     * @return array{event_id:int,at:CarbonImmutable,label:string}|null
     */
    public function resolveLatestMissedOccurrence(Collection $eligibleOccurrences, array $loggedFeedbackLookup): ?array
    {
        return $eligibleOccurrences
            ->sortByDesc(function (array $occurrence): int {
                return $occurrence['at']->getTimestamp();
            })
            ->first(function (array $occurrence) use ($loggedFeedbackLookup): bool {
                $lookupKey = $this->buildFeedbackLookupKey(
                    (int) $occurrence['event_id'],
                    $occurrence['at']->toDateString()
                );

                return !isset($loggedFeedbackLookup[$lookupKey]);
            });
    }

    /**
     * Resolve the missed occurrence that broke the current streak, if any.
     *
     * @param Collection<int,array{event_id:int,at:CarbonImmutable,label:string}> $eligibleOccurrences
     * @param array<string,bool> $loggedFeedbackLookup
     * @return array{event_id:int,at:CarbonImmutable,label:string}|null
     */
    public function resolveStreakBreakOccurrence(Collection $eligibleOccurrences, array $loggedFeedbackLookup): ?array
    {
        $loggedRun = 0;

        foreach ($eligibleOccurrences->sortBy(function (array $occurrence): int {
            return $occurrence['at']->getTimestamp();
        }) as $occurrence) {
            $lookupKey = $this->buildFeedbackLookupKey(
                (int) $occurrence['event_id'],
                $occurrence['at']->toDateString()
            );

            if (isset($loggedFeedbackLookup[$lookupKey])) {
                $loggedRun++;
                continue;
            }

            if ($loggedRun > 0) {
                return $occurrence;
            }

            $loggedRun = 0;
        }

        return null;
    }

    /**
     * Resolve the last date an event should generate scheduled occurrences.
     *
     * @param Event $event
     * @return CarbonImmutable
     */
    public function resolveEventEndDate(Event $event): CarbonImmutable
    {
        $eventStart = CarbonImmutable::parse($event->scheduled_for)->startOfDay();
        $durationWeeks = max(1, (int) data_get($event->repeat, 'duration_in_weeks', 1));

        return $eventStart->addWeeks($durationWeeks)->subDay();
    }

    /**
     * Build the lookup key used to match scheduled occurrences against feedback.
     *
     * @param int $eventId
     * @param string $date
     * @return string
     */
    public function buildFeedbackLookupKey(int $eventId, string $date): string
    {
        return $eventId.'|'.$date;
    }

    /**
     * Determine whether a scheduled occurrence is old enough to count as missed.
     *
     * @param CarbonImmutable $occurrenceAt
     * @param CarbonImmutable $now
     * @param int $graceHours
     * @return bool
     */
    public function isOccurrenceEligibleForMissEvaluation(
        CarbonImmutable $occurrenceAt,
        CarbonImmutable $now,
        int $graceHours
    ): bool {
        return $occurrenceAt
            ->startOfDay()
            ->addDay()
            ->addHours(max(0, $graceHours))
            ->lessThanOrEqualTo($now);
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
}
