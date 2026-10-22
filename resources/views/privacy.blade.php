@extends('layouts.app')
@section('title', 'Privacy · Pharma Strategies')
@section('description', 'How Pharma Strategies handles company conversations, account information, administrator access and retention.')
@section('content')
<div class="nc-shell nc-page">
    <header class="nc-page-header">
        <div><h1>{{ $company ? $company->name.' privacy policy' : 'Privacy notice' }}</h1><p class="nc-muted mt-2">How account information and workplace conversations are handled.</p></div>
    </header>
    @if($company)
        <div class="nc-settings-summary mb-6">
            <div class="nc-card"><div class="nc-label">Conversation review</div><p class="font-semibold mt-2">{{ $setting?->allow_admin_message_access ? 'Administrator review allowed' : 'Participants only' }}</p></div>
            <div class="nc-card"><div class="nc-label">Message retention</div><p class="font-semibold mt-2">{{ $setting?->retention_days ? $setting->retention_days.' days' : 'Kept until deleted under company policy' }}</p></div>
            <div class="nc-card"><div class="nc-label">Automatic retention cleanup</div><p class="font-semibold mt-2">{{ config('chat.retention_scheduled') ? 'Configured; requires an operating server scheduler' : 'Disabled; retention requires manual cleanup' }}</p></div>
            <div class="nc-card"><div class="nc-label">Privacy contact</div><p class="font-semibold mt-2">@if($setting?->privacy_contact_email)<a class="nc-contact-link" href="mailto:{{ $setting->privacy_contact_email }}">{{ $setting->privacy_contact_email }}</a>@else Contact your company administrator.@endif</p></div>
        </div>
    @else
        @auth
            @if(session('company_id'))<p class="mb-6"><a class="nc-text-link" href="{{ route('workspace.privacy') }}">View your current company's policy <span aria-hidden="true">→</span></a></p>@endif
        @endauth
    @endif
    <article class="nc-card p-6 grid gap-6 max-w-4xl">
        <section><h2 class="font-semibold text-lg">Information we handle</h2><p class="nc-muted mt-2">The application stores account and company membership details, messages, uploaded files, read status, device notification subscriptions, and security and administrative activity. This information supports workplace communication, access control, account recovery and investigation of security incidents.</p></section>
        <section><h2 class="font-semibold text-lg">Who can read conversations</h2><p class="nc-muted mt-2">Employees of another company cannot use their company access to read your conversations or download your files. Within a company, conversation participants can read messages. When that company enables administrator review, its Company Super Admins and Platform Super Admins can also review conversations; review requests are recorded in the audit log. Platform Super Admin chat review is read-only. New company policies restrict access to participants by default.</p></section>
        <section><h2 class="font-semibold text-lg">Security and notifications</h2><p class="nc-muted mt-2">Passwords are stored as hashes and cannot be retrieved as readable passwords. Private files require an authorized account. Sessions and remember-me cookies support sign-in; device notifications use your browser's push service when enabled. Notification content may appear on a device's lock screen, so use your device settings to control its visibility. This service does not provide end-to-end encrypted chat; authorized server operators can access stored data for administration.</p></section>
        <section><h2 class="font-semibold text-lg">Retention and account removal</h2><p class="nc-muted mt-2">Each company chooses its message retention period. A saved period makes older messages eligible for cleanup; deletion also requires the server cleanup process. Without a period, history is retained until deleted. Removing company access prevents further access but retains shared conversation history. Permanent account deletion removes credentials and account access; shared messages can remain under a deleted-account author. Audit records and backups have separate retention requirements. Unsend does not erase previously forwarded copies or files already downloaded by recipients.</p></section>
        <section><h2 class="font-semibold text-lg">Hosting and service providers</h2><p class="nc-muted mt-2">The application's configured region is an intended hosting location, not proof of where servers, backups, email or push providers process information. Your company and platform operator must confirm those locations and providers before using the service for sensitive information.</p></section>
        <section><h2 class="font-semibold text-lg">Questions and requests</h2><p class="nc-muted mt-2">Contact your company's privacy contact or administrator for access, correction, retention and deletion requests. The administrator must verify your identity and consider the company's obligations before acting. Only an active Company Super Admin of that company can change its policy through the application. Changes are recorded and reflected in the current company policy above.</p></section>
    </article>
</div>
@endsection
