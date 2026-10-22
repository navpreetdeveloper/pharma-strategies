<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat\MutateMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MessageReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        Queue::fake();
        $company = Company::create(['name' => 'Reliability pharmacy', 'province' => 'Ontario']);
        $actor = User::factory()->create();
        $peer = User::factory()->create();
        $company->users()->attach([$actor->id => ['role' => 'staff', 'status' => 'active'], $peer->id => ['role' => 'staff', 'status' => 'active']]);
        $conversation = Conversation::create(['company_id' => $company->id, 'type' => 'direct']);
        $conversation->participants()->attach([$actor->id, $peer->id]);
        $this->actingAs($actor)->withSession(['company_id' => $company->id]);

        return [$company, $actor, $peer, $conversation];
    }

    public function test_retried_send_reuses_saved_message_and_attachment_but_rejects_changed_content(): void
    {
        [$company, $actor, $peer, $conversation] = $this->workspace();
        Storage::fake('local');
        $key = (string) Str::uuid();
        $send = fn ($body) => $this->postJson(route('messages.send', $conversation), ['body' => $body, 'client_message_id' => $key, 'attachments' => [UploadedFile::fake()->createWithContent('policy.txt', 'Synthetic attachment')]]);
        $first = $send('Original')->assertOk();
        $send('Original')->assertOk()->assertJsonPath('message.id', $first->json('message.id'));
        $send('Changed')->assertStatus(409);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('attachments', 1);
        $this->assertDatabaseCount('message_deliveries', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_recovery_pages_preserve_gaps_and_reconcile_changed_and_removed_rows(): void
    {
        [$company, $actor, $peer, $conversation] = $this->workspace();
        $ids = [];
        foreach (range(1, 103) as $i) {
            $ids[] = Message::create(['company_id' => $company->id, 'conversation_id' => $conversation->id, 'user_id' => $peer->id, 'body' => 'Message '.$i])->id;
        }
        $edited = Message::findOrFail($ids[0]);
        $edited->update(['body' => 'Updated', 'edited_at' => now()]);
        $unsent = Message::findOrFail($ids[1]);
        $unsent->update(['body' => null, 'deleted_at' => now()]);
        Message::findOrFail($ids[2])->delete();
        $url = route('conversations.updates', $conversation);
        $first = $this->getJson($url.'?'.http_build_query(['after' => 0, 'visible' => array_slice($ids, 0, 3)]))->assertOk()->assertJsonCount(100, 'messages')->assertJsonPath('has_more', true);
        $first->assertJsonPath('reconciled.0.body', 'Updated')->assertJsonPath('reconciled.1.body', null)->assertJsonPath('removed.0', $ids[2]);
        $second = $this->getJson($url.'?after='.$first->json('cursor'))->assertOk()->assertJsonCount(2, 'messages')->assertJsonPath('has_more', false);
        $this->assertSame($ids[102], $second->json('cursor'));
        $this->getJson($url.'?'.http_build_query(['after' => 0, 'visible' => range(1, 101)]))->assertUnprocessable();
    }

    public function test_stale_edit_cannot_restore_an_unsent_body_and_unsend_retries_are_idempotent(): void
    {
        [$company, $actor, $peer, $conversation] = $this->workspace();
        $message = Message::create(['company_id' => $company->id, 'conversation_id' => $conversation->id, 'user_id' => $actor->id, 'body' => 'Original']);
        $mutator = app(MutateMessage::class);
        $mutator->execute($actor, $message, null, null);
        $mutator->execute($actor, $message, null, null);
        $this->assertSame(1, AuditLog::where('action', 'message_unsent')->count());
        try {
            $mutator->execute($actor, $message, 'Stale edit', null);
            $this->fail('An edit of an unsent message must be rejected.');
        } catch (ValidationException) {
            $this->assertNull($message->fresh()->body);
            $this->assertNotNull($message->fresh()->deleted_at);
        }
    }

    public function test_edit_and_audit_are_atomic_when_audit_storage_fails(): void
    {
        [$company, $actor, $peer, $conversation] = $this->workspace();
        $message = Message::create(['company_id' => $company->id, 'conversation_id' => $conversation->id, 'user_id' => $actor->id, 'body' => 'Original']);
        $created = $message->created_at->toISOString();
        $event = 'eloquent.creating: '.AuditLog::class;
        Event::listen($event, fn () => throw new \RuntimeException('Synthetic audit outage'));
        try {
            app(MutateMessage::class)->execute($actor, $message, 'Changed', null);
            $this->fail('Audit failure must roll back the mutation.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic audit outage', $error->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame('Original', $message->fresh()->body);
        $this->assertNull($message->fresh()->edited_at);
        app(MutateMessage::class)->execute($actor, $message, 'Changed', null);
        $this->assertSame($created, $message->fresh()->created_at->toISOString());
    }
}
