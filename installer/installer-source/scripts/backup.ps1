<#
.SYNOPSIS
    Full backup of the BSU Inventory server: database, configuration and uploaded files.

.DESCRIPTION
    Writes one zip to <backup folder>\full (chosen in the installer; never inside the install
    folder), readable by Administrators only:

      database.sql            complete dump of the inventory_system database (mariadb-dump)
      config\backend.env      backend\.env (database account, encryption key)
      config\install.json     ports, backup settings, component versions
      certs\                  the local certificate authority, so phones and PCs that trust
                              this server keep working after a restore on a new PC
      files\uploads\          backend\writable\uploads
      files\barcodes\         backend\public\barcodes (batch barcode images)
      files\backup_config.json  the in-app backup settings
      manifest.json           what is inside, with a SHA-256 checksum of every file

    Run by the daily task (as SYSTEM), by "Back up now" in the Server Manager, and by the
    installer before an update or an uninstall. Scheduled backups keep the newest N
    (install.json backupKeep, default 30); other backups are never deleted automatically.

    The application's own per-office backups (Office Settings > Backups) are separate and
    keep working as before.
.PARAMETER Reason
    scheduled, manual, pre-update, pre-uninstall or pre-restore (part of the file name).
.PARAMETER Destination
    Another folder for this one backup (for example a USB drive).
#>
[CmdletBinding()]
param(
    [string]$InstallDir = '',
    [ValidateSet('scheduled', 'manual', 'pre-update', 'pre-uninstall', 'pre-restore')][string]$Reason = 'manual',
    [string]$Destination = ''
)

. "$PSScriptRoot\common.ps1"
Add-Type -AssemblyName System.IO.Compression, System.IO.Compression.FileSystem

function Invoke-Backup {
    $paths = Get-BsuPaths $InstallDir
    Start-BsuLog (Join-Path $paths.Logs 'backup.log')
    $staging = $null
    try {
        $settings = Read-InstallSettings $paths
        if (-not $settings) { throw "Not a BSU Inventory installation: $($paths.InstallJson) is missing." }
        if (-not (Test-Path $paths.RootCnf)) { throw "The database login ($($paths.RootCnf)) is missing." }
        $target = $Destination
        if (-not $target) { $target = Join-Path $settings.backupDir 'full' }
        $target = [System.IO.Path]::GetFullPath($target)
        if ($target.StartsWith($paths.Install + '\', [StringComparison]::OrdinalIgnoreCase)) {
            throw "Backups must not be stored inside the install folder ($($paths.Install)). Choose another folder or drive."
        }
        New-Item -ItemType Directory -Force -Path $target | Out-Null
        Write-Step "Backup ($Reason) to $target"

        # The database must be running for a consistent dump
        $db = Get-BsuService $script:DbService
        if ($db -and $db.Status -ne 'Running') {
            Start-Service -Name $script:DbService
            if (-not (Wait-ServiceStatus $script:DbService 'Running' 60)) { throw 'The database service did not start.' }
        }
        if (-not (Wait-DbAlive $paths 60)) { throw "The database does not answer (is the $($script:DbService) service running?)." }

        # Staging in the Administrators-only folder: the dump holds password hashes
        $staging = Join-Path $paths.SecretsDir ("backup-{0}" -f ([guid]::NewGuid().ToString('N')))
        New-Item -ItemType Directory -Force -Path $staging | Out-Null

        $dump = Join-Path $staging 'database.sql'
        $r = Invoke-Tool -Exe $paths.MariadbDump -Arguments @("--defaults-extra-file=$($paths.RootCnf)", '--single-transaction', '--quick',
            '--routines', '--triggers', '--events', '--hex-blob', '--default-character-set=utf8mb4', '--databases', $script:DbName,
            "--result-file=$dump") -Label 'mariadb-dump'
        if ($r.Code -ne 0 -or -not (Test-Path $dump)) { throw "The database dump failed: $($r.Output)" }
        $tail = Get-Content $dump -Tail 3 -Encoding UTF8
        if (($tail -join "`n") -notmatch 'Dump completed') { throw 'The database dump is incomplete.' }
        Write-Ok ("Database dumped ({0:N1} MB)" -f ((Get-Item $dump).Length / 1MB))

        $copy = {
            param($from, $to)
            if (Test-Path $from) {
                $dir = Split-Path -Parent $to
                if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
                Copy-Item $from $to -Recurse -Force
            }
        }
        & $copy (Join-Path $paths.Backend '.env') (Join-Path $staging 'config\backend.env')
        & $copy $paths.InstallJson (Join-Path $staging 'config\install.json')
        foreach ($f in @('bsu-inventory-ca.key', 'bsu-inventory-ca.crt', 'ca-hostname.txt')) {
            & $copy (Join-Path $paths.Certs $f) (Join-Path $staging "certs\$f")
        }
        & $copy (Join-Path $paths.Backend 'writable\uploads') (Join-Path $staging 'files\uploads')
        & $copy (Join-Path $paths.Backend 'public\barcodes') (Join-Path $staging 'files\barcodes')
        & $copy (Join-Path $paths.Backend 'writable\backups\backup_config.json') (Join-Path $staging 'files\backup_config.json')

        $files = @()
        $sha = [System.Security.Cryptography.SHA256]::Create()
        foreach ($f in Get-ChildItem $staging -Recurse -File) {
            $stream = [System.IO.File]::OpenRead($f.FullName)
            try { $hash = ($sha.ComputeHash($stream) | ForEach-Object { $_.ToString('x2') }) -join '' } finally { $stream.Dispose() }
            $files += [ordered]@{ path = $f.FullName.Substring($staging.Length + 1).Replace('\', '/'); size = $f.Length; sha256 = $hash }
        }
        $manifest = [ordered]@{
            format     = 'bsu-inventory-server-backup'
            version    = 1
            createdAt  = (Get-Date).ToString('s')
            reason     = $Reason
            computer   = $env:COMPUTERNAME
            appVersion = $settings.version
            database   = $script:DbName
            components = $paths.Components
            files      = $files
        }
        Write-Text (Join-Path $staging 'manifest.json') ($manifest | ConvertTo-Json -Depth 5)

        $name = "BSU-Inventory-Backup-{0}-{1}.zip" -f (Get-Date -Format 'yyyyMMdd-HHmmss'), $Reason
        $final = Join-Path $target $name
        $partial = "$final.partial"
        Remove-Item $partial -Force -ErrorAction SilentlyContinue
        # Entries named with "/" (CreateFromDirectory on .NET Framework writes "\", which other
        # zip tools may not understand)
        $zip = [System.IO.Compression.ZipFile]::Open($partial, [System.IO.Compression.ZipArchiveMode]::Create)
        try {
            foreach ($f in Get-ChildItem $staging -Recurse -File) {
                $entry = $f.FullName.Substring($staging.Length + 1).Replace('\', '/')
                [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $f.FullName, $entry, [System.IO.Compression.CompressionLevel]::Optimal)
            }
        } finally { $zip.Dispose() }
        Move-Item $partial $final -Force
        Write-Ok ("Backup written: {0} ({1:N1} MB)" -f $final, ((Get-Item $final).Length / 1MB))

        if ($Reason -eq 'scheduled' -and -not $Destination) {
            $keep = 30
            if ($settings.backupKeep) { $keep = [int]$settings.backupKeep }
            $old = @(Get-ChildItem $target -Filter 'BSU-Inventory-Backup-*-scheduled.zip' | Sort-Object Name -Descending | Select-Object -Skip $keep)
            foreach ($o in $old) { Remove-Item $o.FullName -Force; Write-Log "Removed old scheduled backup $($o.Name)" }
        }
        Write-Host "BACKUP: $final"
        return 0
    } catch {
        Write-Log "Backup FAILED: $($_.Exception.Message)" 'ERROR'
        return 1
    } finally {
        if ($staging -and (Test-Path $staging)) { Remove-Item $staging -Recurse -Force -ErrorAction SilentlyContinue }
    }
}

exit ([int](Invoke-Backup | Select-Object -Last 1))
