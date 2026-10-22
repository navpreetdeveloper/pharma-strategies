@extends('layouts.app')
@section('title', 'Team communication - Pharma Strategies')
@section('content')
<section class="nc-shell nc-landing-simple">
    <div class="nc-landing-hero">
    <div class="nc-landing-intro">
        <div class="nc-eyebrow">Canadian pharmacy teams</div>
        <h1>Private communication for Canadian pharmacy teams.</h1>
        <p>Pharma Strategies gives pharmacy organisations a company-controlled workplace channel so employees can communicate without exchanging personal phone numbers or email addresses.</p>
        <div class="nc-landing-actions"><a class="nc-btn" href="{{ route('login') }}">Sign in <x-icon size="18" /></a></div>
    </div>
    <div class="nc-landing-preview-frame">
    <figure class="nc-workspace-preview" aria-labelledby="workspace-preview-caption">
        <div class="nc-preview-header"><span class="nc-preview-brand"><span aria-hidden="true">P</span> Team workspace</span><span class="nc-preview-label">Preview</span></div>
        <div class="nc-preview-channel"><span class="nc-preview-team-icon"><x-icon name="people" size="22" /></span><div><strong>Pharmacy team</strong><span>Shift handover</span></div></div>
        <div class="nc-preview-messages">
            <div class="nc-preview-message"><span class="nc-preview-avatar" aria-hidden="true">A</span><div><strong>Alex <span>Pharmacist</span></strong><p>The updated shift schedule is ready for the team.</p><div class="nc-preview-file"><x-icon name="file" size="20" /><span>Shift schedule.pdf<small>Shared with your team</small></span></div></div></div>
            <div class="nc-preview-reply"><p>Thanks, I’ll share it with the afternoon team.</p><span>Jamie · Pharmacy manager</span></div>
        </div>
        <figcaption id="workspace-preview-caption"><x-icon name="chat" size="17" /> An illustrative pharmacy team conversation.</figcaption>
    </figure>
    </div>
    </div>
    <div class="nc-card nc-landing-features">
        <article><span class="nc-landing-feature-icon"><x-icon name="chat" size="23" /></span><div><h2>Real-time chat</h2><p>Keep workplace conversations and shared files together.</p></div></article>
        <article><span class="nc-landing-feature-icon"><x-icon name="people" size="23" /></span><div><h2>Role-based access</h2><p>Manage access for administrators, pharmacists, managers, technicians and staff.</p></div></article>
        <article><span class="nc-landing-feature-icon"><x-icon name="shield" size="23" /></span><div><h2>Privacy centre</h2><p>Control retention and conversation access, and review administrative activity.</p></div></article>
    </div>
</section>

@endsection
