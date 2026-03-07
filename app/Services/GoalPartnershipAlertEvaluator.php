<?php

namespace App\Services;

use App\Models\Event;
use App\Models\GoalPartnership;
use App\Models\GoalPartnershipAlertEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class GoalPartnershipAlertEvaluator
{
    public function __construct(
        private readonly GoalOccurrenceService $goalOccurrenceService,
        private readonly GoalPartnerSnapshotService $goalPartnerSnapshotService,
        private readonly GoalPartnershipNotificationService $goalPartnershipNotificationService
    ) {
    }

    /**
     * Evaluate a partnership for alert-worthy signals and create a notification when needed.
     *
     * @param GoalPartnership $partnership
     * @param PartnershipAlertEvaluationSource $evaluationSource
     * @return GoalPartnershipAlertEvent
     */
    public function evaluate(
        GoalPartnership $partnership,
        PartnershipAlertEvaluationSource $evaluationSource
    ): GoalPartnershipAlertEvent
    {
        return DB::transaction(function () use ($partnership, $evaluationSource): GoalPartnershipAlertEvent {
            $partnership = GoalPartnership::query()
                ->with([
                    'goal:id,user_id,title,status,start_date,created_at',
                    'goal.user:id,name',
                    'goal.events:id,goal_id,title,scheduled_for,repeat,points',
                    'initiator:id,name',
                    'partner:id,name',
                ])
                ->lockForUpdate()
                ->find($partnership->id);

            if (!$partnership || !$partnership->goal || !$partnership->goal->user) {
                throw new \RuntimeException('This partnership is no longer available.');
            }

            $goal = $partnership->goal;
            $subjectUser = $goal->user;
            $recipientUser = $this->resolveRecipientUser($partnership, $subjectUser);

            if ($recipientUser === null) {
                throw new \RuntimeException('This partnership is no longer available.');
            }

            if ($partnership->status !== 'active') {
                return $this->createAlertEvent($partnership, $subjectUser, $recipientUser, [
                    'evaluation_source' => $evaluationSource->value,
                    'selected_alert_type' => null,
                    'candidate_types' => [],
                    'outcome' => 'suppressed_paused',
                    'reason_codes' => ['partnership_paused'],
                    'snapshot_excerpt' => [],
                ]);
            }

            if (!$partnership->notify_on_alerts) {
                return $this->createAlertEvent($partnership, $subjectUser, $recipientUser, [
                    'evaluation_source' => $evaluationSource->value,
                    'selected_alert_type' => null,
                    'candidate_types' => [],
                    'outcome' => 'suppressed_notify_disabled',
                    'reason_codes' => ['alerts_disabled'],
                    'snapshot_excerpt' => [],
                ]);
            }

            if ($goal->status === 'completed') {
                return $this->createAlertEvent($partnership, $subjectUser, $recipientUser, [
                    'evaluation_source' => $evaluationSource->value,
                    'selected_alert_type' => null,
                    'candidate_types' => [],
                    'outcome' => 'suppressed_goal_completed',
                    'reason_codes' => ['goal_completed'],
                    'snapshot_excerpt' => [],
                ]);
            }

            $events = $goal->events ?? collect();

            if ($events->isEmpty()) {
                return $this->createAlertEvent($partnership, $subjectUser, $recipientUser, [
                    'evaluation_source' => $evaluationSource->value,
                    'selected_alert_type' => null,
                    'candidate_types' => [],
                    'outcome' => 'suppressed_no_signal',
                    'reason_codes' => ['no_events'],
                    'snapshot_excerpt' => [],
                ]);
            }

            $now = CarbonImmutable::now();
            $graceHours = (int) config('partner_alerts.grace_hours', 6);
            $goalStartDate = $this->goalOccurrenceService->resolveGoalStartDate($goal, $now);
            $feedbackRows = $this->goalOccurrenceService->loadFeedbackRowsForEvents($events, (int) $subjectUser->id);
            $loggedFeedbackLookup = $this->goalOccurrenceService->buildFeedbackLookup($feedbackRows);
            $scheduledOccurrences = $this->goalOccurrenceService
                ->buildScheduledOccurrences($events, $goalStartDate, $now->startOfDay())
                ->sortBy(function (array $occurrence): int {
                    return $occurrence['at']->getTimestamp();
                })
                ->values();

            $eligibleOccurrences = $this->goalOccurrenceService
                ->buildEligibleOccurrences($events, $goalStartDate, $now, $graceHours)
                ->sortBy(function (array $occurrence): int {
                    return $occurrence['at']->getTimestamp();
                })
                ->values();

            $rawMissesWithinGrace = $scheduledOccurrences
                ->contains(function (array $occurrence) use ($loggedFeedbackLookup, $now, $graceHours): bool {
                    $lookupKey = $this->goalOccurrenceService->buildFeedbackLookupKey(
                        (int) $occurrence['event_id'],
                        $occurrence['at']->toDateString()
                    );

                    return !isset($loggedFeedbackLookup[$lookupKey])
                        && !$this->goalOccurrenceService->isOccurrenceEligibleForMissEvaluation(
                            $occurrence['at'],
                            $now,
                            $graceHours
                        );
                });

            $snapshot = $this->goalPartnerSnapshotService->buildSnapshot($partnership);
            $consecutiveMissCount = $this->goalOccurrenceService->determineTrailingMissCount(
                $eligibleOccurrences,
                $loggedFeedbackLookup
            );
            $latestMissedOccurrence = $this->goalOccurrenceService->resolveLatestMissedOccurrence(
                $eligibleOccurrences,
                $loggedFeedbackLookup
            );
            $streakBreakOccurrence = $this->goalOccurrenceService->resolveStreakBreakOccurrence(
                $eligibleOccurrences,
                $loggedFeedbackLookup
            );
            $candidateTypes = [];
            $signalDates = [];
            $inactivityThresholdDays = (int) config('partner_alerts.inactivity_days', 3);

            if ($consecutiveMissCount >= (int) config('partner_alerts.consecutive_misses_threshold', 1)
                && $latestMissedOccurrence !== null
            ) {
                $candidateTypes[] = PartnershipAlertType::ConsecutiveMisses->value;
                $signalDates[PartnershipAlertType::ConsecutiveMisses->value] = $latestMissedOccurrence['at']->toDateString();
            }

            if ($streakBreakOccurrence !== null) {
                $candidateTypes[] = PartnershipAlertType::StreakBroken->value;
                $signalDates[PartnershipAlertType::StreakBroken->value] = $streakBreakOccurrence['at']->toDateString();
            }

            if ((int) ($snapshot['inactivity_days'] ?? 0) >= $inactivityThresholdDays) {
                $candidateTypes[] = PartnershipAlertType::Inactivity->value;
                $signalDates[PartnershipAlertType::Inactivity->value] = $this->goalPartnerSnapshotService
                    ->resolveInactivityBoundaryDate($snapshot, $goalStartDate, $inactivityThresholdDays);
            }

            if (
                ($snapshot['pace_status'] ?? null) === 'behind'
                && (float) ($snapshot['pace_delta'] ?? 0) <= ((float) config('partner_alerts.behind_pace_points', 2) * -1)
                && (float) ($snapshot['points_expected_by_today'] ?? 0) >= (float) config('partner_alerts.min_expected_points', 4)
            ) {
                $candidateTypes[] = PartnershipAlertType::BehindPace->value;
                $signalDates[PartnershipAlertType::BehindPace->value] = $now->toDateString();
            }

            $snapshotExcerpt = $this->goalPartnerSnapshotService->buildSnapshotExcerpt($snapshot, $consecutiveMissCount);

            if ($candidateTypes === []) {
                return $this->createAlertEvent($partnership, $subjectUser, $recipientUser, [
                    'evaluation_source' => $evaluationSource->value,
                    'selected_alert_type' => null,
                    'candidate_types' => [],
                    'outcome' => $rawMissesWithinGrace ? 'suppressed_grace' : 'suppressed_no_signal',
                    'reason_codes' => $rawMissesWithinGrace ? ['miss_within_grace_window'] : ['no_alert_conditions_met'],
                    'snapshot_excerpt' => $snapshotExcerpt,
                ]);
            }

            $selectedAlertType = $candidateTypes[0];
            $dedupeKey = $this->buildDedupeKey(
                $partnership,
                $selectedAlertType,
                $signalDates[$selectedAlertType] ?? $now->toDateString(),
                $snapshotExcerpt
            );

            if ($this->hasGeneratedDuplicate($partnership, $dedupeKey)) {
                return $this->createAlertEvent($partnership, $subjectUser, $recipientUser, [
                    'evaluation_source' => $evaluationSource->value,
                    'selected_alert_type' => $selectedAlertType,
                    'candidate_types' => $candidateTypes,
                    'outcome' => 'suppressed_duplicate',
                    'reason_codes' => ['duplicate_dedupe_key'],
                    'dedupe_key' => $dedupeKey,
                    'signal_date' => $signalDates[$selectedAlertType] ?? null,
                    'snapshot_excerpt' => $snapshotExcerpt,
                ]);
            }

            if ($this->isRateLimited($partnership, $now)) {
                return $this->createAlertEvent($partnership, $subjectUser, $recipientUser, [
                    'evaluation_source' => $evaluationSource->value,
                    'selected_alert_type' => $selectedAlertType,
                    'candidate_types' => $candidateTypes,
                    'outcome' => 'suppressed_rate_limited',
                    'reason_codes' => ['cooldown_active'],
                    'dedupe_key' => $dedupeKey,
                    'signal_date' => $signalDates[$selectedAlertType] ?? null,
                    'snapshot_excerpt' => $snapshotExcerpt,
                ]);
            }

            $alertEvent = $this->createAlertEvent($partnership, $subjectUser, $recipientUser, [
                'evaluation_source' => $evaluationSource->value,
                'selected_alert_type' => $selectedAlertType,
                'candidate_types' => $candidateTypes,
                'outcome' => 'generated',
                'reason_codes' => ['alert_generated'],
                'dedupe_key' => $dedupeKey,
                'signal_date' => $signalDates[$selectedAlertType] ?? null,
                'snapshot_excerpt' => $snapshotExcerpt,
            ]);

            $alertEvent->loadMissing('recipientUser');
            $notificationId = $this->goalPartnershipNotificationService->sendPartnerAlert(
                $partnership,
                $alertEvent,
                $snapshotExcerpt
            );

            $alertEvent->update([
                'notification_id' => $notificationId,
            ]);

            return $alertEvent->fresh();
        });
    }

    /**
     * @param GoalPartnership $partnership
     * @param string $alertType
     * @param string $signalDate
     * @param array<string,mixed> $snapshotExcerpt
     * @return string
     */
    private function buildDedupeKey(
        GoalPartnership $partnership,
        string $alertType,
        string $signalDate,
        array $snapshotExcerpt
    ): string {
        $base = "{$partnership->id}:{$partnership->goal_id}:{$alertType}";

        return match ($alertType) {
            'behind_pace' => $base.':bucket:'.$this->resolveBehindPaceBucket(
                (float) ($snapshotExcerpt['pace_delta'] ?? 0)
            ),
            'consecutive_misses' => $base.":{$signalDate}:count:".((int) ($snapshotExcerpt['consecutive_misses'] ?? 0)),
            default => $base.":{$signalDate}",
        };
    }

    /**
     * @param float $paceDelta
     * @return int
     */
    private function resolveBehindPaceBucket(float $paceDelta): int
    {
        $threshold = max(0.1, (float) config('partner_alerts.behind_pace_points', 2));
        $gap = abs(min(0.0, $paceDelta));

        return max(1, (int) floor($gap / $threshold));
    }

    /**
     * @param GoalPartnership $partnership
     * @param string $dedupeKey
     * @return bool
     */
    private function hasGeneratedDuplicate(GoalPartnership $partnership, string $dedupeKey): bool
    {
        return GoalPartnershipAlertEvent::query()
            ->where('partnership_id', $partnership->id)
            ->where('outcome', 'generated')
            ->where('dedupe_key', $dedupeKey)
            ->exists();
    }

    /**
     * @param GoalPartnership $partnership
     * @param CarbonImmutable $now
     * @return bool
     */
    private function isRateLimited(GoalPartnership $partnership, CarbonImmutable $now): bool
    {
        return GoalPartnershipAlertEvent::query()
            ->where('partnership_id', $partnership->id)
            ->where('outcome', 'generated')
            ->where('evaluated_at', '>=', $now->subHours((int) config('partner_alerts.rate_limit_hours', 24)))
            ->exists();
    }

    /**
     * @param GoalPartnership $partnership
     * @param User $subjectUser
     * @return User|null
     */
    private function resolveRecipientUser(GoalPartnership $partnership, User $subjectUser): ?User
    {
        if ((int) $partnership->initiator_user_id === (int) $subjectUser->id) {
            return $partnership->partner;
        }

        if ((int) $partnership->partner_user_id === (int) $subjectUser->id) {
            return $partnership->initiator;
        }

        return null;
    }

    /**
     * Persist an alert-event row using safe, derived metadata only.
     *
     * @param GoalPartnership $partnership
     * @param User $subjectUser
     * @param User $recipientUser
     * @param array<string,mixed> $attributes
     * @return GoalPartnershipAlertEvent
     */
    private function createAlertEvent(
        GoalPartnership $partnership,
        User $subjectUser,
        User $recipientUser,
        array $attributes
    ): GoalPartnershipAlertEvent {
        return GoalPartnershipAlertEvent::create([
            'partnership_id' => $partnership->id,
            'goal_id' => $partnership->goal_id,
            'subject_user_id' => $subjectUser->id,
            'recipient_user_id' => $recipientUser->id,
            'evaluation_source' => $attributes['evaluation_source'],
            'selected_alert_type' => $attributes['selected_alert_type'] ?? null,
            'candidate_types' => $attributes['candidate_types'] ?? [],
            'outcome' => $attributes['outcome'],
            'reason_codes' => $attributes['reason_codes'] ?? [],
            'dedupe_key' => $attributes['dedupe_key'] ?? null,
            'signal_date' => $attributes['signal_date'] ?? null,
            'snapshot_excerpt' => $attributes['snapshot_excerpt'] ?? [],
            'notification_id' => $attributes['notification_id'] ?? null,
            'evaluated_at' => now(),
        ]);
    }
}
