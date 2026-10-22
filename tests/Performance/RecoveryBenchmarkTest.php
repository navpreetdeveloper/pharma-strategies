<?php

namespace Tests\Performance;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecoveryBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_finds_pending_rows_after_a_large_delivered_history(): void
    {
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $company = Company::create(['name' => 'Synthetic recovery', 'province' => 'Ontario']);
        $user = User::factory()->create();
        $conversation = Conversation::create(['company_id' => $company->id, 'type' => 'group']);
        for ($offset = 0; $offset < 10000; $offset += 100) {
            $messages = [];
            $deliveries = [];
            foreach (range($offset + 1, $offset + 100) as $id) {
                $messages[] = ['id' => $id, 'company_id' => $company->id, 'conversation_id' => $conversation->id, 'user_id' => $user->id, 'body' => 'Synthetic fixture'];
                $deliveries[] = ['message_id' => $id, 'user_id' => $user->id, 'delivered_at' => $id < 10000 ? '2026-01-01 12:00:00' : null];
            }
            DB::table('messages')->insert($messages);
            DB::table('message_deliveries')->insert($deliveries);
        }
        $samples = [];
        foreach (range(1, 10) as $iteration) {
            $start = hrtime(true);
            $pending = DB::table('message_deliveries')->whereNull('delivered_at')->orderBy('id')->limit(100)->get();
            $samples[] = round((hrtime(true) - $start) / 1e6, 3);
            $this->assertCount(1, $pending);
            $this->assertSame(10000, (int) $pending[0]->message_id);
        }
        sort($samples);
        $label = getenv('PERFORMANCE_LABEL') ?: 'current';
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $label);
        $directory = storage_path('app/private/performance');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($directory.'/recovery-'.$label.'.json', json_encode(['environment' => 'Isolated SQLite; 9,999 delivered and one pending row; ten samples', 'median_ms' => $samples[4], 'max_ms' => max($samples), 'plan' => DB::select('EXPLAIN QUERY PLAN select * from message_deliveries where delivered_at is null order by id limit 100')], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
