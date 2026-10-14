<#
.SYNOPSIS
    Run by BSU-Inventory-Setup.exe before it replaces the files of an existing installation.

.DESCRIPTION
    1. full backup (database, .env, certificates, uploads) with the CURRENT version's tools
    2. stops the web and database services and BSU's Laragon window, so files can be replaced

    The installer stops if this fails: an update never starts without a backup. The new
    version's configure.ps1 then runs the migrations and starts everything again.
#>
[CmdletBinding()]
param([Parameter(Mandatory)][string]$InstallDir)

. "$PSScriptRoot\common.ps1"

$paths = Get-BsuPaths $InstallDir
Start-BsuLog (Join-Path $paths.Logs ("update-{0}.log" -f (Get-Date -Format 'yyyyMMdd-HHmmss')))
try {
    if (-not (Test-Path $paths.InstallJson)) { Write-Log 'No existing installation; nothing to prepare.'; exit 0 }
    Write-Step "Preparing the update of $($paths.Install)"

    if (Get-BsuService $script:DbService) {
        $r = Invoke-Tool -Exe 'powershell.exe' -Arguments @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $PSScriptRoot 'backup.ps1'),
            '-InstallDir', $paths.Install, '-Reason', 'pre-update') -Label 'backup.ps1 -Reason pre-update'
        if ($r.Code -ne 0) { throw "The backup before the update failed, so nothing was changed. See $($paths.Logs)\backup.log." }
    } else {
        # Reinstall over data an earlier uninstall kept: the services are gone, the data
        # folder is not touched by the new files (only program folders are replaced)
        Write-Log 'No database service (reinstall over kept data): no backup needed; the data folder is not replaced.'
    }

    Stop-BsuServices
    foreach ($name in @('laragon', 'heidisql')) {
        Get-Process -Name $name -ErrorAction SilentlyContinue |
            Where-Object { $_.Path -and $_.Path.StartsWith($paths.Laragon, [StringComparison]::OrdinalIgnoreCase) } |
            Stop-Process -Force
    }
    Write-Ok 'Services stopped; ready for the new files'
    exit 0
} catch {
    Write-Log "FAILED: $($_.Exception.Message)" 'ERROR'
    exit 1
}
