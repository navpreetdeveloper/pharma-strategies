<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Services\Companies\CompanyProvisioning;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class PlatformCompanyController extends Controller
{
    public function __construct(private readonly CompanyProvisioning $provisioning) {}

    public function index(Request $request)
    {
        $query = Company::query()
            ->withCount([
                'users as active_employees_count' => fn ($query) => $query->where('company_user.status', 'active'),
            ])
            ->orderBy('name');
        if ($request->filled('q')) {
            $query->where('name', 'like', '%'.addcslashes($request->string('q')->toString(), '%_').'%');
        }
        $companies = $query->paginate(25)->withQueryString();

        $request->validate(['company' => 'sometimes|integer|min:1']);
        $selected = $request->filled('company')
            ? Company::withCount(['users as active_employees_count' => fn ($query) => $query->where('company_user.status', 'active')])->findOrFail($request->integer('company'))
            : null;

        return view('platform.companies.index', [
            'companies' => $companies,
            'selected' => $selected,
            'provinces' => config('chat.provinces', []),
        ]);
    }

    public function store(Request $request)
    {
        if (is_string($request->input('admin_email'))) {
            $request->merge(['admin_email' => strtolower(trim($request->input('admin_email')))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'legal_name' => ['nullable', 'string', 'max:180'],
            'province' => ['required', Rule::in(config('chat.provinces', []))],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'admin_password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $company = $this->provisioning->create($request->user(), $data, $request->ip());

        return redirect()
            ->route('platform.companies.index', ['company' => $company->id])
            ->with('ok', 'Company and administrator created.');
    }

    public function update(Request $request, Company $company)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'legal_name' => ['nullable', 'string', 'max:180'],
            'province' => ['required', Rule::in(config('chat.provinces', []))],
        ]);

        DB::transaction(function () use ($request, $company, $data): void {
            $company->update($data);
            $this->record($request, $company, 'platform_company_updated');
        });

        return redirect()
            ->route('platform.companies.index', ['company' => $company->id])
            ->with('ok', 'Company details updated.');
    }

    public function setStatus(Request $request, Company $company)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'archived'])],
        ]);

        DB::transaction(function () use ($request, $company, $data): void {
            $company->update(['status' => $data['status']]);
            $this->record(
                $request,
                $company,
                $data['status'] === 'archived' ? 'platform_company_archived' : 'platform_company_restored',
            );
        });

        if ($data['status'] === 'archived' && (int) $request->session()->get('company_id') === $company->id) {
            $request->session()->forget('company_id');
        }

        return redirect()
            ->route('platform.companies.index', ['company' => $company->id])
            ->with('ok', $data['status'] === 'archived'
                ? 'Company archived. Its records were retained and employee workspace access is disabled.'
                : 'Company restored. Active members can access the workspace again.');
    }

    public function selectWorkspace(Request $request, Company $company)
    {
        abort_unless($company->status === 'active', 403, 'Archived company workspaces cannot be opened.');
        $request->session()->put('company_id', $company->id);

        $this->record($request, $company, 'platform_company_workspace_opened');

        return $request->input('next') === 'employees'
            ? redirect()->route('admin.employees')->with('ok', 'Company switched.')
            : redirect()->route('chat')->with('ok', 'Company switched.');
    }

    public function employees(Request $request)
    {
        if (Company::whereKey($request->session()->get('company_id'))->where('status', 'active')->exists()) {
            return redirect()->route('admin.employees');
        }

        return view('platform.employees', [
            'companies' => Company::orderBy('name')->get(['id', 'name', 'status']),
        ]);
    }

    public function selectEmployeeWorkspace(Request $request)
    {
        $data = $request->validate(['company_id' => ['required', 'integer']]);
        $company = Company::findOrFail($data['company_id']);
        $request->merge(['next' => 'employees']);

        return $this->selectWorkspace($request, $company);
    }

    private function record(Request $request, Company $company, string $action): void
    {
        AuditLog::create([
            'company_id' => $company->id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'target_type' => Company::class,
            'target_id' => $company->id,
            'ip_address' => $request->ip(),
        ]);
    }
}
