<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploymentReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_check_rejects_local_defaults_without_printing_secrets(): void
    {
        $this->artisan('pharma:check-production')->expectsOutput('FAIL production environment')->assertExitCode(1);
    }

    public function test_production_check_accepts_a_configured_profile_but_does_not_claim_device_delivery(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config([
            'app.debug' => false, 'app.url' => 'https://pharmacy-fixture.ca',
            'session.secure' => true, 'session.http_only' => true, 'session.driver' => 'database',
            'queue.default' => 'database', 'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.app_id' => 'fixture', 'broadcasting.connections.reverb.key' => 'fixture-public-key',
            'broadcasting.connections.reverb.secret' => 'fixture-secret-not-for-production',
            'reverb.apps.apps.0.allowed_origins' => ['pharmacy-fixture.ca'], 'security.require_privileged_mfa' => true,
            'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.fixture.ca', 'mail.from.address' => 'support@fixture.ca',
            'webpush.enabled' => true, 'webpush.subject' => 'https://pharmacy-fixture.ca', 'webpush.public_key' => 'fixture-public', 'webpush.private_key' => 'fixture-private',
        ]);
        $this->artisan('pharma:check-production', ['--require-push' => true])
            ->expectsOutput('These checks do not test SMTP, WebSocket delivery, backups or device notifications.')->assertExitCode(0);
    }

    public function test_session_status_returns_fresh_own_scope_and_rejects_suspended_accounts(): void
    {
        $company = Company::create(['name' => 'Session fixture', 'province' => 'Ontario']);
        $user = User::factory()->create();
        $company->users()->attach($user->id, ['role' => 'staff', 'status' => 'active']);
        $response = $this->actingAs($user)->withSession(['company_id' => $company->id])->getJson(route('session.status'));
        $response->assertOk()->assertJsonPath('user_id', $user->id)->assertJsonPath('company_id', $company->id)->assertJsonPath('workspace_role', 'staff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $company->users()->updateExistingPivot($user->id, ['role' => 'pharmacist']);
        $this->getJson(route('session.status'))->assertJsonPath('workspace_role', 'pharmacist');
        $user->update(['status' => 'suspended']);
        $this->getJson(route('session.status'))->assertForbidden();
    }

    public function test_json_logout_invalidates_the_session_and_keeps_the_html_fallback(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson(route('logout'))->assertOk()->assertJsonPath('ok', true)->assertJsonPath('redirect', route('login'));
        $this->assertGuest();
        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
