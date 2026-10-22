<?php

namespace App\Services\Chat;

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

class SendMessage
{
    public function __construct(private readonly AttachmentPolicy $attachments) {}

    public function execute(User $actor, Conversation $conversation, array $data, array $files): Message
    {
        $body = trim((string) ($data['body'] ?? ''));
        if ($body === '' && count($files) === 0) {
            throw ValidationException::withMessages(['body' => 'Write a message or attach at least one file.']);
        }

        $this->attachments->validateLimits($files);

        $submissionId = $data['client_message_id'] ?? null;
        $submissionHash = $submissionId ? hash('sha256', json_encode([
            $body, (int) ($data['reply_to_message_id'] ?? 0),
            array_map(fn ($file) => [$file->getClientOriginalName(), hash_file('sha256', $file->getRealPath())], $files),
        ], JSON_THROW_ON_ERROR)) : null;

        $storedPaths = [];
        $committed = false;
        try {
            $message = DB::transaction(function () use ($actor, $conversation, $body, $files, $data, $submissionId, $submissionHash, &$storedPaths): Message {
                Company::whereKey($conversation->company_id)->lockForUpdate()->firstOrFail();
                abort_unless($actor->fresh()->canWriteConversation($conversation), 403, 'Only active conversation participants can send messages.');
                if ($submissionId) {
                    $existing = $conversation->messages()->where('user_id', $actor->id)->where('client_message_id', $submissionId)->first();
                    if ($existing) {
                        abort_unless(hash_equals($existing->submission_hash, $submissionHash), 409, 'This message request was already used for different content.');

                        return $existing;
                    }
                }
                $replyTo = null;
                if (! empty($data['reply_to_message_id'])) {
                    $replyTo = Message::whereKey($data['reply_to_message_id'])
                        ->where('conversation_id', $conversation->id)
                        ->whereNull('deleted_at')
                        ->firstOrFail();
                }

                $message = Message::create([
                    'company_id' => $conversation->company_id,
                    'conversation_id' => $conversation->id,
                    'user_id' => $actor->id,
                    'body' => $body !== '' ? $body : null,
                    'message_type' => 'text',
                    'reply_to_message_id' => $replyTo?->id,
                    'client_message_id' => $submissionId,
                    'submission_hash' => $submissionHash,
                ]);

                foreach ($files as $file) {
                    $extension = strtolower($file->extension() ?: 'bin');
                    $path = 'companies/'.$conversation->company_id
                        .'/conversations/'.$conversation->id
                        .'/messages/'.$message->id.'/'.Str::uuid().'.'.$extension;
                    $stored = Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

                    if ($stored === false) {
                        throw new \RuntimeException('The file could not be stored securely.');
                    }

                    $storedPaths[] = $stored;
                    $message->attachments()->create([
                        'company_id' => $conversation->company_id,
                        'original_name' => Str::limit($file->getClientOriginalName(), 255, ''),
                        'path' => $stored,
                        'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                        'size' => (int) $file->getSize(),
                    ]);
                }

                MessageDelivery::record($message);

                return $message;
            });

            $committed = true;
            $message->load(['user', 'attachments', 'replyTo.user', 'forwardedFrom.user']);

            MessageDelivery::dispatch($message);

            return $message;
        } catch (Throwable $e) {
            if (! $committed) {
                PrivateFileCleanup::discard($conversation->company_id, $storedPaths);
            }
            throw $e;
        }
    }
}
