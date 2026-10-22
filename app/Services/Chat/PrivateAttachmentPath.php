<?php

namespace App\Services\Chat;

use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;

class PrivateAttachmentPath
{
    public static function resolve(Attachment $attachment): ?string
    {
        $prefix = 'companies/'.$attachment->company_id.'/';
        $path = $attachment->path;
        if (! str_starts_with($path, $prefix) || str_contains($path, '\\') || in_array('..', explode('/', $path), true)) {
            return null;
        }

        $root = realpath(Storage::disk('local')->path(rtrim($prefix, '/')));
        $file = realpath(Storage::disk('local')->path($path));
        if ($root === false || $file === false || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || ! is_file($file) || ! is_readable($file)) {
            return null;
        }

        return $file;
    }
}
