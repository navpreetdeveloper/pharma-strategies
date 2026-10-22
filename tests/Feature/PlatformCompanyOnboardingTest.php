<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformCompanyOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return [
            'name' => 'Approved Pharmacy', 'province' => 'Ontario',
            'admin_name' => 'Company Owner', 'admin_email' => 'owner@example.test',
            'admin_password' => 'TemporaryPassword123!', 'admin_password_confirmation' => 'TemporaryPassword123!',
        ];
    }

    public function test_platform_admin_creates_the_company_owner_and_privacy_settings_together(): void
    {
        $platform = User::factory()->create(['platform_role' => 'super_admin']);
        $data = $this->payload();
        $data['admin_email'] = ' OWNER@example.test ';
        $this->actingAs($platform)->post(route('platform.companies.store'), $data)->assertRedirect();
        $company = Company::whereName('Approved Pharmacy')->firstOrFail();
        $owner = User::whereEmail('owner@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('TemporaryPassword123!', $owner->password));
        $this->assertTrue($owner->must_change_password);
        $this->assertNull($owner->platform_role);
        $this->assertNull($owner->email_verified_at);
        $this->assertAuthenticatedAs($platform);
        $this->assertDatabaseHas('company_user', ['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'company_super_admin', 'status' => 'active']);
        $this->assertDatabaseHas('privacy_settings', ['company_id' => $company->id, 'privacy_contact_email' => $owner->email]);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'user_id' => $platform->id, 'action' => 'platform_company_admin_created', 'target_id' => $owner->id]);
        $this->get(route('platform.companies.index', ['create' => 1]))->assertOk()->assertSee('name="admin_email"', false)->assertSee('Temporary password');
    }

    public function test_company_creation_requires_platform_access(): void
    {
        $this->post(route('platform.companies.store'), $this->payload())->assertRedirect(route('login'));
        $company = Company::create(['name' => 'Existing Pharmacy', 'province' => 'Ontario']);
        $owner = User::factory()->create();
        $company->users()->attach($owner, ['role' => 'company_super_admin', 'status' => 'active']);
        $this->actingAs($owner)->withSession(['company_id' => $company->id])
            ->post(route('platform.companies.store'), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseMissing('users', ['email' => 'owner@example.test']);
    }

    public function test_existing_email_cannot_create_an_orphan_company_or_overwrite_an_account(): void
    {
        $platform = User::factory()->create(['platform_role' => 'super_admin']);
        $existing = User::factory()->create(['email' => 'owner@example.test']);
        $password = $existing->password;
        $this->actingAs($platform)->from(route('platform.companies.index', ['create' => 1]))
            ->post(route('platform.companies.store'), $this->payload())->assertSessionHasErrors('admin_email');
        $this->assertDatabaseCount('companies', 0);
        $this->assertSame($password, $existing->fresh()->password);
        $this->assertFalse($existing->fresh()->must_change_password);
        $this->assertNull(session('_old_input.admin_password'));
        $this->assertNull(session('_old_input.admin_password_confirmation'));
    }

    public function test_admin_identity_and_a_strong_confirmed_password_are_required(): void
    {
        $platform = User::factory()->create(['platform_role' => 'super_admin']);
        $this->actingAs($platform)->post(route('platform.companies.store'), ['name' => 'Incomplete', 'province' => 'Ontario'])
            ->assertSessionHasErrors(['admin_name', 'admin_email', 'admin_password']);
        $this->post(route('platform.companies.store'), [...$this->payload(), 'admin_password' => 'weak', 'admin_password_confirmation' => 'other'])
            ->assertSessionHasErrors('admin_password');
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_new_admin_must_replace_the_temporary_password_before_workspace_access(): void
    {
        config(['security.require_privileged_mfa' => false, 'security.require_email_verification' => false]);
        $platform = User::factory()->create(['platform_role' => 'super_admin']);
        $this->actingAs($platform)->post(route('platform.companies.store'), $this->payload())->assertRedirect();
        $owner = User::whereEmail('owner@example.test')->firstOrFail();
        $this->post(route('logout'));
        $this->withSession(['url.intended' => route('chat')])->post(route('login.submit'), ['email' => $owner->email, 'password' => 'TemporaryPassword123!'])
            ->assertRedirect(route('profile.show'));
        $this->get(route('profile.show'))->assertOk()->assertSee('Change your temporary password before accessing the workspace.');
        $this->get(route('company.dashboard'))->assertRedirect(route('profile.show'));
        $this->getJson(route('workspace.access'))->assertForbidden();
        $this->patch(route('profile.password.update'), ['current_password' => 'TemporaryPassword123!', 'password' => 'TemporaryPassword123!', 'password_confirmation' => 'TemporaryPassword123!'])
            ->assertSessionHasErrors('password');
        $this->assertTrue($owner->fresh()->must_change_password);
        $this->patch(route('profile.password.update'), ['current_password' => 'TemporaryPassword123!', 'password' => 'NewPersonalPassword456!', 'password_confirmation' => 'NewPersonalPassword456!'])
            ->assertRedirect(route('company.dashboard'));
        $this->assertFalse($owner->fresh()->must_change_password);
        $this->assertTrue(Hash::check('NewPersonalPassword456!', $owner->fresh()->password));
        $this->get(route('company.dashboard'))->assertOk();
        $this->getJson(route('workspace.access'))->assertOk();
    }
}
