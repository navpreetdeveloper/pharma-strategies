<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.' . $this->message->conversation_id)];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        $this->message->loadMissing(['user', 'attachments', 'replyTo.user', 'forwardedFrom.user']);
        return $this->messagePayload();
    }

    private function messagePayload(): array
    {
        $reply = $this->message->replyTo;
        $forward = $this->message->forwardedFrom;

        return [
            'id' => $this->message->id,
            'body' => $this->message->body,
            'user_id' => $this->message->user_id,
            'user_name' => $this->message->user->name,
            'created_at' => $this->message->created_at->toIso8601String(),
            'edited_at' => $this->message->edited_at?->toIso8601String(),
            'deleted_at' => $this->message->deleted_at?->toIso8601String(),
            'reply_to' => $reply ? [
                'id' => $reply->id,
                'body' => $reply->body,
                'user_id' => $reply->user_id,
                'user_name' => $reply->user?->name,
            ] : null,
            'forwarded_from' => $forward ? [
                'id' => $forward->id,
                'user_id' => $forward->user_id,
                'user_name' => $forward->user?->name,
                'body' => $forward->body,
            ] : null,
            'attachments' => $this->message->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'name' => $attachment->original_name,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
                'download_url' => route('attachments.download', $attachment),
                'preview_url' => in_array($attachment->mime_type, [
                    'image/jpeg', 'image/png', 'image/webp', 'image/gif',
                    'video/mp4', 'video/webm', 'video/quicktime', 'application/pdf',
                ], true) ? route('attachments.preview', $attachment) : null,
            ])->values()->all(),
        ];
    }
}
