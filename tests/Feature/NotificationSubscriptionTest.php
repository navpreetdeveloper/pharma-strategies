<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_push_subscription_persists_endpoint_and_keys(): void
    {
        $company = Company::create(['name' => 'Test Pharmacy', 'legal_name' => 'Test Pharmacy', 'province' => 'Ontario']);
        $user = User::create(['name' => 'Test User', 'email' => 'push@example.test', 'password' => 'password', 'status' => 'active', 'email_verified_at' => now()]);
        $company->users()->attach($user->id, ['role' => 'staff', 'status' => 'active']);

        $endpoint = 'https://fcm.googleapis.com/subscription/abc';
        $response = $this->actingAs($user)->withSession(['company_id' => $company->id])->postJson(route('push.subscribe'), [
            'subscription' => [
                'endpoint' => $endpoint,
                'keys' => ['p256dh' => str_repeat('a', 20), 'auth' => str_repeat('b', 20)],
            ],
            'device_name' => 'Test Desktop',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $user->id,
            'endpoint_hash' => hash('sha256', $endpoint),
        ]);

        $this->assertSame($endpoint, PushSubscription::first()->endpoint);
    }

    public function test_resubscribing_replaces_a_stale_encrypted_device_record_without_reading_it(): void
    {
        $company = Company::create(['name' => 'Test Pharmacy', 'legal_name' => 'Test Pharmacy', 'province' => 'Ontario']);
        $user = User::create(['name' => 'Test User', 'email' => 'push-repair@example.test', 'password' => 'password', 'status' => 'active', 'email_verified_at' => now()]);
        $company->users()->attach($user->id, ['role' => 'staff', 'status' => 'active']);

        $endpoint = 'https://fcm.googleapis.com/subscription/stale';
        \DB::table('push_subscriptions')->insert([
            'user_id' => $user->id,
            'endpoint' => 'legacy-invalid-encrypted-value',
            'endpoint_hash' => hash('sha256', $endpoint),
            'subscription' => 'legacy-invalid-encrypted-value',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->withSession(['company_id' => $company->id])->postJson(route('push.subscribe'), [
            'subscription' => [
                'endpoint' => $endpoint,
                'keys' => ['p256dh' => str_repeat('a', 20), 'auth' => str_repeat('b', 20)],
            ],
            'device_name' => 'Repaired Desktop',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->assertSame($endpoint, PushSubscription::first()->endpoint);
    }

    public function test_same_owner_repair_preserves_diagnostics_and_new_owner_resets_them(): void
    {
        $company = Company::create(['name' => 'Pharmacy', 'province' => 'Ontario']);
        $users = User::factory()->count(2)->create();
        foreach ($users as $user) {
            $company->users()->attach($user->id, ['role' => 'staff', 'status' => 'active']);
        }
        $endpoint = 'https://fcm.googleapis.com/subscription/diagnostic-probe';
        $data = ['subscription' => ['endpoint' => $endpoint, 'keys' => ['p256dh' => str_repeat('a', 20), 'auth' => str_repeat('b', 20)]]];
        $this->actingAs($users[0])->withSession(['company_id' => $company->id])->postJson(route('push.subscribe'), $data)->assertOk();
        \DB::table('push_subscriptions')->update(['last_delivery_at' => now(), 'last_http_status' => 201]);
        $this->postJson(route('push.subscribe'), $data)->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->assertNotNull(PushSubscription::first()->last_delivery_at);
        $this->assertSame(201, PushSubscription::first()->last_http_status);
        $this->actingAs($users[1])->postJson(route('push.subscribe'), $data)->assertOk();
        $this->assertSame($users[1]->id, PushSubscription::first()->user_id);
        $this->assertNull(PushSubscription::first()->last_delivery_at);
        $this->assertNull(PushSubscription::first()->last_http_status);
    }
}
