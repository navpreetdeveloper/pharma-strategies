<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SiteUiAuditTest extends TestCase
{
    use RefreshDatabase;

    private function inspect(string $url, string $fixture, int $status = 200): void
    {
        $response = $this->get($url)->assertStatus($status);
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/<title>[^<]+Pharma Strategies<\/title>/', $html);
        $this->assertMatchesRegularExpression('/<meta name="description" content="[^"\n]+"/', $html);
        $this->assertStringContainsString('favicon.svg', $html);
        $this->assertStringNotContainsString('placeholder=', $html);
        $this->assertStringContainsString('id="main-content"', $html);

        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        foreach ($dom->getElementsByTagName('a') as $link) {
            $href = $link->getAttribute('href');
            $this->assertNotSame('', $href, 'Empty link in '.$fixture);
            $this->assertNotSame('#', $href, 'Unfinished link in '.$fixture);
            if (str_starts_with($href, '#')) {
                $this->assertNotNull($dom->getElementById(substr($href, 1)));
            } elseif (! preg_match('/^(mailto:|tel:)/', $href)) {
                $path = parse_url($href, PHP_URL_PATH) ?: '/';
                $this->assertNotNull($path);
                if (! is_file(public_path(ltrim($path, '/')))) {
                    $match = Route::getRoutes()->match(Request::create($path));
                    $this->assertContains('GET', $match->methods());
                }
            }
        }
        foreach ($dom->getElementsByTagName('form') as $form) {
            $path = parse_url($form->getAttribute('action'), PHP_URL_PATH);
            $method = strtoupper($form->getAttribute('method') ?: 'GET');
            foreach ($form->getElementsByTagName('input') as $input) {
                if ($input->getAttribute('name') === '_method') {
                    $method = strtoupper($input->getAttribute('value'));
                }
            }
            $match = Route::getRoutes()->match(Request::create($path, $method));
            $this->assertContains($method, $match->methods());
        }
        if (getenv('UI_AUDIT_EXPORT') === '1') {
            $directory = storage_path('app/private/ui-audit');
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($directory.'/'.$fixture.'.html', $html);
        }
    }

    public function test_public_pages_have_metadata_working_links_and_custom_not_found(): void
    {
        config(['site.support_email' => 'support@example.test', 'site.support_phone' => '+1 (416) 555-0100']);
        $this->inspect('/', 'home');
        $this->inspect('/login', 'login');
        $this->inspect('/privacy', 'privacy-notice');
        $this->inspect('/forgot-password', 'forgot');
        $this->inspect('/reset-password/test-token', 'reset');
        $this->inspect('/two-factor-challenge', 'challenge');
        $this->inspect('/page-that-does-not-exist', '404', 404);
        $this->get('/')->assertSee('mailto:support@example.test', false)->assertSee('tel:+14165550100', false)->assertSee((string) now()->year);
        $this->getJson('/page-that-does-not-exist')->assertNotFound()->assertHeader('Content-Type', 'application/json');
    }

    public function test_workspace_pages_and_role_navigation_have_valid_links_and_forms(): void
    {
        $company = Company::create(['name' => 'North Pharmacy', 'province' => 'Ontario', 'status' => 'active']);
        $admin = User::factory()->create(['name' => 'Morgan Pharmacy Administrator With A Long Name']);
        $employee = User::factory()->create(['name' => 'Alex']);
        $company->users()->attach($admin->id, ['role' => 'company_super_admin', 'status' => 'active']);
        $company->users()->attach($employee->id, ['role' => 'staff', 'status' => 'active']);
        $conversation = Conversation::create(['company_id' => $company->id, 'type' => 'direct', 'created_by' => $admin->id]);
        $conversation->participants()->sync([$admin->id, $employee->id]);
        Message::create(['company_id' => $company->id, 'conversation_id' => $conversation->id, 'user_id' => $admin->id, 'body' => str_repeat('long-message-', 40)]);
        $this->actingAs($admin)->withSession(['company_id' => $company->id]);
        $this->inspect('/workspace/privacy', 'workspace-privacy');
        foreach (['/company' => 'workspace', '/admin/employees' => 'employees', '/admin/privacy' => 'privacy', '/admin/audit-logs' => 'activity', '/profile' => 'profile', '/account/authenticator' => 'authenticator', '/chat' => 'chat-list', '/chat?conversation='.$conversation->id => 'chat'] as $url => $fixture) {
            $this->inspect($url, $fixture);
        }
        $platform = User::factory()->create(['platform_role' => 'super_admin']);
        $this->actingAs($platform)->withSession(['company_id' => null]);
        foreach (['/platform/users' => 'people', '/platform/companies' => 'companies', '/platform/companies?create=1' => 'create-company', '/platform/employees' => 'choose-company', '/platform/activity' => 'platform-activity'] as $url => $fixture) {
            $this->inspect($url, $fixture);
        }
        $this->actingAs($employee)->withSession(['company_id' => $company->id]);
        $this->get('/chat')->assertOk()->assertDontSee('>People</a>', false)->assertDontSee('>Companies</a>', false)->assertSee('navigation-toggle', false);
    }
}
