<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

class QueuedVerification extends VerifyEmail implements ShouldBeEncrypted, ShouldQueue
{
    use QueuedSecurityMail;

    public function __construct()
    {
        $this->prepareMailQueue();
    }

    public function shouldSend($notifiable, string $channel): bool
    {
        return $notifiable->status === 'active' && ! $notifiable->hasVerifiedEmail();
    }
}
