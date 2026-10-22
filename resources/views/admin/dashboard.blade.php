@extends('layouts.app')
@section('title', 'Workspace · Pharma Strategies')
@section('content')
<div class="nc-shell nc-page">
    <div class="nc-page-header">
        <div>
            <h1>Workspace overview</h1>
            <p class="nc-muted mt-1">{{ $company->name }}</p>
        </div>
        <a class="nc-btn-secondary" href="{{ route('admin.privacy') }}"><x-icon name="shield" size="18" /> Privacy settings</a>
    </div>

    <div class="nc-stat-grid mt-7">
        @foreach([['Active employees',$employees],['Conversations',$conversations],['Messages today',$messages],['Recent changes',$audit->count()]] as [$label,$value])
            <div class="nc-card nc-stat-card">
                <div class="text-sm nc-muted">{{ $label }}</div>
                <div class="nc-stat-value mt-2">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <div class="mt-6">
        <div class="nc-card p-6">
            <div class="nc-panel-heading">
                <div><h2 class="font-bold">Recent activity</h2></div>
                <a class="nc-btn-secondary" href="{{ route('admin.audit') }}">View all</a>
            </div>
            <div class="mt-5 space-y-3">
                @forelse($audit as $a)
                    <div class="nc-audit-event">
                        <div class="nc-audit-event-copy"><strong>{{ $a->activityLabel() }}</strong><span>{{ $a->activityTarget() }}</span><small>By {{ $a->user?->name ?: 'System' }}</small></div>
                        <time datetime="{{ $a->created_at->toIso8601String() }}">{{ $a->created_at->format('M j, Y') }}<span>{{ $a->created_at->format('g:i a T') }}</span></time>
                    </div>
                @empty
                    <div class="text-sm nc-muted py-5">No audit events yet.</div>
                @endforelse
            </div>
        </div>

    </div>
</div>
@endsection
