<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateWebPushKeys extends Command
{
    protected $signature = 'pharma:push-keys {--force : Replace existing VAPID keys}';
    protected $description = 'Generate and store VAPID keys for Pharma Strategies Web Push notifications.';

    public function handle(): int
    {
        if (!$this->option('force') && env('VAPID_PUBLIC_KEY') && env('VAPID_PRIVATE_KEY')) {
            $this->info('VAPID keys already exist. Use --force only when intentionally rotating them.');
            return self::SUCCESS;
        }

        $keys = VAPID::createVapidKeys();
        $envPath = base_path('.env');
        $env = file_exists($envPath) ? file_get_contents($envPath) : '';

        $replacements = [
            'VAPID_PUBLIC_KEY' => $keys['publicKey'],
            'VAPID_PRIVATE_KEY' => $keys['privateKey'],
            'WEB_PUSH_ENABLED' => 'true',
        ];

        foreach ($replacements as $key => $value) {
            $line = $key.'='.($key === 'VAPID_PRIVATE_KEY' || $key === 'VAPID_PUBLIC_KEY' ? '"'.$value.'"' : $value);
            if (preg_match('/^'.preg_quote($key, '/').'=.*/m', $env)) {
                $env = preg_replace('/^'.preg_quote($key, '/').'=.*/m', $line, $env);
            } else {
                $env .= "\n".$line;
            }
        }

        if (!preg_match('/^VAPID_SUBJECT=/m', $env)) {
            $env .= "\nVAPID_SUBJECT=https://localhost\n";
        } elseif (preg_match('/^VAPID_SUBJECT=\s*$/m', $env)) {
            $env = preg_replace('/^VAPID_SUBJECT=\s*$/m', 'VAPID_SUBJECT=https://localhost', $env);
        }

        file_put_contents($envPath, ltrim($env)."\n");
        $this->info('VAPID keys generated and stored in .env. Keep VAPID_PRIVATE_KEY secret.');
        $this->warn('For production, set VAPID_SUBJECT to your real HTTPS application URL and keep the same VAPID keys.');

        return self::SUCCESS;
    }
}
