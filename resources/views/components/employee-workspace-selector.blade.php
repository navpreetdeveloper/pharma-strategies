@props(['companies', 'company' => null])
@php($activeCompanies = $companies->where('status', 'active'))
@php($archivedCompanies = $companies->where('status', 'archived'))
<section class="nc-card nc-employee-workspace mt-6" aria-labelledby="employee-workspace-heading">
    <div class="nc-employee-workspace-main">
        <span class="nc-employee-workspace-icon"><x-icon name="building" size="24" /></span>
        <div>
            <h2 id="employee-workspace-heading">{{ $company ? 'Managing '.$company->name : 'Choose a company' }}</h2>
            <p class="nc-muted">{{ $activeCompanies->count() }} active {{ Illuminate\Support\Str::plural('company', $activeCompanies->count()) }} · {{ $archivedCompanies->count() }} archived</p>
        </div>
    </div>
    <div class="nc-employee-workspace-controls">
        @if($activeCompanies->isNotEmpty())
            <form method="POST" action="{{ route('platform.employees.workspace') }}" class="nc-employee-workspace-form" data-action-navigation="true">
                @csrf
                <label for="employee-company" class="sr-only">Company to manage</label>
                <select id="employee-company" name="company_id" class="nc-input" required>
                    @if(!$company)<option value="" selected disabled>Select a company</option>@endif
                    <optgroup label="Active companies">
                        @foreach($activeCompanies as $choice)
                            <option value="{{ $choice->id }}" @selected($company?->id === $choice->id)>{{ $choice->name }}</option>
                        @endforeach
                    </optgroup>
                    @if($archivedCompanies->isNotEmpty())
                        <optgroup label="Archived — restore before managing">
                            @foreach($archivedCompanies as $choice)<option disabled>{{ $choice->name }} — Archived</option>@endforeach
                        </optgroup>
                    @endif
                </select>
                <button type="submit" class="nc-btn"><x-icon name="arrow" size="17" /> {{ $company ? 'Switch company' : 'Manage employees' }}</button>
            </form>
        @else
            <p class="nc-muted">No active company workspaces.</p>
        @endif
    </div>
    @if($archivedCompanies->isNotEmpty())
        <div class="nc-employee-archived-note">
            <x-icon name="building" size="17" />
            <div><strong>Archived workspaces</strong><span>Restore a company from its directory page to manage its employees.</span></div>
            <div class="nc-employee-archived-links">
                @foreach($archivedCompanies as $archived)
                    <a href="{{ route('platform.companies.index', ['company' => $archived->id]) }}">{{ $archived->name }} <span aria-hidden="true">↗</span></a>
                @endforeach
            </div>
        </div>
    @elseif($activeCompanies->count() === 1)
        <p class="nc-employee-single-note">One company workspace. New companies can be added from Companies.</p>
    @endif
</section>
