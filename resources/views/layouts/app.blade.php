<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover,interactive-widget=resizes-content">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0d5c8f">
    <meta name="application-name" content="Pharma Strategies">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="apple-touch-icon" href="{{ asset('icons/icon-192.png') }}">
    <meta name="description" content="@yield('description', (config('site.descriptions')[request()->route()?->getName()] ?? config('site.description')))">
    @if(auth()->check() || !request()->routeIs('home'))<meta name="robots" content="noindex, nofollow">@endif
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('icons/icon-192.png') }}" type="image/png">
    <title>@yield('title', $title ?? 'Pharma Strategies')</title>
    @vite(['resources/css/app.css', auth()->check() ? 'resources/js/app.js' : 'resources/js/public.js'])
</head>
<body class="min-h-screen {{ auth()->guest() ? 'nc-public-page' : 'nc-workspace-page' }} {{ request()->routeIs('chat') ? 'nc-chat-page' : '' }}">
<a class="nc-skip-link" href="#main-content">Skip to content</a>
<header class="nc-header">
    <div class="nc-shell nc-header-inner">
        <a href="{{ auth()->check() ? route(auth()->user()->landingRoute()) : route('home') }}" class="nc-brand" aria-label="Pharma Strategies home">
            <span class="nc-brand-mark">P</span>
            <span>Pharma <strong>Strategies</strong></span>
        </a>
        @auth
            <nav id="account-navigation" class="nc-nav" aria-label="Account navigation">
                @php($navCompanies = auth()->user()->isPlatformSuperAdmin() ? collect() : auth()->user()->companies()->wherePivot('status', 'active')->where('companies.status', 'active')->orderBy('companies.name')->get(['companies.id', 'companies.name']))
                @if(!auth()->user()->isPlatformSuperAdmin() || session()->has('company_id'))
                    <a class="nc-nav-btn nc-nav-btn-secondary" href="{{ route('chat') }}" @if(request()->routeIs('chat')) aria-current="page" @endif>Messages</a>
                @endif
                @if(auth()->user()->isPlatformSuperAdmin())
                    <a class="nc-nav-btn nc-nav-btn-secondary" href="{{ route('platform.users.index') }}" @if(request()->routeIs('platform.users.*', 'admin.employees', 'platform.employees.*')) aria-current="page" @endif>People</a>
                    <a class="nc-nav-btn nc-nav-btn-secondary" href="{{ route('platform.audit') }}" @if(request()->routeIs('platform.audit')) aria-current="page" @endif>Activity</a>
                    <a class="nc-nav-btn nc-nav-btn-secondary" href="{{ route('platform.companies.index') }}" @if(request()->routeIs('platform.companies.*')) aria-current="page" @endif>Companies</a>
                @elseif(in_array($navCompanies->firstWhere('id', (int) session('company_id'))?->pivot?->role, ['company_super_admin', 'pharmacy_manager'], true))
                    <a class="nc-nav-btn nc-nav-btn-secondary" href="{{ route('company.dashboard') }}" @if(request()->routeIs('company.dashboard', 'admin.dashboard')) aria-current="page" @endif>Workspace</a>
                    <a class="nc-nav-btn nc-nav-btn-secondary" href="{{ route('admin.employees') }}" @if(request()->routeIs('admin.employees')) aria-current="page" @endif>Employees</a>
                @endif
                @if(!auth()->user()->isPlatformSuperAdmin() && $navCompanies->count() > 1 && session()->has('company_id'))
                    <form method="POST" action="{{ route('company.switch') }}" class="nc-company-switch-form" data-action-navigation="true">
                        @csrf
                        <label class="sr-only" for="company-switcher">Switch company</label>
                        <select id="company-switcher" name="company_id" class="nc-input" aria-label="Switch company" onchange="this.form.requestSubmit()">
                            @foreach($navCompanies as $navCompany)
                                <option value="{{ $navCompany->id }}" @selected((int)session('company_id') === $navCompany->id)>{{ $navCompany->name }}</option>
                            @endforeach
                        </select>
                    </form>
                @endif
                <form method="POST" action="{{ route('logout') }}" class="m-0">
                    @csrf
                    <button type="submit" class="nc-nav-btn nc-nav-btn-secondary">Sign out</button>
                </form>
            </nav>
            <div class="nc-header-actions">
                <a href="{{ route('profile.show') }}" class="nc-user-chip" aria-label="Open your profile"><span class="nc-user-avatar-small">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span><span class="nc-user-name">{{ auth()->user()->isPlatformSuperAdmin() ? 'Platform Super Admin' : auth()->user()->name }}</span></a>
                <div class="nc-notification-wrap" id="notification-wrap">
                    <button type="button" id="notification-button" class="nc-nav-btn nc-icon-btn" aria-label="Notifications" aria-expanded="false">
                        <x-icon name="bell" />
                        @php($unreadNotificationCount = auth()->user()->importantNotifications()->whereNull('read_at')->count())
                        <span id="notification-count" class="nc-notification-count {{ $unreadNotificationCount ? '' : 'hidden' }}">{{ $unreadNotificationCount }}</span>
                    </button>
                    <div id="notification-menu" class="nc-notification-menu hidden" role="dialog" aria-label="Notifications">
                        <div class="nc-notification-head">
                            <div><strong>Notifications</strong><div class="text-xs nc-muted">Workplace activity</div></div>
                            <div class="nc-notification-actions"><button type="button" id="mark-all-notifications" class="nc-link-btn">Mark all read</button><button type="button" id="clear-notifications" class="nc-link-btn nc-link-btn-danger">Clear</button></div>
                        </div>
                        <div id="notification-list" class="nc-notification-list">
                            <div class="p-5 text-sm nc-muted">Loading notifications…</div>
                        </div>
                        <button type="button" id="older-notifications" class="nc-btn-secondary m-3 hidden">Load earlier notifications</button><div class="nc-notification-footer"><button type="button" id="enable-push" class="nc-btn w-full">Enable notifications</button>
                            <div id="notification-status" class="text-xs nc-muted mt-2 hidden" role="status"></div>
                            <button type="button" id="install-app" class="nc-btn-secondary w-full mt-2 hidden">Install Pharma Strategies</button></div>
                    </div>
                </div>
                <button id="navigation-toggle" type="button" class="nc-nav-btn nc-nav-btn-secondary nc-menu-toggle" aria-label="Toggle account navigation" aria-controls="account-navigation" aria-expanded="false"><x-icon name="menu" /><span class="nc-menu-label">Menu</span></button>
            </div>
        @else
            @unless(request()->routeIs('login'))
            <nav class="nc-nav nc-public-nav" aria-label="Public navigation">
                <a class="nc-nav-btn nc-nav-btn-secondary" href="{{ route('login') }}">Sign in</a>
            </nav>
            @endunless
        @endauth
    </div>
</header>
<main id="main-content" tabindex="-1">@yield('content')</main>
@unless(request()->routeIs('chat'))<x-site-footer />@endunless
@php($actionError = $errors->first() ?: $errors->confirmTwoFactorAuthentication->first())
@if($actionError)
    <script type="application/json" data-validation-errors>@json(array_merge($errors->getMessages(), $errors->confirmTwoFactorAuthentication->getMessages()))</script>
    <noscript><div class="nc-inline-form-summary" role="alert">{{ $actionError }}</div></noscript>
@elseif(session('ok') || session('status'))
    <div hidden data-action-feedback data-feedback-type="success">{{ session('ok') ?: session('status') }}</div>
    <noscript><div class="nc-shell nc-inline-success" role="status">{{ session('ok') ?: session('status') }}</div></noscript>
@endif
@auth
<script>
window.PharmaStrategiesConfig = {
    securityFingerprint: {{ Illuminate\Support\Js::from(App\Services\Accounts\SessionContext::fingerprint(request())) }},
    workspaceRole: {{ Illuminate\Support\Js::from(auth()->user()->isPlatformSuperAdmin() ? 'platform_super_admin' : $navCompanies->firstWhere('id', (int) session('company_id'))?->pivot?->role) }},
    pushPublicKey: @json(config('webpush.public_key')),
    pushEnabled: @json((bool) config('webpush.enabled')),
    userId: @json(auth()->id()),
    companyId: @json(session('company_id')),
    presenceEnabled: @json(!auth()->user()->isPlatformSuperAdmin()),
    workspaceAccessEnabled: {{ Illuminate\Support\Js::from(request()->routeIs('chat','admin.*','company.dashboard','platform.companies.*')) }},
    routes: {
        notifications: @json(route('notifications.index')),
        readAll: @json(route('notifications.read-all')),
        clear: @json(route('notifications.clear')),
        readBase: @json(url('/notifications')),
        subscribe: @json(route('push.subscribe')),
        unsubscribe: @json(route('push.unsubscribe')),
        heartbeat: @json(route('presence.heartbeat')),
        presence: @json(route('presence.index')),
    }
};
</script>
@endauth
</body>
</html>
