<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class WorkplaceNotification extends Notification
{
    use Queueable;

    public function __construct(
        private string $title,
        private string $body,
        private array $data = [],
    ) {}

    public function via($notifiable): array
    {
        // Real-time delivery is handled by WorkplaceNotificationSent::ShouldBroadcastNow.
        // Keeping this notification database-only avoids duplicate broadcast events
        // and avoids depending on a queue worker for in-app chat alerts.
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data,
        ];
    }
}
