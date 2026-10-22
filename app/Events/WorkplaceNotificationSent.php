<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Immediate, user-scoped notification broadcast.
 *
 * ShouldBroadcastNow sends immediately once the delivery job runs, avoiding
 * a second queued broadcast. The default queue worker must remain running;
 * message deliveries are the durable outbox for retries.
 */
class WorkplaceNotificationSent implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $userId,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'workplace.notification';
    }

    public function broadcastWith(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data,
            'created_at' => now()->toIso8601String(),
        ];
    }
}
