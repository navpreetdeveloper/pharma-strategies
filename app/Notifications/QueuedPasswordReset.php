<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Password;

class QueuedPasswordReset extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    use QueuedSecurityMail;

    public function __construct(#[\SensitiveParameter] string $token)
    {
        parent::__construct($token);
        $this->prepareMailQueue();
    }

    public function shouldSend($notifiable, string $channel): bool
    {
        return $notifiable->status === 'active' && Password::broker()->tokenExists($notifiable, $this->token);
    }
}
