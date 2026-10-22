<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $r)
    {
        $r->session()->forget(['mfa_pending_id', 'mfa_pending_at', 'mfa_remember']);
        $d = $r->validate([
            'email' => 'required|email|max:190',
            'password' => 'required|string|max:4096',
        ], ['email.email' => 'Email is invalid.', 'email.max' => 'Email is invalid.', 'email.required' => 'Enter your email address.', 'password.required' => 'Enter your password.']);

        $user = User::where('email', strtolower($d['email']))->first();
        if (! $user) {
            return back()->withErrors(['email' => 'Email is invalid.'])->withInput($r->only('email'));
        }
        if (! Hash::check($d['password'], $user->password)) {
            return back()->withErrors(['password' => 'Wrong password.'])->withInput($r->only('email'));
        }
        if ($user->status !== 'active') {
            return back()->withErrors(['email' => 'This account is suspended. Contact your administrator.'])->withInput($r->only('email'));
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $r->session()->put(['mfa_pending_id' => $user->id, 'mfa_pending_at' => time(), 'mfa_remember' => $r->boolean('remember')]);

            return redirect()->route('mfa.challenge');
        }
        Auth::login($user, $r->boolean('remember'));

        return $this->completeLogin($r);
    }

    public function completeLogin(Request $r)
    {

        $r->session()->regenerate();
        $u = $r->user();
        $c = $u->companies()
            ->wherePivot('status', 'active')
            ->where('companies.status', 'active')
            ->first();

        if (! $c && ! $u->isPlatformSuperAdmin()) {
            auth()->logout();

            return back()->withErrors(['email' => 'Your account is not active in a company workspace.']);
        }

        if ($c) {
            $r->session()->put('company_id', $c->id);
        } else {
            $r->session()->forget('company_id');
        }

        AuditLog::create([
            'company_id' => $c?->id,
            'user_id' => $u->id,
            'action' => 'login',
            'ip_address' => $r->ip(),
        ]);

        $landing = $u->landingRoute();
        $r->session()->forget('url.intended');

        return redirect()->route($landing)->with('ok', 'Signed in.');
    }

    public function logout(Request $r)
    {
        if ($r->user()) {
            AuditLog::create([
                'company_id' => $r->session()->get('company_id'),
                'user_id' => $r->user()->id,
                'action' => 'logout',
                'ip_address' => $r->ip(),
            ]);
        }

        if ($r->user() && $endpointHash = $r->session()->get('push_endpoint_hash')) {
            PushSubscription::where('user_id', $r->user()->id)->where('endpoint_hash', $endpointHash)->delete();
        }
        auth()->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        if ($r->expectsJson()) {
            $r->session()->flash('ok', 'Signed out.');

            return response()->json(['ok' => true, 'redirect' => route('login')]);
        }

        return redirect()->route('login')->with('ok', 'Signed out.');
    }
}
