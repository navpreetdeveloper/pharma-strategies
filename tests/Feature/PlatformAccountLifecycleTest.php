<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PushSubscription;
use App\Models\RemovedCompanyAccess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformAccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $platform = User::factory()->create(['platform_role' => 'super_admin']);
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $company = Company::create(['name' => 'Recovery Pharmacy', 'province' => 'Ontario']);
        $company->users()->attach($admin, ['role' => 'company_super_admin', 'status' => 'active']);
        $company->users()->attach($member, ['role' => 'pharmacist', 'status' => 'active', 'name' => 'Workplace Name', 'employee_id' => 'P-123', 'department' => 'Dispensing']);

        return [$platform, $admin, $member, $company];
    }

    private function payload(User $member): array
    {
        return ['manage_user_id' => $member->id, 'current_password' => 'password', 'confirmation_email' => $member->email, 'reason' => 'Verified request from the company', 'role' => 'staff'];
    }

    public function test_removal_and_restore_preserve_original_membership_and_credentials(): void
    {
        [$platform, $admin, $member, $company] = $this->context();
        $originalPassword = $member->password;
        $other = Company::create(['name' => 'Other Company', 'province' => 'Ontario']);
        $other->users()->attach($member, ['role' => 'staff', 'status' => 'active']);
        $this->actingAs($admin)->withSession(['company_id' => $company->id])->delete(route('admin.employees.delete', $member))->assertRedirect();
        $this->assertDatabaseMissing('company_user', ['company_id' => $company->id, 'user_id' => $member->id]);
        $this->assertDatabaseHas('removed_company_access', ['company_id' => $company->id, 'user_id' => $member->id]);
        $this->actingAs($platform)->get(route('platform.users.index'))->assertOk()->assertSee('Restore access')->assertSee('Permanently delete account');
        $this->post(route('platform.users.restore-access', $member), $this->payload($member) + ['company_id' => $company->id])->assertRedirect();
        $this->assertDatabaseHas('company_user', ['company_id' => $company->id, 'user_id' => $member->id, 'role' => 'pharmacist', 'name' => 'Workplace Name', 'employee_id' => 'P-123', 'department' => 'Dispensing', 'status' => 'active']);
        $this->assertDatabaseHas('company_user', ['company_id' => $other->id, 'user_id' => $member->id]);
        $this->assertDatabaseMissing('removed_company_access', ['user_id' => $member->id]);
        $this->assertSame($originalPassword, $member->fresh()->password);
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee_access_restored', 'target_id' => $member->id]);
        $this->postJson(route('platform.users.restore-access', $member), $this->payload($member) + ['company_id' => $company->id])->assertUnprocessable();
    }

    public function test_legacy_removal_can_be_recovered_but_unknown_removal_cannot(): void
    {
        [$platform, $admin, $member, $company] = $this->context();
        $company->users()->detach($member);
        $this->actingAs($platform)->postJson(route('platform.users.restore-access', $member), $this->payload($member) + ['company_id' => $company->id])->assertUnprocessable();
        AuditLog::create(['company_id' => $company->id, 'action' => 'employee_access_removed', 'target_type' => 'User', 'target_id' => $member->id]);
        $this->get(route('platform.users.index'))->assertOk()->assertSee('Restore access');
        $this->post(route('platform.users.restore-access', $member), $this->payload($member) + ['company_id' => $company->id])->assertRedirect();
        $this->assertDatabaseHas('company_user', ['company_id' => $company->id, 'user_id' => $member->id, 'role' => 'staff']);
    }

    public function test_permanent_deletion_removes_credentials_and_devices_but_preserves_shared_history(): void
    {
        Storage::fake('local');
        [$platform, $admin, $member, $company] = $this->context();
        $conversation = Conversation::create(['company_id' => $company->id, 'type' => 'direct', 'created_by' => $member->id]);
        $conversation->participants()->attach([$member->id, $admin->id]);
        $message = Message::create(['company_id' => $company->id, 'conversation_id' => $conversation->id, 'user_id' => $member->id, 'body' => 'Shared history']);
        $reply = Message::create(['company_id' => $company->id, 'conversation_id' => $conversation->id, 'user_id' => $admin->id, 'body' => 'Reply', 'reply_to_message_id' => $message->id, 'forwarded_from_message_id' => $message->id]);
        $path = 'companies/'.$company->id.'/retained.txt';
        Storage::disk('local')->put($path, 'Retained file');
        $attachment = Attachment::create(['company_id' => $company->id, 'message_id' => $message->id, 'path' => $path, 'original_name' => 'retained.txt', 'mime_type' => 'text/plain', 'size' => 13]);
        DB::table('sessions')->insert(['id' => 'deleted-user-session', 'user_id' => $member->id, 'payload' => '', 'last_activity' => time()]);
        DB::table('password_reset_tokens')->insert(['email' => $member->email, 'token' => 'old-token', 'created_at' => now()]);
        PushSubscription::create(['user_id' => $member->id, 'endpoint' => 'https://fcm.googleapis.com/example', 'endpoint_hash' => hash('sha256', 'old'), 'subscription' => []]);
        DB::table('company_invitations')->insert(['company_id' => $company->id, 'created_by' => $member->id, 'accepted_by' => $member->id, 'token_hash' => str_repeat('a', 64), 'email' => $member->email, 'name' => $member->name, 'role' => 'staff', 'expires_at' => now()->addDay(), 'accepted_at' => now()]);
        $this->actingAs($platform)->delete(route('platform.users.destroy', $member), $this->payload($member))->assertRedirect(route('platform.users.index'));
        $this->assertDatabaseMissing('users', ['id' => $member->id]);
        foreach (['sessions', 'push_subscriptions', 'company_user', 'conversation_participants'] as $table) {
            $this->assertDatabaseMissing($table, ['user_id' => $member->id]);
        }
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $member->email]);
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'user_id' => null, 'body' => 'Shared history']);
        $this->assertDatabaseHas('company_invitations', ['created_by' => null, 'accepted_by' => null]);
        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform_account_deleted', 'target_id' => $member->id]);
        $unopened = $this->actingAs($admin)->withSession(['company_id' => $company->id])->get(route('chat'))->assertOk();
        $this->assertSame(1, $unopened->viewData('conversations')->first()->unread_count);
        $this->get(route('chat', ['conversation' => $conversation->id]))->assertOk()->assertSee('Deleted account')->assertSee('Shared history');
        $this->getJson(route('messages.show', $reply))->assertOk()->assertJsonPath('message.reply_to.user_name', 'Deleted account')->assertJsonPath('message.forwarded_from.user_name', 'Deleted account');
        $this->get(route('attachments.download', $attachment))->assertOk();
    }

    public function test_verification_and_role_guards_prevent_unauthorized_deletion(): void
    {
        [$platform, $admin, $member, $company] = $this->context();
        $this->actingAs($admin)->withSession(['company_id' => $company->id])->deleteJson(route('platform.users.destroy', $member), $this->payload($member))->assertForbidden();
        $this->postJson(route('platform.users.restore-access', $member), $this->payload($member) + ['company_id' => $company->id])->assertForbidden();
        $this->actingAs($platform)->deleteJson(route('platform.users.destroy', $member), array_replace($this->payload($member), ['current_password' => 'wrong']))->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->deleteJson(route('platform.users.destroy', $member), array_replace($this->payload($member), ['confirmation_email' => 'wrong@example.test']))->assertUnprocessable()->assertJsonValidationErrors('confirmation_email');
        $this->deleteJson(route('platform.users.destroy', $platform), $this->payload($platform))->assertForbidden();
        $platform->update(['status' => 'suspended']);
        $otherPlatform = User::factory()->create(['platform_role' => 'super_admin']);
        $this->actingAs($otherPlatform)->deleteJson(route('platform.users.destroy', $platform), $this->payload($platform))->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $member->id]);
    }

    public function test_last_company_administrator_is_protected_until_a_replacement_exists(): void
    {
        [$platform, $admin, $member, $company] = $this->context();
        $this->actingAs($platform)->deleteJson(route('platform.users.destroy', $admin), $this->payload($admin))->assertUnprocessable();
        $company->users()->updateExistingPivot($member->id, ['role' => 'company_super_admin']);
        $this->delete(route('platform.users.destroy', $admin), $this->payload($admin))->assertRedirect();
        $this->assertDatabaseMissing('users', ['id' => $admin->id]);
    }

    public function test_recovery_does_not_bypass_suspension_or_archiving(): void
    {
        [$platform, $admin, $member, $company] = $this->context();
        $this->actingAs($admin)->withSession(['company_id' => $company->id])->delete(route('admin.employees.delete', $member));
        $member->update(['status' => 'suspended']);
        $this->actingAs($platform)->postJson(route('platform.users.restore-access', $member), $this->payload($member) + ['company_id' => $company->id])->assertUnprocessable();
        $member->update(['status' => 'active']);
        $company->update(['status' => 'archived']);
        $this->postJson(route('platform.users.restore-access', $member), $this->payload($member) + ['company_id' => $company->id])->assertNotFound();
        $this->assertDatabaseMissing('company_user', ['company_id' => $company->id, 'user_id' => $member->id]);
        $this->assertSame(1, RemovedCompanyAccess::count());
    }
}
