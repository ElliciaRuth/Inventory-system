<#
.SYNOPSIS
    Offline HTTPS server setup for the BSU Inventory System on XAMPP. Run by setup.bat.

.DESCRIPTION
    Needs no internet at any point. On this PC it:
      1. checks XAMPP (Apache with SSL, PHP 8.2+, MySQL/MariaDB) and the prepared project
         folder (backend\vendor and frontend\dist, made by "setup.bat --prepare" on a PC
         with internet)
      2. starts MySQL, makes it reachable from this PC only, creates the database
      3. creates backend\.env if missing (production) and its encryption key
      4. runs the database migrations, and the seeder on an empty database
      5. creates a local certificate authority (once) and an HTTPS certificate for this PC's
         addresses with XAMPP's own OpenSSL, and trusts that authority on this PC
      6. configures Apache: the app on https://<this PC>/, plain HTTP redirected there
      7. registers Apache and MySQL as Windows services that start with Windows, and opens
         ports 80 and 443 in the Windows firewall for private/domain networks

    Safe to run again, e.g. after the PC's IP address changed: the certificate is renewed for
    the new address and devices keep working without installing anything again.

.PARAMETER XamppPath
    XAMPP folder. Default: C:\xampp (or D:\xampp).
.PARAMETER NewCertificate
    Issue a new HTTPS certificate even when the current one is still valid.
.PARAMETER NoServices
    Don't register Apache/MySQL as Windows services (they won't start with Windows).
.PARAMETER NoPause
    Don't wait for a key press at the end.
#>
[CmdletBinding()]
param(
    [string]$XamppPath = '',
    [switch]$NewCertificate,
    [switch]$NoServices,
    [switch]$NoPause
)

$ErrorActionPreference = 'Stop'
$AppRoot  = Split-Path -Parent $PSScriptRoot
$Backend  = Join-Path $AppRoot 'backend'
$Frontend = Join-Path $AppRoot 'frontend'
$Utf8NoBom = New-Object System.Text.UTF8Encoding $false

# ---------------------------------------------------------------------------
#  Output helpers
# ---------------------------------------------------------------------------
function Write-Step([string]$Text) { Write-Host ''; Write-Host "==> $Text" -ForegroundColor Cyan }
function Write-Ok([string]$Text)   { Write-Host "    [OK] $Text" -ForegroundColor Green }
function Write-Note([string]$Text) { Write-Host "    [i]  $Text" -ForegroundColor Gray }
function Write-Warn([string]$Text) { Write-Host "    [!]  $Text" -ForegroundColor Yellow }

function Wait-ForKey {
    if (-not $NoPause) {
        Write-Host ''
        Read-Host 'Press Enter to close this window' | Out-Null
    }
}

# Runs a program, returns its exit code and output. Native programs write progress to
# stderr; that must not count as a PowerShell error.
function Invoke-Tool {
    param([string]$Exe, [string[]]$Arguments, [string]$WorkingDirectory = '')
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
    return [pscustomobject]@{ Code = $code; Output = ($output -join "`n") }
}

function Read-Text([string]$Path) { return [System.IO.File]::ReadAllText($Path) }
function Write-Text([string]$Path, [string]$Text) { [System.IO.File]::WriteAllText($Path, $Text, $Utf8NoBom) }

# ---------------------------------------------------------------------------
#  Administrator rights: Apache configuration, Windows services, certificates, firewall
# ---------------------------------------------------------------------------
$identity = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
if (-not $identity.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host 'Administrator rights are needed (Apache configuration, certificates, firewall).'
    Write-Host 'Windows will ask for permission; the setup continues in a new window.'
    $arguments = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$PSCommandPath`"")
    if ($XamppPath)      { $arguments += @('-XamppPath', "`"$XamppPath`"") }
    if ($NewCertificate) { $arguments += '-NewCertificate' }
    if ($NoServices)     { $arguments += '-NoServices' }
    try {
        $process = Start-Process -FilePath 'powershell.exe' -ArgumentList $arguments -Verb RunAs -Wait -PassThru
    } catch {
        Write-Host 'The setup was cancelled (administrator permission was not given).' -ForegroundColor Yellow
        exit 1
    }
    exit $process.ExitCode
}

try {
    Write-Host '========================================================================'
    Write-Host '  BSU INVENTORY SYSTEM - OFFLINE HTTPS SERVER SETUP (XAMPP)'
    Write-Host '========================================================================'

    # -----------------------------------------------------------------------
    #  1. XAMPP and the prepared project folder
    # -----------------------------------------------------------------------
    Write-Step 'Checking XAMPP and the project files'

    if (-not $XamppPath) {
        foreach ($candidate in @('C:\xampp', 'D:\xampp')) {
            if (Test-Path (Join-Path $candidate 'apache\bin\httpd.exe')) { $XamppPath = $candidate; break }
        }
    }
    if (-not $XamppPath -or -not (Test-Path (Join-Path $XamppPath 'apache\bin\httpd.exe'))) {
        throw 'XAMPP was not found in C:\xampp. Install XAMPP (PHP 8.2 or newer) from its offline installer, or run: setup.bat -XamppPath "D:\path\to\xampp".'
    }
    $XamppPath  = (Resolve-Path $XamppPath).Path
    $Httpd      = Join-Path $XamppPath 'apache\bin\httpd.exe'
    $OpenSsl    = Join-Path $XamppPath 'apache\bin\openssl.exe'
    $Php        = Join-Path $XamppPath 'php\php.exe'
    $PhpIni     = Join-Path $XamppPath 'php\php.ini'
    $Mysqld     = Join-Path $XamppPath 'mysql\bin\mysqld.exe'
    $MyIni      = Join-Path $XamppPath 'mysql\bin\my.ini'
    $HttpdConf  = Join-Path $XamppPath 'apache\conf\httpd.conf'
    $CertDir    = Join-Path $XamppPath 'apache\conf\bsu-inventory'
    foreach ($tool in @($OpenSsl, $Php, $Mysqld, $MyIni, $HttpdConf)) {
        if (-not (Test-Path $tool)) { throw "This XAMPP installation is incomplete: $tool is missing." }
    }
    Write-Ok "XAMPP found in $XamppPath"

    $phpVersion = (Invoke-Tool $Php @('-r', 'echo PHP_VERSION;')).Output.Trim()
    if ([version]($phpVersion -replace '[^0-9.].*$', '') -lt [version]'8.2') {
        throw "XAMPP's PHP is $phpVersion; the system needs PHP 8.2 or newer. Install a newer XAMPP."
    }
    Write-Ok "PHP $phpVersion"

    # PHP extensions the backend needs; XAMPP ships them all, some only commented out
    $modules = (Invoke-Tool $Php @('-m')).Output
    $iniText = Read-Text $PhpIni
    $iniChanged = $false
    foreach ($ext in @('intl', 'mbstring', 'mysqli', 'openssl', 'curl', 'zip', 'gd')) {
        if ($modules -notmatch "(?mi)^$ext\s*$") {
            $pattern = "(?mi)^\s*;\s*extension\s*=\s*(php_)?$ext(\.dll)?\s*$"
            if ($iniText -match $pattern) {
                $iniText = [regex]::Replace($iniText, $pattern, "extension=$ext")
                $iniChanged = $true
                Write-Ok "Enabled PHP extension $ext in php.ini"
            } elseif (@('intl', 'mbstring', 'mysqli', 'openssl') -contains $ext) {
                throw "The PHP extension $ext is missing and could not be enabled in $PhpIni."
            }
        }
    }
    # OPcache keeps the compiled PHP code in memory between requests; XAMPP ships it off.
    # Changed files are still picked up within seconds (validate_timestamps stays on).
    if ($iniText -notmatch '(?m)^\s*zend_extension\s*=\s*(php_)?opcache') {
        if ($iniText -match '(?m)^\s*;\s*zend_extension\s*=\s*(php_)?opcache(\.dll)?\s*$') {
            $iniText = [regex]::Replace($iniText, '(?m)^\s*;\s*zend_extension\s*=\s*(php_)?opcache(\.dll)?\s*$', 'zend_extension=opcache')
        } else {
            $iniText = $iniText.TrimEnd() + "`r`n`r`n[opcache]`r`nzend_extension=opcache`r`n"
        }
        $iniChanged = $true
    }
    if ($iniText -notmatch '(?m)^\s*opcache\.enable\s*=\s*1') {
        if ($iniText -match '(?m)^\s*;?\s*opcache\.enable\s*=.*$') {
            $iniText = [regex]::Replace($iniText, '(?m)^\s*;?\s*opcache\.enable\s*=.*$', 'opcache.enable=1', 1)
        } else {
            $iniText = $iniText.TrimEnd() + "`r`nopcache.enable=1`r`n"
        }
        $iniChanged = $true
        Write-Ok 'Enabled PHP OPcache (faster responses)'
    }
    if ($iniChanged) {
        Copy-Item $PhpIni "$PhpIni.before-bsu-inventory" -ErrorAction SilentlyContinue
        Write-Text $PhpIni $iniText
    }

    if (-not (Test-Path (Join-Path $Backend 'vendor\autoload.php')) -or -not (Test-Path (Join-Path $Frontend 'dist\index.html'))) {
        throw ("The project folder is not prepared: backend\vendor or frontend\dist is missing. " +
            "On a PC with internet run 'setup.bat --prepare' in the project folder, then copy the WHOLE folder " +
            "(including backend\vendor and frontend\dist) to this PC and run setup.bat again.")
    }
    Write-Ok 'Project folder is prepared (backend\vendor, frontend\dist)'

    # Ports 80/443 must be free for Apache (IIS, Skype or VMware sometimes take them)
    foreach ($port in @(80, 443)) {
        $owners = Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue |
            Select-Object -ExpandProperty OwningProcess -Unique
        foreach ($owner in $owners) {
            $name = (Get-Process -Id $owner -ErrorAction SilentlyContinue).ProcessName
            if ($name -and $name -ne 'httpd') {
                throw "Port $port is used by another program ($name, process $owner). Stop or uninstall it, then run setup.bat again."
            }
        }
    }
    Write-Ok 'Ports 80 and 443 are free for Apache'

    # -----------------------------------------------------------------------
    #  2. MySQL: running, reachable from this PC only, database created
    # -----------------------------------------------------------------------
    Write-Step 'MySQL (MariaDB)'

    $myText = Read-Text $MyIni
    $mysqlConfigChanged = $false
    # Only the [mysqld] section; "bind-address" there makes the database refuse other PCs
    $mysqldSection = [regex]::Match($myText, '(?ms)^\[mysqld\]\s*$(.*?)(?=^\[|\z)')
    if ($mysqldSection.Success -and $mysqldSection.Groups[1].Value -notmatch '(?m)^\s*bind-address\s*=') {
        Copy-Item $MyIni "$MyIni.before-bsu-inventory" -ErrorAction SilentlyContinue
        $myText = [regex]::Replace($myText, '(?m)^\[mysqld\]\s*$', "[mysqld]`r`n# Only this PC may connect (added by the BSU Inventory setup)`r`nbind-address=127.0.0.1", 1)
        Write-Text $MyIni $myText
        $mysqlConfigChanged = $true
        Write-Ok 'MySQL now accepts connections from this PC only (bind-address=127.0.0.1)'
    }

    $mysqlService = Get-Service -Name 'mysql' -ErrorAction SilentlyContinue
    if (-not $mysqlService -and -not $NoServices) {
        Get-Process -Name 'mysqld' -ErrorAction SilentlyContinue | Stop-Process -Force
        $result = Invoke-Tool $Mysqld @('--install', 'mysql', "--defaults-file=$MyIni")
        if ($result.Code -ne 0) { throw "Could not register MySQL as a Windows service:`n$($result.Output)" }
        $mysqlService = Get-Service -Name 'mysql'
        Write-Ok 'MySQL registered as a Windows service'
    }
    if ($mysqlService) {
        if (-not $NoServices) { Set-Service -Name 'mysql' -StartupType Automatic }
        if ($mysqlService.Status -eq 'Running' -and $mysqlConfigChanged) {
            Restart-Service -Name 'mysql' -Force
        } elseif ($mysqlService.Status -ne 'Running') {
            Start-Service -Name 'mysql'
        }
    } else {
        if ($mysqlConfigChanged) { Get-Process -Name 'mysqld' -ErrorAction SilentlyContinue | Stop-Process -Force; Start-Sleep -Seconds 2 }
        if (-not (Get-Process -Name 'mysqld' -ErrorAction SilentlyContinue)) {
            Start-Process -FilePath $Mysqld -ArgumentList "--defaults-file=`"$MyIni`"" -WindowStyle Hidden
        }
    }

    # -----------------------------------------------------------------------
    #  3. backend\.env
    # -----------------------------------------------------------------------
    Write-Step 'Backend settings (backend\.env)'

    $envFile = Join-Path $Backend '.env'
    if (-not (Test-Path $envFile)) {
        $lines = @(
            '# Created by the offline setup (setup.bat).',
            '# production hides error details and the debug toolbar from the network.',
            'CI_ENVIRONMENT = production',
            '',
            "app.baseURL = 'https://localhost/'",
            '',
            'database.default.hostname = 127.0.0.1',
            'database.default.database = inventory_system',
            'database.default.username = root',
            'database.default.password = ',
            'database.default.DBDriver = MySQLi',
            'database.default.DBPrefix = ',
            'database.default.port = 3306',
            '',
            'logger.threshold = 4'
        )
        Write-Text $envFile (($lines -join "`r`n") + "`r`n")
        Write-Ok 'Created backend\.env (production)'
    } else {
        $envText = Read-Text $envFile
        # "localhost" makes PHP try IPv6 (::1) first. MySQL listens on 127.0.0.1 only (see above),
        # and on Windows a refused connection takes about 2 seconds before the IPv4 retry:
        # that delay would hit every request.
        if ($envText -match '(?m)^\s*database\.default\.hostname\s*=\s*[''"]?localhost[''"]?\s*$') {
            $envText = [regex]::Replace($envText, '(?m)^(\s*database\.default\.hostname\s*=\s*)[''"]?localhost[''"]?\s*$', '${1}127.0.0.1')
            Write-Text $envFile $envText
            Write-Ok 'Database host set to 127.0.0.1 ("localhost" waits about 2 seconds per connection on Windows)'
        }
        if ($envText -match '(?m)^\s*CI_ENVIRONMENT\s*=\s*development') {
            Write-Warn 'backend\.env has CI_ENVIRONMENT = development: error details are shown to every device.'
            Write-Warn 'On a PC other people use, change it to CI_ENVIRONMENT = production.'
        }
    }
    if ((Read-Text $envFile) -notmatch '(?m)^\s*encryption\.key\s*=\s*\S') {
        $result = Invoke-Tool $Php @('spark', 'key:generate', '--force') $Backend
        if ($result.Code -ne 0) { throw "Could not create the encryption key:`n$($result.Output)" }
        Write-Ok 'Created the encryption key'
    }

    # -----------------------------------------------------------------------
    #  4. Database: create, migrate, seed an empty one
    # -----------------------------------------------------------------------
    Write-Step 'Database'

    $dbTool = Join-Path $PSScriptRoot 'db-tool.php'
    $ready = $false
    for ($i = 0; $i -lt 30; $i++) {
        if ((Invoke-Tool $Php @($dbTool, 'ping')).Code -eq 0) { $ready = $true; break }
        Start-Sleep -Seconds 2
    }
    if (-not $ready) {
        throw "MySQL does not answer. Start it in the XAMPP Control Panel and check $XamppPath\mysql\data\mysql_error.log."
    }
    $result = Invoke-Tool $Php @($dbTool, 'create')
    if ($result.Code -ne 0) { throw "Could not create the database:`n$($result.Output)" }

    $result = Invoke-Tool $Php @('spark', 'migrate') $Backend
    if ($result.Code -ne 0) { throw "The database migrations failed:`n$($result.Output)" }
    Write-Ok 'Database is up to date (migrations)'

    if ((Invoke-Tool $Php @($dbTool, 'users')).Output.Trim() -eq '0') {
        $result = Invoke-Tool $Php @('spark', 'db:seed', 'DatabaseSeeder') $Backend
        if ($result.Code -ne 0) { throw "Seeding the empty database failed:`n$($result.Output)" }
        Write-Ok 'Empty database seeded with the starting data and accounts'
    }

    # -----------------------------------------------------------------------
    #  5. Certificates (XAMPP's OpenSSL; nothing is downloaded)
    # -----------------------------------------------------------------------
    Write-Step 'HTTPS certificates'

    New-Item -ItemType Directory -Force -Path $CertDir | Out-Null
    $caKey     = Join-Path $CertDir 'bsu-inventory-ca.key'
    $caCrt     = Join-Path $CertDir 'bsu-inventory-ca.crt'
    $serverKey = Join-Path $CertDir 'server.key'
    $serverCrt = Join-Path $CertDir 'server.crt'
    $namesFile = Join-Path $CertDir 'server-names.txt'
    $caNameFile = Join-Path $CertDir 'ca-hostname.txt'
    $computer  = $env:COMPUTERNAME.ToLowerInvariant()

    # The authority may only vouch for private network addresses and this PC's own names
    # (name constraints). Even if its key were stolen it could not impersonate real websites
    # on the phones and PCs that trust it.
    if (-not (Test-Path $caKey) -or -not (Test-Path $caCrt)) {
        $caConfig = @"
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
        $caConfigFile = Join-Path $CertDir 'ca.cnf'
        Write-Text $caConfigFile $caConfig
        $env:OPENSSL_CONF = $caConfigFile
        $result = Invoke-Tool $OpenSsl @('req', '-x509', '-newkey', 'rsa:3072', '-nodes', '-sha256', '-days', '3650',
            '-keyout', $caKey, '-out', $caCrt, '-config', $caConfigFile)
        if ($result.Code -ne 0) { throw "Could not create the certificate authority:`n$($result.Output)" }
        Write-Text $caNameFile $computer
        # The authority key stays with administrators only
        Invoke-Tool 'icacls.exe' @($caKey, '/inheritance:r', '/grant:r', '*S-1-5-32-544:F', '*S-1-5-18:F') | Out-Null
        Write-Ok 'Created the local certificate authority (valid 10 years)'
        $NewCertificate = $true
    }
    $caHostname = if (Test-Path $caNameFile) { (Read-Text $caNameFile).Trim() } else { $computer }

    # This PC's private IPv4 addresses (all adapters: LAN, Wi-Fi, mobile hotspot 192.168.137.1)
    $isPrivate = {
        param($ip)
        $o = $ip.Split('.') | ForEach-Object { [int]$_ }
        ($o[0] -eq 10) -or ($o[0] -eq 172 -and $o[1] -ge 16 -and $o[1] -le 31) -or ($o[0] -eq 192 -and $o[1] -eq 168) -or ($o[0] -eq 127)
    }
    $addresses = @(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object { $_.IPAddress -notlike '169.254.*' -and (& $isPrivate $_.IPAddress) } |
        Select-Object -ExpandProperty IPAddress)
    $addresses = @($addresses + '127.0.0.1' | Sort-Object -Unique)
    $dnsNames  = @('localhost')
    if ($caHostname -eq $computer) { $dnsNames += $computer }
    $wanted = (($addresses | ForEach-Object { "IP:$_" }) + ($dnsNames | ForEach-Object { "DNS:$_" })) -join ', '

    $renew = $NewCertificate -or -not (Test-Path $serverCrt) -or -not (Test-Path $serverKey) -or
        -not (Test-Path $namesFile) -or ((Read-Text $namesFile).Trim() -ne $wanted)
    if (-not $renew) {
        # Renew 30 days before it expires
        $renew = (Invoke-Tool $OpenSsl @('x509', '-checkend', '2592000', '-noout', '-in', $serverCrt)).Code -ne 0
    }
    if ($renew) {
        $reqConfigFile = Join-Path $CertDir 'server-req.cnf'
        $extConfigFile = Join-Path $CertDir 'server-ext.cnf'
        Write-Text $reqConfigFile "[req]`ndistinguished_name = dn`nprompt = no`n[dn]`nCN = $($dnsNames[-1])`n"
        Write-Text $extConfigFile ("[ext]`nbasicConstraints = critical, CA:FALSE`nkeyUsage = critical, digitalSignature, keyEncipherment`n" +
            "extendedKeyUsage = serverAuth`nsubjectAltName = $wanted`nauthorityKeyIdentifier = keyid`nsubjectKeyIdentifier = hash`n")
        $env:OPENSSL_CONF = $reqConfigFile
        $csr = Join-Path $CertDir 'server.csr'
        $result = Invoke-Tool $OpenSsl @('req', '-new', '-newkey', 'rsa:2048', '-nodes', '-keyout', $serverKey, '-out', $csr, '-config', $reqConfigFile)
        if ($result.Code -ne 0) { throw "Could not create the server key:`n$($result.Output)" }
        # 825 days: the longest validity Apple devices accept for a locally trusted certificate
        $result = Invoke-Tool $OpenSsl @('x509', '-req', '-in', $csr, '-CA', $caCrt, '-CAkey', $caKey, '-CAcreateserial',
            '-out', $serverCrt, '-days', '825', '-sha256', '-extfile', $extConfigFile, '-extensions', 'ext')
        if ($result.Code -ne 0) { throw "Could not issue the server certificate:`n$($result.Output)" }
        Remove-Item $csr -ErrorAction SilentlyContinue
        $verify = Invoke-Tool $OpenSsl @('verify', '-CAfile', $caCrt, $serverCrt)
        if ($verify.Code -ne 0) { throw "The new certificate does not verify:`n$($verify.Output)" }
        Write-Text $namesFile $wanted
        Write-Ok "Issued the HTTPS certificate for: $wanted"
    } else {
        Write-Ok 'The HTTPS certificate is current for this PC''s addresses'
    }
    Remove-Item Env:\OPENSSL_CONF -ErrorAction SilentlyContinue

    # This PC's browsers trust the authority (Windows certificate store, all users)
    $caCert = New-Object System.Security.Cryptography.X509Certificates.X509Certificate2 $caCrt
    if (-not (Get-ChildItem Cert:\LocalMachine\Root | Where-Object { $_.Thumbprint -eq $caCert.Thumbprint })) {
        Import-Certificate -FilePath $caCrt -CertStoreLocation Cert:\LocalMachine\Root | Out-Null
        Write-Ok 'This PC now trusts the local certificate authority'
    }
    $sha = [System.Security.Cryptography.SHA256]::Create()
    $fingerprint = (($sha.ComputeHash($caCert.RawData) | ForEach-Object { $_.ToString('X2') }) -join ':')

    # -----------------------------------------------------------------------
    #  6. Apache
    # -----------------------------------------------------------------------
    Write-Step 'Apache (HTTPS)'

    $conf = Read-Text $HttpdConf
    $originalConf = $conf
    foreach ($module in @('ssl', 'rewrite', 'headers', 'alias', 'socache_shmcb', 'dir', 'mime', 'filter', 'deflate', 'http2')) {
        $pattern = "(?m)^\s*#\s*(LoadModule\s+$($module)_module\s+\S+)"
        if ($conf -notmatch "(?m)^\s*LoadModule\s+$($module)_module\s" -and $conf -match $pattern) {
            $conf = [regex]::Replace($conf, $pattern, '$1')
            Write-Ok "Enabled Apache module $module"
        }
    }

    # Ports XAMPP already listens on (httpd.conf, and httpd-ssl.conf when it is included)
    $listenText = $conf
    $sslConf = Join-Path $XamppPath 'apache\conf\extra\httpd-ssl.conf'
    if ($conf -match '(?m)^\s*Include\s+"?conf/extra/httpd-ssl\.conf"?' -and (Test-Path $sslConf)) {
        $listenText += "`n" + (Read-Text $sslConf)
    }
    $extraListen = @()
    foreach ($port in @(80, 443)) {
        if ($listenText -notmatch "(?m)^\s*Listen\s+(\S+:)?$port\s*$") { $extraListen += "Listen $port" }
    }

    $toApache = { param($path) $path.Replace('\', '/') }
    $template = Read-Text (Join-Path $PSScriptRoot 'apache\bsu-inventory.conf.template')
    $values = [ordered]@{
        '{{APP_ROOT}}'          = (& $toApache $AppRoot)
        '{{CERT_DIR}}'          = (& $toApache $CertDir)
        '{{HTDOCS}}'            = (& $toApache (Join-Path $XamppPath 'htdocs'))
        '{{HTTPS_PORT_SUFFIX}}' = ''
        '{{HTTP_PORT}}'         = '80'
        '{{HTTPS_PORT}}'        = '443'
        '{{EXTRA_LISTEN}}'      = ($extraListen -join "`r`n")
    }
    $rendered = $template
    foreach ($key in $values.Keys) { $rendered = $rendered.Replace($key, $values[$key]) }
    if ($rendered -match '\{\{[A-Z_]+\}\}') { throw "The Apache template has an unknown placeholder: $($Matches[0])" }
    Write-Text (Join-Path $CertDir 'bsu-inventory.conf') $rendered

    # One Include line, before XAMPP's own virtual hosts, so this site answers by default
    $includeLine = 'Include "conf/bsu-inventory/bsu-inventory.conf"'
    if ($conf -notmatch [regex]::Escape($includeLine)) {
        $anchor = [regex]::Match($conf, '(?m)^\s*Include\s+"?conf/extra/httpd-vhosts\.conf"?\s*$')
        if (-not $anchor.Success) { $anchor = [regex]::Match($conf, '(?m)^\s*Include\s+"?conf/extra/httpd-ssl\.conf"?\s*$') }
        $block = "# BSU Inventory System over HTTPS (added by its setup.bat)`r`n$includeLine`r`n"
        if ($anchor.Success) {
            $conf = $conf.Insert($anchor.Index, $block)
        } else {
            $conf = $conf.TrimEnd() + "`r`n`r`n" + $block
        }
    }

    if ($conf -ne $originalConf) {
        $backup = "$HttpdConf.before-bsu-inventory"
        if (-not (Test-Path $backup)) { Copy-Item $HttpdConf $backup }
        Write-Text $HttpdConf $conf
    }

    $check = Invoke-Tool $Httpd @('-t')
    if ($check.Output -notmatch 'Syntax OK') {
        Write-Text $HttpdConf $originalConf
        throw "Apache rejected the configuration, so httpd.conf was put back as it was:`n$($check.Output)"
    }
    Write-Ok 'Apache configuration checked (Syntax OK)'

    $apacheService = Get-Service -Name 'Apache2.4' -ErrorAction SilentlyContinue
    if (-not $apacheService -and -not $NoServices) {
        Get-Process -Name 'httpd' -ErrorAction SilentlyContinue | Stop-Process -Force
        Start-Sleep -Seconds 1
        $result = Invoke-Tool $Httpd @('-k', 'install', '-n', 'Apache2.4')
        if ($result.Code -ne 0) { throw "Could not register Apache as a Windows service:`n$($result.Output)" }
        $apacheService = Get-Service -Name 'Apache2.4'
        Write-Ok 'Apache registered as a Windows service'
    }
    if ($apacheService) {
        if (-not $NoServices) { Set-Service -Name 'Apache2.4' -StartupType Automatic }
        if ($apacheService.Status -eq 'Running') { Restart-Service -Name 'Apache2.4' -Force } else { Start-Service -Name 'Apache2.4' }
    } else {
        Get-Process -Name 'httpd' -ErrorAction SilentlyContinue | Stop-Process -Force
        Start-Sleep -Seconds 1
        Start-Process -FilePath $Httpd -WindowStyle Hidden
    }
    Start-Sleep -Seconds 2
    Write-Ok 'Apache restarted'

    # -----------------------------------------------------------------------
    #  7. Firewall
    # -----------------------------------------------------------------------
    Write-Step 'Windows firewall'

    $ruleName = 'BSU Inventory (HTTPS)'
    if (-not (Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue)) {
        New-NetFirewallRule -DisplayName $ruleName -Direction Inbound -Protocol TCP -LocalPort 80, 443 `
            -Action Allow -Profile Private, Domain | Out-Null
        Write-Ok 'Allowed ports 80 and 443 on private and domain networks'
    } else {
        Write-Ok 'Firewall rule already present'
    }
    $public = @(Get-NetConnectionProfile -ErrorAction SilentlyContinue | Where-Object { $_.NetworkCategory -eq 'Public' })
    if ($public.Count -gt 0) {
        Write-Warn "These networks are set to Public, where other devices are blocked: $(($public | ForEach-Object { $_.InterfaceAlias }) -join ', ')"
        Write-Warn 'If this is the office network, set it to Private in Windows Settings > Network.'
    }

    # -----------------------------------------------------------------------
    #  Check: the API answers over HTTPS with the new certificate
    # -----------------------------------------------------------------------
    Write-Step 'Checking https://localhost'
    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        $response = Invoke-WebRequest -Uri 'https://localhost/api/auth/me' -UseBasicParsing -TimeoutSec 20
        if ($response.Content -match '"status"') {
            Write-Ok 'The system answers over HTTPS'
        } else {
            Write-Warn 'Apache answered, but not with the system''s API. Check Apache''s error log.'
        }
    } catch {
        Write-Warn "The check failed: $($_.Exception.Message)"
        Write-Warn "See $XamppPath\apache\logs\error.log and bsu-inventory-error.log."
    }

    # -----------------------------------------------------------------------
    #  How to connect
    # -----------------------------------------------------------------------
    $lan = @($addresses | Where-Object { $_ -ne '127.0.0.1' })
    Write-Host ''
    Write-Host '========================================================================' -ForegroundColor Green
    Write-Host '  SETUP COMPLETE - the system runs over HTTPS, without internet' -ForegroundColor Green
    Write-Host '========================================================================' -ForegroundColor Green
    Write-Host ''
    Write-Host '  On this PC:           https://localhost/'
    foreach ($ip in $lan) { Write-Host "  On other devices:     https://$ip/" }
    Write-Host ''
    Write-Host '  ONCE PER DEVICE (phones, tablets, other PCs) - trust this server first:'
    if ($lan.Count -gt 0) { Write-Host "    1. Open  http://$($lan[0])/bsu-inventory-ca.crt  and download the file." }
    Write-Host '    2. Install it as a trusted certificate authority:'
    Write-Host '       Windows: double-click > Install Certificate > Local Machine >'
    Write-Host '                "Trusted Root Certification Authorities".'
    Write-Host '       Android: Settings > Security > Encryption & credentials >'
    Write-Host '                Install a certificate > CA certificate.'
    Write-Host '       iPhone/iPad: open the file and install the profile, then Settings > General >'
    Write-Host '                About > Certificate Trust Settings > switch it on.'
    Write-Host '    3. Check the fingerprint the device shows matches (SHA-256):'
    Write-Host "       $fingerprint" -ForegroundColor White
    Write-Host ''
    Write-Host '  The certificate authority can only vouch for this PC and private network addresses,'
    Write-Host '  never for real websites. Its key stays in:'
    Write-Host "    $CertDir   (back it up; never share it)"
    Write-Host ''
    Write-Host '  If this PC gets a new IP address, run setup.bat again: devices keep working'
    Write-Host '  without installing anything again. Ask for a fixed IP (DHCP reservation).'
    Write-Host ''
    Write-Host '  Forgotten passwords: "Forgot password" emails a code through the user''s own email'
    Write-Host '  account; this PC needs internet only while it sends. Without internet, a manager'
    Write-Host '  sets a new password in Others Management > Users, or run in the backend folder:'
    Write-Host "    $Php spark user:reset-password <username>"
    Write-Host ''
    Wait-ForKey
    exit 0
} catch {
    Write-Host ''
    Write-Host "[X] $($_.Exception.Message)" -ForegroundColor Red
    Write-Host ''
    Write-Host 'Nothing else was changed after this point. Fix the problem above and run setup.bat again.'
    Wait-ForKey
    exit 1
}
