<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use App\Models\GoalPartnership;
use App\Models\User;
use App\Services\GoalPartnerSnapshotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GoalPartnershipController extends Controller
{
    /**
     * List active and paused partnerships for the authenticated user.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function listGoalPartnerships()
    {
        $userId = (int) Auth::id();

        $partnerships = GoalPartnership::query()
            ->where(function ($query) use ($userId) {
                $query->where('initiator_user_id', $userId)
                    ->orWhere('partner_user_id', $userId);
            })
            ->with($this->partnershipSerializationRelations())
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'partnerships' => $partnerships->map(function (GoalPartnership $partnership) use ($userId): array {
                return $this->serializePartnership($partnership, $userId);
            })->values(),
        ]);
    }

    /**
     * Return the partner-safe goal snapshot for a partnership.
     *
     * @param GoalPartnership $partnership
     * @param GoalPartnerSnapshotService $goalPartnerSnapshotService
     * @return \Illuminate\Http\JsonResponse
     */
    public function showGoalPartnershipSnapshot(
        GoalPartnership $partnership,
        GoalPartnerSnapshotService $goalPartnerSnapshotService
    ) {
        if (!$this->isPartnershipParticipant($partnership)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $partnership->load(array_merge(
            $this->partnershipSerializationRelations(),
            ['goal.events:id,goal_id,title,scheduled_for,repeat,points']
        ));

        return response()->json([
            'partnership' => $this->serializePartnership($partnership, (int) Auth::id()),
            'snapshot' => $goalPartnerSnapshotService->buildSnapshot($partnership),
        ]);
    }

    /**
     * Update mutable partnership settings for either participant.
     *
     * @param Request $request
     * @param GoalPartnership $partnership
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateGoalPartnership(Request $request, GoalPartnership $partnership)
    {
        if (!$this->isPartnershipParticipant($partnership)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'notify_on_alerts' => ['required', 'boolean'],
        ]);

        $partnership->update([
            'notify_on_alerts' => (bool) $validated['notify_on_alerts'],
        ]);

        return response()->json([
            'partnership' => $this->serializePartnership(
                $partnership->fresh()->load($this->partnershipSerializationRelations()),
                (int) Auth::id()
            ),
        ]);
    }

    /**
     * Pause alerts for a partnership.
     *
     * @param GoalPartnership $partnership
     * @return \Illuminate\Http\JsonResponse
     */
    public function pauseGoalPartnershipAlerts(GoalPartnership $partnership)
    {
        if (!$this->isPartnershipParticipant($partnership)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($partnership->status !== 'paused') {
            $partnership->update([
                'status' => 'paused',
                'paused_at' => now(),
            ]);
        }

        return response()->json([
            'partnership' => $this->serializePartnership(
                $partnership->fresh()->load($this->partnershipSerializationRelations()),
                (int) Auth::id()
            ),
        ]);
    }

    /**
     * Resume alerts for a partnership.
     *
     * @param GoalPartnership $partnership
     * @return \Illuminate\Http\JsonResponse
     */
    public function unpauseGoalPartnershipAlerts(GoalPartnership $partnership)
    {
        if (!$this->isPartnershipParticipant($partnership)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($partnership->status !== 'active') {
            $partnership->update([
                'status' => 'active',
                'paused_at' => null,
            ]);
        }

        return response()->json([
            'partnership' => $this->serializePartnership(
                $partnership->fresh()->load($this->partnershipSerializationRelations()),
                (int) Auth::id()
            ),
        ]);
    }

    /**
     * Remove the partnership immediately and silently.
     *
     * @param GoalPartnership $partnership
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function unlinkGoalPartnership(GoalPartnership $partnership)
    {
        if (!$this->isPartnershipParticipant($partnership)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $partnership->delete();

        return response()->noContent();
    }

    /**
     * Check if the authenticated user belongs to this partnership.
     *
     * @param GoalPartnership $partnership
     * @return bool
     */
    private function isPartnershipParticipant(GoalPartnership $partnership): bool
    {
        return in_array((int) Auth::id(), [
            (int) $partnership->initiator_user_id,
            (int) $partnership->partner_user_id,
        ], true);
    }

    /**
     * Build a consistent partnership payload for API responses.
     *
     * @param GoalPartnership $partnership
     * @param int $userId
     * @return array<string,mixed>
     */
    private function serializePartnership(GoalPartnership $partnership, int $userId): array
    {
        $isInitiator = $partnership->initiator_user_id === $userId;
        $counterparty = $isInitiator ? $partnership->partner : $partnership->initiator;

        return [
            'id' => $partnership->id,
            'goal_id' => $partnership->goal_id,
            'status' => $partnership->status,
            'role' => $partnership->role,
            'notify_on_alerts' => $partnership->notify_on_alerts,
            'paused_at' => $partnership->paused_at,
            'is_initiator' => $isInitiator,
            'goal' => $this->serializeGoal($partnership->goal),
            'counterparty' => $this->serializeCounterparty($counterparty),
        ];
    }

    /**
     * Return the relationship list used to build partnership API payloads.
     *
     * @return array<int,string>
     */
    private function partnershipSerializationRelations(): array
    {
        return [
            'goal:id,user_id,title,status,start_date,end_date',
            'initiator:id,name',
            'partner:id,name',
        ];
    }

    /**
     * Build a partner-safe goal payload.
     *
     * @param Goal|null $goal
     * @return array<string,mixed>|null
     */
    private function serializeGoal(?Goal $goal): ?array
    {
        if (!$goal) {
            return null;
        }

        return [
            'id' => $goal->id,
            'title' => $goal->title,
            'status' => $goal->status,
            'start_date' => $goal->start_date?->toDateString(),
            'end_date' => $goal->end_date?->toDateString(),
        ];
    }

    /**
     * Build a minimal counterparty payload without extra identity data.
     *
     * @param User|null $user
     * @return array<string,mixed>|null
     */
    private function serializeCounterparty(?User $user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
        ];
    }
}
