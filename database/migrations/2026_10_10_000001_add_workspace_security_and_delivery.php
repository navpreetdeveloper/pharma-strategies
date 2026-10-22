<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->string('province', 40)->change());
        Schema::table('company_user', function (Blueprint $t) {
            $t->string('name', 120)->nullable();
            $t->string('employee_id', 80)->nullable();
            $t->string('department', 120)->nullable();
            $t->string('job_title', 120)->nullable();
        });
        DB::table('company_user')->orderBy('company_id')->get()->each(function ($membership) {
            $user = DB::table('users')->find($membership->user_id);
            if ($user) {
                DB::table('company_user')->where('company_id', $membership->company_id)->where('user_id', $membership->user_id)->update(['name' => $user->name, 'employee_id' => $user->employee_id, 'department' => $user->department, 'job_title' => $user->job_title]);
            }
        });
        Schema::table('users', function (Blueprint $t) {
            $t->text('two_factor_secret')->nullable();
            $t->text('two_factor_recovery_codes')->nullable();
            $t->timestamp('two_factor_confirmed_at')->nullable();
        });
        Schema::create('company_invitations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('created_by')->constrained('users');
            $t->string('token_hash', 64)->unique();
            $t->string('email', 190);
            $t->string('name', 120);
            $t->string('employee_id', 80)->nullable();
            $t->string('department', 120)->nullable();
            $t->string('job_title', 120)->nullable();
            $t->string('role');
            $t->timestamp('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->foreignId('accepted_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['company_id', 'expires_at']);
        });
        Schema::create('message_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamps();
            $t->unique(['message_id', 'user_id']);
        });
        Schema::create('jobs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });
        Schema::create('failed_jobs', function (Blueprint $t) {
            $t->id();
            $t->string('uuid')->unique();
            $t->text('connection');
            $t->text('queue');
            $t->longText('payload');
            $t->longText('exception');
            $t->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['failed_jobs', 'jobs', 'message_deliveries', 'company_invitations'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']));
        Schema::table('company_user', fn (Blueprint $t) => $t->dropColumn(['name', 'employee_id', 'department', 'job_title']));
        // Keep the widened province column to avoid truncating existing registrations.
    }
};
