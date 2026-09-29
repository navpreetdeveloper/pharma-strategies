<?php

namespace App\Http\Controllers;

use App\Models\{User, Company, AuditLog, PrivacySetting};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    private const ROLES = [
        'staff',
        'pharmacist',
        'pharmacy_manager',
        'pharmacy_technician',
        'pharmacy_assistant',
        'company_super_admin',
    ];

    private function company(Request $r): Company
    {
        return Company::findOrFail($r->session()->get('company_id'));
    }

    private function assertCanManageRole(Request $r, string $role): void
    {
        if ($role === 'company_super_admin' && !$r->user()->isSuperAdmin()) {
            abort(403, 'Only a Super Admin can assign the Company Super Admin role.');
        }
    }

    public function dashboard(Request $r)
    {
        $c = $this->company($r);

        return view('admin.dashboard', [
            'company' => $c,
            'employees' => $c->users()->wherePivot('status', 'active')->count(),
            'conversations' => $c->conversations()->count(),
            'messages' => \App\Models\Message::where('company_id', $c->id)->whereDate('created_at', today())->count(),
            'audit' => AuditLog::where('company_id', $c->id)->latest()->limit(8)->get(),
        ]);
    }

    public function employees(Request $r)
    {
        $c = $this->company($r);

        return view('admin.employees', [
            'company' => $c,
            'employees' => $c->users()->orderBy('name')->paginate(25),
        ]);
    }

    public function storeEmployee(Request $r)
    {
        $c = $this->company($r);

        $d = $r->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190|unique:users,email',
            'employee_id' => 'nullable|string|max:80',
            'department' => 'nullable|string|max:120',
            'job_title' => 'nullable|string|max:120',
            'role' => ['required', Rule::in(self::ROLES)],
            'password' => ['required', Password::defaults()],
        ]);

        $this->assertCanManageRole($r, $d['role']);

        $u = User::create([
            'name' => $d['name'],
            'email' => $d['email'],
            'employee_id' => $d['employee_id'] ?? null,
            'department' => $d['department'] ?? null,
            'job_title' => $d['job_title'] ?? null,
            'password' => Hash::make($d['password']),
            'status' => 'active',
            'must_change_password' => true,
        ]);

        $c->users()->attach($u->id, [
            'role' => $d['role'],
            'status' => 'active',
        ]);

        AuditLog::create([
            'company_id' => $c->id,
            'user_id' => $r->user()->id,
            'action' => 'employee_created',
            'target_type' => 'User',
            'target_id' => $u->id,
            'ip_address' => $r->ip(),
        ]);

        return back()->with('ok', 'Employee created.');
    }

    public function updateEmployee(Request $r, User $user)
    {
        $c = $this->company($r);
        abort_unless($c->users()->whereKey($user->id)->exists(), 404);

        $d = $r->validate([
            'name' => 'required|string|max:120',
            'department' => 'nullable|string|max:120',
            'job_title' => 'nullable|string|max:120',
            'role' => ['required', Rule::in(self::ROLES)],
            'status' => 'required|in:active,suspended',
        ]);

        $this->assertCanManageRole($r, $d['role']);

        if ($user->id === $r->user()->id && $d['status'] !== 'active') {
            abort(422, 'You cannot suspend your own account.');
        }

        $user->update([
            'name' => $d['name'],
            'department' => $d['department'] ?? null,
            'job_title' => $d['job_title'] ?? null,
            'status' => $d['status'],
        ]);

        $c->users()->updateExistingPivot($user->id, [
            'role' => $d['role'],
            'status' => $d['status'],
        ]);

        AuditLog::create([
            'company_id' => $c->id,
            'user_id' => $r->user()->id,
            'action' => 'employee_updated',
            'target_type' => 'User',
            'target_id' => $user->id,
            'ip_address' => $r->ip(),
        ]);

        return back()->with('ok', 'Employee updated.');
    }

    public function deleteEmployee(Request $r, User $user)
    {
        abort_unless($r->user()->isSuperAdmin(), 403, 'Only a Super Admin can permanently delete an account.');

        $c = $this->company($r);
        abort_unless($c->users()->whereKey($user->id)->exists(), 404);
        abort_if($user->id === $r->user()->id, 422, 'You cannot delete your own account.');

        $isCompanySuperAdmin = $c->users()->whereKey($user->id)->wherePivot('role', 'company_super_admin')->wherePivot('status', 'active')->exists();
        if ($isCompanySuperAdmin) {
            $activeSuperAdmins = $c->users()->wherePivot('role', 'company_super_admin')->wherePivot('status', 'active')->count();
            abort_if($activeSuperAdmins <= 1, 422, 'The company must keep at least one active Super Admin.');
        }

        $targetId = $user->id;
        $targetName = $user->name;
        $targetEmail = $user->email;

        \DB::transaction(function () use ($c, $user, $r, $targetId, $targetName, $targetEmail) {
            $companyCount = $user->companies()->count();

            // Keep the audit record while removing the user's access from this company.
            AuditLog::create([
                'company_id' => $c->id,
                'user_id' => $r->user()->id,
                'action' => 'employee_deleted',
                'target_type' => 'User',
                'target_id' => $targetId,
                'metadata' => [
                    'name' => $targetName,
                    'email' => $targetEmail,
                    'scope' => $companyCount > 1 ? 'company_access_removed' : 'account_permanently_deleted',
                ],
                'ip_address' => $r->ip(),
            ]);

            if ($companyCount > 1) {
                $c->users()->detach($user->id);
            } else {
                \App\Models\Notification::where('notifiable_type', User::class)->where('notifiable_id', $user->id)->delete();
                \App\Models\PushSubscription::where('user_id', $user->id)->delete();
                \DB::table('sessions')->where('user_id', $user->id)->delete();
                $user->delete();
            }
        });

        return back()->with('ok', 'Employee account deleted from this company.');
    }

    public function audit(Request $r)
    {
        $c = $this->company($r);

        return view('admin.audit', [
            'logs' => AuditLog::where('company_id', $c->id)->with('user')->latest()->paginate(50),
        ]);
    }

    public function privacy(Request $r)
    {
        $c = $this->company($r);

        return view('admin.privacy', [
            'company' => $c,
            'setting' => PrivacySetting::where('company_id', $c->id)->firstOrFail(),
        ]);
    }
}
