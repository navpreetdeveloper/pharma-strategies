<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Http\Adapter\Guzzle7\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    public function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        if (! config('webpush.enabled') || ! config('webpush.public_key') || ! config('webpush.private_key') || ! config('webpush.subject')) {
            return;
        }

        try {
            $subscriptions = PushSubscription::where('user_id', $user->id)->where('updated_at', '>', now()->subDays(90))->get();
        } catch (\Throwable $e) {
            Log::error('Unable to load web push subscriptions.', [
                'user_id' => $user->id,
                'exception_type' => get_class($e),
            ]);
            throw new \RuntimeException('Unable to load push subscriptions.');
        }

        if ($subscriptions->isEmpty()) {
            return;
        }

        try {
            $http = Client::createWithConfig(['allow_redirects' => false, 'timeout' => 15, 'connect_timeout' => 5]);
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
            ], $http, null, null, $http);

            $targetUrl = isset($data['conversation_id'])
                ? url('/chat?conversation='.(int) $data['conversation_id'])
                : url('/chat');

            $payload = json_encode([
                'title' => 'Pharma Strategies',
                'body' => (int) ($data['unread_count'] ?? 1) > 1 ? 'You have '.(int) $data['unread_count'].' unread workplace messages.' : 'You have a new workplace message.',
                'data' => array_intersect_key($data, array_flip(['company_id', 'conversation_id', 'message_id', 'notification_id', 'important', 'unread_count'])),
                'url' => $targetUrl,
                'icon' => url('/icons/icon-192.png'),
                'badge' => url('/icons/icon-192.png'),
            ], JSON_THROW_ON_ERROR);

            foreach ($subscriptions as $stored) {
                try {
                    if (! PushEndpointPolicy::allows($stored->endpoint)) {
                        $stored->delete();

                        continue;
                    }
                    $webPush->queueNotification(
                        Subscription::create($stored->subscription),
                        $payload,
                    );
                } catch (\Throwable $e) {
                    Log::warning('Discarding an invalid web push subscription.', [
                        'user_id' => $user->id,
                        'subscription_id' => $stored->id,
                        'exception_type' => get_class($e),
                    ]);
                    DB::table('push_subscriptions')->where('id', $stored->id)->delete();
                }
            }

            $failed = false;
            foreach ($webPush->flush(100) as $report) {
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
                            'last_delivery_at' => now(),
                            'last_failure_at' => null,
                            'last_http_status' => $report->getResponse()?->getStatusCode(),
                            'last_transport_error' => null,
                        ]);
                    }

                    continue;
                }

                $failed = true;
                $transportCode = PushDeliveryDiagnostics::curlCode($report->getReason());
                if ($stored) {
                    DB::table('push_subscriptions')->where('id', $stored->id)->update([
                        'last_failure_at' => now(),
                        'last_http_status' => $report->getResponse()?->getStatusCode(),
                        'last_transport_error' => $transportCode,
                    ]);
                }
                Log::warning('Web push delivery failed.', [
                    'user_id' => $user->id,
                    'endpoint_hash' => hash('sha256', $endpoint),
                    'status' => $report->getResponse()?->getStatusCode(),
                    'curl_error_code' => $transportCode,
                ]);
            }
            if ($failed) {
                throw new \RuntimeException('One or more push deliveries failed; retry is required.');
            }
        } catch (\Throwable $e) {
            Log::error('Web push delivery exception.', [
                'user_id' => $user->id,
                'exception_type' => get_class($e),
                'curl_error_code' => PushDeliveryDiagnostics::curlCode($e->getMessage()),
            ]);
            throw new \RuntimeException('Push delivery could not complete; retry is required.');
        }
    }
}
