<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PrivacySetting;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PrivacyController extends Controller
{
    public function show(Request $request): View
    {
        // Only the company-protected route may load workspace policy details.
        $company = $request->routeIs('workspace.privacy')
            ? Company::findOrFail($request->session()->get('company_id')) : null;
        $setting = $company ? PrivacySetting::where('company_id', $company->id)->first() : null;

        return view('privacy', compact('company', 'setting'));
    }
}
