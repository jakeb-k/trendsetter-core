<?php

use App\Services\PartnerInviteRegistrationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('partner-invites:prune', function (PartnerInviteRegistrationService $partnerInviteRegistrationService) {
    $expiredCount = $partnerInviteRegistrationService->expireAllStalePendingInvites();
    $prunedCounts = $partnerInviteRegistrationService->pruneResolvedInvites(
        (int) config('services.partner_invites.accepted_retention_days', 30)
    );

    $this->info("Expired {$expiredCount} pending partner invite(s).");
    $this->info("Deleted {$prunedCounts['expired_or_cancelled_deleted']} expired/cancelled invite(s).");
    $this->info("Deleted {$prunedCounts['accepted_deleted']} accepted invite(s) past retention.");
})->purpose('Expire pending partner invites and prune invite history');

Schedule::command('partner-invites:prune')->dailyAt('00:00');
