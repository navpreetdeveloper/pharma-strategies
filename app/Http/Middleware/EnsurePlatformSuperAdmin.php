<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsurePlatformSuperAdmin
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->isPlatformSuperAdmin(), 403, 'Platform Super Admin access is required.');

        return $next($request);
    }
}
