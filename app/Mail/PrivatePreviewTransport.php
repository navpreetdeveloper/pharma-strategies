<?php

namespace App\Mail;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

class PrivatePreviewTransport extends AbstractTransport
{
    protected function doSend(SentMessage $message): void
    {
        if (! app()->environment(['local', 'testing', 'performance'])) {
            throw new \RuntimeException('Private mail preview is only available in development.');
        }
        $path = 'mail-preview/'.Str::uuid().'.eml';
        if (! Storage::disk('local')->put($path, $message->toString(), ['visibility' => 'private'])) {
            throw new \RuntimeException('Could not store the private development email.');
        }
    }

    public function __toString(): string
    {
        return 'private-preview';
    }
}
