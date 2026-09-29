@extends('layouts.app')
@section('content')
<div class="nc-shell py-7">
    <div class="flex items-start justify-between gap-4 flex-wrap">
        <div>
            <div class="text-xs font-bold tracking-wider text-[#0d7aa8] uppercase">Administration</div>
            <h1 class="text-3xl font-extrabold mt-1">Employees & access</h1>
            <p class="nc-muted mt-1">Manage company accounts, workplace roles and account status.</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.dashboard') }}" class="nc-back-btn" aria-label="Back to workspace" data-history-back>← <span>Back</span></a>
            <a href="{{ route('admin.dashboard') }}" class="nc-btn-secondary">Dashboard</a>
            <a href="{{ route('chat') }}" class="nc-btn-secondary">Back to chat</a>
        </div>
    </div>

    @if(session('ok'))
        <div class="nc-alert mt-5 bg-emerald-50 text-emerald-800 border border-emerald-100">{{ session('ok') }}</div>
    @endif
    @if($errors->any())
        <div class="nc-alert mt-5 bg-red-50 text-red-800 border border-red-100">{{ $errors->first() }}</div>
    @endif

    <div class="nc-card p-5 mt-6">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <div><h2 class="font-bold">Create employee account</h2><p class="text-sm nc-muted mt-1">Use a company work identity. A personal phone number is not required.</p></div>
        </div>
        <form method="POST" action="{{ route('admin.employees.store') }}" class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-5">
            @csrf
            <label><span class="nc-label">Full name</span><input class="nc-input" name="name" required></label>
            <label><span class="nc-label">Work email</span><input class="nc-input" name="email" type="email" required></label>
            <label><span class="nc-label">Employee ID</span><input class="nc-input" name="employee_id"></label>
            <label><span class="nc-label">Department</span><input class="nc-input" name="department"></label>
            <label><span class="nc-label">Job title</span><input class="nc-input" name="job_title"></label>
            <label><span class="nc-label">Role</span><select class="nc-input" name="role">
                @foreach(['staff','pharmacist','pharmacy_manager','pharmacy_technician','pharmacy_assistant'] as $role)<option value="{{ $role }}">{{ ucwords(str_replace('_',' ',$role)) }}</option>@endforeach
                @if(auth()->user()->isSuperAdmin())<option value="company_super_admin">Company Super Admin</option>@endif
            </select></label>
            <label><span class="nc-label">Temporary password</span><input class="nc-input" name="password" type="password" required></label>
            <div class="flex items-end"><button type="submit" class="nc-btn w-full">Create account</button></div>
        </form>
    </div>

    <div class="nc-card mt-6 overflow-hidden">
        <div class="px-5 py-4 border-b flex items-center justify-between gap-3">
            <div><h2 class="font-bold">Company accounts</h2><p class="text-sm nc-muted mt-1">{{ $employees->total() }} account(s)</p></div>
        </div>
        <div class="overflow-x-auto">
            <table class="nc-table w-full text-sm min-w-[980px]">
                <thead><tr><th class="text-left">Employee</th><th class="text-left">Role</th><th class="text-left">Department</th><th class="text-left">Status</th><th class="text-left">Actions</th></tr></thead>
                <tbody>
                @foreach($employees as $u)
                    <tr>
                        <td>
                            <div class="font-bold">{{ $u->name }}</div>
                            <div class="text-xs nc-muted mt-1">{{ $u->email }} @if($u->employee_id) · ID {{ $u->employee_id }} @endif</div>
                        </td>
                        <td>{{ ucwords(str_replace('_',' ',$u->pivot->role)) }}</td>
                        <td>{{ $u->department ?: '—' }}</td>
                        <td><span class="inline-flex px-2 py-1 rounded-full text-xs font-bold {{ $u->pivot->status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800' }}">{{ ucfirst($u->pivot->status) }}</span></td>
                        <td>
                            <details class="group">
                                <summary class="nc-btn-secondary inline-flex cursor-pointer list-none">Manage</summary>
                                <div class="mt-3 p-3 rounded-xl border bg-slate-50 space-y-3">
                                    <form method="POST" action="{{ route('admin.employees.update',$u) }}" class="grid sm:grid-cols-2 gap-2">
                                        @csrf @method('PATCH')
                                        <input class="nc-input" name="name" value="{{ $u->name }}" required>
                                        <input class="nc-input" name="department" value="{{ $u->department }}" placeholder="Department">
                                        <input class="nc-input" name="job_title" value="{{ $u->job_title }}" placeholder="Job title">
                                        <select class="nc-input" name="role">
                                            @foreach(['staff','pharmacist','pharmacy_manager','pharmacy_technician','pharmacy_assistant'] as $role)<option value="{{ $role }}" @selected($u->pivot->role === $role)>{{ ucwords(str_replace('_',' ',$role)) }}</option>@endforeach
                                            @if(auth()->user()->isSuperAdmin())<option value="company_super_admin" @selected($u->pivot->role === 'company_super_admin')>Company Super Admin</option>@endif
                                        </select>
                                        <select class="nc-input" name="status">
                                            <option value="active" @selected($u->pivot->status === 'active')>Active</option>
                                            <option value="suspended" @selected($u->pivot->status === 'suspended')>Suspended</option>
                                        </select>
                                        <button type="submit" class="nc-btn">Save changes</button>
                                    </form>

                                    @if(auth()->user()->isSuperAdmin() && $u->id !== auth()->id())
                                        <div class="pt-2 border-t flex flex-wrap items-center justify-between gap-2">
                                            <div><div class="text-sm font-bold text-red-800">Permanent account deletion</div><div class="text-xs text-red-700 mt-1">This removes the user account and associated account data. It cannot be undone.</div></div>
                                            <form method="POST" action="{{ route('admin.employees.delete',$u) }}" onsubmit="return confirm('Permanently delete {{ addslashes($u->name) }}? This cannot be undone.');">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="nc-btn-danger">Delete account</button>
                                            </form>
                                        </div>
                                    @elseif($u->id === auth()->id())
                                        <div class="text-xs nc-muted pt-2 border-t">Your own account cannot be suspended or deleted from this screen.</div>
                                    @endif
                                </div>
                            </details>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t">{{ $employees->links() }}</div>
    </div>
</div>
@endsection
