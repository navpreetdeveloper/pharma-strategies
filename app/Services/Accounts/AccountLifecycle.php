<?php

namespace App\Services\Accounts;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\RemovedCompanyAccess;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Transactional account operations. HTTP validation and presentation belong to callers. */
class AccountLifecycle
{
    public function __construct(private readonly AccountSessions $sessions) {}

    private function verifyActor(User $administrator, string $password): User
    {
        $actor = User::lockForUpdate()->findOrFail($administrator->id);
        abort_unless($actor->isPlatformSuperAdmin(), 403);
        if (! Hash::check($password, $actor->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is incorrect.']);
        }

        return $actor;
    }

    public function restoreAccess(User $administrator, User $user, array $data, ?string $ipAddress): void
    {
        DB::transaction(function () use ($administrator, $user, $data, $ipAddress) {
            $actor = $this->verifyActor($administrator, $data['current_password']);
            $company = Company::whereKey($data['company_id'])->where('status', 'active')->lockForUpdate()->firstOrFail();
            $target = User::lockForUpdate()->findOrFail($user->id);
            abort_if($target->platform_role === 'super_admin', 403);
            if ($target->status !== 'active') {
                throw ValidationException::withMessages(['company_id' => 'A suspended account cannot be restored through company access recovery.']);
            }
            if ($company->users()->whereKey($target->id)->exists()) {
                throw ValidationException::withMessages(['company_id' => 'This account already has a company membership.']);
            }
            $snapshot = RemovedCompanyAccess::where('company_id', $company->id)->where('user_id', $target->id)->first();
            $legacyRemoval = AuditLog::where('company_id', $company->id)->where('action', 'employee_access_removed')->where('target_id', $target->id)->whereIn('target_type', ['User', User::class])->exists();
            if (! $snapshot && ! $legacyRemoval) {
                throw ValidationException::withMessages(['company_id' => 'No removed access was found for this company.']);
            }
            $membership = $snapshot?->membership ?? ['role' => $data['role'], 'name' => $target->name, 'employee_id' => $target->employee_id, 'department' => $target->department, 'job_title' => $target->job_title];
            $company->users()->attach($target->id, $membership + ['status' => 'active']);
            $snapshot?->delete();
            AuditLog::create(['company_id' => $company->id, 'user_id' => $actor->id, 'action' => 'employee_access_restored', 'target_type' => 'User', 'target_id' => $target->id, 'ip_address' => $ipAddress, 'metadata' => ['reason' => trim($data['reason']), 'role' => $membership['role']]]);
        });
    }

    public function destroy(User $administrator, User $user, array $data, ?string $ipAddress): void
    {
        DB::transaction(function () use ($administrator, $user, $data, $ipAddress) {
            $actor = $this->verifyActor($administrator, $data['current_password']);
            $companies = Company::whereHas('users', fn ($q) => $q->where('users.id', $user->id))->orderBy('id')->lockForUpdate()->get();
            $target = User::lockForUpdate()->findOrFail($user->id);
            abort_if($target->id === $actor->id || $target->platform_role === 'super_admin', 403, 'Platform administrator accounts are protected.');
            foreach ($companies as $company) {
                if ($company->users()->whereKey($target->id)->wherePivot('role', 'company_super_admin')->exists()) {
                    if (! $company->users()->where('users.id', '!=', $target->id)->where('users.status', 'active')->wherePivot('role', 'company_super_admin')->wherePivot('status', 'active')->exists()) {
                        throw ValidationException::withMessages(['confirmation_email' => 'Assign another company administrator before deleting this account.']);
                    }
                }
            }
            AuditLog::create(['user_id' => $actor->id, 'action' => 'platform_account_deleted', 'target_type' => 'User', 'target_id' => $target->id, 'ip_address' => $ipAddress, 'metadata' => ['reason' => trim($data['reason']), 'shared_history_retained' => true]]);
            $this->sessions->revoke($target);
            $target->notifications()->delete();
            DB::table('company_invitations')->where('email', $target->email)->whereNull('accepted_at')->delete();
            $target->delete();
        });
    }

    public function resetPassword(User $administrator, User $user, array $data, ?string $ipAddress): void
    {
        DB::transaction(function () use ($administrator, $user, $data, $ipAddress) {
            $actor = $this->verifyActor($administrator, $data['current_password']);
            $target = User::lockForUpdate()->findOrFail($user->id);
            abort_if($target->platform_role === 'super_admin', 403);
            abort_unless($target->status === 'active', 422);
            if (Hash::check($data['password'], $target->password)) {
                throw ValidationException::withMessages(['password' => 'Choose a different temporary password.']);
            }
            $target->forceFill([
                'password' => Hash::make($data['password']), 'must_change_password' => true,
                'remember_token' => Str::random(60),
            ])->save();
            $this->sessions->revoke($target);
            AuditLog::create([
                'company_id' => null, 'user_id' => $actor->id, 'action' => 'platform_password_reset',
                'target_type' => User::class, 'target_id' => $target->id, 'ip_address' => $ipAddress,
                'metadata' => ['reason' => trim($data['reason']), 'identity_verified' => true],
            ]);
        });
    }
}
