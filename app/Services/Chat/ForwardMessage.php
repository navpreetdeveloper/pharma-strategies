<?php

namespace App\Services\Chat;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\MessageDelivery;
use App\Services\PrivateFileCleanup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ForwardMessage
{
    /** All selected destinations succeed together; delivery is dispatched after commit. */
    public function execute(User $actor, Message $source, int $companyId, array $conversationIds, ?string $ipAddress): array
    {
        $storedPaths = [];
        $committed = false;
        try {
            $copies = DB::transaction(function () use ($actor, $source, $companyId, $conversationIds, $ipAddress, &$storedPaths) {
                Company::whereKey($companyId)->where('status', 'active')->lockForUpdate()->firstOrFail();
                $actor = $actor->fresh();
                $source = Message::with(['attachments', 'conversation'])->lockForUpdate()->findOrFail($source->id);
                abort_unless($source->company_id === $companyId && $actor->canWriteConversation($source->conversation), 403);
                if ($source->deleted_at) {
                    throw ValidationException::withMessages(['message' => 'Deleted messages cannot be forwarded.']);
                }
                $targets = Conversation::whereIn('id', $conversationIds)->where('company_id', $companyId)->orderBy('id')->get();
                abort_unless($targets->count() === count($conversationIds), 403);
                foreach ($targets as $target) {
                    abort_unless($actor->canWriteConversation($target), 403);
                }
                $copies = collect();
                foreach ($targets as $target) {
                    $copy = Message::create(['company_id' => $companyId, 'conversation_id' => $target->id,
                        'user_id' => $actor->id, 'body' => $source->body, 'message_type' => 'text', 'forwarded_from_message_id' => $source->id]);
                    foreach ($source->attachments as $attachment) {
                        $extension = pathinfo($attachment->original_name, PATHINFO_EXTENSION) ?: 'bin';
                        $path = 'companies/'.$companyId.'/conversations/'.$target->id.'/messages/'.$copy->id.'/'.Str::uuid().'.'.strtolower($extension);
                        Storage::disk('local')->makeDirectory(dirname($path));
                        if ((int) $attachment->company_id !== $companyId || PrivateAttachmentPath::resolve($attachment) === null || ! Storage::disk('local')->copy($attachment->path, $path)) {
                            throw new \RuntimeException('A forwarded attachment could not be copied securely.');
                        }
                        $storedPaths[] = $path;
                        $copy->attachments()->create(['company_id' => $companyId, 'original_name' => $attachment->original_name,
                            'path' => $path, 'mime_type' => $attachment->mime_type, 'size' => $attachment->size]);
                    }
                    MessageDelivery::record($copy);
                    AuditLog::create(['company_id' => $companyId, 'user_id' => $actor->id, 'action' => 'message_forwarded',
                        'target_type' => 'Message', 'target_id' => $copy->id, 'ip_address' => $ipAddress,
                        'metadata' => ['source_message_id' => $source->id, 'target_conversation_id' => $target->id]]);
                    $copies->push($copy);
                }

                return $copies;
            });
            $committed = true;
            foreach ($copies as $copy) {
                MessageDelivery::dispatch($copy);
            }

            return $copies->pluck('id')->all();
        } catch (Throwable $error) {
            if (! $committed) {
                PrivateFileCleanup::discard($companyId, $storedPaths);
            }
            throw $error;
        }
    }
}
