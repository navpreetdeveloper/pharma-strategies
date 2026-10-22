<?php

namespace App\Http\Controllers;

use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Message;
use App\Models\PrivacySetting;
use App\Models\User;
use App\Services\Accounts\EmployeeAccess;
use App\Services\Companies\UpdatePrivacyPolicy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminController extends Controller
{
    public function __construct(private readonly EmployeeAccess $employees) {}

    private function company(Request $request): Company
    {
        $company = Company::query()
            ->whereKey($request->session()->get('company_id'))
            ->where('status', 'active')
            ->firstOrFail();
        $request->validate(['company_id' => 'sometimes|required|integer']);
        abort_if($request->filled('company_id') && (int) $request->input('company_id') !== $company->id, 409, 'The selected company changed in another tab. Refresh this page before saving.');

        return $company;
    }

    public function dashboard(Request $request)
    {
        $company = $this->company($request);

        return view('admin.dashboard', [
            'company' => $company,
            'employees' => $company->users()->wherePivot('status', 'active')->count(),
            'conversations' => $company->conversations()->count(),
            'messages' => Message::where('company_id', $company->id)->whereDate('created_at', today())->count(),
            'audit' => AuditLog::where('company_id', $company->id)->whereIn('action', AuditLog::recentActions())->with(['user', 'targetUser', 'company'])->latest('id')->limit(8)->get(),
        ]);
    }

    public function employees(Request $request)
    {
        $company = $this->company($request);

        return view('admin.employees', [
            'company' => $company,
            'employees' => $company->users()->orderBy('users.name')->paginate(25),
            'companies' => $request->user()->isPlatformSuperAdmin()
                ? Company::orderBy('name')->get(['id', 'name', 'status']) : collect(),
        ]);
    }

    public function storeEmployee(Request $request)
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }
        $company = $this->company($request);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::defaults()],
            'employee_id' => 'nullable|string|max:80', 'department' => 'nullable|string|max:120', 'job_title' => 'nullable|string|max:120',
            'role' => ['required', Rule::in(CompanyRole::values())],
        ]);
        $this->employees->create($company, $request->user(), $data, $request->ip());

        return back()->with('ok', 'Employee account created. They can sign in with their email and password.');
    }

    public function updateEmployee(Request $request, User $user)
    {
        $company = $this->company($request);
        $data = $request->validate(['name' => 'required|string|max:120', 'employee_id' => 'nullable|string|max:80', 'department' => 'nullable|string|max:120', 'job_title' => 'nullable|string|max:120', 'role' => ['required', Rule::in(CompanyRole::values())], 'status' => 'required|in:active,suspended']);
        $this->employees->update($company, $request->user(), $user, $data, $request->ip());

        return back()->with('ok', 'Company membership updated.');
    }

    public function deleteEmployee(Request $request, User $user)
    {
        $company = $this->company($request);
        $this->employees->remove($company, $request->user(), $user, $request->ip());

        return back()->with('ok', 'Company access removed. The account and conversation history were preserved.');
    }

    public function audit(Request $request)
    {
        $company = $this->company($request);

        $request->validate(['category' => ['nullable', Rule::in(array_keys(AuditLog::categories()))]]);
        $query = AuditLog::where('company_id', $company->id)->whereIn('action', AuditLog::importantActions())->with(['user', 'targetUser', 'company'])->latest('id');
        if ($request->filled('category')) {
            $query->whereIn('action', AuditLog::categories()[$request->input('category')]);
        }

        return view('admin.audit', ['logs' => $query->paginate(30)->withQueryString(), 'company' => $company, 'platformAudit' => false]);
    }

    public function privacy(Request $request)
    {
        $company = $this->company($request);

        return view('admin.privacy', [
            'company' => $company,
            'setting' => PrivacySetting::firstOrCreate(['company_id' => $company->id]),
        ]);
    }

    public function updatePrivacy(Request $request, UpdatePrivacyPolicy $policies)
    {
        abort_unless(! $request->user()->isPlatformSuperAdmin() && $request->user()->hasAnyRole(['company_super_admin']), 403);
        $company = $this->company($request);
        $data = $request->validate(['retention_days' => 'nullable|integer|min:1|max:36500', 'allow_admin_message_access' => 'required|boolean', 'privacy_contact_email' => 'nullable|email|max:190']);
        $policies->execute($request->user(), $company, $data, $request->ip());

        return back()->with('ok', 'Privacy policy saved. Retention deletion requires the configured scheduler or an explicit retention command.');
    }
}
