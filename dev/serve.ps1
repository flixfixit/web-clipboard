# Starts the local dev server: php -S with dev/router.php and raised upload limits.
# Usage: pwsh -File dev/serve.ps1 [-Port 8080] [-BindHost 127.0.0.1]
param(
    [int]$Port = 8080,
    [string]$BindHost = '127.0.0.1'
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

# Freshly installed PHP (winget) is not on PATH in already running shells
$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php) {
    $php = Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages" -Recurse -Filter php.exe -ErrorAction SilentlyContinue |
        Select-Object -First 1 -ExpandProperty FullName
}
if (-not $php) { throw 'PHP nicht gefunden. Installieren mit: winget install PHP.PHP.8.3' }

New-Item -ItemType Directory -Force (Join-Path $root '.devdata/uploads') | Out-Null

$configFile = Join-Path $root 'config.php'
if (-not (Test-Path $configFile)) {
    Set-Content -Path $configFile -Encoding utf8NoBOM -Value "<?php`nreturn require __DIR__ . '/dev/config.dev.php';"
    Write-Host 'config.php angelegt (lädt dev/config.dev.php).'
}

Write-Host "Clipboard-Dev-Server: http://${BindHost}:$Port  (PHP: $php)"
# Chunks are 5 MB, the PHP default upload_max_filesize is only 2 MB
& $php `
    -d upload_max_filesize=16M `
    -d post_max_size=20M `
    -d display_errors=1 `
    -d error_reporting=E_ALL `
    -S "${BindHost}:$Port" -t $root (Join-Path $root 'dev/router.php')
