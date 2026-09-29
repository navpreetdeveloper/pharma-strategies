<?php

namespace App\Jobs;

use App\Events\WorkplaceNotificationSent;
use App\Models\Conversation;
use App\Notifications\WorkplaceNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers durable/in-app/OS notifications after the message HTTP response.
 * Keeping this work out of ChatController::send makes message sending feel
 * immediate even when Web Push providers or notification writes are slower.
 */
class DeliverWorkplaceMessageNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $conversationId,
        public int $senderId,
        public string $senderName,
        public string $senderInitials,
        public int $messageId,
        public string $messagePreview,
    ) {}

    public function handle(): void
    {
        $conversation = Conversation::with(['participants'])->find($this->conversationId);
        if (!$conversation) {
            return;
        }

        foreach ($conversation->participants->where('id', '!=', $this->senderId) as $participant) {
            $notificationData = [
                'conversation_id' => $this->conversationId,
                'sender_name' => $this->senderName,
                'sender_initials' => $this->senderInitials,
                'message_id' => $this->messageId,
                'message_preview' => $this->messagePreview,
            ];

            $participant->notify(new WorkplaceNotification(
                'New workplace message',
                $this->senderName . ' sent you a new message',
                $notificationData
            ));

            $notificationId = $participant->notifications()
                ->where('type', WorkplaceNotification::class)
                ->latest('created_at')
                ->value('id');

            event(new WorkplaceNotificationSent(
                (int) $participant->id,
                'New workplace message',
                $this->senderName . ' sent you a new message',
                $notificationData + ['notification_id' => $notificationId]
            ));

            SendWorkplacePushNotification::dispatch(
                (int) $participant->id,
                'New message from ' . $this->senderName,
                $this->messagePreview,
                $notificationData + ['notification_id' => $notificationId]
            );
        }
    }
}
