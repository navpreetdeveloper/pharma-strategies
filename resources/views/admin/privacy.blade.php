@extends('layouts.app')
@section('title', 'Privacy settings · Pharma Strategies')
@section('content')
<div class="nc-shell nc-page">
    <header class="nc-page-header">
        <div><h1>Privacy & security</h1><p class="nc-muted mt-2">Conversation access and history policy for {{ $company->name }}.</p></div>
        @if(auth()->user()->isPlatformSuperAdmin())<a href="{{ route('admin.dashboard') }}" class="nc-back-btn"><x-icon name="back" size="18" /> Company workspace</a>@endif
    </header>
    <div class="nc-settings-summary">
        @foreach([['Configured data region',$setting->data_residency],['History retention',$setting->retention_days ? $setting->retention_days.' days' : 'Keep indefinitely'],['Administrator review',$setting->allow_admin_message_access ? 'Allowed' : 'Participants only'],['Privacy contact',$setting->privacy_contact_email ?: 'Not configured']] as [$label,$value])
            <div class="nc-card"><div class="nc-label">{{ $label }}</div><div class="font-semibold mt-2">@if($label === 'Privacy contact' && $setting->privacy_contact_email)<a class="nc-contact-link" href="mailto:{{ $setting->privacy_contact_email }}">{{ $value }}</a>@else{{ $value }}@endif</div></div>
        @endforeach
    </div>
    <div class="nc-settings-layout mt-6">
        @if(!auth()->user()->isPlatformSuperAdmin() && auth()->user()->hasAnyRole(['company_super_admin']))
            <section class="nc-card p-6">
                <h2 class="font-semibold text-lg">Workspace privacy policy</h2>
                <p class="nc-muted text-sm mt-2">Changes apply only to {{ $company->name }}.</p>
                <form method="POST" action="{{ route('admin.privacy.update') }}" class="grid gap-5 mt-6">
                    @csrf @method('PATCH')
                    <input type="hidden" name="company_id" value="{{ $company->id }}">
                    <label><span class="nc-label">History retention (days)</span><input class="nc-input" type="number" min="1" max="36500" name="retention_days" value="{{ old('retention_days',$setting->retention_days) }}" aria-describedby="retention-help"><span id="retention-help" class="nc-form-help">Leave blank to retain history indefinitely.</span></label>

                    <fieldset class="nc-policy-options">
                        <legend class="nc-label mb-3">Who can review conversations?</legend>
                        <label class="nc-policy-option">
                            <input type="radio" name="allow_admin_message_access" value="0" @checked(old('allow_admin_message_access', (int)$setting->allow_admin_message_access) == 0)>
                            <span><strong>Conversation participants only</strong><small>Only people in a conversation can read its messages. Administrators cannot review conversations they are not part of.</small></span>
                        </label>
                        <label class="nc-policy-option">
                            <input type="radio" name="allow_admin_message_access" value="1" @checked(old('allow_admin_message_access', (int)$setting->allow_admin_message_access) == 1)>
                            <span><strong>Allow administrator review</strong><small>Company Super Admins and Platform Super Admins can review this company's conversations. Review access is recorded in the audit log; Platform Super Admin chat access is read-only.</small></span>
                        </label>
                    </fieldset>
                    <label><span class="nc-label">Privacy contact email</span><input class="nc-input" name="privacy_contact_email" type="email" value="{{ old('privacy_contact_email',$setting->privacy_contact_email) }}" autocomplete="email" aria-describedby="privacy-contact-help"><span id="privacy-contact-help" class="nc-form-help">The person your team should contact with privacy questions.</span></label>
                    <div><button type="submit" class="nc-btn">Save policy</button></div>
                </form>
            </section>
        @endif
        <aside class="nc-card p-6">
            <x-icon name="shield" size="24" class="text-[#0d5c8f]" />
            <h2 class="font-semibold mt-4">About these settings</h2>
            <p class="nc-muted text-sm mt-3"><strong>Configured data region:</strong> the intended hosting region recorded in the app. This setting does not move data or verify the location of your server, files or backups.</p>
            <p class="nc-muted text-sm mt-3"><strong>Retention:</strong> for example, 90 days makes messages older than 90 days eligible for cleanup, including their attachments and related notifications. It does not change your backup retention.</p>
            <p class="nc-form-help mt-3">{{ config('chat.retention_scheduled') ? 'Scheduled cleanup is configured for 3:00 AM in the application timezone. The server scheduler must be running.' : 'Automatic cleanup is disabled. Saving a retention period does not delete messages until cleanup is enabled on the server or run manually.' }}</p>
            <p class="nc-muted text-sm mt-3"><strong>Privacy contact:</strong> a reference email for privacy questions. Saving it does not send email or configure outgoing mail.</p>
        </aside>
    </div>
</div>
@endsection
