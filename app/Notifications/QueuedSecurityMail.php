<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;

trait QueuedSecurityMail
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    protected function prepareMailQueue(): void
    {
        $this->onQueue('mail')->afterCommit();
    }

    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }
}
