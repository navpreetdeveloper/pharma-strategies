<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ManagePlatformSuperAdmin extends Command
{
    protected $signature = 'platform:super-admin {email : Existing user email} {action : grant or revoke} {--force : Skip the interactive confirmation}';

    protected $description = 'Grant or revoke platform-wide Super Admin access for an existing account';

    public function handle(): int
    {
        $action = $this->argument('action');
        if (! in_array($action, ['grant', 'revoke'], true)) {
            $this->error('Action must be either grant or revoke.');

            return self::INVALID;
        }

        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No account exists for that email address.');

            return self::FAILURE;
        }

        if ($action === 'grant' && $user->status !== 'active') {
            $this->error('Only an active user account can be granted Platform Super Admin access.');

            return self::FAILURE;
        }

        $warning = $action === 'grant'
            ? "Grant platform-wide access to {$user->email} and remove all company memberships? The account and company records will remain."
            : "Revoke platform access from {$user->email}? If this account has no company memberships, it will no longer be able to sign in.";
        if (! $this->option('force') && ! $this->confirm($warning)) {
            $this->info('No changes made.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($user, $action): void {
            if ($action === 'grant') {
                foreach ($user->companies()->orderBy('companies.id')->get() as $company) {
                    Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
                    if ($company->pivot->role === 'company_super_admin') {
                        throw new \RuntimeException('Transfer company administrator responsibilities before removing this membership.');
                    }
                }
                $user->companies()->detach();
                $user->forceFill(['platform_role' => 'super_admin'])->save();
                $auditAction = 'platform_super_admin_granted';
            } else {
                $user->forceFill(['platform_role' => null])->save();
                $auditAction = 'platform_super_admin_revoked';
            }

            \DB::table('sessions')->where('user_id', $user->id)->delete();
            AuditLog::create([
                'company_id' => null,
                'user_id' => $user->id,
                'action' => $auditAction,
                'target_type' => User::class,
                'target_id' => $user->id,
                'metadata' => ['account_email' => $user->email],
            ]);
        });

        $this->info($action === 'grant'
            ? "Platform Super Admin access granted to {$user->email}. Company memberships were removed without deleting their data."
            : "Platform Super Admin access revoked from {$user->email}.");

        return self::SUCCESS;
    }
}
