<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->uuid('client_message_id')->nullable();
            $table->string('submission_hash', 64)->nullable();
            $table->unique(['conversation_id', 'user_id', 'client_message_id'], 'messages_submission_unique');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique('messages_submission_unique');
            $table->dropColumn(['client_message_id', 'submission_hash']);
        });
    }
};
