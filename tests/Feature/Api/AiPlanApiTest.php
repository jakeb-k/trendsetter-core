<?php

namespace Tests\Feature\Api;

use App\Models\AiPlan;
use App\Models\Event;
use App\Models\Goal;
use App\Models\User;
use App\Services\AiPlanGenerator;
use App\Services\EventGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AiPlanApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_generate_plan_returns_message_when_not_finished(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->mock(AiPlanGenerator::class, function ($mock) {
            $mock->shouldReceive('generatePlan')
                ->once()
                ->andReturn(json_encode([
                    'finished' => false,
                    'message' => 'Need more detail.',
                ]));
        });

        $this->mock(EventGenerator::class, function ($mock) {
            $mock->shouldNotReceive('createEvents');
        });

        $response = $this->postJson('/api/v1/ai-plan/chat', [
            'goal_description' => 'Learn guitar',
            'context' => ['experience' => 'beginner'],
        ]);

        $response->assertOk();
        $this->assertSame('Need more detail.', $response->json('message'));
    }

    public function test_generate_plan_returns_goal_plan_and_events_when_finished(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $goal = Goal::factory()->create(['user_id' => $user->id]);
        $aiPlan = AiPlan::factory()->create(['goal_id' => $goal->id]);
        $events = Event::factory(2)->create([
            'goal_id' => $goal->id,
            'ai_plan_id' => $aiPlan->id,
        ]);

        $payload = [
            'finished' => true,
            'goal' => [
                'title' => 'Run a 5k',
                'description' => 'Train to run a 5k',
                'category' => 'fitness',
                'start_date' => '2026-02-01',
                'end_date' => '2026-03-01',
            ],
            'events' => [
                [
                    'title' => 'First run',
                    'description' => 'Run 10 minutes',
                    'due_date' => '2026-02-02',
                ],
            ],
        ];

        $this->mock(AiPlanGenerator::class, function ($mock) use ($payload, $goal, $aiPlan) {
            $mock->shouldReceive('generatePlan')
                ->once()
                ->with('Run a 5k', ['timezone' => 'UTC'])
                ->andReturn(json_encode($payload));

            $mock->shouldReceive('storePlanAndGoal')
                ->once()
                ->with($payload, ['timezone' => 'UTC'])
                ->andReturn([
                    'goal' => $goal,
                    'ai_plan' => $aiPlan,
                ]);
        });

        $this->mock(EventGenerator::class, function ($mock) use ($payload, $goal, $aiPlan, $events) {
            $mock->shouldReceive('createEvents')
                ->once()
                ->with($payload['events'], Mockery::on(function ($arg) use ($goal, $aiPlan) {
                    return $arg['goal']->is($goal) && $arg['ai_plan']->is($aiPlan);
                }))
                ->andReturn($events);
        });

        $response = $this->postJson('/api/v1/ai-plan/chat', [
            'goal_description' => 'Run a 5k',
            'context' => ['timezone' => 'UTC'],
        ]);

        $response
            ->assertOk()
            ->assertJsonStructure([
                'goal' => ['id'],
                'ai_plan' => ['id'],
                'events',
                'finished',
            ]);

        $this->assertTrue($response->json('finished'));
        $this->assertSame($goal->id, $response->json('goal.id'));
        $this->assertSame($aiPlan->id, $response->json('ai_plan.id'));
        $this->assertCount(2, $response->json('events'));
    }
}
