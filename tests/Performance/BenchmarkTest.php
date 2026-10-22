<?php

namespace Tests\Performance;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BenchmarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_synthetic_request_workloads(): void
    {
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Queue::fake();
        $password = 'SyntheticBenchmark1!';
        $hash = Hash::make($password, ['rounds' => 12]);
        $admin = User::factory()->create(['password' => $hash]);
        $staff = User::factory()->create(['password' => $hash]);
        $peer = User::factory()->create(['password' => $hash]);
        $company = Company::create(['name' => 'Synthetic pharmacy', 'province' => 'Ontario']);
        $company->users()->attach([$admin->id => ['role' => 'company_super_admin', 'status' => 'active'], $staff->id => ['role' => 'staff', 'status' => 'active'], $peer->id => ['role' => 'pharmacist', 'status' => 'active']]);
        $conversation = null;
        $historySize = (int) (getenv('PERFORMANCE_HISTORY_SIZE') ?: 10000);
        $this->assertContains($historySize, [1000, 10000]);
        foreach (range(1, 10) as $number) {
            $thread = Conversation::create(['company_id' => $company->id, 'type' => 'group', 'name' => 'Synthetic thread '.$number]);
            $thread->participants()->attach([$admin->id, $staff->id, $peer->id]);
            $conversation ??= $thread;
            $size = $number === 1 ? $historySize : 100;
            for ($offset = 0; $offset < $size; $offset += 100) {
                $rows = [];
                foreach (range(1, 100) as $i) {
                    $rows[] = ['company_id' => $company->id, 'conversation_id' => $thread->id, 'user_id' => $peer->id, 'body' => 'Synthetic performance fixture', 'message_type' => 'text', 'created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-01 12:00:00'];
                }
                DB::table('messages')->insert($rows);
            }
        }
        $lastId = $conversation->messages()->max('id');
        $metrics = [];
        $measure = function (string $name, callable $request, ?User $actor = null) use (&$metrics, $company): void {
            $samples = [];
            foreach (range(1, 5) as $iteration) {
                if ($actor) {
                    $this->actingAs($actor->fresh())->withSession(['company_id' => $company->id]);
                } else {
                    auth()->logout();
                    auth()->forgetGuards();
                }
                DB::enableQueryLog();
                DB::flushQueryLog();
                $start = hrtime(true);
                $response = $request();
                $elapsed = (hrtime(true) - $start) / 1e6;
                $queries = DB::getQueryLog();
                DB::disableQueryLog();
                if ($name === 'login_response') {
                    $response->assertRedirect();
                } else {
                    $response->assertSuccessful();
                }
                $duplicates = array_count_values(array_column($queries, 'query'));
                arsort($duplicates);
                if (in_array($name, ['conversation_open', 'conversation_partial'], true)) {
                    $this->assertSame(50, preg_match_all('/\bdata-message-id="\d+"/', $response->getContent()));
                }
                $samples[] = ['ms' => round($elapsed, 3), 'response_bytes' => strlen($response->getContent()), 'queries' => count($queries), 'sql_ms' => round(array_sum(array_column($queries, 'time')), 3), 'repeated_sql' => array_slice($duplicates, 0, 4, true)];
            }
            $times = array_column($samples, 'ms');
            sort($times);
            $metrics[$name] = ['median_ms' => $times[2], 'max_ms' => max($times), 'samples' => $samples];
        };
        $measure('home', fn () => $this->get('/'));
        $measure('login_form', fn () => $this->get('/login'));
        $measure('company_dashboard', fn () => $this->get(route('company.dashboard')), $admin);
        $measure('conversation_list', fn () => $this->get(route('chat')), $staff);
        $measure('conversation_open', fn () => $this->get(route('chat', ['conversation' => $conversation->id])), $staff);
        $measure('conversation_partial', fn () => $this->get(route('chat', ['conversation' => $conversation->id]), ['X-Chat-View' => '1']), $staff);
        $measure('sidebar', fn () => $this->get(route('chat'), ['X-Chat-Sidebar' => '1']), $staff);
        $measure('history', fn () => $this->getJson(route('messages.history', $conversation).'?before='.$lastId), $staff);
        $measure('updates_empty', fn () => $this->getJson(route('conversations.updates', $conversation).'?after='.$lastId), $staff);
        $measure('send', fn () => $this->postJson(route('messages.send', $conversation), ['body' => 'Synthetic benchmark message']), $staff);
        $measure('login_response', fn () => $this->post('/login', ['email' => $staff->email, 'password' => $password]));
        $report = ['environment' => 'Isolated in-memory SQLite HTTP kernel; five samples; 10 conversations; bcrypt 12; fake queues; excludes network and PHP server bootstrap', 'primary_history_size' => $historySize, 'total_messages' => $historySize + 900, 'php' => PHP_VERSION, 'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2), 'metrics' => $metrics];
        $label = getenv('PERFORMANCE_LABEL') ?: 'current';
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $label);
        $directory = storage_path('app/private/performance');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($directory.'/'.$label.'.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->getJson(route('conversations.updates', $conversation).'?after='.$lastId)->assertJsonCount(5, 'messages');
    }
}
