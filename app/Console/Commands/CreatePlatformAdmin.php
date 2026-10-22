<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:create-admin {email} {--name=Platform Administrator} {--password : Enter a password privately instead of generating one}';

    protected $description = 'Create a standalone platform administrator with email and password login';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $name = $this->option('name');
        $validator = Validator::make(['email' => $email, 'name' => $name], ['email' => 'required|email|max:190', 'name' => 'required|string|max:120']);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::INVALID;
        }
        $existing = User::where('email', $email)->first();
        if ($existing) {
            if ($existing->isPlatformSuperAdmin()) {
                $this->info('This account already has platform access. Its password was preserved.');

                return self::SUCCESS;
            }
            if ($existing->companies()->wherePivot('role', 'company_super_admin')->exists()) {
                $this->error('This account is a protected company administrator. Use a separate email for platform access.');

                return self::FAILURE;
            }

            return $this->call('platform:super-admin', ['email' => $email, 'action' => 'grant', '--force' => true]);
        }
        $password = $this->option('password') ? $this->secret('Enter the account password') : 'Ps!'.bin2hex(random_bytes(12)).'9';
        $validator = Validator::make(['password' => $password], ['password' => ['required', 'string', Password::defaults()]]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::INVALID;
        }
        DB::transaction(function () use ($email, $name, $password) {
            $user = User::create(['email' => $email, 'name' => $name, 'password' => Hash::make($password), 'status' => 'active', 'platform_role' => 'super_admin', 'must_change_password' => false]);
            AuditLog::create(['user_id' => $user->id, 'action' => 'platform_super_admin_created', 'target_type' => User::class, 'target_id' => $user->id, 'metadata' => ['source' => 'console']]);
        });
        $this->info('Platform administrator created.');
        $this->line('Email: '.$email);
        if (! $this->option('password')) {
            $this->line('Password: '.$password);
        }

        return self::SUCCESS;
    }
}
