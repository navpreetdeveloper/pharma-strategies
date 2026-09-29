<?php

use App\Models\Company;
use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('conversation.{conversation}', function ($user, Conversation $conversation) {
    return $user->canAccessConversation($conversation);
});

Broadcast::channel('company.{company}.presence', function ($user, Company $company) {
    if ((int) session('company_id') !== (int) $company->id) return false;
    $member = $user->companies()->whereKey($company->id)->wherePivot('status', 'active')->exists();
    return $member ? [
        'id' => $user->id,
        'name' => $user->name,
        'last_seen_at' => $user->last_seen_at?->toIso8601String(),
    ] : false;
});

Broadcast::channel('App.Models.User.{userId}', function ($user, int $userId) {
    return (int) $user->id === $userId;
});
