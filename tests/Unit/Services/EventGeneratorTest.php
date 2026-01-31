<?php

namespace Tests\Unit\Services;

use App\Models\AiPlan;
use App\Models\Event;
use App\Models\Goal;
use App\Services\EventGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class EventGeneratorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function create_events_persists_repeat_metadata(): void
    {
        $goal = Goal::factory()->create();
        $aiPlan = AiPlan::factory()->create(['goal_id' => $goal->id]);

        $eventContent = [
            [
                'title' => 'Workout',
                'description' => 'Lift weights',
                'due_date' => '2026-02-01',
                'repeat' => [
                    'frequency' => 'weekly',
                    'times_per_week' => 3,
                    'duration_in_weeks' => 4,
                ],
            ],
            [
                'title' => 'Reflection',
                'description' => 'Journal entry',
                'due_date' => '2026-02-02',
            ],
        ];

        $generator = new EventGenerator();
        $events = $generator->createEvents($eventContent, [
            'goal' => $goal,
            'ai_plan' => $aiPlan,
        ]);

        $this->assertCount(2, $events);
        $this->assertInstanceOf(Event::class, $events->first());
        $this->assertSame('weekly', $events->first()->repeat['frequency']);
        $this->assertNull($events->last()->repeat);
    }
}
