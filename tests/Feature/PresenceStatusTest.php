<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresenceStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_is_recent_and_scoped_to_active_company_members(): void
    {
        $company = Company::create(['name' => 'Pharmacy', 'province' => 'Ontario']);
        $viewer = User::factory()->create();
        $online = User::factory()->create(['last_seen_at' => now()]);
        $offline = User::factory()->create(['last_seen_at' => now()->subSeconds(90)]);
        $foreign = User::factory()->create(['last_seen_at' => now()]);
        $suspended = User::factory()->create(['status' => 'suspended', 'last_seen_at' => now()]);
        foreach ([$viewer, $online, $offline, $suspended] as $user) {
            $company->users()->attach($user->id, ['role' => 'staff', 'status' => 'active']);
        }
        $response = $this->actingAs($viewer)->withSession(['company_id' => $company->id])->getJson(route('presence.index', ['ids' => [$online->id, $offline->id, $foreign->id, $suspended->id]]))->assertOk()->assertJsonCount(2, 'users');
        $this->assertTrue(collect($response->json('users'))->firstWhere('id', $online->id)['online']);
        $this->assertFalse(collect($response->json('users'))->firstWhere('id', $offline->id)['online']);
        $company->users()->updateExistingPivot($online->id, ['status' => 'suspended']);
        $this->getJson(route('presence.index', ['ids' => [$online->id]]))->assertOk()->assertJsonCount(0, 'users');
    }
}
