<?php

namespace App\Services\Companies;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\PrivacySetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CompanyProvisioning
{
    public function create(User $actor, array $data, ?string $ipAddress): Company
    {
        return DB::transaction(function () use ($actor, $data, $ipAddress): Company {
            abort_unless($actor->fresh()->isPlatformSuperAdmin(), 403);
            $company = Company::create([
                'name' => $data['name'],
                'legal_name' => $data['legal_name'] ?? null,
                'province' => $data['province'],
                'status' => 'active',
            ]);

            $admin = User::create([
                'name' => $data['admin_name'], 'email' => $data['admin_email'],
                'password' => Hash::make($data['admin_password']),
                'status' => 'active', 'must_change_password' => true,
            ]);
            $company->users()->attach($admin->id, [
                'name' => $data['admin_name'], 'role' => 'company_super_admin', 'status' => 'active',
            ]);
            PrivacySetting::create(['company_id' => $company->id, 'privacy_contact_email' => $admin->email]);
            AuditLog::create(['company_id' => $company->id, 'user_id' => $actor->id, 'action' => 'platform_company_created', 'target_type' => Company::class, 'target_id' => $company->id, 'ip_address' => $ipAddress]);
            AuditLog::create([
                'company_id' => $company->id, 'user_id' => $actor->id,
                'action' => 'platform_company_admin_created', 'target_type' => User::class,
                'target_id' => $admin->id, 'ip_address' => $ipAddress,
            ]);

            return $company;
        });

    }
}
