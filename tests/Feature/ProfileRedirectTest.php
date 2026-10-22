<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_update_returns_to_profile(): void
    {
        $company = Company::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Test Pharmacy',
            'province' => 'Ontario',
            'timezone' => 'America/Toronto',
        ]);

        $user = User::create([
            'name' => 'Original Name',
            'email' => 'profile@example.test',
            'password' => bcrypt('password'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->companies()->attach($company->id, ['role' => 'staff', 'status' => 'active']);
        $this->actingAs($user)->withSession(['company_id' => $company->id]);

        $response = $this->patch(route('profile.update'), [
            'name' => 'Updated Name',
            'employee_id' => '',
            'department' => '',
            'job_title' => '',
        ]);

        $response->assertRedirect(route('profile.show'));
        $response->assertSessionHas('ok', 'Profile updated successfully.');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated Name']);
    }
}
