<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RepliesForwardingPresenceTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Test Pharmacy Group', 'province' => 'ON']);
    }

    private function user(string $name, Company $company): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->companies()->attach($company->id, ['role' => 'pharmacy_staff', 'status' => 'active']);
        return $user;
    }

    private function conversation(Company $company, User ...$users): Conversation
    {
        $conversation = Conversation::create(['company_id' => $company->id, 'type' => 'direct', 'created_by' => $users[0]->id]);
        $conversation->participants()->sync(collect($users)->pluck('id')->all());
        return $conversation;
    }

    public function test_user_can_reply_to_a_message(): void
    {
        Event::fake();
        $company = $this->company();
        $sender = $this->user('Sender', $company);
        $recipient = $this->user('Recipient', $company);
        $conversation = $this->conversation($company, $sender, $recipient);
        $this->actingAs($sender)->withSession(['company_id' => $company->id]);

        $original = Message::create(['company_id' => $company->id, 'conversation_id' => $conversation->id, 'user_id' => $recipient->id, 'body' => 'Please confirm the delivery.', 'message_type' => 'text']);
        $response = $this->postJson(route('messages.send', $conversation), ['body' => 'Confirmed.', 'reply_to_message_id' => $original->id]);

        $response->assertOk();
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'reply_to_message_id' => $original->id, 'body' => 'Confirmed.']);
    }

    public function test_user_can_forward_a_message_to_another_authorized_conversation(): void
    {
        Event::fake();
        Storage::fake('local');
        $company = $this->company();
        $sender = $this->user('Sender', $company);
        $recipient = $this->user('Recipient', $company);
        $other = $this->user('Other', $company);
        $source = $this->conversation($company, $sender, $recipient);
        $target = $this->conversation($company, $sender, $other);
        $this->actingAs($sender)->withSession(['company_id' => $company->id]);
        $original = Message::create(['company_id' => $company->id, 'conversation_id' => $source->id, 'user_id' => $recipient->id, 'body' => 'Important workplace update', 'message_type' => 'text']);

        $response = $this->postJson(route('messages.forward', $original), ['conversation_ids' => [$target->id]]);
        $response->assertOk();
        $this->assertDatabaseHas('messages', ['conversation_id' => $target->id, 'forwarded_from_message_id' => $original->id, 'body' => $original->body]);
    }

    public function test_presence_heartbeat_updates_last_seen(): void
    {
        $company = $this->company();
        $user = $this->user('Online User', $company);
        $this->actingAs($user)->withSession(['company_id' => $company->id]);
        $this->postJson(route('presence.heartbeat'))->assertOk();
        $this->assertNotNull($user->fresh()->last_seen_at);
    }
}
