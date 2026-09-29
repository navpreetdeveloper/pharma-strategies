@extends('layouts.app')
@section('content')
<div class="nc-shell py-7">
    <div class="flex items-start justify-between gap-4 flex-wrap">
        <div>
            <div class="text-xs font-bold tracking-wider text-[#0d7aa8] uppercase">Company administration</div>
            <h1 class="text-3xl font-extrabold mt-1">Workspace overview</h1>
            <p class="nc-muted mt-1">{{ $company->name }} · controlled workplace communication</p>
        </div>
        <div class="flex gap-2 items-center"><a class="nc-back-btn" href="{{ route('chat') }}" aria-label="Back to chat" data-history-back>← <span>Back</span></a><a class="nc-btn" href="{{ route('chat') }}">Open chat</a></div>
    </div>

    <div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-4 mt-7">
        @foreach([['Active employees',$employees,'People with active company access'],['Conversations',$conversations,'Company conversations'],['Messages today',$messages,'Messages created today'],['Audit events',$audit->count(),'Recent events shown below']] as [$label,$value,$hint])
            <div class="nc-card p-5">
                <div class="text-sm nc-muted">{{ $label }}</div>
                <div class="text-3xl font-extrabold mt-2">{{ $value }}</div>
                <div class="text-xs nc-muted mt-2">{{ $hint }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid lg:grid-cols-[1fr_320px] gap-5 mt-6">
        <div class="nc-card p-6">
            <div class="flex items-center justify-between gap-3">
                <div><h2 class="font-bold">Recent audit activity</h2><p class="text-sm nc-muted mt-1">Administrative actions for this company.</p></div>
                <a class="nc-btn-secondary" href="{{ route('admin.audit') }}">View all</a>
            </div>
            <div class="mt-5 space-y-3">
                @forelse($audit as $a)
                    <div class="flex items-center justify-between gap-4 border-b last:border-b-0 pb-3 last:pb-0 text-sm">
                        <div><div class="font-semibold">{{ ucwords(str_replace('_',' ',$a->action)) }}</div><div class="text-xs nc-muted mt-1">{{ $a->user?->name ?: 'System' }}</div></div>
                        <span class="text-xs nc-muted whitespace-nowrap">{{ $a->created_at->format('Y-m-d H:i') }}</span>
                    </div>
                @empty
                    <div class="text-sm nc-muted py-5">No audit events yet.</div>
                @endforelse
            </div>
        </div>

        <div class="space-y-4">
            <div class="nc-card p-5">
                <h2 class="font-bold">Administration</h2>
                <div class="grid gap-2 mt-4">
                    <a class="nc-btn" href="{{ route('admin.employees') }}">Manage employees</a>
                    <a class="nc-btn-secondary" href="{{ route('admin.audit') }}">Audit logs</a>
                    <a class="nc-btn-secondary" href="{{ route('profile.show') }}">My profile</a>
                </div>
            </div>
            <div class="nc-card p-5">
                <div class="text-sm font-bold">Access reminder</div>
                <p class="text-sm nc-muted mt-2">Super Admin actions are company-scoped and administrative activity is recorded in the audit log.</p>
            </div>
        </div>
    </div>
</div>
@endsection
