<#
    Shared by every BSU Inventory server script (configure, launcher, manager, backup,
    restore, uninstall). Dot-source it:   . "$PSScriptRoot\common.ps1"

    Installed layout (the install folder is chosen in the installer, default C:\BSU-Inventory):

      <install>\laragon\                      BSU's own copy of Laragon 6 (no other Laragon is touched)
        bin\apache\httpd-*\                   Apache (Apache Lounge build)
        bin\php\php-*\                        PHP, loaded into Apache as mod_php; php.ini lives here
        bin\mysql\mariadb-*\                  MariaDB
        www\bsu-inventory\                    the application (backend\, frontend\dist\, deploy\)
        etc\bsu-inventory\                    generated Apache configuration and HTTPS certificates
        data\bsu-inventory-db\                the database files (my.ini is here too)
        usr\bsu-components.json               which component folders this installer shipped
      <install>\manager\                      these scripts and the two small launcher programs
      <install>\config\install.json           settings chosen in the installer (no secrets)
      <install>\config\secrets\               database root login; Administrators and SYSTEM only
      <install>\logs\                         install, backup, Apache, PHP and MariaDB logs

    Apache and MariaDB run as two Windows services under their own low-privilege virtual
    accounts (NT SERVICE\BSUInventoryWeb, NT SERVICE\BSUInventoryDB), so the system is up
    after a restart before anyone logs in.

    Windows PowerShell 5.1 compatible (no ternary, no ??).
#>

$ErrorActionPreference = 'Stop'

$script:WebService = 'BSUInventoryWeb'
$script:DbService  = 'BSUInventoryDB'
$script:DbName     = 'inventory_system'   # the project's database (deploy\setup-server.ps1, docker-compose.yml)
$script:DbUser     = 'inventory_user'
$script:Utf8NoBom  = New-Object System.Text.UTF8Encoding $false
$script:Secrets    = New-Object System.Collections.Generic.List[string]
$script:LogFile    = $null

# ---------------------------------------------------------------------------
#  Paths
# ---------------------------------------------------------------------------
function Get-BsuPaths {
    param([string]$InstallDir = '')
    if (-not $InstallDir) { $InstallDir = Split-Path -Parent $PSScriptRoot }
    $InstallDir = [System.IO.Path]::GetFullPath($InstallDir.TrimEnd('\'))
    $laragon = Join-Path $InstallDir 'laragon'
    $app     = Join-Path $laragon 'www\bsu-inventory'
    $p = [ordered]@{
        Install    = $InstallDir
        Laragon    = $laragon
        App        = $app
        Backend    = Join-Path $app 'backend'
        Dist       = Join-Path $app 'frontend\dist'
        Etc        = Join-Path $laragon 'etc\bsu-inventory'
        Certs      = Join-Path $laragon 'etc\bsu-inventory\certs'
        DataDir    = Join-Path $laragon 'data\bsu-inventory-db'
        Tmp        = Join-Path $laragon 'tmp'
        Manager    = Join-Path $InstallDir 'manager'
        Config     = Join-Path $InstallDir 'config'
        SecretsDir = Join-Path $InstallDir 'config\secrets'
        InstallJson = Join-Path $InstallDir 'config\install.json'
        RootCnf    = Join-Path $InstallDir 'config\secrets\db-root.cnf'
        Logs       = Join-Path $InstallDir 'logs'
    }
    $components = Join-Path $laragon 'usr\bsu-components.json'
    if (Test-Path $components) {
        $c = Get-Content $components -Raw | ConvertFrom-Json
        $p.Components = $c
        $p.Apache  = Join-Path $laragon "bin\apache\$($c.apache)"
        $p.Php     = Join-Path $laragon "bin\php\$($c.php)"
        $p.MariaDbDir = Join-Path $laragon "bin\mysql\$($c.mariadb)"
        $p.Httpd   = Join-Path $p.Apache 'bin\httpd.exe'
        $p.OpenSsl = Join-Path $p.Apache 'bin\openssl.exe'
        $p.PhpExe  = Join-Path $p.Php 'php.exe'
        $p.PhpIni  = Join-Path $p.Php 'php.ini'
        # (keys are case-insensitive: the folder and the client need distinct names)
        $p.Mariadbd     = Join-Path $p.MariaDbDir 'bin\mariadbd.exe'
        $p.MariadbCli   = Join-Path $p.MariaDbDir 'bin\mariadb.exe'
        $p.MariadbDump  = Join-Path $p.MariaDbDir 'bin\mariadb-dump.exe'
        $p.MariadbAdmin = Join-Path $p.MariaDbDir 'bin\mariadb-admin.exe'
    }
    return [pscustomobject]$p
}

function Read-InstallSettings([object]$Paths) {
    if (Test-Path $Paths.InstallJson) { return Get-Content $Paths.InstallJson -Raw | ConvertFrom-Json }
    return $null
}

# ---------------------------------------------------------------------------
#  Logging. Secrets registered with Register-Secret are masked in every log line.
# ---------------------------------------------------------------------------
function Start-BsuLog([string]$Path) {
    $dir = Split-Path -Parent $Path
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
    $script:LogFile = $Path
}

function Register-Secret([string]$Value) {
    if ($Value -and $Value.Length -ge 4) { $script:Secrets.Add($Value) }
}

function Protect-Text([string]$Text) {
    if (-not $Text) { return $Text }
    foreach ($s in $script:Secrets) { $Text = $Text.Replace($s, '********') }
    # Belt and braces: anything that looks like a password assignment
    $Text = [regex]::Replace($Text, '(?i)(password\s*[=:]\s*)\S+', '$1********')
    $Text = [regex]::Replace($Text, '(?i)(IDENTIFIED BY\s+)''[^'']*''', '$1''********''')
    return $Text
}

function Write-Log([string]$Text, [string]$Level = 'INFO') {
    $line = '{0} [{1}] {2}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Level, (Protect-Text $Text)
    if ($script:LogFile) { Add-Content -Path $script:LogFile -Value $line -Encoding UTF8 }
    $color = switch ($Level) { 'ERROR' { 'Red' } 'WARN' { 'Yellow' } 'OK' { 'Green' } 'STEP' { 'Cyan' } default { 'Gray' } }
    Write-Host $line -ForegroundColor $color
}
function Write-Step([string]$Text) { Write-Log "==> $Text" 'STEP' }
function Write-Ok([string]$Text)   { Write-Log $Text 'OK' }
function Write-Warn([string]$Text) { Write-Log $Text 'WARN' }

# ---------------------------------------------------------------------------
#  Running programs. Native tools write progress to stderr: that is not an error.
#  The command line itself is never logged (it may carry a password); only $Label.
# ---------------------------------------------------------------------------
function Invoke-Tool {
    param(
        [Parameter(Mandatory)][string]$Exe,
        [string[]]$Arguments = @(),
        [string]$WorkingDirectory = '',
        [string]$Label = '',
        [switch]$Quiet
    )
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    if ($WorkingDirectory) { Push-Location $WorkingDirectory }
    try {
        $output = & $Exe @Arguments 2>&1 | ForEach-Object { "$_" }
        $code   = $LASTEXITCODE
    } finally {
        if ($WorkingDirectory) { Pop-Location }
        $ErrorActionPreference = $previous
    }
    $text = ($output -join "`n").Trim()
    if ($Label -and -not $Quiet) {
        Write-Log "$Label (exit $code)"
        if ($text) { foreach ($l in ($text -split "`n")) { if ($l.Trim()) { Write-Log "    $l" } } }
    }
    return [pscustomobject]@{ Code = $code; Output = (Protect-Text $text) }
}

function Invoke-Spark([object]$Paths, [string[]]$Arguments, [string]$Label) {
    return Invoke-Tool -Exe $Paths.PhpExe -Arguments (@('spark') + $Arguments) -WorkingDirectory $Paths.Backend -Label $Label
}

# ---------------------------------------------------------------------------
#  Files
# ---------------------------------------------------------------------------
function Read-Text([string]$Path) { return [System.IO.File]::ReadAllText($Path) }
function Write-Text([string]$Path, [string]$Text) {
    $dir = Split-Path -Parent $Path
    if ($dir -and -not (Test-Path $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
    [System.IO.File]::WriteAllText($Path, $Text, $script:Utf8NoBom)
}
function ConvertTo-ApachePath([string]$Path) { return $Path.Replace('\', '/') }

function Expand-Template([string]$TemplatePath, [hashtable]$Values) {
    $text = Read-Text $TemplatePath
    foreach ($key in $Values.Keys) { $text = $text.Replace("{{$key}}", [string]$Values[$key]) }
    if ($text -match '\{\{[A-Z_]+\}\}') { throw "Template $TemplatePath has an unfilled placeholder: $($Matches[0])" }
    return $text
}

# A random password without characters that need quoting in .env, my.ini or SQL
function New-RandomSecret([int]$Length = 32) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'.ToCharArray()
    $bytes = New-Object byte[] $Length
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    return -join ($bytes | ForEach-Object { $chars[$_ % $chars.Length] })
}

# ---------------------------------------------------------------------------
#  Permissions (well-known SIDs, so they work on any Windows language)
# ---------------------------------------------------------------------------
$script:SidAdmins = '*S-1-5-32-544'
$script:SidSystem = '*S-1-5-18'
$script:SidUsers  = '*S-1-5-32-545'

# A service's own account is its service SID (S-1-5-80- + SHA-1 of the upper-case name), the
# same value "sc.exe showsid" prints. Known before the service exists, so permissions can
# be granted in any order.
function Get-ServiceSid([string]$Name) {
    $sha = [System.Security.Cryptography.SHA1]::Create()
    $h = $sha.ComputeHash([System.Text.Encoding]::Unicode.GetBytes($Name.ToUpperInvariant()))
    return 'S-1-5-80-' + ((0..4 | ForEach-Object { [BitConverter]::ToUInt32($h, $_ * 4) }) -join '-')
}
$script:WebSid = '*' + (Get-ServiceSid $script:WebService)
$script:DbSid  = '*' + (Get-ServiceSid $script:DbService)

function Invoke-Icacls([string[]]$Arguments) {
    $r = Invoke-Tool -Exe 'icacls.exe' -Arguments $Arguments -Quiet
    if ($r.Code -ne 0) { throw "Could not set permissions ($($Arguments[0])): $($r.Output)" }
}

# Administrators and SYSTEM only (secrets, certificate keys, full backups). The folder gets
# the rules and everything in it inherits them. (Never "/inheritance:r ... /T": that also
# strips each FILE's inheritance while the folder-only (OI)(CI) grants don't apply to files,
# leaving files nobody, not even an administrator, can read.)
function Set-AdminOnlyAcl([string]$Path) {
    if ((Get-Item -LiteralPath $Path).PSIsContainer) {
        Invoke-Icacls @($Path, '/inheritance:r', '/grant:r', "$($script:SidAdmins):(OI)(CI)F", "$($script:SidSystem):(OI)(CI)F", '/C', '/Q')
        if (Get-ChildItem -LiteralPath $Path -Force | Select-Object -First 1) {
            Invoke-Icacls @((Join-Path $Path '*'), '/reset', '/T', '/C', '/Q')
        }
    } else {
        Invoke-Icacls @($Path, '/inheritance:r', '/grant:r', "$($script:SidAdmins):F", "$($script:SidSystem):F", '/C', '/Q')
    }
}

function Grant-Access([string]$Path, [string]$Account, [string]$Rights = 'M') {
    if (-not (Test-Path $Path)) { New-Item -ItemType Directory -Force -Path $Path | Out-Null }
    $isDir = (Get-Item $Path).PSIsContainer
    $spec = if ($isDir) { "$($Account):(OI)(CI)$Rights" } else { "$($Account):$Rights" }
    Invoke-Icacls @($Path, '/grant', $spec, '/C', '/Q')
}

# ---------------------------------------------------------------------------
#  Services
# ---------------------------------------------------------------------------
function Get-BsuService([string]$Name) { return Get-Service -Name $Name -ErrorAction SilentlyContinue }

function Wait-ServiceStatus([string]$Name, [string]$Status, [int]$Seconds = 60) {
    $deadline = (Get-Date).AddSeconds($Seconds)
    while ((Get-Date) -lt $deadline) {
        $s = Get-BsuService $Name
        if ($s -and $s.Status -eq $Status) { return $true }
        if (-not $s -and $Status -eq 'Stopped') { return $true }   # a missing service is not running
        Start-Sleep -Milliseconds 500
    }
    return $false
}

function Start-BsuServices {
    foreach ($name in @($script:DbService, $script:WebService)) {
        $s = Get-BsuService $name
        if (-not $s) { throw "The Windows service $name is missing. Run 'Repair' in the BSU Inventory Server Manager." }
        if ($s.Status -ne 'Running') {
            Start-Service -Name $name
            if (-not (Wait-ServiceStatus $name 'Running' 60)) { throw "The service $name did not start. See the logs folder." }
        }
    }
}

function Stop-BsuServices {
    foreach ($name in @($script:WebService, $script:DbService)) {
        $s = Get-BsuService $name
        if ($s -and $s.Status -ne 'Stopped') {
            Stop-Service -Name $name -Force
            if (-not (Wait-ServiceStatus $name 'Stopped' 90)) { throw "The service $name did not stop." }
        }
    }
}

# ---------------------------------------------------------------------------
#  Network
# ---------------------------------------------------------------------------
function Test-PrivateIPv4([string]$Ip) {
    $o = $Ip.Split('.') | ForEach-Object { [int]$_ }
    return ($o[0] -eq 10) -or ($o[0] -eq 172 -and $o[1] -ge 16 -and $o[1] -le 31) -or ($o[0] -eq 192 -and $o[1] -eq 168)
}

# This PC's LAN addresses, the one with the default route first. Each has .IP and .Dhcp.
# Without -All only adapters that have a default route (the real LAN, not VMware/VirtualBox
# host-only adapters), unless there is none; -All lists every private address (certificate).
function Get-LanAddresses([switch]$All) {
    $routes = @(Get-NetRoute -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' -ErrorAction SilentlyContinue |
        Sort-Object { $_.RouteMetric + $_.InterfaceMetric })
    $order = @{}
    $i = 0
    foreach ($r in $routes) { if (-not $order.ContainsKey($r.InterfaceIndex)) { $order[$r.InterfaceIndex] = $i++ } }
    $list = @(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object { $_.IPAddress -notlike '169.254.*' -and $_.IPAddress -ne '127.0.0.1' -and (Test-PrivateIPv4 $_.IPAddress) } |
        ForEach-Object {
            $rank = if ($order.ContainsKey($_.InterfaceIndex)) { $order[$_.InterfaceIndex] } else { 1000 }
            [pscustomobject]@{ IP = $_.IPAddress; Dhcp = ($_.PrefixOrigin -eq 'Dhcp'); Interface = $_.InterfaceAlias; Rank = $rank }
        } | Sort-Object Rank, IP)
    $routed = @($list | Where-Object { $_.Rank -lt 1000 })
    if (-not $All -and $routed.Count -gt 0) { return $routed }
    return $list
}

function Get-PortOwner([int]$Port) {
    $owners = @(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue |
        Select-Object -ExpandProperty OwningProcess -Unique)
    foreach ($id in $owners) {
        $p = Get-Process -Id $id -ErrorAction SilentlyContinue
        $path = ''
        try { $path = $p.Path } catch { }
        return [pscustomobject]@{ Id = $id; Name = $(if ($p) { $p.ProcessName } else { "PID $id" }); Path = $path }
    }
    return $null
}

# True when nothing listens on the port, or only this installation's own program does
function Test-PortAvailable([int]$Port, [string]$OwnExe) {
    $owner = Get-PortOwner $Port
    if (-not $owner) { return $true }
    return ($OwnExe -and $owner.Path -and ($owner.Path -ieq $OwnExe))
}

function Get-HttpsSuffix([int]$HttpsPort) { if ($HttpsPort -eq 443) { return '' } return ":$HttpsPort" }

# Checks the API answers. Returns $null when healthy, else the reason.
function Test-AppHealth([int]$HttpsPort, [string]$HostName = 'localhost') {
    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        $url = "https://$HostName$(Get-HttpsSuffix $HttpsPort)/api/auth/me"
        $response = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 15
        if ($response.Content -match '"status"') { return $null }
        return 'The web server answered, but not with the BSU Inventory API.'
    } catch {
        # 401 from /api/auth/me still means the app is running
        $r = $_.Exception.Response
        if ($r -and [int]$r.StatusCode -eq 401) { return $null }
        return $_.Exception.Message
    }
}

function Wait-AppHealthy([int]$HttpsPort, [int]$Seconds = 60) {
    $deadline = (Get-Date).AddSeconds($Seconds)
    $reason = 'not checked'
    while ((Get-Date) -lt $deadline) {
        $reason = Test-AppHealth $HttpsPort
        if (-not $reason) { return $null }
        Start-Sleep -Seconds 2
    }
    return $reason
}

# ---------------------------------------------------------------------------
#  Database client access as root (password from the admin-only secrets file)
# ---------------------------------------------------------------------------
function Invoke-DbRoot([object]$Paths, [string]$Sql, [string]$Label, [string]$Database = '') {
    # The SQL may hold a password: it goes in a file in the admin-only folder, never on a command line
    $file = Join-Path $Paths.SecretsDir ("run-{0}.sql" -f ([guid]::NewGuid().ToString('N')))
    Write-Text $file $Sql
    try {
        $dbArgs = @("--defaults-extra-file=$($Paths.RootCnf)", '--batch', '--skip-column-names')
        if ($Database) { $dbArgs += $Database }
        $dbArgs += @('-e', "source $($file.Replace('\', '/'))")
        return Invoke-Tool -Exe $Paths.MariadbCli -Arguments $dbArgs -Label $Label
    } finally {
        Remove-Item $file -Force -ErrorAction SilentlyContinue
    }
}

function Test-DbAlive([object]$Paths) {
    if (-not (Test-Path $Paths.RootCnf)) { return $false }
    $r = Invoke-Tool -Exe $Paths.MariadbAdmin -Arguments @("--defaults-extra-file=$($Paths.RootCnf)", '--connect-timeout=3', 'ping') -Quiet
    return ($r.Code -eq 0)
}

function Wait-DbAlive([object]$Paths, [int]$Seconds = 60) {
    $deadline = (Get-Date).AddSeconds($Seconds)
    while ((Get-Date) -lt $deadline) {
        if (Test-DbAlive $Paths) { return $true }
        Start-Sleep -Seconds 1
    }
    return $false
}

# ---------------------------------------------------------------------------
#  Status (Server Manager and launcher)
# ---------------------------------------------------------------------------
function Get-ServerStatus([object]$Paths) {
    $settings = Read-InstallSettings $Paths
    $rows = New-Object System.Collections.Generic.List[object]
    $add = { param($name, $ok, $detail) $rows.Add([pscustomobject]@{ Name = $name; Ok = $ok; Detail = $detail }) }

    $laragonOk = Test-Path (Join-Path $Paths.Laragon 'laragon.exe')
    & $add 'Laragon' $laragonOk $(if ($laragonOk) { "$($Paths.Laragon) (Laragon $($Paths.Components.laragon))" } else { 'missing' })

    $web = Get-BsuService $script:WebService
    & $add 'Web server (Apache)' ($web -and $web.Status -eq 'Running') $(if ($web) { "$($web.Status) - Apache $($Paths.Components.apacheVersion)" } else { 'service missing' })

    $phpOk = $false; $phpDetail = 'missing'
    if ($Paths.PhpExe -and (Test-Path $Paths.PhpExe)) {
        $v = (Invoke-Tool -Exe $Paths.PhpExe -Arguments @('-r', 'echo PHP_VERSION;') -Quiet).Output.Trim()
        $phpOk = ($v -match '^\d+\.\d+') -and ([version]($v -replace '[^0-9.].*$', '') -ge [version]'8.2')
        $phpDetail = "PHP $v" + $(if ($phpOk) { ' (compatible)' } else { ' - the system needs PHP 8.2 or newer' })
    }
    & $add 'PHP' $phpOk $phpDetail

    $db = Get-BsuService $script:DbService
    & $add 'Database server (MariaDB)' ($db -and $db.Status -eq 'Running') $(if ($db) { "$($db.Status) - MariaDB $($Paths.Components.mariadbVersion)" } else { 'service missing' })

    $dbOk = $false; $dbDetail = 'not checked (web server or database stopped)'
    if ($db -and $db.Status -eq 'Running' -and (Test-Path $Paths.PhpExe)) {
        $r = Invoke-Tool -Exe $Paths.PhpExe -Arguments @((Join-Path $Paths.App 'deploy\db-tool.php'), 'ping') -Quiet
        $dbOk = ($r.Code -eq 0)
        $dbDetail = $(if ($dbOk) { "connected to $($script:DbName) as the application's own account" } else { "cannot connect: $($r.Output)" })
    }
    & $add 'Database connection' $dbOk $dbDetail

    $httpsPort = 443
    if ($settings) { $httpsPort = [int]$settings.httpsPort }
    $appOk = $false; $appDetail = 'web server stopped'
    if ($web -and $web.Status -eq 'Running') {
        $reason = Test-AppHealth $httpsPort
        $appOk = -not $reason
        $appDetail = $(if ($appOk) { "answers on https://localhost$(Get-HttpsSuffix $httpsPort)/" } else { $reason })
    }
    & $add 'Application' $appOk $appDetail

    $lanEnabled = $true
    if ($settings) { $lanEnabled = [bool]$settings.lanAccess }
    if ($lanEnabled) {
        $rule = Get-NetFirewallRule -DisplayName 'BSU Inventory (web)' -ErrorAction SilentlyContinue
        $ruleOk = $rule -and ($rule.Enabled -eq 'True')
        $public = @(Get-NetConnectionProfile -ErrorAction SilentlyContinue | Where-Object { $_.NetworkCategory -eq 'Public' })
        $lanOk = $ruleOk -and $public.Count -eq 0
        $detail = if (-not $ruleOk) { 'the firewall rule "BSU Inventory (web)" is missing or off' }
                  elseif ($public.Count -gt 0) { "network set to Public (other PCs are blocked): $(($public | ForEach-Object { $_.InterfaceAlias }) -join ', ')" }
                  else { 'firewall open for the web ports on private/domain networks' }
        & $add 'LAN access' $lanOk $detail
    } else {
        & $add 'LAN access' $true 'turned off in the installer (this PC only)'
    }

    return [pscustomobject]@{ Rows = $rows; Settings = $settings; Addresses = @(Get-LanAddresses); HttpsPort = $httpsPort }
}
