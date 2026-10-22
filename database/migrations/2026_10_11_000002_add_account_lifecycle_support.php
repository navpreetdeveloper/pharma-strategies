<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('removed_company_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('membership');
            $table->timestamp('removed_at');
            $table->unique(['company_id', 'user_id']);
        });
        Schema::table('messages', fn (Blueprint $table) => $table->dropForeign(['user_id']));
        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('company_invitations', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['accepted_by']);
        });
        Schema::table('company_invitations', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->change();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('accepted_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('audit_logs', fn (Blueprint $table) => $table->index(['action', 'target_id'], 'audit_action_target_index'));
    }

    public function down(): void
    {
        if (DB::table('messages')->whereNull('user_id')->exists() || DB::table('company_invitations')->whereNull('created_by')->exists()) {
            throw new RuntimeException('Anonymous chat history exists. Restore a compatible release instead of reverting this migration.');
        }
        Schema::table('messages', fn (Blueprint $table) => $table->dropForeign(['user_id']));
        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        Schema::table('company_invitations', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['accepted_by']);
        });
        Schema::table('company_invitations', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable(false)->change();
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('accepted_by')->references('id')->on('users');
        });
        Schema::table('audit_logs', fn (Blueprint $table) => $table->dropIndex('audit_action_target_index'));
        Schema::dropIfExists('removed_company_access');
    }
};
