<?php

namespace App\Services;

use App\Models\GoalPartnership;
use App\Models\GoalPartnershipAlertEvent;
use App\Models\User;
use Illuminate\Support\Str;

class GoalPartnershipNotificationService
{
    /**
     * Create a partner-facing alert notification using Laravel's database channel.
     *
     * @param GoalPartnership $partnership
     * @param GoalPartnershipAlertEvent $alertEvent
     * @param array<string,mixed> $snapshotExcerpt
     * @return string
     */
    public function sendPartnerAlert(
        GoalPartnership $partnership,
        GoalPartnershipAlertEvent $alertEvent,
        array $snapshotExcerpt
    ): string {
        $recipient = $alertEvent->recipientUser;
        $goalTitle = (string) ($partnership->goal?->title ?? 'Untitled goal');
        $selectedAlertType = $alertEvent->selected_alert_type;
        $evaluationSource = $alertEvent->evaluation_source;
        $alertType = $selectedAlertType instanceof PartnershipAlertType
            ? $selectedAlertType->value
            : (string) ($selectedAlertType ?? PartnershipAlertType::ConsecutiveMisses->value);
        $source = $evaluationSource instanceof PartnershipAlertEvaluationSource
            ? $evaluationSource->value
            : (string) ($evaluationSource ?? PartnershipAlertEvaluationSource::ScheduledScan->value);
        [$title, $body] = $this->buildAlertCopy($partnership, $alertType, $goalTitle, $snapshotExcerpt);

        $notification = new PartnerAlertNotification([
            'partner_alert_type' => $alertType,
            'source' => $source,
            'title' => $title,
            'body' => $body,
            'partnership_id' => $partnership->id,
            'goal_id' => $partnership->goal_id,
            'goal_title' => $goalTitle,
            'role' => $partnership->role,
            'deep_link' => $this->buildPartnerDeepLink($partnership),
            'dedupe_key' => $alertEvent->dedupe_key,
            'related_alert_event_id' => $alertEvent->id,
            'encouragement' => [
                'allowed' => true,
                'already_sent' => false,
                'presets' => config('partner_alerts.encouragement_presets', []),
            ],
        ]);

        $notification->id = (string) Str::uuid();
        $recipient->notify($notification);

        return $notification->id;
    }

    /**
     * Create an owner-facing encouragement notification.
     *
     * @param GoalPartnership $partnership
     * @param string $sourceAlertNotificationId
     * @param string $presetKey
     * @param User $recipient
     * @param User $sender
     * @return string
     */
    public function sendPartnerEncouragement(
        GoalPartnership $partnership,
        string $sourceAlertNotificationId,
        string $presetKey,
        User $recipient,
        User $sender
    ): string {
        $presetMessage = (string) config("partner_alerts.encouragement_presets.{$presetKey}", '');
        $goalTitle = (string) ($partnership->goal?->title ?? 'Untitled goal');

        $notification = new PartnerEncouragementNotification([
            'source' => 'encouragement',
            'title' => 'Partner encouragement received',
            'body' => "{$sender->name}: {$presetMessage}",
            'partnership_id' => $partnership->id,
            'goal_id' => $partnership->goal_id,
            'goal_title' => $goalTitle,
            'role' => $partnership->role,
            'deep_link' => null,
            'source_alert_notification_id' => $sourceAlertNotificationId,
            'preset_key' => $presetKey,
            'sender_user_id' => $sender->id,
            'encouragement' => [
                'allowed' => false,
                'already_sent' => true,
                'presets' => [],
            ],
        ]);

        $notification->id = (string) Str::uuid();
        $recipient->notify($notification);

        return $notification->id;
    }

    /**
     * Format alert notification copy using safe partnership data only.
     *
     * @param GoalPartnership $partnership
     * @param string $alertType
     * @param string $goalTitle
     * @param array<string,mixed> $snapshotExcerpt
     * @return array{0:string,1:string}
     */
    private function buildAlertCopy(
        GoalPartnership $partnership,
        string $alertType,
        string $goalTitle,
        array $snapshotExcerpt
    ): array {
        $tone = (string) $partnership->role;
        $missCount = max(1, (int) ($snapshotExcerpt['consecutive_misses'] ?? 1));
        $inactivityDays = max(1, (int) ($snapshotExcerpt['inactivity_days'] ?? 1));
        $paceGap = abs((float) ($snapshotExcerpt['pace_delta'] ?? 0));

        return match ($alertType) {
            'behind_pace' => [
                'Behind pace',
                $this->formatByTone(
                    $tone,
                    "Progress on {$goalTitle} is behind pace by ".number_format($paceGap, 1)." points. A quick nudge could help.",
                    "{$goalTitle} is behind pace by ".number_format($paceGap, 1)." points. Follow up.",
                    "{$goalTitle} is behind pace by ".number_format($paceGap, 1)." points."
                ),
            ],
            'inactivity' => [
                'No recent check-ins',
                $this->formatByTone(
                    $tone,
                    "Your partner has not checked in on {$goalTitle} for {$inactivityDays} day(s). A gentle ping could help.",
                    "{$goalTitle} has been quiet for {$inactivityDays} day(s). Time to check in.",
                    "{$goalTitle} has had no check-ins for {$inactivityDays} day(s)."
                ),
            ],
            'streak_broken' => [
                'Streak broken',
                $this->formatByTone(
                    $tone,
                    "The current streak on {$goalTitle} broke. A reset message could help.",
                    "The streak on {$goalTitle} broke. Follow up now.",
                    "The streak on {$goalTitle} broke."
                ),
            ],
            default => [
                'Missed check-ins',
                $this->formatByTone(
                    $tone,
                    "Your partner missed {$missCount} scheduled check-in(s) for {$goalTitle}. A gentle nudge could help.",
                    "{$missCount} scheduled check-in(s) were missed for {$goalTitle}. Follow up.",
                    "{$missCount} scheduled check-in(s) were missed for {$goalTitle}."
                ),
            ],
        };
    }

    /**
     * Choose role-specific tone without changing any authorization or thresholds.
     *
     * @param string $tone
     * @param string $cheerleader
     * @param string $drillSergeant
     * @param string $silent
     * @return string
     */
    private function formatByTone(string $tone, string $cheerleader, string $drillSergeant, string $silent): string
    {
        return match ($tone) {
            'drill_sergeant' => $drillSergeant,
            'silent' => $silent,
            default => $cheerleader,
        };
    }

    /**
     * Build the frontend route target for partner snapshot deep links.
     *
     * @param GoalPartnership $partnership
     * @return string
     */
    private function buildPartnerDeepLink(GoalPartnership $partnership): string
    {
        return "/partnerships/{$partnership->id}";
    }
}
