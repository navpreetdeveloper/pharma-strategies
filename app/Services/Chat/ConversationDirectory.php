<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\PrivacySetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ConversationDirectory
{
    public function query(User $user, int $companyId): Builder
    {
        $canReview = $user->isSuperAdmin() && PrivacySetting::administratorReviewAllowed($companyId);
        $query = Conversation::where('company_id', $companyId)->whereHas('messages')
            ->with(['participants.companies' => fn ($q) => $q->where('companies.id', $companyId), 'latestMessage'])
            // The timeline index can read one newest row instead of aggregating
            // every message in each conversation during sidebar reconciliation.
            ->addSelect(['messages_max_created_at' => Message::select('created_at')
                ->whereColumn('conversation_id', 'conversations.id')
                ->orderByDesc('created_at')->orderByDesc('id')->limit(1)])
            ->withCount(['messages as unread_count' => fn ($q) => $q->where(fn ($authors) => $authors->where('user_id', '!=', $user->id)->orWhereNull('user_id'))->whereNull('deleted_at')
                ->where('messages.id', '>', DB::table('conversation_participants')->select('last_read_message_id')->whereColumn('conversation_id', 'messages.conversation_id')->where('user_id', $user->id))]);
        if (! $canReview) {
            $query->whereHas('participants', fn ($q) => $q->where('users.id', $user->id));
        }

        return $query;
    }
}
