<?php

namespace App\Http\Controllers;

use App\Models\{User, Company, PrivacySetting, AuditLog};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function home()
    {
        return auth()->check() ? redirect()->route('chat') : view('landing');
    }

    public function showLogin()
    {
        return view('auth.login');
    }

    public function showRegister()
    {
        return view('auth.register');
    }

    public function login(Request $r)
    {
        $d = $r->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (!auth()->attempt([
            'email' => $d['email'],
            'password' => $d['password'],
            'status' => 'active',
        ], $r->boolean('remember'))) {
            return back()->withErrors(['email' => 'The sign-in details are incorrect.'])->withInput($r->only('email'));
        }

        $r->session()->regenerate();
        $u = $r->user();
        $c = $u->companies()->wherePivot('status', 'active')->first();

        if (!$c && !$u->isPlatformSuperAdmin()) {
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

        return redirect()->intended(route('chat'));
    }

    public function register(Request $r)
    {
        $d = $r->validate([
            'company_name' => 'required|string|max:180',
            'province' => 'required|string|max:40',
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190|unique:users,email',
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $result = DB::transaction(function () use ($d, $r) {
            $c = Company::create([
                'uuid' => (string) Str::uuid(),
                'name' => $d['company_name'],
                'legal_name' => $d['company_name'],
                'province' => $d['province'],
            ]);

            $u = User::create([
                'name' => $d['name'],
                'email' => $d['email'],
                'password' => Hash::make($d['password']),
                'status' => 'active',
                'job_title' => 'Company Super Admin',
            ]);

            $c->users()->attach($u->id, [
                'role' => 'company_super_admin',
                'status' => 'active',
            ]);

            PrivacySetting::create([
                'company_id' => $c->id,
                'privacy_contact_email' => $u->email,
            ]);

            AuditLog::create([
                'company_id' => $c->id,
                'user_id' => $u->id,
                'action' => 'company_registered',
                'ip_address' => $r->ip(),
            ]);

            return [$c, $u];
        });

        [$c, $u] = $result;
        // Establish the authenticated session before redirecting into the company-protected admin area.
        Auth::login($u);
        $r->session()->regenerate();
        $r->session()->put('company_id', $c->id);
        $r->session()->save();

        return redirect('/admin')->with('ok', 'Your company workspace has been created successfully.');
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

        auth()->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('login');
    }
}
