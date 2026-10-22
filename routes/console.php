<?php

use App\Jobs\DeliverWorkplaceMessage;
use App\Services\PrivateFileCleanup;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('pharma:about', function () {
    $this->info('Pharma Strategies — private workplace communication for Canadian pharmacy teams.');
})->purpose('Show application information');

Artisan::command('chat:retry-deliveries', function () {
    $count = 0;
    foreach (DB::table('message_deliveries')->whereNull('delivered_at')->orderBy('id')->limit(100)->get() as $row) {
        DeliverWorkplaceMessage::dispatch((int) $row->message_id, (int) $row->user_id);
        $count++;
    }
    // Separate indexed scans avoid a full-table OR query and duplicate rows.
    foreach (DB::table('message_deliveries')->whereNull('push_queued_at')->whereNotNull('delivered_at')->orderBy('id')->limit(100)->get() as $row) {
        DeliverWorkplaceMessage::dispatch((int) $row->message_id, (int) $row->user_id);
        $count++;
    }
    $this->info("Queued {$count} pending deliveries.");
})->purpose('Recover persisted message deliveries after queue or transport outages');
Schedule::command('chat:retry-deliveries')->everyMinute()->withoutOverlapping();

if (config('chat.retention_scheduled', false)) {
    Schedule::command('chat:prune --apply')->dailyAt('03:00')->withoutOverlapping();
}

Artisan::command('chat:cleanup-files', function () {
    $this->info('Removed '.PrivateFileCleanup::flush().' pending private files.');
})->purpose('Retry private attachment deletion after filesystem outages');
Schedule::command('chat:cleanup-files')->everyFiveMinutes()->withoutOverlapping();
