<?php

namespace App\Services;

use App\Models\Goal;
use Carbon\Carbon;

class GoalCompletionService
{
    private array $statusPoints = [
        'nailed_it' => 4,
        'completed' => 3,
        'struggled' => 2,
        'partial' => 1,
        'skipped' => 0,
    ];

    private array $statusKeys = [
        'nailed_it',
        'completed',
        'struggled',
        'partial',
        'skipped',
    ];

    private array $moodKeys = [
        'happy',
        'good',
        'meh',
        'frustrated',
    ];

    public function compute(Goal $goal): array
    {
        $goal->loadMissing(['events.feedback']);

        $statusCounts = array_fill_keys($this->statusKeys, 0);
        $moodCounts = array_fill_keys($this->moodKeys, 0);

        $pointsEarned = 0;
        foreach ($goal->events as $event) {
            foreach ($event->feedback as $feedback) {
                $status = $feedback->status;
                if (isset($this->statusPoints[$status])) {
                    $pointsEarned += $this->statusPoints[$status];
                }
                if (isset($statusCounts[$status])) {
                    $statusCounts[$status] += 1;
                }
                $mood = $feedback->mood;
                if (isset($moodCounts[$mood])) {
                    $moodCounts[$mood] += 1;
                }
            }
        }

        $maxPossiblePoints = $this->calculateMaxPossiblePoints($goal);
        $thresholdPoints = (int) ceil($maxPossiblePoints * 0.75);

        $completionReasons = [];
        if ($pointsEarned >= $thresholdPoints) {
            $completionReasons[] = 'points_threshold';
        }

        $endDatePassed = Carbon::now()->greaterThanOrEqualTo(
            Carbon::parse($goal->end_date)->addDay()
        );
        if ($endDatePassed) {
            $completionReasons[] = 'end_date_passed';
        }

        return [
            'points_earned' => $pointsEarned,
            'max_possible_points' => $maxPossiblePoints,
            'threshold_points' => $thresholdPoints,
            'is_completable' => count($completionReasons) > 0,
            'completion_reasons' => $completionReasons,
            'status_counts' => $statusCounts,
            'mood_counts' => $moodCounts,
        ];
    }

    private function calculateMaxPossiblePoints(Goal $goal): int
    {
        $goalStart = Carbon::parse($goal->start_date)->startOfDay();
        $goalEnd = Carbon::parse($goal->end_date)->endOfDay();

        $totalOccurrences = 0;

        foreach ($goal->events as $event) {
            $eventStart = Carbon::parse($event->scheduled_for ?? $event->created_at)->startOfDay();
            $eventEnd = $goalEnd->copy();

            if (!empty($event->repeat) && !empty($event->repeat['duration_in_weeks'])) {
                $eventEnd = $eventStart->copy()->addWeeks((int) $event->repeat['duration_in_weeks'])->subDay();
            }

            if ($eventEnd->lt($goalStart) || $eventStart->gt($goalEnd)) {
                continue;
            }

            $rangeStart = $eventStart->lt($goalStart) ? $goalStart->copy() : $eventStart->copy();
            $rangeEnd = $eventEnd->gt($goalEnd) ? $goalEnd->copy() : $eventEnd->copy();

            if (empty($event->repeat)) {
                if ($eventStart->betweenIncluded($goalStart, $goalEnd)) {
                    $totalOccurrences += 1;
                }
                continue;
            }

            $frequency = $event->repeat['frequency'] ?? 'weekly';
            $timesPerWeek = (int) ($event->repeat['times_per_week'] ?? 1);
            $timesPerWeek = max(1, $timesPerWeek);

            switch ($frequency) {
                case 'daily':
                    $totalOccurrences += $rangeStart->diffInDays($rangeEnd) + 1;
                    break;
                case 'weekly':
                    $days = $rangeStart->diffInDays($rangeEnd) + 1;
                    $weeks = (int) ceil($days / 7);
                    $totalOccurrences += $weeks * $timesPerWeek;
                    break;
                case 'bi-monthly':
                    $months = $rangeStart->copy()->startOfMonth()->diffInMonths($rangeEnd->copy()->startOfMonth()) + 1;
                    $totalOccurrences += (int) ceil($months / 2);
                    break;
                case 'monthly':
                    $months = $rangeStart->copy()->startOfMonth()->diffInMonths($rangeEnd->copy()->startOfMonth()) + 1;
                    $totalOccurrences += $months;
                    break;
                default:
                    $days = $rangeStart->diffInDays($rangeEnd) + 1;
                    $weeks = (int) ceil($days / 7);
                    $totalOccurrences += $weeks * $timesPerWeek;
                    break;
            }
        }

        return $totalOccurrences * 4;
    }
}
