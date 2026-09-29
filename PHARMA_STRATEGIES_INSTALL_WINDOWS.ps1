$ErrorActionPreference = 'Stop'

Write-Host ""
Write-Host "Pharma Strategies - Laravel 12 Windows Installer (v3.6)" -ForegroundColor Cyan
Write-Host "Creates a clean Laravel 12 app and merges the Pharma Strategies source, Web Push and PWA support correctly." -ForegroundColor Gray

if (-not (Get-Command composer -ErrorAction SilentlyContinue)) { throw "Composer is required. Install Composer first." }
if (-not (Get-Command npm -ErrorAction SilentlyContinue)) { throw "Node.js/npm is required. Install Node.js first." }
if (-not (Get-Command php -ErrorAction SilentlyContinue)) { throw "PHP is required and must be available on PATH." }

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$target = Join-Path $root 'PharmaStrategies'

if (Test-Path $target) {
    throw "PharmaStrategies already exists. Delete/rename it first, then run this installer again."
}

function Copy-DirectoryContents([string]$source, [string]$destination) {
    if (-not (Test-Path $source)) { return }
    if (-not (Test-Path $destination)) { New-Item -ItemType Directory -Path $destination -Force | Out-Null }
    Copy-Item -Path (Join-Path $source '*') -Destination $destination -Recurse -Force
}

Write-Host "[1/8] Creating a clean Laravel 12 application..." -ForegroundColor Yellow
composer create-project laravel/laravel:^12.0 $target
if ($LASTEXITCODE -ne 0) { throw "Laravel project creation failed." }

Write-Host "[2/8] Merging Pharma Strategies source files..." -ForegroundColor Yellow
Copy-DirectoryContents (Join-Path $root 'app') (Join-Path $target 'app')
Copy-DirectoryContents (Join-Path $root 'bootstrap') (Join-Path $target 'bootstrap')
Copy-DirectoryContents (Join-Path $root 'config') (Join-Path $target 'config')
Copy-DirectoryContents (Join-Path $root 'database') (Join-Path $target 'database')
Copy-DirectoryContents (Join-Path $root 'resources') (Join-Path $target 'resources')
Copy-DirectoryContents (Join-Path $root 'routes') (Join-Path $target 'routes')
Copy-DirectoryContents (Join-Path $root 'public') (Join-Path $target 'public')
Copy-DirectoryContents (Join-Path $root 'tests') (Join-Path $target 'tests')
Copy-Item (Join-Path $root 'vite.config.js') (Join-Path $target 'vite.config.js') -Force
Copy-Item (Join-Path $root 'package.json') (Join-Path $target 'package.json') -Force
Copy-Item (Join-Path $root '.env.example') (Join-Path $target '.env.example') -Force

Set-Location $target

Write-Host "[3/8] Installing Laravel packages..." -ForegroundColor Yellow
composer require livewire/livewire:^3.8.3 laravel/reverb:^1.12 minishlink/web-push:^11.0 --with-all-dependencies
if ($LASTEXITCODE -ne 0) { throw "Composer dependency installation failed." }

Write-Host "[4/8] Creating the local environment file..." -ForegroundColor Yellow
Copy-Item '.env.example' '.env' -Force
php artisan key:generate --force
if ($LASTEXITCODE -ne 0) { throw "Application key generation failed." }
php artisan pharma:push-keys
if ($LASTEXITCODE -ne 0) { throw "Web Push VAPID key generation failed." }

Write-Host "[5/8] Installing frontend packages..." -ForegroundColor Yellow
npm install
if ($LASTEXITCODE -ne 0) { throw "npm install failed." }

Write-Host "[6/8] Clearing and validating Laravel configuration..." -ForegroundColor Yellow
php artisan optimize:clear
if ($LASTEXITCODE -ne 0) { throw "Laravel cache clear failed." }
php artisan about --only=environment,cache,drivers 2>$null

Write-Host "[7/8] Verifying Pharma Strategies source and Reverb command..." -ForegroundColor Yellow
$route = Get-Content '.\routes\web.php' -Raw
if ($route -match "view\('welcome'\)") { throw "Default Laravel welcome route detected." }
if ($route -notmatch "view\('landing'\)") { throw "Pharma Strategies landing route verification failed." }
if (-not (Test-Path '.\app\Services\WebPushService.php')) { throw "Web Push service missing." }
if (-not (Test-Path '.\app\Http\Controllers\ProfileController.php')) { throw "Profile controller missing." }
if (-not (Select-String -Path '.\app\Http\Controllers\NotificationController.php' -Pattern "'endpoint' => \$endpoint" -Quiet)) { throw "Push endpoint persistence fix missing." }
if (-not (Test-Path '.\app\Events\MessageEdited.php')) { throw "Message edit event missing." }
if (-not (Test-Path '.\database\migrations\2026_09_25_000003_add_replies_forwards_presence.php')) { throw "Replies/forwarding/presence migration missing." }
if (-not (Select-String -Path '.\routes\web.php' -Pattern 'messages.forward' -Quiet)) { throw "Message forwarding route missing." }
if (-not (Test-Path '.\database\migrations\2026_09_25_000004_direct_chat_uniqueness_and_unsend.php')) { throw "Direct chat uniqueness/unsend migration missing." }
if (-not (Test-Path '.\app\Events\MessageDeleted.php')) { throw "Message deleted event missing." }
if (-not (Select-String -Path '.\routes\web.php' -Pattern 'messages.destroy' -Quiet)) { throw "Message unsend route missing." }

if (-not (Select-String -Path '.\routes\web.php' -Pattern 'presence.heartbeat' -Quiet)) { throw "Presence heartbeat route missing." }
if (-not (Test-Path '.\routes\channels.php')) { throw "Broadcast channel definitions missing." }
if (-not (Select-String -Path '.\routes\channels.php' -Pattern 'company.{company}.presence' -Quiet)) { throw "Company presence channel missing." }
if (-not (Test-Path '.\config\chat.php')) { throw "Chat configuration missing." }
if (-not (Select-String -Path '.\routes\web.php' -Pattern 'messages.edit' -Quiet)) { throw "Message edit route missing." }
if (-not (Select-String -Path '.\routes\web.php' -Pattern 'attachments.preview' -Quiet)) { throw "Attachment preview route missing." }
if (-not (Test-Path '.\public\sw.js')) { throw "Push service worker missing." }
if (-not (Test-Path '.\app\Models\Company.php')) { throw "Company model missing." }
if (-not (Select-String -Path '.\app\Models\Company.php' -Pattern "'uuid'" -Quiet)) { throw "Company UUID fillable field is missing." }
$commands = php artisan list | Out-String
if ($commands -notmatch 'reverb:start') { throw "Reverb command was not registered." }

Write-Host "[8/8] Checking frontend dependency versions..." -ForegroundColor Yellow
$pkg = Get-Content '.\package.json' -Raw
if ($pkg -notmatch 'vite.*\^8\.0\.0') { throw "Vite 8 requirement is missing." }
if ($pkg -notmatch 'laravel-vite-plugin.*\^3\.2\.0') { throw "Laravel Vite plugin requirement is missing." }

Write-Host ""
Write-Host "INSTALLATION COMPLETE" -ForegroundColor Green
Write-Host ""
Write-Host "Next:" -ForegroundColor Cyan
Write-Host "1. Edit .env only if your MySQL username/password differs from the defaults." -ForegroundColor White
Write-Host "2. Create an empty MySQL database named: pharma_strategies" -ForegroundColor White
Write-Host "3. Run: php artisan migrate" -ForegroundColor White
Write-Host "4. Run: npm run dev" -ForegroundColor White
Write-Host "5. In another terminal run: php artisan reverb:start" -ForegroundColor White
Write-Host "6. In another terminal run: php artisan serve" -ForegroundColor White
Write-Host "7. Open: http://127.0.0.1:8000" -ForegroundColor White
Write-Host "8. If push notifications are enabled, use the Notifications bell to opt in on each device." -ForegroundColor White
Write-Host "9. Message editing is server-enforced for the configured 10-minute window; unsend is server-enforced for the configured 5-minute window; attachments are stored privately." -ForegroundColor White
Write-Host "10. Replies, forwarding, unsend, unique direct chats and real-time online/offline presence are included in v3.6." -ForegroundColor White -ForegroundColor White
Write-Host ""
Write-Host "No sample/demo data is seeded by this project." -ForegroundColor Green
