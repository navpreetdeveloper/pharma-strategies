<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_deliveries', function (Blueprint $table) {
            $table->timestamp('push_queued_at')->nullable()->index('message_deliveries_push_pending_index');
        });
        // The previous implementation queued push before marking delivery done.
        // Preserve that state so an upgrade cannot replay historical notifications.
        DB::table('message_deliveries')->whereNotNull('delivered_at')
            ->update(['push_queued_at' => DB::raw('delivered_at')]);
    }

    public function down(): void
    {
        Schema::table('message_deliveries', function (Blueprint $table) {
            $table->dropIndex('message_deliveries_push_pending_index');
            $table->dropColumn('push_queued_at');
        });
    }
};
