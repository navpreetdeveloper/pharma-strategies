<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PrivacySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatAccessReviewTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        Storage::fake('local');
        $company = Company::create(['name' => 'Review Pharmacy', 'province' => 'Ontario']);
        PrivacySetting::create(['company_id' => $company->id, 'allow_admin_message_access' => true]);
        $first = User::factory()->create(['name' => 'staff']);
        $second = User::factory()->create(['name' => 'pharmacist']);
        $company->users()->attach($first, ['role' => 'staff', 'status' => 'active', 'name' => 'staff']);
        $company->users()->attach($second, ['role' => 'pharmacist', 'status' => 'active', 'name' => 'pharmacist']);
        $conversation = Conversation::create(['company_id' => $company->id, 'type' => 'direct', 'created_by' => $first->id]);
        $conversation->participants()->sync([$first->id, $second->id]);
        $message = Message::create(['company_id' => $company->id, 'conversation_id' => $conversation->id, 'user_id' => $first->id, 'body' => 'Private team update']);
        $path = 'companies/'.$company->id.'/review.txt';
        Storage::disk('local')->put($path, 'Private document');
        $attachment = Attachment::create(['company_id' => $company->id, 'message_id' => $message->id, 'path' => $path, 'original_name' => 'review.txt', 'mime_type' => 'text/plain', 'size' => 16]);

        return [$company, $first, $second, $conversation, $message, $attachment];
    }

    public function test_platform_conversation_names_do_not_repeat_roles_and_review_is_read_only(): void
    {
        [$company, $first, $second, $conversation] = $this->context();
        $admin = User::factory()->create(['platform_role' => 'super_admin']);
        $response = $this->actingAs($admin)->withSession(['company_id' => $company->id])->get(route('chat', ['conversation' => $conversation->id]));
        $response->assertOk()->assertSee('staff &amp; pharmacist', false)->assertSee('Direct conversation · Read-only')
            ->assertDontSee('Staff · staff')->assertDontSee('id="composer"', false)->assertDontSee('aria-label="Employee to message"', false);
    }

    public function test_chat_landing_keeps_list_visible_until_a_conversation_is_selected(): void
    {
        [$company, $member] = $this->context();
        $response = $this->actingAs($member)->withSession(['company_id' => $company->id])->get(route('chat'));
        $response->assertOk()->assertSee('Choose a conversation to get started.')->assertDontSee('id="composer"', false);
        $this->assertMatchesRegularExpression('/id="conversation-panel" class="[^"]*nc-chat-sidebar\\s*"/', $response->getContent());
    }

    public function test_opening_a_draft_does_not_list_it_until_the_first_message_is_sent(): void
    {
        [$company, $member] = $this->context();
        $colleague = User::factory()->create(['name' => 'New colleague']);
        $company->users()->attach($colleague, ['role' => 'staff', 'status' => 'active']);
        $this->actingAs($member)->withSession(['company_id' => $company->id]);
        $this->post(route('conversations.create'), ['type' => 'direct', 'user_id' => $colleague->id])->assertRedirect();
        $draft = Conversation::latest('id')->firstOrFail();
        $this->get(route('chat'))->assertOk()->assertDontSee('data-conversation-id="'.$draft->id.'"', false);
        $opened = $this->get(route('chat', ['conversation' => $draft->id]))->assertOk()->assertSee('id="composer"', false);
        $opened->assertDontSee('data-conversation-id="'.$draft->id.'"', false);
        // Reopening a draft reuses the same direct conversation.
        $this->post(route('conversations.create'), ['type' => 'direct', 'user_id' => $colleague->id])
            ->assertRedirect(route('chat', ['conversation' => $draft->id]));
        $this->assertSame(2, Conversation::count());
        $this->postJson(route('messages.send', $draft), ['body' => 'First message'])->assertOk();
        $this->get(route('chat'))->assertOk()->assertSee('data-conversation-id="'.$draft->id.'"', false)->assertSee('First message');
    }

    public function test_list_order_and_preview_follow_messages_rather_than_conversation_creation(): void
    {
        [$company, $member, $second, $existing, $message] = $this->context();
        $message->forceFill(['created_at' => now()->subMinutes(10)])->save();
        $newer = Conversation::create(['company_id' => $company->id, 'type' => 'group', 'name' => 'Newer conversation', 'created_by' => $member->id]);
        $newer->participants()->sync([$member->id, $second->id]);
        $previous = Message::create(['company_id' => $company->id, 'conversation_id' => $newer->id, 'user_id' => $member->id, 'body' => 'Previous update']);
        $previous->forceFill(['created_at' => now()->subMinutes(5)])->save();
        Message::create(['company_id' => $company->id, 'conversation_id' => $existing->id, 'user_id' => $member->id, 'body' => 'Most recent update']);
        $this->actingAs($member)->withSession(['company_id' => $company->id])->get(route('chat'))->assertOk()
            ->assertSeeInOrder(['data-conversation-id="'.$existing->id.'"', 'Most recent update', 'data-conversation-id="'.$newer->id.'"'], false);
    }

    public function test_platform_sidebar_does_not_expose_message_bodies_outside_audited_review(): void
    {
        [$company] = $this->context();
        $admin = User::factory()->create(['platform_role' => 'super_admin']);
        $this->actingAs($admin)->withSession(['company_id' => $company->id])->get(route('chat'))
            ->assertOk()->assertSee('Open to review messages')->assertDontSee('Private team update');
    }

    public function test_chat_runtime_configuration_is_valid_json_and_cannot_inject_scripts(): void
    {
        [$company, $member, $second, $conversation] = $this->context();
        $name = '</script><script>alert("unsafe")</script>';
        $member->forceFill(['name' => $name])->save();
        $response = $this->actingAs($member)->withSession(['company_id' => $company->id])
            ->get(route('chat', ['conversation' => $conversation->id]))->assertOk();
        $response->assertDontSee($name, false)->assertSee('data-chat-config', false);
        preg_match('/<script type="application\/json" data-chat-config>(.*?)<\/script>/s', $response->getContent(), $match);
        $config = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($name, $config['currentUserName']);
        $this->assertSame($conversation->id, $config['conversationId']);
        $this->assertTrue($config['canWrite']);
    }

    public function test_foreign_company_cannot_fetch_messages_history_or_private_files(): void
    {
        [$company, $member, $second, $conversation, $message, $attachment] = $this->context();
        $foreign = Company::create(['name' => 'Other Pharmacy', 'province' => 'Ontario']);
        $outsider = User::factory()->create();
        $foreign->users()->attach($outsider, ['role' => 'staff', 'status' => 'active']);
        $this->actingAs($outsider)->withSession(['company_id' => $foreign->id]);
        $this->getJson(route('messages.show', $message))->assertForbidden();
        $this->getJson(route('messages.history', ['conversation' => $conversation->id, 'before' => $message->id]))->assertForbidden();
        $this->get(route('attachments.download', $attachment))->assertNotFound();
        $this->get(route('attachments.preview', $attachment))->assertNotFound();
        $this->postJson(route('messages.send', $conversation), ['body' => 'Forbidden'])->assertForbidden();
    }

    public function test_removed_members_lose_message_and_file_access_on_the_next_request(): void
    {
        [$company, $member, $second, $conversation, $message, $attachment] = $this->context();
        $this->actingAs($member)->withSession(['company_id' => $company->id]);
        $this->getJson(route('messages.show', $message))->assertOk();
        $company->users()->detach($member);
        $this->getJson(route('messages.show', $message))->assertForbidden();
        $this->get(route('attachments.download', $attachment))->assertForbidden();
    }

    public function test_private_chat_responses_and_downloads_are_not_cacheable(): void
    {
        [$company, $member, $second, $conversation, $message, $attachment] = $this->context();
        $this->actingAs($member)->withSession(['company_id' => $company->id]);
        foreach ([route('chat', ['conversation' => $conversation->id]), route('messages.show', $message), route('attachments.download', $attachment)] as $url) {
            $response = $this->get($url)->assertOk();
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
        }
    }

    public function test_privacy_setting_can_revoke_platform_review_access(): void
    {
        [$company, $member, $second, $conversation, $message, $attachment] = $this->context();
        PrivacySetting::updateOrCreate(['company_id' => $company->id], ['allow_admin_message_access' => false]);
        $admin = User::factory()->create(['platform_role' => 'super_admin']);
        $this->actingAs($admin)->withSession(['company_id' => $company->id]);
        $this->getJson(route('messages.show', $message))->assertForbidden();
        $this->get(route('attachments.download', $attachment))->assertForbidden();
    }
}
