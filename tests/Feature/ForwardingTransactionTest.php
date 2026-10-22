<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\MessagePayload;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ForwardingTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_forward_payload_never_exposes_later_source_edits_to_the_other_conversation(): void
    {
        $company = Company::create(['name' => 'Private source', 'province' => 'Ontario']);
        $author = User::factory()->create();
        $sourceConversation = Conversation::create(['company_id' => $company->id, 'type' => 'group']);
        $destination = Conversation::create(['company_id' => $company->id, 'type' => 'group']);
        $source = Message::create(['company_id' => $company->id, 'conversation_id' => $sourceConversation->id, 'user_id' => $author->id, 'body' => 'Later private edit']);
        $copy = Message::create(['company_id' => $company->id, 'conversation_id' => $destination->id, 'user_id' => $author->id, 'body' => 'Originally shared text', 'forwarded_from_message_id' => $source->id]);
        $payload = MessagePayload::make($copy);
        $this->assertSame('Originally shared text', $payload['body']);
        $this->assertArrayNotHasKey('body', $payload['forwarded_from']);
        $this->assertStringNotContainsString('Later private edit', json_encode($payload));
    }

    public function test_a_later_destination_failure_rolls_back_all_copies_and_stages_file_cleanup(): void
    {
        Queue::fake();
        $realDisk = Storage::fake('local');
        $company = Company::create(['name' => 'Atomic forwarding', 'province' => 'Ontario']);
        $user = User::factory()->create();
        $peer = User::factory()->create();
        $company->users()->attach([$user->id => ['role' => 'staff', 'status' => 'active'], $peer->id => ['role' => 'staff', 'status' => 'active']]);
        $conversations = collect();
        for ($i = 0; $i < 3; $i++) {
            $conversation = Conversation::create(['company_id' => $company->id, 'type' => 'group']);
            $conversation->participants()->attach([$user->id, $peer->id]);
            $conversations->push($conversation);
        }
        $source = Message::create(['company_id' => $company->id, 'conversation_id' => $conversations[0]->id, 'user_id' => $user->id, 'body' => 'Forwarded source']);
        Attachment::create(['company_id' => $company->id, 'message_id' => $source->id, 'path' => 'companies/'.$company->id.'/source.txt', 'original_name' => 'source.txt', 'mime_type' => 'text/plain', 'size' => 10]);
        $realDisk->put('companies/'.$company->id.'/source.txt', 'Synthetic source');
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('path')->andReturnUsing(fn ($path) => $realDisk->path($path));
        $disk->shouldReceive('makeDirectory')->andReturn(true);
        $disk->shouldReceive('exists')->andReturn(true);
        $disk->shouldReceive('copy')->twice()->andReturn(true, false);
        $disk->shouldReceive('delete')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $this->actingAs($user)->withSession(['company_id' => $company->id])->postJson(route('messages.forward', $source), ['conversation_ids' => [$conversations[1]->id, $conversations[2]->id]])
            ->assertStatus(500)->assertJsonPath('code', 'server_error');
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('attachments', 1);
        $this->assertDatabaseCount('message_deliveries', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'message_forwarded']);
        $this->assertDatabaseCount('private_file_deletions', 1);
        Queue::assertNothingPushed();
    }
}
