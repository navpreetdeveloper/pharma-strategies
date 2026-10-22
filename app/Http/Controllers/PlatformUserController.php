<?php

namespace App\Http\Controllers;

use App\Http\Requests\PlatformAccountActionRequest;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\Accounts\AccountLifecycle;
use App\Services\Accounts\RecoverableCompanyAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformUserController extends Controller
{
    public function __construct(private readonly AccountLifecycle $accounts, private readonly RecoverableCompanyAccess $removedAccess) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:120',
            'company_id' => 'nullable|integer|exists:companies,id',
            'status' => ['nullable', Rule::in(['active', 'suspended'])],
        ]);
        $query = User::with(['companies' => fn ($q) => $q->orderBy('name')])->orderBy('name')->orderBy('id');
        if ($request->filled('q')) {
            $term = '%'.addcslashes(trim($data['q']), '%_').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
        }
        if ($request->filled('company_id')) {
            $query->whereHas('companies', fn ($q) => $q->where('companies.id', $data['company_id']));
        }
        if ($request->filled('status')) {
            $query->where('status', $data['status']);
        }

        $users = $query->paginate(25)->withQueryString();
        $recoverable = $this->removedAccess->forUsers($users->getCollection());

        return view('platform.users.index', [
            'users' => $users, 'recoverableAccess' => $recoverable,
            'companies' => Company::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function restoreAccess(PlatformAccountActionRequest $request, User $user)
    {
        $data = $request->validated();
        $this->accounts->restoreAccess($request->user(), $user, $data, $request->ip());

        return back()->with('ok', 'Company access restored. Existing sign-in details are unchanged.');
    }

    public function destroy(PlatformAccountActionRequest $request, User $user)
    {
        $data = $request->validated();
        $this->accounts->destroy($request->user(), $user, $data, $request->ip());

        return redirect()->route('platform.users.index')->with('ok', 'Account permanently deleted. Shared chat history was retained.');
    }

    public function resetPassword(PlatformAccountActionRequest $request, User $user)
    {
        abort_if($user->platform_role === 'super_admin', 403, 'Platform administrators must use their own password recovery.');
        abort_unless($user->status === 'active', 422, 'Password recovery does not reactivate suspended accounts.');
        $data = $request->validated();
        $this->accounts->resetPassword($request->user(), $user, $data, $request->ip());

        return back()->with('ok', 'Temporary password set. The user must change it at sign-in.');
    }

    public function audit(Request $request)
    {
        $request->validate(['company_id' => 'nullable|integer|exists:companies,id', 'category' => ['nullable', Rule::in(array_keys(AuditLog::categories()))]]);
        $query = AuditLog::with(['user', 'targetUser', 'company'])->whereIn('action', AuditLog::importantActions())->latest('id');
        if ($request->filled('company_id')) {
            $query->where('company_id', $request->integer('company_id'));
        }
        if ($request->filled('category')) {
            $query->whereIn('action', AuditLog::categories()[$request->input('category')]);
        }

        return view('admin.audit', [
            'logs' => $query->paginate(30)->withQueryString(), 'platformAudit' => true,
            'companies' => Company::orderBy('name')->get(['id', 'name']), 'company' => null,
        ]);
    }
}
