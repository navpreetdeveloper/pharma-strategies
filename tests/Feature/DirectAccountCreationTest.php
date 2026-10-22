<?php

namespace Tests\Feature;

use App\Events\MessageSent;
use App\Events\WorkplaceNotificationSent;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DirectAccountCreationTest extends TestCase
{
    use RefreshDatabase;

    private function companyContext(string $role = 'company_super_admin'): array
    {
        $company = Company::create(['name' => 'Direct Login Pharmacy', 'province' => 'Ontario']);
        $admin = User::factory()->create();
        $company->users()->attach($admin, ['role' => $role, 'status' => 'active']);

        return [$company, $admin];
    }

    private function data(): array
    {
        return ['name' => 'New Employee', 'email' => 'NEW.EMPLOYEE@example.test', 'password' => 'EmployeePassword123!', 'role' => 'staff'];
    }

    public function test_admin_creates_employee_who_can_log_in_and_open_chat_without_an_invitation(): void
    {
        Notification::fake();
        [$company,$admin] = $this->companyContext();
        $this->actingAs($admin)->withSession(['company_id' => $company->id])->post(route('admin.employees.store'), $this->data())->assertRedirect();
        $employee = User::whereEmail('new.employee@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('EmployeePassword123!', $employee->password));
        $this->assertNull($employee->email_verified_at);
        $this->assertFalse($employee->must_change_password);
        $this->assertDatabaseHas('company_user', ['company_id' => $company->id, 'user_id' => $employee->id, 'role' => 'staff', 'status' => 'active']);
        $this->assertDatabaseCount('company_invitations', 0);
        Notification::assertNothingSent();
        $this->post(route('logout'));
        $this->post(route('login.submit'), ['email' => $employee->email, 'password' => 'EmployeePassword123!'])->assertRedirect(route('chat'));
        $this->assertAuthenticatedAs($employee);
        $this->get(route('chat'))->assertOk();
        $this->getJson(route('workspace.access'))->assertOk()->assertSessionHas('company_id', $company->id);
        $this->post(route('conversations.create'), ['user_id' => $admin->id, 'type' => 'direct'])->assertRedirect();
    }

    public function test_existing_email_does_not_reset_password_or_add_a_membership(): void
    {
        [$company,$admin] = $this->companyContext();
        $existing = User::factory()->create(['email' => 'new.employee@example.test', 'password' => Hash::make('OriginalPassword123!')]);
        $this->actingAs($admin)->withSession(['company_id' => $company->id])->postJson(route('admin.employees.store'), $this->data())->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertTrue(Hash::check('OriginalPassword123!', $existing->fresh()->password));
        $this->assertDatabaseMissing('company_user', ['company_id' => $company->id, 'user_id' => $existing->id]);
    }

    public function test_weak_password_is_rejected_and_staff_cannot_create_accounts(): void
    {
        [$company,$admin] = $this->companyContext();
        $this->actingAs($admin)->withSession(['company_id' => $company->id])->postJson(route('admin.employees.store'), array_replace($this->data(), ['password' => 'short']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $company->users()->updateExistingPivot($admin->id, ['role' => 'staff']);
        $this->postJson(route('admin.employees.store'), $this->data())->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'new.employee@example.test']);
    }

    public function test_manager_cannot_create_company_or_platform_administrators(): void
    {
        [$company,$admin] = $this->companyContext('pharmacy_manager');
        $this->actingAs($admin)->withSession(['company_id' => $company->id])->postJson(route('admin.employees.store'), array_replace($this->data(), ['role' => 'company_super_admin']))->assertForbidden();
        $this->postJson(route('admin.employees.store'), array_replace($this->data(), ['role' => 'super_admin']))->assertUnprocessable();
        $this->post(route('admin.employees.store'), $this->data() + ['platform_role' => 'super_admin'])->assertRedirect();
        $this->assertNull(User::whereEmail('new.employee@example.test')->firstOrFail()->platform_role);
    }

    public function test_new_employee_can_receive_and_send_messages_with_email_verification_disabled(): void
    {
        Event::fake([MessageSent::class, WorkplaceNotificationSent::class]);
        [$company,$admin] = $this->companyContext();
        $this->actingAs($admin)->withSession(['company_id' => $company->id])->post(route('admin.employees.store'), $this->data())->assertRedirect();
        $employee = User::whereEmail('new.employee@example.test')->firstOrFail();
        $conversation = Conversation::create(['company_id' => $company->id, 'type' => 'direct', 'created_by' => $admin->id]);
        $conversation->participants()->sync([$admin->id, $employee->id]);
        $this->get(route('chat', ['conversation' => $conversation->id]))->assertOk()->assertSee('New Employee');
        $this->post(route('logout'));
        $this->post(route('login.submit'), ['email' => $employee->email, 'password' => 'EmployeePassword123!'])->assertRedirect(route('chat'));
        $this->postJson(route('messages.send', $conversation), ['body' => 'Simple login works'])->assertOk();
        $message = Message::firstOrFail();
        $this->assertTrue($employee->activeInCompany($company->id));
        $this->assertTrue($employee->canWriteConversation($conversation));
        $this->assertDatabaseHas('message_deliveries', ['message_id' => $message->id, 'user_id' => $admin->id]);
    }

    public function test_employee_form_contains_password_and_no_invitation_section(): void
    {
        [$company,$admin] = $this->companyContext();
        $this->actingAs($admin)->withSession(['company_id' => $company->id])->get(route('admin.employees'))->assertOk()->assertSee('Create account')->assertSee('name="password"', false)->assertDontSee('Create invitation')->assertDontSee('Pending invitations');
    }

    public function test_old_invitation_links_are_disabled(): void
    {
        $this->get('/invite/old-token')->assertNotFound();
        $this->post('/invite/old-token')->assertNotFound();
    }

    public function test_platform_account_command_creates_a_hashed_password_and_working_login(): void
    {
        $result = Artisan::call('platform:create-admin', ['email' => 'PLATFORM@example.test']);
        $this->assertSame(0, $result);
        preg_match('/Password: (\S+)/', Artisan::output(), $matches);
        $this->assertNotEmpty($matches[1]);
        $admin = User::whereEmail('platform@example.test')->firstOrFail();
        $this->assertSame('super_admin', $admin->platform_role);
        $this->assertTrue(Hash::check($matches[1], $admin->password));
        $this->assertNotSame($matches[1], $admin->password);
        $this->assertDatabaseCount('company_user', 0);
        $this->post(route('login.submit'), ['email' => $admin->email, 'password' => $matches[1]])->assertRedirect(route('platform.companies.index'));
        $this->get(route('platform.companies.index'))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform_super_admin_created']);
    }

    public function test_platform_creation_keeps_existing_platform_password_and_protects_company_owners(): void
    {
        [$company,$owner] = $this->companyContext();
        $this->artisan('platform:create-admin', ['email' => $owner->email])->assertExitCode(1);
        $this->assertNull($owner->fresh()->platform_role);
        $this->assertDatabaseHas('company_user', ['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'company_super_admin']);
        $platform = User::factory()->create(['platform_role' => 'super_admin', 'password' => Hash::make('KeepPassword123!')]);
        $this->artisan('platform:create-admin', ['email' => $platform->email])->assertExitCode(0);
        $this->assertTrue(Hash::check('KeepPassword123!', $platform->fresh()->password));
    }
}
