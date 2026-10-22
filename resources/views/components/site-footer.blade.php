<footer class="nc-shell nc-site-footer">
    <a href="{{ route('home') }}">Pharma Strategies</a>
    <nav aria-label="Footer navigation">
        <a href="{{ route('privacy') }}">Privacy</a>
        @if(config('site.support_email'))<a href="mailto:{{ config('site.support_email') }}">{{ config('site.support_email') }}</a>@endif
        @if(config('site.support_phone'))<a href="tel:{{ preg_replace('/[^+0-9]/', '', config('site.support_phone')) }}">{{ config('site.support_phone') }}</a>@endif
    </nav>
    <small>&copy; {{ now()->year }} Pharma Strategies. All rights reserved.</small>
</footer>
