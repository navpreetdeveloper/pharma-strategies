<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationFlowTest extends TestCase
{
    use RefreshDatabase;

    public static function landingRoles(): array
    {
        return [
            ['company_super_admin', 'company.dashboard'],
            ['pharmacy_manager', 'company.dashboard'],
            ['staff', 'chat'],
            ['platform', 'platform.companies.index'],
        ];
    }

    #[DataProvider('landingRoles')]
    public function test_sign_in_and_home_open_the_correct_role_destination(string $role, string $destination): void
    {
        $user = User::factory()->create(['platform_role' => $role === 'platform' ? 'super_admin' : null]);
        if ($role !== 'platform') {
            $company = Company::create(['name' => 'Landing Pharmacy', 'province' => 'Ontario']);
            $company->users()->attach($user, ['role' => $role, 'status' => 'active']);
        }
        $this->withSession(['url.intended' => route('chat')])->post(route('login.submit'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route($destination));
        $this->get(route('home'))->assertRedirect(route($destination));
        $this->get(route($destination))->assertOk();
    }

    public function test_public_company_registration_is_disabled_and_no_records_are_created(): void
    {
        $this->get('/register')->assertNotFound();
        $response = $this->post('/register', [
            'company_name' => 'Example Pharmacy',
            'province' => 'Ontario',
            'name' => 'Workspace Admin',
            'email' => 'admin@example.test',
            'password' => 'StrongPassword!123',
            'password_confirmation' => 'StrongPassword!123',
        ]);

        $response->assertNotFound();
        $this->assertGuest();
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('company_user', 0);
        foreach (['/', '/login'] as $path) {
            $this->get($path)->assertOk()->assertDontSee('href="http://127.0.0.1:8000/register"', false)
                ->assertDontSee('Register company')->assertDontSee('Get started')->assertDontSee('Create a workspace');
        }
    }
}
