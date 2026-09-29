<?php

namespace App\Http\Controllers;

use App\Events\MessageEdited;
use App\Events\MessageSent;
use App\Events\WorkplaceNotificationSent;
use App\Jobs\DeliverWorkplaceMessageNotification;
use App\Models\{Attachment, AuditLog, Conversation, Message};
use App\Notifications\WorkplaceNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $companyId = $request->session()->get('company_id');

        $conversationQuery = $user->isSuperAdmin()
            ? Conversation::where('company_id', $companyId)
            : $user->conversations()->where('company_id', $companyId);

        // Keep the inbox ordered like a modern messaging app: the thread with
        // the most recent message stays at the top. This is especially useful
        // on mobile because employees do not need to hunt through an old list.
        $rawConversations = $conversationQuery
            ->with('participants')
            ->withMax('messages', 'created_at')
            ->orderByDesc('messages_max_created_at')
            ->orderByDesc('id')
            ->get();

        // Old versions could create duplicate direct conversation rows for the
        // same employee pair. Hide exact duplicates in the UI while preserving
        // the newest conversation record and its complete history.
        $seenDirectPairs = [];
        $conversations = $rawConversations->filter(function (Conversation $conversation) use (&$seenDirectPairs) {
            if ($conversation->type !== 'direct') {
                return true;
            }

            $participantIds = $conversation->participants->pluck('id')->sort()->values()->all();
            $pairKey = $conversation->direct_key ?: implode(':', $participantIds);
            if (isset($seenDirectPairs[$pairKey])) {
                return false;
            }
            $seenDirectPairs[$pairKey] = true;
            return true;
        })->values();

        // Do not auto-open the first conversation. The inbox itself is the
        // default landing screen, matching the mobile conversation-list flow.
        $requestedConversationId = (int) $request->query('conversation');
        $selected = $requestedConversationId > 0
            ? $conversations->firstWhere('id', $requestedConversationId)
            : null;
        $directory = \App\Models\User::whereHas('companies', fn ($query) => $query->where('companies.id', $companyId)->where('company_user.status', 'active'))
            ->where('users.id', '!=', $user->id)
            ->orderBy('name')
            ->get();

        $selectedMessages = collect();
        if ($selected) {
            // Render only the latest 100 messages on the first mobile/desktop
            // paint. The database retains the complete conversation history;
            // bounding the initial payload prevents large threads from making
            // the chat page progressively slower as the company grows.
            $selectedMessages = $selected->messages()
                ->with(['user', 'attachments', 'replyTo.user', 'forwardedFrom.user'])
                ->latest('created_at')
                ->latest('id')
                ->limit(100)
                ->get()
                ->reverse()
                ->values();
        }

        return view('chat.index', compact('conversations', 'selected', 'directory', 'selectedMessages'));
    }

    public function olderMessages(Request $request, Conversation $conversation)
    {
        $this->authorizeAccess($request, $conversation);

        $before = $request->validate([
            'before' => ['required', 'integer', 'min:1'],
        ])['before'];

        $messages = $conversation->messages()
            ->with(['user', 'attachments', 'replyTo.user', 'forwardedFrom.user'])
            ->where('id', '<', $before)
            ->latest('id')
            ->limit(101)
            ->get()
            ->reverse()
            ->values();

        $hasMore = $messages->count() > 100;
        $messages = $messages->take(-100)->values();

        return response()->json([
            'messages' => $messages->map(function (Message $message) {
                return [
                    'id' => $message->id,
                    'user_id' => $message->user_id,
                    'user_name' => $message->user?->name ?? 'Employee',
                    'body' => $message->body,
                    'created_at' => $message->created_at?->toIso8601String(),
                    'edited_at' => $message->edited_at?->toIso8601String(),
                    'deleted_at' => $message->deleted_at?->toIso8601String(),
                    'reply_to' => $message->replyTo ? [
                        'id' => $message->replyTo->id,
                        'user_name' => $message->replyTo->user?->name ?? 'Employee',
                        'body' => $message->replyTo->body,
                    ] : null,
                    'forwarded_from' => $message->forwardedFrom ? [
                        'id' => $message->forwardedFrom->id,
                        'user_name' => $message->forwardedFrom->user?->name ?? 'Employee',
                    ] : null,
                    'attachments' => $message->attachments->map(fn ($attachment) => [
                        'id' => $attachment->id,
                        'name' => $attachment->original_name,
                        'original_name' => $attachment->original_name,
                        'mime_type' => $attachment->mime_type,
                        'size' => $attachment->size,
                        'preview_url' => in_array($attachment->mime_type, [
                            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
                            'video/mp4', 'video/webm', 'video/quicktime',
                            'application/pdf',
                        ], true) ? route('attachments.preview', $attachment) : null,
                        'download_url' => route('attachments.download', $attachment),
                    ])->values(),
                ];
            })->values(),
            'has_more' => $hasMore,
        ]);
    }

    public function latestMessages(Request $request, Conversation $conversation)
    {
        $this->authorizeAccess($request, $conversation);

        $after = $request->validate([
            'after' => ['required', 'integer', 'min:0'],
        ])['after'];

        $messages = $conversation->messages()
            ->with(['user', 'attachments', 'replyTo.user', 'forwardedFrom.user'])
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(25)
            ->get();

        return response()->json([
            'messages' => $messages->map(fn (Message $message) => $this->messagePayload($message))->values(),
            'has_more' => $messages->count() === 25,
        ]);
    }

    public function create(Request $request)
    {
        $companyId = $request->session()->get('company_id');
        $data = $request->validate([
            'user_id' => 'required|integer',
            'name' => 'nullable|string|max:150',
            'type' => 'required|in:direct,group,department,announcement',
        ]);

        $target = \App\Models\User::whereKey($data['user_id'])
            ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId)->where('company_user.status', 'active'))
            ->firstOrFail();

        if ($data['type'] === 'direct') {
            $directKey = $this->directConversationKey($request->user()->id, $target->id);
            $conversation = Conversation::where('company_id', $companyId)
                ->where('type', 'direct')
                ->where('direct_key', $directKey)
                ->first();

            // Backward-compatible guard for older conversations created before direct_key existed.
            if (!$conversation) {
                $conversation = Conversation::where('company_id', $companyId)
                    ->where('type', 'direct')
                    ->with('participants:id')
                    ->get()
                    ->first(function (Conversation $candidate) use ($request, $target) {
                        return $candidate->participants->pluck('id')->sort()->values()->all() === collect([$request->user()->id, $target->id])->sort()->values()->all();
                    });
            }

            if ($conversation) {
                if (!$conversation->direct_key) {
                    $conversation->forceFill(['direct_key' => $directKey])->saveQuietly();
                }
                return redirect()->route('chat', ['conversation' => $conversation->id]);
            }

            try {
                $conversation = Conversation::create([
                    'company_id' => $companyId,
                    'type' => 'direct',
                    'name' => null,
                    'direct_key' => $directKey,
                    'created_by' => $request->user()->id,
                ]);
            } catch (QueryException $exception) {
                if ((string) $exception->getCode() !== '23000') {
                    throw $exception;
                }
                $conversation = Conversation::where('company_id', $companyId)
                    ->where('type', 'direct')
                    ->where('direct_key', $directKey)
                    ->firstOrFail();
            }
        } else {
            $conversation = Conversation::create([
                'company_id' => $companyId,
                'type' => $data['type'],
                'name' => $data['name'],
                'direct_key' => null,
                'created_by' => $request->user()->id,
            ]);
        }

        $conversation->participants()->syncWithoutDetaching([$request->user()->id, $target->id]);

        AuditLog::create([
            'company_id' => $companyId,
            'user_id' => $request->user()->id,
            'action' => 'conversation_created',
            'target_type' => 'Conversation',
            'target_id' => $conversation->id,
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('chat', ['conversation' => $conversation->id]);
    }

    public function send(Request $request, Conversation $conversation)
    {
        $this->authorizeAccess($request, $conversation);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array', 'max:' . config('chat.attachments.max_files', 5)],
            'attachments.*' => [
                'file',
                'max:' . (config('chat.attachments.max_video_mb', 100) * 1024),
                'mimes:' . implode(',', config('chat.attachments.mimes', [])),
            ],
            'message_type' => ['nullable', 'in:text'],
            'reply_to_message_id' => ['nullable', 'integer'],
        ]);

        $files = array_values(array_filter($request->file('attachments', [])));
        $body = trim((string) ($data['body'] ?? ''));
        $replyTo = null;
        if (!empty($data['reply_to_message_id'])) {
            $replyTo = Message::whereKey($data['reply_to_message_id'])
                ->where('conversation_id', $conversation->id)
                ->whereNull('deleted_at')
                ->firstOrFail();
        }

        if ($body === '' && count($files) === 0) {
            throw ValidationException::withMessages(['body' => 'Write a message or attach at least one file.']);
        }

        $this->validateAttachmentLimits($files);

        $storedPaths = [];
        try {
            $message = DB::transaction(function () use ($request, $conversation, $body, $files, $replyTo, &$storedPaths): Message {
                $message = Message::create([
                    'company_id' => $conversation->company_id,
                    'conversation_id' => $conversation->id,
                    'user_id' => $request->user()->id,
                    'body' => $body !== '' ? $body : null,
                    'message_type' => 'text',
                    'reply_to_message_id' => $replyTo?->id,
                ]);

                foreach ($files as $file) {
                    $extension = strtolower($file->extension() ?: 'bin');
                    $path = 'companies/' . $conversation->company_id
                        . '/conversations/' . $conversation->id
                        . '/messages/' . $message->id . '/' . Str::uuid() . '.' . $extension;
                    $stored = $this->attachmentDisk()->putFileAs(dirname($path), $file, basename($path));

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

                return $message;
            });

            $message->load(['user', 'attachments', 'replyTo.user', 'forwardedFrom.user']);

            $messagePreview = trim((string) ($message->body ?? ''));
            if ($messagePreview === '') {
                $messagePreview = $message->attachments->count() === 1
                    ? 'Sent an attachment'
                    : 'Sent ' . $message->attachments->count() . ' attachments';
            }
            $messagePreview = Str::limit(preg_replace('/\s+/', ' ', $messagePreview), 180);
            $senderInitials = collect(preg_split('/\s+/', trim($request->user()->name)))
                ->filter()
                ->take(2)
                ->map(fn ($part) => Str::upper(Str::substr($part, 0, 1)))
                ->implode('');

            // Do not make the HTTP send request wait for Reverb. The UI already
            // renders an optimistic message, while this broadcast is delivered
            // from Laravel's terminating phase after the response has been sent.
            $this->broadcastAfterResponse(new MessageSent($message));

            // Notification persistence and OS push are deferred until after the
            // HTTP response so sending a message remains fast on mobile.
            DeliverWorkplaceMessageNotification::dispatchAfterResponse(
                (int) $conversation->id,
                (int) $request->user()->id,
                (string) $request->user()->name,
                $senderInitials ?: 'PS',
                (int) $message->id,
                $messagePreview,
            );

            return response()->json([
                'ok' => true,
                'message' => [
                    ...$this->messagePayload($message),
                ],
            ]);
        } catch (Throwable $e) {
            foreach ($storedPaths as $path) {
                $this->attachmentDisk()->delete($path);
            }
            throw $e;
        }
    }

    private function messagePayload(Message $message): array
    {
        $message->loadMissing(['user', 'attachments', 'replyTo.user', 'forwardedFrom.user']);

        return [
            'id' => $message->id,
            'body' => $message->body,
            'user_id' => $message->user_id,
            'user_name' => $message->user?->name ?? 'Employee',
            'user' => ['name' => $message->user?->name],
            'created_at' => $message->created_at?->toIso8601String(),
            'edited_at' => $message->edited_at?->toIso8601String(),
            'deleted_at' => $message->deleted_at?->toIso8601String(),
            'reply_to' => $message->replyTo ? [
                'id' => $message->replyTo->id,
                'body' => $message->replyTo->body,
                'user_id' => $message->replyTo->user_id,
                'user_name' => $message->replyTo->user?->name,
            ] : null,
            'forwarded_from' => $message->forwardedFrom ? [
                'id' => $message->forwardedFrom->id,
                'user_id' => $message->forwardedFrom->user_id,
                'user_name' => $message->forwardedFrom->user?->name,
                'body' => $message->forwardedFrom->body,
            ] : null,
            'attachments' => $message->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'name' => $attachment->original_name,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
                'download_url' => route('attachments.download', $attachment),
                'preview_url' => in_array($attachment->mime_type, $this->previewableMimeTypes(), true)
                    ? route('attachments.preview', $attachment)
                    : null,
            ])->values()->all(),
        ];
    }

    public function heartbeat(Request $request)
    {
        $request->user()->forceFill(['last_seen_at' => now()])->saveQuietly();
        return response()->json(['ok' => true, 'last_seen_at' => now()->toIso8601String()]);
    }

    public function edit(Request $request, Message $message)
    {
        $conversation = $message->conversation;
        $this->authorizeAccess($request, $conversation);

        if ((int) $message->user_id !== (int) $request->user()->id) {
            abort(403);
        }

        if ($message->deleted_at) {
            throw ValidationException::withMessages(['body' => 'Deleted messages cannot be edited.']);
        }

        $window = max(1, (int) config('chat.message_edit_window_minutes', 10));
        if ($message->created_at->copy()->addMinutes($window)->isPast()) {
            throw ValidationException::withMessages([
                'body' => "Messages can only be edited within {$window} minutes of sending.",
            ]);
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $body = trim($data['body']);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'The message cannot be empty.']);
        }

        $message->forceFill([
            'body' => $body,
            'edited_at' => now(),
        ])->save();

        $message->load('user');
        $this->broadcastAfterResponse(new MessageEdited($message));

        AuditLog::create([
            'company_id' => $conversation->company_id,
            'user_id' => $request->user()->id,
            'action' => 'message_edited',
            'target_type' => 'Message',
            'target_id' => $message->id,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => [
                'id' => $message->id,
                'body' => $message->body,
                'edited_at' => $message->edited_at?->toIso8601String(),
            ],
        ]);
    }

    public function destroy(Request $request, Message $message)
    {
        $conversation = $message->conversation;
        $this->authorizeAccess($request, $conversation);

        if ((int) $message->user_id !== (int) $request->user()->id) {
            abort(403);
        }

        $window = max(1, (int) config('chat.message_delete_window_minutes', 5));
        if ($message->created_at->copy()->addMinutes($window)->isPast()) {
            throw ValidationException::withMessages([
                'message' => "Messages can only be unsent within {$window} minutes of sending.",
            ]);
        }

        foreach ($message->attachments as $attachment) {
            $this->attachmentDisk()->delete($attachment->path);
        }
        $message->attachments()->delete();

        $message->forceFill([
            'body' => null,
            'edited_at' => null,
            'deleted_at' => now(),
            'deleted_by' => $request->user()->id,
            'message_type' => 'deleted',
        ])->save();

        $this->broadcastAfterResponse(new \App\Events\MessageDeleted($message));

        AuditLog::create([
            'company_id' => $conversation->company_id,
            'user_id' => $request->user()->id,
            'action' => 'message_unsent',
            'target_type' => 'Message',
            'target_id' => $message->id,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['ok' => true, 'message' => ['id' => $message->id, 'deleted_at' => $message->deleted_at?->toIso8601String()]]);
    }

    public function forward(Request $request, Message $message)
    {
        $sourceConversation = $message->conversation;
        $this->authorizeAccess($request, $sourceConversation);

        $data = $request->validate([
            'conversation_ids' => ['required', 'array', 'min:1', 'max:20'],
            'conversation_ids.*' => ['integer', 'distinct'],
        ]);

        $targets = Conversation::whereIn('id', $data['conversation_ids'])
            ->where('company_id', $request->session()->get('company_id'))
            ->get();

        if ($targets->count() !== count($data['conversation_ids'])) abort(403);
        foreach ($targets as $target) $this->authorizeAccess($request, $target);

        if ($message->deleted_at) {
            throw ValidationException::withMessages(['message' => 'Deleted messages cannot be forwarded.']);
        }

        $message->load(['user', 'attachments']);
        $created = [];

        foreach ($targets as $target) {
            $storedPaths = [];
            try {
                $newMessage = DB::transaction(function () use ($message, $target, &$storedPaths): Message {
                    $copy = Message::create([
                        'company_id' => $target->company_id,
                        'conversation_id' => $target->id,
                        'user_id' => auth()->id(),
                        'body' => $message->body,
                        'message_type' => 'text',
                        'forwarded_from_message_id' => $message->id,
                    ]);

                    foreach ($message->attachments as $attachment) {
                        $extension = pathinfo($attachment->original_name, PATHINFO_EXTENSION) ?: 'bin';
                        $newPath = 'companies/' . $target->company_id . '/conversations/' . $target->id . '/messages/' . $copy->id . '/' . Str::uuid() . '.' . strtolower($extension);
                        $this->attachmentDisk()->makeDirectory(dirname($newPath));
                        if (!$this->attachmentDisk()->exists($attachment->path) || !$this->attachmentDisk()->copy($attachment->path, $newPath)) {
                            throw new \RuntimeException('A forwarded attachment could not be copied securely.');
                        }
                        $storedPaths[] = $newPath;
                        $copy->attachments()->create([
                            'company_id' => $target->company_id,
                            'original_name' => $attachment->original_name,
                            'path' => $newPath,
                            'mime_type' => $attachment->mime_type,
                            'size' => $attachment->size,
                        ]);
                    }
                    return $copy;
                });

                $newMessage->load(['user', 'attachments', 'forwardedFrom.user']);
                $this->broadcastAfterResponse(new MessageSent($newMessage));
                AuditLog::create([
                    'company_id' => $target->company_id,
                    'user_id' => auth()->id(),
                    'action' => 'message_forwarded',
                    'target_type' => 'Message',
                    'target_id' => $newMessage->id,
                    'ip_address' => $request->ip(),
                    'metadata' => ['source_message_id' => $message->id, 'target_conversation_id' => $target->id],
                ]);
                $created[] = $newMessage->id;
            } catch (Throwable $e) {
                foreach ($storedPaths as $path) $this->attachmentDisk()->delete($path);
                throw $e;
            }
        }

        return response()->json(['ok' => true, 'message_ids' => $created]);
    }

    public function download(Request $request, Attachment $attachment)
    {
        $this->authorizeAttachment($request, $attachment);

        $disk = $this->attachmentDisk();
        if (!$disk->exists($attachment->path)) {
            abort(404, 'Attachment file is no longer available.');
        }

        $downloadName = Str::ascii($attachment->original_name) ?: 'attachment';
        return $disk->download($attachment->path, $downloadName);
    }

    public function preview(Request $request, Attachment $attachment)
    {
        $this->authorizeAttachment($request, $attachment);

        if (!in_array($attachment->mime_type, $this->previewableMimeTypes(), true)) {
            abort(404);
        }

        // Attachments may live on local storage during development or an
        // S3-compatible disk in production. Never build a filesystem path
        // manually for object storage: stream through Laravel's configured
        // filesystem adapter instead. This fixes previews/downloads when the
        // production disk is not the local container filesystem.
        $disk = $this->attachmentDisk();
        if (!$disk->exists($attachment->path)) {
            abort(404, 'Attachment file is no longer available.');
        }

        $previewName = Str::ascii($attachment->original_name) ?: 'attachment';

        return $disk->response(
            $attachment->path,
            $previewName,
            [
                'Content-Type' => $attachment->mime_type,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=3600',
            ],
            'inline',
        );
    }

    private function directConversationKey(int $firstUserId, int $secondUserId): string
    {
        $ids = [$firstUserId, $secondUserId];
        sort($ids, SORT_NUMERIC);
        return $ids[0] . ':' . $ids[1];
    }

    private function authorizeAccess(Request $request, Conversation $conversation): void
    {
        if ($conversation->company_id != $request->session()->get('company_id') || !$request->user()->canAccessConversation($conversation)) {
            abort(403);
        }
    }

    private function authorizeAttachment(Request $request, Attachment $attachment): void
    {
        if ((int) $attachment->company_id !== (int) $request->session()->get('company_id')) {
            abort(404);
        }

        $conversation = $attachment->message()->with('conversation')->firstOrFail()->conversation;
        $this->authorizeAccess($request, $conversation);
    }

    private function validateAttachmentLimits(array $files): void
    {
        $totalBytes = 0;
        $totalLimit = config('chat.attachments.max_total_mb', 120) * 1024 * 1024;
        $imageLimit = config('chat.attachments.max_image_mb', 10) * 1024 * 1024;
        $videoLimit = config('chat.attachments.max_video_mb', 100) * 1024 * 1024;
        $documentLimit = config('chat.attachments.max_document_mb', 25) * 1024 * 1024;

        foreach ($files as $file) {
            $size = (int) $file->getSize();
            $mime = (string) ($file->getMimeType() ?: '');
            $totalBytes += $size;

            $limit = str_starts_with($mime, 'image/')
                ? $imageLimit
                : (str_starts_with($mime, 'video/') ? $videoLimit : $documentLimit);

            if ($size > $limit) {
                $label = str_starts_with($mime, 'image/') ? 'Images' : (str_starts_with($mime, 'video/') ? 'Videos' : 'Documents');
                $mb = (int) round($limit / 1024 / 1024);
                throw ValidationException::withMessages([
                    'attachments' => ["{$label} must be {$mb} MB or smaller."],
                ]);
            }
        }

        if ($totalBytes > $totalLimit) {
            throw ValidationException::withMessages([
                'attachments' => ['The combined attachment size is too large. Please send fewer or smaller files.'],
            ]);
        }
    }

    private function attachmentDisk()
    {
        return Storage::disk(config('chat.attachments.disk', config('filesystems.default', 'local')));
    }

    private function broadcastAfterResponse(object $event): void
    {
        app()->terminating(function () use ($event): void {
            try {
                broadcast($event);
            } catch (Throwable $exception) {
                // Realtime delivery must never turn a successful message send
                // into a failed request when Reverb is temporarily unavailable.
                report($exception);
            }
        });
    }

    private function previewableMimeTypes(): array
    {
        return [
            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
            'video/mp4', 'video/webm', 'video/quicktime',
            'application/pdf',
        ];
    }
}
