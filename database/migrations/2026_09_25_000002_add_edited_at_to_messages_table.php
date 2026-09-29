<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->timestamp('edited_at')->nullable()->after('message_type')->index();
        });

        Schema::table('attachments', function (Blueprint $table): void {
            $table->index(['company_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropIndex('attachments_company_id_message_id_index');
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('messages_edited_at_index');
            $table->dropColumn('edited_at');
        });
    }
};
