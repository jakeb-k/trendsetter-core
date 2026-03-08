<?php

use App\Models\GoalPartnership;
use App\Models\GoalPartnershipAlertEvent;
use App\Services\GoalPartnershipAlertEvaluator;
use App\Services\PartnershipAlertEvaluationSource;
use App\Services\PartnerInviteRegistrationService;
use App\Services\PartnerAlertNotification;
use App\Services\PartnerEncouragementNotification;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('partner-invites:prune', function (PartnerInviteRegistrationService $partnerInviteRegistrationService) {
    $expiredCount = $partnerInviteRegistrationService->expireAllStalePendingInvites();
    $expiredAcceptedUnclaimedCount = $partnerInviteRegistrationService->expireStaleAcceptedUnclaimedInvites();
    $prunedCounts = $partnerInviteRegistrationService->pruneResolvedInvites(
        (int) config('services.partner_invites.accepted_retention_days', 30)
    );

    $this->info("Expired {$expiredCount} pending partner invite(s).");
    $this->info("Expired {$expiredAcceptedUnclaimedCount} accepted-unclaimed invite(s).");
    $this->info("Deleted {$prunedCounts['expired_or_cancelled_deleted']} expired/cancelled invite(s).");
    $this->info("Archived {$prunedCounts['accepted_archived']} accepted invite(s) past retention.");
})->purpose('Expire pending partner invites and prune invite history');

Artisan::command('goal-partnerships:evaluate-alerts', function (GoalPartnershipAlertEvaluator $goalPartnershipAlertEvaluator) {
    $evaluatedCount = 0;
    $recentLogSubmitCutoff = now()->subHours((int) config('partner_alerts.scan_recent_log_submit_hours', 24));
    $rateLimitCutoff = now()->subHours((int) config('partner_alerts.rate_limit_hours', 24));

    GoalPartnership::query()
        ->where('status', 'active')
        ->where('notify_on_alerts', true)
        ->whereHas('goal', function ($query) {
            $query
                ->where('status', '!=', 'completed')
                ->whereHas('events');
        })
        ->whereDoesntHave('alertEvents', function ($query) use ($recentLogSubmitCutoff) {
            $query
                ->where('evaluation_source', PartnershipAlertEvaluationSource::LogSubmit->value)
                ->where('evaluated_at', '>=', $recentLogSubmitCutoff);
        })
        ->whereDoesntHave('alertEvents', function ($query) use ($rateLimitCutoff) {
            $query
                ->where('outcome', 'generated')
                ->where('evaluated_at', '>=', $rateLimitCutoff);
        })
        ->orderBy('id')
        ->chunkById((int) config('partner_alerts.scan_chunk_size', 200), function ($partnerships) use ($goalPartnershipAlertEvaluator, &$evaluatedCount): void {
            foreach ($partnerships as $partnership) {
                rescue(function () use ($goalPartnershipAlertEvaluator, $partnership, &$evaluatedCount): void {
                    $goalPartnershipAlertEvaluator->evaluate(
                        $partnership,
                        PartnershipAlertEvaluationSource::ScheduledScan
                    );
                    $evaluatedCount++;
                }, report: true);
            }
        });

    $this->info("Evaluated {$evaluatedCount} goal partnership(s) for partner alerts.");
})->purpose('Evaluate active goal partnerships for partner alerts');

Artisan::command('partner-notifications:prune', function () {
    $suppressedDeleted = GoalPartnershipAlertEvent::query()
        ->where('outcome', '!=', 'generated')
        ->where('evaluated_at', '<=', now()->subDays((int) config('partner_alerts.suppressed_retention_days', 7)))
        ->delete();

    $readDeleted = DB::table('notifications')
        ->whereIn('type', [
            PartnerAlertNotification::DATABASE_TYPE,
            PartnerEncouragementNotification::DATABASE_TYPE,
        ])
        ->whereNotNull('read_at')
        ->where('read_at', '<=', now()->subDays((int) config('partner_alerts.read_notification_retention_days', 7)))
        ->delete();

    $this->info("Deleted {$suppressedDeleted} suppressed alert event(s).");
    $this->info("Deleted {$readDeleted} read partner notification(s).");
})->purpose('Prune suppressed partner alert events and old read notifications');

Schedule::command('partner-invites:prune')
    ->dailyAt('00:00')
    ->withoutOverlapping((int) config('services.partner_invites.prune_schedule_lock_minutes', 30));

Schedule::command('goal-partnerships:evaluate-alerts')
    ->dailyAt('01:00')
    ->withoutOverlapping((int) config('services.partner_notifications.alert_scan_schedule_lock_minutes', 180));

Schedule::command('partner-notifications:prune')
    ->dailyAt('01:30')
    ->withoutOverlapping((int) config('services.partner_notifications.prune_schedule_lock_minutes', 60));
