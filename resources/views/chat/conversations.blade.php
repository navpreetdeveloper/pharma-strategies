<div data-conversation-results>
        <div class="nc-chat-list">
            @forelse($conversations as $c)
                @php($isSelected = $selected && $selected->id === $c->id)
                @php($presentation = App\Services\ConversationPresentation::make($c, auth()->user()))
                @php($other = $presentation['other'])
                <a href="{{ route('chat',['conversation'=>$c->id]) }}" class="nc-conversation {{ $isSelected ? 'active' : '' }} {{ $c->unread_count ? 'has-unread' : '' }}" aria-label="{{ $presentation['title'] }}{{ $c->unread_count ? ', '.$c->unread_count.' unread messages' : '' }}" @if($isSelected) aria-current="page" @endif data-conversation-link data-conversation-id="{{ $c->id }}">
                    <div class="flex items-center gap-3">
                        <span class="nc-conversation-avatar">{{ $presentation['initials'] }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="nc-conversation-heading"><div class="nc-conversation-name">{{ $presentation['title'] }}</div><time class="nc-conversation-time" datetime="{{ $c->latestMessage?->created_at?->toIso8601String() }}">{{ $c->latestMessage?->created_at?->isToday() ? $c->latestMessage->created_at->format('g:i A') : $c->latestMessage?->created_at?->format('M j') }}</time></div>
                            <div class="nc-conversation-preview">{{ !$c->participants->contains('id', auth()->id()) ? 'Open to review messages' : ($c->latestMessage?->deleted_at ? 'Message unsent' : Illuminate\Support\Str::limit($c->latestMessage?->body ?: 'Attachment', 90)) }}</div>@if($presentation['subtitle'])<div class="nc-conversation-subtitle">{{ $presentation['subtitle'] }}</div>@endif
                            @if($other)
                                <div class="text-xs nc-muted mt-1 flex items-center gap-1" data-presence-user="{{ $other->id }}"><span class="nc-presence-dot"></span><span data-presence-label>Offline</span></div>
                            @endif
                        </div>
                        @if($c->unread_count)<span class="nc-unread-badge" data-unread-count="{{ $c->unread_count }}" aria-label="{{ $c->unread_count }} unread messages">{{ $c->unread_count > 99 ? '99+' : $c->unread_count }}</span>@elseif($isSelected)<span class="nc-active-chevron" aria-hidden="true">›</span>@endif
                    </div>
                </a>
            @empty
                <div class="p-6 text-sm nc-muted">No messages yet. Choose a colleague above and send your first message.</div>
            @endforelse
        </div>
        @if($conversations->hasPages())<div class="p-3">{{ $conversations->links() }}</div>@endif
</div>