<?php

namespace App\Services;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PartnerEncouragementNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const DATABASE_TYPE = 'partner_encouragement';

    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        private readonly array $payload
    ) {
    }

    /**
     * @param object $notifiable
     * @return array<int,string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @param object $notifiable
     * @return string
     */
    public function databaseType(object $notifiable): string
    {
        return self::DATABASE_TYPE;
    }

    /**
     * @param object $notifiable
     * @return array<string,mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload;
    }

    /**
     * @param object $notifiable
     * @return array<string,mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
