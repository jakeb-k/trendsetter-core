<?php

namespace App\Http\Controllers;

use App\Mail\PartnerInviteMail;
use App\Models\Goal;
use App\Models\GoalPartnerInvite;
use App\Models\GoalPartnership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PartnerInviteController extends Controller
{
    /**
     * List partner invites for a specific goal owned by the authenticated user.
     *
     * @param Goal $goal
     * @return \Illuminate\Http\JsonResponse
     */
    public function listGoalPartnerInvites(Goal $goal)
    {
        if ($goal->user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $this->expirePendingInvitesForGoal($goal->id);

        $invites = GoalPartnerInvite::query()
            ->where('goal_id', $goal->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'invites' => $invites,
        ]);
    }

    /**
     * Create and send a new partner invite for a goal.
     *
     * @param Request $request
     * @param Goal $goal
     * @return \Illuminate\Http\JsonResponse
     */
    public function createGoalPartnerInvite(Request $request, Goal $goal)
    {
        $validated = $request->validate([
            'invitee_email' => ['required', 'string', 'email:rfc'],
            'role' => ['required', Rule::in(['cheerleader', 'drill_sergeant', 'silent'])],
            'notify_on_alerts' => ['required', 'boolean'],
        ]);

        $inviteeEmail = Str::lower($validated['invitee_email']);
        if ($inviteeEmail === Str::lower(Auth::user()->email)) {
            return response()->json([
                'message' => 'You cannot invite yourself as a partner.',
            ], 422);
        }

        $existingPartnership = GoalPartnership::query()->where('goal_id', $goal->id)->exists();
        if ($existingPartnership) {
            return response()->json([
                'message' => 'This goal already has an active partner.',
            ], 409);
        }

        $this->expirePendingInvitesForGoal($goal->id);

        $pendingInviteExists = GoalPartnerInvite::query()
            ->where('goal_id', $goal->id)
            ->where('status', 'pending')
            ->exists();

        if ($pendingInviteExists) {
            return response()->json([
                'message' => 'A pending invite already exists for this goal.',
            ], 409);
        }

        [$plainToken, $tokenHash] = $this->generateInviteTokenPair();
        $invitee = User::query()->whereRaw('LOWER(email) = ?', [$inviteeEmail])->first();

        $invite = GoalPartnerInvite::create([
            'goal_id' => $goal->id,
            'inviter_user_id' => Auth::id(),
            'invitee_user_id' => $invitee?->id,
            'invitee_email' => $inviteeEmail,
            'status' => 'pending',
            'role' => $validated['role'],
            'notify_on_alerts' => (bool) $validated['notify_on_alerts'],
            'token_hash' => $tokenHash,
            'expires_at' => now()->addHours((int) config('services.partner_invites.expiry_hours', 72)),
            'last_sent_at' => now(),
        ]);

        $invite->load(['inviter:id,name,email', 'goal:id,title']);
        Mail::to($inviteeEmail)->send(new PartnerInviteMail($invite, $this->buildInviteDeepLinkUrl($plainToken)));

        return response()->json([
            'invite' => $invite,
        ], 201);
    }

    /**
     * Resend an existing pending partner invite email with a fresh token.
     *
     * @param GoalPartnerInvite $invite
     * @return \Illuminate\Http\JsonResponse
     */
    public function resendGoalPartnerInviteEmail(GoalPartnerInvite $invite)
    {
        if ($invite->inviter_user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($invite->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending invites can be resent.',
            ], 422);
        }

        if ($invite->hasExpired()) {
            $invite->update([
                'status' => 'expired',
            ]);

            return response()->json([
                'message' => 'Invite is expired. Create a new invite instead.',
            ], 422);
        }

        [$plainToken, $tokenHash] = $this->generateInviteTokenPair();

        $invite->update([
            'token_hash' => $tokenHash,
            'last_sent_at' => now(),
        ]);

        $invite->load(['inviter:id,name,email', 'goal:id,title']);
        Mail::to($invite->invitee_email)->send(new PartnerInviteMail($invite, $this->buildInviteDeepLinkUrl($plainToken)));

        return response()->json([
            'invite' => $invite,
        ]);
    }

    /**
     * Cancel a pending partner invite.
     *
     * @param GoalPartnerInvite $invite
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancelGoalPartnerInvite(GoalPartnerInvite $invite)
    {
        if ($invite->inviter_user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($invite->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending invites can be cancelled.',
            ], 422);
        }

        $invite->update([
            'status' => 'cancelled',
            'responded_at' => now(),
        ]);

        return response()->json([
            'invite' => $invite,
        ]);
    }

    /**
     * Mark pending invites as expired when their expiry timestamp has passed.
     * 
     * This function is used when there is a connection so works effectively as a sync mechanism
     *
     * @param int $goalId
     * @return void
     */
    private function expirePendingInvitesForGoal(int $goalId): void
    {
        GoalPartnerInvite::query()
            ->where('goal_id', $goalId)
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => 'expired',
                'responded_at' => now(),
            ]);
    }

    /**
     * Generate a random plain token and its SHA-256 hash.
     *
     * @return array{0:string,1:string}
     */
    private function generateInviteTokenPair(): array
    {
        $plain = Str::random(64);
        $hash = hash('sha256', $plain);

        return [$plain, $hash];
    }

    /**
     * Build the deep link URL for invite acceptance.
     *
     * @param string $token
     * @return string
     */
    private function buildInviteDeepLinkUrl(string $token): string
    {
        $base = rtrim((string) config('services.partner_invites.url_base', 'trendsetter://partner-invite'), '/');

        return sprintf('%s?token=%s', $base, urlencode($token));
    }
}
