<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use App\Models\GoalPartnership;
use App\Models\GoalPartnershipAlertEvent;
use App\Models\User;
use App\Services\GoalPartnershipNotificationService;
use App\Services\PartnerAlertNotification;
use App\Services\PartnerEncouragementNotification;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class PartnerNotificationController extends Controller
{
    /**
     * List partner-related notifications for the authenticated user.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $perPage = min(50, max(1, (int) $request->integer('per_page', 25)));

        /** @var LengthAwarePaginator $paginator */
        $paginator = $user->notifications()
            ->whereIn('type', $this->partnerNotificationTypes())
            ->latest()
            ->paginate($perPage);

        $notifications = $paginator->getCollection();
        $partnershipIds = $notifications
            ->pluck('data.partnership_id')
            ->filter()
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values();

        $partnerships = GoalPartnership::query()
            ->whereIn('id', $partnershipIds)
            ->with([
                'goal:id,title,status',
            ])
            ->get()
            ->keyBy('id');

        return response()->json([
            'notifications' => $notifications->map(function (DatabaseNotification $notification) use ($partnerships): array {
                $partnership = $partnerships->get((int) data_get($notification->data, 'partnership_id'));

                return $this->serializeNotification(
                    $notification,
                    $partnership
                );
            })->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Return the authenticated user's unread partner notification count.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function unreadCount()
    {
        return response()->json([
            'unread_count' => Auth::user()
                ->unreadNotifications()
                ->whereIn('type', $this->partnerNotificationTypes())
                ->count(),
        ]);
    }

    /**
     * Mark a single notification as read.
     *
     * @param string $notificationId
     * @return \Illuminate\Http\JsonResponse
     */
    public function markRead(string $notificationId)
    {
        $notification = $this->findNotificationForAuthenticatedUser($notificationId);

        if (!$notification) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return response()->json([
            'notification_id' => $notification->id,
            'read_at' => $notification->fresh()->read_at,
        ]);
    }

    /**
     * Mark all partner notifications as read for the authenticated user.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function markAllRead()
    {
        $updated = Auth::user()
            ->unreadNotifications()
            ->whereIn('type', $this->partnerNotificationTypes())
            ->update(['read_at' => now()]);

        return response()->json([
            'updated_count' => $updated,
        ]);
    }

    /**
     * Hard delete all read partner notifications for the authenticated user.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function clearRead()
    {
        $deleted = Auth::user()
            ->notifications()
            ->whereIn('type', $this->partnerNotificationTypes())
            ->whereNotNull('read_at')
            ->delete();

        return response()->json([
            'deleted_count' => $deleted,
        ]);
    }

    /**
     * Send a preset encouragement for one alert notification.
     *
     * @param Request $request
     * @param string $notificationId
     * @param GoalPartnershipNotificationService $goalPartnershipNotificationService
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendEncouragement(
        Request $request,
        string $notificationId,
        GoalPartnershipNotificationService $goalPartnershipNotificationService
    ) {
        $notification = $this->findNotificationForAuthenticatedUser($notificationId);

        if (!$notification) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($notification->type !== PartnerAlertNotification::DATABASE_TYPE) {
            return response()->json([
                'message' => 'Encouragement can only be sent for partner alert notifications.',
            ], 422);
        }

        $validated = $request->validate([
            'preset_key' => ['required', 'string', Rule::in(array_keys(config('partner_alerts.encouragement_presets', [])))],
        ]);

        /** @var GoalPartnershipAlertEvent|null $alertEvent */
        $alertEvent = GoalPartnershipAlertEvent::query()
            ->with(['partnership.goal', 'subjectUser', 'recipientUser'])
            ->find((int) data_get($notification->data, 'related_alert_event_id'));

        if (!$alertEvent || !$alertEvent->partnership || !$alertEvent->subjectUser) {
            return response()->json([
                'message' => 'This partnership alert is no longer available.',
            ], 422);
        }

        $sender = $request->user();
        $partnership = $alertEvent->partnership;

        if (
            (int) $alertEvent->recipient_user_id !== (int) $sender->id
            || !in_array((int) $sender->id, [(int) $partnership->initiator_user_id, (int) $partnership->partner_user_id], true)
        ) {
            return response()->json([
                'message' => 'You cannot respond to this notification.',
            ], 422);
        }

        if ($this->hasEncouragementAlreadyBeenSent($notification, $alertEvent->subjectUser)) {
            return response()->json([
                'message' => 'An encouragement has already been sent for this alert.',
            ], 422);
        }

        $encouragementNotificationId = $goalPartnershipNotificationService->sendPartnerEncouragement(
            $partnership,
            $notification->id,
            $validated['preset_key'],
            $alertEvent->subjectUser,
            $sender
        );

        $updatedData = $notification->data;
        data_set($updatedData, 'encouragement.already_sent', true);
        $notification->forceFill(['data' => $updatedData])->save();

        return response()->json([
            'encouragement' => [
                'notification_id' => $encouragementNotificationId,
                'source_notification_id' => $notification->id,
                'preset_key' => $validated['preset_key'],
            ],
        ], 201);
    }

    /**
     * @return array<int,string>
     */
    private function partnerNotificationTypes(): array
    {
        return [
            PartnerAlertNotification::DATABASE_TYPE,
            PartnerEncouragementNotification::DATABASE_TYPE,
        ];
    }

    /**
     * @param string $notificationId
     * @return DatabaseNotification|null
     */
    private function findNotificationForAuthenticatedUser(string $notificationId): ?DatabaseNotification
    {
        return Auth::user()
            ->notifications()
            ->whereIn('type', $this->partnerNotificationTypes())
            ->find($notificationId);
    }

    /**
     * @param DatabaseNotification $notification
     * @param GoalPartnership|null $partnership
     * @return array<string,mixed>
     */
    private function serializeNotification(
        DatabaseNotification $notification,
        ?GoalPartnership $partnership
    ): array {
        $goal = $partnership?->goal;
        $deepLink = $partnership ? data_get($notification->data, 'deep_link') : null;
        $encouragementAllowed = $notification->type === PartnerAlertNotification::DATABASE_TYPE && $partnership !== null;
        $alreadySent = (bool) data_get($notification->data, 'encouragement.already_sent', false);

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'source' => data_get($notification->data, 'source'),
            'title' => data_get($notification->data, 'title'),
            'body' => data_get($notification->data, 'body'),
            'deep_link' => $deepLink,
            'read_at' => $notification->read_at,
            'created_at' => $notification->created_at,
            'partnership' => $partnership ? [
                'id' => $partnership->id,
                'goal_id' => $partnership->goal_id,
                'status' => $partnership->status,
                'role' => $partnership->role,
                'goal' => $this->serializeGoal($goal),
            ] : null,
            'encouragement' => [
                'allowed' => $encouragementAllowed,
                'already_sent' => $encouragementAllowed ? $alreadySent : true,
                'presets' => $encouragementAllowed ? config('partner_alerts.encouragement_presets', []) : [],
            ],
        ];
    }

    /**
     * @param DatabaseNotification $sourceAlertNotification
     * @param User $recipient
     * @return bool
     */
    private function hasEncouragementAlreadyBeenSent(
        DatabaseNotification $sourceAlertNotification,
        User $recipient
    ): bool {
        if ((bool) data_get($sourceAlertNotification->data, 'encouragement.already_sent', false)) {
            return true;
        }

        return $recipient->notifications()
            ->where('type', PartnerEncouragementNotification::DATABASE_TYPE)
            ->get()
            ->contains(function (DatabaseNotification $notification) use ($sourceAlertNotification): bool {
                return (string) data_get($notification->data, 'source_alert_notification_id', '')
                    === (string) $sourceAlertNotification->id;
            });
    }

    /**
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
        ];
    }
}
