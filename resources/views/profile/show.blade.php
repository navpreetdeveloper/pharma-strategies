@extends('layouts.app')
@section('title', 'My profile · Pharma Strategies')
@section('content')
<div class="nc-shell nc-page">


    <div class="nc-page-header">
        <div>
            <h1>My profile</h1>
            <p class="nc-muted mt-1">{{ $user->isPlatformSuperAdmin() ? 'Manage your platform administrator account.' : 'Manage your profile and workplace access.' }}</p>
        </div>
    </div>

    <div class="grid lg:grid-cols-[320px_1fr] gap-5 mt-6">
        <div class="nc-card p-6">
            <div class="flex items-center gap-4">
                <div class="nc-profile-avatar">{{ strtoupper(substr($user->name, 0, 1)) }}</div>
                <div class="min-w-0">
                    <div class="font-semibold text-lg truncate">{{ $user->name }}</div>
                </div>
            </div>
            <div class="mt-6 space-y-4 text-sm">
                <div><div class="nc-label">Work email</div><div class="font-semibold break-all">{{ $user->email }}</div></div>
                <div><div class="nc-label">Access level</div><div class="font-semibold">{{ $user->isPlatformSuperAdmin() ? 'Platform Super Admin' : ucwords(str_replace('_',' ', $user->role() ?? 'Staff')) }}</div></div>
                @unless($user->isPlatformSuperAdmin())<div><div class="nc-label">Current company</div><div class="font-semibold">{{ $company?->name ?: 'Not selected' }}</div></div>@endunless
                <div><div class="nc-label">Account status</div><span class="inline-flex px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700">{{ ucfirst($user->status) }}</span></div>
            </div>
        </div>

        <div class="space-y-5">
            <div class="nc-card p-6">
                <h2 class="font-bold text-lg">Profile details</h2>
                <form method="POST" action="{{ route('profile.update') }}" class="grid sm:grid-cols-2 gap-4 mt-5">
                    @csrf @method('PATCH')
                    <label><span class="nc-label">Full name</span><input class="nc-input" name="name" value="{{ old('name',$user->name) }}" required></label>
                    @unless($user->isPlatformSuperAdmin())
                    <label><span class="nc-label">Employee ID</span><input class="nc-input" name="employee_id" value="{{ old('employee_id',$user->employee_id) }}"></label>
                    <label><span class="nc-label">Department</span><input class="nc-input" name="department" value="{{ old('department',$user->department) }}"></label>
                    <label><span class="nc-label">Job title</span><input class="nc-input" name="job_title" value="{{ old('job_title',$user->job_title) }}"></label>
                    @endunless
                    <div class="sm:col-span-2 flex justify-end"><button type="submit" class="nc-btn">Save profile</button></div>
                </form>
            </div>

            <div class="nc-card p-6">
                <div class="flex items-start justify-between gap-4 flex-wrap">
                    <div>
                        <h2 class="font-bold text-lg">Change password</h2>
                        <p class="text-sm nc-muted mt-1">{{ $user->must_change_password ? 'Change your temporary password before accessing the workspace.' : 'Use your current password to choose a new one.' }}</p>
                    </div>
                    @if($user->must_change_password)
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-800 border border-amber-100">Temporary password</span>
                    @endif
                </div>
                <form method="POST" action="{{ route('profile.password.update') }}" class="grid sm:grid-cols-2 gap-4 mt-5">
                    @csrf @method('PATCH')
                    <label class="sm:col-span-2"><span class="nc-label">Current password</span><input class="nc-input" name="current_password" type="password" autocomplete="current-password" required></label>
                    <label><span class="nc-label">New password</span><input class="nc-input" name="password" type="password" minlength="8" autocomplete="new-password" required><span class="nc-form-help">At least 8 characters, uppercase, lowercase and a number.</span></label>
                    <label><span class="nc-label">Confirm new password</span><input class="nc-input" name="password_confirmation" type="password" minlength="8" autocomplete="new-password" required></label>
                    <div class="sm:col-span-2 flex justify-end"><button type="submit" class="nc-btn">Change password</button></div>
                </form>
            </div>

            @unless($user->isPlatformSuperAdmin())
            <div class="nc-card p-6">
                <h2 class="font-bold text-lg">Your company access</h2>
                <p class="text-sm nc-muted mt-1">These are the companies where you have an employee account, with your role in each workspace.</p>
                @forelse($memberships as $membership)
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <div><div class="font-bold">{{ $membership->name }}</div><div class="text-sm nc-muted mt-1">{{ ucwords(str_replace('_',' ',$membership->pivot->role)) }}</div></div>
                        <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-bold {{ $membership->status === 'archived' || $membership->pivot->status !== 'active' ? 'bg-amber-50 text-amber-800' : 'bg-emerald-50 text-emerald-700' }}">{{ $membership->status === 'archived' ? 'Company archived' : ucfirst($membership->pivot->status) }}</span>
                    </div>
                @empty
                    <div class="mt-4 text-sm nc-muted">No company accounts are assigned.</div>
                @endforelse
            </div>

            @endunless

            @if($user->isPlatformSuperAdmin())
                <section class="nc-card p-6">
                    <h2 class="font-semibold text-lg">Platform administration</h2>
                    <p class="text-sm nc-muted mt-2">Your account manages company workspaces across the platform. You do not need an employee membership in each company.</p>
                </section>
            @endif
            <section class="nc-card p-6">
                <div class="nc-security-summary"><x-icon name="shield" size="24" /><div>
                    <h2 class="font-semibold text-lg">Two-step sign-in</h2>
                    <p class="text-sm nc-muted mt-2">Add a six-digit code from an authenticator app after your password when signing in.</p>
                    <span class="nc-security-state">{{ $user->hasEnabledTwoFactorAuthentication() ? 'Enabled on your account' : 'Not set up' }}</span>
                </div></div>
                <a class="nc-btn-secondary mt-4" href="{{ route('mfa.settings') }}">{{ $user->hasEnabledTwoFactorAuthentication() ? 'Manage two-step sign-in' : 'Set up two-step sign-in' }}</a>
            </section>
            @if(!$user->isPlatformSuperAdmin() && session()->has('company_id') && ($user->isSuperAdmin() || $user->hasAnyRole(['pharmacy_manager'])))
                <section class="nc-card p-6">
                    <h2 class="font-semibold text-lg">Company privacy settings</h2>
                    <p class="text-sm nc-muted mt-2">Choose how long this company keeps conversation history, who can review conversations, and who handles privacy enquiries.</p>
                    <a class="nc-btn-secondary mt-4" href="{{ route('admin.privacy') }}">Manage company privacy</a>
                </section>
            @endif
        </div>
    </div>
</div>

@endsection
