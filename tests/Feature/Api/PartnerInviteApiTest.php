<?php

namespace Tests\Feature\Api;

use App\Mail\PartnerInviteMail;
use App\Models\Goal;
use App\Models\GoalPartnerInvite;
use App\Models\GoalPartnership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PartnerInviteApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_partner_invite_and_send_email(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $owner->id,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/goals/{$goal->id}/partner-invites", [
            'invitee_email' => 'buddy@example.com',
            'role' => 'cheerleader',
            'notify_on_alerts' => true,
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('goal_partner_invites', [
            'goal_id' => $goal->id,
            'inviter_user_id' => $owner->id,
            'invitee_email' => 'buddy@example.com',
            'status' => 'pending',
            'role' => 'cheerleader',
            'notify_on_alerts' => 1,
        ]);

        Mail::assertSent(PartnerInviteMail::class, function (PartnerInviteMail $mail) {
            return $mail->hasTo('buddy@example.com')
                && str_contains($mail->inviteUrl, 'trendsetter://partner-invite?token=');
        });
    }

    public function test_cannot_invite_self_as_partner(): void
    {
        Mail::fake();

        $owner = User::factory()->create([
            'email' => 'owner@example.com',
        ]);
        $goal = Goal::factory()->create([
            'user_id' => $owner->id,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/goals/{$goal->id}/partner-invites", [
            'invitee_email' => 'OWNER@example.com',
            'role' => 'cheerleader',
            'notify_on_alerts' => true,
        ]);

        $response->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_cannot_invite_when_goal_already_has_partnership(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $partner = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $owner->id,
        ]);

        GoalPartnership::create([
            'goal_id' => $goal->id,
            'initiator_user_id' => $owner->id,
            'partner_user_id' => $partner->id,
            'status' => 'active',
            'role' => 'cheerleader',
            'paused_at' => null,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/goals/{$goal->id}/partner-invites", [
            'invitee_email' => 'buddy@example.com',
            'role' => 'cheerleader',
            'notify_on_alerts' => true,
        ]);

        $response->assertStatus(409);
        Mail::assertNothingSent();
    }

    public function test_owner_can_resend_pending_invite_and_new_token_is_issued(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $owner->id,
        ]);

        $invite = GoalPartnerInvite::create([
            'goal_id' => $goal->id,
            'inviter_user_id' => $owner->id,
            'invitee_email' => 'buddy@example.com',
            'status' => 'pending',
            'role' => 'cheerleader',
            'notify_on_alerts' => true,
            'token_hash' => hash('sha256', 'old-token'),
            'expires_at' => now()->addDay(),
            'last_sent_at' => now()->subHour(),
        ]);

        $oldTokenHash = $invite->token_hash;

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/partner-invites/{$invite->id}/resend");

        $response->assertOk();

        $invite->refresh();
        $this->assertNotSame($oldTokenHash, $invite->token_hash);

        Mail::assertSent(PartnerInviteMail::class, function (PartnerInviteMail $mail) {
            return $mail->hasTo('buddy@example.com')
                && str_contains($mail->inviteUrl, 'trendsetter://partner-invite?token=');
        });
    }

    public function test_owner_can_delete_pending_invite(): void
    {
        $owner = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $owner->id,
        ]);

        $invite = GoalPartnerInvite::create([
            'goal_id' => $goal->id,
            'inviter_user_id' => $owner->id,
            'invitee_email' => 'buddy@example.com',
            'status' => 'pending',
            'role' => 'cheerleader',
            'notify_on_alerts' => true,
            'token_hash' => hash('sha256', 'token-to-delete'),
            'expires_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($owner);

        $response = $this->deleteJson("/api/v1/partner-invites/{$invite->id}");
        $response->assertNoContent();

        $this->assertDatabaseMissing('goal_partner_invites', [
            'id' => $invite->id,
        ]);
    }
}
