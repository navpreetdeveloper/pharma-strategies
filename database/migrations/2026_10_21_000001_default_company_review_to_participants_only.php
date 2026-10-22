<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Change only the default; preserve every company's existing decision.
        Schema::table('privacy_settings', function (Blueprint $table) {
            $table->boolean('allow_admin_message_access')->default(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('privacy_settings', function (Blueprint $table) {
            $table->boolean('allow_admin_message_access')->default(true)->change();
        });
    }
};
