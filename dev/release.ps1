# Prepares a release on main: turns "## Unreleased" in CHANGELOG.md into the
# version section, commits and creates the tag. Pushing is left to you; the
# tag push starts the GitHub release workflow (.github/workflows/release.yml).
# Usage: pwsh -File dev/release.ps1 -Version 1.0.0
param(
    [Parameter(Mandatory)][ValidatePattern('^\d+\.\d+\.\d+$')][string]$Version
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Push-Location $root
try {
    $tag = "v$Version"
    if ((git branch --show-current) -ne 'main') { throw 'Releases werden von main aus erstellt.' }
    if (git status --porcelain) { throw 'Es gibt uncommittete Änderungen.' }
    git fetch -q origin
    if ((git rev-parse HEAD) -ne (git rev-parse origin/main)) { throw 'main ist nicht auf dem Stand von origin/main (git pull?).' }
    if (git tag -l $tag) { throw "Tag $tag existiert bereits." }

    $changelog = Join-Path $root 'CHANGELOG.md'
    $text = [IO.File]::ReadAllText($changelog)
    $match = [regex]::Match($text, '(?ms)^## Unreleased\r?\n(.*?)(?=^## |\z)')
    if (-not $match.Success -or -not $match.Groups[1].Value.Trim()) {
        throw 'CHANGELOG.md hat keinen Abschnitt "## Unreleased" mit Einträgen.'
    }
    $date = Get-Date -Format 'yyyy-MM-dd'
    $text = $text.Remove($match.Index, '## Unreleased'.Length).Insert($match.Index, "## Unreleased`n`n## $Version - $date")
    [IO.File]::WriteAllText($changelog, $text, [Text.UTF8Encoding]::new($false))

    git add CHANGELOG.md
    git commit -q -m "Release $tag"
    git tag -a $tag -m "Clipboard $tag"
    Write-Host "Release $tag vorbereitet (Commit und Tag lokal)."
    Write-Host 'Veröffentlichen mit:'
    Write-Host "  git push origin main $tag"
} finally {
    Pop-Location
}
