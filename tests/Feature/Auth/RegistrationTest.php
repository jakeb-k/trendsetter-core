<?php

namespace Tests\Feature\Auth;

use App\Models\Goal;
use App\Models\GoalPartnerInvite;
use App\Models\GoalPartnership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered()
    {
        $this->markTestSkipped('UI rendering is not used in this app.');
    }

    public function test_new_users_can_register()
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_registration_auto_accepts_pending_invite_by_signup_email(): void
    {
        $inviter = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $inviter->id,
        ]);

        $invite = GoalPartnerInvite::create([
            'goal_id' => $goal->id,
            'inviter_user_id' => $inviter->id,
            'invitee_email' => 'new-partner@example.com',
            'status' => 'pending',
            'role' => 'drill_sergeant',
            'notify_on_alerts' => true,
            'token_hash' => hash('sha256', 'seed-token'),
            'expires_at' => now()->addDay(),
        ]);

        $this->post('/register', [
            'name' => 'New Partner',
            'email' => 'new-partner@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $newUser = User::where('email', 'new-partner@example.com')->first();
        $this->assertNotNull($newUser);

        $invite->refresh();
        $this->assertSame($newUser->id, $invite->invitee_user_id);
        $this->assertSame('accepted', $invite->status);

        $partnership = GoalPartnership::where('goal_id', $goal->id)->first();
        $this->assertNotNull($partnership);
        $this->assertSame($inviter->id, $partnership->initiator_user_id);
        $this->assertSame($newUser->id, $partnership->partner_user_id);
        $this->assertSame('drill_sergeant', $partnership->role);
        $this->assertSame('active', $partnership->status);
    }

    public function test_registration_accepts_multiple_pending_invites_for_different_goals(): void
    {
        $inviter = User::factory()->create();
        $goalA = Goal::factory()->create(['user_id' => $inviter->id]);
        $goalB = Goal::factory()->create(['user_id' => $inviter->id]);

        GoalPartnerInvite::create([
            'goal_id' => $goalA->id,
            'inviter_user_id' => $inviter->id,
            'invitee_email' => 'new-partner@example.com',
            'status' => 'pending',
            'role' => 'cheerleader',
            'notify_on_alerts' => true,
            'token_hash' => hash('sha256', 'seed-token-a'),
            'expires_at' => now()->addDay(),
        ]);

        GoalPartnerInvite::create([
            'goal_id' => $goalB->id,
            'inviter_user_id' => $inviter->id,
            'invitee_email' => 'new-partner@example.com',
            'status' => 'pending',
            'role' => 'silent',
            'notify_on_alerts' => true,
            'token_hash' => hash('sha256', 'seed-token-b'),
            'expires_at' => now()->addDay(),
        ]);

        $this->post('/register', [
            'name' => 'New Partner',
            'email' => 'new-partner@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $newUser = User::where('email', 'new-partner@example.com')->first();
        $this->assertNotNull($newUser);

        $this->assertSame(2, GoalPartnership::where('partner_user_id', $newUser->id)->count());
        $this->assertSame(0, GoalPartnerInvite::whereRaw('LOWER(invitee_email) = ?', ['new-partner@example.com'])->where('status', 'pending')->count());
    }

    public function test_registration_deletes_pending_invite_when_goal_already_has_partnership(): void
    {
        $inviter = User::factory()->create();
        $existingPartner = User::factory()->create();
        $goal = Goal::factory()->create(['user_id' => $inviter->id]);

        GoalPartnership::create([
            'goal_id' => $goal->id,
            'initiator_user_id' => $inviter->id,
            'partner_user_id' => $existingPartner->id,
            'status' => 'active',
            'role' => 'cheerleader',
            'paused_at' => null,
        ]);

        $invite = GoalPartnerInvite::create([
            'goal_id' => $goal->id,
            'inviter_user_id' => $inviter->id,
            'invitee_email' => 'new-partner@example.com',
            'status' => 'pending',
            'role' => 'cheerleader',
            'notify_on_alerts' => true,
            'token_hash' => hash('sha256', 'seed-token-existing-partnership'),
            'expires_at' => now()->addDay(),
        ]);

        $this->post('/register', [
            'name' => 'New Partner',
            'email' => 'new-partner@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertDatabaseMissing('goal_partner_invites', [
            'id' => $invite->id,
        ]);

        $this->assertSame(1, GoalPartnership::where('goal_id', $goal->id)->count());
        $this->assertSame($existingPartner->id, GoalPartnership::where('goal_id', $goal->id)->first()?->partner_user_id);
    }
}
