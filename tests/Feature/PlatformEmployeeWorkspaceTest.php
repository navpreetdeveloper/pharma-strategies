<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformEmployeeWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $first = Company::create(['name' => 'First Pharmacy', 'province' => 'Ontario']);
        $second = Company::create(['name' => 'Second Pharmacy', 'province' => 'Quebec']);
        $admin = User::factory()->create(['platform_role' => 'super_admin']);
        $employee = User::factory()->create(['email' => 'shared@example.test']);
        foreach ([$first, $second] as $company) {
            $company->users()->attach($employee, ['name' => 'Shared employee', 'role' => 'staff', 'status' => 'active']);
        }

        return [$first, $second, $admin, $employee];
    }

    public function test_platform_admin_can_choose_a_company_without_an_existing_workspace(): void
    {
        [$first, $second, $admin] = $this->context();
        $this->actingAs($admin)->get(route('platform.employees.index'))->assertOk()
            ->assertSee('Choose a company')->assertSee('First Pharmacy')->assertSee('Second Pharmacy')
            ->assertDontSee('name="password"', false);
        $this->post(route('platform.employees.workspace'), ['company_id' => $second->id])
            ->assertRedirect(route('admin.employees'))->assertSessionHas('company_id', $second->id);
        $this->get(route('admin.employees'))->assertOk()->assertSee('Managing Second Pharmacy')->assertSee('Switch company');
        $this->assertDatabaseHas('audit_logs', ['company_id' => $second->id, 'user_id' => $admin->id, 'action' => 'platform_company_workspace_opened']);
    }

    public function test_selected_company_controls_employee_creation_and_membership_edits(): void
    {
        [$first, $second, $admin, $employee] = $this->context();
        $this->actingAs($admin)->withSession(['company_id' => $first->id]);
        $this->post(route('platform.employees.workspace'), ['company_id' => $second->id])->assertRedirect();
        $this->post(route('admin.employees.store'), [
            'company_id' => $second->id, 'name' => 'Second employee', 'email' => 'second.employee@example.test',
            'password' => 'EmployeePassword123!', 'role' => 'pharmacist',
        ])->assertRedirect();
        $new = User::whereEmail('second.employee@example.test')->firstOrFail();
        $this->assertDatabaseHas('company_user', ['company_id' => $second->id, 'user_id' => $new->id, 'role' => 'pharmacist']);
        $this->assertDatabaseMissing('company_user', ['company_id' => $first->id, 'user_id' => $new->id]);
        $this->patch(route('admin.employees.update', $employee), [
            'company_id' => $second->id, 'name' => 'Second workspace name', 'employee_id' => 'SECOND-101', 'role' => 'pharmacist', 'status' => 'active',
        ])->assertRedirect();
        $this->assertDatabaseHas('company_user', ['company_id' => $second->id, 'user_id' => $employee->id, 'name' => 'Second workspace name', 'employee_id' => 'SECOND-101', 'role' => 'pharmacist']);
        $this->assertDatabaseHas('company_user', ['company_id' => $first->id, 'user_id' => $employee->id, 'name' => 'Shared employee', 'role' => 'staff']);
    }

    public function test_company_administrators_cannot_use_platform_company_selection(): void
    {
        [$first, $second, $admin, $employee] = $this->context();
        $first->users()->updateExistingPivot($employee->id, ['role' => 'company_super_admin']);
        $this->actingAs($employee)->withSession(['company_id' => $first->id]);
        $this->get(route('platform.employees.index'))->assertForbidden();
        $this->post(route('platform.employees.workspace'), ['company_id' => $second->id])->assertForbidden();
        $this->get(route('admin.employees'))->assertOk()->assertDontSee('id="employee-company"', false);
    }

    public function test_archived_or_missing_companies_cannot_be_selected(): void
    {
        [$first, $second, $admin] = $this->context();
        $second->update(['status' => 'archived']);
        $this->actingAs($admin)->withSession(['company_id' => $first->id]);
        $this->get(route('admin.employees'))->assertOk()->assertDontSee('<option value="'.$second->id.'"', false)
            ->assertSee('Second Pharmacy — Archived')->assertSee('Restore a company from its directory page');
        $this->post(route('platform.employees.workspace'), ['company_id' => $second->id])->assertForbidden()->assertSessionHas('company_id', $first->id);
        $this->post(route('platform.employees.workspace'), ['company_id' => 99999])->assertNotFound();
    }

    public function test_restoring_a_company_makes_it_available_in_the_employee_selector(): void
    {
        [$first, $second, $admin] = $this->context();
        $second->update(['status' => 'archived']);
        $this->actingAs($admin)->withSession(['company_id' => $first->id]);
        $this->patch(route('platform.companies.status', $second), ['status' => 'active'])->assertRedirect();
        $this->get(route('admin.employees'))->assertOk()->assertSee('<option value="'.$second->id.'"', false)
            ->assertDontSee('Second Pharmacy — Archived');
        $this->post(route('platform.employees.workspace'), ['company_id' => $second->id])
            ->assertRedirect(route('admin.employees'))->assertSessionHas('company_id', $second->id);
    }

    public function test_archived_company_links_open_details_outside_the_first_directory_page(): void
    {
        [$first, $second, $admin] = $this->context();
        $second->update(['name' => 'Z Archived Workspace', 'status' => 'archived']);
        for ($index = 0; $index < 26; $index++) {
            Company::create(['name' => 'Additional Pharmacy '.$index, 'province' => 'Ontario']);
        }
        $this->actingAs($admin)->get(route('platform.companies.index', ['company' => $second->id]))->assertOk()
            ->assertViewHas('selected', fn ($selected) => $selected->id === $second->id && $selected->status === 'archived');
    }

    public function test_stale_company_forms_cannot_write_after_switching_workspaces(): void
    {
        [$first, $second, $admin, $employee] = $this->context();
        $this->actingAs($admin)->withSession(['company_id' => $second->id]);
        $this->postJson(route('admin.employees.store'), ['company_id' => $first->id])->assertStatus(409);
        $this->patchJson(route('admin.employees.update', $employee), ['company_id' => $first->id])->assertStatus(409);
        $this->deleteJson(route('admin.employees.delete', $employee), ['company_id' => $first->id])->assertStatus(409);
        $this->patchJson(route('admin.privacy.update'), ['company_id' => $first->id])->assertForbidden();
        $this->assertDatabaseHas('company_user', ['company_id' => $second->id, 'user_id' => $employee->id, 'role' => 'staff']);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_privacy_page_explains_company_scope_and_real_cleanup_configuration(): void
    {
        [$first, $second, $admin] = $this->context();
        $this->actingAs($admin)->withSession(['company_id' => $second->id]);
        config(['chat.retention_scheduled' => false]);
        $this->get(route('admin.privacy'))->assertOk()->assertSee('Conversation access and history policy for Second Pharmacy.')
            ->assertDontSee('name="allow_admin_message_access"', false)
            ->assertSee('Automatic cleanup is disabled.')->assertSee('Keep indefinitely')
            ->assertSee('This setting does not move data')->assertSee('Saving it does not send email');
        config(['chat.retention_scheduled' => true]);
        $this->get(route('admin.privacy'))->assertOk()->assertSee('The server scheduler must be running.');
    }

    public function test_employee_editors_do_not_expand_the_directory_table(): void
    {
        [$first, $second, $admin, $employee] = $this->context();
        $response = $this->actingAs($admin)->withSession(['company_id' => $first->id])->get(route('admin.employees'))->assertOk();
        $html = $response->getContent();
        $this->assertGreaterThan(strpos($html, '</table>'), strpos($html, '<dialog id="employee-editor-'.$employee->id.'"'));
        $response->assertSee('aria-haspopup="dialog"', false)->assertSee('data-employee-editor-close', false);
        $this->assertStringNotContainsString('nc-manage-panel', $html);
    }

    public function test_invalid_employee_edits_preserve_values_and_reopen_the_editor(): void
    {
        [$first, $second, $admin, $employee] = $this->context();
        $this->actingAs($admin)->withSession(['company_id' => $first->id])->from(route('admin.employees'))
            ->patch(route('admin.employees.update', $employee), [
                'company_id' => $first->id, 'employee_editor' => $employee->id,
                'name' => 'Preserved employee name', 'role' => 'staff', 'status' => 'invalid',
            ])->assertRedirect(route('admin.employees'))->assertSessionHasErrors('status');
        $this->get(route('admin.employees'))->assertOk()->assertSee('data-employee-editor-reopen', false)
            ->assertSee('value="Preserved employee name"', false);
    }
}
