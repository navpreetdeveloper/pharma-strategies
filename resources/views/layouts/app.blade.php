<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0b3554">
    <meta name="application-name" content="Pharma Strategies">
    <meta name="reverb-app-key" content="{{ config('reverb.apps.apps.0.key') }}">
    <meta name="reverb-host" content="{{ request()->getHost() }}">
    <meta name="reverb-port" content="{{ request()->isSecure() ? 443 : config('reverb.apps.apps.0.options.port', 8081) }}">
    <meta name="reverb-scheme" content="{{ request()->isSecure() ? 'https' : 'http' }}">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="apple-touch-icon" href="{{ asset('icons/icon-192.png') }}">
    <title>{{ $title ?? 'Pharma Strategies' }}</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
</head>
<body class="min-h-screen">
<header class="nc-header">
    <div class="nc-shell nc-header-inner">
        <a href="{{ auth()->check() ? route('chat') : route('home') }}" class="nc-brand" aria-label="Pharma Strategies home">
            <span class="nc-brand-mark">P</span>
            <span>Pharma <strong>Strategies</strong></span>
        </a>
        @auth
            <nav class="nc-nav" aria-label="Account navigation">
                <a href="{{ route('profile.show') }}" class="nc-user-chip" aria-label="Open your profile"><span class="nc-user-avatar-small">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span><span class="nc-user-name">{{ auth()->user()->name }}</span></a>
                <div class="nc-notification-wrap" id="notification-wrap">
                    <button type="button" id="notification-button" class="nc-nav-btn nc-icon-btn" aria-label="Notifications" aria-expanded="false">
                        <span aria-hidden="true">🔔</span>
                        <span id="notification-count" class="nc-notification-count {{ auth()->user()->unreadNotifications()->count() ? '' : 'hidden' }}">{{ auth()->user()->unreadNotifications()->count() }}</span>
                    </button>
                    <div id="notification-menu" class="nc-notification-menu hidden" role="dialog" aria-label="Notifications">
                        <div class="nc-notification-head">
                            <div><strong>Notifications</strong><div class="text-xs nc-muted">Workplace activity</div></div>
                            <div class="nc-notification-actions"><button type="button" id="mark-all-notifications" class="nc-link-btn">Mark all read</button><button type="button" id="clear-notifications" class="nc-link-btn nc-link-btn-danger">Clear</button></div>
                        </div>
                        <div id="notification-list" class="nc-notification-list">
                            <div class="p-5 text-sm nc-muted">Loading notifications…</div>
                        </div>
                        <div class="nc-notification-footer"><button type="button" id="enable-push" class="nc-btn w-full">Enable notifications</button>
                            <div id="notification-status" class="text-xs nc-muted mt-2 hidden" role="status"></div>
                            <button type="button" id="install-app" class="nc-btn-secondary w-full mt-2 hidden">Install Pharma Strategies</button></div>
                    </div>
                </div>
                @if(auth()->user()->isSuperAdmin() || auth()->user()->hasAnyRole(['pharmacy_manager']))
                    <a class="nc-nav-btn nc-nav-btn-secondary" href="{{ route('company.dashboard') }}">Workspace</a>
                    <a class="nc-nav-btn nc-nav-btn-secondary" href="{{ route('admin.employees') }}">Admin</a>
                @endif
                <form method="POST" action="{{ route('logout') }}" class="m-0">
                    @csrf
                    <button type="submit" class="nc-nav-btn nc-nav-btn-secondary">Sign out</button>
                </form>
            </nav>
        @else
            <nav class="nc-nav" aria-label="Public navigation">
                <a class="nc-nav-btn nc-nav-btn-secondary" href="{{ route('login') }}">Sign in</a>
                <a class="nc-nav-btn nc-nav-btn-primary" href="{{ route('register') }}">Get started</a>
            </nav>
        @endauth
    </div>
</header>
<main>@yield('content')</main>
@auth
<script>
window.PharmaStrategiesConfig = {
    pushPublicKey: @json(config('webpush.public_key')),
    pushEnabled: @json((bool) config('webpush.enabled')),
    userId: @json(auth()->id()),
    companyId: @json(session('company_id')),
    routes: {
        notifications: @json(route('notifications.index')),
        readAll: @json(route('notifications.read-all')),
        clear: @json(route('notifications.clear')),
        readBase: @json(url('/notifications')),
        subscribe: @json(route('push.subscribe')),
        unsubscribe: @json(route('push.unsubscribe')),
        heartbeat: @json(route('presence.heartbeat')),
    }
};
</script>
@endauth
</body>
</html>
