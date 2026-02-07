<?php

namespace Tests\Unit\Services;

use App\Models\Goal;
use App\Models\GoalPartnerInvite;
use App\Models\User;
use App\Services\PartnerInviteRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerInviteRegistrationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_resolved_invites_deletes_expired_cancelled_and_old_accepted(): void
    {
        $inviter = User::factory()->create();
        $goal = Goal::factory()->create(['user_id' => $inviter->id]);

        $expiredInvite = GoalPartnerInvite::create([
            'goal_id' => $goal->id,
            'inviter_user_id' => $inviter->id,
            'invitee_email' => 'expired@example.com',
            'status' => 'expired',
            'role' => 'cheerleader',
            'notify_on_alerts' => true,
            'token_hash' => hash('sha256', 'expired-token'),
            'expires_at' => now()->subDays(5),
            'responded_at' => now()->subDays(5),
        ]);

        $cancelledInvite = GoalPartnerInvite::create([
            'goal_id' => $goal->id,
            'inviter_user_id' => $inviter->id,
            'invitee_email' => 'cancelled@example.com',
            'status' => 'cancelled',
            'role' => 'cheerleader',
            'notify_on_alerts' => true,
            'token_hash' => hash('sha256', 'cancelled-token'),
            'expires_at' => now()->subDays(5),
            'responded_at' => now()->subDays(5),
        ]);

        $oldAcceptedInvite = GoalPartnerInvite::create([
            'goal_id' => $goal->id,
            'inviter_user_id' => $inviter->id,
            'invitee_email' => 'old-accepted@example.com',
            'status' => 'accepted',
            'role' => 'cheerleader',
            'notify_on_alerts' => true,
            'token_hash' => hash('sha256', 'old-accepted-token'),
            'expires_at' => now()->subDays(40),
            'responded_at' => now()->subDays(40),
        ]);

        $recentAcceptedInvite = GoalPartnerInvite::create([
            'goal_id' => $goal->id,
            'inviter_user_id' => $inviter->id,
            'invitee_email' => 'recent-accepted@example.com',
            'status' => 'accepted',
            'role' => 'cheerleader',
            'notify_on_alerts' => true,
            'token_hash' => hash('sha256', 'recent-accepted-token'),
            'expires_at' => now()->subDays(2),
            'responded_at' => now()->subDays(2),
        ]);

        $service = new PartnerInviteRegistrationService();
        $result = $service->pruneResolvedInvites(30);

        $this->assertSame(2, $result['expired_or_cancelled_deleted']);
        $this->assertSame(1, $result['accepted_deleted']);

        $this->assertDatabaseMissing('goal_partner_invites', ['id' => $expiredInvite->id]);
        $this->assertDatabaseMissing('goal_partner_invites', ['id' => $cancelledInvite->id]);
        $this->assertDatabaseMissing('goal_partner_invites', ['id' => $oldAcceptedInvite->id]);
        $this->assertDatabaseHas('goal_partner_invites', ['id' => $recentAcceptedInvite->id]);
    }
}
