<#
.SYNOPSIS
    Builds installer\BSU-Inventory-Setup.exe from this repository.

.DESCRIPTION
    Run on a developer PC with internet, Node.js 20.19+ (or 22.12+) and npm:

        powershell -ExecutionPolicy Bypass -File installer\installer-source\build.ps1

    1. downloads the pinned components (components.json) into .cache and checks each
       SHA-256: Laragon 6.0, PHP, Apache, MariaDB, the VC++ runtime, Composer,
       innoextract and Inno Setup (the last three are only used here, on the build PC)
    2. assembles BSU's Laragon copy: Laragon 6's manager and HeidiSQL, with its old
       Apache/PHP/MySQL and the developer extras (Node, Python, Git, ...) left out and the
       pinned Apache, PHP and MariaDB added
    3. the application, production only: the backend with `composer install --no-dev`
       (run by the bundled PHP, so the packages match the server's PHP), the Vue frontend
       built with `npm ci` + `npm run build` (only dist\ is shipped)
    4. the server scripts and the two launcher programs (compiled with the C# compiler that
       ships with Windows), and an icon from the BSU logo
    5. compiles BSU-Inventory.iss with Inno Setup

    The working tree is packaged as it is (uncommitted changes included). backend\.env,
    vendor\, writable\ contents, barcode images, tests and node_modules are never packaged.
.PARAMETER Clean
    Delete the previous staging folder (build\) first. The download cache is kept.
.PARAMETER AcceptNewVcRedist
    Microsoft replaced vc_redist.x64.exe (the aka.ms link always serves the newest): accept
    the new file if it carries a valid Microsoft signature, and record its checksum.
#>
[CmdletBinding()]
param([switch]$Clean, [switch]$AcceptNewVcRedist)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$Src      = $PSScriptRoot
$Repo     = Split-Path -Parent (Split-Path -Parent $Src)
$Cache    = Join-Path $Src '.cache'
$Build    = Join-Path $Src 'build'
$Stage    = Join-Path $Build 'stage'
$OutDir   = Split-Path -Parent $Src
$Laragon  = Join-Path $Stage 'laragon'
$AppStage = Join-Path $Laragon 'www\bsu-inventory'
$ComponentsFile = Join-Path $Src 'components.json'
$C = Get-Content $ComponentsFile -Raw | ConvertFrom-Json

function Step([string]$t) { Write-Host ''; Write-Host "==> $t" -ForegroundColor Cyan }
function Ok([string]$t)   { Write-Host "    [OK] $t" -ForegroundColor Green }

function Invoke-Native([string]$Exe, [string[]]$Arguments, [string]$Dir = '') {
    if ($Dir) { Push-Location $Dir }
    try {
        $previous = $ErrorActionPreference
        $ErrorActionPreference = 'Continue'
        & $Exe @Arguments 2>&1 | ForEach-Object { Write-Host "    $_" }
        $code = $LASTEXITCODE
        $ErrorActionPreference = $previous
        if ($code -ne 0) { throw "$Exe $($Arguments -join ' ') failed (exit $code)" }
    } finally { if ($Dir) { Pop-Location } }
}

function Get-Sha256([string]$Path) { return (Get-FileHash -Algorithm SHA256 -Path $Path).Hash.ToLowerInvariant() }

function Get-Component([string]$Name) {
    $c = $C.$Name
    $file = Join-Path $Cache $c.file
    if (-not (Test-Path $file)) {
        Write-Host "    downloading $($c.url)"
        $urls = @($c.url)
        if ($c.fallbackUrl) { $urls += $c.fallbackUrl }
        $done = $false
        foreach ($u in $urls) {
            try {
                Invoke-WebRequest -Uri $u -OutFile "$file.part" -UseBasicParsing -UserAgent 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) BSU-Inventory-Build'
                Move-Item "$file.part" $file -Force
                $done = $true
                break
            } catch { Write-Host "    ($u failed: $($_.Exception.Message))" }
        }
        if (-not $done) { throw "Could not download $Name." }
    }
    $hash = Get-Sha256 $file
    if ($hash -ne $c.sha256) {
        if ($Name -eq 'vcredist' -and $AcceptNewVcRedist) {
            $sig = Get-AuthenticodeSignature $file
            if ($sig.Status -ne 'Valid' -or $sig.SignerCertificate.Subject -notmatch 'O=Microsoft Corporation') { throw 'The new vc_redist.x64.exe is not validly signed by Microsoft.' }
            $raw = Get-Content $ComponentsFile -Raw
            Set-Content -Path $ComponentsFile -Value $raw.Replace($c.sha256, $hash) -Encoding UTF8 -NoNewline
            Ok "Accepted the new Microsoft-signed vc_redist.x64.exe ($hash), recorded in components.json"
        } else {
            Remove-Item $file -Force
            $hint = ''
            if ($Name -eq 'vcredist') { $hint = ' Microsoft probably released a new runtime: run build.ps1 -AcceptNewVcRedist.' }
            throw "Checksum mismatch for $($c.file): expected $($c.sha256), got $hash. The file was deleted.$hint"
        }
    }
    Ok "$Name $($c.version) ($($c.file), SHA-256 verified)"
    return $file
}

function Expand-Zip([string]$Zip, [string]$To) {
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    if (Test-Path $To) { Remove-Item $To -Recurse -Force }
    [System.IO.Compression.ZipFile]::ExtractToDirectory($Zip, $To)
}

function Copy-Tree([string]$From, [string]$To, [string[]]$ExcludeDirs = @(), [string[]]$ExcludeFiles = @()) {
    $rc = @($From, $To, '/E', '/NFL', '/NDL', '/NJH', '/NJS', '/NP', '/R:1', '/W:1')
    if ($ExcludeDirs.Count)  { $rc += '/XD'; $rc += $ExcludeDirs }
    if ($ExcludeFiles.Count) { $rc += '/XF'; $rc += $ExcludeFiles }
    & robocopy.exe @rc | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "Copying $From failed (robocopy $LASTEXITCODE)" }
}

# ---------------------------------------------------------------------------
Step 'Components'
New-Item -ItemType Directory -Force -Path $Cache | Out-Null
$files = @{}
foreach ($n in @('laragon', 'php', 'apache', 'mariadb', 'vcredist', 'composer', 'innoextract', 'innosetup')) { $files[$n] = Get-Component $n }

$tools = Join-Path $Cache 'tools'
$innoextract = Join-Path $tools 'innoextract\innoextract.exe'
if (-not (Test-Path $innoextract)) { Expand-Zip $files.innoextract (Join-Path $tools 'innoextract') }
$iscc = Join-Path $tools 'innosetup\ISCC.exe'
if (-not (Test-Path $iscc)) {
    # Portable, for this user only: nothing is registered on the build PC
    $p = Start-Process -FilePath $files.innosetup -ArgumentList @('/VERYSILENT', '/SUPPRESSMSGBOXES', '/NORESTART', '/SP-', '/CURRENTUSER', '/PORTABLE=1', '/NOICONS', "/DIR=`"$(Join-Path $tools 'innosetup')`"") -Wait -PassThru
    if ($p.ExitCode -ne 0 -or -not (Test-Path $iscc)) { throw 'Could not unpack Inno Setup.' }
}
Ok 'Build tools ready (innoextract, Inno Setup)'

$node = (Get-Command node -ErrorAction SilentlyContinue)
if (-not $node) { throw 'Node.js is needed to build the frontend (20.19+ or 22.12+).' }
$nodeVersion = [version]((& node -v).TrimStart('v'))
if (-not (($nodeVersion.Major -eq 20 -and $nodeVersion -ge [version]'20.19') -or $nodeVersion -ge [version]'22.12')) {
    throw "Node.js $nodeVersion is too old for Vite 8 (needs 20.19+ or 22.12+)."
}
Ok "Node.js $nodeVersion"

# ---------------------------------------------------------------------------
Step 'Staging folder'
if ($Clean -and (Test-Path $Build)) { Remove-Item $Build -Recurse -Force }
if (Test-Path $Stage) { Remove-Item $Stage -Recurse -Force }
New-Item -ItemType Directory -Force -Path $Stage | Out-Null

# ---------------------------------------------------------------------------
Step "Laragon $($C.laragon.version) (BSU's own copy)"
$laragonX = Join-Path $Cache "x\laragon-$($C.laragon.version)"
if (-not (Test-Path (Join-Path $laragonX 'app\laragon.exe'))) {
    if (Test-Path $laragonX) { Remove-Item $laragonX -Recurse -Force }
    New-Item -ItemType Directory -Force -Path $laragonX | Out-Null
    Invoke-Native $innoextract @('-s', '-d', $laragonX, $files.laragon)
}
# Kept: the Laragon manager, HeidiSQL (database browser), its own helper files.
# Left out: its 2022 Apache/PHP/MySQL (replaced below) and developer tools the server does
# not need (Node.js, Python, Git, Nginx, Redis, Memcached, ngrok, Notepad++, Cmder, Composer...).
$dropBins = @('apache', 'mysql', 'php', 'nginx', 'nodejs', 'python', 'git', 'ngrok', 'redis', 'memcached', 'notepad++', 'cmder', 'composer', 'sendmail', 'telnet', 'yarn', 'cronical')
Copy-Tree (Join-Path $laragonX 'app') $Laragon -ExcludeDirs ($dropBins | ForEach-Object { Join-Path $laragonX "app\bin\$_" }) -ExcludeFiles @('VC_redist.x64.exe')
Remove-Item (Join-Path $Laragon 'www\*') -Recurse -Force -ErrorAction SilentlyContinue
foreach ($d in @('bin\apache', 'bin\php', 'bin\mysql', 'www', 'tmp', 'data')) { New-Item -ItemType Directory -Force -Path (Join-Path $Laragon $d) | Out-Null }
Ok 'Laragon manager and HeidiSQL staged'

Step "PHP $($C.php.version)"
$phpDir = Join-Path $Laragon "bin\php\$($C.php.folder)"
Expand-Zip $files.php $phpDir
foreach ($x in @('dev', 'phpdbg.exe', 'php8phpdbg.dll', 'php8embed.lib', 'php-cgi.exe', 'ext\php_dl_test.dll', 'ext\php_zend_test.dll')) {
    Remove-Item (Join-Path $phpDir $x) -Recurse -Force -ErrorAction SilentlyContinue
}
Ok "PHP staged in bin\php\$($C.php.folder)"

Step "Apache $($C.apache.version)"
$tmpApache = Join-Path $Build 'apache-x'
Expand-Zip $files.apache $tmpApache
$apacheDir = Join-Path $Laragon "bin\apache\$($C.apache.folder)"
Move-Item (Join-Path $tmpApache 'Apache24') $apacheDir
foreach ($x in @('htdocs', 'manual', 'cgi-bin', 'include', 'lib', 'logs\*')) { Remove-Item (Join-Path $apacheDir $x) -Recurse -Force -ErrorAction SilentlyContinue }
Copy-Item (Join-Path $tmpApache 'ReadMe.txt') (Join-Path $apacheDir 'ReadMe-ApacheLounge.txt') -ErrorAction SilentlyContinue
Remove-Item $tmpApache -Recurse -Force
Ok "Apache staged in bin\apache\$($C.apache.folder)"

Step "MariaDB $($C.mariadb.version)"
$tmpMaria = Join-Path $Build 'mariadb-x'
Expand-Zip $files.mariadb $tmpMaria
$mariaDir = Join-Path $Laragon "bin\mysql\$($C.mariadb.folder)"
Move-Item (Join-Path $tmpMaria $C.mariadb.folder) $mariaDir
Remove-Item $tmpMaria -Recurse -Force
# Legacy mysql* copies of the mariadb* tools (mysqld.exe stays: Laragon looks for it),
# backup/benchmark/debug tools the system does not use, and C headers
Get-ChildItem (Join-Path $mariaDir 'bin') -Filter 'mysql*.exe' | Where-Object { $_.Name -ne 'mysqld.exe' } | Remove-Item -Force
foreach ($x in @('include', 'bin\mariabackup.exe', 'bin\mariadb-backup.exe', 'bin\mbstream.exe', 'bin\mariadb-ldb.exe', 'bin\sst_dump.exe',
                 'bin\mariadb-slap.exe', 'bin\mariadb-upgrade-wizard.exe', 'bin\aria_dump_log.exe', 'bin\aria_ftdump.exe', 'bin\aria_pack.exe',
                 'bin\aria_read_log.exe', 'bin\myisam_ftdump.exe', 'bin\myisamlog.exe', 'bin\myisampack.exe')) {
    Remove-Item (Join-Path $mariaDir $x) -Recurse -Force -ErrorAction SilentlyContinue
}
Get-ChildItem $mariaDir -Recurse -Filter '*.pdb' | Remove-Item -Force
Ok "MariaDB staged in bin\mysql\$($C.mariadb.folder)"

$components = [ordered]@{
    laragon = $C.laragon.version
    apache = $C.apache.folder; apacheVersion = $C.apache.version
    php = $C.php.folder; phpVersion = $C.php.version
    mariadb = $C.mariadb.folder; mariadbVersion = $C.mariadb.version
}
[System.IO.File]::WriteAllText((Join-Path $Laragon 'usr\bsu-components.json'), ($components | ConvertTo-Json), (New-Object System.Text.UTF8Encoding $false))

# ---------------------------------------------------------------------------
Step 'Backend (CodeIgniter, production packages only)'
$backendSrc = Join-Path $Repo 'backend'
$backendStage = Join-Path $AppStage 'backend'
Copy-Tree $backendSrc $backendStage `
    -ExcludeDirs @((Join-Path $backendSrc 'vendor'), (Join-Path $backendSrc 'writable'), (Join-Path $backendSrc 'tests'), (Join-Path $backendSrc 'public\barcodes'), 'node_modules', '.git') `
    -ExcludeFiles @('.env', 'env', 'phpunit.xml.dist', 'dev-router.php', '*.log', '*.sql', '*.bsubackup')
# Empty writable folders with their access rules (index.html / .htaccess), no data
& robocopy.exe (Join-Path $backendSrc 'writable') (Join-Path $backendStage 'writable') 'index.html' '.htaccess' /S /NFL /NDL /NJH /NJS /NP | Out-Null
foreach ($d in @('cache', 'logs', 'session', 'uploads', 'imports', 'backups', 'debugbar')) { New-Item -ItemType Directory -Force -Path (Join-Path $backendStage "writable\$d") | Out-Null }
New-Item -ItemType Directory -Force -Path (Join-Path $backendStage 'public\barcodes') | Out-Null
& robocopy.exe (Join-Path $backendSrc 'public\barcodes') (Join-Path $backendStage 'public\barcodes') 'index.html' '.htaccess' /NFL /NDL /NJH /NJS /NP | Out-Null

# Composer runs with the bundled PHP, so platform checks match the server
$buildIni = Join-Path $Build 'php-build.ini'
[System.IO.File]::WriteAllText($buildIni, "extension_dir=`"$(Join-Path $phpDir 'ext')`"`r`nextension=openssl`r`nextension=mbstring`r`nextension=intl`r`nextension=zip`r`nextension=curl`r`nextension=fileinfo`r`nmemory_limit=1G`r`n")
$env:COMPOSER_HOME = Join-Path $Cache 'composer-home'
$env:COMPOSER_CACHE_DIR = Join-Path $Cache 'composer-cache'
$env:COMPOSER_NO_INTERACTION = '1'
Invoke-Native (Join-Path $phpDir 'php.exe') @('-c', $buildIni, $files.composer, 'install', '--no-dev', '--optimize-autoloader', '--prefer-dist', '--no-progress', '--no-interaction') $backendStage
Invoke-Native (Join-Path $phpDir 'php.exe') @('-c', $buildIni, $files.composer, 'check-platform-reqs', '--no-dev') $backendStage
Ok 'Backend staged with production packages'

Step 'Frontend (Vue production build)'
$feBuild = Join-Path $Build 'frontend-src'
$feSrc = Join-Path $Repo 'frontend'
Copy-Tree $feSrc $feBuild -ExcludeDirs @((Join-Path $feSrc 'node_modules'), (Join-Path $feSrc 'dist'))
Invoke-Native 'npm.cmd' @('ci', '--no-audit', '--no-fund') $feBuild
Invoke-Native 'npm.cmd' @('run', 'build') $feBuild
Copy-Tree (Join-Path $feBuild 'dist') (Join-Path $AppStage 'frontend\dist')
Ok 'frontend\dist staged (no sources, no node_modules)'

# Supporting files
New-Item -ItemType Directory -Force -Path (Join-Path $AppStage 'deploy') | Out-Null
Copy-Item (Join-Path $Repo 'deploy\db-tool.php') (Join-Path $AppStage 'deploy\db-tool.php')
# Inert under the installed Apache configuration (AllowOverride None); protects the folder if
# Laragon's own Apache is ever started on it
Copy-Item (Join-Path $Repo '.htaccess') (Join-Path $AppStage '.htaccess')
$appVersion = (Get-Content (Join-Path $Repo 'frontend\package.json') -Raw | ConvertFrom-Json).version
[System.IO.File]::WriteAllText((Join-Path $AppStage 'VERSION'), $appVersion)

# ---------------------------------------------------------------------------
Step 'Server scripts, launchers and icon'
$manager = Join-Path $Stage 'manager'
New-Item -ItemType Directory -Force -Path (Join-Path $manager 'templates') | Out-Null
Copy-Item (Join-Path $Src 'scripts\*.ps1') $manager
Copy-Item (Join-Path $Src 'templates\*') (Join-Path $manager 'templates')

# Icon from the BSU logo: PNG images of 16-256 px in one .ico
Add-Type -AssemblyName System.Drawing
$logo = [System.Drawing.Image]::FromFile((Join-Path $Repo 'frontend\src\assets\images\bsu-logo.png'))
$icoPath = Join-Path $manager 'bsu-inventory.ico'
$images = @()
foreach ($size in @(256, 64, 48, 32, 16)) {
    $bmp = New-Object System.Drawing.Bitmap $size, $size
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.InterpolationMode = 'HighQualityBicubic'
    $g.Clear([System.Drawing.Color]::Transparent)
    $scale = [Math]::Min($size / $logo.Width, $size / $logo.Height)
    $w = [int]($logo.Width * $scale); $h = [int]($logo.Height * $scale)
    $g.DrawImage($logo, [int](($size - $w) / 2), [int](($size - $h) / 2), $w, $h)
    $ms = New-Object System.IO.MemoryStream
    $bmp.Save($ms, [System.Drawing.Imaging.ImageFormat]::Png)
    $images += , @{ Size = $size; Bytes = $ms.ToArray() }
    $g.Dispose(); $bmp.Dispose()
}
$logo.Dispose()
$fs = [System.IO.File]::Create($icoPath)
$bw = New-Object System.IO.BinaryWriter $fs
$bw.Write([uint16]0); $bw.Write([uint16]1); $bw.Write([uint16]$images.Count)
$offset = 6 + 16 * $images.Count
foreach ($i in $images) {
    $dim = $i.Size; if ($dim -ge 256) { $dim = 0 }
    $bw.Write([byte]$dim); $bw.Write([byte]$dim); $bw.Write([byte]0); $bw.Write([byte]0)
    $bw.Write([uint16]1); $bw.Write([uint16]32); $bw.Write([uint32]$i.Bytes.Length); $bw.Write([uint32]$offset)
    $offset += $i.Bytes.Length
}
foreach ($i in $images) { $bw.Write($i.Bytes) }
$bw.Close()

$csc = Join-Path $env:WINDIR 'Microsoft.NET\Framework64\v4.0.30319\csc.exe'
$stub = Join-Path $Src 'stub\Launcher.cs'
Invoke-Native $csc @('/nologo', '/target:winexe', '/optimize+', "/win32icon:$icoPath", "/out:$(Join-Path $manager 'BSU-Inventory.exe')", $stub)
Invoke-Native $csc @('/nologo', '/target:winexe', '/optimize+', "/win32icon:$icoPath", "/win32manifest:$(Join-Path $Src 'stub\admin.manifest')", "/out:$(Join-Path $manager 'BSU-Inventory-Manager.exe')", $stub)
Copy-Item $files.vcredist (Join-Path $Stage 'vc_redist.x64.exe')
Ok 'Launchers compiled; icon created'

# ---------------------------------------------------------------------------
Step "Compiling the installer (BSU Inventory System $appVersion)"
Invoke-Native $iscc @('/Q', "/DAppVersion=$appVersion", "/DStageDir=$Stage", "/DOutputDir=$OutDir",
    "/DLaragonVersion=$($C.laragon.version)", "/DApacheVersion=$($C.apache.version)", "/DPhpVersion=$($C.php.version)", "/DMariaDbVersion=$($C.mariadb.version)",
    (Join-Path $Src 'BSU-Inventory.iss'))
$setup = Join-Path $OutDir 'BSU-Inventory-Setup.exe'
$hash = Get-Sha256 $setup
[System.IO.File]::WriteAllText("$setup.sha256", "$hash  BSU-Inventory-Setup.exe`r`n")
Ok ("{0} ({1:N0} MB), SHA-256 {2}" -f $setup, ((Get-Item $setup).Length / 1MB), $hash)
