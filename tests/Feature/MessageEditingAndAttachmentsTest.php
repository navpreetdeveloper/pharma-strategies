<?php

namespace Tests\Feature;

use App\Events\WorkplaceNotificationSent;
use App\Jobs\DeliverWorkplaceMessage;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
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
            'email_verified_at' => now(),
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
        [$company, $user, $conversation] = $this->context();
        $message = Message::create([
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'body' => 'Original message',
        ]);

        Event::fake();
        $response = $this->actingAs($user)
            ->withSession(['company_id' => $company->id])
            ->patchJson(route('messages.edit', $message), ['body' => 'Edited message']);

        $response->assertOk()->assertJsonPath('message.body', 'Edited message');
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => 'Edited message']);
    }

    public function test_message_edit_is_rejected_after_ten_minutes(): void
    {
        [$company, $user, $conversation] = $this->context();
        $message = Message::create([
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'body' => 'Original message',
        ]);
        $message->forceFill(['created_at' => Carbon::now()->subMinutes(11)])->saveQuietly();

        Event::fake();
        $this->actingAs($user)
            ->withSession(['company_id' => $company->id])
            ->patchJson(route('messages.edit', $message), ['body' => 'Should fail'])
            ->assertStatus(422);

        $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => 'Original message']);
    }

    public function test_uploaded_image_can_be_previewed_through_the_private_attachment_route(): void
    {
        Storage::fake('local');
        [$company, $user, $conversation] = $this->context();
        Event::fake();
        $file = UploadedFile::fake()->createWithContent(
            'team.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jknsAAAAASUVORK5CYII=')
        );

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

    public function test_uploaded_attachment_can_be_downloaded_through_the_private_attachment_route(): void
    {
        Storage::fake('local');
        [$company, $user, $conversation] = $this->context();
        Event::fake();
        $file = UploadedFile::fake()->create('policy.pdf', 200, 'application/pdf');
        $this->actingAs($user)->withSession(['company_id' => $company->id])->postJson(route('messages.send', $conversation), ['attachments' => [$file]])->assertOk();
        $attachment = $conversation->messages()->latest('id')->first()->attachments()->first();
        $download = $this->actingAs($user)->withSession(['company_id' => $company->id])->get(route('attachments.download', $attachment));
        $download->assertOk();
        $this->assertStringContainsString('attachment', strtolower((string) $download->headers->get('Content-Disposition')));
    }

    public function test_saved_message_is_delivered_with_private_notification_without_content_preview(): void
    {
        Storage::fake('local');
        Event::fake([WorkplaceNotificationSent::class]);
        [$company, $user, $conversation] = $this->context();

        $recipient = User::create([
            'name' => 'Staff Member',
            'email' => 'staff@example.test',
            'password' => bcrypt('password'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $company->users()->attach($recipient->id, ['role' => 'staff', 'status' => 'active']);
        $conversation->participants()->attach($recipient->id);

        $this->actingAs($user)
            ->withSession(['company_id' => $company->id])
            ->postJson(route('messages.send', $conversation), ['body' => 'Hello from admin']);

        $savedMessage = $conversation->messages()->latest('id')->firstOrFail();
        (new DeliverWorkplaceMessage($savedMessage->id, $recipient->id))->handle(app(WebPushService::class));
        Event::assertDispatched(WorkplaceNotificationSent::class, function (WorkplaceNotificationSent $event) use ($recipient, $conversation) {
            return $event->userId === (int) $recipient->id
                && $event->data['conversation_id'] === $conversation->id
                && $event->data['important'] === false
                && ! isset($event->data['message_preview'])
                && ! isset($event->data['sender_name'])
                && $event->body === 'You received a new workplace message.';
        });

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_message_can_be_sent_with_an_attachment(): void
    {
        Storage::fake('local');
        [$company, $user, $conversation] = $this->context();
        Event::fake();
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
