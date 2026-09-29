@extends('layouts.app')

@section('content')
<div class="min-h-[calc(100vh-64px)] flex items-center justify-center px-5 py-10">
    <div class="nc-card p-8 w-full max-w-lg">
        <h1 class="text-2xl font-bold">Register your company</h1>
        <p class="nc-muted mt-1">The first account becomes the Company Super Admin. No demo data is created.</p>

        @if ($errors->any())
            <div class="mt-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                <div class="font-semibold">Please correct the following:</div>
                <ul class="mt-2 list-disc pl-5 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('register.submit') }}" class="grid md:grid-cols-2 gap-4 mt-6">
            @csrf

            <label class="md:col-span-2 text-sm font-medium">
                Company name
                <input class="nc-input mt-1" name="company_name" value="{{ old('company_name') }}" required autocomplete="organization">
                @error('company_name')<span class="block mt-1 text-xs text-red-700">{{ $message }}</span>@enderror
            </label>

            <label class="text-sm font-medium">
                Province
                <select class="nc-input mt-1" name="province" required>
                    @foreach(['Alberta','British Columbia','Manitoba','New Brunswick','Newfoundland and Labrador','Northwest Territories','Nova Scotia','Nunavut','Ontario','Prince Edward Island','Quebec','Saskatchewan','Yukon'] as $p)
                        <option value="{{ $p }}" @selected(old('province', 'Alberta') === $p)>{{ $p }}</option>
                    @endforeach
                </select>
                @error('province')<span class="block mt-1 text-xs text-red-700">{{ $message }}</span>@enderror
            </label>

            <label class="text-sm font-medium">
                Your name
                <input class="nc-input mt-1" name="name" value="{{ old('name') }}" required autocomplete="name">
                @error('name')<span class="block mt-1 text-xs text-red-700">{{ $message }}</span>@enderror
            </label>

            <label class="md:col-span-2 text-sm font-medium">
                Work email
                <input class="nc-input mt-1" name="email" value="{{ old('email') }}" type="email" required autocomplete="email">
                @error('email')<span class="block mt-1 text-xs text-red-700">{{ $message }}</span>@enderror
            </label>

            <label class="text-sm font-medium">
                Password
                <input class="nc-input mt-1" name="password" type="password" required autocomplete="new-password">
                @error('password')<span class="block mt-1 text-xs text-red-700">{{ $message }}</span>@enderror
            </label>

            <label class="text-sm font-medium">
                Confirm password
                <input class="nc-input mt-1" name="password_confirmation" type="password" required autocomplete="new-password">
            </label>

            <button type="submit" class="nc-btn md:col-span-2">Create company workspace</button>
        </form>
    </div>
</div>
@endsection
