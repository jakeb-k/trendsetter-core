<?php

namespace Tests\Unit\Services;

use App\Services\PartnerAlertNotification;
use App\Services\PartnerEncouragementNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\TestCase;

class PartnerNotificationDispatchConfigTest extends TestCase
{
    public function test_partner_alert_notification_dispatches_after_commit(): void
    {
        $notification = new PartnerAlertNotification([
            'source' => 'scheduled_scan',
        ]);

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertTrue($notification->afterCommit === true);
    }

    public function test_partner_encouragement_notification_dispatches_after_commit(): void
    {
        $notification = new PartnerEncouragementNotification([
            'source' => 'encouragement',
        ]);

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertTrue($notification->afterCommit === true);
    }
}
