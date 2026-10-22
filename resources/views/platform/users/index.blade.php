@extends('layouts.app')
@section('title', 'People - Pharma Strategies')
@section('content')
<div class="nc-shell nc-page nc-employees-page nc-user-directory">
    <header class="nc-page-header"><div><h1>People</h1><p class="nc-muted mt-2">Sign-in accounts, workplace access and recovery.</p></div></header>
    <x-people-navigation />
    <form method="GET" action="{{ route('platform.users.index') }}" class="nc-directory-filters nc-card">
        <label><span class="nc-label">Find an account</span><input name="q" class="nc-input" type="search" value="{{ request('q') }}" maxlength="120"></label>
        <label><span class="nc-label">Company</span><select name="company_id" class="nc-input" onchange="this.form.requestSubmit()"><option value="">All companies</option>@foreach($companies as $company)<option value="{{ $company->id }}" @selected(request('company_id') == $company->id)>{{ $company->name }}</option>@endforeach</select></label>
        <label><span class="nc-label">Account status</span><select name="status" class="nc-input"><option value="">All accounts</option><option value="active" @selected(request('status') === 'active')>Active</option><option value="suspended" @selected(request('status') === 'suspended')>Suspended</option></select></label>
        <button type="submit" class="nc-btn">Search</button><a class="nc-btn-secondary" href="{{ route('platform.users.index') }}">Clear</a>
    </form>
    <section class="nc-card overflow-hidden">
        <div class="nc-directory-heading"><h2>All accounts</h2><span>{{ $users->total() }} accounts</span></div>
        <div class="nc-table-scroll"><table class="nc-table nc-responsive-table"><caption class="sr-only">Platform accounts, company access and recovery controls</caption><thead><tr><th scope="col">Account</th><th scope="col">Company access</th><th scope="col">Sign-in security</th><th scope="col">Actions</th></tr></thead>
        <tbody>@forelse($users as $user)
        @php($hasWorkspace = $user->companies->contains(fn ($company) => $company->status === 'active' && $company->pivot->status === 'active'))
        <tr>
            <td class="nc-table-primary" data-label="Account"><div class="nc-account-person"><span class="nc-employee-avatar" aria-hidden="true">{{ Illuminate\Support\Str::upper(Illuminate\Support\Str::substr($user->name, 0, 1)) }}</span><div><strong>{{ $user->name }}</strong><small><a class="nc-contact-link" href="mailto:{{ $user->email }}">{{ $user->email }}</a></small><span class="nc-account-status {{ $user->status === 'active' ? 'is-active' : 'is-suspended' }}">{{ ucfirst($user->status) }}</span>@if($user->platform_role === 'super_admin')<small>Platform Super Admin</small>@endif</div></div></td>
            <td data-label="Company access">@if($user->platform_role === 'super_admin')<strong>Platform administration</strong><small>No employee membership required.</small>@else @forelse($user->companies as $company)<div class="nc-user-membership"><strong>{{ $company->name }}</strong><small>{{ ucwords(str_replace('_', ' ', $company->pivot->role)) }}</small><small>{{ $company->status === 'archived' ? 'Company archived' : ucfirst($company->pivot->status).' company access' }}</small></div>@empty<strong class="nc-access-warning">No company access</strong>@endforelse @unless($hasWorkspace)<small class="nc-access-explanation">Cannot enter a workspace. Resetting the password will not restore company access.</small>@endunless @endif</td>
            <td data-label="Sign-in security"><span>{{ $user->hasEnabledTwoFactorAuthentication() ? 'Authenticator enabled' : 'Password sign-in' }}</span>@if($user->must_change_password)<small>Password change required</small>@endif</td>
            <td class="nc-table-actions" data-label="Actions">
                @if($user->platform_role === 'super_admin')<span class="nc-muted">Protected administrator</span>
                @else<div class="nc-account-actions">
                    @if($user->status === 'active')
                        <button type="button" class="nc-btn-secondary" data-employee-editor="reset-user-{{ $user->id }}" aria-haspopup="dialog">Reset password</button>
                        @if(!empty($recoverableAccess[$user->id]))<button type="button" class="nc-btn-secondary" data-employee-editor="restore-user-{{ $user->id }}" aria-haspopup="dialog">Restore access</button>@endif
                    @endif
                    <button type="button" class="nc-btn-danger" data-employee-editor="delete-user-{{ $user->id }}" aria-haspopup="dialog">Delete account</button>
                </div>@endif
            </td>
        </tr>@empty<tr><td colspan="4" class="nc-empty-state">No users match these filters.</td></tr>@endforelse</tbody></table></div>
        <div class="p-4 border-t">{{ $users->links() }}</div>
    </section>
    @include('platform.users.password-dialogs')
    @include('platform.users.lifecycle-dialogs')
</div>
@endsection
