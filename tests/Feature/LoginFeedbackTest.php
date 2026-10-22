<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_login_discards_an_intended_conversation_and_opens_chat_list(): void
    {
        $company = Company::create(['name' => 'Pharmacy', 'province' => 'Ontario']);
        $user = User::factory()->create();
        $company->users()->attach($user->id, ['role' => 'staff', 'status' => 'active']);
        $this->withSession(['url.intended' => route('chat', ['conversation' => 123])])
            ->post(route('login.submit'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('chat'));
        $this->assertNull(session('url.intended'));
        $this->get(route('chat'))->assertOk()->assertViewHas('selected', null);
        $this->post(route('logout'));

        $user->forceFill(['two_factor_secret' => encrypt('SECRET'), 'two_factor_recovery_codes' => encrypt('["recovery-code"]'), 'two_factor_confirmed_at' => now()])->save();
        $this->withSession(['url.intended' => route('chat', ['conversation' => 123])])
            ->post(route('login.submit'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('mfa.challenge'));
        $this->post(route('mfa.challenge.confirm'), ['recovery_code' => 'recovery-code'])->assertRedirect(route('chat'));
        $this->assertNull(session('url.intended'));
    }

    public function test_invalid_and_unknown_emails_get_email_errors_without_signing_in(): void
    {
        foreach (['not-an-email', 'unknown@example.test'] as $email) {
            $this->from(route('login'))->post(route('login.submit'), ['email' => $email, 'password' => 'NotThePassword1'])
                ->assertRedirect(route('login'))->assertSessionHasErrors(['email' => 'Email is invalid.']);
            $this->assertGuest();
            $this->assertNull(session('_old_input.password'));
        }
    }

    public function test_wrong_password_is_associated_with_password_field_and_clears_pending_mfa(): void
    {
        $user = User::factory()->create(['platform_role' => 'super_admin']);
        $this->withSession(['mfa_pending_id' => $user->id, 'mfa_pending_at' => time(), 'mfa_remember' => true])
            ->from(route('login'))->post(route('login.submit'), ['email' => $user->email, 'password' => 'wrong'])
            ->assertRedirect(route('login'))->assertSessionHasErrors(['password' => 'Wrong password.']);
        $this->assertGuest();
        $this->assertNull(session('mfa_pending_id'));
        $this->assertNull(session('_old_input.password'));
        $this->get(route('login'))->assertOk()->assertSee('data-validation-errors', false)->assertSee('Wrong password.');
    }

    public function test_suspended_account_cannot_sign_in_even_with_correct_password(): void
    {
        $user = User::factory()->create(['status' => 'suspended', 'platform_role' => 'super_admin']);
        $this->post(route('login.submit'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'This account is suspended. Contact your administrator.']);
        $this->assertGuest();
    }

    public function test_valid_login_normalizes_email_and_requires_mfa_when_enabled(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.test', 'platform_role' => 'super_admin']);
        $this->post(route('login.submit'), ['email' => 'ADMIN@example.test', 'password' => 'password'])->assertRedirect(route('platform.companies.index'));
        $this->assertAuthenticatedAs($user);
        $this->post(route('logout'));
        $user->forceFill(['two_factor_secret' => encrypt('SECRET'), 'two_factor_recovery_codes' => encrypt('["recovery-code"]'), 'two_factor_confirmed_at' => now()])->save();
        $this->post(route('login.submit'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('mfa.challenge'));
        $this->assertGuest();
        $this->assertSame($user->id, session('mfa_pending_id'));
    }
}
