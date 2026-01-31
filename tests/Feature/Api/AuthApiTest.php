<?php

namespace Tests\Feature\Api;

use App\Models\AiPlan;
use App\Models\Event;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_login_returns_token_user_and_goals(): void
    {
        $user = User::factory()->create();
        $goal = Goal::factory()->create(['user_id' => $user->id]);
        $aiPlan = AiPlan::factory()->create(['goal_id' => $goal->id]);
        Event::factory()->create([
            'goal_id' => $goal->id,
            'ai_plan_id' => $aiPlan->id,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response
            ->assertOk()
            ->assertJsonStructure([
                'goals',
                'user' => ['id', 'name', 'email'],
                'token',
            ]);

        $this->assertSame($user->id, $response->json('user.id'));
        $this->assertNotEmpty($response->json('token'));
        $this->assertCount(1, $response->json('goals'));
        $this->assertSame(1, $user->refresh()->tokens()->count());
    }

    public function test_api_login_rejects_invalid_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable();
        $this->assertSame(0, $user->refresh()->tokens()->count());
    }
}
