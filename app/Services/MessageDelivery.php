<?php

namespace App\Services;

use App\Jobs\DeliverWorkplaceMessage;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

class MessageDelivery
{
    public static function record(Message $message): void
    {
        $ids = $message->conversation->participants()->whereKeyNot($message->user_id)->pluck('users.id');
        foreach ($ids as $id) {
            DB::table('message_deliveries')->insertOrIgnore(['message_id' => $message->id, 'user_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public static function dispatch(Message $message): void
    {
        foreach (DB::table('message_deliveries')->where('message_id', $message->id)->whereNull('delivered_at')->pluck('user_id') as $id) {
            try {
                DeliverWorkplaceMessage::dispatch($message->id, (int) $id)->afterCommit();
            } catch (\Throwable $error) {
                report($error);
            }
        }
    }
}
