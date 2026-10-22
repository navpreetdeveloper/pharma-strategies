<?php

namespace App\Providers;

use App\Mail\PrivatePreviewTransport;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Fortify;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {
        Schema::defaultStringLength(191);
        Paginator::useTailwind();
        Password::defaults(fn () => Password::min(8)->mixedCase()->numbers());
        Mail::extend('private-preview', fn () => new PrivatePreviewTransport);
    }
}
