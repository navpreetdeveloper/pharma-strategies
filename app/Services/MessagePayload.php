<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Message;

class MessagePayload
{
    public static function make(Message $message): array
    {
        $message->loadMissing(['user', 'attachments', 'replyTo.user', 'forwardedFrom.user']);
        $author = $message->user?->name ?? 'Deleted account';

        $payload = [
            'id' => $message->id,
            'body' => $message->deleted_at ? null : $message->body,
            'user_id' => $message->user_id,
            'user_name' => $author,
            'user' => ['name' => $author],
            'created_at' => $message->created_at?->toIso8601String(),
            'edited_at' => $message->edited_at?->toIso8601String(),
            'deleted_at' => $message->deleted_at?->toIso8601String(),
            'reply_to' => self::reference($message->replyTo, true),
            // Forwarding shares the saved copy, not later edits from another conversation.
            'forwarded_from' => self::reference($message->forwardedFrom, false),
            'attachments' => $message->deleted_at ? [] : $message->attachments->map(fn (Attachment $attachment) => self::attachment($attachment))->values()->all(),
        ];
        $payload['revision'] = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return $payload;
    }

    private static function reference(?Message $message, bool $includeBody): ?array
    {
        if (! $message) {
            return null;
        }
        $reference = ['id' => $message->id, 'user_id' => $message->user_id, 'user_name' => $message->user?->name ?? 'Deleted account'];
        if ($includeBody) {
            $reference['body'] = $message->deleted_at ? null : $message->body;
        }

        return $reference;
    }

    private static function attachment(Attachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->size,
            'download_url' => route('attachments.download', $attachment),
            'preview_url' => in_array($attachment->mime_type, config('chat.attachments.preview_mimes', []), true) ? route('attachments.preview', $attachment) : null,
        ];
    }
}
