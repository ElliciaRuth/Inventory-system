<#
.SYNOPSIS
    Configures (or repairs) the BSU Inventory server on BSU's own Laragon copy.

.DESCRIPTION
    Run by BSU-Inventory-Setup.exe after it copied the files (new install and update), and by
    "Repair" in the Server Manager (for example after the PC got a new IP address). Safe to
    run again at any time; it never deletes inventory data. Needs no internet.

      1. checks the bundled Laragon components and the ports
      2. locks the install folder down (Administrators change it, everyone else only reads)
      3. writes php.ini (only the extensions the system needs; errors logged, never shown)
      4. MariaDB: creates the data folder once, runs it as a Windows service reachable from
         this PC only, creates the database and the application's own account (not root)
      5. backend\.env (production) with a generated database password and encryption key
      6. runs the database migrations and the reference-data seeder (no demo accounts, no
         inventory); on a new install, creates the administrator account chosen in the installer
      7. HTTPS: a local certificate authority (once) and a certificate for this PC's addresses
      8. Apache as a Windows service; firewall opened for the web ports on private networks
      9. daily backup task; health check

    The log (logs\configure-*.log) never contains passwords or keys.
#>
[CmdletBinding()]
param(
    [string]$InstallDir = '',
    [ValidateSet('Install', 'Upgrade', 'Repair')][string]$Mode = 'Repair',
    [string]$Version = '',
    [int]$HttpPort = 0,
    [int]$HttpsPort = 0,
    [int]$LanAccess = -1,
    [string]$BackupDir = '',
    [int]$DailyBackup = -1,
    [string]$BackupTime = '',
    [string]$AdminFile = '',
    [switch]$DefineOnly
)

. "$PSScriptRoot\common.ps1"

# ===========================================================================
#  Steps
# ===========================================================================

function Assert-Administrator {
    $id = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
    if (-not $id.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Administrator rights are needed (Windows services, firewall, certificates).'
    }
}

function Resolve-Settings([object]$Paths) {
    $old = Read-InstallSettings $Paths
    $s = [ordered]@{
        version     = ''
        installedAt = (Get-Date).ToString('s')
        updatedAt   = (Get-Date).ToString('s')
        httpPort    = 80
        httpsPort   = 443
        dbPort      = 0
        lanAccess   = $true
        backupDir   = ''
        dailyBackup = $true
        backupTime  = '17:30'
        backupKeep  = 30
        components  = $Paths.Components
    }
    if ($old) {
        foreach ($k in @('installedAt', 'httpPort', 'httpsPort', 'dbPort', 'lanAccess', 'backupDir', 'dailyBackup', 'backupTime', 'backupKeep', 'version')) {
            if ($null -ne $old.$k -and "$($old.$k)" -ne '') { $s[$k] = $old.$k }
        }
        $s.previousComponents = $old.components
    }
    if ($Version)          { $s.version = $Version }
    if ($HttpPort -gt 0)   { $s.httpPort = $HttpPort }
    if ($HttpsPort -gt 0)  { $s.httpsPort = $HttpsPort }
    if ($LanAccess -ge 0)  { $s.lanAccess = [bool]$LanAccess }
    if ($BackupDir)        { $s.backupDir = $BackupDir.TrimEnd('\') }
    if ($DailyBackup -ge 0){ $s.dailyBackup = [bool]$DailyBackup }
    if ($BackupTime)       { $s.backupTime = $BackupTime }
    if (-not $s.backupDir) { $s.backupDir = 'C:\BSU Inventory Backups' }
    return $s
}

function Save-Settings([object]$Paths, $Settings) {
    $copy = [ordered]@{}
    foreach ($k in $Settings.Keys) { if ($k -ne 'previousComponents') { $copy[$k] = $Settings[$k] } }
    Write-Text $Paths.InstallJson ($copy | ConvertTo-Json -Depth 5)
}

function Test-Components([object]$Paths) {
    Write-Step 'Checking the bundled Laragon components'
    if (-not $Paths.Components) { throw "The Laragon component list is missing ($($Paths.Laragon)\usr\bsu-components.json). Run the installer again." }
    foreach ($f in @($Paths.Httpd, $Paths.OpenSsl, $Paths.PhpExe, $Paths.Mariadbd, $Paths.MariadbCli, $Paths.MariadbDump,
                     (Join-Path $Paths.Backend 'spark'), (Join-Path $Paths.Backend 'vendor\autoload.php'), (Join-Path $Paths.Dist 'index.html'))) {
        if (-not (Test-Path $f)) { throw "A required file is missing: $f. Run the installer again." }
    }
    Write-Ok "Laragon $($Paths.Components.laragon) at $($Paths.Laragon)"
    Write-Ok "Apache $($Paths.Components.apacheVersion), PHP $($Paths.Components.phpVersion), MariaDB $($Paths.Components.mariadbVersion)"

    # Other Laragon installations are reported only; they are never changed
    $others = @('C:\laragon', 'D:\laragon', 'E:\laragon') | Where-Object { (Test-Path "$_\laragon.exe") -and ($_ -ine $Paths.Laragon) }
    foreach ($o in $others) { Write-Log "Another Laragon is installed at $o. It is not used or changed by BSU Inventory." }
}

function Select-Ports([object]$Paths, $Settings) {
    Write-Step 'Checking the ports'
    foreach ($port in @([int]$Settings.httpPort, [int]$Settings.httpsPort)) {
        if (-not (Test-PortAvailable $port $Paths.Httpd)) {
            $o = Get-PortOwner $port
            throw "Port $port is used by another program ($($o.Name), $($o.Path)). Stop it (or choose other ports in the installer) and try again."
        }
    }
    Write-Ok "Web ports $($Settings.httpPort) (HTTP) and $($Settings.httpsPort) (HTTPS) are free"

    if ([int]$Settings.dbPort -le 0) {
        # A database port nobody else uses (XAMPP or another Laragon may hold 3306)
        foreach ($candidate in 3306..3320) {
            if (Test-PortAvailable $candidate $Paths.Mariadbd) { $Settings.dbPort = $candidate; break }
        }
        if ([int]$Settings.dbPort -le 0) { throw 'No free database port between 3306 and 3320.' }
    } elseif (-not (Test-PortAvailable ([int]$Settings.dbPort) $Paths.Mariadbd)) {
        $o = Get-PortOwner ([int]$Settings.dbPort)
        throw "The database port $($Settings.dbPort) is used by another program ($($o.Name)). Stop it and try again."
    }
    Write-Ok "Database port $($Settings.dbPort) (this PC only; not opened in the firewall)"
}

function Set-InstallPermissions([object]$Paths, [bool]$Full) {
    Write-Step 'File permissions'
    foreach ($d in @($Paths.Config, $Paths.SecretsDir, $Paths.Logs, (Join-Path $Paths.Logs 'apache'), (Join-Path $Paths.Logs 'php'),
                     (Join-Path $Paths.Logs 'mariadb'), $Paths.Tmp, $Paths.Etc, $Paths.Certs)) {
        if (-not (Test-Path $d)) { New-Item -ItemType Directory -Force -Path $d | Out-Null }
    }
    # The install folder: Administrators and SYSTEM change it, everyone else reads. Nothing
    # inherited from the drive (C:\ lets every signed-in user modify new folders).
    Invoke-Icacls @($Paths.Install, '/inheritance:r', '/grant:r', "$($script:SidAdmins):(OI)(CI)F", "$($script:SidSystem):(OI)(CI)F", "$($script:SidUsers):(OI)(CI)RX", '/C', '/Q')
    if ($Full) {
        Write-Log 'Resetting permissions below the install folder (first install; this takes a moment)'
        Invoke-Icacls @("$($Paths.Install)\*", '/reset', '/T', '/C', '/Q')
    }
    Set-AdminOnlyAcl $Paths.SecretsDir
    Write-Ok 'Install folder: Administrators can change it, users can only read it'
}

function Grant-ServiceAccess([object]$Paths) {
    $web = $script:WebSid
    $db  = $script:DbSid
    # Read and run everything (the service SIDs exist once the services were created)
    Grant-Access $Paths.Install $web 'RX'
    Grant-Access $Paths.Install $db 'RX'
    # Write only where the app and the servers write
    foreach ($d in @('writable', 'writable\cache', 'writable\logs', 'writable\session', 'writable\uploads', 'writable\imports', 'writable\backups', 'writable\debugbar', 'public\barcodes')) {
        Grant-Access (Join-Path $Paths.Backend $d) $web 'M'
    }
    Grant-Access (Join-Path $Paths.Logs 'apache') $web 'M'
    Grant-Access (Join-Path $Paths.Logs 'php') $web 'M'
    Grant-Access $Paths.Tmp $web 'M'
    Grant-Access $Paths.DataDir $db 'M'
    Grant-Access (Join-Path $Paths.Logs 'mariadb') $db 'M'
    Write-Ok 'The web and database services can write only to their own folders'
}

function Write-LaragonIni([object]$Paths, $Settings) {
    Write-Step 'Laragon'
    $c = $Paths.Components
    # Laragon's own Start All stays off: Apache and MariaDB run as Windows services instead.
    # Its window remains available for its tools (HeidiSQL, terminal).
    $ini = @"
[preferences]
AutoStart=0
RunAtStartup=0
AutoVirtualHosts=0
AutoCreateDatabase=0
MinimizeWhenRun=0

[php]
Version=$($c.php)

[apache]
Version=$($c.apache)
Port=$($Settings.httpPort)
SSLEnabled=0

[mysql]
Version=$($c.mariadb)
Port=$($Settings.dbPort)
"@
    Write-Text (Join-Path $Paths.Laragon 'usr\laragon.ini') ($ini -replace "`r?`n", "`r`n")
    # Laragon's sample start page is not part of BSU Inventory
    Remove-Item (Join-Path $Paths.Laragon 'www\index.php') -Force -ErrorAction SilentlyContinue
    Write-Ok "Laragon set to use $($c.php), $($c.apache), $($c.mariadb); its own auto-start is off"
}

# Sets "key = value" in an ini/.env text: replaces the first line for the key (also a
# commented-out one, with $Comment) or appends it. Works on CRLF and LF lines alike: the line
# break itself is never part of the match ($ in .NET multiline mode stops before \n only).
function Set-ConfigLine([string]$Text, [string]$Key, [string]$Value, [string]$Comment = ';') {
    $pattern = "(?m)^[ \t]*$([regex]::Escape($Comment))?[ \t]*$([regex]::Escape($Key))[ \t]*=[^\r\n]*(?=\r?$)"
    $line = "$Key = $Value"
    if ([regex]::IsMatch($Text, $pattern)) { return ([regex]$pattern).Replace($Text, $line.Replace('$', '$$'), 1) }
    return $Text.TrimEnd() + "`r`n$line`r`n"
}

function Set-IniValue([string]$Text, [string]$Key, [string]$Value) { return Set-ConfigLine $Text $Key $Value ';' }

function Write-PhpIni([object]$Paths) {
    Write-Step 'PHP (php.ini)'
    $text = Read-Text (Join-Path $Paths.Php 'php.ini-production')
    # Only what the system uses: CodeIgniter (intl, mbstring), the database (mysqli),
    # encryption and backups (openssl, zip), PDF/images (gd), uploads (fileinfo)
    foreach ($ext in @('intl', 'mbstring', 'mysqli', 'openssl', 'zip', 'gd', 'fileinfo')) {
        $pattern = "(?m)^[ \t]*;?[ \t]*extension[ \t]*=[ \t]*(php_)?$ext(\.dll)?[ \t]*(?=\r?$)"
        if ([regex]::IsMatch($text, $pattern)) { $text = ([regex]$pattern).Replace($text, "extension=$ext", 1) }
        else { $text = $text.TrimEnd() + "`r`nextension=$ext`r`n" }
    }
    $pattern = '(?m)^[ \t]*;?[ \t]*zend_extension[ \t]*=[ \t]*(php_)?opcache(\.dll)?[ \t]*(?=\r?$)'
    if ([regex]::IsMatch($text, $pattern)) { $text = ([regex]$pattern).Replace($text, 'zend_extension=opcache', 1) }
    else { $text = $text.TrimEnd() + "`r`nzend_extension=opcache`r`n" }

    $phpLog = ConvertTo-ApachePath (Join-Path $Paths.Logs 'php\php_errors.log')
    $tmp    = ConvertTo-ApachePath $Paths.Tmp
    $cacert = ConvertTo-ApachePath (Join-Path $Paths.Laragon 'etc\ssl\cacert.pem')
    $values = [ordered]@{
        'extension_dir'           = "`"$(ConvertTo-ApachePath (Join-Path $Paths.Php 'ext'))`""
        'display_errors'          = 'Off'
        'display_startup_errors'  = 'Off'
        'log_errors'              = 'On'
        'error_log'               = "`"$phpLog`""
        'expose_php'              = 'Off'
        'date.timezone'           = 'Asia/Manila'
        'memory_limit'            = '512M'
        'max_execution_time'      = '300'
        'max_input_time'          = '300'
        'upload_max_filesize'     = '64M'
        'post_max_size'           = '64M'
        'upload_tmp_dir'          = "`"$tmp`""
        'sys_temp_dir'            = "`"$tmp`""
        'opcache.enable'          = '1'
        'opcache.enable_cli'      = '0'
        'opcache.memory_consumption' = '128'
        'opcache.validate_timestamps' = '1'
        'opcache.revalidate_freq' = '2'
        # Lets "Forgot password" verify the mail server's certificate (it needs internet anyway)
        'openssl.cafile'          = "`"$cacert`""
        'curl.cainfo'             = "`"$cacert`""
    }
    foreach ($k in $values.Keys) { $text = Set-IniValue $text $k $values[$k] }
    $text = "; BSU Inventory System - generated by manager\configure.ps1 from php.ini-production.`r`n; Do not edit: Repair and updates rewrite it.`r`n`r`n" + $text
    Write-Text $Paths.PhpIni $text

    $mods = (Invoke-Tool -Exe $Paths.PhpExe -Arguments @('-m') -Quiet).Output
    foreach ($ext in @('intl', 'mbstring', 'mysqli', 'openssl', 'zip', 'gd', 'fileinfo', 'Zend OPcache', 'dom', 'ctype', 'iconv')) {
        if ($mods -notmatch "(?mi)^$([regex]::Escape($ext))\s*$") { throw "The PHP extension $ext does not load. See $($Paths.PhpIni)." }
    }
    $v = (Invoke-Tool -Exe $Paths.PhpExe -Arguments @('-r', 'echo PHP_VERSION;') -Quiet).Output.Trim()
    Write-Ok "PHP $v with intl, mbstring, mysqli, openssl, zip, gd, fileinfo and OPcache; errors are logged, not shown"
}

function New-ServiceAccount([string]$Name, [string]$DisplayName, [string]$Description, [string]$BinPath, [string]$DependsOn = '') {
    $existing = Get-CimInstance Win32_Service -Filter "Name='$Name'" -ErrorAction SilentlyContinue
    if ($existing -and ($existing.PathName -ne $BinPath)) {
        # An update moved the program (new component version): register it again
        if ($existing.State -ne 'Stopped') { Stop-Service -Name $Name -Force; Wait-ServiceStatus $Name 'Stopped' 60 | Out-Null }
        Invoke-Tool -Exe 'sc.exe' -Arguments @('delete', $Name) -Quiet | Out-Null
        Start-Sleep -Seconds 2
        $existing = $null
    }
    if (-not $existing) {
        # New-Service passes the program path as it is (sc.exe would need nested quoting)
        New-Service -Name $Name -BinaryPathName $BinPath -DisplayName $DisplayName -Description $Description -StartupType Automatic | Out-Null
        Write-Ok "Registered the Windows service $Name"
    }
    # Its own virtual account (NT SERVICE\<name>): no password, no administrator rights
    $r = Invoke-Tool -Exe 'sc.exe' -Arguments @('config', $Name, 'start=', 'auto', 'obj=', "NT SERVICE\$Name", 'DisplayName=', $DisplayName) -Quiet
    if ($r.Code -ne 0) { throw "Could not set the account of the service ${Name}: $($r.Output)" }
    Invoke-Tool -Exe 'sc.exe' -Arguments @('description', $Name, $Description) -Quiet | Out-Null
    if ($DependsOn) { Invoke-Tool -Exe 'sc.exe' -Arguments @('config', $Name, 'depend=', $DependsOn) -Quiet | Out-Null }
    # Restart automatically if it ever stops unexpectedly
    Invoke-Tool -Exe 'sc.exe' -Arguments @('failure', $Name, 'reset=', '86400', 'actions=', 'restart/5000/restart/15000/restart/60000') -Quiet | Out-Null
    # Signed-in users may START it (the BSU Inventory shortcut does); stopping stays with Administrators
    $sd = (Invoke-Tool -Exe 'sc.exe' -Arguments @('sdshow', $Name) -Quiet).Output.Trim()
    if ($sd -match '\(A;;CCLCSWLOCRRC;;;IU\)') {
        $sd = $sd.Replace('(A;;CCLCSWLOCRRC;;;IU)', '(A;;CCLCSWRPLOCRRC;;;IU)')
        Invoke-Tool -Exe 'sc.exe' -Arguments @('sdset', $Name, $sd) -Quiet | Out-Null
    }
}

# The data folder (once), the root login and my.ini. Returns $true when the data is new.
function Initialize-MariaDbFiles([object]$Paths, $Settings) {
    Write-Step 'Database server (MariaDB)'
    $myIni = Join-Path $Paths.DataDir 'my.ini'
    $newData = -not (Test-Path (Join-Path $Paths.DataDir 'mysql'))

    if ($newData) {
        if (Test-Path $Paths.RootCnf) { Remove-Item $Paths.RootCnf -Force }
        $rootPassword = New-RandomSecret 32
        Register-Secret $rootPassword
        if (Test-Path $Paths.DataDir) {
            if (@(Get-ChildItem $Paths.DataDir -Force).Count -gt 0) { throw "$($Paths.DataDir) is not empty but holds no database. Move its contents away and try again." }
            Remove-Item $Paths.DataDir -Force
        }
        $installDb = Join-Path $Paths.MariaDbDir 'bin\mariadb-install-db.exe'
        $r = Invoke-Tool -Exe $installDb -Arguments @("--datadir=$($Paths.DataDir)", "--password=$rootPassword", "--port=$($Settings.dbPort)") -Label 'Creating the database files (mariadb-install-db)'
        if ($r.Code -ne 0) { throw "Could not create the database files: $($r.Output)" }
        $cnf = "[client]`r`nuser=root`r`npassword=$rootPassword`r`nhost=127.0.0.1`r`nport=$($Settings.dbPort)`r`n"
        Write-Text $Paths.RootCnf $cnf
        Set-AdminOnlyAcl $Paths.SecretsDir
        Write-Ok "Created the database files in $($Paths.DataDir); the root password is in $($Paths.RootCnf) (Administrators only)"
    } elseif (-not (Test-Path $Paths.RootCnf)) {
        throw ("The database exists but its root login ($($Paths.RootCnf)) is missing. Restore that file from a backup " +
               "(config\secrets in a full backup), or see 'Lost database root password' in the README.")
    } else {
        # Keep the client port in step with the server
        $cnfText = Read-Text $Paths.RootCnf
        $pw = [regex]::Match($cnfText, '(?m)^password=(.*)$').Groups[1].Value.Trim()
        Register-Secret $pw
        Write-Text $Paths.RootCnf ([regex]::Replace($cnfText, '(?m)^port=[^\r\n]*(?=\r?$)', "port=$($Settings.dbPort)"))
    }

    $logDir = ConvertTo-ApachePath (Join-Path $Paths.Logs 'mariadb')
    $text = Expand-Template (Join-Path $Paths.Manager 'templates\my.ini.template') @{
        DATA_DIR = ConvertTo-ApachePath $Paths.DataDir
        PORT     = $Settings.dbPort
        LOG_DIR  = $logDir
    }
    Write-Text $myIni ($text -replace "`r?`n", "`r`n")
    return $newData
}

# MariaDB as the BSUInventoryDB Windows service; system tables upgraded after a version change
function Start-MariaDbService([object]$Paths, $Settings, [bool]$NewData) {
    $myIni = Join-Path $Paths.DataDir 'my.ini'
    $binPath = "`"$($Paths.Mariadbd)`" `"--defaults-file=$myIni`" $($script:DbService)"
    New-ServiceAccount $script:DbService 'BSU Inventory - Database (MariaDB)' 'Database of the BSU Inventory System (Laragon MariaDB). Reachable from this PC only.' $binPath
    Grant-Access $Paths.Install $script:DbSid 'RX'
    Grant-Access $Paths.DataDir $script:DbSid 'M'
    Grant-Access (Join-Path $Paths.Logs 'mariadb') $script:DbSid 'M'

    Start-Service -Name $script:DbService
    if (-not (Wait-ServiceStatus $script:DbService 'Running' 60) -or -not (Wait-DbAlive $Paths 60)) {
        throw "MariaDB did not start. See $($Paths.Logs)\mariadb\error.log."
    }
    Write-Ok "MariaDB $($Paths.Components.mariadbVersion) runs as a Windows service on 127.0.0.1:$($Settings.dbPort)"

    # After a MariaDB version change the system tables are brought up to date
    $prev = $null
    if ($Settings.previousComponents) { $prev = $Settings.previousComponents.mariadbVersion }
    if (-not $NewData -and $prev -and ($prev -ne $Paths.Components.mariadbVersion)) {
        $r = Invoke-Tool -Exe (Join-Path $Paths.MariaDbDir 'bin\mariadb-upgrade.exe') -Arguments @("--defaults-extra-file=$($Paths.RootCnf)") -Label "Upgrading the system tables from MariaDB $prev"
        if ($r.Code -ne 0) { throw "mariadb-upgrade failed: $($r.Output)" }
    }
}

function Initialize-AppDatabase([object]$Paths, $Settings) {
    Write-Step "Database $($script:DbName) and its account"
    $envFile = Join-Path $Paths.Backend '.env'
    $password = ''
    if (Test-Path $envFile) {
        $password = [regex]::Match((Read-Text $envFile), '(?m)^\s*database\.default\.password\s*=\s*(.*)$').Groups[1].Value.Trim().Trim("'", '"')
    }
    $user = $script:DbUser
    if (Test-Path $envFile) {
        $u = [regex]::Match((Read-Text $envFile), '(?m)^\s*database\.default\.username\s*=\s*(.*)$').Groups[1].Value.Trim().Trim("'", '"')
        if ($u -and $u -ne 'root') { $user = $u }
    }
    if (-not $password -or $password.Length -lt 16) { $password = New-RandomSecret 32 }
    Register-Secret $password

    # The application gets its own account with rights on its own database only (never root).
    # ALTER USER also brings the password in line with .env after a restore.
    $sql = @"
CREATE DATABASE IF NOT EXISTS ``$($script:DbName)`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$user'@'127.0.0.1' IDENTIFIED BY '$password';
CREATE USER IF NOT EXISTS '$user'@'localhost' IDENTIFIED BY '$password';
ALTER USER '$user'@'127.0.0.1' IDENTIFIED BY '$password';
ALTER USER '$user'@'localhost' IDENTIFIED BY '$password';
GRANT ALL PRIVILEGES ON ``$($script:DbName)``.* TO '$user'@'127.0.0.1';
GRANT ALL PRIVILEGES ON ``$($script:DbName)``.* TO '$user'@'localhost';
DROP USER IF EXISTS ''@'localhost';
DROP USER IF EXISTS 'root'@'$($env:COMPUTERNAME.ToLowerInvariant())';
DROP DATABASE IF EXISTS test;
FLUSH PRIVILEGES;
"@
    $r = Invoke-DbRoot $Paths $sql 'Creating the database and the application account'
    if ($r.Code -ne 0) { throw "Could not create the database or its account: $($r.Output)" }
    Write-Ok "Database $($script:DbName) ready; the application signs in as '$user' (rights on this database only)"
    return [pscustomobject]@{ User = $user; Password = $password }
}

function Write-BackendEnv([object]$Paths, $Settings, $Db, [string]$PrimaryUrl) {
    Write-Step 'Backend settings (backend\.env)'
    $envFile = Join-Path $Paths.Backend '.env'
    if (-not (Test-Path $envFile)) {
        $lines = @(
            '# BSU Inventory System - created by the Windows installer.',
            '# Private: holds the database password and the encryption key. Readable only by',
            '# Administrators and the web server service. Kept as it is by updates.',
            'CI_ENVIRONMENT = production',
            '',
            "app.baseURL = '$PrimaryUrl'",
            '',
            'database.default.hostname = 127.0.0.1',
            "database.default.database = $($script:DbName)",
            "database.default.username = $($Db.User)",
            "database.default.password = $($Db.Password)",
            'database.default.DBDriver = MySQLi',
            'database.default.DBPrefix = ',
            "database.default.port = $($Settings.dbPort)",
            '',
            'logger.threshold = 4'
        )
        Write-Text $envFile (($lines -join "`r`n") + "`r`n")
        Write-Ok 'Created backend\.env (production, the application''s own database account)'
    } else {
        $text = Read-Text $envFile
        $before = $text
        $set = { param($t, $key, $value) Set-ConfigLine $t $key $value '#' }
        $text = & $set $text 'database.default.hostname' '127.0.0.1'
        $text = & $set $text 'database.default.port' "$($Settings.dbPort)"
        $text = & $set $text 'database.default.username' $Db.User
        $text = & $set $text 'database.default.password' $Db.Password
        $text = & $set $text 'app.baseURL' "'$PrimaryUrl'"
        if ($text -match '(?m)^\s*CI_ENVIRONMENT\s*=\s*development') {
            $text = & $set $text 'CI_ENVIRONMENT' 'production'
            Write-Warn 'CI_ENVIRONMENT was development (error details shown to every device); set to production'
        }
        if ($text -ne $before) {
            Copy-Item $envFile (Join-Path $Paths.SecretsDir ("env-before-{0}.bak" -f (Get-Date -Format 'yyyyMMdd-HHmmss')))
            Write-Text $envFile $text
            Write-Ok 'Updated backend\.env (previous copy kept in config\secrets)'
        } else {
            Write-Ok 'backend\.env kept as it is'
        }
    }
    if ((Read-Text $envFile) -notmatch '(?m)^\s*encryption\.key\s*=\s*\S') {
        $r = Invoke-Spark $Paths @('key:generate', '--force') 'Creating the encryption key'
        if ($r.Code -ne 0) { throw "Could not create the encryption key: $($r.Output)" }
        $key = [regex]::Match((Read-Text $envFile), '(?m)^\s*encryption\.key\s*=\s*(.*)$').Groups[1].Value.Trim()
        Register-Secret $key
    }
    # Administrators and SYSTEM change it; only the web service may read it
    Invoke-Icacls @($envFile, '/inheritance:r', '/grant:r', "$($script:SidAdmins):F", "$($script:SidSystem):F", "$($script:WebSid):R", '/C', '/Q')
}

function Update-Schema([object]$Paths, [string]$AdminFile) {
    Write-Step 'Database tables (migrations)'
    foreach ($d in @('cache', 'logs', 'session', 'uploads', 'imports', 'backups', 'debugbar')) {
        $dir = Join-Path $Paths.Backend "writable\$d"
        if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
    }
    $r = Invoke-Spark $Paths @('migrate') 'php spark migrate'
    if ($r.Code -ne 0 -or $r.Output -match '(?i)\b(error|exception)\b') { throw "The database migrations failed: $($r.Output)" }
    Write-Ok 'Database tables are up to date'

    # The fixed lists (access levels, statuses, offices...). Only adds what is missing; no
    # accounts and no inventory.
    $r = Invoke-Spark $Paths @('db:seed', 'ReferenceDataSeeder') 'php spark db:seed ReferenceDataSeeder'
    if ($r.Code -ne 0) { throw "Adding the reference data failed: $($r.Output)" }
    Write-Ok 'Reference data present (access levels, statuses, transaction types, offices)'

    if ($AdminFile) {
        if (-not (Test-Path $AdminFile)) { throw 'The administrator details from the installer are missing.' }
        $r = Invoke-Spark $Paths @('user:create-admin', '--from-file', $AdminFile, '--if-none') 'php spark user:create-admin'
        Remove-Item $AdminFile -Force -ErrorAction SilentlyContinue
        if ($r.Code -ne 0) { throw "Could not create the administrator account: $($r.Output)" }
        Write-Ok 'Administrator account ready'
    }
}

function Set-InAppBackupFolder([object]$Paths, $Settings) {
    # The app's own backups (Office Settings > Backups) default to a folder outside the
    # application, next to the full backups. Settings an office already saved are kept.
    $inApp = Join-Path $Settings.backupDir 'in-app'
    if (-not (Test-Path $inApp)) { New-Item -ItemType Directory -Force -Path $inApp | Out-Null }
    Grant-Access $inApp $script:WebSid 'M'
    $configFile = Join-Path $Paths.Backend 'writable\backups\backup_config.json'
    if (-not (Test-Path $configFile)) {
        $json = @{ backup_dir = ((ConvertTo-ApachePath $inApp).TrimEnd('/') + '/') } | ConvertTo-Json
        Write-Text $configFile $json
        Write-Ok "In-app backups default to $inApp"
    }
}

function Initialize-Certificates([object]$Paths, [string[]]$Addresses) {
    Write-Step 'HTTPS certificates'
    $dir = $Paths.Certs
    $public = Join-Path $dir 'public'
    New-Item -ItemType Directory -Force -Path $public | Out-Null
    $caKey = Join-Path $dir 'bsu-inventory-ca.key'
    $caCrt = Join-Path $dir 'bsu-inventory-ca.crt'
    $serverKey = Join-Path $dir 'server.key'
    $serverCrt = Join-Path $dir 'server.crt'
    $namesFile = Join-Path $dir 'server-names.txt'
    $caNameFile = Join-Path $dir 'ca-hostname.txt'
    $computer = $env:COMPUTERNAME.ToLowerInvariant()
    $renew = $false

    # The authority may only vouch for private network addresses and this PC's own names
    # (name constraints): even if its key were stolen it could not impersonate real websites.
    if (-not (Test-Path $caKey) -or -not (Test-Path $caCrt)) {
        $caConfig = Join-Path $dir 'ca.cnf'
        Write-Text $caConfig @"
[req]
distinguished_name = dn
prompt = no
x509_extensions = v3_ca
[dn]
CN = BSU Inventory Local CA ($($env:COMPUTERNAME))
O = Benguet State University - Inventory System
[v3_ca]
basicConstraints = critical, CA:TRUE, pathlen:0
keyUsage = critical, keyCertSign, cRLSign
subjectKeyIdentifier = hash
nameConstraints = critical, permitted;IP:10.0.0.0/255.0.0.0, permitted;IP:172.16.0.0/255.240.0.0, permitted;IP:192.168.0.0/255.255.0.0, permitted;IP:127.0.0.0/255.0.0.0, permitted;DNS:localhost, permitted;DNS:$computer
"@
        $env:OPENSSL_CONF = $caConfig
        $r = Invoke-Tool -Exe $Paths.OpenSsl -Arguments @('req', '-x509', '-newkey', 'rsa:3072', '-nodes', '-sha256', '-days', '3650', '-keyout', $caKey, '-out', $caCrt, '-config', $caConfig) -Quiet
        if ($r.Code -ne 0) { throw "Could not create the certificate authority: $($r.Output)" }
        Write-Text $caNameFile $computer
        $renew = $true
        Write-Ok 'Created the local certificate authority (valid 10 years)'
    }
    $caHostname = $computer
    if (Test-Path $caNameFile) { $caHostname = (Read-Text $caNameFile).Trim() }

    $ips = @($Addresses + '127.0.0.1' | Sort-Object -Unique)
    $dns = @('localhost')
    if ($caHostname -eq $computer) { $dns += $computer }
    $wanted = (($ips | ForEach-Object { "IP:$_" }) + ($dns | ForEach-Object { "DNS:$_" })) -join ', '

    $renew = $renew -or -not (Test-Path $serverCrt) -or -not (Test-Path $serverKey) -or -not (Test-Path $namesFile) -or ((Read-Text $namesFile).Trim() -ne $wanted)
    if (-not $renew) {
        $renew = (Invoke-Tool -Exe $Paths.OpenSsl -Arguments @('x509', '-checkend', '2592000', '-noout', '-in', $serverCrt) -Quiet).Code -ne 0
    }
    if ($renew) {
        $req = Join-Path $dir 'server-req.cnf'
        $ext = Join-Path $dir 'server-ext.cnf'
        Write-Text $req "[req]`ndistinguished_name = dn`nprompt = no`n[dn]`nCN = $($dns[-1])`n"
        Write-Text $ext ("[ext]`nbasicConstraints = critical, CA:FALSE`nkeyUsage = critical, digitalSignature, keyEncipherment`n" +
            "extendedKeyUsage = serverAuth`nsubjectAltName = $wanted`nauthorityKeyIdentifier = keyid`nsubjectKeyIdentifier = hash`n")
        $env:OPENSSL_CONF = $req
        $csr = Join-Path $dir 'server.csr'
        $r = Invoke-Tool -Exe $Paths.OpenSsl -Arguments @('req', '-new', '-newkey', 'rsa:2048', '-nodes', '-keyout', $serverKey, '-out', $csr, '-config', $req) -Quiet
        if ($r.Code -ne 0) { throw "Could not create the server key: $($r.Output)" }
        # 825 days: the longest validity Apple devices accept for a locally trusted certificate
        $r = Invoke-Tool -Exe $Paths.OpenSsl -Arguments @('x509', '-req', '-in', $csr, '-CA', $caCrt, '-CAkey', $caKey, '-CAcreateserial',
            '-out', $serverCrt, '-days', '825', '-sha256', '-extfile', $ext, '-extensions', 'ext') -Quiet
        if ($r.Code -ne 0) { throw "Could not issue the server certificate: $($r.Output)" }
        Remove-Item $csr -ErrorAction SilentlyContinue
        $verify = Invoke-Tool -Exe $Paths.OpenSsl -Arguments @('verify', '-CAfile', $caCrt, $serverCrt) -Quiet
        if ($verify.Code -ne 0) { throw "The new certificate does not verify: $($verify.Output)" }
        Write-Text $namesFile $wanted
        Write-Ok "Issued the HTTPS certificate for: $wanted"
    } else {
        Write-Ok 'The HTTPS certificate is current for this PC''s addresses'
    }
    Remove-Item Env:\OPENSSL_CONF -ErrorAction SilentlyContinue
    Copy-Item $caCrt (Join-Path $public 'bsu-inventory-ca.crt') -Force

    # Keys: Administrators and SYSTEM; the web service reads only the server key
    Set-AdminOnlyAcl $dir
    Invoke-Icacls @($dir, '/grant', "$($script:WebSid):(RX)", '/C', '/Q')
    Grant-Access $serverKey $script:WebSid 'R'
    Grant-Access $serverCrt $script:WebSid 'R'
    Invoke-Icacls @($public, '/grant', "$($script:SidUsers):(OI)(CI)RX", "$($script:WebSid):(OI)(CI)RX", '/T', '/C', '/Q')

    # This PC's browsers trust the authority
    $cert = New-Object System.Security.Cryptography.X509Certificates.X509Certificate2 $caCrt
    if (-not (Get-ChildItem Cert:\LocalMachine\Root | Where-Object { $_.Thumbprint -eq $cert.Thumbprint })) {
        Import-Certificate -FilePath $caCrt -CertStoreLocation Cert:\LocalMachine\Root | Out-Null
        Write-Ok 'This PC now trusts the BSU Inventory certificate authority'
    }
    $sha = [System.Security.Cryptography.SHA256]::Create()
    return (($sha.ComputeHash($cert.RawData) | ForEach-Object { $_.ToString('X2') }) -join ':')
}

function Write-ApacheConfig([object]$Paths, $Settings) {
    Write-Step 'Web server (Apache)'
    $apacheLogs = Join-Path $Paths.Logs 'apache'
    $vhosts = Join-Path $Paths.Etc 'bsu-inventory-vhosts.conf'
    $conf   = Join-Path $Paths.Etc 'httpd.conf'
    $bind = '0.0.0.0'
    if (-not $Settings.lanAccess) { $bind = '127.0.0.1' }

    $text = Expand-Template (Join-Path $Paths.Manager 'templates\bsu-inventory-vhosts.conf.template') @{
        APP_ROOT          = ConvertTo-ApachePath $Paths.App
        CERT_DIR          = ConvertTo-ApachePath $Paths.Certs
        HTTP_PORT         = $Settings.httpPort
        HTTPS_PORT        = $Settings.httpsPort
        HTTPS_PORT_SUFFIX = Get-HttpsSuffix ([int]$Settings.httpsPort)
    }
    Write-Text $vhosts ($text -replace "`r?`n", "`r`n")
    $text = Expand-Template (Join-Path $Paths.Manager 'templates\httpd.conf.template') @{
        APACHE_ROOT = ConvertTo-ApachePath $Paths.Apache
        LOG_DIR     = ConvertTo-ApachePath $apacheLogs
        LISTEN      = "Listen $($bind):$($Settings.httpPort)`r`nListen $($bind):$($Settings.httpsPort)"
        PHP_DIR     = ConvertTo-ApachePath $Paths.Php
        PHP_LOADFILES = ((Get-ChildItem $Paths.Php -Filter 'icu*.dll' | Sort-Object { $_.Name -notlike 'icudt*' }, Name |
                          ForEach-Object { "LoadFile `"$(ConvertTo-ApachePath $_.FullName)`"" }) -join "`r`n")
        VHOSTS_CONF = ConvertTo-ApachePath $vhosts
    }
    Write-Text $conf ($text -replace "`r?`n", "`r`n")

    $check = Invoke-Tool -Exe $Paths.Httpd -Arguments @('-t', '-f', $conf) -Quiet
    if ($check.Output -notmatch 'Syntax OK') { throw "Apache rejected its configuration: $($check.Output)" }
    Write-Ok 'Apache configuration checked (Syntax OK)'
    return $conf
}

# Apache as the BSUInventoryWeb Windows service (started later, after the permissions)
# Registered early (not started): the permissions below name its account, and Windows only
# knows that account once the service exists. Same registration as "httpd -k install": the
# program runs with "-k runservice", Apache takes its service name from Windows and its
# arguments ("-f <conf>") from Parameters\ConfigArgs.
function Register-WebService([object]$Paths) {
    $conf = Join-Path $Paths.Etc 'httpd.conf'
    $binPath = "`"$($Paths.Httpd)`" -k runservice"
    New-ServiceAccount $script:WebService 'BSU Inventory - Web server (Apache)' 'Serves the BSU Inventory System (Laragon Apache + PHP) on the local network over HTTPS.' $binPath $script:DbService
    $params = "HKLM:\SYSTEM\CurrentControlSet\Services\$($script:WebService)\Parameters"
    if (-not (Test-Path $params)) { New-Item -Path $params -Force | Out-Null }
    New-ItemProperty -Path $params -Name 'ConfigArgs' -PropertyType MultiString -Value @('-f', $conf) -Force | Out-Null
}

function Set-Firewall([object]$Paths, $Settings) {
    Write-Step 'Windows firewall'
    $name = 'BSU Inventory (web)'
    Get-NetFirewallRule -DisplayName $name -ErrorAction SilentlyContinue | Remove-NetFirewallRule
    if (-not $Settings.lanAccess) {
        Write-Ok 'LAN access is off: no firewall rule (this PC only)'
        return
    }
    # Only the two web ports, only for Apache, only on private/domain networks, only from
    # private (LAN) addresses. The database port is never opened.
    New-NetFirewallRule -DisplayName $name -Description 'BSU Inventory System: HTTPS and the HTTP redirect, for computers on the local network.' `
        -Direction Inbound -Protocol TCP -LocalPort @([int]$Settings.httpPort, [int]$Settings.httpsPort) -Program $Paths.Httpd `
        -RemoteAddress @('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16') -Profile @('Private', 'Domain') -Action Allow | Out-Null
    Write-Ok "Allowed TCP $($Settings.httpPort) and $($Settings.httpsPort) from LAN addresses on private and domain networks (MariaDB's port stays closed)"
    $public = @(Get-NetConnectionProfile -ErrorAction SilentlyContinue | Where-Object { $_.NetworkCategory -eq 'Public' })
    if ($public.Count -gt 0) {
        Write-Warn "These networks are set to Public, where other PCs are blocked: $(($public | ForEach-Object { $_.InterfaceAlias }) -join ', ')"
        Write-Warn 'If this is the BSU office network, set it to Private: Windows Settings > Network & internet > (the network) > Private network.'
    }
}

function Set-BackupTask([object]$Paths, $Settings) {
    Write-Step 'Backups'
    $full = Join-Path $Settings.backupDir 'full'
    New-Item -ItemType Directory -Force -Path $full | Out-Null
    # Full backups hold every record and the configuration: Administrators and SYSTEM only
    Set-AdminOnlyAcl $full
    $taskName = 'BSU Inventory Daily Backup'
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue
    if ($Settings.dailyBackup) {
        $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ("-NoProfile -ExecutionPolicy Bypass -File `"$(Join-Path $Paths.Manager 'backup.ps1')`" -Reason scheduled")
        $trigger = New-ScheduledTaskTrigger -Daily -At ([datetime]::ParseExact($Settings.backupTime, 'HH:mm', $null))
        $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
        # A backup missed while the PC was off runs as soon as it is back on
        $taskSettings = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 2) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
        Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Settings $taskSettings `
            -Description 'Full backup of the BSU Inventory database, configuration and uploaded files.' | Out-Null
        Write-Ok "Daily full backup at $($Settings.backupTime) to $full (the newest $($Settings.backupKeep) are kept)"
    } else {
        Write-Ok "Daily backup is off; back up from the Server Manager. Folder: $full"
    }
}

function Write-ConnectionInfo([object]$Paths, $Settings, [object[]]$Lan, [string]$Fingerprint) {
    $suffix = Get-HttpsSuffix ([int]$Settings.httpsPort)
    $httpSuffix = ''
    if ([int]$Settings.httpPort -ne 80) { $httpSuffix = ":$($Settings.httpPort)" }
    $lines = @('BSU Inventory System - how to connect', '======================================', '',
               "On this server:     https://localhost$suffix/")
    if ($Settings.lanAccess) {
        foreach ($a in $Lan) { $lines += "On other computers: https://$($a.IP)$suffix/" }
        if ($Lan.Count -eq 0) { $lines += 'No LAN address found: connect this PC to the office network, then run Repair in the Server Manager.' }
        if (@($Lan | Where-Object { $_.Dhcp }).Count -gt 0) {
            $lines += @('', 'This address comes from DHCP and may change. Ask the network administrator for a DHCP',
                        'reservation (or a static address) for this PC. After a change, run Repair in the Server Manager.')
        }
        $lines += @('', 'ONCE PER COMPUTER/PHONE: trust this server''s certificate authority first.')
        if ($Lan.Count -gt 0) { $lines += "  1. Open  http://$($Lan[0].IP)$httpSuffix/bsu-inventory-ca.crt  and save the file." }
        $lines += @('  2. Windows: double-click it > Install Certificate > Local Machine >',
                    '     Place all certificates in: Trusted Root Certification Authorities.',
                    '     Android: Settings > Security > Encryption & credentials > Install a certificate > CA certificate.',
                    '     iPhone/iPad: open the file, install the profile, then Settings > General > About >',
                    '     Certificate Trust Settings > switch it on.',
                    '  3. Check that the SHA-256 fingerprint shown matches:',
                    "     $Fingerprint")
    } else {
        $lines += @('', 'LAN access is off: only this PC can use the system.')
    }
    $lines += @('', "Backups: $($Settings.backupDir)", "Logs:    $($Paths.Logs)")
    Write-Text (Join-Path $Paths.Config 'connection-info.txt') (($lines -join "`r`n") + "`r`n")
}

# ===========================================================================
#  Main
# ===========================================================================
function Invoke-Configure {
    $paths = Get-BsuPaths $InstallDir
    Start-BsuLog (Join-Path $paths.Logs ("configure-{0}-{1}.log" -f $Mode.ToLowerInvariant(), (Get-Date -Format 'yyyyMMdd-HHmmss')))
    try {
        Assert-Administrator
        Write-Step "BSU Inventory server configuration ($Mode) in $($paths.Install)"
        $settings = Resolve-Settings $paths
        Test-Components $paths

        # Configure with the servers stopped (an update already stopped them)
        Stop-BsuServices
        Select-Ports $paths $settings
        # Saved now (and again at the end), so a Repair after a failed run knows the chosen ports
        Save-Settings $paths $settings
        Set-InstallPermissions $paths ($Mode -eq 'Install')
        Write-LaragonIni $paths $settings
        Write-PhpIni $paths

        $lan = @(Get-LanAddresses)
        $ip = 'localhost'
        if ($settings.lanAccess -and $lan.Count -gt 0) { $ip = $lan[0].IP }
        $primaryUrl = "https://$ip$(Get-HttpsSuffix ([int]$settings.httpsPort))/"

        $newData = Initialize-MariaDbFiles $paths $settings
        Start-MariaDbService $paths $settings $newData
        Register-WebService $paths
        $db = Initialize-AppDatabase $paths $settings
        Write-BackendEnv $paths $settings $db $primaryUrl
        Update-Schema $paths $AdminFile
        Set-InAppBackupFolder $paths $settings
        # The certificate covers every private address of this PC (also Wi-Fi or a hotspot)
        $fingerprint = Initialize-Certificates $paths @(Get-LanAddresses -All | ForEach-Object { $_.IP })
        Write-ApacheConfig $paths $settings | Out-Null
        Grant-ServiceAccess $paths
        Set-Firewall $paths $settings
        Set-BackupTask $paths $settings

        Write-Step 'Starting and checking the system'
        Start-BsuServices
        $problem = Wait-AppHealthy ([int]$settings.httpsPort) 60
        if ($problem) { throw "The system does not answer on https://localhost: $problem. See $($paths.Logs)\apache\error.log." }
        Write-Ok "The system answers on https://localhost$(Get-HttpsSuffix ([int]$settings.httpsPort))/"
        if ($settings.lanAccess -and $lan.Count -gt 0) {
            $problem = Test-AppHealth ([int]$settings.httpsPort) $lan[0].IP
            if ($problem) { Write-Warn "Not reachable on $($lan[0].IP) from this PC: $problem" }
            else { Write-Ok "The system answers on $primaryUrl" }
        }

        $settings.updatedAt = (Get-Date).ToString('s')
        Save-Settings $paths $settings
        # Tells the installer the first setup finished (else it asks for the administrator again)
        Write-Text (Join-Path $paths.Config 'setup-complete') (Get-Date).ToString('s')
        Write-ConnectionInfo $paths $settings $lan $fingerprint
        Write-Log 'RESULT: OK' 'OK'
        return 0
    } catch {
        Write-Log "RESULT: FAILED - $($_.Exception.Message)" 'ERROR'
        Write-Log ($_.ScriptStackTrace) 'ERROR'
        return 1
    }
}

if (-not $DefineOnly) { exit ([int](Invoke-Configure | Select-Object -Last 1)) }
