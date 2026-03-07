<?php

namespace Tests\Unit\Services;

use App\Models\Event;
use App\Services\GoalOccurrenceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Tests\TestCase;

class GoalOccurrenceServiceTest extends TestCase
{
    public function test_it_builds_weekly_occurrences_using_even_offsets_from_event_start(): void
    {
        $service = new GoalOccurrenceService();
        $event = $this->makeEvent([
            'id' => 10,
            'title' => 'Run',
            'scheduled_for' => '2026-01-20',
            'repeat' => [
                'frequency' => 'weekly',
                'times_per_week' => 3,
                'duration_in_weeks' => 2,
            ],
        ]);

        $occurrences = $service->buildScheduledOccurrencesForEvent(
            $event,
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-02-28')
        );

        $this->assertSame([
            '2026-01-20',
            '2026-01-22',
            '2026-01-24',
            '2026-01-27',
            '2026-01-29',
            '2026-01-31',
        ], $this->occurrenceDates($occurrences));
    }

    public function test_it_builds_monthly_occurrences_without_overflowing_short_months(): void
    {
        $service = new GoalOccurrenceService();
        $event = $this->makeEvent([
            'id' => 11,
            'title' => 'Review',
            'scheduled_for' => '2026-01-31',
            'repeat' => [
                'frequency' => 'monthly',
                'duration_in_weeks' => 8,
            ],
        ]);

        $occurrences = $service->buildScheduledOccurrencesForEvent(
            $event,
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-03-31')
        );

        $this->assertSame([
            '2026-01-31',
            '2026-02-28',
        ], $this->occurrenceDates($occurrences));
    }

    public function test_due_today_occurrence_is_not_eligible_for_miss_evaluation_until_grace_window_expires(): void
    {
        $service = new GoalOccurrenceService();
        $occurrenceAt = CarbonImmutable::parse('2026-02-07')->startOfDay();

        $this->assertFalse(
            $service->isOccurrenceEligibleForMissEvaluation(
                $occurrenceAt,
                CarbonImmutable::parse('2026-02-08 05:59:59'),
                6
            )
        );

        $this->assertTrue(
            $service->isOccurrenceEligibleForMissEvaluation(
                $occurrenceAt,
                CarbonImmutable::parse('2026-02-08 06:00:00'),
                6
            )
        );
    }

    public function test_it_returns_only_unlogged_occurrences_that_are_old_enough_to_count_as_missed(): void
    {
        $service = new GoalOccurrenceService();
        $event = $this->makeEvent([
            'id' => 12,
            'title' => 'Study',
            'scheduled_for' => '2026-02-03',
            'repeat' => [
                'frequency' => 'weekly',
                'times_per_week' => 3,
                'duration_in_weeks' => 2,
            ],
        ]);

        $loggedFeedbackLookup = [
            $service->buildFeedbackLookupKey(12, '2026-02-03') => true,
            $service->buildFeedbackLookupKey(12, '2026-02-05') => true,
        ];

        $missedOccurrences = $service->buildEligibleMissedOccurrences(
            collect([$event]),
            CarbonImmutable::parse('2026-02-01'),
            CarbonImmutable::parse('2026-02-08 07:00:00'),
            $loggedFeedbackLookup,
            6
        );

        $this->assertSame([
            '2026-02-07',
        ], $this->occurrenceDates($missedOccurrences));
    }

    /**
     * @param array<string,mixed> $attributes
     * @return Event
     */
    private function makeEvent(array $attributes): Event
    {
        $event = new Event();
        $event->forceFill($attributes);

        return $event;
    }

    /**
     * @param Collection<int,array{event_id:int,at:CarbonImmutable,label:string}> $occurrences
     * @return array<int,string>
     */
    private function occurrenceDates(Collection $occurrences): array
    {
        return array_values(array_map(function (array $occurrence): string {
            return $occurrence['at']->toDateString();
        }, $occurrences->all()));
    }
}
