# Builds the deployable release ZIP locally, exactly like the GitHub release
# workflow: git archive of a committed ref, filtered by export-ignore in
# .gitattributes, with the version written into index.php (export-subst).
# Usage: pwsh -File dev/build-release.ps1 [-Ref v1.0.0] [-OutDir dist]
param(
    [string]$Ref = 'HEAD',
    [string]$OutDir = 'dist'
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Push-Location $root
try {
    git rev-parse --verify --quiet "$Ref^{commit}" | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Unbekannte Git-Referenz: $Ref" }
    if ($Ref -eq 'HEAD' -and (git status --porcelain)) {
        Write-Warning 'Es gibt uncommittete Änderungen. Das ZIP enthält nur den committeten Stand.'
    }

    $version = (git describe --tags --always $Ref).Trim()
    New-Item -ItemType Directory -Force $OutDir | Out-Null
    $zip = Join-Path (Resolve-Path $OutDir) "clipboard-$version.zip"
    git archive --format=zip --prefix=clipboard/ -o $zip $Ref
    if ($LASTEXITCODE -ne 0) { throw 'git archive fehlgeschlagen.' }

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [System.IO.Compression.ZipFile]::OpenRead($zip)
    try {
        Write-Host "Release gebaut: $zip"
        $archive.Entries | Where-Object { $_.Length -gt 0 } | ForEach-Object {
            Write-Host ('  {0,-28} {1,8} B' -f $_.FullName, $_.Length)
        }
    } finally { $archive.Dispose() }
} finally {
    Pop-Location
}
