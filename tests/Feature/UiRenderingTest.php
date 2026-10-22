<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UiRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_render_their_forms_and_icon_components(): void
    {
        $this->get('/')->assertOk()->assertSee('Private communication for Canadian pharmacy teams.')->assertSee('Skip to content');
        $this->get(route('login'))->assertOk()->assertSee('autocomplete="current-password"', false);
        $this->get('/register')->assertNotFound();
        $this->get(route('password.request'))->assertOk()->assertSee('Send reset link');
    }

    public function test_platform_profile_shows_platform_controls_instead_of_employee_memberships(): void
    {
        $admin = User::factory()->create(['platform_role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($admin)->get(route('profile.show'))
            ->assertOk()
            ->assertSee('Platform administration')
            ->assertSee(route('platform.companies.index'))
            ->assertSee('Two-step sign-in')
            ->assertDontSee('Your company access')
            ->assertDontSee('Company memberships')
            ->assertDontSee('Company privacy settings')
            ->assertDontSee('Employee ID');
    }

    public function test_administration_screens_render_and_privacy_form_stays_inside_page_container(): void
    {
        $company = Company::create(['name' => 'Pharmacy & Partners', 'province' => 'Ontario']);
        $admin = User::factory()->create(['name' => 'Workspace Administrator']);
        $company->users()->attach($admin, ['role' => 'company_super_admin', 'status' => 'active']);
        AuditLog::create(['company_id' => $company->id, 'user_id' => $admin->id, 'action' => 'employee_created', 'target_type' => '<script>alert(1)</script>', 'target_id' => $admin->id]);
        $this->actingAs($admin)->withSession(['company_id' => $company->id]);
        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.employees'))->assertOk()->assertSee('data-label="Actions"', false);
        $this->get(route('admin.audit'))->assertOk()->assertSee('Employee created')->assertSee('Archived record')->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('IP address');
        $this->get(route('profile.show'))->assertOk();
        $response = $this->get(route('admin.privacy'))->assertOk();
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        $forms = $xpath->query('//main[@id="main-content"]//div[contains(concat(" ", normalize-space(@class), " "), " nc-page ")]//form[@action="'.route('admin.privacy.update').'"]');
        $this->assertSame(1, $forms->length, 'The privacy form must remain inside the responsive page container.');
    }
}
