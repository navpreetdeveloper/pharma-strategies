<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PrivacySetting;
use App\Models\User;
use App\Services\Chat\ConversationDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompanyPrivacyHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        Storage::fake('local');
        $company = Company::create(['name' => 'Private Pharmacy', 'province' => 'Ontario']);
        $member = User::factory()->create();
        $admin = User::factory()->create();
        $company->users()->attach($member, ['role' => 'staff', 'status' => 'active']);
        $company->users()->attach($admin, ['role' => 'company_super_admin', 'status' => 'active']);
        $conversation = Conversation::create(['company_id' => $company->id, 'type' => 'group']);
        $conversation->participants()->attach($member);
        $message = Message::create(['company_id' => $company->id, 'conversation_id' => $conversation->id, 'user_id' => $member->id, 'body' => 'Confidential fixture']);
        $path = 'companies/'.$company->id.'/document.png';
        Storage::disk('local')->put($path, 'Synthetic private file');
        $attachment = Attachment::create(['company_id' => $company->id, 'message_id' => $message->id, 'path' => $path, 'original_name' => 'document.png', 'mime_type' => 'image/png', 'size' => 22]);

        return [$company, $member, $admin, $conversation, $message, $attachment];
    }

    public function test_missing_policy_denies_company_and_platform_review_but_keeps_participant_access(): void
    {
        [$company, $member, $admin, $conversation, $message, $attachment] = $this->context();
        $platform = User::factory()->create(['platform_role' => 'super_admin']);
        foreach ([$admin, $platform] as $reviewer) {
            $this->actingAs($reviewer)->withSession(['company_id' => $company->id]);
            $this->getJson(route('messages.show', $message))->assertForbidden();
            $this->getJson(route('conversations.updates', ['conversation' => $conversation->id, 'after' => 0]))->assertForbidden();
            $this->get(route('attachments.download', $attachment))->assertForbidden();
            $this->get(route('chat'))->assertOk()->assertDontSee('Confidential fixture')->assertDontSee('data-conversation-id="'.$conversation->id.'"', false);
        }
        $this->actingAs($member)->withSession(['company_id' => $company->id])->getJson(route('messages.show', $message))->assertOk();
        $this->get(route('attachments.download', $attachment))->assertOk();
    }

    public function test_explicit_review_permission_is_revoked_on_the_next_request(): void
    {
        [$company, , $admin, $conversation, $message] = $this->context();
        $policy = PrivacySetting::create(['company_id' => $company->id, 'allow_admin_message_access' => true]);
        $this->actingAs($admin)->withSession(['company_id' => $company->id])->getJson(route('messages.show', $message))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'user_id' => $admin->id, 'action' => 'privileged_conversation_read']);
        $policy->update(['allow_admin_message_access' => false]);
        $this->getJson(route('messages.show', $message))->assertForbidden();
        $this->assertFalse(app(ConversationDirectory::class)->query($admin, $company->id)->whereKey($conversation->id)->exists());
    }

    public function test_model_and_database_defaults_are_participants_only(): void
    {
        [$company] = $this->context();
        $policy = PrivacySetting::create(['company_id' => $company->id]);
        $this->assertFalse($policy->fresh()->allow_admin_message_access);
        $policy->delete();
        DB::table('privacy_settings')->insert(['company_id' => $company->id]);
        $this->assertFalse(PrivacySetting::administratorReviewAllowed($company->id));
    }

    public function test_platform_admin_cannot_enable_its_own_review_access(): void
    {
        [$company, , $owner] = $this->context();
        $policy = PrivacySetting::create(['company_id' => $company->id]);
        $platform = User::factory()->create(['platform_role' => 'super_admin']);
        $this->actingAs($platform)->withSession(['company_id' => $company->id]);
        $this->get(route('admin.privacy'))->assertOk()->assertDontSee('name="allow_admin_message_access"', false);
        $this->patchJson(route('admin.privacy.update'), ['allow_admin_message_access' => true])->assertForbidden();
        $this->assertFalse($policy->fresh()->allow_admin_message_access);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'privacy_policy_updated']);
        $this->actingAs($owner)->patch(route('admin.privacy.update'), ['allow_admin_message_access' => true])->assertRedirect();
        $this->assertTrue($policy->fresh()->allow_admin_message_access);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'user_id' => $owner->id, 'action' => 'privacy_policy_updated']);
    }

    public function test_foreign_company_cannot_read_recovery_mutations_presence_or_policy_details(): void
    {
        [$company, $member, , $conversation, $message] = $this->context();
        PrivacySetting::create(['company_id' => $company->id, 'privacy_contact_email' => 'private-contact@example.test']);
        $other = Company::create(['name' => 'Other Pharmacy', 'province' => 'Ontario']);
        $outsider = User::factory()->create();
        $other->users()->attach($outsider, ['role' => 'company_super_admin', 'status' => 'active']);
        $this->actingAs($outsider)->withSession(['company_id' => $other->id]);
        $this->getJson(route('conversations.updates', ['conversation' => $conversation->id, 'after' => 0, 'visible' => [$message->id]]))->assertForbidden();
        $this->postJson(route('conversations.read', $conversation), ['last_message_id' => $message->id])->assertForbidden();
        $this->patchJson(route('messages.edit', $message), ['body' => 'Tampered'])->assertForbidden();
        $this->deleteJson(route('messages.destroy', $message))->assertForbidden();
        $this->postJson(route('messages.forward', $message), ['conversation_ids' => [$conversation->id]])->assertForbidden();
        $this->getJson(route('presence.index', ['ids' => [$member->id]]))->assertOk()->assertExactJson(['users' => []]);
        $this->get(route('workspace.privacy', ['company_id' => $company->id]))->assertOk()->assertSee('Other Pharmacy privacy policy')->assertDontSee('private-contact@example.test');
        $this->withSession(['company_id' => $company->id])->get(route('workspace.privacy'))->assertForbidden();
    }

    public function test_attachment_paths_cannot_escape_their_company_or_private_storage(): void
    {
        [$company, $member, , , , $attachment] = $this->context();
        $other = Company::create(['name' => 'Foreign file owner', 'province' => 'Ontario']);
        $foreignPath = 'companies/'.$other->id.'/secret.png';
        Storage::disk('local')->put($foreignPath, 'Foreign private file');
        $this->actingAs($member)->withSession(['company_id' => $company->id]);
        foreach ([$foreignPath, 'companies/'.$company->id.'/../'.$other->id.'/secret.png', 'companies/'.$company->id.'/missing.png'] as $path) {
            $attachment->update(['path' => $path]);
            $this->get(route('attachments.download', $attachment))->assertNotFound();
            $this->get(route('attachments.preview', $attachment))->assertNotFound();
        }
    }

    public function test_privacy_notice_is_public_but_company_settings_require_current_membership(): void
    {
        [$company, $member] = $this->context();
        PrivacySetting::create(['company_id' => $company->id, 'privacy_contact_email' => 'private-contact@example.test']);
        $this->get(route('privacy'))->assertOk()->assertSee('Privacy notice')->assertDontSee('private-contact@example.test');
        $this->get(route('workspace.privacy'))->assertRedirect(route('login'));
        $this->actingAs($member)->withSession(['company_id' => $company->id])->get(route('workspace.privacy'))->assertOk()->assertSee('private-contact@example.test')->assertSee('Participants only')->assertHeader('X-Content-Type-Options', 'nosniff');
        $company->users()->detach($member);
        $this->get(route('workspace.privacy'))->assertForbidden();
    }

    public function test_participant_authorization_does_not_query_review_policy(): void
    {
        [$company, $member, , $conversation] = $this->context();
        $this->withSession(['company_id' => $company->id]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertTrue($member->canAccessConversation($conversation));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(0, array_filter($queries, fn ($query) => str_contains($query['query'], 'privacy_settings')));
    }
}
