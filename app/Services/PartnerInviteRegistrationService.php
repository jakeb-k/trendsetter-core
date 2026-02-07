<?php

namespace App\Services;

use App\Models\Goal;
use App\Models\GoalPartnerInvite;
use App\Models\GoalPartnership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PartnerInviteRegistrationService
{
    /**
     * Claim accepted partner invites for the given user.
     *
     * @param User $user
     * @return void
     */
    public function claimAcceptedInvitesForUser(User $user): void
    {
        if ($user->email_verified_at === null) {
            return;
        }

        $normalizedEmail = Str::lower($user->email);

        DB::transaction(function () use ($user, $normalizedEmail) {
            $this->expireAllStalePendingInvites();
            $this->expireStaleAcceptedUnclaimedInvites();

            $matchingInvites = GoalPartnerInvite::query()
                ->where('status', 'accepted')
                ->whereNull('archived_at')
                ->where(function ($query) use ($user, $normalizedEmail) {
                    $query->where('invitee_user_id', $user->id)
                        ->orWhere(function ($subQuery) use ($normalizedEmail) {
                            $subQuery->whereNull('invitee_user_id')
                                ->whereRaw('LOWER(invitee_email) = ?', [$normalizedEmail]);
                        });
                })
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            foreach ($matchingInvites as $invite) {
                $this->claimAcceptedInviteForUser($invite, $user);
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
     * Expire accepted invites that were never claimed within the claim window.
     *
     * @param int|null $claimDays
     * @return int
     */
    public function expireStaleAcceptedUnclaimedInvites(?int $claimDays = null): int
    {
        $claimWindowDays = $claimDays ?? (int) config('services.partner_invites.accepted_claim_days', 7);
        $acceptedCutoff = now()->subDays(max(0, $claimWindowDays));

        return GoalPartnerInvite::query()
            ->where('status', 'accepted')
            ->whereNull('archived_at')
            ->whereDoesntHave('goal.partnership')
            ->where('responded_at', '<=', $acceptedCutoff)
            ->update([
                'status' => 'expired',
                'responded_at' => now(),
            ]);
    }

    /**
     * Delete stale invite history to keep the table size bounded.
     *
     * @param int $acceptedRetentionDays
     * @return array{expired_or_cancelled_deleted:int,accepted_archived:int}
     */
    public function pruneResolvedInvites(int $acceptedRetentionDays = 30): array
    {
        $acceptedCutoff = now()->subDays(max(0, $acceptedRetentionDays));

        $expiredOrCancelledDeleted = GoalPartnerInvite::query()
            ->whereIn('status', ['expired', 'cancelled'])
            ->delete();

        $acceptedArchived = GoalPartnerInvite::query()
            ->where('status', 'accepted')
            ->whereNull('archived_at')
            ->where('responded_at', '<=', $acceptedCutoff)
            ->update([
                'archived_at' => now(),
            ]);

        return [
            'expired_or_cancelled_deleted' => $expiredOrCancelledDeleted,
            'accepted_archived' => $acceptedArchived,
        ];
    }

    /**
     * Claim an accepted invite for a verified user when possible.
     *
     * @param GoalPartnerInvite $invite
     * @param User $user
     * @return void
     */
    private function claimAcceptedInviteForUser(GoalPartnerInvite $invite, User $user): void
    {
        Goal::query()
            ->whereKey($invite->goal_id)
            ->lockForUpdate()
            ->first();

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
            'responded_at' => $invite->responded_at ?? now(),
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
