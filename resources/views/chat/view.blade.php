<div data-chat-view>
<div class="nc-chat-layout">
    <aside id="conversation-panel" class="nc-card nc-chat-sidebar {{ $selected ? 'mobile-hidden' : '' }}">
        <div class="nc-chat-top">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="nc-chat-title">Conversations</div>
                    <div class="text-xs nc-muted mt-1 truncate">{{ auth()->user()->name }} · {{ ucwords(str_replace('_',' ', auth()->user()->role() ?? 'Platform Super Admin')) }}</div>
                </div>
                <span class="nc-secure-pill">{{ auth()->user()->isPlatformSuperAdmin() ? 'Review' : 'Team chat' }}</span>
            </div>
            @if(!auth()->user()->isPlatformSuperAdmin() && isset($directory) && $directory->count())
                <form method="POST" action="{{ route('conversations.create') }}" class="nc-new-chat-form mt-4">
                    @csrf
                    <input type="hidden" name="type" value="direct">
                    <select name="user_id" class="nc-input text-sm" aria-label="Employee to message">
                        @foreach($directory as $d)
                            <option value="{{ $d->id }}">{{ $d->companies->first()?->pivot?->name ?: $d->name }} · {{ ucwords(str_replace('_',' ', $d->companies->first()?->pivot?->role ?? 'staff')) }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="nc-btn nc-open-chat-btn">Open</button>
                </form>
                <div class="nc-direct-note">Choose a colleague to start a conversation or continue where you left off.</div>
            @endif
        </div>
        @include('chat.conversations')
        @if($directory->hasPages())<div class="p-3">{{ $directory->links() }}</div>@endif
    </aside>

    <section id="chat-panel" class="nc-card nc-chat-main {{ $selected ? '' : 'mobile-hidden' }}">
        <div class="nc-chat-header">
            <div class="flex items-center gap-3 min-w-0">
                <button type="button" id="mobile-back" class="nc-mobile-back-btn" aria-label="Back to conversations">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18 9 12l6-6"/></svg>
                </button>
                <div class="min-w-0 flex-1">
                    @if($selected)
                        @php($selectedPresentation = App\Services\ConversationPresentation::make($selected, auth()->user()))
                        <div class="nc-chat-person-title">{{ $selectedPresentation['title'] }}</div>
                        @if($selectedPresentation['subtitle'])<div class="nc-conversation-subtitle">{{ $selectedPresentation['subtitle'] }}</div>@endif
                        @if($selectedPresentation['other'])
                            <div class="nc-chat-presence" data-selected-presence-user="{{ $selectedPresentation['other']->id }}"><span class="nc-presence-dot"></span><span data-presence-label>Offline</span></div>
                        @endif
                    @else
                        <div class="nc-chat-person-title">Your workplace conversations</div>
                        <div class="nc-conversation-subtitle">Choose a conversation to get started.</div>
                    @endif
                </div>
            </div>
            @if($selected)
                <button type="button" id="close-chat" class="nc-chat-close-btn" aria-label="Close chat" title="Close chat">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    <span>Close</span>
                </button>
            @endif
        </div>

        @if($selected && $hasOlderMessages)<button id="load-history" type="button" class="nc-btn-secondary mx-auto my-2">Load earlier messages</button>@endif
        <div id="messages" class="nc-chat-messages" aria-live="polite">
            @if($selected)
                @forelse($selectedMessages as $m)
                    @php($isDeleted = (bool) $m->deleted_at)
                    @php($canEdit = !$isDeleted && $m->user_id === auth()->id() && $m->created_at->copy()->addMinutes(config('chat.message_edit_window_minutes', 10))->isFuture() && $m->body)
                    @php($canDelete = !$isDeleted && $m->user_id === auth()->id() && $m->created_at->copy()->addMinutes(config('chat.message_delete_window_minutes', 5))->isFuture())
                    <div class="nc-message-row {{ $m->user_id === auth()->id() ? 'mine' : '' }} {{ $isDeleted ? 'is-deleted' : '' }}" data-message-id="{{ $m->id }}" data-edited-at="{{ $m->edited_at?->toIso8601String() }}" data-revision="{{ App\Services\MessagePayload::make($m)['revision'] }}" data-created-at="{{ $m->created_at->toIso8601String() }}" data-user-id="{{ $m->user_id }}" data-editable="{{ $canEdit ? '1' : '0' }}" data-deletable="{{ $canDelete ? '1' : '0' }}">
                        <div class="nc-bubble">
                            <div class="nc-message-head">
                                @php($senderRole = $m->user?->companies->first()?->pivot?->role)
                                <div class="nc-message-meta">{{ $m->user?->name ?? 'Deleted account' }}@if($senderRole) · {{ ucwords(str_replace('_',' ',$senderRole)) }}@endif · {{ $m->created_at->format('g:i A') }}@if($m->edited_at) · <span class="nc-edited-label">Edited</span>@endif</div>
                                @if(!$isDeleted && $canWrite)
                                    <div class="nc-message-menu-wrap">
                                        <button type="button" class="nc-message-more" aria-label="Message actions" aria-expanded="false" title="Message actions">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg>
                                        </button>
                                    </div>
                                @endif
                            </div>
                            @if($isDeleted)
                                <div class="nc-deleted-message"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 9v4"/><path d="M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg><span>This message was unsent</span></div>
                            @else
                                @if($m->replyTo)
                                    <button type="button" class="nc-reply-preview" data-jump-message="{{ $m->replyTo->id }}">
                                        <strong>Replying to {{ $m->replyTo->user?->name ?? 'Deleted account' }}</strong>
                                        <span>{{ Illuminate\Support\Str::limit($m->replyTo->body ?: 'Attachment', 100) }}</span>
                                    </button>
                                @endif
                                @if($m->forwardedFrom)
                                    <div class="nc-forwarded-label"><span>Forwarded</span> from {{ $m->forwardedFrom->user?->name ?? 'Deleted account' }}</div>
                                @endif
                                @if($m->body)<div class="nc-message-body whitespace-pre-wrap break-words">{{ $m->body }}</div>@endif
                                @if($m->attachments->count())
                                    <div class="nc-attachments">
                                        @foreach($m->attachments as $attachment)
                                            @php($previewable = in_array($attachment->mime_type, config('chat.attachments.preview_mimes', []), true))
                                            <div class="nc-attachment" data-attachment-id="{{ $attachment->id }}">
                                                @if(str_starts_with($attachment->mime_type, 'image/'))
                                                    <div class="nc-media-attachment">
                                                        <a href="{{ route('attachments.preview', $attachment) }}" class="nc-image-attachment" data-attachment-preview-link><img src="{{ route('attachments.preview', $attachment) }}" alt="{{ $attachment->original_name }}" loading="eager" decoding="async" data-attachment-preview-image><span class="nc-attachment-fallback hidden">Image unavailable</span></a>
                                                        <a href="{{ route('attachments.download', $attachment) }}" download="{{ $attachment->original_name }}" class="nc-attachment-download" aria-label="Download {{ $attachment->original_name }}" title="Download attachment"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v11"/><path d="m7 10 5 5 5-5"/><path d="M5 20h14"/></svg></a>
                                                    </div>
                                                @elseif(str_starts_with($attachment->mime_type, 'video/'))
                                                    <div class="nc-media-attachment">
                                                        <video class="nc-video-attachment" controls preload="metadata"><source src="{{ route('attachments.preview', $attachment) }}" type="{{ $attachment->mime_type }}"></video>
                                                        <a href="{{ route('attachments.download', $attachment) }}" download="{{ $attachment->original_name }}" class="nc-attachment-download" aria-label="Download {{ $attachment->original_name }}" title="Download attachment"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v11"/><path d="m7 10 5 5 5-5"/><path d="M5 20h14"/></svg></a>
                                                    </div>
                                                @else
                                                    <div class="nc-file-attachment">
                                                        <a href="{{ $previewable ? route('attachments.preview', $attachment) : route('attachments.download', $attachment) }}" target="_blank" rel="noopener" class="nc-file-open"><span class="nc-file-icon">{{ $attachment->mime_type === 'application/pdf' ? 'PDF' : (str_contains($attachment->mime_type, 'word') ? 'DOC' : (str_contains($attachment->mime_type, 'spreadsheet') ? 'XLS' : (str_contains($attachment->mime_type, 'presentation') ? 'PPT' : 'FILE'))) }}</span><span class="min-w-0"><strong class="block truncate">{{ $attachment->original_name }}</strong><small>{{ number_format($attachment->size / 1048576, 2) }} MB · {{ $previewable ? 'Open' : 'Download' }}</small></span></a>
                                                        <a href="{{ route('attachments.download', $attachment) }}" download="{{ $attachment->original_name }}" class="nc-attachment-download" aria-label="Download {{ $attachment->original_name }}" title="Download attachment"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v11"/><path d="m7 10 5 5 5-5"/><path d="M5 20h14"/></svg></a>
                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            @endif
                        </div>
                    </div>
                @empty
                    <div id="empty-chat" class="nc-empty-chat"><div class="nc-empty-chat-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11.5a7.5 7.5 0 0 1-8 7.5 8 8 0 0 1-4.1-1.1L4 19l1.1-3.3A7.5 7.5 0 1 1 20 11.5Z"/></svg></div><strong>No messages yet</strong><span>Start a private workplace conversation.</span></div>
                @endforelse
            @else
                <div class="nc-empty-chat"><strong>Create or select a conversation</strong><span>Choose an employee from the conversation list.</span></div>
            @endif
        </div>

        @if($selected)
            <div id="chat-error" class="nc-alert nc-chat-error hidden" role="alert"></div>
            @if(!$canWrite)
                <div class="nc-platform-read-only-note"><strong>Read-only administration access</strong><span>You can review this company conversation, but cannot send, edit, delete or forward messages.</span></div>
            @else
            <div id="reply-bar" class="nc-reply-bar hidden">
                <div class="nc-reply-bar-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 17 4 12l5-5"/><path d="M5 12h9a6 6 0 0 1 6 6"/></svg></div>
                <div class="min-w-0 flex-1"><strong>Replying to <span id="reply-user"></span></strong><div id="reply-preview" class="truncate"></div></div>
                <button type="button" id="cancel-reply" class="nc-link-btn">Cancel</button>
            </div>
            <div id="attachment-selection" class="nc-attachment-selection hidden" aria-live="polite"></div>
            <form id="composer" class="nc-composer" enctype="multipart/form-data">
                <div class="nc-attachment-menu-wrap">
                    <button type="button" id="attachment-menu-button" class="nc-attach-button" aria-label="Add attachment" aria-expanded="false" title="Add attachment">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m20.5 11.5-8.6 8.6a5 5 0 0 1-7.1-7.1l9.1-9.1a3.5 3.5 0 1 1 5 5l-9.2 9.2a2 2 0 1 1-2.8-2.8l8.5-8.5"/></svg>
                    </button>
                    <div id="attachment-menu" class="nc-attachment-menu hidden" role="menu" aria-label="Add an attachment">
                        <div class="nc-attachment-menu-title">Add to message</div>
                        <button type="button" id="add-photos-files" role="menuitem"><span class="nc-attach-option-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 5h14v14H5Z"/><circle cx="9" cy="9" r="1.5"/><path d="m6 17 4-4 3 3 2-2 3 3"/></svg></span><span><strong>Add photos or files</strong><small>Images, videos, PDF, Word, Excel and more</small></span></button>
                    </div>
                    <input id="attachment-input" class="hidden" type="file" accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv" multiple>
                </div>
                <textarea id="message" class="nc-input nc-message-input" rows="1" autocomplete="off" maxlength="10000" aria-label="Message"></textarea>
                <button id="send-button" type="submit" aria-label="Send message" class="nc-send-btn"><span>Send</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 4 16 8-16 8 3-8Z"/><path d="M7 12h13"/></svg></button>
            </form>
            <div class="nc-composer-note">Up to {{ config('chat.attachments.max_files', 5) }} files · Images {{ config('chat.attachments.max_image_mb', 10) }} MB · Videos {{ config('chat.attachments.max_video_mb', 100) }} MB · Documents {{ config('chat.attachments.max_document_mb', 25) }} MB</div>
            <div id="forward-modal" class="nc-modal hidden" role="dialog" aria-modal="true" aria-labelledby="forward-title">
                <div class="nc-modal-card">
                    <div class="nc-modal-head"><div><h2 id="forward-title" class="font-bold">Forward message</h2><p class="text-xs nc-muted mt-1">Select the workplace conversation(s) that should receive this message.</p></div><button type="button" id="close-forward" class="nc-modal-close" aria-label="Close">×</button></div>
                    <div id="forward-list" class="nc-forward-list mt-4">
                        @foreach($conversations->filter(fn($conversation)=>$writableConversationIds->contains($conversation->id)) as $target)
                            @if($target->id !== $selected->id)
                                <label class="nc-forward-option"><input type="checkbox" value="{{ $target->id }}"><span class="nc-conversation-avatar mini">{{ strtoupper(substr($target->name ?: ($target->participants->where('id','!=',auth()->id())->pluck('name')->first() ?: 'C'),0,1)) }}</span><span class="min-w-0"><strong class="block truncate">{{ $target->name ?: ($target->participants->where('id','!=',auth()->id())->pluck('name')->join(', ') ?: 'Conversation') }}</strong><small class="nc-muted">{{ ucwords(str_replace('_',' ', $target->type)) }}</small></span></label>
                            @endif
                        @endforeach
                    </div>
                    <div class="nc-modal-actions"><button type="button" id="cancel-forward" class="nc-btn-secondary">Cancel</button><button type="button" id="confirm-forward" class="nc-btn">Forward</button></div>
                    <div id="forward-error" class="text-sm text-red-700 mt-3 hidden"></div>
                </div>
            </div>
            @endif
        @endif
    </section>
</div>

@php($chatConfig = [
    'conversationId' => $selected?->id,
    'recoveryCursor' => $recoveryCursor ?? 0,
    'currentUserId' => auth()->id(),
    'currentUserName' => auth()->user()->name,
    'canWrite' => (bool) $canWrite,
    'editWindowMinutes' => (int) config('chat.message_edit_window_minutes', 10),
    'deleteWindowMinutes' => (int) config('chat.message_delete_window_minutes', 5),
    'messagesUrl' => url('/messages'),
    'sendUrl' => $selected ? route('messages.send', $selected) : null,
    'readUrl' => $selected && $selected->participants->contains('id', auth()->id()) ? route('conversations.read', $selected) : null,
    'updatesUrl' => $selected ? route('conversations.updates', $selected) : null,
    'attachments' => [
        'maxTotalMb' => (int) config('chat.attachments.max_total_mb', 120),
        'maxImageMb' => (int) config('chat.attachments.max_image_mb', 10),
        'maxVideoMb' => (int) config('chat.attachments.max_video_mb', 100),
        'maxDocumentMb' => (int) config('chat.attachments.max_document_mb', 25),
        'maxFiles' => (int) config('chat.attachments.max_files', 5),
    ],
])
<script type="application/json" data-chat-config>{!! Illuminate\Support\Js::encode($chatConfig) !!}</script>
</div>
