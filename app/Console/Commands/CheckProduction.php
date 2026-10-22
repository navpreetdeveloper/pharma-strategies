<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;

class CheckProduction extends Command
{
    protected $signature = 'pharma:check-production {--require-push : Require browser push configuration} {--require-backup : Require a successful backup in the past 26 hours}';

    protected $description = 'Check release configuration without printing secrets or claiming device delivery';

    public function handle(): int
    {
        $key = (string) config('app.key');
        $decodedKey = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $host = (string) parse_url(config('app.url'), PHP_URL_HOST);
        $checks = [
            'production environment' => app()->environment('production'),
            'debug disabled' => ! config('app.debug'),
            'HTTPS application URL' => parse_url(config('app.url'), PHP_URL_SCHEME) === 'https'
                && $host !== '' && ! in_array($host, ['localhost', '127.0.0.1', 'example.com'], true)
                && ! str_ends_with($host, '.example.com') && ! str_ends_with($host, '.test'),
            'application key configured' => is_string($decodedKey) && Encrypter::supported($decodedKey, config('app.cipher')),
            'secure session cookie' => (bool) config('session.secure'),
            'HttpOnly session cookie' => (bool) config('session.http_only'),
            'database-backed sessions' => config('session.driver') === 'database',
            'asynchronous queue' => in_array(config('queue.default'), ['database', 'redis'], true),
            'Reverb broadcasting' => config('broadcasting.default') === 'reverb',
            'production Reverb credentials' => (bool) config('broadcasting.connections.reverb.app_id')
                && ! in_array(config('broadcasting.connections.reverb.key'), ['', null, 'pharma-strategies-local-key'], true)
                && ! in_array(config('broadcasting.connections.reverb.secret'), ['', null, 'pharma-strategies-local-secret'], true),
            'explicit Reverb origin allowlist' => ! empty(config('reverb.apps.apps.0.allowed_origins')) && ! in_array('*', config('reverb.apps.apps.0.allowed_origins'), true),
            'privileged MFA required' => (bool) config('security.require_privileged_mfa'),
            'real SMTP delivery configured' => config('mail.default') === 'smtp' && (bool) config('mail.mailers.smtp.host') && (bool) config('mail.from.address'),
        ];
        if ($this->option('require-push') || config('webpush.enabled')) {
            $checks['push enabled and configured'] = config('webpush.enabled') && config('webpush.subject') && config('webpush.public_key') && config('webpush.private_key');
        }
        if ($this->option('require-backup')) {
            $path = config('operations.backup_success_file');
            $modified = is_file($path) && is_readable($path) ? filemtime($path) : false;
            $checks['successful backup in past 26 hours'] = $modified !== false && $modified <= time() && $modified >= time() - 26 * 3600;
        }
        foreach ($checks as $name => $passed) {
            $this->line(($passed ? 'PASS ' : 'FAIL ').$name);
        }
        $this->line('These checks do not test SMTP, WebSocket delivery, backups or device notifications.');

        return in_array(false, array_map('boolval', $checks), true) ? self::FAILURE : self::SUCCESS;
    }
}
