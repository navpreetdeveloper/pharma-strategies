<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventSensitiveCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->user() || $request->routeIs('login', 'logout', 'mfa.*', 'password.*')) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }

        return $response;
    }
}
