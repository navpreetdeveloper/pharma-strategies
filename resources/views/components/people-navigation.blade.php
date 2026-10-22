<nav class="nc-people-navigation" aria-label="People management">
    <a href="{{ route('platform.users.index') }}" @if(request()->routeIs('platform.users.*')) aria-current="page" @endif><x-icon name="people" size="18" /> All accounts</a>
    <a href="{{ route('platform.employees.index') }}" @if(request()->routeIs('admin.employees', 'platform.employees.*')) aria-current="page" @endif><x-icon name="building" size="18" /> Company access</a>
</nav>
