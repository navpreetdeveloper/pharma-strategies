<?php

namespace App\Services\Accounts;

use App\Enums\CompanyRole;
use Illuminate\Http\Request;

class SessionContext
{
    /** Fresh identity/scope metadata for validating private browser history snapshots. */
    public static function make(Request $request): array
    {
        $user = $request->user();
        $companyId = $request->session()->get('company_id');
        $role = $user->isPlatformSuperAdmin() ? 'platform_super_admin' : $user->companies()
            ->where('companies.id', $companyId)->where('companies.status', 'active')
            ->wherePivot('status', 'active')->first(['companies.id'])?->pivot?->role;
        $landing = $user->must_change_password ? 'profile.show'
            : ($user->isPlatformSuperAdmin() ? 'platform.companies.index'
                : (in_array($role, [CompanyRole::Administrator->value, CompanyRole::Manager->value], true) ? 'company.dashboard' : 'chat'));

        return ['user_id' => $user->id, 'company_id' => $companyId, 'workspace_role' => $role, 'security_fingerprint' => self::fingerprint($request), 'landing_url' => route($landing)];
    }

    public static function fingerprint(Request $request): string
    {
        $user = $request->user();

        // This is UI consistency metadata, never an authentication credential.
        // Hashing the CSRF nonce distinguishes a new login without exposing a session ID.
        return hash('sha256', json_encode([
            hash('sha256', $request->session()->token()),
            $user->status, (bool) $user->must_change_password,
            $user->hasVerifiedEmail(), $user->hasEnabledTwoFactorAuthentication(),
            (int) $request->session()->get('mfa_user_id') === $user->id,
            (bool) config('security.require_email_verification'),
            (bool) config('security.require_privileged_mfa'),
        ], JSON_THROW_ON_ERROR));
    }
}
