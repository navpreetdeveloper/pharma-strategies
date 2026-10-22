<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\User;

class ConversationPresentation
{
    public static function make(Conversation $conversation, User $viewer): array
    {
        $participants = $conversation->participants;
        $visible = $conversation->type === 'direct' && ! $viewer->isPlatformSuperAdmin()
            ? $participants->where('id', '!=', $viewer->id)
            : $participants;
        $names = $visible->map(function ($participant) use ($conversation) {
            $membership = $participant->companies->firstWhere('id', $conversation->company_id);

            return $membership?->pivot?->name ?: $participant->name;
        })->values();
        $title = $conversation->name ?: ($names->join(' & ') ?: ($conversation->type === 'direct' ? 'Deleted account' : 'Conversation'));
        $other = $conversation->type === 'direct' && ! $viewer->isPlatformSuperAdmin() ? $visible->first() : null;
        $subtitle = $conversation->type === 'direct'
            ? ($viewer->isPlatformSuperAdmin() ? 'Direct conversation · Read-only' : '')
            : ucwords(str_replace('_', ' ', $conversation->type)).' · '.$participants->count().' participants';
        $initials = $names->take(2)->map(fn ($name) => mb_strtoupper(mb_substr(trim($name), 0, 1)))->join('');

        return ['title' => $title, 'subtitle' => $subtitle, 'initials' => $initials ?: 'C', 'other' => $other];
    }
}
