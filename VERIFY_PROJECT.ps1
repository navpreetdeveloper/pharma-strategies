$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$required = @('artisan','composer.json','package.json','routes\web.php','routes\channels.php','app\Models\Company.php','config\reverb.php','database\migrations','resources')
foreach($p in $required){
    if(-not(Test-Path (Join-Path $root $p))){ throw "Missing required path: $p" }
}
$route = Get-Content (Join-Path $root 'routes\web.php') -Raw
if($route -match "view\('welcome'\)"){ throw "Default Laravel welcome route detected." }
if($route -notmatch "view\('landing'\)"){ throw "Pharma Strategies landing route not detected." }
$company = Get-Content (Join-Path $root 'app\Models\Company.php') -Raw
if($company -notmatch "'uuid'"){ throw "Company uuid is not mass assignable." }
$reverb = Get-Content (Join-Path $root 'config\reverb.php') -Raw
if($reverb -notmatch "'scaling'"){ throw "Reverb scaling configuration is missing." }
$pkg = Get-Content (Join-Path $root 'package.json') -Raw
if($pkg -notmatch 'vite[^\r\n]*\^8\.0\.0'){ throw "Vite 8 requirement not found." }
if($pkg -notmatch 'laravel-vite-plugin[^\r\n]*\^3\.2\.0'){ throw "Laravel Vite plugin 3.2 requirement not found." }

$chat = Get-Content (Join-Path $root 'resources\views\chat\index.blade.php') -Raw
if($chat -notmatch 'global-message-menu'){ throw "Global viewport-safe message menu is missing." }
if($chat -notmatch 'data-message-action'){ throw "Delegated message actions are missing." }
if($chat -notmatch 'availableBelow'){ throw "Viewport-safe message menu positioning is missing." }
if($chat -notmatch "message.attachments") { throw "Explicit attachment response handling is missing." }
$test = Get-Content (Join-Path $root 'tests\Feature\MessageEditingAndAttachmentsTest.php') -Raw
if($test -notmatch 'uploaded_image_can_be_previewed') { throw "Private image preview regression test is missing." }
if($test -notmatch 'test_message_sends_immediate_private_realtime_notification') { throw "Real-time notification regression test is missing." }
$event = Get-Content (Join-Path $root 'app\Events\WorkplaceNotificationSent.php') -Raw
if($event -notmatch 'ShouldBroadcastNow') { throw "Immediate notification broadcast event is missing." }
$appjs = Get-Content (Join-Path $root 'resources\js\app.js') -Raw
if($appjs -notmatch "\.listen\('\.workplace\.notification'") { throw "Real-time user notification listener is missing." }
if($appjs -notmatch 'showInAppWorkplaceToast\(notification\)'){ throw "Slack-style live toast renderer is missing." }
if($appjs -notmatch 'showInAppWorkplaceToast\(notification\);\s*incrementLiveNotificationCount'){ throw "Live notification is not rendered before background refresh." }
if($appjs -notmatch 'initialiseRealtimeNotifications\(\);'){ throw "Independent real-time notification initialization is missing." }
if($appjs -notmatch 'message_preview'){ throw "Message preview payload is missing from live notification UI." }

Write-Host 'Pharma Strategies v3.6.8 source verification: PASS' -ForegroundColor Green


# v3.6.9 push subscription stale APP_KEY hardening
if (-not (Select-String -Path 'app/Http/Controllers/NotificationController.php' -Pattern "DB::table\('push_subscriptions'\)->where\('endpoint_hash'" -Quiet)) { throw 'Push subscribe stale-row hardening missing.' }
if (-not (Select-String -Path 'app/Services/WebPushService.php' -Pattern 'Discarding an invalid web push subscription' -Quiet)) { throw 'Invalid push subscription isolation missing.' }
if (-not (Select-String -Path 'tests/Feature/NotificationSubscriptionTest.php' -Pattern 'stale_encrypted_device_record' -Quiet)) { throw 'Push subscription regression test missing.' }
