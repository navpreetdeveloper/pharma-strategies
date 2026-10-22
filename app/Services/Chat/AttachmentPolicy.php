<?php

namespace App\Services\Chat;

use Illuminate\Validation\ValidationException;

class AttachmentPolicy
{
    public function validateLimits(array $files): void
    {
        $totalBytes = 0;
        $totalLimit = config('chat.attachments.max_total_mb', 120) * 1024 * 1024;
        $imageLimit = config('chat.attachments.max_image_mb', 10) * 1024 * 1024;
        $videoLimit = config('chat.attachments.max_video_mb', 100) * 1024 * 1024;
        $documentLimit = config('chat.attachments.max_document_mb', 25) * 1024 * 1024;

        foreach ($files as $file) {
            $size = (int) $file->getSize();
            $mime = (string) ($file->getMimeType() ?: '');
            $totalBytes += $size;

            $limit = str_starts_with($mime, 'image/')
                ? $imageLimit
                : (str_starts_with($mime, 'video/') ? $videoLimit : $documentLimit);

            if ($size > $limit) {
                $label = str_starts_with($mime, 'image/') ? 'Images' : (str_starts_with($mime, 'video/') ? 'Videos' : 'Documents');
                $mb = (int) round($limit / 1024 / 1024);
                throw ValidationException::withMessages([
                    'attachments' => ["{$label} must be {$mb} MB or smaller."],
                ]);
            }
        }

        if ($totalBytes > $totalLimit) {
            throw ValidationException::withMessages([
                'attachments' => ['The combined attachment size is too large. Please send fewer or smaller files.'],
            ]);
        }
    }
}
