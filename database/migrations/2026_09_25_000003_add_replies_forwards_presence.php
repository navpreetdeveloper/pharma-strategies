<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->foreignId('reply_to_message_id')->nullable()->after('user_id')->constrained('messages')->nullOnDelete();
            $table->foreignId('forwarded_from_message_id')->nullable()->after('reply_to_message_id')->constrained('messages')->nullOnDelete();
            $table->index(['conversation_id', 'reply_to_message_id']);
            $table->index(['conversation_id', 'forwarded_from_message_id']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('last_seen_at')->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropForeign(['reply_to_message_id']);
            $table->dropForeign(['forwarded_from_message_id']);
            $table->dropIndex(['conversation_id', 'reply_to_message_id']);
            $table->dropIndex(['conversation_id', 'forwarded_from_message_id']);
            $table->dropColumn(['reply_to_message_id', 'forwarded_from_message_id']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
    }
};
