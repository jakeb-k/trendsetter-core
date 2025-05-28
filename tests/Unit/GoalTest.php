<?php

namespace Tests\Unit;

use App\Models\AiPlan;
use App\Models\Event;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class GoalTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    public Goal $goal;

    public User $user;

    public AiPlan $aiPlan;

    /** @var \Illuminate\Support\Collection<int, Event> */
    public $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->goal = Goal::factory()->create(['user_id' => $this->user->id]);
        $this->aiPlan = AiPlan::factory()->create(['goal_id' => $this->goal->id]);
        $this->events = Event::factory(3)->create(['goal_id' => $this->goal->id, 'ai_plan_id' => $this->aiPlan->id]);
    }

    #[Test]
    public function goal_belongs_to_a_user()
    {
        $this->assertInstanceOf(User::class, $this->goal->user);
        $this->assertEquals($this->user->id, $this->goal->user->id);
    }

    #[Test]
    public function goal_has_many_ai_plans()
    {
        $this->assertCount(1, $this->goal->ai_plans);
        $this->assertInstanceOf(AiPlan::class, $this->goal->ai_plans->first());
        $this->assertInstanceOf(HasMany::class, $this->goal->ai_plans());
    }

    #[Test]
    public function goal_has_many_events()
    {
        $this->assertCount(3, $this->goal->events);
        $this->assertInstanceOf(Event::class, $this->goal->events->first());
        $this->assertInstanceOf(HasMany::class, $this->goal->events());
    }
}
