<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_deliveries', fn (Blueprint $table) => $table->index('delivered_at', 'message_deliveries_pending_index'));
        Schema::table('push_subscriptions', function (Blueprint $table): void {
            $table->timestamp('last_delivery_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->unsignedSmallInteger('last_transport_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('message_deliveries', fn (Blueprint $table) => $table->dropIndex('message_deliveries_pending_index'));
        Schema::table('push_subscriptions', fn (Blueprint $table) => $table->dropColumn(['last_delivery_at', 'last_failure_at', 'last_http_status', 'last_transport_error']));
    }
};
