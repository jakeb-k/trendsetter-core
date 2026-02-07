<?php

namespace Tests\Feature\Api;

use App\Models\AiPlan;
use App\Models\Event;
use App\Models\Goal;
use App\Models\GoalPartnerInvite;
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

    public function test_api_login_claims_accepted_invite_for_verified_existing_user(): void
    {
        $inviter = User::factory()->create();
        $goal = Goal::factory()->create(['user_id' => $inviter->id]);
        $user = User::factory()->create([
            'email' => 'existing-partner@example.com',
        ]);

        $invite = GoalPartnerInvite::create([
            'goal_id' => $goal->id,
            'inviter_user_id' => $inviter->id,
            'invitee_email' => 'existing-partner@example.com',
            'status' => 'accepted',
            'role' => 'silent',
            'notify_on_alerts' => true,
            'token_hash' => hash('sha256', 'existing-user-token'),
            'expires_at' => now()->addDay(),
            'responded_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $invite->refresh();
        $this->assertSame($user->id, $invite->invitee_user_id);

        $this->assertDatabaseHas('goal_partnerships', [
            'goal_id' => $goal->id,
            'initiator_user_id' => $inviter->id,
            'partner_user_id' => $user->id,
            'status' => 'active',
            'role' => 'silent',
        ]);
    }

    public function test_api_login_does_not_claim_accepted_invite_for_unverified_user(): void
    {
        $inviter = User::factory()->create();
        $goal = Goal::factory()->create(['user_id' => $inviter->id]);
        $user = User::factory()->unverified()->create([
            'email' => 'unverified-partner@example.com',
        ]);

        $invite = GoalPartnerInvite::create([
            'goal_id' => $goal->id,
            'inviter_user_id' => $inviter->id,
            'invitee_email' => 'unverified-partner@example.com',
            'status' => 'accepted',
            'role' => 'cheerleader',
            'notify_on_alerts' => true,
            'token_hash' => hash('sha256', 'unverified-user-token'),
            'expires_at' => now()->addDay(),
            'responded_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $invite->refresh();
        $this->assertNull($invite->invitee_user_id);

        $this->assertDatabaseMissing('goal_partnerships', [
            'goal_id' => $goal->id,
            'partner_user_id' => $user->id,
        ]);
    }
}
