<?php

namespace App\Services\Accounts;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\RemovedCompanyAccess;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class EmployeeAccess
{
    private function isCompanyAdministrator(User $actor, Company $company): bool
    {
        return $actor->isPlatformSuperAdmin() || ($actor->status === 'active' && $company->users()->whereKey($actor->id)->wherePivot('role', 'company_super_admin')->wherePivot('status', 'active')->exists());
    }

    private function authorizeManager(User $actor, Company $company): void
    {
        abort_unless($actor->status === 'active' && ($actor->isPlatformSuperAdmin() || $company->users()->whereKey($actor->id)->wherePivotIn('role', ['company_super_admin', 'pharmacy_manager'])->wherePivot('status', 'active')->exists()), 403);
    }

    public function create(Company $company, User $administrator, array $data, ?string $ipAddress): void
    {
        DB::transaction(function () use ($company, $administrator, $data, $ipAddress) {
            Company::whereKey($company->id)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $actor = $administrator->fresh();
            $this->authorizeManager($actor, $company);
            abort_if($data['role'] === 'company_super_admin' && ! $this->isCompanyAdministrator($actor, $company), 403);
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password']), 'status' => 'active', 'must_change_password' => $data['role'] === 'company_super_admin']);
            $company->users()->attach($user->id, [
                'name' => $data['name'], 'role' => $data['role'], 'status' => 'active',
                'employee_id' => $data['employee_id'] ?? null, 'department' => $data['department'] ?? null, 'job_title' => $data['job_title'] ?? null,
            ]);
            AuditLog::create(['company_id' => $company->id, 'user_id' => $actor->id, 'action' => 'employee_created', 'target_type' => 'User', 'target_id' => $user->id, 'metadata' => ['role' => $data['role']], 'ip_address' => $ipAddress]);
        });
    }

    public function update(Company $company, User $administrator, User $user, array $data, ?string $ipAddress): void
    {
        DB::transaction(function () use ($company, $user, $administrator, $data, $ipAddress) {
            Company::whereKey($company->id)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $administrator = $administrator->fresh();
            $this->authorizeManager($administrator, $company);
            abort_if($data['role'] === 'company_super_admin' && ! $this->isCompanyAdministrator($administrator, $company), 403);
            $member = $company->users()->whereKey($user->id)->firstOrFail();
            abort_if($member->pivot->role === 'company_super_admin' && ! $this->isCompanyAdministrator($administrator, $company), 403, 'Managers cannot modify company administrators.');
            abort_if($member->pivot->role === 'company_super_admin' && ($data['role'] !== $member->pivot->role || $data['status'] !== $member->pivot->status), 422, 'Company administrator access is protected.');
            abort_if($user->id === $administrator->id && ($data['role'] !== $member->pivot->role || $data['status'] !== $member->pivot->status), 422, 'You cannot change your own role or suspend your access.');
            $company->users()->updateExistingPivot($user->id, $data);
            if ($data['status'] === 'suspended') {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            AuditLog::create(['company_id' => $company->id, 'user_id' => $administrator->id, 'action' => 'employee_updated', 'target_type' => 'User', 'target_id' => $user->id, 'metadata' => ['scope' => 'company_membership', 'role' => $data['role'], 'status' => $data['status']], 'ip_address' => $ipAddress]);
        });
    }

    public function remove(Company $company, User $administrator, User $user, ?string $ipAddress): void
    {
        DB::transaction(function () use ($company, $user, $administrator, $ipAddress) {
            Company::whereKey($company->id)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $administrator = $administrator->fresh();
            $this->authorizeManager($administrator, $company);
            abort_unless($this->isCompanyAdministrator($administrator, $company), 403);
            $member = $company->users()->whereKey($user->id)->firstOrFail();
            abort_if($user->id === $administrator->id || $member->pivot->role === 'company_super_admin', 422, 'Company administrators and your own access are protected.');
            RemovedCompanyAccess::updateOrCreate(['company_id' => $company->id, 'user_id' => $user->id], [
                'membership' => collect($member->pivot->getAttributes())->only(['role', 'name', 'employee_id', 'department', 'job_title'])->all(),
                'removed_by' => $administrator->id, 'removed_at' => now(),
            ]);
            $company->users()->detach($user->id);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            AuditLog::create(['company_id' => $company->id, 'user_id' => $administrator->id, 'action' => 'employee_access_removed', 'target_type' => 'User', 'target_id' => $user->id, 'metadata' => ['scope' => 'company_access_removed'], 'ip_address' => $ipAddress]);
        });
    }
}
