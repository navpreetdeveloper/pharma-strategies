<?php

use App\Models\Company;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\SendMessage;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Transport benchmark only. This helper refuses the application database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$driver = DB::getDriverName();
$path = config('database.connections.'.$driver.'.database');
$prefix = realpath(storage_path('app/private/performance')).DIRECTORY_SEPARATOR;
$sqliteFixture = $driver === 'sqlite' && str_starts_with(realpath($path) ?: '', $prefix) && preg_match('/queue-[a-f0-9-]+\.sqlite$/', $path);
$mysqlFixture = $driver === 'mysql' && preg_match('/^pharma_probe_[a-f0-9]{32}$/', $path)
    && in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost', '::1'], true);
if (! app()->environment('performance') || (! $sqliteFixture && ! $mysqlFixture)) {
    throw new RuntimeException('Use a dedicated disposable performance fixture.');
}
$action = $argv[1] ?? '';
if ($action === 'drop-browser') {
    if (! $mysqlFixture) {
        throw new RuntimeException('Only disposable MySQL fixtures support this action.');
    }
    DB::statement('DROP DATABASE `'.$path.'`');
    exit;
}
if (in_array($action, ['setup', 'setup-browser'], true)) {
    if ($mysqlFixture) {
        // Connect without selecting a database, then create a new UUID name.
        // CREATE without IF NOT EXISTS refuses reuse of an existing fixture.
        config(['database.connections.mysql.database' => null]);
        DB::purge();
        DB::statement('CREATE DATABASE `'.$path.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        config(['database.connections.mysql.database' => $path]);
        DB::purge();
    } elseif (filesize($path) !== 0) {
        throw new RuntimeException('Refusing to seed an existing database.');
    }
    $kernel->call('migrate', ['--force' => true]);
    $base = random_int(1000000000, 1500000000);
    $browser = $action === 'setup-browser';
    $password = $browser ? bin2hex(random_bytes(12)).'Aa1!' : null;
    $hash = password_hash($password ?? bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
    $company = Company::forceCreate(['id' => $base + 1, 'name' => 'Synthetic queue probe', 'province' => 'Ontario']);
    foreach ([$base + 2, $base + 3] as $id) {
        $user = User::forceCreate(['id' => $id, 'name' => 'Synthetic probe '.$id, 'email' => 'probe-'.$id.'@example.test', 'password' => $hash, 'status' => 'active']);
        $company->users()->attach($user->id, ['role' => 'staff', 'status' => 'active']);
    }
    $conversation = Conversation::forceCreate(['id' => $base + 4, 'company_id' => $company->id, 'type' => 'direct']);
    $conversation->participants()->attach([$base + 2, $base + 3]);
    $result = ['recipient' => $base + 2];
    if ($browser) {
        $admin = User::forceCreate(['id' => $base + 5, 'name' => 'Synthetic company administrator', 'email' => 'probe-admin-'.$base.'@example.test', 'password' => $hash, 'status' => 'active']);
        $company->users()->attach($admin->id, ['role' => 'company_super_admin', 'status' => 'active']);
        $platform = User::forceCreate(['id' => $base + 6, 'name' => 'Synthetic platform administrator', 'email' => 'probe-platform-'.$base.'@example.test', 'password' => $hash, 'status' => 'active', 'platform_role' => 'super_admin']);
        Company::forceCreate(['id' => $base + 7, 'name' => 'Second synthetic pharmacy', 'province' => 'Ontario']);
        $result += ['sender' => $base + 3, 'conversation' => $conversation->id, 'password' => $password, 'recipient_email' => 'probe-'.($base + 2).'@example.test', 'sender_email' => 'probe-'.($base + 3).'@example.test', 'admin_email' => $admin->email, 'platform_email' => $platform->email];
    }
    echo json_encode($result).PHP_EOL;
} elseif ($action === 'enqueue') {
    $conversation = Conversation::firstOrFail();
    $actor = User::orderByDesc('id')->firstOrFail();
    $start = microtime(true) * 1000;
    $message = app(SendMessage::class)->execute($actor, $conversation, ['body' => 'Synthetic queue probe'], []);
    echo json_encode(['message_id' => $message->id, 'started_ms' => $start, 'persist_ms' => microtime(true) * 1000 - $start]).PHP_EOL;
} elseif ($action === 'status') {
    echo json_encode(['pending' => DB::table('jobs')->count(), 'undelivered' => DB::table('message_deliveries')->whereNull('delivered_at')->count()]).PHP_EOL;
} else {
    throw new RuntimeException('Unknown performance fixture action.');
}
