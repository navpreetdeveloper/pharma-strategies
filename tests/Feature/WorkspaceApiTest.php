<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WorkspaceApiTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $company = Company::create(['name' => 'API Pharmacy', 'province' => 'Ontario']);
        $user = User::factory()->create();
        $peer = User::factory()->create();
        $company->users()->attach([$user->id => ['role' => 'staff', 'status' => 'active'], $peer->id => ['role' => 'staff', 'status' => 'active']]);
        $conversation = Conversation::create(['company_id' => $company->id, 'created_by' => $peer->id, 'type' => 'direct']);
        $conversation->participants()->attach([$user->id, $peer->id]);
        $message = Message::create(['company_id' => $company->id, 'conversation_id' => $conversation->id, 'user_id' => $peer->id, 'body' => 'Incoming']);

        return [$company, $user, $peer, $conversation, $message];
    }

    public function test_api_requires_authentication_and_exposes_only_safe_account_fields(): void
    {
        $this->get('/api/v1/me')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        [$company, $user] = $this->context();
        $this->actingAs($user)->get('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id)
            ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token')->assertJsonMissingPath('data.two_factor_secret');
        $user->update(['status' => 'suspended']);
        $this->get('/api/v1/me')->assertForbidden();
    }

    public function test_conversation_list_and_messages_are_company_and_participant_scoped(): void
    {
        [$company, $user, $peer, $conversation, $message] = $this->context();
        $otherCompany = Company::create(['name' => 'Private employer', 'province' => 'Ontario']);
        $other = Conversation::create(['company_id' => $otherCompany->id, 'type' => 'direct']);
        $otherMessage = Message::create(['company_id' => $otherCompany->id, 'conversation_id' => $other->id, 'user_id' => $peer->id, 'body' => 'Private message']);
        $sameCompanyPrivate = Conversation::create(['company_id' => $company->id, 'type' => 'group']);
        $sameCompanyPrivate->participants()->attach($peer);
        Message::create(['company_id' => $company->id, 'conversation_id' => $sameCompanyPrivate->id, 'user_id' => $peer->id, 'body' => 'Private group']);
        $this->actingAs($user)->withSession(['company_id' => $company->id]);
        $this->get('/api/v1/conversations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $conversation->id)->assertJsonPath('data.0.unread_count', 1)->assertJsonMissingPath('data.0.latest_message.body');
        $this->get('/api/v1/conversations/'.$conversation->id.'/messages?after=0')->assertOk()->assertJsonPath('messages.0.body', 'Incoming');
        $this->get('/api/v1/conversations/'.$other->id.'/messages?after=0')->assertForbidden()->assertDontSee('Private message');
        $this->get('/api/v1/conversations/'.$sameCompanyPrivate->id.'/messages?after=0')->assertForbidden();
        $this->postJson('/api/v1/conversations/'.$conversation->id.'/read', ['last_message_id' => $otherMessage->id])->assertNotFound();
        $this->postJson('/api/v1/conversations/'.$conversation->id.'/read', ['last_message_id' => $message->id])->assertOk();
        $this->get('/api/v1/conversations')->assertJsonPath('data.0.unread_count', 0);
        $this->get('/api/v1/conversations/'.$conversation->id.'/messages?after=bad')->assertUnprocessable()->assertJsonValidationErrors('after');
        $company->users()->detach($user);
        $this->get('/api/v1/conversations')->assertForbidden();
    }

    public function test_api_cannot_bypass_mfa_or_required_password_change(): void
    {
        [$company, $user] = $this->context();
        $user->update(['must_change_password' => true]);
        $this->actingAs($user)->get('/api/v1/me')->assertForbidden();
        $user->forceFill(['must_change_password' => false, 'two_factor_secret' => encrypt('authenticator-secret'), 'two_factor_confirmed_at' => now()])->save();
        $this->get('/api/v1/me')->assertUnauthorized()->assertJsonPath('message', 'Two-step verification is required.');
    }

    public function test_mutations_require_csrf_and_use_the_existing_message_delivery_flow(): void
    {
        Queue::fake();
        [$company, $user, $peer, $conversation] = $this->context();
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $url = '/api/v1/conversations/'.$conversation->id.'/messages';
        $this->actingAs($user)->withSession(['company_id' => $company->id, '_token' => 'known-csrf-token'])
            ->postJson($url, ['body' => 'CSRF protected message'])->assertStatus(419);
        $this->assertDatabaseMissing('messages', ['body' => 'CSRF protected message']);
        $this->withHeader('X-CSRF-TOKEN', 'known-csrf-token')->postJson($url, ['body' => 'CSRF protected message'])
            ->assertOk()->assertJsonPath('message.user_name', $user->name);
        $this->assertDatabaseHas('messages', ['body' => 'CSRF protected message', 'user_id' => $user->id]);
    }

    public function test_unexpected_json_errors_do_not_expose_internal_details_even_in_debug_mode(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/v1/test-error', fn () => throw new \RuntimeException('Private SQL connection detail'));
        $this->getJson('/api/v1/test-error')->assertStatus(500)->assertJsonPath('code', 'server_error')
            ->assertDontSee('Private SQL connection detail')->assertJsonMissingPath('trace');
    }
}
