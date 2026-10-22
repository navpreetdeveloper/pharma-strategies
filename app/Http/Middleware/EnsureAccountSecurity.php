<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAccountSecurity
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }
        abort_unless($user->status === 'active', 403, 'This account is suspended.');
        if (! $user->meetsEmailAccessPolicy() && ! $request->routeIs('verification.*', 'logout')) {
            return $request->expectsJson() ? response()->json(['message' => 'Verify your work email first.'], 403) : redirect()->route('verification.notice');
        }
        if ($user->hasEnabledTwoFactorAuthentication() && (int) $request->session()->get('mfa_user_id') !== $user->id && ! $request->routeIs('logout')) {
            $request->session()->put(['mfa_pending_id' => $user->id, 'mfa_pending_at' => time()]);
            auth()->logout();

            return $request->expectsJson() ? response()->json(['message' => 'Two-step verification is required.'], 401) : redirect()->route('mfa.challenge');
        }
        if ($user->must_change_password && ! $request->routeIs('profile.show', 'profile.password.update', 'logout', 'verification.*')) {
            return $request->expectsJson() ? response()->json(['message' => 'Change your temporary password first.'], 403) : redirect()->route('profile.show')->with('ok', 'Change your temporary password to continue.');
        }
        if (config('security.require_privileged_mfa') && ($user->isPlatformSuperAdmin() || $user->hasAnyRole(['company_super_admin', 'pharmacy_manager'])) && ! $user->hasEnabledTwoFactorAuthentication() && ! $request->routeIs('mfa.*', 'profile.show', 'profile.password.update', 'logout', 'verification.*')) {
            return $request->expectsJson() ? response()->json(['message' => 'Set up an authenticator before workspace access.'], 403) : redirect()->route('mfa.settings');
        }

        return $next($request);
    }
}
