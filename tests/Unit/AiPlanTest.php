<?php

namespace Tests\Unit;

use App\Models\AiPlan;
use App\Models\Event;
use App\Models\Goal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class AiPlanTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    public AiPlan $aiPlan;

    public Goal $goal;

    /** @var \Illuminate\Support\Collection<int, Event> */
    public $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->goal = Goal::factory()->create();
        $this->aiPlan = AiPlan::factory()->create([
            'goal_id' => $this->goal->id,
        ]);
        $this->events = Event::factory(3)->create([
            'goal_id' => $this->goal->id,
            'ai_plan_id' => $this->aiPlan->id,
        ]);
    }

    #[Test]
    public function ai_plan_belongs_to_a_goal()
    {
        $this->assertInstanceOf(Goal::class, $this->aiPlan->goal);
        $this->assertEquals($this->goal->id, $this->aiPlan->goal->id);
    }

    #[Test]
    public function ai_plan_has_many_events()
    {
        $this->assertCount(3, $this->aiPlan->events);
        $this->assertInstanceOf(Event::class, $this->aiPlan->events->first());
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $this->aiPlan->events());
    }
}
