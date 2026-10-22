<?php

namespace Tests\Feature;

use App\Jobs\DeliverWorkplaceMessage;
use App\Jobs\SendWorkplacePushNotification;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\MessageDelivery;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class NotificationDeliveryResilienceTest extends TestCase
{
    use RefreshDatabase;

    private function delivery(): array
    {
        $company = Company::create(['name' => 'Synthetic pharmacy', 'province' => 'Ontario']);
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $company->users()->attach([$sender->id => ['role' => 'staff', 'status' => 'active'], $recipient->id => ['role' => 'staff', 'status' => 'active']]);
        $conversation = Conversation::create(['company_id' => $company->id, 'type' => 'direct']);
        $conversation->participants()->attach([$sender->id, $recipient->id]);
        $message = Message::create(['company_id' => $company->id, 'conversation_id' => $conversation->id, 'user_id' => $sender->id, 'body' => 'Synthetic message']);
        MessageDelivery::record($message);

        return [$message, $recipient];
    }

    public function test_broker_outage_does_not_block_or_duplicate_push_dispatch(): void
    {
        [$message, $recipient] = $this->delivery();
        Queue::fake();
        $job = new class($message->id, $recipient->id) extends DeliverWorkplaceMessage
        {
            protected function publish(int $userId, array $data): void
            {
                throw new RuntimeException('Synthetic broker outage');
            }
        };
        foreach (range(1, 2) as $attempt) {
            try {
                $job->handle(app(WebPushService::class));
                $this->fail('Broker failure must retain a retry');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('Realtime message delivery is unavailable', $error->getMessage());
            }
        }
        Queue::assertPushed(SendWorkplacePushNotification::class, 1);
        $this->assertNotNull(DB::table('message_deliveries')->value('push_queued_at'));
        $this->assertNull(DB::table('message_deliveries')->value('delivered_at'));
    }

    public function test_push_queue_outage_preserves_live_delivery_and_does_not_rebroadcast_on_retry(): void
    {
        [$message, $recipient] = $this->delivery();
        Bus::shouldReceive('dispatch')->with(Mockery::type(SendWorkplacePushNotification::class))
            ->andThrow(new RuntimeException('Synthetic push queue outage'));
        $job = new class($message->id, $recipient->id) extends DeliverWorkplaceMessage
        {
            public int $published = 0;

            protected function publish(int $userId, array $data): void
            {
                $this->published++;
            }
        };
        foreach (range(1, 2) as $attempt) {
            try {
                $job->handle(app(WebPushService::class));
                $this->fail('Push queue failure must retain a retry');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('Push dispatch is unavailable', $error->getMessage());
            }
        }
        $this->assertSame(1, $job->published);
        $this->assertNotNull(DB::table('message_deliveries')->value('delivered_at'));
        $this->assertNull(DB::table('message_deliveries')->value('push_queued_at'));
    }

    public function test_recovery_includes_push_only_pending_rows(): void
    {
        $this->delivery();
        DB::table('message_deliveries')->update(['delivered_at' => now()]);
        Queue::fake();
        $this->artisan('chat:retry-deliveries')->assertSuccessful();
        Queue::assertPushed(DeliverWorkplaceMessage::class, 1);
    }

    public function test_migration_does_not_replay_previously_dispatched_notifications(): void
    {
        $this->delivery();
        $migration = require database_path('migrations/2026_10_15_000001_track_push_dispatch_independently.php');
        $migration->down();
        DB::table('message_deliveries')->update(['delivered_at' => now()]);
        $migration->up();
        $this->assertNotNull(DB::table('message_deliveries')->value('push_queued_at'));
        Queue::fake();
        $this->artisan('chat:retry-deliveries')->assertSuccessful();
        Queue::assertNothingPushed();
    }
}
