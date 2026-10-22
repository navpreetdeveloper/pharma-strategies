<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReportHealth extends Command
{
    protected $signature = 'pharma:health';

    protected $description = 'Report queue and provider acceptance metadata without exposing account data';

    public function handle(): int
    {
        $report = [
            'environment' => app()->environment(),
            'queue_connection' => config('queue.default'),
            'pending_jobs' => config('queue.default') === 'database' ? DB::table('jobs')->selectRaw('queue, count(*) as count')->groupBy('queue')->get() : 'Inspect the configured queue backend; database depth is not applicable.',
            'pending_message_deliveries' => DB::table('message_deliveries')->whereNull('delivered_at')->count(),
            'pending_push_dispatches' => DB::table('message_deliveries')->whereNull('push_queued_at')->count(),
            'historical_failed_jobs' => DB::table('failed_jobs')->count(),
            'subscribed_devices' => DB::table('push_subscriptions')->count(),
            'last_provider_acceptance' => DB::table('push_subscriptions')->max('last_delivery_at'),
            'recent_provider_failures' => DB::table('push_subscriptions')->where('last_failure_at', '>=', now()->subMinutes(15))->count(),
            'device_delivery' => 'Not measured; provider acceptance is not a device receipt.',
        ];
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
