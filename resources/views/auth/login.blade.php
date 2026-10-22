@extends('layouts.app')
@section('title', 'Sign in · Pharma Strategies')
@section('content')
<div class="nc-shell nc-auth-layout">
    <aside class="nc-auth-intro">
        <h2>Pharma Strategies</h2>
        <p>Your company workspace.</p>
    </aside>
    <section class="nc-card nc-auth-card" aria-labelledby="login-heading">
        <h1 id="login-heading">Sign in</h1>
        <p class="nc-muted">Enter your work email and password.</p>
        <form method="POST" action="{{ route('login.submit') }}" class="nc-auth-form">
            @csrf
            <label><span class="nc-label">Work email</span><input class="nc-input" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus></label>
            <div>
                <label class="nc-label" for="login-password">Password</label>
                <div class="nc-password-field">
                    <input id="login-password" class="nc-input" name="password" type="password" autocomplete="current-password" required>
                    <button type="button" class="nc-password-toggle" data-password-toggle aria-controls="login-password" aria-label="Show password" aria-pressed="false" hidden>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" />
                            <circle cx="12" cy="12" r="3" />
                            <path data-password-slash d="m3 3 18 18" hidden />
                        </svg>
                    </button>
                </div>
            </div>
            <div class="nc-auth-options"><label class="nc-checkbox"><input type="checkbox" name="remember" @checked(old('remember'))> Remember me</label><a class="nc-text-link" href="{{ route('password.request') }}">Forgot password?</a></div>
            <button type="submit" class="nc-btn w-full">Sign in <x-icon size="18" /></button>
        </form>
    </section>
</div>
@endsection
