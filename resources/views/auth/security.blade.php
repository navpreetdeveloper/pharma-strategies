@extends('layouts.app')
@section('title', (['verify' => 'Verify work email', 'forgot' => 'Reset password', 'reset' => 'Choose a new password', 'challenge' => 'Two-step verification', 'mfa' => 'Sign-in security'][$mode] ?? 'Account security').' - Pharma Strategies')
@section('content')
<section class="nc-card nc-security-page">
@if($mode==='verify')
<h1 class="text-2xl font-bold">Verify your work email</h1><p class="nc-muted my-4">Open the verification link sent to {{ auth()->user()->email }} to activate workspace access.</p>
<form method="POST" action="{{ route('verification.send') }}">@csrf<button class="nc-btn">Resend verification email</button></form>
@elseif($mode==='forgot')
<h1 class="text-2xl font-bold">Reset your password</h1><form method="POST" action="{{ route('password.email') }}" class="grid gap-4 mt-4">@csrf<label>Work email<input class="nc-input" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required></label><button class="nc-btn">Send reset link</button><a href="{{ route('login') }}">Back to sign in</a></form>
@elseif($mode==='reset')
<h1 class="text-2xl font-bold">Choose a new password</h1><form method="POST" action="{{ route('password.update') }}" class="grid gap-4 mt-4">@csrf<input type="hidden" name="token" value="{{ $token }}"><label>Work email<input class="nc-input" name="email" type="email" value="{{ $email }}" required></label><label>New password<input class="nc-input" name="password" type="password" minlength="8" autocomplete="new-password" required></label><label>Confirm password<input class="nc-input" name="password_confirmation" type="password" minlength="8" autocomplete="new-password" required></label><button class="nc-btn">Reset password</button></form>
@elseif($mode==='challenge')
<h1 class="text-2xl font-bold">Two-step verification</h1><form method="POST" action="{{ route('mfa.challenge.confirm') }}" class="grid gap-4 mt-4">@csrf<label>Authenticator code<input class="nc-input" name="code" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code"></label><p class="nc-muted">Or use one unused recovery code.</p><label>Recovery code<input class="nc-input" name="recovery_code" autocomplete="off"></label><button class="nc-btn">Verify and sign in</button><a href="{{ route('login') }}">Start again</a></form>
@elseif($mode==='mfa')
<h1 class="text-2xl font-bold">Two-step sign-in</h1>
<p class="nc-muted mt-3">An authenticator app generates a six-digit code on your phone. After setup, enter that code after your password when signing in.</p>
@if($user->hasEnabledTwoFactorAuthentication())
<p class="nc-muted my-4">Your authenticator protects sign-in. Save recovery codes offline in a secure place.</p>
@if(session('mfa_codes'))<ul class="grid grid-cols-2 gap-2 my-4">@foreach($user->recoveryCodes() as $code)<li><code>{{ $code }}</code></li>@endforeach</ul>@endif
<form method="POST" action="{{ route('mfa.disable') }}" class="grid gap-4">@csrf @method('DELETE')<label>Current password<input class="nc-input" type="password" name="current_password" autocomplete="current-password" required></label><label>Authenticator code<input class="nc-input" name="code" inputmode="numeric" pattern="[0-9]{6}" required></label><button class="nc-btn-secondary">Turn off two-step sign-in</button></form>
@elseif($user->two_factor_secret && session('mfa_setup_at') && time()-session('mfa_setup_at')<600)
<p class="nc-muted my-4">Scan this code or enter the setup key in your authenticator.</p>
<div class="my-4">{!! $user->twoFactorQrCodeSvg() !!}</div><code class="block break-all">{{ \Laravel\Fortify\Fortify::currentEncrypter()->decrypt($user->two_factor_secret) }}</code>
<form method="POST" action="{{ route('mfa.confirm') }}" class="grid gap-4 mt-4">@csrf<label>Six-digit code<input class="nc-input" name="code" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" required></label><button class="nc-btn">Finish setup</button></form>
@else
<form method="POST" action="{{ route('mfa.enable') }}" class="grid gap-4 mt-4">@csrf<label>Current password<input class="nc-input" type="password" name="current_password" autocomplete="current-password" required></label><button class="nc-btn">Continue setup</button></form>
@endif
<a class="nc-btn-secondary inline-block mt-5" href="{{ route('profile.show') }}">Back to profile</a>
@endif
</section>
@endsection
