<?php

namespace Tests\Unit\Services;

use App\Models\Goal;
use App\Models\User;
use App\Services\AiPlanGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class AiPlanGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function generate_plan_uses_openai_client_and_returns_content(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-31 10:00:00'));

        $clientMock = Mockery::mock();
        $chatMock = Mockery::mock();

        $clientMock->shouldReceive('chat')->once()->andReturn($chatMock);
        $chatMock->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function (array $payload) {
                return $payload['model'] === 'gpt-4o'
                    && str_contains($payload['messages'][0]['content'], 'currently: 2026-01-31')
                    && str_contains($payload['messages'][1]['content'], 'Goal: Build a habit');
            }))
            ->andReturn([
                'choices' => [
                    [
                        'message' => [
                            'content' => '{"finished":false,"message":"Need more info"}',
                        ],
                    ],
                ],
            ]);

        $generator = new AiPlanGenerator();
        $clientProperty = (new \ReflectionClass($generator))->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($generator, $clientMock);
        $response = $generator->generatePlan('Build a habit');

        $this->assertSame('{"finished":false,"message":"Need more info"}', $response);

        Carbon::setTestNow();
    }

    #[Test]
    public function store_plan_and_goal_creates_records_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $generator = new AiPlanGenerator();

        $payload = [
            'goal' => [
                'title' => 'Run a 5k',
                'description' => 'Train to run a 5k',
                'category' => 'fitness',
                'start_date' => '2026-02-01',
                'end_date' => '2026-03-01',
            ],
        ];

        $result = $generator->storePlanAndGoal($payload, ['timezone' => 'UTC']);

        $this->assertInstanceOf(Goal::class, $result['goal']);
        $this->assertSame($user->id, $result['goal']->user_id);
        $this->assertSame($result['goal']->id, $result['ai_plan']->goal_id);
        $this->assertSame(json_encode(['timezone' => 'UTC']), $result['ai_plan']->prompt_log);
    }
}
