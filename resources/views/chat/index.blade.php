@extends('layouts.app')
@section('content')
@if(session('ok'))<div class="nc-alert nc-chat-flash" role="status">{{ session('ok') }}</div>@endif
<div class="nc-chat-layout">
    <aside id="conversation-panel" class="nc-card nc-chat-sidebar {{ $selected ? 'mobile-hidden' : '' }}">
        <div class="nc-chat-top">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="nc-chat-title">Conversations</div>
                    <div class="text-xs nc-muted mt-1 truncate">{{ auth()->user()->name }} · {{ ucwords(str_replace('_',' ', auth()->user()->role() ?? 'Platform Super Admin')) }}</div>
                </div>
                <span class="nc-secure-pill"><span class="nc-secure-dot"></span>Secure</span>
            </div>
            @if(isset($directory) && $directory->count())
                <form method="POST" action="{{ route('conversations.create') }}" class="nc-new-chat-form mt-4">
                    @csrf
                    <input type="hidden" name="type" value="direct">
                    <select name="user_id" class="nc-input text-sm" aria-label="Employee to message">
                        @foreach($directory as $d)
                            <option value="{{ $d->id }}">{{ $d->name }} · {{ $d->job_title ?: ucwords(str_replace('_',' ', $d->role())) }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="nc-btn nc-open-chat-btn">Open</button>
                </form>
                <div class="nc-direct-note">Each employee pair has one direct conversation. Opening an existing pair returns to the same thread.</div>
            @endif
        </div>
        <div class="nc-chat-list">
            @forelse($conversations as $c)
                @php($isSelected = $selected && $selected->id === $c->id)
                @php($others = $c->participants->where('id','!=',auth()->id())->values())
                @php($other = $c->type === 'direct' ? $others->first() : null)
                @php($conversationLabel = $c->name ?: ($others->map(fn($person) => $person->name . ' · ' . ($person->job_title ?: ucwords(str_replace('_',' ', $person->role()))))->join(', ') ?: 'Conversation'))
                <a href="{{ route('chat',['conversation'=>$c->id]) }}" class="nc-conversation {{ $isSelected ? 'active' : '' }}" data-conversation-link>
                    <div class="flex items-center gap-3">
                        <span class="nc-conversation-avatar">{{ strtoupper(substr($conversationLabel,0,1)) }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="nc-conversation-name">{{ $conversationLabel }}</div>
                            @if($other)
                                <div class="text-xs nc-muted mt-1 flex items-center gap-1" data-presence-user="{{ $other->id }}"><span class="nc-presence-dot"></span><span data-presence-label>Offline</span></div>
                            @else
                                <div class="text-xs nc-muted mt-1">{{ ucwords(str_replace('_',' ',$c->type)) }}</div>
                            @endif
                        </div>
                    </div>
                </a>
            @empty
                <div class="p-6 text-sm nc-muted">No conversations yet. Open an employee conversation to begin.</div>
            @endforelse
        </div>
    </aside>

    <section id="chat-panel" class="nc-card nc-chat-main {{ $selected ? '' : 'mobile-hidden' }}">
        <div class="nc-chat-header">
            <div class="flex items-center gap-3 min-w-0">
                <button type="button" id="mobile-back" class="nc-mobile-back-btn" aria-label="Back to conversations">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18 9 12l6-6"/></svg>
                </button>
                <div class="min-w-0 flex-1">
                    <div class="font-bold truncate">{{ $selected?->name ?: ($selected ? $selected->participants->where('id','!=',auth()->id())->map(fn($person) => $person->name . ' · ' . ($person->job_title ?: ucwords(str_replace('_',' ', $person->role()))))->join(', ') : 'Select a conversation') }}</div>
                    @if($selected && $selected->type === 'direct' && $selected->participants->where('id','!=',auth()->id())->first())
                        @php($headerOther = $selected->participants->where('id','!=',auth()->id())->first())
                        <div class="text-xs nc-muted mt-1 flex items-center gap-1" data-selected-presence-user="{{ $headerOther->id }}"><span class="nc-presence-dot"></span><span data-presence-label>Offline</span></div>
                    @else
                        <div class="text-xs nc-muted mt-1">Private workplace channel · real-time messaging</div>
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

        <div id="messages" class="nc-chat-messages" aria-live="polite">
             @if($selected && $selectedMessages->count() >= 100)
                 <div class="nc-load-older-wrap"><button type="button" id="load-older-messages" class="nc-load-older">Load older messages</button></div>
             @endif
            @if($selected)
                @forelse($selectedMessages as $m)
                    @php($isDeleted = (bool) $m->deleted_at)
                    @php($canEdit = !$isDeleted && $m->user_id === auth()->id() && $m->created_at->copy()->addMinutes(config('chat.message_edit_window_minutes', 10))->isFuture() && $m->body)
                    @php($canDelete = !$isDeleted && $m->user_id === auth()->id() && $m->created_at->copy()->addMinutes(config('chat.message_delete_window_minutes', 5))->isFuture())
                    <div class="nc-message-row {{ $m->user_id === auth()->id() ? 'mine' : '' }} {{ $isDeleted ? 'is-deleted' : '' }}" data-message-id="{{ $m->id }}" data-created-at="{{ $m->created_at->toIso8601String() }}" data-user-id="{{ $m->user_id }}" data-editable="{{ $canEdit ? '1' : '0' }}" data-deletable="{{ $canDelete ? '1' : '0' }}">
                        <div class="nc-bubble">
                            <div class="nc-message-head">
                                <div class="nc-message-meta">{{ $m->user->name }} · {{ $m->created_at->format('g:i A') }}@if($m->edited_at) · <span class="nc-edited-label">Edited</span>@endif</div>
                                @if(!$isDeleted)
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
                                        <strong>Replying to {{ $m->replyTo->user->name }}</strong>
                                        <span>{{ Illuminate\Support\Str::limit($m->replyTo->body ?: 'Attachment', 100) }}</span>
                                    </button>
                                @endif
                                @if($m->forwardedFrom)
                                    <div class="nc-forwarded-label"><span>Forwarded</span> from {{ $m->forwardedFrom->user->name }}</div>
                                @endif
                                @if($m->body)<div class="nc-message-body whitespace-pre-wrap break-words">{{ $m->body }}</div>@endif
                                @if($m->attachments->count())
                                    <div class="nc-attachments">
                                        @foreach($m->attachments as $attachment)
                                            @php($previewable = in_array($attachment->mime_type, ['image/jpeg','image/png','image/webp','image/gif','video/mp4','video/webm','video/quicktime','application/pdf'], true))
                                            <div class="nc-attachment" data-attachment-id="{{ $attachment->id }}">
                                                @if(str_starts_with($attachment->mime_type, 'image/'))
                                                    <div class="nc-media-attachment nc-image-media">
                                                        <a href="{{ route('attachments.preview', $attachment) }}" target="_blank" rel="noopener" class="nc-image-attachment" data-attachment-preview-link><img src="{{ route('attachments.preview', $attachment) }}" alt="{{ $attachment->original_name }}" loading="lazy" decoding="async" data-attachment-preview-image draggable="false"><span class="nc-attachment-fallback hidden">Image unavailable · tap to retry</span></a>
                                                        <div class="nc-image-caption"><span class="nc-image-name">{{ $attachment->original_name }}</span><a href="{{ route('attachments.download', $attachment) }}" download="{{ $attachment->original_name }}" class="nc-attachment-download" aria-label="Download {{ $attachment->original_name }}" title="Download attachment"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v11"/><path d="m7 10 5 5 5-5"/><path d="M5 20h14"/></svg></a></div>
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
                <input id="message" class="nc-input" placeholder="Write a workplace message…" autocomplete="off" maxlength="10000" aria-label="Message">
                <button id="send-button" type="submit" class="nc-send-btn"><span>Send</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 4 16 8-16 8 3-8Z"/><path d="M7 12h13"/></svg></button>
            </form>
            <div class="nc-composer-note">Up to {{ config('chat.attachments.max_files', 5) }} files · Images {{ config('chat.attachments.max_image_mb', 10) }} MB · Videos {{ config('chat.attachments.max_video_mb', 100) }} MB · Documents {{ config('chat.attachments.max_document_mb', 25) }} MB</div>
            <div id="forward-modal" class="nc-modal hidden" role="dialog" aria-modal="true" aria-labelledby="forward-title">
                <div class="nc-modal-card">
                    <div class="nc-modal-head"><div><h2 id="forward-title" class="font-bold">Forward message</h2><p class="text-xs nc-muted mt-1">Select the workplace conversation(s) that should receive this message.</p></div><button type="button" id="close-forward" class="nc-modal-close" aria-label="Close">×</button></div>
                    <div id="forward-list" class="nc-forward-list mt-4">
                        @foreach($conversations as $target)
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
    </section>
</div>

<script>
// Fast in-place conversation navigation. The authenticated shell stays in
// place; only the chat workspace is replaced.
document.addEventListener('DOMContentLoaded', () => {
    // Keep only in-flight conversation requests. A persistent HTML cache can
    // make a mobile chat reopen with an older message list after another device
    // has already sent new messages.
    const cache = window.__pharmaChatPrefetch ||= new Map();
    const load = (url) => {
        if (!url) return Promise.reject(new Error('Missing conversation URL.'));
        if (!cache.has(url)) {
            const request = fetch(url, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
                cache: 'no-store'
            }).then(async response => {
                if (!response.ok) throw new Error(`Conversation request failed (${response.status}).`);
                return response.text();
            }).finally(() => {
                cache.delete(url);
            });
            cache.set(url, request);
        }
        return cache.get(url);
    };
    const prefetch = url => { load(url).catch(() => {}); };

    const navigate = async (url, pushHistory = true) => {
        document.body.classList.add('nc-navigation-loading');
        try {
            const html = await load(url);
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const nextLayout = doc.querySelector('.nc-chat-layout');
            const currentLayout = document.querySelector('.nc-chat-layout');
            if (!nextLayout || !currentLayout) throw new Error('Chat layout was not returned.');

            window.PharmaStrategiesCleanupChat?.();
            currentLayout.replaceWith(nextLayout);
            if (pushHistory) {
                history.pushState({ pharmaChat: true, conversation: new URL(url).searchParams.get('conversation') }, '', url);
            }
            document.title = doc.title || document.title;

            const chatScript = [...doc.scripts].find(script => script.textContent.includes('const conversationId ='));
            if (chatScript?.textContent) Function(chatScript.textContent)();
        } catch (error) {
            window.location.href = url;
            return;
        } finally {
            document.body.classList.remove('nc-navigation-loading');
        }
    };

    window.PharmaStrategiesNavigateChat = navigate;

    document.addEventListener('pointerdown', event => {
        const link = event.target.closest?.('[data-conversation-link]');
        if (link) prefetch(link.href);
    }, {passive:true});
    document.addEventListener('touchstart', event => {
        const link = event.target.closest?.('[data-conversation-link]');
        if (link) prefetch(link.href);
    }, {passive:true});
    document.addEventListener('click', event => {
        const link = event.target.closest?.('[data-conversation-link]');
        if (!link || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
        event.preventDefault();
        navigate(link.href, true);
    });
    window.addEventListener('popstate', () => navigate(window.location.href, false));
});
</script>

@if($selected)
<script>
(() => {
    const conversationId = {{ $selected->id }};
    const currentUserId = {{ auth()->id() }};
    const editWindowMinutes = {{ (int) config('chat.message_edit_window_minutes', 10) }};
    const deleteWindowMinutes = {{ (int) config('chat.message_delete_window_minutes', 5) }};
    const box = document.getElementById('messages');
    if (box) box.style.overflowAnchor = 'none';
    const composer = document.getElementById('composer');
    const input = document.getElementById('message');
    const sendButton = document.getElementById('send-button');
    const errorBox = document.getElementById('chat-error');
    const selection = document.getElementById('attachment-selection');
    const attachmentMenu = document.getElementById('attachment-menu');
    const attachmentMenuButton = document.getElementById('attachment-menu-button');
    const attachmentInput = document.getElementById('attachment-input');
    const selectedFiles = [];
    const chatAbort = new AbortController();
    const on = (target, type, handler, options = {}) => target?.addEventListener(type, handler, {...options, signal: chatAbort.signal});
    let replyToMessage = null;
    let activeSends = 0;
    let forwardMessageId = null;
    const replyBar = document.getElementById('reply-bar');
    const replyUser = document.getElementById('reply-user');
    const replyPreview = document.getElementById('reply-preview');
    const forwardModal = document.getElementById('forward-modal');
    const forwardError = document.getElementById('forward-error');
    const seen = new Set([...box.querySelectorAll('[data-message-id]')].map(el => String(el.dataset.messageId)));
    const icon = (path) => `<svg viewBox="0 0 24 24" aria-hidden="true">${path}</svg>`;

    const showError = (message) => { if (!errorBox) return; errorBox.textContent = message || 'Something went wrong. Please try again.'; errorBox.classList.remove('hidden'); };
    const clearError = () => errorBox?.classList.add('hidden');
    let initialScrollSettled = false;
    const getLastMessageRow = () => {
        const rows = box?.querySelectorAll('[data-message-id]');
        return rows?.length ? rows[rows.length - 1] : null;
    };
    const scrollToLatestPosition = () => {
        if (!box) return;
        const last = getLastMessageRow();
        if (!last) {
            box.scrollTop = box.scrollHeight;
            return;
        }

        // scrollTop = scrollHeight is normally sufficient, but iOS Safari can
        // restore/anchor an overflow container while a newly opened page is
        // still laying out. Align the actual last message's bottom with the
        // visible bottom as a second, geometry-based correction.
        const boxRect = box.getBoundingClientRect();
        const lastRect = last.getBoundingClientRect();
        const delta = lastRect.bottom - boxRect.bottom;
        if (Math.abs(delta) > 0.5) box.scrollTop += delta;
        box.scrollTop = Math.max(0, box.scrollTop);
    };
    const forceScrollBottom = (smooth = false) => {
        if (!box) return;
        const previousBehavior = box.style.scrollBehavior;
        box.style.scrollBehavior = smooth ? 'smooth' : 'auto';
        box.scrollTop = box.scrollHeight;
        scrollToLatestPosition();
        requestAnimationFrame(() => {
            box.scrollTop = box.scrollHeight;
            scrollToLatestPosition();
            requestAnimationFrame(() => {
                box.scrollTop = box.scrollHeight;
                scrollToLatestPosition();
                box.style.scrollBehavior = previousBehavior;
            });
        });
    };
    const settleInitialScroll = () => {
        // Keep correcting during the initial mobile layout/toolbar/media
        // settling window. This is intentionally bounded so it never creates
        // a permanent animation loop.
        forceScrollBottom(false);
        let frames = 0;
        const settleFrame = () => {
            if (!initialScrollSettled || document.hidden || !box) return;
            scrollToLatestPosition();
            frames += 1;
            if (frames < 90) requestAnimationFrame(settleFrame);
        };
        requestAnimationFrame(settleFrame);
        [150, 400, 800, 1200, 1800].forEach(delay => setTimeout(() => {
            if (!initialScrollSettled || document.hidden) return;
            forceScrollBottom(false);
        }, delay));
    };
    const scrollBottom = () => forceScrollBottom(false);
    const messageKey = m => [new Date(m.created_at || 0).getTime() || 0, Number(m.id || 0)];
    const compareMessages = (a,b) => { const [at,ai]=messageKey(a), [bt,bi]=messageKey(b); return at-bt || ai-bi; };
    const formatBytes = bytes => { if (!bytes) return '0 B'; const units=['B','KB','MB','GB']; const i=Math.min(Math.floor(Math.log(bytes)/Math.log(1024)),units.length-1); return `${(bytes/Math.pow(1024,i)).toFixed(i?1:0)} ${units[i]}`; };
    const formatTime = value => new Date(value).toLocaleTimeString([], {hour:'numeric',minute:'2-digit'});

    let activeMessageMenuRow = null;
    let activeMessageMenuButton = null;
    const globalMessageMenu = document.createElement('div');
    globalMessageMenu.id = 'global-message-menu';
    globalMessageMenu.className = 'nc-message-menu nc-message-menu-global hidden';
    globalMessageMenu.setAttribute('role', 'menu');
    globalMessageMenu.setAttribute('aria-label', 'Message actions');
    document.body.appendChild(globalMessageMenu);

    const closeAllMessageMenus = () => {
        globalMessageMenu.classList.add('hidden');
        activeMessageMenuButton?.setAttribute('aria-expanded', 'false');
        activeMessageMenuRow = null;
        activeMessageMenuButton = null;
    };

    const messageActionIcon = {
        reply: '<path d="M9 17 4 12l5-5"/><path d="M5 12h9a6 6 0 0 1 6 6"/>',
        forward: '<path d="m13 5 7 7-7 7"/><path d="M20 12H7a4 4 0 0 0-4 4v1"/>',
        edit: '<path d="M12 20h9"/><path d="m16.5 3.5 4 4L8 20H4v-4Z"/>',
        delete: '<path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 14h10l1-14M9 7V4h6v3"/>',
    };

    const openMessageMenu = (row, button) => {
        if (!row || !button || row.classList.contains('is-deleted')) return;
        if (activeMessageMenuRow === row && !globalMessageMenu.classList.contains('hidden')) {
            closeAllMessageMenus();
            return;
        }
        closeAllMessageMenus();
        activeMessageMenuRow = row;
        activeMessageMenuButton = button;
        button.setAttribute('aria-expanded', 'true');
        globalMessageMenu.innerHTML = '';
        const addAction = (label, action, danger = false) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = danger ? 'danger' : '';
            item.dataset.messageAction = action;
            item.setAttribute('role', 'menuitem');
            item.innerHTML = `${icon(messageActionIcon[action])}<span>${label}</span>`;
            globalMessageMenu.appendChild(item);
        };
        addAction('Reply', 'reply');
        addAction('Forward', 'forward');
        if (row.dataset.editable === '1') addAction('Edit', 'edit');
        if (row.dataset.deletable === '1') addAction('Unsend', 'delete', true);
        globalMessageMenu.classList.remove('hidden');
        globalMessageMenu.style.visibility = 'hidden';
        globalMessageMenu.style.display = 'block';
        globalMessageMenu.style.maxHeight = 'calc(100vh - 16px)';

        const rect = button.getBoundingClientRect();
        const gap = 6;
        const viewportPadding = 8;
        const viewportWidth = document.documentElement.clientWidth || window.innerWidth;
        const viewportHeight = document.documentElement.clientHeight || window.innerHeight;
        let menuRect = globalMessageMenu.getBoundingClientRect();
        const availableBelow = Math.max(0, viewportHeight - rect.bottom - viewportPadding - gap);
        const availableAbove = Math.max(0, rect.top - viewportPadding - gap);
        const preferredBelow = availableBelow >= menuRect.height || availableBelow >= availableAbove;
        const availableSpace = preferredBelow ? availableBelow : availableAbove;

        if (menuRect.height > availableSpace && availableSpace > 40) {
            globalMessageMenu.style.maxHeight = `${Math.floor(availableSpace)}px`;
            menuRect = globalMessageMenu.getBoundingClientRect();
        }

        let left = rect.right - menuRect.width;
        const maxLeft = Math.max(viewportPadding, viewportWidth - menuRect.width - viewportPadding);
        left = Math.max(viewportPadding, Math.min(left, maxLeft));

        let top = preferredBelow
            ? rect.bottom + gap
            : rect.top - menuRect.height - gap;
        const maxTop = Math.max(viewportPadding, viewportHeight - menuRect.height - viewportPadding);
        top = Math.max(viewportPadding, Math.min(top, maxTop));

        globalMessageMenu.style.left = `${Math.round(left)}px`;
        globalMessageMenu.style.top = `${Math.round(top)}px`;
        globalMessageMenu.style.visibility = 'visible';
    };

    on(box, 'click', event => {
        const more = event.target.closest('.nc-message-more');
        if (more) {
            event.preventDefault();
            event.stopPropagation();
            openMessageMenu(more.closest('[data-message-id]'), more);
            return;
        }
        const jump = event.target.closest('[data-jump-message]');
        if (jump) {
            const target = box.querySelector(`[data-message-id="${jump.dataset.jumpMessage}"]`);
            target?.scrollIntoView({behavior:'smooth',block:'center'});
            target?.classList.add('nc-message-highlight');
            setTimeout(() => target?.classList.remove('nc-message-highlight'), 1200);
        }
    });

    on(document, 'click', event => {
        if (!event.target.closest('#global-message-menu') && !event.target.closest('.nc-message-more')) closeAllMessageMenus();
        if (!event.target.closest('.nc-attachment-menu-wrap')) { attachmentMenu?.classList.add('hidden'); attachmentMenuButton?.setAttribute('aria-expanded','false'); }
    });
    on(document, 'keydown', event => { if (event.key === 'Escape') closeAllMessageMenus(); });
    on(box, 'scroll', closeAllMessageMenus, {passive:true});
    on(window, 'resize', closeAllMessageMenus);

    const downloadIcon = () => `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v11"/><path d="m7 10 5 5 5-5"/><path d="M5 20h14"/></svg>`;
    const attachmentDownloadButton = (downloadUrl, name) => {
        const button=document.createElement('a'); button.href=downloadUrl||'#'; button.className='nc-attachment-download';
        button.setAttribute('download', name || 'attachment'); button.setAttribute('aria-label', `Download ${name || 'attachment'}`); button.title='Download attachment'; button.innerHTML=downloadIcon(); return button;
    };
    const attachmentMarkup = attachment => {
        const wrapper=document.createElement('div'); wrapper.className='nc-attachment';
        const mime=attachment.mime_type||'', previewUrl=attachment.preview_url, downloadUrl=attachment.download_url;
        const name=attachment.name||attachment.original_name||'Attachment';
        if (mime.startsWith('image/') && previewUrl) {
            const mediaWrap=document.createElement('div'); mediaWrap.className='nc-media-attachment nc-image-media';
            const link=document.createElement('a'); link.href=previewUrl; link.target='_blank'; link.rel='noopener'; link.className='nc-image-attachment';
            const img=document.createElement('img'); img.src=previewUrl; img.alt=name; img.loading='lazy'; img.decoding='async'; img.draggable=false;
            let retried=false;
            img.addEventListener('error',()=>{
                if(!retried){
                    retried=true;
                    const separator=previewUrl.includes('?')?'&':'?';
                    img.src=`${previewUrl}${separator}retry=${Date.now()}`;
                    return;
                }
                img.classList.add('hidden');
                const fallback=document.createElement('span');
                fallback.className='nc-attachment-fallback';
                fallback.textContent='Image unavailable · tap to retry';
                link.appendChild(fallback);
            });
            link.appendChild(img);
            const caption=document.createElement('div'); caption.className='nc-image-caption';
            const captionName=document.createElement('span'); captionName.className='nc-image-name'; captionName.textContent=name;
            caption.appendChild(captionName);
            if(downloadUrl){ caption.appendChild(attachmentDownloadButton(downloadUrl,name)); }
            mediaWrap.append(link, caption); wrapper.appendChild(mediaWrap);
        } else if (mime.startsWith('video/') && previewUrl) {
            const mediaWrap=document.createElement('div'); mediaWrap.className='nc-media-attachment';
            const video=document.createElement('video'); video.className='nc-video-attachment'; video.controls=true; video.preload='metadata';
            const source=document.createElement('source'); source.src=previewUrl; source.type=mime; video.appendChild(source);
            mediaWrap.append(video, attachmentDownloadButton(downloadUrl,name)); wrapper.appendChild(mediaWrap);
        } else {
            const card=document.createElement('div'); card.className='nc-file-attachment';
            const open=document.createElement('a'); open.href=previewUrl||downloadUrl; open.target='_blank'; open.rel='noopener'; open.className='nc-file-open';
            const iconEl=document.createElement('span'); iconEl.className='nc-file-icon'; iconEl.textContent=mime==='application/pdf'?'PDF':(mime.includes('word')||/\.docx?$/i.test(name)?'DOC':mime.includes('spreadsheet')||/\.(xls|xlsx|csv)$/i.test(name)?'XLS':mime.includes('presentation')||/\.(ppt|pptx)$/i.test(name)?'PPT':'FILE');
            const text=document.createElement('span'); text.className='min-w-0'; const strong=document.createElement('strong'); strong.className='block truncate'; strong.textContent=name;
            const small=document.createElement('small'); small.textContent=`${formatBytes(Number(attachment.size||0))} · ${previewUrl?'Open':'Download'}`; text.append(strong,small); open.append(iconEl,text); card.append(open,attachmentDownloadButton(downloadUrl,name)); wrapper.appendChild(card);
        }
        return wrapper;
    };
    const renderAttachments = (row, attachments=[]) => { row.querySelector('.nc-attachments')?.remove(); if (!attachments.length) return; const wrap=document.createElement('div'); wrap.className='nc-attachments'; attachments.forEach(a=>wrap.appendChild(attachmentMarkup(a))); row.querySelector('.nc-bubble')?.appendChild(wrap); };

    const buildActionMenu = () => {
        const wrap=document.createElement('div'); wrap.className='nc-message-menu-wrap';
        const more=document.createElement('button'); more.type='button'; more.className='nc-message-more'; more.setAttribute('aria-label','Message actions'); more.setAttribute('aria-expanded','false'); more.title='Message actions'; more.innerHTML=icon('<circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/>');
        wrap.append(more); return wrap;
    };

    const buildMessageRow = m => {
        const row=document.createElement('div'); const deleted=!!m.deleted_at;
        row.className='nc-message-row'+(Number(m.user_id)===currentUserId?' mine':'')+(deleted?' is-deleted':''); row.dataset.messageId=m.id; row.dataset.createdAt=m.created_at||''; row.dataset.userId=m.user_id;
        const canEdit=Number(m.user_id)===currentUserId && !!m.body && !deleted && (new Date(m.created_at).getTime()+editWindowMinutes*60000>Date.now());
        const canDelete=Number(m.user_id)===currentUserId && !deleted && (new Date(m.created_at).getTime()+deleteWindowMinutes*60000>Date.now());
        row.dataset.editable=canEdit?'1':'0'; row.dataset.deletable=canDelete?'1':'0';
        const bubble=document.createElement('div'); bubble.className='nc-bubble';
        const head=document.createElement('div'); head.className='nc-message-head'; const meta=document.createElement('div'); meta.className='nc-message-meta'; meta.textContent=`${m.user_name} · ${formatTime(m.created_at)}`;
        if(m.edited_at){meta.append(' · '); const edited=document.createElement('span'); edited.className='nc-edited-label'; edited.textContent='Edited'; meta.appendChild(edited);}
        if(String(m.id || '').startsWith('temp-')){meta.append(' · '); const sending=document.createElement('span'); sending.className='nc-message-sending'; sending.textContent='Sending'; meta.appendChild(sending);}
        head.appendChild(meta);
        if(!deleted) head.appendChild(buildActionMenu()); bubble.appendChild(head);
        if(deleted){ const d=document.createElement('div'); d.className='nc-deleted-message'; d.innerHTML=`${icon('<path d="M12 9v4"/><path d="M12 17h.01"/><circle cx="12" cy="12" r="9"/>')}<span>This message was unsent</span>`; bubble.appendChild(d); }
        else {
            if(m.reply_to){ const reply=document.createElement('button'); reply.type='button'; reply.className='nc-reply-preview'; reply.dataset.jumpMessage=m.reply_to.id; const strong=document.createElement('strong'); strong.textContent=`Replying to ${m.reply_to.user_name||'employee'}`; const span=document.createElement('span'); span.textContent=m.reply_to.body||'Attachment'; reply.append(strong,span); bubble.appendChild(reply); }
            if(m.forwarded_from){ const f=document.createElement('div'); f.className='nc-forwarded-label'; f.innerHTML=`<span>Forwarded</span> from ${m.forwarded_from.user_name||'employee'}`; bubble.appendChild(f); }
            if(m.body){ const body=document.createElement('div'); body.className='nc-message-body whitespace-pre-wrap break-words'; body.textContent=m.body; bubble.appendChild(body); }
            if(m.attachments?.length) renderAttachments(row,m.attachments);
        }
        row.appendChild(bubble); return row;
    };

    const updateRowBody=(row,body,editedAt)=>{ let bodyEl=row.querySelector('.nc-message-body'); if(!bodyEl){bodyEl=document.createElement('div');bodyEl.className='nc-message-body whitespace-pre-wrap break-words';row.querySelector('.nc-bubble')?.appendChild(bodyEl);} bodyEl.textContent=body; let edited=row.querySelector('.nc-edited-label'); if(!edited){edited=document.createElement('span');edited.className='nc-edited-label';row.querySelector('.nc-message-meta')?.append(' · ',edited);} edited.textContent='Edited';row.dataset.editedAt=editedAt||''; };
    const markDeleted=(row)=>{ row.classList.add('is-deleted'); row.querySelector('.nc-message-menu-wrap')?.remove(); row.querySelector('.nc-reply-preview')?.remove(); row.querySelector('.nc-forwarded-label')?.remove(); row.querySelector('.nc-message-body')?.remove(); row.querySelector('.nc-attachments')?.remove(); const bubble=row.querySelector('.nc-bubble'); if(!bubble.querySelector('.nc-deleted-message')){const d=document.createElement('div');d.className='nc-deleted-message';d.innerHTML=`${icon('<path d="M12 9v4"/><path d="M12 17h.01"/><circle cx="12" cy="12" r="9"/>')}<span>This message was unsent</span>`;bubble.appendChild(d);} row.dataset.editable='0';row.dataset.deletable='0'; };
    const scheduleWindow=(row,minutes,dataAttr)=>{
        if(row.dataset[dataAttr]!=='1')return;
        const remaining=new Date(row.dataset.createdAt).getTime()+minutes*60000-Date.now();
        if(remaining<=0){row.dataset[dataAttr]='0';return;}
        setTimeout(()=>{row.dataset[dataAttr]='0';},remaining);
    };
    const addMessage=m=>{if(m.id&&seen.has(String(m.id)))return;const near=box.scrollHeight-box.scrollTop-box.clientHeight<140;if(m.id)seen.add(String(m.id));document.getElementById('empty-chat')?.remove();const row=buildMessageRow(m);const rows=[...box.querySelectorAll('[data-message-id]')];const before=rows.find(existing=>compareMessages(m,{id:existing.dataset.messageId,created_at:existing.dataset.createdAt})<0);if(before)box.insertBefore(row,before);else box.appendChild(row);scheduleWindow(row,editWindowMinutes,'editable');scheduleWindow(row,deleteWindowMinutes,'deletable');if(near||Number(m.user_id)===currentUserId)forceScrollBottom(true);};
    const loadOlderButton=document.getElementById('load-older-messages');
    let loadingOlder=false;
    const prependOlderMessages=messages=>{
        const previousHeight=box.scrollHeight;
        const previousTop=box.scrollTop;
        messages.forEach(m=>{
            if(m.id&&seen.has(String(m.id))) return;
            if(m.id) seen.add(String(m.id));
            const row=buildMessageRow(m);
            const first=box.querySelector('[data-message-id]');
            if(first) box.insertBefore(row,first); else box.appendChild(row);
            scheduleWindow(row,editWindowMinutes,'editable');
            scheduleWindow(row,deleteWindowMinutes,'deletable');
        });
        box.scrollTop=box.scrollHeight-previousHeight+previousTop;
    };
    on(loadOlderButton, 'click', async()=>{
        if(loadingOlder) return;
        const first=box.querySelector('[data-message-id]');
        if(!first) return;
        loadingOlder=true;
        loadOlderButton.disabled=true;
        loadOlderButton.textContent='Loading…';
        try{
            const response=await fetch(`{{ route('messages.history',$selected) }}?before=${encodeURIComponent(first.dataset.messageId)}`,{credentials:'same-origin',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
            const payload=await response.json().catch(()=>({}));
            if(!response.ok) throw new Error(payload.message||'Older messages could not be loaded.');
            prependOlderMessages(payload.messages||[]);
            if(!payload.has_more){
                loadOlderButton.remove();
            }else{
                loadOlderButton.disabled=false;
                loadOlderButton.textContent='Load older messages';
            }
        }catch(error){
            loadOlderButton.disabled=false;
            loadOlderButton.textContent='Load older messages';
            showError(error.message||'Older messages could not be loaded.');
        }finally{loadingOlder=false;}
    });

    const openEdit=async row=>{if(row.dataset.userId!=currentUserId||row.dataset.editable!=='1')return;const bodyEl=row.querySelector('.nc-message-body');if(!bodyEl||row.querySelector('.nc-edit-form'))return;const original=bodyEl.textContent;const form=document.createElement('form');form.className='nc-edit-form';const textarea=document.createElement('textarea');textarea.className='nc-input';textarea.maxLength=10000;textarea.value=original;textarea.rows=3;const actions=document.createElement('div');actions.className='nc-edit-actions';const cancel=document.createElement('button');cancel.type='button';cancel.className='nc-btn-secondary';cancel.textContent='Cancel';const save=document.createElement('button');save.type='submit';save.className='nc-btn';save.textContent='Save';actions.append(cancel,save);form.append(textarea,actions);bodyEl.replaceWith(form);textarea.focus();cancel.addEventListener('click',()=>form.replaceWith(bodyEl));form.addEventListener('submit',async e=>{e.preventDefault();const value=textarea.value.trim();if(!value)return;save.disabled=cancel.disabled=true;try{const response=await fetch(`{{ url('/messages') }}/${row.dataset.messageId}`,{method:'PATCH',credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({body:value})});const payload=await response.json().catch(()=>({}));if(!response.ok)throw new Error(payload.message||payload.errors?.body?.[0]||'The message could not be edited.');updateRowBody(row,payload.message.body,payload.message.edited_at);form.remove();row.dataset.editable='0';}catch(error){showError(error.message);save.disabled=cancel.disabled=false;}});};
    const setReply=row=>{const body=row.querySelector('.nc-message-body')?.textContent||(row.querySelector('.nc-attachments')?'Attachment':'');replyToMessage={id:Number(row.dataset.messageId),user_name:row.querySelector('.nc-message-meta')?.textContent?.split(' · ')[0]||'Employee',body};replyUser.textContent=replyToMessage.user_name;replyPreview.textContent=replyToMessage.body||'Attachment';replyBar?.classList.remove('hidden');input?.focus();};
    const clearReply=()=>{replyToMessage=null;replyBar?.classList.add('hidden');};
    on(document.getElementById('cancel-reply'), 'click', clearReply);
    const openForward=row=>{forwardMessageId=Number(row.dataset.messageId);forwardError?.classList.add('hidden');forwardModal?.classList.remove('hidden');};
    const closeForward=()=>{forwardMessageId=null;forwardModal?.classList.add('hidden');document.querySelectorAll('#forward-list input[type=checkbox]').forEach(cb=>cb.checked=false);};
    on(document.getElementById('close-forward'), 'click', closeForward); on(document.getElementById('cancel-forward'), 'click', closeForward); on(forwardModal, 'click', e=>{if(e.target===forwardModal)closeForward();});
    document.getElementById('confirm-forward')?.addEventListener('click',async()=>{if(!forwardMessageId)return;const ids=[...document.querySelectorAll('#forward-list input[type=checkbox]:checked')].map(cb=>Number(cb.value));if(!ids.length){forwardError.textContent='Select at least one conversation.';forwardError.classList.remove('hidden');return;}const button=document.getElementById('confirm-forward');button.disabled=true;try{const response=await fetch(`{{ url('/messages') }}/${forwardMessageId}/forward`,{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({conversation_ids:ids})});const payload=await response.json().catch(()=>({}));if(!response.ok)throw new Error(payload.message||Object.values(payload.errors||{}).flat()[0]||'The message could not be forwarded.');closeForward();}catch(error){forwardError.textContent=error.message;forwardError.classList.remove('hidden');}finally{button.disabled=false;}});

    const unsend=async row=>{ if(row.dataset.userId!=currentUserId||row.dataset.deletable!=='1')return; const result=await Swal.fire({title:'Unsend message?',text:'This will remove the message and its attachments for everyone in this conversation.',icon:'warning',showCancelButton:true,confirmButtonText:'Unsend message',cancelButtonText:'Keep message',reverseButtons:true,focusCancel:true,buttonsStyling:false,customClass:{popup:'nc-swal-popup',title:'nc-swal-title',htmlContainer:'nc-swal-text',confirmButton:'nc-swal-confirm danger',cancelButton:'nc-swal-cancel'}}); if(!result.isConfirmed)return; try{const response=await fetch(`{{ url('/messages') }}/${row.dataset.messageId}`,{method:'DELETE',credentials:'same-origin',headers:{'Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content}});const payload=await response.json().catch(()=>({}));if(!response.ok)throw new Error(payload.message||payload.errors?.message?.[0]||'The message could not be unsent.');markDeleted(row);await Swal.fire({title:'Message unsent',text:'The message has been removed for everyone.',icon:'success',timer:1500,showConfirmButton:false});}catch(error){showError(error.message);} };

    on(globalMessageMenu, 'click', event => {
        const action = event.target.closest('[data-message-action]');
        if (!action || !activeMessageMenuRow) return;
        const row = activeMessageMenuRow;
        const type = action.dataset.messageAction;
        closeAllMessageMenus();
        if (type === 'reply') setReply(row);
        else if (type === 'forward') openForward(row);
        else if (type === 'edit') openEdit(row);
        else if (type === 'delete') unsend(row);
    });

    const selectionPreviewUrls=new Map();
    const fileKey=file=>`${file.name}:${file.size}:${file.lastModified}`;
    const renderSelection=()=>{
        const activeKeys=new Set(selectedFiles.map(fileKey));
        [...selectionPreviewUrls.entries()].forEach(([key,url])=>{if(!activeKeys.has(key)){URL.revokeObjectURL(url);selectionPreviewUrls.delete(key);}});
        selection.innerHTML='';
        if(!selectedFiles.length){selection.classList.add('hidden');return;}
        selection.classList.remove('hidden');
        selectedFiles.forEach((file,index)=>{
            const item=document.createElement('div'); item.className='nc-selected-file';
            if(file.type.startsWith('image/')){
                const key=fileKey(file); const url=selectionPreviewUrls.get(key)||URL.createObjectURL(file); selectionPreviewUrls.set(key,url);
                const preview=document.createElement('img'); preview.className='nc-selected-file-preview'; preview.src=url; preview.alt=file.name; preview.draggable=false; item.appendChild(preview);
            } else if(file.type.startsWith('video/')) {
                const key=fileKey(file); const url=selectionPreviewUrls.get(key)||URL.createObjectURL(file); selectionPreviewUrls.set(key,url);
                const preview=document.createElement('video'); preview.className='nc-selected-file-preview'; preview.src=url; preview.muted=true; preview.playsInline=true; preview.preload='metadata'; item.appendChild(preview);
            } else {
                const doc=document.createElement('span'); doc.className='nc-selected-file-doc'; doc.textContent=(file.name.split('.').pop()||'FILE').slice(0,4).toUpperCase(); item.appendChild(doc);
            }
            const name=document.createElement('span'); name.className='nc-selected-file-name truncate'; name.textContent=file.name; name.title=file.name;
            const remove=document.createElement('button'); remove.type='button'; remove.className='nc-selected-file-remove'; remove.textContent='×'; remove.setAttribute('aria-label',`Remove ${file.name}`);
            remove.addEventListener('click',()=>{selectedFiles.splice(index,1);renderSelection();});
            item.append(name,remove); selection.appendChild(item);
        });
    };
    const validateSelectedFilesClient=()=>{const maxTotal={{ (int)config('chat.attachments.max_total_mb',120) }}*1024*1024,imageMax={{ (int)config('chat.attachments.max_image_mb',10) }}*1024*1024,videoMax={{ (int)config('chat.attachments.max_video_mb',100) }}*1024*1024,documentMax={{ (int)config('chat.attachments.max_document_mb',25) }}*1024*1024,total=selectedFiles.reduce((s,f)=>s+f.size,0);if(total>maxTotal)return`The combined attachment size cannot exceed ${formatBytes(maxTotal)}.`;for(const file of selectedFiles){const limit=file.type.startsWith('image/')?imageMax:(file.type.startsWith('video/')?videoMax:documentMax);if(file.size>limit)return`${file.name} is too large. The maximum for this file type is ${formatBytes(limit)}.`;}return null;};
    const addFiles=fileList=>{const maxFiles={{ (int)config('chat.attachments.max_files',5) }},maxTotalBytes={{ (int)config('chat.attachments.max_total_mb',120) }}*1024*1024,existingKeys=new Set(selectedFiles.map(file=>`${file.name}:${file.size}:${file.lastModified}`));let rejected=false;[...fileList].forEach(file=>{const key=`${file.name}:${file.size}:${file.lastModified}`;if(existingKeys.has(key))return;if(selectedFiles.length>=maxFiles){rejected=true;return;}selectedFiles.push(file);existingKeys.add(key);});const total=selectedFiles.reduce((s,f)=>s+f.size,0);if(rejected)showError(`You can attach up to ${maxFiles} files per message.`);else if(total>maxTotalBytes)showError(`The combined attachment size cannot exceed ${formatBytes(maxTotalBytes)}.`);else clearError();renderSelection();};

    // Large phone photos are the most common cause of slow sends. Compress only
    // oversized images in the browser before upload; small images remain untouched.
    const optimizeImageForUpload=async file=>{
        if(!file.type.startsWith('image/')||file.size<=1.5*1024*1024||typeof createImageBitmap==='undefined') return file;
        try{
            const bitmap=await createImageBitmap(file);
            const maxSide=1800; const scale=Math.min(1,maxSide/Math.max(bitmap.width,bitmap.height));
            const canvas=document.createElement('canvas'); canvas.width=Math.max(1,Math.round(bitmap.width*scale)); canvas.height=Math.max(1,Math.round(bitmap.height*scale));
            const ctx=canvas.getContext('2d',{alpha:true}); ctx.drawImage(bitmap,0,0,canvas.width,canvas.height); bitmap.close?.();
            const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',.82));
            if(!blob||blob.size>=file.size*.92) return file;
            const baseName=file.name.replace(/\.[^.]+$/,'')||'photo';
            return new File([blob],`${baseName}.jpg`,{type:'image/jpeg',lastModified:Date.now()});
        }catch(_){ return file; }
    };
    const prepareUploadFiles=async files=>Promise.all(files.map(optimizeImageForUpload));
    on(attachmentInput, 'change', ()=>{addFiles(attachmentInput.files);attachmentInput.value='';attachmentMenu.classList.add('hidden');attachmentMenuButton.setAttribute('aria-expanded','false');});
    on(attachmentMenuButton, 'click', ()=>{const open=!attachmentMenu.classList.contains('hidden');attachmentMenu.classList.toggle('hidden',open);attachmentMenuButton.setAttribute('aria-expanded',String(!open));});
    on(document.getElementById('add-photos-files'), 'click', ()=>{attachmentInput?.click();attachmentMenu?.classList.add('hidden');attachmentMenuButton?.setAttribute('aria-expanded','false');});
    on(composer, 'dragover', e=>{e.preventDefault();composer.classList.add('dragging');}); on(composer, 'dragleave', ()=>composer.classList.remove('dragging')); on(composer, 'drop', e=>{e.preventDefault();composer.classList.remove('dragging');addFiles(e.dataTransfer.files);});
    on(input, 'keydown', e=>{if(e.key==='Enter'&&!e.shiftKey&&window.innerWidth>520){e.preventDefault();composer.requestSubmit();}});

    box.querySelectorAll('[data-message-id]').forEach(row=>{scheduleWindow(row,editWindowMinutes,'editable');scheduleWindow(row,deleteWindowMinutes,'deletable');});

    // Always land on the newest message when opening a conversation. In-place
    // mobile navigation can execute this script before the replacement layout
    // has been painted, so settle over a few short frames instead of blocking
    // the user while images load.
    initialScrollSettled = true;
    settleInitialScroll();

    window.PharmaStrategies.listenConversation(conversationId,addMessage);
    window.PharmaStrategies.listenConversationEdits(conversationId,event=>{const row=box.querySelector(`[data-message-id="${event.id}"]`);if(row&&!row.classList.contains('is-deleted'))updateRowBody(row,event.body,event.edited_at);});
    window.PharmaStrategies.listenConversationDeletes(conversationId,event=>{const row=box.querySelector(`[data-message-id="${event.id}"]`);if(row)markDeleted(row);});

    // Reverb is the primary real-time path. On mobile networks, however, a
    // WebSocket can be temporarily unavailable or suspended. In that case we
    // use a tiny ID-based fallback request instead of reloading the page. The
    // fallback is disabled immediately when Reverb reconnects.
    let realtimeConnected=false;
    let fallbackTimer=null;
    const latestMessageId=()=>Math.max(0,...[...box.querySelectorAll('[data-message-id]')].map(row=>{const id=Number(row.dataset.messageId);return Number.isFinite(id)&&id>0?id:0;}));
    const pollLatest=async(force=false)=>{
        if(realtimeConnected && !force)return;
        try{
            const response=await fetch(`{{ route('messages.latest',$selected) }}?after=${latestMessageId()}`,{credentials:'same-origin',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'},cache:'no-store'});
            if(!response.ok)return;
            const payload=await response.json();
            (payload.messages||[]).forEach(addMessage);
        }catch(_){ /* transient mobile network failure; retry on next tick */ }
    };
    const stopFallback=()=>{if(fallbackTimer){clearInterval(fallbackTimer);fallbackTimer=null;}};
    const startFallback=()=>{if(realtimeConnected||fallbackTimer)return;pollLatest();fallbackTimer=setInterval(pollLatest,1000);};
    // A lightweight safety sync catches a channel subscription that looks
    // connected but is not receiving conversation events. It only asks for
    // messages after the last known ID and pauses while the tab is hidden.
    const safetySyncTimer=setInterval(()=>{
        if(document.hidden || !conversationId) return;
        pollLatest(true);
    },2200);
    const connection=window.Echo?.connector?.pusher?.connection;
    if(connection){
        const handleState=state=>{
            const next=state?.current||state;
            realtimeConnected=next==='connected';
            if(realtimeConnected) stopFallback(); else startFallback();
        };
        connection.bind('state_change',handleState);
        handleState(connection.state);
    }else{ startFallback(); }
    setTimeout(()=>{if(!realtimeConnected)startFallback();},2500);

    const renderPresence=(userId,online,lastSeen)=>document.querySelectorAll(`[data-presence-user="${userId}"],[data-selected-presence-user="${userId}"]`).forEach(el=>{el.classList.toggle('is-online',online);const label=el.querySelector('[data-presence-label]');if(label)label.textContent=online?'Online':(lastSeen?`Last seen ${new Date(lastSeen).toLocaleString([], {dateStyle:'medium',timeStyle:'short'})}`:'Offline');});
    on(window, 'pharma:presence', event=>{const d=event.detail||{};const user=d.user;if(!user)return;renderPresence(user.id,d.type!=='leaving',user.last_seen_at||new Date().toISOString());});

    on(composer, 'submit', async event=>{
        event.preventDefault();
        clearError();
        const body=input.value.trim();
        if(!body&&!selectedFiles.length)return;
        const fileError=validateSelectedFilesClient();
        if(fileError){showError(fileError);return;}

        const bodyBeforeSend = body;
        const filesBeforeSend = [...selectedFiles];
        // Never block the composer while another message is uploading. The
        // optimistic row is shown immediately and each request owns its own
        // body/file snapshot, so consecutive messages can be sent normally.
        activeSends += 1;
        sendButton.setAttribute('aria-busy', 'true');
        input.value='';
        selectedFiles.splice(0,selectedFiles.length);
        renderSelection();
        sendButton.querySelector('span').textContent=filesBeforeSend.length?'Uploading…':'Sending…';
        const tempId=`temp-${Date.now()}`;
        const localPreviewUrls=[];
        const optimisticAttachments=filesBeforeSend.map(file=>{
            const preview=file.type.startsWith('image/')||file.type.startsWith('video/')?URL.createObjectURL(file):null;
            if(preview)localPreviewUrls.push(preview);
            return {name:file.name,original_name:file.name,mime_type:file.type,size:file.size,preview_url:preview,download_url:null};
        });
        const optimistic={id:tempId,body,user_id:currentUserId,user_name:@json(auth()->user()->name),created_at:new Date().toISOString(),edited_at:null,deleted_at:null,reply_to:replyToMessage?{...replyToMessage}:null,forwarded_from:null,attachments:optimisticAttachments};
        document.getElementById('empty-chat')?.remove();
        const optimisticRow=buildMessageRow(optimistic);
        optimisticRow.dataset.optimistic='1';
        box.appendChild(optimisticRow);
        scrollBottom();

        try{
            const uploadFiles=await prepareUploadFiles(filesBeforeSend);
            const formData=new FormData();
            if(body)formData.append('body',body);
            if(replyToMessage?.id)formData.append('reply_to_message_id',replyToMessage.id);
            uploadFiles.forEach(file=>formData.append('attachments[]',file,file.name));
            const response=await fetch('{{ route('messages.send',$selected) }}',{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:formData});
            const payload=await response.json().catch(()=>({}));
            if(!response.ok)throw new Error(payload.message||Object.values(payload.errors||{}).flat()[0]||'The message could not be sent.');
            optimisticRow.remove();
            if(payload.message)addMessage({id:payload.message.id,body:payload.message.body,user_id:payload.message.user_id,user_name:payload.message.user?.name||@json(auth()->user()->name),created_at:payload.message.created_at,edited_at:payload.message.edited_at,reply_to:payload.message.reply_to||null,forwarded_from:payload.message.forwarded_from||null,deleted_at:payload.message.deleted_at||null,attachments:payload.message.attachments||[]});
            clearReply();
        }catch(error){
            optimisticRow.remove();
            if (!input.value && bodyBeforeSend) input.value = bodyBeforeSend;
            if (!selectedFiles.length && filesBeforeSend.length) {
                filesBeforeSend.forEach(file => selectedFiles.push(file));
                renderSelection();
            }
            showError(error.message);
        }finally{
            localPreviewUrls.forEach(url=>URL.revokeObjectURL(url));
            activeSends = Math.max(0, activeSends - 1);
            sendButton.removeAttribute('aria-busy');
            sendButton.disabled = false;
            input.disabled = false;
            sendButton.querySelector('span').textContent = activeSends ? 'Sending…' : 'Send';
            input.focus();
        }
    });

    document.querySelectorAll('[data-attachment-preview-image]').forEach(image => {
        let retried=false;
        on(image, 'load', () => {
            if (initialScrollSettled) forceScrollBottom(false);
        });
        on(image, 'error', () => {
            if(!retried){
                retried=true;
                const separator=image.src.includes('?')?'&':'?';
                image.src=`${image.src}${separator}retry=${Date.now()}`;
                return;
            }
            const link = image.closest('[data-attachment-preview-link]');
            if (!link) return;
            image.classList.add('hidden');
            link.classList.add('nc-attachment-preview-missing');
            link.querySelector('.nc-attachment-fallback')?.classList.remove('hidden');
        });
    });

    const showConversationList = () => {
        const inboxUrl = @json(route('chat'));
        if (window.PharmaStrategiesNavigateChat) {
            window.PharmaStrategiesNavigateChat(inboxUrl, true);
            return;
        }
        document.getElementById('conversation-panel')?.classList.remove('mobile-hidden');
        document.getElementById('chat-panel')?.classList.add('nc-chat-closed-panel','mobile-hidden');
        document.querySelector('.nc-chat-layout')?.classList.add('nc-chat-closed');
        document.body.classList.remove('nc-mobile-chat-open');
    };
    on(document.getElementById('close-chat'), 'click', showConversationList);
    on(document.getElementById('mobile-back'), 'click', showConversationList);
    window.PharmaStrategiesCleanupChat=()=>{
        stopFallback();
        clearInterval(safetySyncTimer);
        window.Echo?.leave?.(`conversation.${conversationId}`);
        chatAbort.abort();
        globalMessageMenu.remove();
        selectionPreviewUrls.forEach(url => URL.revokeObjectURL(url));
        selectionPreviewUrls.clear();
        if (window.PharmaStrategiesCleanupChat) window.PharmaStrategiesCleanupChat = null;
    };
})();
</script>
@endif
@endsection
