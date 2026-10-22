<?php

namespace App\Services;

class PushDeliveryDiagnostics
{
    /** Extract a numeric diagnostic; never retain provider URLs, headers or keys. */
    public static function curlCode(string $reason): ?int
    {
        return preg_match('/cURL error (\d+)/i', $reason, $matches) ? (int) $matches[1] : null;
    }
}
