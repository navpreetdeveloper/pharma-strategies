<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $company = $user->companies()->where('companies.id', $request->session()->get('company_id'))->first();

        return view('profile.show', [
            'user' => $user,
            'company' => $company,
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'employee_id' => ['nullable', 'string', 'max:80'],
            'department' => ['nullable', 'string', 'max:120'],
            'job_title' => ['nullable', 'string', 'max:120'],
        ]);

        $user->update($data);

        AuditLog::create([
            'company_id' => $request->session()->get('company_id'),
            'user_id' => $user->id,
            'action' => 'profile_updated',
            'target_type' => 'User',
            'target_id' => $user->id,
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('profile.show')->with('ok', 'Profile updated successfully.');
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $request->user();

        if (!Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'The current password is incorrect.'])->withInput();
        }

        if (Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['password' => 'Your new password must be different from the current password.']);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'must_change_password' => false,
        ])->save();

        // Keep the current session alive while invalidating other authenticated sessions.
        Auth::logoutOtherDevices($data['password']);
        $request->session()->regenerate();

        AuditLog::create([
            'company_id' => $request->session()->get('company_id'),
            'user_id' => $user->id,
            'action' => 'password_changed',
            'target_type' => 'User',
            'target_id' => $user->id,
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('profile.show')->with('ok', 'Your password has been changed successfully.');
    }
}
