<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends OS-level web push after the HTTP response has been returned.
 * This keeps push-provider/network latency out of the message-send request.
 */
class SendWorkplacePushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $userId,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    public function handle(WebPushService $webPush): void
    {
        $user = User::find($this->userId);
        if (!$user) return;
        $webPush->sendToUser($user, $this->title, $this->body, $this->data);
    }
}
