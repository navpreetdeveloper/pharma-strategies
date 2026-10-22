<?php

namespace App\Services\Companies;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\PrivacySetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdatePrivacyPolicy
{
    public function execute(User $actor, Company $company, array $data, ?string $ipAddress): void
    {
        DB::transaction(function () use ($actor, $company, $data, $ipAddress) {
            Company::whereKey($company->id)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $actor = $actor->fresh();
            abort_unless($actor && ! $actor->isPlatformSuperAdmin() && $actor->activeInCompany($company->id)
                && $actor->companies()->where('companies.id', $company->id)->wherePivot('role', 'company_super_admin')->wherePivot('status', 'active')->exists(), 403);

            PrivacySetting::updateOrCreate(['company_id' => $company->id], $data);
            AuditLog::create(['company_id' => $company->id, 'user_id' => $actor->id, 'action' => 'privacy_policy_updated', 'metadata' => $data, 'ip_address' => $ipAddress]);
        });
    }
}
