<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PushDeliveryDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerformanceOptimizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_fragments_keep_authorization_and_only_offer_writable_forward_targets(): void
    {
        $company = Company::create(['name' => 'Scoped pharmacy', 'province' => 'Ontario']);
        $admin = User::factory()->create();
        $staff = User::factory()->create();
        $other = User::factory()->create();
        $company->users()->attach([$admin->id => ['role' => 'company_super_admin', 'status' => 'active'], $staff->id => ['role' => 'staff', 'status' => 'active'], $other->id => ['role' => 'staff', 'status' => 'active']]);
        $joined = Conversation::create(['company_id' => $company->id, 'type' => 'group', 'name' => 'Joined']);
        $review = Conversation::create(['company_id' => $company->id, 'type' => 'group', 'name' => 'Review only']);
        $writable = Conversation::create(['company_id' => $company->id, 'type' => 'group', 'name' => 'Writable target']);
        $joined->participants()->attach([$admin->id, $staff->id]);
        $review->participants()->attach([$staff->id, $other->id]);
        $writable->participants()->attach([$admin->id, $staff->id]);
        foreach ([$joined, $review, $writable] as $conversation) {
            Message::create(['company_id' => $company->id, 'conversation_id' => $conversation->id, 'user_id' => $staff->id, 'body' => 'Scoped fixture']);
        }
        $response = $this->actingAs($admin)->withSession(['company_id' => $company->id])->get(route('chat', ['conversation' => $joined->id]), ['X-Chat-View' => '1'])->assertOk();
        $response->assertSee('data-chat-config', false)->assertDontSee('account-navigation')->assertDontSee('<!doctype', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $html = $response->getContent();
        $this->assertStringContainsString('class="nc-forward-option"><input type="checkbox" value="'.$writable->id.'"', $html);
        $this->assertStringNotContainsString('class="nc-forward-option"><input type="checkbox" value="'.$review->id.'"', $html);
        $this->actingAs($other)->withSession(['company_id' => $company->id])->get(route('chat', ['conversation' => $joined->id]), ['X-Chat-View' => '1'])->assertForbidden();
        $foreign = Company::create(['name' => 'Other tenant', 'province' => 'Ontario']);
        $thread = Conversation::create(['company_id' => $foreign->id, 'type' => 'group']);
        $this->actingAs($admin)->withSession(['company_id' => $company->id])->get(route('chat', ['conversation' => $thread->id]), ['X-Chat-View' => '1'])->assertNotFound();
    }

    public function test_public_entry_excludes_account_and_chat_code_without_changing_login_fields(): void
    {
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $response = $this->get('/login')->assertOk();
        $response->assertSee($manifest['resources/js/public.js']['file'], false)->assertDontSee($manifest['resources/js/app.js']['file'], false);
        $response->assertSee('name="email"', false)->assertSee('name="password"', false);
    }

    public function test_push_diagnostics_extract_codes_without_exposing_provider_credentials(): void
    {
        $this->assertSame(60, PushDeliveryDiagnostics::curlCode('cURL error 60: certificate problem at https://provider.invalid/private-capability'));
        $this->assertNull(PushDeliveryDiagnostics::curlCode('Provider did not accept the request'));
        $record = new PushSubscription(['endpoint' => 'https://provider.invalid/private-capability', 'endpoint_hash' => 'hidden-hash', 'subscription' => ['keys' => ['auth' => 'hidden-test-value']]]);
        $this->assertArrayNotHasKey('endpoint', $record->toArray());
        $this->assertArrayNotHasKey('subscription', $record->toArray());
    }
}
