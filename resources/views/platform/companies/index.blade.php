@extends('layouts.app')
@section('title', 'Companies · Pharma Strategies')
@section('content')
<div class="nc-shell nc-platform-companies-page">
    <header class="nc-platform-companies-heading">
        <div>
            <h1>Companies</h1>
            <p>Manage independent company workspaces from one place.</p>
        </div>
        @unless(request()->boolean('create'))<a href="{{ route('platform.companies.index', ['create' => 1]) }}" class="nc-btn"><span aria-hidden="true">+</span> Add company</a>@endunless
    </header>


    <section class="nc-platform-directory nc-card">
        <aside class="nc-platform-company-list" aria-label="Company workspaces">
            <div class="nc-platform-list-heading"><h2>All companies</h2><span>{{ $companies->count() }}</span></div>
            <form method="GET" action="{{ route('platform.companies.index') }}" class="nc-platform-search-form">
                @if(request()->boolean('create'))<input type="hidden" name="create" value="1">@endif
                <label class="sr-only" for="company-search">Search companies</label>
                <input id="company-search" class="nc-input" type="search" name="q" value="{{ request('q') }}"><button class="nc-platform-search-submit" type="submit" aria-label="Search companies"><x-icon name="search" size="18" /></button>
            </form>
            <nav class="nc-platform-company-items">
                @forelse($companies as $company)
                    <a href="{{ route('platform.companies.index', ['company' => $company->id, 'q' => request('q')]) }}"
                       class="nc-platform-company-item {{ $selected?->id === $company->id ? 'is-selected' : '' }}"
                       aria-current="{{ $selected?->id === $company->id ? 'page' : 'false' }}">
                        <span>{{ $company->name }}</span><span aria-hidden="true">›</span>
                    </a>
                @empty
                    <p class="nc-platform-empty-list">{{ request('q') ? 'No companies match your search.' : 'No companies yet. Create a workspace to get started.' }}</p>
                @endforelse
            </nav>
        </aside>

        <section class="nc-platform-company-detail" aria-live="polite">
            @if(request()->boolean('create'))
                <div class="nc-platform-form-panel">
                    <div class="nc-platform-detail-top"><a class="nc-back-btn" href="{{ route('platform.companies.index') }}">Cancel</a></div>
                    <h2>Add a company</h2>
                    <p class="nc-platform-detail-description">Create the workspace and its Company Admin account.</p>
                    <form method="POST" action="{{ route('platform.companies.store') }}" class="nc-platform-company-form">
                        @csrf
                        <label><span class="nc-label">Company display name</span><input class="nc-input" name="name" value="{{ old('name') }}" required maxlength="180"></label>
                        <label><span class="nc-label">Registered business name (optional)</span><input class="nc-input" name="legal_name" value="{{ old('legal_name') }}" maxlength="180"></label>
                        <label><span class="nc-label">Province / territory</span><select class="nc-input" name="province" required><option value="">Select province or territory</option>@foreach($provinces as $province)<option value="{{ $province }}" @selected(old('province') === $province)>{{ $province }}</option>@endforeach</select></label>
                        <h3>Company Admin</h3>
                        <label><span class="nc-label">Company admin name</span><input class="nc-input" name="admin_name" value="{{ old('admin_name') }}" required maxlength="120" autocomplete="name"></label>
                        <label><span class="nc-label">Work email</span><input class="nc-input" name="admin_email" type="email" value="{{ old('admin_email') }}" required maxlength="190" autocomplete="off"></label>
                        <label><span class="nc-label">Temporary password</span><input class="nc-input" name="admin_password" type="password" required minlength="8" autocomplete="new-password"><span class="nc-form-help">At least 8 characters, uppercase, lowercase and a number. The administrator must change it at first sign-in.</span></label>
                        <label><span class="nc-label">Confirm temporary password</span><input class="nc-input" name="admin_password_confirmation" type="password" required minlength="8" autocomplete="new-password"></label>
                        <button type="submit" class="nc-btn">Create company</button>
                    </form>
                </div>
            @elseif($selected)
                @php($archived = $selected->status === 'archived')
                <div class="nc-platform-company-detail-inner">
                    <div class="nc-platform-detail-top"><span class="nc-platform-company-status {{ $archived ? 'is-archived' : '' }}">{{ $archived ? 'Archived' : 'Active' }}</span></div>
                    <div class="nc-platform-company-title"><span class="nc-platform-company-mark">{{ strtoupper(mb_substr($selected->name, 0, 1)) }}</span><div><h2>{{ $selected->name }}</h2><p>{{ $selected->legal_name ?: 'Legal name not provided' }}</p></div></div>
                    <div class="nc-platform-company-stats">
                        <div><span>Province / territory</span><strong>{{ $selected->province ?: 'Not provided' }}</strong></div>
                        <div><span>Active employees</span><strong>{{ $selected->active_employees_count }}</strong></div>
                        <div><span>Workspace access</span><strong>{{ $archived ? 'Disabled' : 'Available' }}</strong></div>
                    </div>
                    <div class="nc-platform-actions">
                        @unless($archived)
                            <form method="POST" action="{{ route('platform.companies.workspace', $selected) }}" data-action-navigation="true">@csrf<button class="nc-btn" type="submit">View conversations</button></form>
                            <form method="POST" action="{{ route('platform.companies.workspace', $selected) }}" data-action-navigation="true">@csrf<input type="hidden" name="next" value="employees"><button class="nc-btn-secondary" type="submit">Manage employees</button></form>
                        @endunless
                    </div>
                    <div class="nc-platform-edit-section">
                        <h3>Company details</h3>
                        <form method="POST" action="{{ route('platform.companies.update', $selected) }}" class="nc-platform-company-form">
                            @csrf @method('PATCH')
                            <label><span class="nc-label">Company display name</span><input class="nc-input" name="name" value="{{ old('name', $selected->name) }}" required maxlength="180"></label>
                            <label><span class="nc-label">Registered business name (optional)</span><input class="nc-input" name="legal_name" value="{{ old('legal_name', $selected->legal_name) }}" maxlength="180"></label>
                            <label><span class="nc-label">Province / territory</span><select class="nc-input" name="province" required>@foreach($provinces as $province)<option value="{{ $province }}" @selected(old('province', $selected->province) === $province)>{{ $province }}</option>@endforeach</select></label>
                            <button type="submit" class="nc-btn-secondary">Save changes</button>
                        </form>
                    </div>
                    <div class="nc-platform-archive-section">
                        <div><h3>{{ $archived ? 'Restore workspace' : 'Archive workspace' }}</h3><p>{{ $archived ? 'Active employees will regain access after restoration.' : 'Archiving disables employee access but preserves company, conversation and audit records.' }}</p></div>
                        <form method="POST" action="{{ route('platform.companies.status', $selected) }}" data-confirm-title="{{ $selected->status === 'active' ? 'Archive company?' : 'Restore company?' }}" data-confirm-label="{{ $selected->status === 'active' ? 'Archive company' : 'Restore company' }}" data-confirm-danger="{{ $selected->status === 'active' ? 'true' : 'false' }}" data-confirm-text="{{ $selected->status === 'active' ? 'Employees will lose workspace access. Company records will be kept.' : 'Active employees will regain workspace access.' }}">
                            @csrf @method('PATCH')
                            <input type="hidden" name="status" value="{{ $archived ? 'active' : 'archived' }}">
                            <button type="submit" class="{{ $archived ? 'nc-btn-secondary' : 'nc-btn-danger' }}">{{ $archived ? 'Restore company' : 'Archive company' }}</button>
                        </form>
                    </div>
                </div>
            @else
                <div class="nc-platform-detail-empty"><span><x-icon name="building" size="26" /></span><h2>Select a company</h2><p>Choose a company from the list to view its workspace details and controls.</p></div>
            @endif
        </section>
    </section>
</div>
<div class="nc-shell py-4">{{ $companies->links() }}</div>
@endsection
