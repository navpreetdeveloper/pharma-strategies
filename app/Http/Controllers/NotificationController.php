<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'unread_count' => $user->unreadNotifications()->count(),
            'notifications' => $user->notifications()->latest()->limit(25)->get()->map(fn ($notification) => [
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
        $request->user()->unreadNotifications->markAsRead();

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
        $endpointHash = hash('sha256', $endpoint);

        // Push subscriptions are disposable device credentials. Replace the
        // existing endpoint row instead of updateOrCreate so a subscription
        // encrypted with an older APP_KEY can never be read during an update.
        // This prevents a stale encrypted row from turning a normal subscribe
        // request into a 500 "The MAC is invalid" error after APP_KEY changes.
        DB::table('push_subscriptions')->where('endpoint_hash', $endpointHash)->delete();

        PushSubscription::create([
            'user_id' => $request->user()->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => $endpointHash,
            'subscription' => $data['subscription'],
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'device_name' => $data['device_name'] ?? null,
            'last_used_at' => now(),
        ]);

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
