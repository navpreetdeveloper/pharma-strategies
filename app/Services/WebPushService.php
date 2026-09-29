<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    public function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        if (!config('webpush.enabled') || !config('webpush.public_key') || !config('webpush.private_key') || !config('webpush.subject')) {
            return;
        }

        try {
            $subscriptions = PushSubscription::where('user_id', $user->id)->get();
        } catch (\Throwable $e) {
            Log::error('Unable to load web push subscriptions.', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
            return;
        }

        if ($subscriptions->isEmpty()) return;

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => config('webpush.subject'),
                    'publicKey' => config('webpush.public_key'),
                    'privateKey' => config('webpush.private_key'),
                ],
            ], [
                'TTL' => 300,
                'urgency' => 'high',
                'contentType' => 'application/json',
            ]);

            $targetUrl = isset($data['conversation_id'])
                ? url('/chat?conversation=' . (int) $data['conversation_id'])
                : url('/chat');

            $payload = json_encode([
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'url' => $targetUrl,
                'icon' => url('/icons/icon-192.png'),
                'badge' => url('/icons/icon-192.png'),
            ], JSON_THROW_ON_ERROR);

            foreach ($subscriptions as $stored) {
                try {
                    $webPush->queueNotification(
                        Subscription::create($stored->subscription),
                        $payload,
                    );
                } catch (\Throwable $e) {
                    Log::warning('Discarding an invalid web push subscription.', [
                        'user_id' => $user->id,
                        'subscription_id' => $stored->id,
                        'reason' => $e->getMessage(),
                    ]);
                    DB::table('push_subscriptions')->where('id', $stored->id)->delete();
                }
            }

            foreach ($webPush->flush() as $report) {
                $endpoint = $report->getEndpoint();
                $stored = $subscriptions->firstWhere('endpoint_hash', hash('sha256', $endpoint));

                if ($report->isSubscriptionExpired()) {
                    $stored?->delete();
                    continue;
                }

                if ($report->isSuccess()) {
                    if ($stored) {
                        DB::table('push_subscriptions')->where('id', $stored->id)->update([
                            'last_used_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                    continue;
                }

                Log::warning('Web push delivery failed.', [
                    'user_id' => $user->id,
                    'endpoint' => $endpoint,
                    'reason' => $report->getReason(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Web push delivery exception.', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
