<#
.SYNOPSIS
    Sets the release version everywhere it appears, and optionally tags it and builds the installer.

.DESCRIPTION
    The version lives in one place, frontend\package.json. The installer (BSU-Inventory-Setup.exe),
    the app's menu and installed servers read it from there. This script raises it and brings the
    README in line:

        - frontend\package.json and package-lock.json  ("version")
        - README.md: the version and release badges, "Current Version", the footer, and the
          changelog's "Unreleased" row, which becomes the new version (marked Current)

    Write the release notes first, as an "| **Unreleased** | ... |" row at the top of the
    changelog (README.md, section 9). Without it the script stops.

    Versions are MAJOR.MINOR.PATCH: PATCH for fixes only, MINOR for new features or changed
    screens, MAJOR for changes that need data moved by hand. A version can only go up (the
    installer refuses to install an older version over a newer one).

    Typical release:

        git commit -am "..."                          # the work itself
        .\release.ps1 -Version 2.4.0 -Tag -Build      # version, commit + tag, installer

.PARAMETER Version
    The new version, e.g. 2.4.0. Must be higher than the current one.
.PARAMETER Tag
    Commit the version change ("Release 2.4.0") and tag it (v2.4.0). Everything else must be
    committed first, so the tag is exactly the released code. Nothing is pushed.
.PARAMETER Build
    Build installer\BSU-Inventory-Setup.exe afterwards (installer\installer-source\build.ps1).
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$Version,
    [switch]$Tag,
    [switch]$Build
)

$ErrorActionPreference = 'Stop'
$Repo     = $PSScriptRoot
$Frontend = Join-Path $Repo 'frontend'
$Readme   = Join-Path $Repo 'README.md'
$Utf8     = New-Object System.Text.UTF8Encoding($false)

function Step([string]$t) { Write-Host "==> $t" -ForegroundColor Cyan }
function Ok([string]$t)   { Write-Host "    [OK] $t" -ForegroundColor Green }

function Invoke-Git([string[]]$Arguments) {
    # Git writes progress to stderr; Windows PowerShell would treat that as an error
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $out = & git -C $Repo @Arguments 2>&1 | ForEach-Object { "$_" }
    $code = $LASTEXITCODE
    $ErrorActionPreference = $previous
    if ($code -ne 0) { throw "git $($Arguments -join ' ') failed: $out" }
    return $out
}

# ---------------------------------------------------------------------------
# Checks first: nothing is changed unless the whole release can go ahead
if ($Version -notmatch '^\d+\.\d+\.\d+$') {
    throw "Version must look like 2.4.0 (MAJOR.MINOR.PATCH), not '$Version'."
}
$current = (Get-Content (Join-Path $Frontend 'package.json') -Raw | ConvertFrom-Json).version
if ([version]$Version -le [version]$current) {
    throw "The version can only go up: the current version is $current."
}

$readmeText = [System.IO.File]::ReadAllText($Readme, $Utf8)
# Only a changelog table row (a line that starts with it), not the instructions that mention it
$unreleasedRow = '(?m)^\| \*\*Unreleased\*\* \| [^|]+\|'
if ($readmeText -notmatch $unreleasedRow) {
    throw 'README.md has no "| **Unreleased** |" row in the changelog (section 9). Write the release notes there first.'
}

if ($Tag) {
    $dirty = @(Invoke-Git @('status', '--porcelain', '--untracked-files=no')) | Where-Object { $_ }
    if ($dirty) {
        throw "Commit (or stash) your changes first, so the v$Version tag is exactly the released code:`n$($dirty -join "`n")"
    }
    if (Invoke-Git @('tag', '--list', "v$Version")) {
        throw "Tag v$Version already exists."
    }
}

# ---------------------------------------------------------------------------
Step "Version $current -> $Version"

Push-Location $Frontend
try {
    # Updates package.json and package-lock.json together, keeping their formatting
    & npm.cmd version $Version --no-git-tag-version | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'npm version failed.' }
} finally { Pop-Location }
Ok 'frontend\package.json, package-lock.json'

$month    = (Get-Date).ToString('MMMM yyyy', [System.Globalization.CultureInfo]'en-US')
$monthUrl = $month -replace ' ', '%20'

$replacements = @(
    @('version-\d+\.\d+\.\d+-blue', "version-$Version-blue"),
    @('release-[A-Za-z]+%20\d{4}-green', "release-$monthUrl-green"),
    @('(\*\*Current Version:\*\* `v)\d+\.\d+\.\d+(` \(Production Release . )[^)]+\)', "`${1}$Version`${2}$month)"),
    @('(Monitoring System v)\d+\.\d+\.\d+( .)', "`${1}$Version`${2}"),
    # The previous release is no longer the current one
    @(' \*\(Current\)\*', ''),
    @($unreleasedRow, "| **``v$Version``** *(Current)* | $month |")
)
foreach ($r in $replacements) {
    if ($readmeText -notmatch $r[0]) { Write-Warning "README.md: nothing matched $($r[0])" }
    $readmeText = [regex]::Replace($readmeText, $r[0], $r[1])
}
[System.IO.File]::WriteAllText($Readme, $readmeText, $Utf8)
Ok 'README.md (badges, current version, changelog, footer)'

if ($Tag) {
    Step "Commit and tag v$Version"
    Invoke-Git @('add', '--', 'frontend/package.json', 'frontend/package-lock.json', 'README.md') | Out-Null
    Invoke-Git @('commit', '-m', "Release $Version") | Out-Null
    Invoke-Git @('tag', '-a', "v$Version", '-m', "Release $Version") | Out-Null
    Ok "Committed and tagged v$Version (not pushed: git push; git push origin v$Version)"
}

if ($Build) {
    Step 'Building the installer'
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $Repo 'installer\installer-source\build.ps1')
    if ($LASTEXITCODE -ne 0) { throw 'The installer build failed (see above).' }
}

Write-Host ''
Write-Host "Version $Version is set." -ForegroundColor Green
