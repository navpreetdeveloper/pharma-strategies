<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformUserRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $platform = User::factory()->create(['platform_role' => 'super_admin']);
        $member = User::factory()->create(['name' => 'Recovery Employee', 'email' => 'recover@example.test', 'remember_token' => 'old-remember-token']);
        $company = Company::create(['name' => 'First Employer', 'province' => 'Ontario']);
        $company->users()->attach($member, ['role' => 'staff', 'status' => 'active']);

        return [$platform, $member, $company];
    }

    private function payload(User $user): array
    {
        return ['reset_user_id' => $user->id, 'current_password' => 'password', 'password' => 'NewTemporaryPassword123!', 'password_confirmation' => 'NewTemporaryPassword123!', 'reason' => 'Identity verified with company manager', 'identity_verified' => '1'];
    }

    public function test_directory_shows_identity_and_memberships_without_passwords_or_authenticator_secrets(): void
    {
        [$platform, $member, $company] = $this->context();
        $response = $this->actingAs($platform)->get(route('platform.users.index'))->assertOk()
            ->assertSee('recover@example.test')->assertSee('First Employer')->assertSee('Reset password')
            ->assertDontSee($member->password)->assertDontSee('old-remember-token');
        $this->assertCount(2, $response->viewData('users'));
        $this->get(route('platform.users.index', ['q' => 'recover@example.test', 'company_id' => $company->id, 'status' => 'active']))->assertOk()->assertSee('Recovery Employee');
        $this->get(route('platform.users.index', ['q' => 'absent@example.test']))->assertOk()->assertSee('No users match');
    }

    public function test_company_roles_and_guests_cannot_access_platform_recovery_or_global_audits(): void
    {
        [$platform, $member, $company] = $this->context();
        $this->get(route('platform.users.index'))->assertRedirect(route('login'));
        foreach (['staff', 'company_super_admin', 'pharmacy_manager'] as $role) {
            $company->users()->updateExistingPivot($member->id, ['role' => $role]);
            $this->actingAs($member)->withSession(['company_id' => $company->id]);
            $this->get(route('platform.users.index'))->assertForbidden();
            $this->get(route('platform.audit'))->assertForbidden();
            $this->post(route('platform.users.reset-password', $platform), $this->payload($platform))->assertForbidden();
        }
        $this->assertTrue(Hash::check('password', $platform->fresh()->password));
    }

    public function test_directory_explains_missing_workspace_access_without_treating_platform_admins_as_employees(): void
    {
        [$platform, $member, $company] = $this->context();
        $company->users()->updateExistingPivot($member->id, ['status' => 'suspended']);
        $this->actingAs($platform)->get(route('platform.users.index', ['q' => $member->email]))
            ->assertOk()->assertSee('All accounts')->assertSee('Company access')
            ->assertSee('Cannot enter a workspace.')->assertSee('Resetting the password will not restore company access.')
            ->assertSee('Your administrator password')->assertSee('Enter your own Platform Super Admin password')
            ->assertSee('No active company access.');
        $this->get(route('platform.users.index', ['q' => $platform->email]))->assertOk()
            ->assertSee('No employee membership required.')->assertDontSee('Cannot enter a workspace.');
    }

    public function test_activity_filters_out_routine_events_without_deleting_records(): void
    {
        [$platform, $member, $company] = $this->context();
        $company->users()->updateExistingPivot($member->id, ['role' => 'company_super_admin']);
        foreach (['login', 'logout', 'platform_company_workspace_opened', 'conversation_created', 'message_edited', 'message_forwarded', 'unknown_internal_event', 'platform_password_reset', 'mfa_disabled', 'employee_access_removed', 'privileged_conversation_read', 'message_unsent'] as $action) {
            AuditLog::create(['company_id' => $company->id, 'user_id' => $member->id, 'action' => $action]);
        }
        foreach ([$platform, $member] as $actor) {
            $this->actingAs($actor)->withSession(['company_id' => $company->id]);
            $route = $actor->isPlatformSuperAdmin() ? 'platform.audit' : 'admin.audit';
            $response = $this->get(route($route))->assertOk();
            $this->assertCount(5, $response->viewData('logs'));
            $response->assertSee('Temporary password issued')->assertSee('Authenticator disabled')->assertSee('Company access removed')
                ->assertSee('Conversation reviewed')->assertSee('Message unsent')
                ->assertDontSee('Signed in')->assertDontSee('Signed out')->assertDontSee('Workspace opened')
                ->assertDontSee('Conversation started')->assertDontSee('Message edited')->assertDontSee('Message forwarded')->assertDontSee('Other activity');
            $filtered = $this->get(route($route, ['category' => 'security']))->assertOk();
            $this->assertCount(3, $filtered->viewData('logs'));
        }
        $this->assertSame(12, AuditLog::count());
    }

    public function test_reset_revokes_sessions_remembered_login_push_and_old_reset_tokens_but_preserves_memberships(): void
    {
        [$platform, $member, $company] = $this->context();
        $other = Company::create(['name' => 'Other Employer', 'province' => 'Quebec']);
        $other->users()->attach($member, ['role' => 'pharmacist', 'status' => 'suspended']);
        DB::table('sessions')->insert(['id' => 'old-user-session', 'user_id' => $member->id, 'payload' => '', 'last_activity' => time()]);
        DB::table('password_reset_tokens')->insert(['email' => $member->email, 'token' => 'old-reset-token', 'created_at' => now()]);
        PushSubscription::create(['user_id' => $member->id, 'endpoint' => 'https://fcm.googleapis.com/example', 'endpoint_hash' => hash('sha256', 'endpoint'), 'subscription' => ['endpoint' => 'https://fcm.googleapis.com/example']]);
        $this->actingAs($platform)->from(route('platform.users.index'))->post(route('platform.users.reset-password', $member), $this->payload($member))
            ->assertRedirect(route('platform.users.index'));
        $fresh = $member->fresh();
        $this->assertTrue(Hash::check('NewTemporaryPassword123!', $fresh->password));
        $this->assertTrue($fresh->must_change_password);
        $this->assertNotSame('old-remember-token', $fresh->remember_token);
        $this->assertDatabaseMissing('sessions', ['user_id' => $member->id]);
        $this->assertDatabaseMissing('push_subscriptions', ['user_id' => $member->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $member->email]);
        $this->assertDatabaseHas('company_user', ['company_id' => $company->id, 'user_id' => $member->id, 'role' => 'staff', 'status' => 'active']);
        $this->assertDatabaseHas('company_user', ['company_id' => $other->id, 'user_id' => $member->id, 'role' => 'pharmacist', 'status' => 'suspended']);
        $log = AuditLog::whereAction('platform_password_reset')->firstOrFail();
        $this->assertSame($platform->id, $log->user_id);
        $this->assertSame($member->id, $log->target_id);
        $this->assertTrue($log->metadata['identity_verified']);
        $this->assertStringNotContainsString('NewTemporaryPassword123!', json_encode($log->metadata));
        $this->assertAuthenticatedAs($platform);
    }

    public function test_recovery_requires_actor_password_identity_check_and_reason(): void
    {
        [$platform, $member] = $this->context();
        $this->actingAs($platform)->postJson(route('platform.users.reset-password', $member), [...$this->payload($member), 'current_password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->postJson(route('platform.users.reset-password', $member), [...$this->payload($member), 'identity_verified' => '0', 'reason' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['identity_verified', 'reason']);
        $this->post(route('platform.users.reset-password', $member), [...$this->payload($member), 'password_confirmation' => 'different'])
            ->assertSessionHasErrors('password');
        $this->assertNull(session('_old_input.password'));
        $this->assertNull(session('_old_input.current_password'));
        $this->get(route('platform.users.index'))->assertOk()
            ->assertSee('data-validation-errors', false)->assertSee('data-employee-editor-reopen', false)
            ->assertDontSee('data-feedback-type="error"', false);
        $this->assertTrue(Hash::check('password', $member->fresh()->password));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'platform_password_reset']);
    }

    public function test_platform_admins_are_protected_and_recovery_does_not_reactivate_suspended_users(): void
    {
        [$platform, $member] = $this->context();
        $this->actingAs($platform)->post(route('platform.users.reset-password', $platform), $this->payload($platform))->assertForbidden();
        $member->update(['status' => 'suspended']);
        $this->post(route('platform.users.reset-password', $member), $this->payload($member))->assertStatus(422);
        $this->assertTrue(Hash::check('password', $member->fresh()->password));
    }

    public function test_recovery_preserves_authenticator_configuration(): void
    {
        [$platform, $member] = $this->context();
        $member->forceFill(['two_factor_secret' => encrypt('authenticator-secret'), 'two_factor_recovery_codes' => encrypt(json_encode(['existing-recovery-code'])), 'two_factor_confirmed_at' => now()])->save();
        $secret = $member->two_factor_secret;
        $codes = $member->two_factor_recovery_codes;
        $this->actingAs($platform)->post(route('platform.users.reset-password', $member), $this->payload($member))->assertRedirect();
        $this->assertSame($secret, $member->fresh()->two_factor_secret);
        $this->assertSame($codes, $member->fresh()->two_factor_recovery_codes);
        $this->get(route('platform.users.index'))->assertOk()->assertSee('Authenticator enabled')->assertDontSee('authenticator-secret')->assertDontSee('existing-recovery-code');
    }

    public function test_temporary_password_sign_in_requires_replacement_and_old_password_is_rejected(): void
    {
        [$platform, $member] = $this->context();
        $this->actingAs($platform)->post(route('platform.users.reset-password', $member), $this->payload($member))->assertRedirect();
        $this->post(route('logout'));
        $this->post(route('login.submit'), ['email' => $member->email, 'password' => 'password'])->assertSessionHasErrors('password');
        $this->assertGuest();
        $this->post(route('login.submit'), ['email' => $member->email, 'password' => 'NewTemporaryPassword123!'])->assertRedirect(route('profile.show'));
        $this->getJson(route('workspace.access'))->assertForbidden();
        $this->patch(route('profile.password.update'), ['current_password' => 'NewTemporaryPassword123!', 'password' => 'PersonalPassword456!', 'password_confirmation' => 'PersonalPassword456!'])->assertRedirect(route('chat'));
        $this->getJson(route('workspace.access'))->assertOk();
    }

    public function test_company_audit_is_scoped_and_platform_only_details_are_hidden(): void
    {
        [$platform, $member, $company] = $this->context();
        $company->users()->updateExistingPivot($member->id, ['role' => 'company_super_admin']);
        $other = Company::create(['name' => 'Private Other Employer', 'province' => 'Ontario']);
        AuditLog::create(['company_id' => $company->id, 'user_id' => $member->id, 'action' => 'employee_updated', 'target_type' => 'User', 'target_id' => $member->id, 'ip_address' => '192.0.2.99', 'metadata' => ['secret' => 'hidden-metadata']]);
        AuditLog::create(['company_id' => $company->id, 'user_id' => $member->id, 'action' => 'login']);
        AuditLog::create(['company_id' => $other->id, 'user_id' => $platform->id, 'action' => 'privacy_policy_updated']);
        $this->actingAs($member)->withSession(['company_id' => $company->id]);
        $this->get(route('admin.audit'))->assertOk()->assertSee('Employee access updated')->assertDontSee('Private Other Employer')->assertDontSee('192.0.2.99')->assertDontSee('hidden-metadata');
        $this->get(route('company.dashboard'))->assertOk()->assertSee('Employee access updated')->assertDontSee('Signed in');
        $this->get(route('admin.audit', ['category' => 'security']))->assertOk()->assertDontSee('Signed in')->assertDontSee('Employee access updated');
        $this->actingAs($platform)->get(route('platform.audit'))->assertOk()->assertSee('192.0.2.99')->assertSee('Private Other Employer')->assertDontSee('hidden-metadata');
        $company->users()->updateExistingPivot($member->id, ['role' => 'staff']);
        $this->actingAs($member)->get(route('admin.audit'))->assertForbidden();
    }
}
