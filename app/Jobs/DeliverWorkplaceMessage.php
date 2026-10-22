<?php

namespace App\Jobs;

use App\Events\WorkplaceNotificationSent;
use App\Models\Message;
use App\Models\User;
use App\Notifications\WorkplaceNotification;
use App\Services\WebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

class DeliverWorkplaceMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public int $messageId, public int $userId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("message:{$this->messageId}:recipient:{$this->userId}"))->releaseAfter(10)->expireAfter(120)];
    }

    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }

    public function handle(WebPushService $push): void
    {
        $delivery = DB::table('message_deliveries')->where('message_id', $this->messageId)->where('user_id', $this->userId);
        if (! $delivery->exists() || ($delivery->value('delivered_at') && $delivery->value('push_queued_at'))) {
            return;
        }
        $message = Message::with('conversation')->find($this->messageId);
        $user = User::find($this->userId);
        if (! $message || $message->deleted_at || ! $user || ! $user->activeInCompany($message->company_id) || ! $message->conversation->participants()->whereKey($user->id)->exists()) {
            $delivery->update(['delivered_at' => now(), 'push_queued_at' => now(), 'updated_at' => now()]);

            return;
        }
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, "pharma:message:{$message->id}:recipient:{$user->id}")->toString();
        $important = $message->conversation->type === 'announcement';
        $data = ['company_id' => $message->company_id, 'conversation_id' => $message->conversation_id, 'message_id' => $message->id, 'notification_id' => $id, 'important' => $important];
        if ($important) {
            DB::table('notifications')->insertOrIgnore(['id' => $id, 'type' => WorkplaceNotification::class, 'notifiable_type' => User::class, 'notifiable_id' => $user->id, 'data' => json_encode(['title' => 'Workplace announcement', 'body' => 'You have a new workplace announcement.', 'data' => $data]), 'created_at' => $message->created_at, 'updated_at' => now()]);
        }
        $pushFailed = false;
        try {
            DB::transaction(function () use ($data): void {
                $row = DB::table('message_deliveries')->where('message_id', $this->messageId)
                    ->where('user_id', $this->userId)->lockForUpdate()->first();
                if (! $row || $row->push_queued_at) {
                    return;
                }
                // Queue insertion and marker commit atomically when they use
                // the same database connection. Other connections/backends
                // retain at-least-once behavior on a commit failure.
                SendWorkplacePushNotification::dispatch($this->userId, 'Pharma Strategies', 'You have a new workplace message.', $data)
                    ->onQueue('push')->beforeCommit();
                DB::table('message_deliveries')->where('id', $row->id)
                    ->update(['push_queued_at' => now(), 'updated_at' => now()]);
            });
        } catch (\Throwable $error) {
            $pushFailed = true;
            Log::warning('Push dispatch is unavailable.', ['message_id' => $this->messageId, 'recipient_id' => $this->userId, 'exception_type' => get_class($error)]);
        }
        // Broadcast only invalidation IDs; active HTTP authorization supplies the content.
        if (! $delivery->value('delivered_at')) {
            try {
                $this->publish($user->id, $data);
            } catch (\Throwable $error) {
                // SDK exceptions may include signed provider URLs. Keep credentials
                // out of queue logs while preserving the durable retry path.
                Log::warning('Realtime message publishing failed.', [
                    'message_id' => $message->id, 'recipient_id' => $user->id, 'exception_type' => get_class($error),
                ]);
                throw new \RuntimeException('Realtime message delivery is unavailable; retry is required.');
            }
            $delivery->update(['delivered_at' => now(), 'updated_at' => now()]);
        }
        if ($pushFailed) {
            throw new \RuntimeException('Push dispatch is unavailable; retry is required.');
        }
    }

    protected function publish(int $userId, array $data): void
    {
        event(new WorkplaceNotificationSent($userId, 'New workplace message', 'You received a new workplace message.', $data));
    }
}
