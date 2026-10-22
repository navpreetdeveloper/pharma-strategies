<?php

namespace App\Services\Accounts;

use App\Models\AuditLog;
use App\Models\RemovedCompanyAccess;
use App\Models\User;
use Illuminate\Support\Collection;

class RecoverableCompanyAccess
{
    /** Batch lookup prevents a separate history query for each directory row. */
    public function forUsers(Collection $users): array
    {
        $snapshots = RemovedCompanyAccess::with('company')->whereIn('user_id', $users->pluck('id'))->get()->groupBy('user_id');
        $legacy = AuditLog::with('company')->where('action', 'employee_access_removed')
            ->whereIn('target_type', ['User', User::class])->whereIn('target_id', $users->pluck('id'))->latest('id')->get()->groupBy('target_id');
        $options = [];
        foreach ($users as $user) {
            $removed = [];
            foreach ($snapshots->get($user->id, collect()) as $snapshot) {
                if ($snapshot->company?->status === 'active' && ! $user->companies->contains('id', $snapshot->company_id)) {
                    $removed[$snapshot->company_id] = ['company' => $snapshot->company, 'role' => $snapshot->membership['role'] ?? null];
                }
            }
            foreach ($legacy->get($user->id, collect()) as $log) {
                if ($log->company?->status === 'active' && ! isset($removed[$log->company_id]) && ! $user->companies->contains('id', $log->company_id)) {
                    $removed[$log->company_id] = ['company' => $log->company, 'role' => null];
                }
            }
            $options[$user->id] = $removed;
        }

        return $options;
    }
}
