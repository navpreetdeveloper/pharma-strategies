<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Message;
use App\Models\PrivacySetting;
use App\Services\PrivateFileCleanup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneWorkspaceHistory extends Command
{
    protected $signature = 'chat:prune {--apply : Permanently delete expired history using each company retention policy}';

    protected $description = 'Preview or apply configured company retention to messages, private attachments, and notifications';

    public function handle(): int
    {
        foreach (PrivacySetting::whereNotNull('retention_days')->where('retention_days', '>', 0)->cursor() as $policy) {
            $cutoff = now()->subDays($policy->retention_days);
            $query = Message::where('company_id', $policy->company_id)->where('created_at', '<', $cutoff);
            $this->info("Company {$policy->company_id}: {$query->count()} expired messages.");
            if (! $this->option('apply')) {
                continue;
            }
            $query->with('attachments')->chunkById(100, function ($messages) use ($policy) {
                foreach ($messages as $message) {
                    $paths = $message->attachments->pluck('path')->all();
                    DB::transaction(function () use ($message, $policy, $paths) {
                        PrivateFileCleanup::stage($policy->company_id, $paths);
                        $message->delete();
                        AuditLog::create(['company_id' => $policy->company_id, 'action' => 'retention_message_deleted', 'target_type' => 'Message', 'target_id' => $message->id]);
                    });
                    PrivateFileCleanup::flush();
                }
            });
            DB::table('notifications')->where('created_at', '<', $cutoff)->orderBy('id')->chunkById(100, function ($rows) use ($policy) {
                $ids = $rows->filter(function ($row) use ($policy) {
                    $data = json_decode($row->data, true);

                    return (int) ($data['data']['company_id'] ?? 0) === (int) $policy->company_id;
                })->pluck('id');
                DB::table('notifications')->whereIn('id', $ids)->delete();
            });
        }
        if (! $this->option('apply')) {
            $this->comment('Preview only. Use --apply after approving the retention policy and backup lifecycle.');
        }

        return self::SUCCESS;
    }
}
