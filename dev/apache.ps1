# Controls the local IONOS-like Apache in WSL (see dev/apache/).
# Usage: pwsh -File dev/apache.ps1 [setup|start|stop|restart|status|log]
param(
    [ValidateSet('setup', 'start', 'stop', 'restart', 'status', 'log')]
    [string]$Action = 'start',
    [string]$Distro = 'Ubuntu'
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$wslRoot = (wsl -d $Distro -- wslpath -a ($root -replace '\\', '/')).Trim()

function Invoke-Root([string]$cmd) {
    wsl -d $Distro -u root -- bash -c $cmd
    if ($LASTEXITCODE -ne 0) { throw "Fehler in WSL: $cmd" }
}

# Same runtime data as dev/serve.ps1; the uploads/.htaccess copy keeps blobs
# protected exactly like the real uploads/ folder
New-Item -ItemType Directory -Force (Join-Path $root '.devdata/uploads') | Out-Null
Copy-Item (Join-Path $root 'uploads/.htaccess') (Join-Path $root '.devdata/uploads/.htaccess') -Force
$configFile = Join-Path $root 'config.php'
if (-not (Test-Path $configFile)) {
    Set-Content -Path $configFile -Encoding utf8NoBOM -Value "<?php`nreturn require __DIR__ . '/dev/config.dev.php';"
    Write-Host 'config.php angelegt (lädt dev/config.dev.php).'
}

switch ($Action) {
    'setup'   { Invoke-Root "bash '$wslRoot/dev/apache/setup.sh'" }
    'start'   { Invoke-Root 'service apache2 start >/dev/null'; Write-Host 'Apache läuft: http://localhost:8081' }
    'stop'    { Invoke-Root 'service apache2 stop >/dev/null'; Write-Host 'Apache gestoppt.' }
    'restart' { Invoke-Root 'service apache2 restart >/dev/null'; Write-Host 'Apache neu gestartet: http://localhost:8081' }
    'status'  { Invoke-Root 'service apache2 status | head -5' }
    'log'     { Invoke-Root 'tail -n 50 /var/log/apache2/clipboard-dev-error.log' }
}
