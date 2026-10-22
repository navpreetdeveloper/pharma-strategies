<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PrivateFileCleanup
{
    /** Preserve the original failure while recording files for retryable cleanup. */
    public static function discard(int $companyId, array $paths): void
    {
        if ($paths === []) {
            return;
        }
        try {
            self::stage($companyId, $paths);
            self::flush();
        } catch (\Throwable $error) {
            report($error);
        }
    }

    public static function stage(int $companyId, array $paths): void
    {
        foreach ($paths as $path) {
            if (! self::valid($companyId, $path)) {
                throw new \RuntimeException('Refused cleanup outside private company storage.');
            }
            DB::table('private_file_deletions')->insertOrIgnore(['company_id' => $companyId, 'path' => $path, 'path_hash' => hash('sha256', $path), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private static function valid(int $companyId, string $path): bool
    {
        return str_starts_with($path, "companies/{$companyId}/") && ! in_array('..', preg_split('~[\\\\/]~', $path), true) && ! str_contains($path, "\0");
    }

    public static function flush(): int
    {
        $count = 0;
        foreach (DB::table('private_file_deletions')->orderBy('id')->limit(100)->get() as $task) {
            try {
                if (! self::valid($task->company_id, $task->path)) {
                    throw new \RuntimeException('Refused unsafe cleanup task.');
                }
                $disk = Storage::disk('local');
                if ($disk->exists($task->path) && ! $disk->delete($task->path)) {
                    throw new \RuntimeException('Private file cleanup failed; task retained for retry.');
                }
                DB::table('private_file_deletions')->where('id', $task->id)->delete();
                $count++;
            } catch (\Throwable $error) {
                report($error);
            }
        }

        return $count;
    }
}
