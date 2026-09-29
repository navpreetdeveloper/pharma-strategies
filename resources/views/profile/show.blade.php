@extends('layouts.app')
@section('content')
<div class="nc-shell py-7">
    <a href="{{ route('chat') }}" class="nc-back-btn mb-5" aria-label="Back to chat">← <span>Back</span></a>

    @if(session('ok'))
        <div class="nc-alert mt-4 bg-emerald-50 text-emerald-800 border border-emerald-100">{{ session('ok') }}</div>
    @endif
    @if($errors->any())
        <div class="nc-alert mt-4 bg-red-50 text-red-800 border border-red-100">{{ $errors->first() }}</div>
    @endif

    <div class="flex items-start justify-between gap-4 flex-wrap">
        <div>
            <div class="text-xs font-bold tracking-wider text-[#0d7aa8] uppercase">Account</div>
            <h1 class="text-3xl font-extrabold mt-1">My profile</h1>
            <p class="nc-muted mt-1">Your professional identity and company access information.</p>
        </div>
    </div>

    <div class="grid lg:grid-cols-[320px_1fr] gap-5 mt-6">
        <div class="nc-card p-6">
            <div class="flex items-center gap-4">
                <div class="nc-profile-avatar">{{ strtoupper(substr($user->name, 0, 1)) }}</div>
                <div class="min-w-0">
                    <div class="font-extrabold text-lg truncate">{{ $user->name }}</div>
                    <div class="text-sm nc-muted truncate">{{ ucwords(str_replace('_',' ', $user->role() ?? 'Platform Super Admin')) }}</div>
                </div>
            </div>
            <div class="mt-6 space-y-4 text-sm">
                <div><div class="nc-label">Work email</div><div class="font-semibold break-all">{{ $user->email }}</div></div>
                <div><div class="nc-label">Company</div><div class="font-semibold">{{ $company?->name ?: 'Platform' }}</div></div>
                <div><div class="nc-label">Account status</div><span class="inline-flex px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700">{{ ucfirst($user->status) }}</span></div>
            </div>
        </div>

        <div class="space-y-5">
            <div class="nc-card p-6">
                <h2 class="font-bold text-lg">Professional information</h2>
                <p class="text-sm nc-muted mt-1">Only workplace information is stored here. Personal phone numbers and home addresses are not required.</p>
                <form method="POST" action="{{ route('profile.update') }}" class="grid sm:grid-cols-2 gap-4 mt-5">
                    @csrf @method('PATCH')
                    <label><span class="nc-label">Full name</span><input class="nc-input" name="name" value="{{ old('name',$user->name) }}" required></label>
                    <label><span class="nc-label">Employee ID</span><input class="nc-input" name="employee_id" value="{{ old('employee_id',$user->employee_id) }}"></label>
                    <label><span class="nc-label">Department</span><input class="nc-input" name="department" value="{{ old('department',$user->department) }}"></label>
                    <label><span class="nc-label">Job title</span><input class="nc-input" name="job_title" value="{{ old('job_title',$user->job_title) }}"></label>
                    <div class="sm:col-span-2 flex justify-end"><button type="submit" class="nc-btn">Save profile</button></div>
                </form>
            </div>

            <div class="nc-card p-6">
                <div class="flex items-start justify-between gap-4 flex-wrap">
                    <div>
                        <h2 class="font-bold text-lg">Password & account security</h2>
                        <p class="text-sm nc-muted mt-1">Change your workplace password at any time. Temporary passwords provided by an administrator should be replaced here.</p>
                    </div>
                    @if($user->must_change_password)
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-800 border border-amber-100">Temporary password</span>
                    @endif
                </div>
                <form method="POST" action="{{ route('profile.password.update') }}" class="grid sm:grid-cols-2 gap-4 mt-5">
                    @csrf @method('PATCH')
                    <label class="sm:col-span-2"><span class="nc-label">Current password</span><input class="nc-input" name="current_password" type="password" autocomplete="current-password" required></label>
                    <label><span class="nc-label">New password</span><input class="nc-input" name="password" type="password" autocomplete="new-password" required></label>
                    <label><span class="nc-label">Confirm new password</span><input class="nc-input" name="password_confirmation" type="password" autocomplete="new-password" required></label>
                    <div class="sm:col-span-2 flex justify-end"><button type="submit" class="nc-btn">Change password</button></div>
                </form>
            </div>

            <div class="nc-card p-6">
                <h2 class="font-bold">Privacy & security</h2>
                <p class="text-sm nc-muted mt-1">Company privacy controls are managed by authorised administrators.</p>
                @if($user->isSuperAdmin() || $user->hasAnyRole(['pharmacy_manager']))
                    <a href="{{ route('admin.privacy') }}" class="nc-btn-secondary mt-4">Open company privacy settings</a>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
