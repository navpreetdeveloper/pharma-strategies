<?php

use App\Http\Middleware\EnsureAccountSecurity;
use App\Http\Middleware\EnsureCompanyContext;
use App\Http\Middleware\EnsurePlatformSuperAdmin;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\PreventSensitiveCaching;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'auth', 'auth.session', 'security']])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [PreventSensitiveCaching::class]);
        $middleware->alias(['security' => EnsureAccountSecurity::class, 'company' => EnsureCompanyContext::class, 'role' => EnsureRole::class, 'platform' => EnsurePlatformSuperAdmin::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // SQL exception messages embed bindings, including private chat bodies.
        $exceptions->report(function (QueryException $error) {
            Log::error('Database operation failed.', ['exception_type' => get_class($error), 'sql_state' => (string) $error->getCode()]);

            return false;
        });
        $exceptions->dontFlash(['admin_password', 'admin_password_confirmation', 'code', 'recovery_code', 'token']);
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $error) => $request->is('api/*') || $request->expectsJson());
        $exceptions->respond(function (Response $response) {
            if ($response->getStatusCode() >= 500 && (request()->is('api/*') || request()->expectsJson())) {
                return response()->json(['message' => 'Something went wrong. Please try again.', 'code' => 'server_error'], $response->getStatusCode(), ['Cache-Control' => 'no-store']);
            }

            return $response;
        });
    })->create();
