<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Browser push runs on its own worker, separately from live chat delivery.
 */
class SendWorkplacePushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }

    public function __construct(
        public int $userId,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    public function handle(WebPushService $webPush): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }
        if (isset($this->data['message_id'])) {
            $message = Message::with('conversation')->find($this->data['message_id']);
            if (! $message || $message->deleted_at || ! $user->activeInCompany($message->company_id) || ! $message->conversation->participants()->whereKey($user->id)->exists()) {
                return;
            }
            $lastRead = (int) DB::table('conversation_participants')->where('conversation_id', $message->conversation_id)->where('user_id', $user->id)->value('last_read_message_id');
            $unread = $message->conversation->messages()->where(fn ($authors) => $authors->where('user_id', '!=', $user->id)->orWhereNull('user_id'))->whereNull('deleted_at')->where('id', '>', $lastRead);
            // Older jobs in a message burst are superseded by its latest unread message.
            if ((int) (clone $unread)->max('id') !== (int) $message->id) {
                return;
            }
            $this->data['unread_count'] = $unread->count();
        }
        $webPush->sendToUser($user, $this->title, $this->body, $this->data);
    }
}
