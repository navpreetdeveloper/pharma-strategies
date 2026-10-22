@extends('layouts.app')
@section('title', 'Activity - Pharma Strategies')
@section('content')
<div class="nc-shell nc-page nc-audit-page">
    <header class="nc-page-header">
        <div><h1>Activity</h1><p class="nc-muted mt-2">{{ $platformAudit ? 'Important platform and company changes.' : $company->name.' - important changes.' }}</p></div>
    </header>
    <form method="GET" class="nc-directory-filters nc-card">
        <label><span class="nc-label">Event type</span><select name="category" class="nc-input"><option value="">All important changes</option>@foreach(App\Models\AuditLog::categories() as $key => $actions)<option value="{{ $key }}" @selected(request('category') === $key)>{{ $key === 'messaging' ? 'Message removals' : ucfirst($key) }}</option>@endforeach</select></label>
        @if($platformAudit)<label><span class="nc-label">Company</span><select name="company_id" class="nc-input"><option value="">All companies and platform</option>@foreach($companies as $item)<option value="{{ $item->id }}" @selected(request('company_id') == $item->id)>{{ $item->name }}</option>@endforeach</select></label>@endif
        <button class="nc-btn" type="submit">Filter</button><a class="nc-btn-secondary" href="{{ $platformAudit ? route('platform.audit') : route('admin.audit') }}">Clear</a>
    </form>
    <section class="nc-card overflow-hidden">
        <div class="nc-directory-heading"><h2>Activity history</h2><span>{{ $logs->total() }} events</span></div>
        <div class="nc-table-scroll" role="region" aria-label="Audit activity">
            <table class="nc-table nc-responsive-table nc-audit-table">
                <caption class="sr-only">Authorized administrative and security activity</caption>
                <thead><tr><th scope="col">When</th><th scope="col">Performed by</th><th scope="col">Activity</th><th scope="col">Affected record</th>@if($platformAudit)<th scope="col">Details</th>@endif</tr></thead>
                <tbody>@forelse($logs as $log)
                    <tr>
                        <td data-label="When"><time datetime="{{ $log->created_at->toIso8601String() }}">{{ $log->created_at->format('M j, Y') }}<small>{{ $log->created_at->format('g:i a T') }}</small></time></td>
                        <td data-label="Performed by">{{ $log->user?->name ?: 'System' }}</td>
                        <td data-label="Activity"><div><strong>{{ $log->activityLabel() }}</strong>@if($platformAudit)<small>{{ $log->company?->name ?: 'Platform' }}</small>@endif</div></td>
                        <td data-label="Affected record">{{ $log->activityTarget() }}</td>
                        @if($platformAudit)<td data-label="Details"><details class="nc-audit-details"><summary>View details</summary><dl><dt>IP address</dt><dd>{{ $log->ip_address ?: 'Not recorded' }}</dd>@if($log->action === 'platform_password_reset')<dt>Recovery reason</dt><dd>{{ $log->metadata['reason'] ?? 'Not recorded' }}</dd><dt>Identity check</dt><dd>{{ ($log->metadata['identity_verified'] ?? false) ? 'Confirmed by administrator' : 'Not recorded' }}</dd>@endif</dl></details></td>@endif
                    </tr>
                @empty<tr><td colspan="{{ $platformAudit ? 5 : 4 }}" class="nc-empty-state">No activity matches these filters.</td></tr>@endforelse</tbody>
            </table>
        </div>
        <div class="p-4 border-t">{{ $logs->links() }}</div>
    </section>
</div>
@endsection
