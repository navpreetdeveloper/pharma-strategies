<?php

namespace Tests\Feature;

use App\Events\WorkplaceNotificationSent;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MessageEditingAndAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $company = Company::create([
            'name' => 'Example Pharmacy',
            'legal_name' => 'Example Pharmacy',
            'province' => 'Ontario',
        ]);
        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
        $company->users()->attach($user->id, ['role' => 'company_super_admin', 'status' => 'active']);
        $conversation = Conversation::create([
            'company_id' => $company->id,
            'type' => 'direct',
            'created_by' => $user->id,
        ]);
        $conversation->participants()->attach($user->id);

        return [$company, $user, $conversation];
    }

    public function test_user_can_edit_their_message_within_ten_minutes(): void
    {
        Event::fake();
        [$company, $user, $conversation] = $this->context();
        $message = Message::create([
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'body' => 'Original message',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['company_id' => $company->id])
            ->patchJson(route('messages.edit', $message), ['body' => 'Edited message']);

        $response->assertOk()->assertJsonPath('message.body', 'Edited message');
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => 'Edited message']);
    }

    public function test_message_edit_is_rejected_after_ten_minutes(): void
    {
        Event::fake();
        [$company, $user, $conversation] = $this->context();
        $message = Message::create([
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'body' => 'Original message',
        ]);
        $message->forceFill(['created_at' => Carbon::now()->subMinutes(11)])->saveQuietly();

        $this->actingAs($user)
            ->withSession(['company_id' => $company->id])
            ->patchJson(route('messages.edit', $message), ['body' => 'Should fail'])
            ->assertStatus(422);

        $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => 'Original message']);
    }


    public function test_uploaded_image_can_be_previewed_through_the_private_attachment_route(): void
    {
        Storage::fake('local');
        Event::fake();
        [$company, $user, $conversation] = $this->context();
        $file = UploadedFile::fake()->image('team.png', 80, 80);

        $response = $this->actingAs($user)
            ->withSession(['company_id' => $company->id])
            ->postJson(route('messages.send', $conversation), [
                'attachments' => [$file],
            ]);

        $response->assertOk();
        $attachment = $conversation->messages()->latest('id')->first()->attachments()->first();

        $preview = $this->actingAs($user)
            ->withSession(['company_id' => $company->id])
            ->get(route('attachments.preview', $attachment));

        $preview->assertOk();
        $preview->assertHeader('Content-Type', 'image/png');
        Storage::disk('local')->assertExists($attachment->path);
    }


    public function test_chat_attachments_use_the_configured_attachment_disk_for_upload_and_preview(): void
    {
        Storage::fake('s3');
        Event::fake();
        config(['chat.attachments.disk' => 's3']);
        [$company, $user, $conversation] = $this->context();
        $file = UploadedFile::fake()->image('configured-disk.png', 80, 80);

        $this->actingAs($user)
            ->withSession(['company_id' => $company->id])
            ->postJson(route('messages.send', $conversation), ['attachments' => [$file]])
            ->assertOk();

        $attachment = $conversation->messages()->latest('id')->first()->attachments()->first();
        Storage::disk('s3')->assertExists($attachment->path);

        $preview = $this->actingAs($user)
            ->withSession(['company_id' => $company->id])
            ->get(route('attachments.preview', $attachment));

        $preview->assertOk();
        $preview->assertHeader('Content-Type', 'image/png');
    }

    public function test_uploaded_attachment_can_be_downloaded_through_the_private_attachment_route(): void
    {
        Storage::fake('local');
        Event::fake();
        [$company, $user, $conversation] = $this->context();
        $file = UploadedFile::fake()->create('policy.pdf', 200, 'application/pdf');
        $this->actingAs($user)->withSession(['company_id' => $company->id])->postJson(route('messages.send', $conversation), ['attachments' => [$file]])->assertOk();
        $attachment = $conversation->messages()->latest('id')->first()->attachments()->first();
        $download = $this->actingAs($user)->withSession(['company_id' => $company->id])->get(route('attachments.download', $attachment));
        $download->assertOk();
        $this->assertStringContainsString('attachment', strtolower((string) $download->headers->get('Content-Disposition')));
    }

    public function test_message_sends_immediate_private_realtime_notification_to_other_participant(): void
    {
        Storage::fake('local');
        Event::fake([WorkplaceNotificationSent::class]);
        [$company, $user, $conversation] = $this->context();

        $recipient = User::create([
            'name' => 'Staff Member',
            'email' => 'staff@example.test',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
        $company->users()->attach($recipient->id, ['role' => 'staff', 'status' => 'active']);
        $conversation->participants()->attach($recipient->id);

        $this->actingAs($user)
            ->withSession(['company_id' => $company->id])
            ->postJson(route('messages.send', $conversation), ['body' => 'Hello from admin']);

        Event::assertDispatched(WorkplaceNotificationSent::class, function (WorkplaceNotificationSent $event) use ($recipient, $conversation) {
            return $event->userId === (int) $recipient->id
                && $event->data['conversation_id'] === $conversation->id
                && $event->data['sender_name'] === 'Admin'
                && $event->data['sender_initials'] === 'A'
                && $event->data['message_preview'] === 'Hello from admin';
        });

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $recipient->id,
            'notifiable_type' => User::class,
        ]);
    }

    public function test_message_can_be_sent_with_an_attachment(): void
    {
        Storage::fake('local');
        Event::fake();
        [$company, $user, $conversation] = $this->context();
        $file = UploadedFile::fake()->create('policy.pdf', 200, 'application/pdf');

        $response = $this->actingAs($user)
            ->withSession(['company_id' => $company->id])
            ->postJson(route('messages.send', $conversation), [
                'body' => 'Please review this policy.',
                'attachments' => [$file],
            ]);

        $response->assertOk()->assertJsonPath('message.body', 'Please review this policy.')->assertJsonPath('message.attachments.0.name', 'policy.pdf')->assertJsonPath('message.attachments.0.mime_type', 'application/pdf');
        $this->assertDatabaseCount('attachments', 1);
        $attachment = $conversation->messages()->latest('id')->first()->attachments()->first();
        Storage::disk('local')->assertExists($attachment->path);
    }
}
