<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('direct_key', 80)->nullable()->after('type');
            $table->index(['company_id', 'type', 'direct_key']);
        });

        // Backfill direct keys and merge duplicate direct conversations created by older builds.
        $directs = DB::table('conversations')->where('type', 'direct')->orderBy('id')->get();
        $seen = [];
        foreach ($directs as $conversation) {
            $ids = DB::table('conversation_participants')->where('conversation_id', $conversation->id)->orderBy('user_id')->pluck('user_id')->all();
            if (count($ids) !== 2) {
                continue;
            }
            $key = min($ids) . ':' . max($ids);
            $scopeKey = $conversation->company_id . ':' . $key;
            if (isset($seen[$scopeKey])) {
                $canonicalId = $seen[$scopeKey];
                DB::table('messages')->where('conversation_id', $conversation->id)->update(['conversation_id' => $canonicalId]);
                DB::table('conversation_participants')->where('conversation_id', $conversation->id)->delete();
                DB::table('conversations')->where('id', $conversation->id)->delete();
                continue;
            }
            $seen[$scopeKey] = $conversation->id;
            DB::table('conversations')->where('id', $conversation->id)->update(['direct_key' => $key]);
        }

        Schema::table('conversations', function (Blueprint $table): void {
            $table->unique(['company_id', 'direct_key'], 'conversations_company_direct_unique');
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->timestamp('deleted_at')->nullable()->after('edited_at')->index();
            $table->foreignId('deleted_by')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropForeign(['deleted_by']);
            $table->dropIndex(['deleted_at']);
            $table->dropColumn(['deleted_at', 'deleted_by']);
        });
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropUnique('conversations_company_direct_unique');
            $table->dropIndex(['company_id', 'type', 'direct_key']);
            $table->dropColumn('direct_key');
        });
    }
};
