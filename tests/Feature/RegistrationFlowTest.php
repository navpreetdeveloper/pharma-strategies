<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_registration_creates_a_super_admin_and_opens_the_workspace(): void
    {
        $response = $this->post(route('register.submit'), [
            'company_name' => 'Example Pharmacy',
            'province' => 'Ontario',
            'name' => 'Workspace Admin',
            'email' => 'admin@example.test',
            'password' => 'StrongPassword!123',
            'password_confirmation' => 'StrongPassword!123',
        ]);

        $response->assertRedirect('/admin');
        $response->assertSessionHas('ok', 'Your company workspace has been created successfully.');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('companies', ['name' => 'Example Pharmacy']);
        $this->assertDatabaseHas('users', ['email' => 'admin@example.test']);
        $this->assertDatabaseHas('company_user', ['role' => 'company_super_admin', 'status' => 'active']);
    }
}
