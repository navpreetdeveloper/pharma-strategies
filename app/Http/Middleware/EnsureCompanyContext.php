<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;

class EnsureCompanyContext
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        abort_if($user && $user->status !== 'active', 403, 'This account is suspended.');
        if (! $user) {
            return $next($request);
        }

        if (! $request->session()->has('company_id')) {
            $company = $user->companies()
                ->wherePivot('status', 'active')
                ->where('companies.status', 'active')
                ->first();

            if ($company) {
                $request->session()->put('company_id', $company->id);
            } elseif (! $user->isPlatformSuperAdmin()) {
                abort(403, 'No active company workspace is assigned to this account.');
            }
        }

        if ($request->session()->has('company_id')) {
            $company = Company::query()
                ->whereKey($request->session()->get('company_id'))
                ->where('status', 'active')
                ->first();

            if (! $company) {
                $request->session()->forget('company_id');
                abort(403, 'This company workspace is not active.');
            }

            if (! $user->isPlatformSuperAdmin()) {
                $allowed = $user->companies()
                    ->where('companies.id', $company->id)
                    ->wherePivot('status', 'active')
                    ->exists();

                if (! $allowed) {
                    abort(403, 'You are not authorised to access this company workspace.');
                }
            }
        }

        return $next($request);
    }
}
