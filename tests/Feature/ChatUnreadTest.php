<?php

namespace Tests\Feature;

use App\Events\WorkplaceNotificationSent;
use App\Jobs\DeliverWorkplaceMessage;
use App\Jobs\SendWorkplacePushNotification;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\WorkplaceNotification;
use App\Services\MessageDelivery;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ChatUnreadTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $company = Company::create(['name' => 'Pharmacy', 'province' => 'Ontario']);
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $company->users()->attach([$sender->id => ['role' => 'staff', 'status' => 'active'], $recipient->id => ['role' => 'staff', 'status' => 'active']]);
        $conversation = Conversation::create(['company_id' => $company->id, 'type' => 'direct', 'created_by' => $sender->id]);
        $conversation->participants()->attach([$sender->id, $recipient->id]);
        $this->actingAs($recipient)->withSession(['company_id' => $company->id]);

        return [$company, $sender, $recipient, $conversation];
    }

    private function message(Company $company, User $sender, Conversation $conversation, array $extra = []): Message
    {
        return Message::create($extra + ['company_id' => $company->id, 'conversation_id' => $conversation->id, 'user_id' => $sender->id, 'body' => 'Hello']);
    }

    public function test_unread_counts_exclude_own_and_unsent_messages_and_clear_on_open(): void
    {
        [$company, $sender, $recipient, $conversation] = $this->context();
        $this->message($company, $sender, $conversation);
        $last = $this->message($company, $sender, $conversation);
        $this->message($company, $recipient, $conversation);
        $this->message($company, $sender, $conversation, ['deleted_at' => now()]);
        $response = $this->get(route('chat'))->assertOk()->assertSee('2 unread messages');
        $this->assertSame(2, $response->viewData('conversations')->first()->unread_count);
        $response = $this->get(route('chat', ['conversation' => $conversation->id]))->assertOk();
        $this->assertSame(0, $response->viewData('conversations')->first()->unread_count);
        $this->message($company, $sender, $conversation);
        $this->assertSame(1, $this->get(route('chat'))->viewData('conversations')->first()->unread_count);
        $this->postJson(route('conversations.read', $conversation), ['last_message_id' => $last->id])->assertOk();
        $this->assertSame(1, $this->get(route('chat'))->viewData('conversations')->first()->unread_count);
    }

    public function test_first_incoming_message_appears_in_lightweight_conversation_list(): void
    {
        [$company, $sender, $recipient, $conversation] = $this->context();
        $this->get(route('chat'))->assertDontSee('data-conversation-id="'.$conversation->id.'"', false);
        $this->message($company, $sender, $conversation);
        $this->withHeader('X-Chat-Sidebar', '1')->get(route('chat'))->assertOk()
            ->assertSee('data-conversation-id="'.$conversation->id.'"', false)->assertSee('1 unread messages')->assertDontSee('<html', false);
    }

    public function test_live_catchup_returns_new_messages_and_rejects_other_conversations(): void
    {
        [$company, $sender, $recipient, $conversation] = $this->context();
        $first = $this->message($company, $sender, $conversation);
        $second = $this->message($company, $sender, $conversation);
        $this->getJson(route('conversations.updates', $conversation).'?after='.$first->id)->assertOk()->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.id', $second->id);
        $other = Conversation::create(['company_id' => $company->id, 'type' => 'direct', 'created_by' => $sender->id]);
        $other->participants()->attach($sender);
        $foreignMessage = $this->message($company, $sender, $other);
        $this->getJson(route('conversations.updates', $other).'?after=0')->assertForbidden();
        $this->postJson(route('conversations.read', $conversation), ['last_message_id' => $foreignMessage->id])->assertNotFound();
        $this->postJson(route('conversations.read', $other), ['last_message_id' => $foreignMessage->id])->assertForbidden();
    }

    public function test_routine_delivery_broadcasts_and_queues_push_without_notification_centre_records(): void
    {
        [$company, $sender, $recipient, $conversation] = $this->context();
        Event::fake();
        Queue::fake();
        $message = $this->message($company, $sender, $conversation);
        MessageDelivery::record($message);
        $push = \Mockery::mock(WebPushService::class);
        $push->shouldNotReceive('sendToUser');
        (new DeliverWorkplaceMessage($message->id, $recipient->id))->handle($push);
        Event::assertDispatched(WorkplaceNotificationSent::class, fn ($event) => $event->userId === $recipient->id && $event->data['important'] === false);
        Queue::assertPushed(SendWorkplacePushNotification::class, fn ($job) => $job->queue === 'push' && $job->userId === $recipient->id && $job->data['important'] === false);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertNotNull(DB::table('message_deliveries')->value('delivered_at'));
    }

    public function test_notification_centre_hides_legacy_chat_alerts_and_retains_announcements(): void
    {
        [$company, $sender, $recipient, $conversation] = $this->context();
        $recipient->notifyNow(new WorkplaceNotification('Routine', 'Routine', ['message_id' => 1]));
        $recipient->notifyNow(new WorkplaceNotification('Announcement', 'Important', ['message_id' => 2, 'important' => true]));
        $this->getJson(route('notifications.index'))->assertOk()->assertJsonCount(1, 'notifications')->assertJsonPath('unread_count', 1)->assertJsonPath('notifications.0.title', 'Announcement');
    }

    public function test_push_unread_counts_include_retained_messages_from_deleted_authors(): void
    {
        [$company, $sender, $recipient, $conversation] = $this->context();
        $old = $this->message($company, $sender, $conversation);
        $old->forceFill(['user_id' => null])->save();
        $latest = $this->message($company, $sender, $conversation);
        $push = \Mockery::mock(WebPushService::class);
        $push->shouldReceive('sendToUser')->once()->withArgs(fn ($user, $title, $body, $data) => $user->id === $recipient->id && $data['unread_count'] === 2);
        (new SendWorkplacePushNotification($recipient->id, 'Pharma Strategies', 'New message', ['message_id' => $latest->id, 'conversation_id' => $conversation->id]))->handle($push);
    }

    public function test_push_groups_the_latest_unread_message_and_skips_read_or_removed_access(): void
    {
        [$company, $sender, $recipient, $conversation] = $this->context();
        $first = $this->message($company, $sender, $conversation);
        $latest = $this->message($company, $sender, $conversation);
        $job = fn ($message) => new SendWorkplacePushNotification($recipient->id, 'Pharma Strategies', 'New message', ['message_id' => $message->id, 'conversation_id' => $conversation->id, 'important' => false]);
        $push = \Mockery::mock(WebPushService::class);
        $push->shouldReceive('sendToUser')->once()->withArgs(fn ($user, $title, $body, $data) => $user->id === $recipient->id && $data['message_id'] === $latest->id && $data['unread_count'] === 2);
        $job($first)->handle($push);
        $job($latest)->handle($push);
        $this->postJson(route('conversations.read', $conversation), ['last_message_id' => $latest->id])->assertOk();
        $job($latest)->handle($push);
        $new = $this->message($company, $sender, $conversation);
        $company->users()->detach($recipient);
        $job($new)->handle($push);
    }
}
