<?php

namespace App\Http\Controllers;

use App\Events\MessageDeleted;
use App\Events\MessageEdited;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat\ConversationDirectory;
use App\Services\Chat\CreateConversation;
use App\Services\Chat\ForwardMessage;
use App\Services\Chat\MutateMessage;
use App\Services\Chat\PrivateAttachmentPath;
use App\Services\Chat\SendMessage;
use App\Services\MessagePayload;
use App\Services\PrivateFileCleanup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChatController extends Controller
{
    public function __construct(private readonly ConversationDirectory $directory, private readonly SendMessage $sender, private readonly CreateConversation $creator, private readonly ForwardMessage $forwarder, private readonly MutateMessage $mutator) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $companyId = $request->session()->get('company_id');
        if ($user->isPlatformSuperAdmin() && ! $companyId) {
            return redirect()->route('platform.companies.index');
        }
        $query = $this->directory->query($user, (int) $companyId);
        if ($request->header('X-Chat-Sidebar') === '1') {
            $conversations = $query->orderByDesc('messages_max_created_at')->orderByDesc('id')->paginate(50, ['*'], 'conversations_page')->withQueryString();

            return response()->view('chat.conversations', ['conversations' => $conversations, 'selected' => null])->header('Cache-Control', 'private, no-store');
        }
        $selected = null;
        if ($request->filled('conversation')) {
            $selected = Conversation::with(['participants.companies' => fn ($q) => $q->where('companies.id', $companyId)])->whereKey($request->integer('conversation'))->where('company_id', $companyId)->firstOrFail();
            $this->authorizeAccess($request, $selected);
        }
        $directory = User::with(['companies' => fn ($q) => $q->where('companies.id', $companyId)])
            ->where('users.status', 'active')->when(config('security.require_email_verification'), fn ($q) => $q->whereNotNull('email_verified_at'))
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $companyId)->where('company_user.status', 'active'))
            ->where('users.id', '!=', $user->id)->orderBy('users.name')->paginate(50, ['*'], 'employees_page')->withQueryString();
        $selectedMessages = collect();
        $hasOlderMessages = false;
        $recoveryCursor = 0;
        if ($selected) {
            $recoveryCursor = (int) $selected->messages()->max('id');
            $rows = $selected->messages()->where('id', '<=', $recoveryCursor)->with(['user.companies' => fn ($q) => $q->where('companies.id', $companyId), 'attachments', 'replyTo.user', 'forwardedFrom.user'])->orderByDesc('created_at')->orderByDesc('id')->limit(51)->get();
            $hasOlderMessages = $rows->count() > 50;
            $selectedMessages = $rows->take(50)->reverse()->values();
            $this->markReadThrough($request, $selected, (int) $selectedMessages->max('id'));
            $this->auditRead($request, $selected);
        }

        $conversations = $query->orderByDesc('messages_max_created_at')->orderByDesc('id')->paginate(50, ['*'], 'conversations_page')->withQueryString();
        $canWrite = $selected && $user->canWriteConversation($selected);
        // All rows are company-scoped and their participants were freshly eager-loaded.
        // This only controls the menu; ForwardMessage reauthorizes each mutation.
        $writableConversationIds = $canWrite
            ? $conversations->filter(fn ($target) => $target->participants->contains('id', $user->id))->pluck('id')
            : collect();
        $data = compact('conversations', 'selected', 'directory', 'selectedMessages', 'hasOlderMessages', 'recoveryCursor', 'canWrite', 'writableConversationIds');
        if ($request->header('X-Chat-View') === '1') {
            return response()->view('chat.view', $data)
                ->header('Cache-Control', 'private, no-store')
                ->header('X-Chat-Title', 'Messages - Pharma Strategies');
        }

        return view('chat.index', $data);
    }

    public function history(Request $request, Conversation $conversation)
    {
        $this->authorizeAccess($request, $conversation);
        $data = $request->validate(['before' => 'required|integer|min:1']);
        $cursor = $conversation->messages()->whereKey($data['before'])->firstOrFail();
        $rows = $conversation->messages()->with(['user', 'attachments', 'replyTo.user', 'forwardedFrom.user'])
            ->where(fn ($q) => $q->where('created_at', '<', $cursor->created_at)->orWhere(fn ($q) => $q->where('created_at', $cursor->created_at)->where('id', '<', $cursor->id)))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(51)->get();
        $this->auditRead($request, $conversation);

        return response()->json(['messages' => $rows->take(50)->reverse()->values()->map(fn ($m) => MessagePayload::make($m)), 'has_more' => $rows->count() > 50]);
    }

    public function updates(Request $request, Conversation $conversation)
    {
        $this->authorizeAccess($request, $conversation);
        $data = $request->validate([
            'after' => 'required|integer|min:0',
            'sidebar' => 'sometimes|boolean',
            'conversations_page' => 'sometimes|integer|min:1',
            'visible' => 'sometimes|array|max:100',
            'visible.*' => 'integer|min:1|distinct',
        ]);
        $messages = $conversation->messages()->with(['user', 'attachments', 'replyTo.user', 'forwardedFrom.user'])
            ->where('id', '>', $data['after'])->orderBy('id')->limit(101)->get();
        $hasMore = $messages->count() > 100;
        $messages = $messages->take(100);
        if ($messages->isNotEmpty()) {
            $this->auditRead($request, $conversation);
        }

        $payload = ['messages' => $messages->map(fn ($m) => MessagePayload::make($m)), 'cursor' => (int) ($messages->last()?->id ?? $data['after']), 'has_more' => $hasMore];
        if (isset($data['visible'])) {
            $visible = array_map('intval', $data['visible']);
            $current = $conversation->messages()->with(['user', 'attachments', 'replyTo.user', 'forwardedFrom.user'])->whereIn('id', $visible)->get();
            $payload['reconciled'] = $current->map(fn ($m) => MessagePayload::make($m))->values();
            $payload['removed'] = array_values(array_diff($visible, $current->pluck('id')->all()));
            if ($current->isNotEmpty() && $messages->isEmpty()) {
                $this->auditRead($request, $conversation);
            }
        }
        if ($request->boolean('sidebar')) {
            // The web client reconciles messages and unread cards in one request.
            // Keep the existing message-only contract for other clients.
            $conversations = $this->directory->query($request->user(), $conversation->company_id)
                ->orderByDesc('messages_max_created_at')->orderByDesc('id')
                ->paginate(50, ['*'], 'conversations_page', $data['conversations_page'] ?? 1)
                ->withPath(route('chat'))->appends(['conversation' => $conversation->id]);
            $payload['sidebar'] = view('chat.conversations', ['conversations' => $conversations, 'selected' => $conversation])->render();
        }

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, Conversation $conversation)
    {
        $this->authorizeAccess($request, $conversation);
        abort_unless($conversation->participants()->whereKey($request->user()->id)->exists(), 403);
        $data = $request->validate(['last_message_id' => 'required|integer|min:1']);
        $message = $conversation->messages()->whereKey($data['last_message_id'])->firstOrFail();
        $this->markReadThrough($request, $conversation, $message->id);

        return response()->json(['ok' => true]);
    }

    private function markReadThrough(Request $request, Conversation $conversation, int $messageId): void
    {
        DB::table('conversation_participants')->where('conversation_id', $conversation->id)->where('user_id', $request->user()->id)
            ->where('last_read_message_id', '<', $messageId)->update(['last_read_message_id' => $messageId, 'last_read_at' => now(), 'updated_at' => now()]);
    }

    public function showMessage(Request $request, Message $message)
    {
        $this->authorizeAccess($request, $message->conversation);
        $this->auditRead($request, $message->conversation);

        return response()->json(['message' => MessagePayload::make($message)]);
    }

    private function auditRead(Request $request, Conversation $conversation): void
    {
        if (! $conversation->participants()->whereKey($request->user()->id)->exists()) {
            AuditLog::create(['company_id' => $conversation->company_id, 'user_id' => $request->user()->id, 'action' => 'privileged_conversation_read', 'target_type' => 'Conversation', 'target_id' => $conversation->id, 'ip_address' => $request->ip()]);
        }
    }

    public function create(Request $request)
    {
        abort_if($request->user()->isPlatformSuperAdmin(), 403, 'Platform Super Admin access is read-only.');
        $companyId = $request->session()->get('company_id');
        $data = $request->validate([
            'user_id' => 'required|integer',
            'name' => 'nullable|string|max:150',
            'type' => 'required|in:direct,group,department,announcement',
        ]);

        $conversation = $this->creator->execute($request->user(), (int) $companyId, $data, $request->ip());

        return redirect()->route('chat', ['conversation' => $conversation->id]);
    }

    public function send(Request $request, Conversation $conversation)
    {
        abort_if($request->user()->isPlatformSuperAdmin(), 403, 'Platform Super Admin access is read-only.');
        $this->authorizeWrite($request, $conversation);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array', 'max:'.config('chat.attachments.max_files', 5)],
            'attachments.*' => [
                'file',
                'max:'.(config('chat.attachments.max_video_mb', 100) * 1024),
                'mimes:'.implode(',', config('chat.attachments.mimes', [])),
            ],
            'message_type' => ['nullable', 'in:text'],
            'reply_to_message_id' => ['nullable', 'integer'],
            'client_message_id' => ['nullable', 'uuid'],
        ]);

        $message = $this->sender->execute($request->user(), $conversation, $data, array_values(array_filter($request->file('attachments', []))));

        return response()->json(['ok' => true, 'message' => MessagePayload::make($message)]);
    }

    public function heartbeat(Request $request)
    {
        abort_if($request->user()->isPlatformSuperAdmin(), 403, 'Platform Super Admin accounts do not have company presence.');
        $request->user()->forceFill(['last_seen_at' => now()])->saveQuietly();

        return response()->json(['ok' => true, 'last_seen_at' => now()->toIso8601String()]);
    }

    public function presence(Request $request)
    {
        $data = $request->validate(['ids' => 'required|array|max:100', 'ids.*' => 'required|integer|min:1']);
        $companyId = $request->session()->get('company_id');
        $users = User::whereIn('id', $data['ids'])->where('status', 'active')->where('must_change_password', false)
            ->when(config('security.require_email_verification'), fn ($q) => $q->whereNotNull('email_verified_at'))
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $companyId)->where('companies.status', 'active')->where('company_user.status', 'active'))
            ->get(['id', 'last_seen_at']);

        return response()->json(['users' => $users->map(fn ($user) => [
            'id' => $user->id,
            'online' => $user->last_seen_at && $user->last_seen_at->gt(now()->subSeconds(60)),
            'last_seen_at' => $user->last_seen_at?->toIso8601String(),
        ])])->header('Cache-Control', 'private, no-store');
    }

    public function edit(Request $request, Message $message)
    {
        $this->authorizeWrite($request, $message->conversation);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $body = trim($data['body']);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'The message cannot be empty.']);
        }
        $message = $this->mutator->execute($request->user(), $message, $body, $request->ip());
        try {
            broadcast(new MessageEdited($message));
        } catch (Throwable $error) {
            report($error);
        }

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
        $this->authorizeWrite($request, $message->conversation);
        $message = $this->mutator->execute($request->user(), $message, null, $request->ip());
        PrivateFileCleanup::flush();
        try {
            broadcast(new MessageDeleted($message));
        } catch (Throwable $error) {
            report($error);
        }

        return response()->json(['ok' => true, 'message' => ['id' => $message->id, 'deleted_at' => $message->deleted_at?->toIso8601String()]]);
    }

    public function forward(Request $request, Message $message)
    {
        abort_if($request->user()->isPlatformSuperAdmin(), 403, 'Platform Super Admin access is read-only.');
        $sourceConversation = $message->conversation;
        $this->authorizeWrite($request, $sourceConversation);

        $data = $request->validate([
            'conversation_ids' => ['required', 'array', 'min:1', 'max:20'],
            'conversation_ids.*' => ['integer', 'distinct'],
        ]);

        $created = $this->forwarder->execute($request->user(), $message, (int) $request->session()->get('company_id'), $data['conversation_ids'], $request->ip());

        return response()->json(['ok' => true, 'message_ids' => $created]);
    }

    public function download(Request $request, Attachment $attachment)
    {
        $this->authorizeAttachment($request, $attachment);
        $downloadName = Str::ascii($attachment->original_name) ?: 'attachment';

        $path = PrivateAttachmentPath::resolve($attachment);
        abort_if($path === null, 404, 'Attachment file is no longer available.');

        return response()->download($path, $downloadName);
    }

    public function preview(Request $request, Attachment $attachment)
    {
        $this->authorizeAttachment($request, $attachment);

        if (! in_array($attachment->mime_type, config('chat.attachments.preview_mimes', []), true)) {
            abort(404);
        }

        $absolutePath = PrivateAttachmentPath::resolve($attachment);
        if ($absolutePath === null) {
            abort(404, 'Attachment file is no longer available.');
        }

        return response()->file(
            $absolutePath,
            [
                'Content-Type' => $attachment->mime_type,
                'Content-Disposition' => 'inline; filename="'.addslashes(Str::ascii($attachment->original_name) ?: 'attachment').'"',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    private function authorizeWrite(Request $request, Conversation $conversation): void
    {
        $this->authorizeAccess($request, $conversation);
        abort_unless($request->user()->fresh()->canWriteConversation($conversation), 403, 'Only active conversation participants can send messages.');
    }

    private function authorizeAccess(Request $request, Conversation $conversation): void
    {
        if ($conversation->company_id != $request->session()->get('company_id') || ! $request->user()->canAccessConversation($conversation)) {
            abort(403);
        }
    }

    private function authorizeAttachment(Request $request, Attachment $attachment): void
    {
        if ((int) $attachment->company_id !== (int) $request->session()->get('company_id')) {
            abort(404);
        }

        $message = $attachment->message()->with('conversation')->firstOrFail();
        $conversation = $message->conversation;
        abort_if($message->deleted_at || (int) $message->company_id !== (int) $attachment->company_id || (int) $conversation->company_id !== (int) $attachment->company_id, 404);
        $this->authorizeAccess($request, $conversation);
        $this->auditRead($request, $conversation);
    }
}
