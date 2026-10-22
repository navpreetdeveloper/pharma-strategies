<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->unsignedBigInteger('last_read_message_id')->default(0);
        });
        DB::table('conversation_participants')->whereNotNull('last_read_at')->orderBy('conversation_id')->orderBy('user_id')->chunk(200, function ($rows) {
            foreach ($rows as $row) {
                $id = DB::table('messages')->where('conversation_id', $row->conversation_id)->where('created_at', '<=', $row->last_read_at)->max('id') ?? 0;
                DB::table('conversation_participants')->where('conversation_id', $row->conversation_id)->where('user_id', $row->user_id)->update(['last_read_message_id' => $id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', fn (Blueprint $table) => $table->dropColumn('last_read_message_id'));
    }
};
