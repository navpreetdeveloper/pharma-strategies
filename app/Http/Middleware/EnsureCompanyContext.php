<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureCompanyContext
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        if (!$request->session()->has('company_id')) {
            $company = $user->companies()->wherePivot('status', 'active')->first();

            if ($company) {
                $request->session()->put('company_id', $company->id);
            } elseif (!$user->isPlatformSuperAdmin()) {
                abort(403, 'No active company workspace is assigned to this account.');
            }
        }

        if (!$user->isPlatformSuperAdmin() && $request->session()->has('company_id')) {
            $allowed = $user->companies()
                ->where('companies.id', $request->session()->get('company_id'))
                ->wherePivot('status', 'active')
                ->exists();

            if (!$allowed) {
                abort(403, 'You are not authorised to access this company workspace.');
            }
        }

        return $next($request);
    }
}
