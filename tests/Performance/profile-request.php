<?php

// Optional isolated-browser probe; records timings only, never content or keys.
$target = getenv('PERFORMANCE_HTTP_LOG');
$allowed = realpath(__DIR__.'/../../storage/app/private/performance');
if (getenv('APP_ENV') !== 'performance' || ! $target || realpath(dirname($target)) !== $allowed || ! preg_match('/^http-[a-f0-9-]+\.jsonl$/', basename($target))) {
    return;
}
$start = hrtime(true);
register_shutdown_function(static function () use ($start, $target): void {
    $cache = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
    file_put_contents($target, json_encode([
        'path' => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH),
        'execution_ms' => round((hrtime(true) - $start) / 1e6, 2),
        'status' => http_response_code(),
        'opcache_enabled' => (bool) ($cache['opcache_enabled'] ?? false),
        'cached_scripts' => $cache['opcache_statistics']['num_cached_scripts'] ?? 0,
    ]).PHP_EOL, FILE_APPEND | LOCK_EX);
});
