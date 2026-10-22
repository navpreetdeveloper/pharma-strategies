<?php

// Read-only production-schema inspection. Never output record bodies or credentials.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\Chat\ConversationDirectory;
use Illuminate\Support\Facades\DB;

if (DB::getDriverName() !== 'mysql') {
    throw new RuntimeException('This audit requires the local MySQL/MariaDB connection.');
}
config(['session.driver' => 'array']);
$report = ['database_version' => DB::selectOne('select version() as version')->version, 'tables' => [], 'plans' => []];
foreach (['companies', 'users', 'company_user', 'conversations', 'conversation_participants', 'messages', 'attachments', 'message_deliveries', 'notifications', 'sessions', 'audit_logs', 'jobs'] as $table) {
    $report['tables'][$table] = ['rows' => DB::table($table)->count(), 'indexes' => DB::select('SHOW INDEX FROM `'.$table.'`')];
}
$actor = User::where('status', 'active')->whereNull('platform_role')->whereHas('companies')->first();
if ($actor && $company = $actor->companies()->where('companies.status', 'active')->wherePivot('status', 'active')->first()) {
    session()->put('company_id', $company->id);
    $directory = app(ConversationDirectory::class)->query($actor, $company->id)->orderByDesc('messages_max_created_at')->orderByDesc('id')->limit(50);
    $report['plans']['conversation_directory'] = DB::select('EXPLAIN '.$directory->toSql(), $directory->getBindings());
    $conversationId = DB::table('conversations')->where('company_id', $company->id)->value('id')
        ?? DB::table('conversations')->value('id');
    if ($conversationId) {
        $report['plans']['message_updates'] = DB::select('EXPLAIN select * from messages where conversation_id = ? and id > ? order by id limit 100', [$conversationId, 0]);
        $report['plans']['message_history'] = DB::select('EXPLAIN select * from messages where conversation_id = ? order by created_at desc, id desc limit 51', [$conversationId]);
    }
    $report['plans']['company_activity'] = DB::select('EXPLAIN select * from audit_logs where company_id = ? and action in (?, ?) order by id desc limit 30', [$company->id, 'employee_created', 'employee_access_removed']);
}
$report['queue'] = ['pending' => DB::table('jobs')->selectRaw('queue, count(*) as count')->groupBy('queue')->get(), 'undelivered' => DB::table('message_deliveries')->whereNull('delivered_at')->count(), 'failed' => DB::table('failed_jobs')->count()];
$report['plans']['pending_delivery_recovery'] = DB::select('EXPLAIN select * from message_deliveries where delivered_at is null order by id limit 100');
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
