<?php

namespace App\Services;

use App\Models\GoalPartnerInvite;
use App\Models\GoalPartnership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PartnerInviteRegistrationService
{
    /**
     * Link and activate all pending partner invites that match a new user's email.
     *
     * @param User $user
     * @return void
     */
    public function linkPendingInvitesForNewUser(User $user): void
    {
        $normalizedEmail = Str::lower($user->email);

        DB::transaction(function () use ($user, $normalizedEmail) {
            $this->expireAllStalePendingInvites();

            $matchingInvites = GoalPartnerInvite::query()
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->whereRaw('LOWER(invitee_email) = ?', [$normalizedEmail])
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            foreach ($matchingInvites as $invite) {
                $this->activateInviteForUser($invite, $user);
            }
        });
    }

    /**
     * Expire all pending invites that are now past their expiry time.
     *
     * @return int
     */
    public function expireAllStalePendingInvites(): int
    {
        return GoalPartnerInvite::query()
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => 'expired',
                'responded_at' => now(),
            ]);
    }

    /**
     * Activate an invite for a newly registered user when possible.
     *
     * @param GoalPartnerInvite $invite
     * @param User $user
     * @return void
     */
    private function activateInviteForUser(GoalPartnerInvite $invite, User $user): void
    {
        $goalAlreadyPartnered = GoalPartnership::query()
            ->where('goal_id', $invite->goal_id)
            ->lockForUpdate()
            ->exists();

        if ($goalAlreadyPartnered) {
            $invite->delete();

            return;
        }

        GoalPartnership::create([
            'goal_id' => $invite->goal_id,
            'initiator_user_id' => $invite->inviter_user_id,
            'partner_user_id' => $user->id,
            'status' => 'active',
            'role' => $invite->role,
            'paused_at' => null,
        ]);

        $invite->update([
            'invitee_user_id' => $user->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        GoalPartnerInvite::query()
            ->where('goal_id', $invite->goal_id)
            ->where('status', 'pending')
            ->where('id', '!=', $invite->id)
            ->update([
                'status' => 'cancelled',
                'responded_at' => now(),
            ]);
    }
}
