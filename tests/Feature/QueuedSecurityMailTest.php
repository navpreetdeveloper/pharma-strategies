<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\QueuedPasswordReset;
use App\Notifications\QueuedVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QueuedSecurityMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_recovery_jobs_are_encrypted_and_do_not_share_the_chat_queue(): void
    {
        config(['queue.default' => 'database']);
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);
        $user->sendPasswordResetNotification($token);
        $job = DB::table('jobs')->first();
        $this->assertSame('mail', $job->queue);
        $this->assertStringNotContainsString($token, $job->payload);
        $payload = json_decode($job->payload, true);
        $command = unserialize(decrypt($payload['data']['command']));
        $this->assertTrue($command->shouldBeEncrypted);
        $this->assertInstanceOf(QueuedPasswordReset::class, $command->notification);
        $this->assertTrue($command->notification->shouldSend($user, 'mail'));
        Password::broker()->deleteToken($user);
        $this->assertFalse($command->notification->shouldSend($user, 'mail'));
    }

    public function test_verified_or_suspended_users_do_not_receive_delayed_verification_messages(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $mail = new QueuedVerification;
        $this->assertSame('mail', $mail->queue);
        $this->assertTrue($mail->shouldSend($user, 'mail'));
        $user->markEmailAsVerified();
        $this->assertFalse($mail->shouldSend($user, 'mail'));
        $user->forceFill(['email_verified_at' => null, 'status' => 'suspended'])->save();
        $this->assertFalse($mail->shouldSend($user, 'mail'));
    }

    public function test_development_emails_are_captured_privately_without_logging_reset_tokens(): void
    {
        Storage::fake('local');
        Mail::mailer('private-preview')->raw('Synthetic reset capability', fn ($message) => $message->to('fixture@example.test')->subject('Synthetic preview'));
        $files = Storage::disk('local')->files('mail-preview');
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.eml', $files[0]);
        $this->assertStringContainsString('Synthetic reset capability', Storage::disk('local')->get($files[0]));
        $this->get('/storage/'.$files[0])->assertNotFound();
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame('private', Storage::disk('local')->getVisibility($files[0]));
        }
    }
}
