<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

class AccountSecurityController extends Controller
{
    public function forgot()
    {
        return view('auth.security', ['mode' => 'forgot']);
    }

    public function sendReset(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:190']);
        Password::sendResetLink(['email' => strtolower($data['email'])]);

        return back()->with('ok', 'Check your email for a password reset link.');
    }

    public function resetForm(Request $request, string $token)
    {
        return view('auth.security', ['mode' => 'reset', 'token' => $token, 'email' => $request->query('email')]);
    }

    public function reset(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'token' => 'required|string', 'password' => ['required', 'confirmed', PasswordRule::defaults()]]);
        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60), 'must_change_password' => false])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET ? redirect()->route('login')->with('ok', 'Password reset. Sign in with your new password.') : back()->withErrors(['email' => 'The reset link is invalid or expired. Request a new link.']);
    }

    public function verifyNotice()
    {
        return view('auth.security', ['mode' => 'verify']);
    }

    public function verify(EmailVerificationRequest $request)
    {
        $request->fulfill();

        return redirect()->route($request->user()->landingRoute())->with('ok', 'Work email verified.');
    }

    public function resend(Request $request)
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return back()->with('ok', 'Check your email for the verification link.');
    }

    public function challenge()
    {
        return view('auth.security', ['mode' => 'challenge']);
    }

    public function confirmChallenge(Request $request, TwoFactorAuthenticationProvider $provider)
    {
        $data = $request->validate(['code' => 'nullable|digits:6', 'recovery_code' => 'nullable|string|max:100']);
        $pending = $request->session()->get('mfa_pending_id');
        abort_unless($pending && time() - (int) $request->session()->get('mfa_pending_at') < 600, 419, 'Sign in again to start a new verification.');
        $user = DB::transaction(function () use ($pending, $provider, $data) {
            $user = User::lockForUpdate()->findOrFail($pending);
            abort_unless($user->status === 'active' && $user->hasEnabledTwoFactorAuthentication(), 403);
            $valid = false;
            if (! empty($data['recovery_code'])) {
                foreach ($user->recoveryCodes() as $recovery) {
                    if (hash_equals($recovery, $data['recovery_code'])) {
                        $user->replaceRecoveryCode($recovery);
                        $valid = true;
                        break;
                    }
                }
            } elseif (! empty($data['code'])) {
                $valid = $provider->verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), $data['code']);
            }
            if (! $valid) {
                throw ValidationException::withMessages(['code' => 'The authenticator or recovery code is invalid.']);
            }

            return $user;
        });
        Auth::login($user, $request->session()->pull('mfa_remember', false));
        $request->session()->forget(['mfa_pending_id', 'mfa_pending_at']);
        $request->session()->regenerate();
        $request->session()->put('mfa_user_id', $user->id);

        return app(AuthController::class)->completeLogin($request);
    }

    private function password(Request $request): void
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
    }

    public function security(Request $request)
    {
        return view('auth.security', ['mode' => 'mfa', 'user' => $request->user()]);
    }

    public function enable(Request $request, EnableTwoFactorAuthentication $action)
    {
        $this->password($request);
        $action($request->user());
        $request->session()->put('mfa_setup_at', time());

        return redirect()->route('mfa.settings');
    }

    public function confirm(Request $request, ConfirmTwoFactorAuthentication $action)
    {
        $data = $request->validate(['code' => 'required|digits:6']);
        $action($request->user(), $data['code']);
        $request->session()->put('mfa_user_id', $request->user()->id);
        AuditLog::create(['company_id' => $request->session()->get('company_id'), 'user_id' => $request->user()->id, 'action' => 'mfa_enabled', 'ip_address' => $request->ip()]);

        return redirect()->route('mfa.settings')->with('mfa_codes', true)->with('ok', 'Authenticator enabled. Save your recovery codes securely.');
    }

    public function disable(Request $request, DisableTwoFactorAuthentication $action, TwoFactorAuthenticationProvider $provider)
    {
        $this->password($request);
        $data = $request->validate(['code' => 'required|digits:6']);
        abort_unless($provider->verify(Fortify::currentEncrypter()->decrypt($request->user()->two_factor_secret), $data['code']), 422, 'Invalid authenticator code.');
        $action($request->user());
        $request->session()->forget('mfa_user_id');
        AuditLog::create(['company_id' => $request->session()->get('company_id'), 'user_id' => $request->user()->id, 'action' => 'mfa_disabled', 'ip_address' => $request->ip()]);

        return back()->with('ok', 'Authenticator disabled.');
    }
}
