<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PrivacySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name): Company
    {
        return Company::create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'province' => 'Ontario',
            'status' => 'active',
        ]);
    }

    private function member(string $name, Company $company, string $role = 'staff'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $company->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    public function test_platform_admin_lands_on_company_directory_and_can_manage_workspaces(): void
    {
        $platformAdmin = User::factory()->create(['platform_role' => 'super_admin']);

        $this->actingAs($platformAdmin)
            ->get(route('chat'))
            ->assertRedirect(route('platform.companies.index'));

        $this->actingAs($platformAdmin)
            ->get(route('platform.companies.index'))
            ->assertOk()
            ->assertSee('Companies')
            ->assertSee('All companies');

        $this->post(route('platform.companies.store'), [
            'name' => 'Northside Pharmacy',
            'legal_name' => 'Northside Pharmacy Ltd.',
            'admin_name' => 'Northside Admin', 'admin_email' => 'northside.admin@example.test',
            'admin_password' => 'NorthsidePassword123!', 'admin_password_confirmation' => 'NorthsidePassword123!',
            'province' => 'Ontario',
        ])->assertRedirect();

        $company = Company::where('name', 'Northside Pharmacy')->firstOrFail();
        $this->assertSame('active', $company->status);

        $this->patch(route('platform.companies.update', $company), [
            'name' => 'Northside Health',
            'legal_name' => 'Northside Health Ltd.',
            'province' => 'Alberta',
        ])->assertRedirect();
        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'name' => 'Northside Health',
            'province' => 'Alberta',
        ]);
    }

    public function test_platform_admin_can_read_any_company_conversations_but_cannot_write(): void
    {
        Event::fake();
        $company = $this->company('Cross-company pharmacy');
        PrivacySetting::create(['company_id' => $company->id, 'allow_admin_message_access' => true]);
        $first = $this->member('Staff Member', $company, 'staff');
        $second = $this->member('Pharmacist Member', $company, 'pharmacist');
        $conversation = Conversation::create([
            'company_id' => $company->id,
            'type' => 'direct',
            'created_by' => $first->id,
        ]);
        $conversation->participants()->sync([$first->id, $second->id]);
        $message = Message::create([
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'user_id' => $first->id,
            'body' => 'Private company update',
            'message_type' => 'text',
        ]);
        $platformAdmin = User::factory()->create(['platform_role' => 'super_admin']);

        $this->actingAs($platformAdmin)
            ->post(route('platform.companies.workspace', $company))
            ->assertRedirect(route('chat'));

        $this->get(route('chat', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertSee('Private company update')
            ->assertSee('Staff Member')
            ->assertSee('Pharmacist Member')
            ->assertSee('Companies');

        $this->post(route('messages.send', $conversation), ['body' => 'Unauthorized platform write'])
            ->assertForbidden();
        $this->assertDatabaseMissing('messages', ['body' => 'Unauthorized platform write']);

        $this->post(route('conversations.create'), [
            'type' => 'direct',
            'user_id' => $first->id,
        ])->assertForbidden();

        $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => 'Private company update']);
    }

    public function test_archived_company_is_hidden_from_members_but_retained_for_platform_admin(): void
    {
        $company = $this->company('Archived pharmacy');
        $employee = $this->member('Company employee', $company);
        $platformAdmin = User::factory()->create(['platform_role' => 'super_admin']);

        $this->actingAs($platformAdmin)
            ->patch(route('platform.companies.status', $company), ['status' => 'archived'])
            ->assertRedirect();

        $this->assertDatabaseHas('companies', ['id' => $company->id, 'status' => 'archived']);
        $this->assertDatabaseHas('company_user', ['company_id' => $company->id, 'user_id' => $employee->id]);

        $this->actingAs($employee)
            ->withSession(['company_id' => $company->id])
            ->get(route('chat'))
            ->assertForbidden();

        $this->actingAs($platformAdmin)
            ->get(route('platform.companies.index', ['company' => $company->id]))
            ->assertOk()
            ->assertSee('Archived');

        $this->patch(route('platform.companies.status', $company), ['status' => 'active'])
            ->assertRedirect();
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'status' => 'active']);
    }

    public function test_non_platform_user_cannot_open_platform_company_directory(): void
    {
        $company = $this->company('Tenant A');
        $employee = $this->member('Tenant A user', $company);

        $this->actingAs($employee)
            ->get(route('platform.companies.index'))
            ->assertForbidden();
    }

    public function test_platform_grant_is_separate_from_active_account_status_and_removes_company_roles(): void
    {
        $company = $this->company('Previous employer');
        $owner = User::factory()->create([
            'email' => 'platform-owner@example.test',
            'password' => Hash::make('StrongPassword!123'),
            'status' => 'active',
        ]);
        $company->users()->attach($owner->id, ['role' => 'pharmacist', 'status' => 'active']);

        $this->artisan('platform:super-admin', [
            'email' => $owner->email,
            'action' => 'grant',
            '--force' => true,
        ])->assertExitCode(0);

        $owner->refresh();
        $this->assertSame('active', $owner->status);
        $this->assertSame('super_admin', $owner->platform_role);
        $this->assertDatabaseMissing('company_user', ['user_id' => $owner->id]);

        $this->post(route('login.submit'), [
            'email' => $owner->email,
            'password' => 'StrongPassword!123',
        ])->assertRedirect(route('platform.companies.index'));
        $this->assertAuthenticatedAs($owner);
    }

    public function test_user_can_switch_only_to_an_active_company_membership(): void
    {
        $firstCompany = $this->company('First company');
        $secondCompany = $this->company('Second company');
        $employee = $this->member('Multi-company employee', $firstCompany);
        $secondCompany->users()->attach($employee->id, ['role' => 'pharmacist', 'status' => 'active']);

        $this->actingAs($employee)
            ->withSession(['company_id' => $firstCompany->id])
            ->post(route('company.switch'), ['company_id' => $secondCompany->id])
            ->assertRedirect(route('chat'))
            ->assertSessionHas('company_id', $secondCompany->id);

        $secondCompany->update(['status' => 'archived']);
        $this->post(route('company.switch'), ['company_id' => $secondCompany->id])->assertNotFound();
    }
}
