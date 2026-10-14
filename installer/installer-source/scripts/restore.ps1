<#
.SYNOPSIS
    Restores a full backup made by backup.ps1 (the Server Manager's "Restore backup").

.DESCRIPTION
    1. checks the zip (manifest and SHA-256 of every file)
    2. makes a safety backup of the current state first (reason pre-restore)
    3. stops the web server, replaces the inventory_system database with the backup's,
       and puts back the uploaded files and barcode images
    4. runs the migrations (a backup from an older version is brought up to date)
    5. starts the web server and checks it answers

    backend\.env is NOT replaced: it holds this server's own database account. With
    -RestoreCertificates the backup's certificate authority replaces this server's, so
    devices that trusted the old server keep working (use it when moving to a new PC).
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$BackupFile,
    [string]$InstallDir = '',
    [switch]$RestoreCertificates
)

. "$PSScriptRoot\common.ps1"
Add-Type -AssemblyName System.IO.Compression, System.IO.Compression.FileSystem

function Invoke-Restore {
    $paths = Get-BsuPaths $InstallDir
    Start-BsuLog (Join-Path $paths.Logs ("restore-{0}.log" -f (Get-Date -Format 'yyyyMMdd-HHmmss')))
    $staging = $null
    try {
        $settings = Read-InstallSettings $paths
        if (-not $settings) { throw "Not a BSU Inventory installation: $($paths.InstallJson) is missing." }
        if (-not (Test-Path $BackupFile)) { throw "Backup file not found: $BackupFile" }
        Write-Step "Restoring $BackupFile"

        $staging = Join-Path $paths.SecretsDir ("restore-{0}" -f ([guid]::NewGuid().ToString('N')))
        [System.IO.Compression.ZipFile]::ExtractToDirectory($BackupFile, $staging)
        $manifestFile = Join-Path $staging 'manifest.json'
        if (-not (Test-Path $manifestFile)) { throw 'This is not a BSU Inventory server backup (manifest.json is missing).' }
        $manifest = Get-Content $manifestFile -Raw | ConvertFrom-Json
        if ($manifest.format -ne 'bsu-inventory-server-backup') { throw 'This is not a BSU Inventory server backup.' }
        $sha = [System.Security.Cryptography.SHA256]::Create()
        foreach ($f in $manifest.files) {
            $full = Join-Path $staging ($f.path.Replace('/', '\'))
            if (-not (Test-Path $full)) { throw "The backup is incomplete: $($f.path) is missing." }
            $stream = [System.IO.File]::OpenRead($full)
            try { $hash = ($sha.ComputeHash($stream) | ForEach-Object { $_.ToString('x2') }) -join '' } finally { $stream.Dispose() }
            if ($hash -ne $f.sha256) { throw "The backup is damaged: $($f.path) does not match its checksum." }
        }
        Write-Ok "Backup checked: made $($manifest.createdAt) on $($manifest.computer), version $($manifest.appVersion)"

        # Safety net: the current state, in case this restore was a mistake
        Write-Step 'Safety backup of the current data'
        $r = Invoke-Tool -Exe 'powershell.exe' -Arguments @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $PSScriptRoot 'backup.ps1'),
            '-InstallDir', $paths.Install, '-Reason', 'pre-restore') -Label 'backup.ps1 -Reason pre-restore'
        if ($r.Code -ne 0) { throw 'The safety backup failed, so nothing was restored.' }

        Write-Step 'Replacing the database'
        Stop-Service -Name $script:WebService -Force -ErrorAction SilentlyContinue
        Wait-ServiceStatus $script:WebService 'Stopped' 60 | Out-Null
        $dbService = Get-BsuService $script:DbService
        if ($dbService -and $dbService.Status -ne 'Running') { Start-Service -Name $script:DbService }
        if (-not (Wait-DbAlive $paths 60)) { throw 'The database does not answer.' }
        $dump = (Join-Path $staging 'database.sql').Replace('\', '/')
        # The dump recreates the database; the application account's rights are kept (they are
        # granted on the database name)
        $r = Invoke-DbRoot $paths "DROP DATABASE IF EXISTS ``$($script:DbName)``;`nsource $dump" 'Importing database.sql'
        if ($r.Code -ne 0) { throw "Importing the database failed: $($r.Output). The safety backup can be restored." }
        Write-Ok 'Database restored'

        Write-Step 'Uploaded files'
        $put = {
            param($from, $to)
            if (Test-Path $from) {
                if (-not (Test-Path $to)) { New-Item -ItemType Directory -Force -Path $to | Out-Null }
                Copy-Item (Join-Path $from '*') $to -Recurse -Force
            }
        }
        & $put (Join-Path $staging 'files\uploads') (Join-Path $paths.Backend 'writable\uploads')
        & $put (Join-Path $staging 'files\barcodes') (Join-Path $paths.Backend 'public\barcodes')
        $cfg = Join-Path $staging 'files\backup_config.json'
        if (Test-Path $cfg) { Copy-Item $cfg (Join-Path $paths.Backend 'writable\backups\backup_config.json') -Force }
        Write-Ok 'Uploaded files and barcode images restored'

        if ($RestoreCertificates -and (Test-Path (Join-Path $staging 'certs\bsu-inventory-ca.key'))) {
            Write-Step 'Certificate authority'
            foreach ($f in @('bsu-inventory-ca.key', 'bsu-inventory-ca.crt', 'ca-hostname.txt')) {
                $from = Join-Path $staging "certs\$f"
                if (Test-Path $from) { Copy-Item $from (Join-Path $paths.Certs $f) -Force }
            }
            # A new server certificate is issued from it by Repair below
            Remove-Item (Join-Path $paths.Certs 'server-names.txt') -Force -ErrorAction SilentlyContinue
            Write-Ok 'Certificate authority restored'
        }

        # Migrations, permissions, certificate, services and health check
        Write-Step 'Repair (migrations, permissions, services)'
        $r = Invoke-Tool -Exe 'powershell.exe' -Arguments @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $PSScriptRoot 'configure.ps1'),
            '-InstallDir', $paths.Install, '-Mode', 'Repair') -Label 'configure.ps1 -Mode Repair'
        if ($r.Code -ne 0) { throw "The restore finished, but the repair step failed. See the newest configure-repair log in $($paths.Logs)." }
        Write-Log 'RESULT: OK' 'OK'
        return 0
    } catch {
        Write-Log "RESULT: FAILED - $($_.Exception.Message)" 'ERROR'
        try { Start-Service -Name $script:WebService -ErrorAction SilentlyContinue } catch { }
        return 1
    } finally {
        if ($staging -and (Test-Path $staging)) { Remove-Item $staging -Recurse -Force -ErrorAction SilentlyContinue }
    }
}

exit ([int](Invoke-Restore | Select-Object -Last 1))
