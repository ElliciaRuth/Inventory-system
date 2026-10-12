<#
.SYNOPSIS
    Run by the BSU Inventory uninstaller with the choices the administrator made.

.DESCRIPTION
    Always: removes the two Windows services, the firewall rule and the daily backup task.
    The program files (BSU's own Laragon copy and the application) are then removed by the
    uninstaller itself. No other Laragon installation is ever touched.

    Only when chosen:
      -RemoveDatabase   the database files (inventory, users, transactions). The uninstaller
                        offers a backup first and never removes them silently.
      -RemoveUploads    barcode images, uploaded and imported files
      -RemoveConfig     backend\.env, the database login, the certificate authority (also
                        removed from this PC's trusted certificates). Only together with
                        the database: a kept database needs its login to be used again.
      -RemoveLogs       the logs folder
      -RemoveBackups    the backup folder chosen in the installer (full and in-app backups)
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$InstallDir,
    [switch]$Backup,
    [switch]$RemoveDatabase,
    [switch]$RemoveUploads,
    [switch]$RemoveConfig,
    [switch]$RemoveLogs,
    [switch]$RemoveBackups
)

. "$PSScriptRoot\common.ps1"

$paths = Get-BsuPaths $InstallDir
# Outside the install folder, which may be deleted
Start-BsuLog (Join-Path $env:TEMP ("bsu-inventory-uninstall-{0}.log" -f (Get-Date -Format 'yyyyMMdd-HHmmss')))
$settings = Read-InstallSettings $paths

function Remove-Path([string]$Path, [string]$What) {
    if ($Path -and (Test-Path $Path)) {
        Remove-Item $Path -Recurse -Force -ErrorAction SilentlyContinue
        if (Test-Path $Path) { Write-Warn "Could not remove all of $What ($Path)" } else { Write-Ok "Removed $What" }
    }
}

try {
    Write-Step "Uninstalling BSU Inventory from $($paths.Install)"
    if ($RemoveConfig -and -not $RemoveDatabase) { throw 'The configuration can only be removed together with the database.' }

    if ($Backup) {
        $r = Invoke-Tool -Exe 'powershell.exe' -Arguments @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $PSScriptRoot 'backup.ps1'),
            '-InstallDir', $paths.Install, '-Reason', 'pre-uninstall') -Label 'backup.ps1 -Reason pre-uninstall'
        if ($r.Code -ne 0) { Write-Log 'The backup failed; nothing was removed.' 'ERROR'; exit 2 }
    }

    # Services, firewall, scheduled task
    Stop-BsuServices
    foreach ($name in @($script:WebService, $script:DbService)) {
        if (Get-BsuService $name) {
            Invoke-Tool -Exe 'sc.exe' -Arguments @('delete', $name) -Quiet | Out-Null
            Write-Ok "Removed the Windows service $name"
        }
    }
    foreach ($name in @('laragon', 'heidisql', 'httpd', 'mariadbd', 'mysqld')) {
        Get-Process -Name $name -ErrorAction SilentlyContinue |
            Where-Object { $_.Path -and $_.Path.StartsWith($paths.Install, [StringComparison]::OrdinalIgnoreCase) } |
            Stop-Process -Force
    }
    Get-NetFirewallRule -DisplayName 'BSU Inventory (web)' -ErrorAction SilentlyContinue | Remove-NetFirewallRule
    Unregister-ScheduledTask -TaskName 'BSU Inventory Daily Backup' -Confirm:$false -ErrorAction SilentlyContinue
    Write-Ok 'Removed the firewall rule and the daily backup task'

    # Files the program created (not removed by the uninstaller's own file list)
    Remove-Path (Join-Path $paths.Etc 'httpd.conf') 'the Apache configuration'
    Remove-Path (Join-Path $paths.Etc 'bsu-inventory-vhosts.conf') 'the Apache site configuration'
    if ($paths.PhpIni) { Remove-Path $paths.PhpIni 'php.ini' }
    Remove-Path (Join-Path $paths.Laragon 'usr\laragon.ini') 'laragon.ini'
    Remove-Path $paths.Tmp 'temporary files'
    foreach ($d in @('cache', 'session', 'debugbar')) { Remove-Path (Join-Path $paths.Backend "writable\$d") "writable\$d" }
    Remove-Path (Join-Path $paths.Install 'config\connection-info.txt') 'connection-info.txt'

    if ($RemoveDatabase) { Remove-Path $paths.DataDir 'the database' }
    else { Write-Log "Kept the database: $($paths.DataDir)" }

    if ($RemoveUploads) {
        foreach ($d in @('writable\uploads', 'writable\imports', 'writable\backups', 'public\barcodes')) { Remove-Path (Join-Path $paths.Backend $d) $d }
    } else { Write-Log 'Kept the uploaded files and barcode images' }

    if ($RemoveConfig) {
        $ca = Join-Path $paths.Certs 'bsu-inventory-ca.crt'
        if (Test-Path $ca) {
            $cert = New-Object System.Security.Cryptography.X509Certificates.X509Certificate2 $ca
            Get-ChildItem Cert:\LocalMachine\Root | Where-Object { $_.Thumbprint -eq $cert.Thumbprint } | Remove-Item -Force
            Write-Ok 'This PC no longer trusts the BSU Inventory certificate authority'
        }
        Remove-Path $paths.Certs 'the certificates'
        Remove-Path (Join-Path $paths.Backend '.env') 'backend\.env'
        Remove-Path $paths.Config 'the configuration and database login'
    } else { Write-Log 'Kept the configuration (backend\.env, database login, certificates)' }

    if ($RemoveBackups -and $settings -and $settings.backupDir) { Remove-Path $settings.backupDir 'the backups' }
    elseif ($settings -and $settings.backupDir) { Write-Log "Kept the backups: $($settings.backupDir)" }

    if ($RemoveLogs) {
        Remove-Path $paths.Logs 'the logs'
        Remove-Path (Join-Path $paths.Backend 'writable\logs') 'the application logs'
    } else { Write-Log "Kept the logs: $($paths.Logs)" }

    # Folders the configuration created (the uninstaller only removes the ones it installed);
    # removed only when nothing that was kept is inside
    foreach ($d in @($paths.Etc, $paths.Config)) {
        if ((Test-Path $d) -and -not (Get-ChildItem -LiteralPath $d -Recurse -Force -File | Select-Object -First 1)) {
            Remove-Item -LiteralPath $d -Recurse -Force -ErrorAction SilentlyContinue
        }
    }

    exit 0
} catch {
    Write-Log "FAILED: $($_.Exception.Message)" 'ERROR'
    exit 1
}
