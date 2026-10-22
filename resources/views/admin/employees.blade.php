@extends('layouts.app')
@section('title', 'Employees · Pharma Strategies')
@section('content')
<div class="nc-shell nc-page nc-employees-page">
    <div class="nc-page-header">
        <div>
            <h1 class="mt-2">Employees & access</h1>
            <p class="nc-muted mt-1">Manage accounts, workplace roles and access for {{ $company->name }}.</p>
        </div>
    </div>

    @if(auth()->user()->isPlatformSuperAdmin())
        <x-people-navigation />
        <x-employee-workspace-selector :companies="$companies" :company="$company" />
    @endif


    <div class="nc-employee-content">
    <section class="nc-card nc-employee-directory" aria-labelledby="employee-directory-heading">
        <div class="nc-employee-directory-heading">
            <div><h2 id="employee-directory-heading">{{ $company->name }} accounts</h2></div>
            <span class="nc-employee-count">{{ $employees->total() }} {{ Illuminate\Support\Str::plural('account', $employees->total()) }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="nc-table nc-responsive-table w-full text-sm min-w-[760px]">
                <caption class="sr-only">Company employees and their access controls</caption>
                <thead><tr><th scope="col" class="text-left">Employee</th><th scope="col" class="text-left">Role</th><th scope="col" class="text-left">Department</th><th scope="col" class="text-left">Status</th><th scope="col" class="text-left">Actions</th></tr></thead>
                <tbody>
                @forelse($employees as $u)
                    <tr>
                        <td data-label="Employee" class="nc-table-primary">
                            <div class="nc-employee-person"><span class="nc-employee-avatar" aria-hidden="true">{{ Illuminate\Support\Str::upper(Illuminate\Support\Str::substr($u->pivot->name ?: $u->name, 0, 1)) }}</span><div><div class="font-semibold">{{ $u->pivot->name ?: $u->name }}</div><div class="text-xs nc-muted mt-1"><a class="nc-contact-link" href="mailto:{{ $u->email }}">{{ $u->email }}</a> @if($u->pivot->employee_id) · ID {{ $u->pivot->employee_id }} @endif</div></div></div>
                        </td>
                        <td data-label="Role">{{ ucwords(str_replace('_',' ',$u->pivot->role)) }}</td>
                        <td data-label="Department">{{ $u->pivot->department ?: '—' }}</td>
                        <td data-label="Status"><span class="nc-employee-status {{ $u->pivot->status === 'active' ? 'is-active' : 'is-suspended' }}"><span aria-hidden="true"></span>{{ ucfirst($u->pivot->status) }}</span></td>
                        <td data-label="Actions" class="nc-table-actions">
                            <button type="button" class="nc-btn-secondary nc-employee-manage-button" data-employee-editor="employee-editor-{{ $u->id }}" aria-haspopup="dialog" aria-controls="employee-editor-{{ $u->id }}" aria-label="Manage account for {{ $u->pivot->name ?: $u->name }}">Manage <x-icon name="arrow" size="15" /></button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="nc-empty-state">No employee accounts yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($employees->hasPages())<div class="p-4 border-t">{{ $employees->links() }}</div>@endif
    </section>

    <section id="new-employee" class="nc-card nc-employee-create" aria-labelledby="new-employee-heading">
        <div class="nc-employee-create-heading"><span class="nc-employee-workspace-icon"><x-icon name="people" size="22" /></span><div><h2 id="new-employee-heading">Create an employee</h2></div></div>
        <form method="POST" action="{{ route('admin.employees.store') }}" class="nc-employee-create-form">
            @csrf
            <input type="hidden" name="company_id" value="{{ $company->id }}">
            <label><span class="nc-label">Full name</span><input class="nc-input" name="name" value="{{ old('name') }}" autocomplete="name" maxlength="120" required></label>
            <label><span class="nc-label">Work email</span><input class="nc-input" name="email" type="email" value="{{ old('email') }}" autocomplete="email" maxlength="190" required></label>
            <label><span class="nc-label">Password</span><input class="nc-input" name="password" type="password" autocomplete="new-password" minlength="8" aria-describedby="employee-password-help" required><span id="employee-password-help" class="nc-form-help">At least 8 characters with uppercase, lowercase and a number.</span></label>
            <label><span class="nc-label">Role</span><select class="nc-input" name="role">
                @foreach(\App\Enums\CompanyRole::employeeValues() as $role)<option value="{{ $role }}" @selected(old('role', 'staff') === $role)>{{ ucwords(str_replace('_',' ',$role)) }}</option>@endforeach
                @if(auth()->user()->isSuperAdmin())<option value="company_super_admin" @selected(old('role') === 'company_super_admin')>Company Super Admin</option>@endif
            </select></label>
            <details class="nc-employee-optional" @if(old('employee_id') || old('department') || old('job_title')) open @endif>
                <summary>Employment details <span>Optional</span></summary>
                <div>
                    <label><span class="nc-label">Employee ID</span><input class="nc-input" name="employee_id" value="{{ old('employee_id') }}" maxlength="80"></label>
                    <label><span class="nc-label">Department</span><input class="nc-input" name="department" value="{{ old('department') }}" maxlength="120"></label>
                    <label><span class="nc-label">Job title</span><input class="nc-input" name="job_title" value="{{ old('job_title') }}" maxlength="120"></label>
                </div>
            </details>
            <button type="submit" class="nc-btn nc-employee-create-button"><span aria-hidden="true">+</span> Create account</button>
        </form>
    </section>


    </div>


    @foreach($employees as $u)
        @php($reopenEditor = $errors->any() && (int) old('employee_editor') === $u->id)
        <dialog id="employee-editor-{{ $u->id }}" class="nc-employee-dialog" @if($reopenEditor) data-employee-editor-reopen @endif aria-labelledby="employee-editor-title-{{ $u->id }}">
            <header class="nc-employee-dialog-header"><div><span class="nc-employee-caption">{{ $company->name }} · Company access</span><h2 id="employee-editor-title-{{ $u->id }}">{{ $u->pivot->name ?: $u->name }}</h2><p>{{ $u->email }}</p></div><button type="button" class="nc-employee-dialog-close" data-employee-editor-close aria-label="Close employee editor"><x-icon name="close" /></button></header>
            <div class="nc-employee-dialog-body">
<form method="POST" action="{{ route('admin.employees.update',$u) }}" class="nc-employee-edit-form">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="employee_editor" value="{{ $u->id }}">
                                        <input type="hidden" name="company_id" value="{{ $company->id }}">
                                        <label><span class="nc-label">Full name</span><input class="nc-input" name="name" value="{{ $reopenEditor ? old('name', $u->pivot->name ?: $u->name) : ($u->pivot->name ?: $u->name) }}" required></label>
                                        <label><span class="nc-label">Employee ID</span><input class="nc-input" name="employee_id" value="{{ $reopenEditor ? old('employee_id', $u->pivot->employee_id) : ($u->pivot->employee_id) }}"></label>
                                        <label><span class="nc-label">Department</span><input class="nc-input" name="department" value="{{ $reopenEditor ? old('department', $u->pivot->department) : ($u->pivot->department) }}"></label>
                                        <label><span class="nc-label">Job title</span><input class="nc-input" name="job_title" value="{{ $reopenEditor ? old('job_title', $u->pivot->job_title) : ($u->pivot->job_title) }}"></label>
                                        @if($u->pivot->role === 'company_super_admin')<input type="hidden" name="role" value="company_super_admin"><input type="hidden" name="status" value="{{ $u->pivot->status }}"><p class="nc-employee-protection-note">Company administrator access is protected.</p>@else
                                        <select class="nc-input" name="role" aria-label="Employee role">
                                            @foreach(\App\Enums\CompanyRole::employeeValues() as $role)<option value="{{ $role }}" @selected(($reopenEditor ? old('role', $u->pivot->role) : $u->pivot->role) === $role)>{{ ucwords(str_replace('_',' ',$role)) }}</option>@endforeach
                                            @if(auth()->user()->isSuperAdmin())<option value="company_super_admin" @selected(($reopenEditor ? old('role', $u->pivot->role) : $u->pivot->role) === 'company_super_admin')>Company Super Admin</option>@endif
                                        </select>
                                        <select class="nc-input" name="status" aria-label="Employee status">
                                            <option value="active" @selected(($reopenEditor ? old('status', $u->pivot->status) : $u->pivot->status) === 'active')>Active</option>
                                            <option value="suspended" @selected(($reopenEditor ? old('status', $u->pivot->status) : $u->pivot->status) === 'suspended')>Suspended</option>
                                        </select>
                                        @endif
                                        <div class="nc-employee-edit-footer"><button type="button" class="nc-btn-secondary" data-employee-editor-close>Cancel</button><button type="submit" class="nc-btn">Save changes</button></div>
                                    </form>

                                    @if(auth()->user()->isSuperAdmin() && $u->id !== auth()->id() && $u->pivot->role !== 'company_super_admin')
                                        <div class="nc-employee-remove-zone">
                                            <div><div class="text-sm font-bold text-red-800">Remove company access</div><div class="text-xs text-red-700 mt-1">The account and conversation history are retained.</div></div>
                                            <form method="POST" action="{{ route('admin.employees.delete',$u) }}" data-confirm-title="Remove employee access?" data-confirm-label="Remove access" data-confirm-text="This employee will lose access to {{ $company->name }}. Their account and chat history will be kept.">
                                                @csrf @method('DELETE')
                                                <input type="hidden" name="company_id" value="{{ $company->id }}">
                                                <button type="submit" class="nc-btn-danger">Remove access</button>
                                            </form>
                                        </div>
                                    @elseif($u->id === auth()->id())
                                        <div class="text-xs nc-muted pt-2 border-t">Your own account cannot be suspended or deleted from this screen.</div>
                                    @endif
            </div>
        </dialog>
    @endforeach
</div>

@endsection
