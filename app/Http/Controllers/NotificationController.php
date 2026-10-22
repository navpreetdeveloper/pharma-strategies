<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\PushEndpointPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $page = $user->importantNotifications()->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(25);

        return response()->json([
            'next_cursor' => $page->nextCursor()?->encode(),
            'unread_count' => $user->importantNotifications()->whereNull('read_at')->count(),
            'notifications' => $page->getCollection()->map(fn ($notification) => [
                'id' => $notification->id,
                'read_at' => $notification->read_at,
                'created_at' => $notification->created_at?->toIso8601String(),
                'title' => data_get($notification->data, 'title'),
                'body' => data_get($notification->data, 'body'),
                'data' => is_array($notification->data) ? data_get($notification->data, 'data', []) : [],
            ]),
        ]);
    }

    public function read(Request $request, string $notification)
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $item->markAsRead();

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function clear(Request $request)
    {
        $user = $request->user();
        $deleted = $user->notifications()->delete();

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }

    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'subscription' => ['required', 'array'],
            'subscription.endpoint' => ['required', 'url', 'max:2048'],
            'subscription.keys' => ['required', 'array'],
            'subscription.keys.p256dh' => ['required', 'string', 'max:255'],
            'subscription.keys.auth' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $endpoint = $data['subscription']['endpoint'];
        if (! PushEndpointPolicy::allows($endpoint)) {
            throw ValidationException::withMessages(['subscription.endpoint' => 'Use a supported HTTPS browser push provider.']);
        }
        $endpointHash = hash('sha256', $endpoint);
        $request->session()->put('push_endpoint_hash', $endpointHash);

        // Build fresh encrypted attributes without decrypting an existing row.
        // Same-owner repairs preserve diagnostics; account changes reset them.
        $attributes = (new PushSubscription([
            'user_id' => $request->user()->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => $endpointHash,
            'subscription' => $data['subscription'],
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'device_name' => $data['device_name'] ?? null,
            'last_used_at' => now(),
        ]))->getAttributes();
        DB::transaction(function () use ($attributes, $endpointHash, $request): void {
            $stored = DB::table('push_subscriptions')->where('endpoint_hash', $endpointHash)->lockForUpdate()->first(['id', 'user_id']);
            $values = $attributes + ['updated_at' => now()];
            if ($stored) {
                if ((int) $stored->user_id !== (int) $request->user()->id) {
                    $values += ['last_delivery_at' => null, 'last_failure_at' => null, 'last_http_status' => null, 'last_transport_error' => null];
                }
                DB::table('push_subscriptions')->where('id', $stored->id)->update($values);
            } else {
                DB::table('push_subscriptions')->insert($values + ['created_at' => now()]);
            }
        }, 3);

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:2048'],
        ]);

        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->delete();

        return response()->json(['ok' => true]);
    }
}
