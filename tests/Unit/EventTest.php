<?php

namespace Tests\Unit;

use App\Models\AiPlan;
use App\Models\Event;
use App\Models\EventFeedback;
use App\Models\Goal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class EventTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    public Goal $goal;

    public Event $event;

    public AiPlan $aiPlan;

    /** @var \Illuminate\Support\Collection<int, EventFeedback> */
    public $eventFeedback;

    protected function setUp(): void
    {
        parent::setUp();

        $this->goal = Goal::factory()->create();
        $this->aiPlan = AiPlan::factory()->create(['goal_id' => $this->goal->id]);
        $this->event = Event::factory()->create([
            'goal_id' => $this->goal->id,
            'ai_plan_id' => $this->aiPlan->id,
        ]);
        $this->eventFeedback = EventFeedback::factory(3)->create([
            'event_id' => $this->event->id,
        ]);
    }

    #[Test]
    public function event_belongs_to_a_goal()
    {
        $this->assertInstanceOf(Goal::class, $this->event->goal);
        $this->assertEquals($this->goal->id, $this->event->goal->id);
    }

    #[Test]
    public function event_belongs_to_a_goal_plan()
    {
        $this->assertInstanceOf(AiPlan::class, $this->event->ai_plan);
        $this->assertEquals($this->aiPlan->id, $this->event->ai_plan->id);
    }

    #[Test]
    public function event_can_have_many_feedback_entries()
    {
        $this->assertCount(3, $this->event->feedback);
        $this->assertInstanceOf(EventFeedback::class, $this->event->feedback->first());
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $this->event->feedback());
    }

}
