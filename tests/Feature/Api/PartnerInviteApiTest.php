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
                && str_contains($mail->inviteUrl, '/partner-invite?token=');
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

    public function test_non_owner_cannot_create_partner_invite_for_goal(): void
    {
        Mail::fake();

        $goalOwner = User::factory()->create();
        $otherUser = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $goalOwner->id,
        ]);

        Sanctum::actingAs($otherUser);

        $response = $this->postJson("/api/v1/goals/{$goal->id}/partner-invites", [
            'invitee_email' => 'buddy@example.com',
            'role' => 'cheerleader',
            'notify_on_alerts' => true,
        ]);

        $response->assertForbidden();
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
                && str_contains($mail->inviteUrl, '/partner-invite?token=');
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

    public function test_owner_can_delete_unclaimed_accepted_invite(): void
    {
        $owner = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $owner->id,
        ]);

        $invite = GoalPartnerInvite::create([
            'goal_id' => $goal->id,
            'inviter_user_id' => $owner->id,
            'invitee_email' => 'buddy@example.com',
            'status' => 'accepted',
            'role' => 'cheerleader',
            'notify_on_alerts' => true,
            'token_hash' => hash('sha256', 'accepted-token-to-delete'),
            'expires_at' => now()->addDay(),
            'responded_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($owner);

        $response = $this->deleteJson("/api/v1/partner-invites/{$invite->id}");
        $response->assertNoContent();

        $this->assertDatabaseMissing('goal_partner_invites', [
            'id' => $invite->id,
        ]);
    }

    public function test_non_owner_cannot_resend_invite_email(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
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
            'token_hash' => hash('sha256', 'cannot-resend-token'),
            'expires_at' => now()->addDay(),
            'last_sent_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($otherUser);

        $response = $this->postJson("/api/v1/partner-invites/{$invite->id}/resend");
        $response->assertForbidden();

        Mail::assertNothingSent();
    }

    public function test_public_can_resolve_pending_invite_by_token(): void
    {
        $owner = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $owner->id,
            'title' => 'Run 5k',
        ]);

        $plainToken = 'resolve-token';
        GoalPartnerInvite::create([
            'goal_id' => $goal->id,
            'inviter_user_id' => $owner->id,
            'invitee_email' => 'buddy@example.com',
            'status' => 'pending',
            'role' => 'cheerleader',
            'notify_on_alerts' => true,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->getJson('/api/v1/partner-invites/resolve?token='.$plainToken);
        $response
            ->assertOk()
            ->assertJsonPath('invite.goal_title', 'Run 5k')
            ->assertJsonPath('invite.inviter_name', $owner->name)
            ->assertJsonPath('invite.status', 'pending')
            ->assertJsonPath('invite.can_respond', true);
    }

    public function test_public_can_accept_pending_invite_and_token_becomes_single_use(): void
    {
        $owner = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $owner->id,
        ]);

        $plainToken = 'accept-token';
        $invite = GoalPartnerInvite::create([
            'goal_id' => $goal->id,
            'inviter_user_id' => $owner->id,
            'invitee_email' => 'buddy@example.com',
            'status' => 'pending',
            'role' => 'drill_sergeant',
            'notify_on_alerts' => true,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        $oldTokenHash = $invite->token_hash;

        $response = $this->postJson('/api/v1/partner-invites/respond', [
            'token' => $plainToken,
            'decision' => 'accept',
        ]);

        $response->assertOk();

        $invite->refresh();
        $this->assertSame('accepted', $invite->status);
        $this->assertNull($invite->invitee_user_id);
        $this->assertNotNull($invite->responded_at);
        $this->assertNotSame($oldTokenHash, $invite->token_hash);

        $this->assertDatabaseMissing('goal_partnerships', [
            'goal_id' => $goal->id,
        ]);

        $this->postJson('/api/v1/partner-invites/respond', [
            'token' => $plainToken,
            'decision' => 'accept',
        ])->assertNotFound();
    }

    public function test_public_can_decline_pending_invite_by_token(): void
    {
        $owner = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $owner->id,
        ]);

        $plainToken = 'decline-token';
        $invite = GoalPartnerInvite::create([
            'goal_id' => $goal->id,
            'inviter_user_id' => $owner->id,
            'invitee_email' => 'buddy@example.com',
            'status' => 'pending',
            'role' => 'silent',
            'notify_on_alerts' => true,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->postJson('/api/v1/partner-invites/respond', [
            'token' => $plainToken,
            'decision' => 'decline',
        ]);
        $response->assertOk();

        $invite->refresh();
        $this->assertSame('declined', $invite->status);
        $this->assertNull($invite->invitee_user_id);
        $this->assertNotNull($invite->responded_at);
    }

    public function test_public_resolve_endpoint_is_rate_limited_per_token(): void
    {
        $owner = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $owner->id,
            'title' => 'Rate Limited Goal',
        ]);

        $plainToken = 'rate-limit-resolve-token';
        GoalPartnerInvite::create([
            'goal_id' => $goal->id,
            'inviter_user_id' => $owner->id,
            'invitee_email' => 'buddy@example.com',
            'status' => 'pending',
            'role' => 'cheerleader',
            'notify_on_alerts' => true,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->getJson('/api/v1/partner-invites/resolve?token='.$plainToken)->assertOk();
        }

        $this->getJson('/api/v1/partner-invites/resolve?token='.$plainToken)->assertStatus(429);
    }

    public function test_cannot_create_new_invite_when_pending_invite_already_exists_for_goal(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $goal = Goal::factory()->create(['user_id' => $owner->id]);

        GoalPartnerInvite::create([
            'goal_id' => $goal->id,
            'inviter_user_id' => $owner->id,
            'invitee_email' => 'first@example.com',
            'status' => 'pending',
            'role' => 'cheerleader',
            'notify_on_alerts' => true,
            'token_hash' => hash('sha256', 'first-token'),
            'expires_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/goals/{$goal->id}/partner-invites", [
            'invitee_email' => 'second@example.com',
            'role' => 'silent',
            'notify_on_alerts' => true,
        ]);

        $response->assertStatus(409);
    }

    public function test_cannot_create_new_invite_when_accepted_unclaimed_invite_exists_for_goal(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $goal = Goal::factory()->create(['user_id' => $owner->id]);

        GoalPartnerInvite::create([
            'goal_id' => $goal->id,
            'inviter_user_id' => $owner->id,
            'invitee_email' => 'first@example.com',
            'status' => 'accepted',
            'role' => 'cheerleader',
            'notify_on_alerts' => true,
            'token_hash' => hash('sha256', 'accepted-reserved-token'),
            'expires_at' => now()->addDay(),
            'responded_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/goals/{$goal->id}/partner-invites", [
            'invitee_email' => 'second@example.com',
            'role' => 'silent',
            'notify_on_alerts' => true,
        ]);

        $response->assertStatus(409);
    }

    public function test_responding_to_expired_invite_marks_invite_expired_and_rotates_token(): void
    {
        $owner = User::factory()->create();
        $goal = Goal::factory()->create([
            'user_id' => $owner->id,
        ]);

        $plainToken = 'expired-token';
        $invite = GoalPartnerInvite::create([
            'goal_id' => $goal->id,
            'inviter_user_id' => $owner->id,
            'invitee_email' => 'buddy@example.com',
            'status' => 'pending',
            'role' => 'cheerleader',
            'notify_on_alerts' => true,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->subMinute(),
        ]);

        $oldTokenHash = $invite->token_hash;

        $this->postJson('/api/v1/partner-invites/respond', [
            'token' => $plainToken,
            'decision' => 'accept',
        ])->assertStatus(410);

        $invite->refresh();
        $this->assertSame('expired', $invite->status);
        $this->assertNotNull($invite->responded_at);
        $this->assertNotSame($oldTokenHash, $invite->token_hash);
    }
}
