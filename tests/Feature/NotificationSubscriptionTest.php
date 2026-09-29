<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Models\PushSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_push_subscription_persists_endpoint_and_keys(): void
    {
        $company = Company::create(['name' => 'Test Pharmacy', 'legal_name' => 'Test Pharmacy', 'province' => 'Ontario']);
        $user = User::create(['name' => 'Test User', 'email' => 'push@example.test', 'password' => 'password', 'status' => 'active']);
        $company->users()->attach($user->id, ['role' => 'staff', 'status' => 'active']);

        $endpoint = 'https://push.example.test/subscription/abc';
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
        $user = User::create(['name' => 'Test User', 'email' => 'push-repair@example.test', 'password' => 'password', 'status' => 'active']);
        $company->users()->attach($user->id, ['role' => 'staff', 'status' => 'active']);

        $endpoint = 'https://push.example.test/subscription/stale';
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

}
