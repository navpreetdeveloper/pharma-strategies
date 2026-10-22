<?php

namespace App\Services\Chat;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Message;
use App\Models\User;
use App\Services\PrivateFileCleanup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MutateMessage
{
    public function execute(User $actor, Message $message, ?string $body, ?string $ipAddress): Message
    {
        return DB::transaction(function () use ($actor, $message, $body, $ipAddress) {
            // Match send/forward/access-management lock order before touching the row.
            Company::whereKey($message->company_id)->lockForUpdate()->firstOrFail();
            $actor = $actor->fresh();
            $current = Message::whereKey($message->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->canWriteConversation($current->conversation) && (int) $current->user_id === (int) $actor->id, 403);
            if ($current->deleted_at) {
                if ($body === null) {
                    return $current;
                }
                throw ValidationException::withMessages(['body' => 'Unsent messages cannot be edited.']);
            }
            $minutes = max(1, (int) config($body === null ? 'chat.message_delete_window_minutes' : 'chat.message_edit_window_minutes', $body === null ? 5 : 10));
            if ($current->created_at->copy()->addMinutes($minutes)->isPast()) {
                throw ValidationException::withMessages([$body === null ? 'message' : 'body' => "The {$minutes}-minute message change window has ended."]);
            }
            if ($body === null) {
                PrivateFileCleanup::stage($current->company_id, $current->attachments()->pluck('path')->all());
                $current->forceFill(['body' => null, 'edited_at' => null, 'deleted_at' => now(), 'deleted_by' => $actor->id, 'message_type' => 'deleted'])->save();
                $current->attachments()->delete();
            } else {
                $current->forceFill(['body' => $body, 'edited_at' => now()])->save();
            }
            AuditLog::create(['company_id' => $current->company_id, 'user_id' => $actor->id, 'action' => $body === null ? 'message_unsent' : 'message_edited', 'target_type' => 'Message', 'target_id' => $current->id, 'ip_address' => $ipAddress]);

            return $current;
        });
    }
}
