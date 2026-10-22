<?php

namespace Tests\Feature;

use App\Events\MessageEdited;
use App\Events\MessageSent;
use App\Jobs\DeliverWorkplaceMessage;
use App\Jobs\SendWorkplacePushNotification;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PrivacySetting;
use App\Models\User;
use App\Notifications\QueuedPasswordReset as ResetPassword;
use App\Services\MessageDelivery;
use App\Services\PrivateFileCleanup;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class LaravelSecurityParityTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $company = Company::create(['name' => 'Canada Pharmacy', 'province' => 'Newfoundland and Labrador']);
        $owner = User::factory()->create(['name' => 'Owner']);
        $member = User::factory()->create(['name' => 'Member']);
        $company->users()->attach($owner, ['role' => 'company_super_admin', 'status' => 'active', 'name' => 'Workspace Owner']);
        $company->users()->attach($member, ['role' => 'staff', 'status' => 'active', 'name' => 'Workspace Member']);
        $conversation = Conversation::create(['company_id' => $company->id, 'type' => 'direct', 'created_by' => $owner->id]);
        $conversation->participants()->sync([$owner->id, $member->id]);

        return [$company, $owner, $member, $conversation];
    }

    public function test_unverified_and_temporary_password_accounts_cannot_read_messages(): void
    {
        config(['security.require_email_verification' => true]);
        [$c,$owner,$member,$conversation] = $this->context();
        $member->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($member)->withSession(['company_id' => $c->id])->getJson(route('messages.history', $conversation).'?before=1')->assertForbidden();
        $member->forceFill(['email_verified_at' => now(), 'must_change_password' => true])->save();
        $this->getJson(route('workspace.access'))->assertForbidden();
        $this->get(route('profile.show'))->assertOk();
    }

    public function test_nonparticipant_admin_can_read_with_policy_but_cannot_send_and_policy_can_revoke_read(): void
    {
        [$c,$owner,$member,$conversation] = $this->context();
        $conversation->participants()->detach($owner);
        PrivacySetting::create(['company_id' => $c->id, 'allow_admin_message_access' => true]);
        $message = Message::create(['company_id' => $c->id, 'conversation_id' => $conversation->id, 'user_id' => $member->id, 'body' => 'Private']);
        $this->actingAs($owner)->withSession(['company_id' => $c->id])->getJson(route('messages.show', $message))->assertOk();
        $this->postJson(route('messages.send', $conversation), ['body' => 'Forbidden'])->assertForbidden();
        $this->postJson(route('messages.forward', $message), ['conversation_ids' => [$conversation->id]])->assertForbidden();
        PrivacySetting::where('company_id', $c->id)->update(['allow_admin_message_access' => false]);
        $this->getJson(route('messages.show', $message))->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'privileged_conversation_read']);
    }

    public function test_manager_cannot_modify_an_existing_administrator_and_owner_cannot_remove_self(): void
    {
        [$c,$owner,$member] = $this->context();
        $c->users()->updateExistingPivot($member->id, ['role' => 'pharmacy_manager']);
        $this->actingAs($member)->withSession(['company_id' => $c->id])->patchJson(route('admin.employees.update', $owner), ['name' => 'Changed', 'role' => 'staff', 'status' => 'suspended'])->assertForbidden();
        $this->actingAs($owner)->deleteJson(route('admin.employees.delete', $owner))->assertStatus(422);
        $this->assertDatabaseHas('company_user', ['company_id' => $c->id, 'user_id' => $owner->id, 'role' => 'company_super_admin', 'status' => 'active']);
    }

    public function test_employee_edits_are_scoped_and_removal_preserves_history_and_other_company_access(): void
    {
        [$c,$owner,$member,$conversation] = $this->context();
        $other = Company::create(['name' => 'Second employer', 'province' => 'Ontario']);
        $other->users()->attach($member, ['role' => 'pharmacist', 'status' => 'active', 'name' => 'Second name']);
        $message = Message::create(['company_id' => $c->id, 'conversation_id' => $conversation->id, 'user_id' => $member->id, 'body' => 'Preserve history']);
        $this->actingAs($owner)->withSession(['company_id' => $c->id])->patch(route('admin.employees.update', $member), ['name' => 'First name', 'role' => 'staff', 'status' => 'suspended'])->assertRedirect();
        $this->assertSame('Member', $member->fresh()->name);
        $this->assertSame('active', $member->fresh()->status);
        $this->assertDatabaseHas('company_user', ['company_id' => $other->id, 'user_id' => $member->id, 'name' => 'Second name', 'status' => 'active']);
        $this->delete(route('admin.employees.delete', $member))->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $member->id]);
        $this->assertDatabaseHas('messages', ['id' => $message->id]);
        $this->assertDatabaseMissing('company_user', ['company_id' => $c->id, 'user_id' => $member->id]);
    }

    public function test_removed_employee_without_another_workspace_cannot_sign_in(): void
    {
        [$company, $owner, $member] = $this->context();
        $member->update(['password' => Hash::make('SecurePassword123!')]);
        $this->actingAs($owner)->withSession(['company_id' => $company->id])
            ->delete(route('admin.employees.delete', $member))->assertRedirect();
        $this->post(route('logout'));
        $this->post(route('login.submit'), ['email' => $member->email, 'password' => 'SecurePassword123!'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseHas('users', ['id' => $member->id, 'status' => 'active']);
    }

    public function test_removed_employee_can_sign_in_to_another_active_workspace(): void
    {
        [$company, $owner, $member] = $this->context();
        $member->update(['password' => Hash::make('SecurePassword123!')]);
        $other = Company::create(['name' => 'Other pharmacy', 'province' => 'Ontario']);
        $other->users()->attach($member, ['role' => 'staff', 'status' => 'active']);
        DB::table('sessions')->insert(['id' => 'removed-employee-session', 'user_id' => $member->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($owner)->withSession(['company_id' => $company->id])
            ->delete(route('admin.employees.delete', $member))->assertRedirect();
        $this->assertDatabaseMissing('sessions', ['id' => 'removed-employee-session']);
        $this->post(route('logout'));
        $this->post(route('login.submit'), ['email' => $member->email, 'password' => 'SecurePassword123!'])
            ->assertRedirect(route('chat'))->assertSessionHas('company_id', $other->id);
        $this->assertAuthenticatedAs($member);
        $this->getJson(route('messages.history', 1).'?before=1')->assertForbidden();
    }

    public function test_recovery_code_is_consumed_and_password_login_does_not_bypass_mfa(): void
    {
        [$c,$owner,$member] = $this->context();
        $member->forceFill(['password' => Hash::make('SecurePassword123!'), 'two_factor_secret' => Fortify::currentEncrypter()->encrypt('test-secret'), 'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['one-time-recovery'])), 'two_factor_confirmed_at' => now()])->save();
        $this->post(route('login.submit'), ['email' => $member->email, 'password' => 'SecurePassword123!'])->assertRedirect(route('mfa.challenge'));
        $this->assertGuest();
        $this->post(route('mfa.challenge.confirm'), ['recovery_code' => 'one-time-recovery'])->assertRedirect();
        $this->assertAuthenticatedAs($member);
        $this->assertNotContains('one-time-recovery', $member->fresh()->recoveryCodes());
        $this->post(route('logout'));
        $this->post(route('login.submit'), ['email' => $member->email, 'password' => 'SecurePassword123!']);
        $this->postJson(route('mfa.challenge.confirm'), ['recovery_code' => 'one-time-recovery'])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_password_reset_invalidates_temporary_password_and_uses_the_broker_token(): void
    {
        Notification::fake();
        [$c,$owner,$member] = $this->context();
        $member->update(['must_change_password' => true]);
        $this->post(route('password.email'), ['email' => $member->email])->assertRedirect();
        Notification::assertSentTo($member, ResetPassword::class, function ($notification) use ($member) {
            $this->post(route('password.update'), ['email' => $member->email, 'token' => $notification->token, 'password' => 'ChangedPassword123!', 'password_confirmation' => 'ChangedPassword123!'])->assertRedirect(route('login'));

            return true;
        });
        $this->assertTrue(Hash::check('ChangedPassword123!', $member->fresh()->password));
        $this->assertFalse($member->fresh()->must_change_password);
    }

    public function test_latest_history_and_cursor_pages_are_bounded_and_complete(): void
    {
        [$c,$owner,$member,$conversation] = $this->context();
        for ($i = 0; $i < 125; $i++) {
            Message::create(['company_id' => $c->id, 'conversation_id' => $conversation->id, 'user_id' => $member->id, 'body' => "Message {$i}"]);
        }
        $response = $this->actingAs($member)->withSession(['company_id' => $c->id])->get(route('chat', ['conversation' => $conversation->id]))->assertOk();
        $this->assertCount(50, $response->viewData('selectedMessages'));
        $cursor = $response->viewData('selectedMessages')->first()->id;
        $page = $this->getJson(route('messages.history', $conversation).'?before='.$cursor)->assertOk()->assertJsonCount(50, 'messages')->assertJsonPath('has_more', true);
        $next = $page->json('messages.0.id');
        $this->getJson(route('messages.history', $conversation).'?before='.$next)->assertOk()->assertJsonCount(25, 'messages')->assertJsonPath('has_more', false);
    }

    public function test_delivery_outage_preserves_attachment_and_retry_creates_only_one_notification(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$c,$owner,$member,$conversation] = $this->context();
        Event::fake();
        $conversation->update(['type' => 'announcement']);
        $this->actingAs($owner)->withSession(['company_id' => $c->id])->postJson(route('messages.send', $conversation), ['body' => 'Saved', 'attachments' => [UploadedFile::fake()->create('policy.pdf', 2, 'application/pdf')]])->assertOk();
        $message = Message::firstOrFail();
        $attachment = $message->attachments()->firstOrFail();
        $job = new DeliverWorkplaceMessage($message->id, $member->id);
        $job->handle(app(WebPushService::class));
        Queue::assertPushed(SendWorkplacePushNotification::class);
        $pushJob = new SendWorkplacePushNotification($member->id, 'Pharma Strategies', 'New announcement', ['message_id' => $message->id, 'important' => true]);
        $push = \Mockery::mock(WebPushService::class);
        $push->shouldReceive('sendToUser')->once()->andThrow(new \RuntimeException('Transport down'));
        try {
            $pushJob->handle($push);
            $this->fail('Delivery should be retryable');
        } catch (\RuntimeException $error) {
            $this->assertSame('Transport down', $error->getMessage());
        }
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => 'Saved']);
        $this->assertNotNull(DB::table('message_deliveries')->value('delivered_at'));
        $push = \Mockery::mock(WebPushService::class);
        $push->shouldReceive('sendToUser')->once();
        $pushJob->handle($push);
        $job->handle($push);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertNotNull(DB::table('message_deliveries')->value('delivered_at'));
    }

    public function test_database_queue_keeps_push_delivery_out_of_the_message_request(): void
    {
        config(['queue.default' => 'database']);
        [$company, $sender, $recipient, $conversation] = $this->context();
        Event::fake();
        $push = \Mockery::mock(WebPushService::class);
        $push->shouldNotReceive('sendToUser');
        $this->app->instance(WebPushService::class, $push);

        $this->actingAs($sender)->withSession(['company_id' => $company->id])
            ->postJson(route('messages.send', $conversation), ['body' => 'Queued delivery'])
            ->assertOk();

        $this->assertDatabaseHas('messages', ['body' => 'Queued delivery']);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseHas('message_deliveries', ['user_id' => $recipient->id, 'delivered_at' => null]);
    }

    public function test_removed_recipient_receives_no_notification_or_push(): void
    {
        [$c,$owner,$member,$conversation] = $this->context();
        Event::fake();
        $message = Message::create(['company_id' => $c->id, 'conversation_id' => $conversation->id, 'user_id' => $owner->id, 'body' => 'Private']);
        MessageDelivery::record($message);
        $c->users()->detach($member);
        $push = \Mockery::mock(WebPushService::class);
        $push->shouldNotReceive('sendToUser');
        (new DeliverWorkplaceMessage($message->id, $member->id))->handle($push);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_push_endpoint_policy_rejects_internal_hosts_credentials_redirect_hosts_and_ports(): void
    {
        [$c,$owner,$member] = $this->context();
        $this->actingAs($member)->withSession(['company_id' => $c->id]);
        foreach (['http://127.0.0.1/a', 'https://169.254.169.254/a', 'https://fcm.googleapis.com.evil.test/a', 'https://user@fcm.googleapis.com/a', 'https://fcm.googleapis.com:8443/a'] as $endpoint) {
            $this->postJson(route('push.subscribe'), ['subscription' => ['endpoint' => $endpoint, 'keys' => ['p256dh' => str_repeat('a', 20), 'auth' => str_repeat('b', 20)]]])->assertUnprocessable();
        }
    }

    public function test_broadcast_payload_contains_only_identifiers(): void
    {
        [$c,$owner,$member,$conversation] = $this->context();
        $message = Message::create(['company_id' => $c->id, 'conversation_id' => $conversation->id, 'user_id' => $owner->id, 'body' => 'Sensitive message']);
        foreach ([new MessageSent($message), new MessageEdited($message)] as $event) {
            $data = $event->broadcastWith();
            $this->assertArrayNotHasKey('body', $data);
            $this->assertArrayNotHasKey('attachments', $data);
            $this->assertSame($message->id, $data['id']);
        }
    }

    public function test_privacy_policy_is_owner_only_and_retention_preview_preserves_data(): void
    {
        Storage::fake('local');
        [$c,$owner,$member,$conversation] = $this->context();
        $this->actingAs($member)->withSession(['company_id' => $c->id])->patchJson(route('admin.privacy.update'), ['retention_days' => 30, 'allow_admin_message_access' => false])->assertForbidden();
        $this->actingAs($owner)->patch(route('admin.privacy.update'), ['retention_days' => 30, 'allow_admin_message_access' => false])->assertRedirect();
        $message = Message::create(['company_id' => $c->id, 'conversation_id' => $conversation->id, 'user_id' => $member->id, 'body' => 'Expired']);
        $message->forceFill(['created_at' => now()->subDays(31)])->saveQuietly();
        $path = "companies/{$c->id}/conversations/{$conversation->id}/messages/{$message->id}/policy.pdf";
        Storage::disk('local')->put($path, 'private');
        $message->attachments()->create(['company_id' => $c->id, 'original_name' => 'policy.pdf', 'path' => $path, 'mime_type' => 'application/pdf', 'size' => 7]);
        $fresh = Message::create(['company_id' => $c->id, 'conversation_id' => $conversation->id, 'user_id' => $member->id, 'body' => 'Keep']);
        $this->artisan('chat:prune')->assertExitCode(0);
        $this->assertDatabaseHas('messages', ['id' => $message->id]);
        Storage::disk('local')->assertExists($path);
        $this->artisan('chat:prune', ['--apply' => true])->assertExitCode(0);
        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
        $this->assertDatabaseHas('messages', ['id' => $fresh->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_signed_email_verification_unlocks_the_account(): void
    {
        [$c,$owner,$member] = $this->context();
        $member->forceFill(['email_verified_at' => null])->save();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $member->id, 'hash' => sha1($member->email)]);
        $this->actingAs($member)->withSession(['company_id' => $c->id])->get($url)->assertRedirect(route('chat'));
        $this->assertTrue($member->fresh()->hasVerifiedEmail());
    }

    public function test_authenticator_enrollment_requires_password_and_valid_totp_confirmation(): void
    {
        [$c,$owner,$member] = $this->context();
        $this->actingAs($member)->withSession(['company_id' => $c->id])->post(route('mfa.enable'), ['current_password' => 'incorrect'])->assertSessionHasErrors('current_password');
        $this->post(route('mfa.enable'), ['current_password' => 'password'])->assertRedirect(route('mfa.settings'));
        $member->refresh();
        $this->assertFalse($member->hasEnabledTwoFactorAuthentication());
        $secret = Fortify::currentEncrypter()->decrypt($member->two_factor_secret);
        $code = (new Google2FA)->getCurrentOtp($secret);
        $wrong = str_pad((string) (((int) $code + 1) % 1000000), 6, '0', STR_PAD_LEFT);
        $this->post(route('mfa.confirm'), ['code' => $wrong])->assertSessionHasErrors();
        $this->assertFalse($member->fresh()->hasEnabledTwoFactorAuthentication());
        $this->post(route('mfa.confirm'), ['code' => $code])->assertRedirect(route('mfa.settings'));
        $this->assertTrue($member->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertCount(8, $member->fresh()->recoveryCodes());
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post(route('login.submit'), ['email' => 'unknown@example.test', 'password' => 'InvalidPassword123!'])->assertRedirect();
        }
        $this->post(route('login.submit'), ['email' => 'unknown@example.test', 'password' => 'InvalidPassword123!'])->assertStatus(429);
    }

    public function test_failed_private_file_cleanup_is_retained_and_retry_removes_only_the_staged_file(): void
    {
        Storage::fake('local');
        [$c] = $this->context();
        $path = "companies/{$c->id}/conversations/1/messages/1/old.pdf";
        Storage::disk('local')->put($path, 'old');
        PrivateFileCleanup::stage($c->id, [$path]);
        $manager = Storage::getFacadeRoot();
        $disk = \Mockery::mock();
        $disk->shouldReceive('exists')->once()->with($path)->andReturn(true);
        $disk->shouldReceive('delete')->once()->with($path)->andReturn(false);
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);
        $this->assertSame(0, PrivateFileCleanup::flush());
        $this->assertDatabaseCount('private_file_deletions', 1);
        Storage::swap($manager);
        $this->assertSame(1, PrivateFileCleanup::flush());
        $this->assertDatabaseCount('private_file_deletions', 0);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_chat_and_security_views_render_valid_script_artifacts(): void
    {
        [$c,$owner,$member,$conversation] = $this->context();
        $response = $this->actingAs($member)->withSession(['company_id' => $c->id])->get(route('chat', ['conversation' => $conversation->id]))->assertOk();
        preg_match_all('/<script(?![^>]*type="application\/json")(?:\s[^>]*)?>(.*?)<\/script>/s', $response->getContent(), $scripts);
        foreach ($scripts[1] as $index => $script) {
            if (trim($script) !== '') {
                file_put_contents(storage_path("logs/chat-rendered-{$index}.js"), $script);
            }
        }
        $this->post(route('mfa.enable'), ['current_password' => 'password'])->assertRedirect();
        $this->get(route('mfa.settings'))->assertOk()->assertSee('svg', false);
        $this->actingAs($owner)->get(route('admin.employees'))->assertOk();
    }
}
