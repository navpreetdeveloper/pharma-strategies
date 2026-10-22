<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('platform_role')->nullable()->after('status');
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->string('status')->default('active')->after('data_residency');
        });

        DB::table('users')
            ->where('status', 'platform_super_admin')
            ->update([
                'platform_role' => 'super_admin',
                'status' => 'active',
            ]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('status');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('platform_role');
        });
    }
};
