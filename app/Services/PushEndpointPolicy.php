<?php

namespace App\Services;

class PushEndpointPolicy
{
    public static function allows(string $endpoint): bool
    {
        $url = parse_url($endpoint);
        if (! $url || ($url['scheme'] ?? '') !== 'https' || isset($url['user'],$url['pass']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || (isset($url['port']) && $url['port'] !== 443)) {
            return false;
        }
        $host = strtolower($url['host'] ?? '');

        return $host === 'fcm.googleapis.com' || $host === 'updates.push.services.mozilla.com'
         || (str_ends_with($host, '.notify.windows.com') && $host !== '.notify.windows.com')
         || (str_ends_with($host, '.push.apple.com') && $host !== '.push.apple.com');
    }
}
