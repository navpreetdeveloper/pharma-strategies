<?php

namespace App\Http\Resources;

use App\Services\ConversationPresentation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $presentation = ConversationPresentation::make($this->resource, $request->user());

        return ['id' => $this->id, 'type' => $this->type, 'title' => $presentation['title'],
            'subtitle' => $presentation['subtitle'], 'unread_count' => (int) $this->unread_count,
            'latest_message_at' => $this->latestMessage?->created_at?->toIso8601String()];
    }
}
