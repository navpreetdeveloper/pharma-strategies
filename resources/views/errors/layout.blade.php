<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="@yield('message')">
    <meta name="theme-color" content="#0d5c8f">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('icons/icon-192.png') }}" type="image/png">
    <title>@yield('title') - Pharma Strategies</title>
    @vite('resources/css/app.css')
</head>
<body class="nc-public-page">
    <a class="nc-skip-link" href="#main-content">Skip to content</a>
    <header class="nc-header"><div class="nc-shell nc-header-inner"><a class="nc-brand" href="{{ route('home') }}"><span class="nc-brand-mark" aria-hidden="true">P</span><span>Pharma <strong>Strategies</strong></span></a></div></header>
    <main id="main-content" class="nc-shell nc-error-page" tabindex="-1">
        <span class="nc-eyebrow">@yield('code')</span>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <a class="nc-btn" href="{{ route('home') }}">Return home</a>
    </main>
    <x-site-footer />
</body>
</html>
