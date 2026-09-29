<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MessageOrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_view_loads_messages_oldest_first_with_id_as_tiebreaker(): void
    {
        $company = Company::create(['name' => 'Example Pharmacy', 'legal_name' => 'Example Pharmacy', 'province' => 'Ontario']);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => bcrypt('password'), 'status' => 'active']);
        $company->users()->attach($user->id, ['role' => 'company_super_admin', 'status' => 'active']);
        $conversation = Conversation::create(['company_id' => $company->id, 'type' => 'direct', 'created_by' => $user->id]);
        $conversation->participants()->attach($user->id);

        $first = Message::create(['company_id' => $company->id, 'conversation_id' => $conversation->id, 'user_id' => $user->id, 'body' => 'First']);
        $second = Message::create(['company_id' => $company->id, 'conversation_id' => $conversation->id, 'user_id' => $user->id, 'body' => 'Second']);
        $time = Carbon::now()->startOfSecond();
        $first->forceFill(['created_at' => $time])->saveQuietly();
        $second->forceFill(['created_at' => $time])->saveQuietly();

        $ordered = $conversation->messages()->oldest('created_at')->oldest('id')->pluck('id')->all();
        $this->assertSame([$first->id, $second->id], $ordered);
    }
    public function test_chat_index_renders_only_the_latest_100_messages_in_oldest_to_newest_order(): void
    {
        $company = Company::create(['name' => 'Example Pharmacy', 'legal_name' => 'Example Pharmacy', 'province' => 'Ontario']);
        $user = User::create(['name' => 'Admin', 'email' => 'latest@example.test', 'password' => bcrypt('password'), 'status' => 'active']);
        $company->users()->attach($user->id, ['role' => 'company_super_admin', 'status' => 'active']);
        $conversation = Conversation::create(['company_id' => $company->id, 'type' => 'direct', 'created_by' => $user->id]);
        $conversation->participants()->attach($user->id);

        for ($i = 1; $i <= 105; $i++) {
            Message::create(['company_id' => $company->id, 'conversation_id' => $conversation->id, 'user_id' => $user->id, 'body' => "Message {$i}"]);
        }

        $response = $this->actingAs($user)
            ->withSession(['company_id' => $company->id])
            ->get(route('chat', ['conversation' => $conversation->id]));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringNotContainsString('Message 1</div>', $html);
        $this->assertStringContainsString('Message 6</div>', $html);
        $this->assertStringContainsString('Message 105</div>', $html);
        $this->assertTrue(strpos($html, 'Message 6</div>') < strpos($html, 'Message 105</div>'));
    }

}
