param(
    [string]$PhpBinary = 'php',
    [ValidateRange(1, 65535)][int]$Port = 8000,
    [string]$HostAddress = '0.0.0.0'
)

$ErrorActionPreference = 'Stop'
$workspacePath = Split-Path -Parent $PSScriptRoot
$publicPath = Join-Path $workspacePath 'public'
$routerPath = Join-Path $workspacePath 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'
if (-not (Test-Path -LiteralPath $routerPath)) { throw 'Install Composer dependencies first.' }
$artisanPath = Join-Path $workspacePath 'artisan'
$applicationEnvironment = & $PhpBinary -d opcache.enable_cli=0 $artisanPath env --no-ansi
if ($LASTEXITCODE -ne 0 -or ($applicationEnvironment -join ' ') -notmatch '\blocal\b') {
    throw 'This development server requires APP_ENV=local with no production configuration cache.'
}
& $PhpBinary -d opcache.enable_cli=0 $artisanPath route:clear --no-ansi
if ($LASTEXITCODE -ne 0) { throw 'Could not clear the local route cache.' }

# The Windows development server otherwise recompiles the framework for each request.
# Check timestamps on every request so local code changes remain visible.
$serverArguments = @(
    '-d', 'opcache.enable_cli=1',
    # Windows shares OPcache by user/SAPI/cache ID; isolate this server instance.
    '-d', "opcache.cache_id=pharma-web-$PID-$Port",
    '-d', 'opcache.memory_consumption=192',
    '-d', 'opcache.max_accelerated_files=20000',
    '-d', 'opcache.validate_timestamps=1',
    '-d', 'opcache.revalidate_freq=0',
    '-d', 'upload_max_filesize=128M',
    '-d', 'post_max_size=128M',
    '-d', 'max_file_uploads=20',
    '-d', 'memory_limit=256M',
    '-S', "${HostAddress}:$Port", '-t', $publicPath, $routerPath
)
# Laravel's development router resolves index.php relative to its working directory.
Push-Location -LiteralPath $publicPath
try {
    & $PhpBinary @serverArguments
    if ($LASTEXITCODE -ne 0) { throw "Local server exited with code $LASTEXITCODE." }
} finally {
    Pop-Location
}
