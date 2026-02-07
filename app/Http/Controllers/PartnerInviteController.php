<?php

namespace App\Http\Controllers;

use App\Mail\PartnerInviteMail;
use App\Models\Goal;
use App\Models\GoalPartnerInvite;
use App\Models\GoalPartnership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        $this->expireStaleAcceptedUnclaimedInvitesForGoal($goal->id);

        $invites = GoalPartnerInvite::query()
            ->where('goal_id', $goal->id)
            ->whereNull('archived_at')
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
        if ($goal->user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'invitee_email' => ['required', 'string', 'email:rfc'],
            'role' => ['required', Rule::in(['cheerleader', 'drill_sergeant', 'silent'])],
            'notify_on_alerts' => ['required', 'boolean'],
        ]);

        $inviteeEmail = Str::lower($validated['invitee_email']);
        if ($inviteeEmail === Str::lower((string) Auth::user()->email)) {
            return response()->json([
                'message' => 'You cannot invite yourself as a partner.',
            ], 422);
        }
        try {
            $payload = DB::transaction(function () use ($goal, $inviteeEmail, $validated) {
                Goal::query()
                    ->whereKey($goal->id)
                    ->lockForUpdate()
                    ->first();

                $existingPartnership = GoalPartnership::query()
                    ->where('goal_id', $goal->id)
                    ->exists();

                if ($existingPartnership) {
                    throw new HttpException(409, 'This goal already has an active partner.');
                }

                $this->expirePendingInvitesForGoal($goal->id);
                $this->expireStaleAcceptedUnclaimedInvitesForGoal($goal->id);

                $pendingInviteExists = GoalPartnerInvite::query()
                    ->where('goal_id', $goal->id)
                    ->whereNull('archived_at')
                    ->where('status', 'pending')
                    ->exists();

                if ($pendingInviteExists) {
                    throw new HttpException(409, 'A pending invite already exists for this goal.');
                }

                $acceptedUnclaimedInviteExists = GoalPartnerInvite::query()
                    ->where('goal_id', $goal->id)
                    ->whereNull('archived_at')
                    ->where('status', 'accepted')
                    ->whereDoesntHave('goal.partnership')
                    ->exists();

                if ($acceptedUnclaimedInviteExists) {
                    throw new HttpException(409, 'An invite has been accepted and is waiting to be claimed.');
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

                return [
                    'invite' => $invite,
                    'plain_token' => $plainToken,
                ];
            });
        } catch (HttpException $httpException) {
            return response()->json([
                'message' => $httpException->getMessage(),
            ], $httpException->getStatusCode());
        }

        /** @var GoalPartnerInvite $invite */
        $invite = $payload['invite'];
        $plainToken = $payload['plain_token'];

        $invite->load(['inviter:id,name,email', 'goal:id,title']);
        Mail::to($inviteeEmail)->send(new PartnerInviteMail($invite, $this->buildInviteUrl($plainToken)));

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
                'responded_at' => now(),
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
        Mail::to($invite->invitee_email)->send(new PartnerInviteMail($invite, $this->buildInviteUrl($plainToken)));

        return response()->json([
            'invite' => $invite,
        ]);
    }

    /**
     * Delete a pending or unclaimed accepted partner invite.
     *
     * @param GoalPartnerInvite $invite
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function cancelGoalPartnerInvite(GoalPartnerInvite $invite)
    {
        if ($invite->inviter_user_id !== Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $canDelete = $invite->status === 'pending'
            || ($invite->status === 'accepted' && !GoalPartnership::query()->where('goal_id', $invite->goal_id)->exists());

        if (!$canDelete) {
            return response()->json([
                'message' => 'Only pending or unclaimed accepted invites can be deleted.',
            ], 422);
        }

        $invite->delete();

        return response()->noContent();
    }

    /**
     * Resolve token metadata for rendering the web invite page.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resolveGoalPartnerInviteToken(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        $invite = $this->findInviteByPlainToken($validated['token']);
        if (!$invite) {
            return response()->json(['message' => 'Invite not found.'], 404);
        }

        if ($invite->status === 'pending' && $invite->hasExpired()) {
            $invite->update([
                'status' => 'expired',
                'responded_at' => now(),
            ]);
        }

        $invite->load(['inviter:id,name', 'goal:id,title']);
        $goalAlreadyPartnered = GoalPartnership::query()
            ->where('goal_id', $invite->goal_id)
            ->exists();

        return response()->json([
            'invite' => [
                'id' => $invite->id,
                'goal_id' => $invite->goal_id,
                'goal_title' => $invite->goal->title,
                'inviter_name' => $invite->inviter->name,
                'role' => $invite->role,
                'notify_on_alerts' => $invite->notify_on_alerts,
                'expires_at' => $invite->expires_at,
                'status' => $invite->status,
                'can_respond' => $invite->status === 'pending' && !$goalAlreadyPartnered,
            ],
        ]);
    }

    /**
     * Respond to a pending invite token from the web landing page.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function respondGoalPartnerInvite(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'decision' => ['required', Rule::in(['accept', 'decline'])],
        ]);

        return DB::transaction(function () use ($validated) {
            $invite = $this->findInviteByPlainToken($validated['token'], true);
            if (!$invite) {
                return response()->json(['message' => 'Invite not found.'], 404);
            }

            Goal::query()
                ->whereKey($invite->goal_id)
                ->lockForUpdate()
                ->first();

            if ($invite->status !== 'pending') {
                return response()->json([
                    'message' => 'Invite is no longer pending.',
                    'status' => $invite->status,
                ], 422);
            }

            if ($invite->hasExpired()) {
                $invite->update([
                    'status' => 'expired',
                    'responded_at' => now(),
                ]);
                $this->rotateInviteTokenHash($invite);

                return response()->json([
                    'message' => 'Invite has expired.',
                    'status' => 'expired',
                ], 410);
            }

            if ($validated['decision'] === 'accept') {
                $goalAlreadyPartnered = GoalPartnership::query()
                    ->where('goal_id', $invite->goal_id)
                    ->lockForUpdate()
                    ->exists();

                if ($goalAlreadyPartnered) {
                    $invite->update([
                        'status' => 'expired',
                        'responded_at' => now(),
                    ]);
                    $this->rotateInviteTokenHash($invite);

                    return response()->json([
                        'message' => 'This invite can no longer be claimed.',
                        'status' => 'expired',
                    ], 409);
                }

                $invite->update([
                    'status' => 'accepted',
                    'responded_at' => now(),
                ]);

                $this->rotateInviteTokenHash($invite);

                return response()->json([
                    'invite' => $invite->fresh(),
                    'message' => 'Invite accepted. Sign in or create an account with this email to activate.',
                ]);
            }

            $invite->update([
                'status' => 'declined',
                'responded_at' => now(),
            ]);

            $this->rotateInviteTokenHash($invite);

            return response()->json([
                'invite' => $invite->fresh(),
                'message' => 'Invite declined.',
            ]);
        });
    }

    /**
     * Mark pending invites as expired when their expiry timestamp has passed.
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
     * Expire accepted invites that are still unclaimed after the claim window.
     *
     * @param int $goalId
     * @return void
     */
    private function expireStaleAcceptedUnclaimedInvitesForGoal(int $goalId): void
    {
        $claimWindowDays = (int) config('services.partner_invites.accepted_claim_days', 7);
        $cutoff = now()->subDays(max(0, $claimWindowDays));

        GoalPartnerInvite::query()
            ->where('goal_id', $goalId)
            ->where('status', 'accepted')
            ->whereNull('archived_at')
            ->whereDoesntHave('goal.partnership')
            ->where('responded_at', '<=', $cutoff)
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
     * Build the public invite URL included in email.
     *
     * @param string $token
     * @return string
     */
    private function buildInviteUrl(string $token): string
    {
        $base = rtrim((string) config('services.partner_invites.url_base', 'https://app.trendsetter.com/partner-invite'), '/');

        return sprintf('%s?token=%s', $base, urlencode($token));
    }

    /**
     * Find an invite record by the plain token value.
     *
     * @param string $plainToken
     * @param bool $lockForUpdate
     * @return GoalPartnerInvite|null
     */
    private function findInviteByPlainToken(string $plainToken, bool $lockForUpdate = false): ?GoalPartnerInvite
    {
        $query = GoalPartnerInvite::query()->where('token_hash', hash('sha256', $plainToken));

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Rotate token hash to enforce single-use response links.
     *
     * @param GoalPartnerInvite $invite
     * @return void
     */
    private function rotateInviteTokenHash(GoalPartnerInvite $invite): void
    {
        $invite->update([
            'token_hash' => hash('sha256', Str::random(64)),
        ]);
    }
}
