@extends('layouts.app')
@section('title', 'Employees · Pharma Strategies')
@section('content')
<div class="nc-shell nc-page nc-employees-page">
    <div class="nc-page-header">
        <div><h1>Employees & access</h1><p class="nc-muted mt-2">Manage employee accounts across your companies.</p></div>
    </div>
    <x-people-navigation />
    <x-employee-workspace-selector :companies="$companies" />
    <div class="nc-card p-6 mt-6"><h2 class="font-semibold">Select a workspace to get started</h2><p class="nc-muted text-sm mt-2">The employee list and account form will appear for the company you select. Archived companies can be restored from Companies.</p></div>
</div>
@endsection
