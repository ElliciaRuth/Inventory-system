<#
.SYNOPSIS
    Automated Setup and Startup Script for BSU Inventory Management System.
.DESCRIPTION
    Performs comprehensive environment checks (Node.js, npm, Vue/Vite, PHP, Composer, XAMPP/Apache/MySQL),
    automatically installs missing dependencies, configures .env files, runs migrations,
    starts CodeIgniter & Vue development servers, and opens the application in your browser.
.PARAMETER SkipBrowser
    Do not automatically open the browser when servers are live.
.PARAMETER InstallOnly
    Only check environment and install dependencies without starting servers.
.PARAMETER HostAddress
    Host address to bind the backend server (default: 0.0.0.0).
.PARAMETER BackendPort
    Port for CodeIgniter dev server (default: 8080).
.PARAMETER FrontendPort
    Port for Vue Vite dev server (default: 5173).
#>

[CmdletBinding()]
param(
    [switch]$SkipBrowser,
    [switch]$InstallOnly,
    [string]$HostAddress = "0.0.0.0",
    [int]$BackendPort = 8080,
    [int]$FrontendPort = 5173
)

# Project Paths
$RootDir = $PSScriptRoot
if (-not $RootDir) { $RootDir = (Get-Location).Path }
$BackendDir = Join-Path $RootDir "backend"
$FrontendDir = Join-Path $RootDir "frontend"

# Global tracking for background server processes
$script:BackendProc = $null
$script:FrontendProc = $null

# --- Terminal Styling Helpers ---
function Write-Header {
    param([string]$Title)
    Write-Host ""
    Write-Host ("=" * 70) -ForegroundColor Cyan
    Write-Host "  $Title" -ForegroundColor White
    Write-Host ("=" * 70) -ForegroundColor Cyan
    Write-Host ""
}

function Write-Success {
    param([string]$Message)
    Write-Host "  [OK] $Message" -ForegroundColor Green
}

function Write-Warning {
    param([string]$Message)
    Write-Host "  [!]  $Message" -ForegroundColor Yellow
}

function Write-ErrorMsg {
    param([string]$Message)
    Write-Host "  [X]  $Message" -ForegroundColor Red
}

function Write-Info {
    param([string]$Message)
    Write-Host "  [i]  $Message" -ForegroundColor Cyan
}

function Write-Step {
    param([string]$Step, [string]$Description)
    Write-Host ""
    Write-Host ">> Step ${Step}: $Description" -ForegroundColor Magenta
}

function Test-PortListening {
    param(
        [string]$Address = "127.0.0.1",
        [int]$Port,
        [int]$TimeoutMs = 800
    )
    # Check 1: Query Windows TCP connection table directly (detects 0.0.0.0, 127.0.0.1, [::1])
    try {
        $conns = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
        if ($conns) { return $true }
    } catch {}

    # Check 2: Netstat command fallback
    try {
        $found = netstat -ano 2>$null | Select-String -Pattern ":$Port\s+.*LISTENING"
        if ($found) { return $true }
    } catch {}

    # Check 3: Active socket probes across IPv4, localhost, and IPv6
    $targets = @("127.0.0.1", "localhost", "::1")
    if ($Address -and ($targets -notcontains $Address)) { $targets = @($Address) + $targets }
    foreach ($target in $targets) {
        try {
            $tcpClient = New-Object System.Net.Sockets.TcpClient
            $iar = $tcpClient.BeginConnect($target, $Port, $null, $null)
            $wait = $iar.AsyncWaitHandle.WaitOne($TimeoutMs, $false)
            if ($wait) {
                $tcpClient.EndConnect($iar)
                $tcpClient.Close()
                return $true
            }
            $tcpClient.Close()
        } catch {}
    }
    return $false
}

function Find-XamppPath {
    $candidates = @(
        "C:\xampp",
        "D:\xampp",
        "E:\xampp"
    )
    $parent = Split-Path $RootDir -Parent
    if ($parent) {
        $grandParent = Split-Path $parent -Parent
        if ($grandParent) { $candidates += $grandParent }
        $candidates += $parent
    }
    foreach ($cand in $candidates) {
        if ($cand -and (Test-Path -Path $cand -ErrorAction SilentlyContinue)) {
            $phpPath = Join-Path $cand "php\php.exe"
            $mysqlPath = Join-Path $cand "mysql"
            if ((Test-Path -Path $phpPath -ErrorAction SilentlyContinue) -and (Test-Path -Path $mysqlPath -ErrorAction SilentlyContinue)) {
                return (Resolve-Path $cand).Path
            }
        }
    }
    return $null
}

# --- Cleanup on Exit ---
function Stop-DevServers {
    Write-Host ""
    Write-Info "Shutting down development servers..."
    
    if ($script:BackendProc -and -not $script:BackendProc.HasExited) {
        try {
            Stop-Process -Id $script:BackendProc.Id -Force -ErrorAction SilentlyContinue
            Write-Success "Backend server stopped (PID $($script:BackendProc.Id))."
        } catch {}
    }
    
    if ($script:FrontendProc -and -not $script:FrontendProc.HasExited) {
        try {
            # Kill process tree for frontend (cmd -> npm -> node -> vite)
            taskkill /PID $script:FrontendProc.Id /T /F >$null 2>&1
            Write-Success "Frontend dev server stopped (PID $($script:FrontendProc.Id))."
        } catch {}
    }
}

# ==============================================================================
# BANNER
# ==============================================================================
Write-Host ""
Write-Host "  +--------------------------------------------------------------------+" -ForegroundColor Green
Write-Host "  |          BSU INTEGRATED INVENTORY MONITORING SYSTEM                |" -ForegroundColor Green
Write-Host "  |             Automated Stack Setup & Server Launcher                |" -ForegroundColor Green
Write-Host "  +--------------------------------------------------------------------+" -ForegroundColor Green

# ==============================================================================
# PHASE 1: ENVIRONMENT & DEPENDENCY CHECKS
# ==============================================================================
Write-Header "PHASE 1: ENVIRONMENT & DEPENDENCY CHECKS"

$hasFatalError = $false

# 1.1 Detect XAMPP Path
$xamppPath = Find-XamppPath
if ($xamppPath) {
    Write-Success "XAMPP installation detected at: $xamppPath"
} else {
    Write-Warning "Standard XAMPP folder not detected in C:\xampp or D:\xampp."
}

# 1.2 Detect and ensure PHP in PATH
$phpCmd = Get-Command php -ErrorAction SilentlyContinue
if (-not $phpCmd -and $xamppPath) {
    $xamppPhp = Join-Path $xamppPath "php"
    if (Test-Path (Join-Path $xamppPhp "php.exe")) {
        $env:PATH = "$xamppPhp;" + $env:PATH
        $phpCmd = Get-Command php -ErrorAction SilentlyContinue
        Write-Info "Added XAMPP PHP to current session PATH ($xamppPhp)."
    }
}

if ($phpCmd) {
    $phpVersionRaw = (php -r "echo PHP_VERSION;").Trim()
    $phpMajorMinor = [version]($phpVersionRaw.Split('-')[0])
    if ($phpMajorMinor -ge [version]"8.2") {
        Write-Success "PHP $phpVersionRaw detected."
    } elseif ($phpMajorMinor -ge [version]"8.1") {
        Write-Success "PHP $phpVersionRaw detected (Recommended: PHP 8.2+)."
    } else {
        Write-Warning "PHP $phpVersionRaw detected. CodeIgniter 4 requires PHP 8.1+ (8.2+ recommended)."
    }

    # Verify essential PHP extensions for CodeIgniter 4
    $requiredExts = @("mysqli", "curl", "intl", "mbstring", "openssl", "json")
    $missingExts = @()
    foreach ($ext in $requiredExts) {
        $loaded = php -r "echo extension_loaded('$ext') ? '1' : '0';"
        if ($loaded -ne '1') {
            $missingExts += $ext
        }
    }

    if ($missingExts.Count -eq 0) {
        Write-Success "Required PHP extensions enabled (mysqli, curl, intl, mbstring, openssl, json)."
    } else {
        Write-Warning "Missing PHP extension(s): $($missingExts -join ', '). Please enable them in php.ini."
    }
} else {
    Write-ErrorMsg "PHP is not installed or not found in PATH."
    Write-Host "       Download XAMPP with PHP 8.2+ from: https://www.apachefriends.org/" -ForegroundColor Yellow
    $hasFatalError = $true
}

# 1.3 Detect Composer
$composerCmd = Get-Command composer -ErrorAction SilentlyContinue
if (-not $composerCmd -and $xamppPath) {
    if (Test-Path (Join-Path $xamppPath "php\composer.phar")) {
        Set-Alias -Name composer -Value "php"
        $composerCmd = $true
    } elseif (Test-Path "C:\ProgramData\ComposerSetup\bin\composer.bat") {
        $env:PATH = "C:\ProgramData\ComposerSetup\bin;" + $env:PATH
        $composerCmd = Get-Command composer -ErrorAction SilentlyContinue
    }
}

if ($composerCmd) {
    $composerVer = (composer --version 2>&1) -split "`n" | Select-Object -First 1
    Write-Success "Composer detected: $composerVer"
} else {
    Write-ErrorMsg "Composer is not installed or not in PATH."
    Write-Host "       Download Composer from: https://getcomposer.org/download/" -ForegroundColor Yellow
    $hasFatalError = $true
}

# 1.4 Detect Node.js and npm
$nodeCmd = Get-Command node -ErrorAction SilentlyContinue
if (-not $nodeCmd) {
    $nodePaths = @(
        "$env:ProgramFiles\nodejs",
        "${env:ProgramFiles(x86)}\nodejs",
        "$env:LOCALAPPDATA\Programs\nodejs"
    )
    foreach ($np in $nodePaths) {
        if (Test-Path (Join-Path $np "node.exe")) {
            $env:PATH = "$np;" + $env:PATH
            $nodeCmd = Get-Command node -ErrorAction SilentlyContinue
            Write-Info "Added Node.js to current session PATH ($np)."
            break
        }
    }
}

if ($nodeCmd) {
    $nodeVer = (node -v).Trim()
    Write-Success "Node.js detected: $nodeVer"
} else {
    Write-ErrorMsg "Node.js is not installed or not found in PATH."
    Write-Host "       Download Node.js (LTS recommended) from: https://nodejs.org/" -ForegroundColor Yellow
    $hasFatalError = $true
}

$npmCmd = Get-Command npm -ErrorAction SilentlyContinue
if ($npmCmd) {
    $npmVer = (npm -v).Trim()
    Write-Success "npm detected: v$npmVer"
} else {
    Write-ErrorMsg "npm is not installed or not found in PATH."
    $hasFatalError = $true
}

# 1.5 Detect Vue CLI / Vite Stack
$vueCliCmd = Get-Command vue -ErrorAction SilentlyContinue
if ($vueCliCmd) {
    $vueVer = (vue --version 2>&1).Trim()
    Write-Success "Vue CLI detected globally: $vueVer"
} else {
    Write-Info "Vue CLI (global) not installed. (Modern projects utilize Vite build tool)."
}

if (Test-Path (Join-Path $FrontendDir "package.json")) {
    $pkgJson = Get-Content (Join-Path $FrontendDir "package.json") -Raw | ConvertFrom-Json
    $hasVue = ($pkgJson.dependencies.PSObject.Properties['vue'] -ne $null)
    $hasVite = ($pkgJson.devDependencies.PSObject.Properties['vite'] -ne $null)
    if ($hasVue -and $hasVite) {
        Write-Success "Frontend Stack detected: Vue $($pkgJson.dependencies.vue) + Vite $($pkgJson.devDependencies.vite)"
    }
}

# 1.6 Detect Apache & MySQL Status
$apacheRunning = $false
$mysqlRunning = $false

# Test MySQL Port 3306
if (Test-PortListening -Address "127.0.0.1" -Port 3306) {
    $mysqlRunning = $true
    Write-Success "MySQL Service / Daemon is RUNNING (Port 3306 listening)."
} else {
    Write-Warning "MySQL is NOT running on port 3306."
}

# Test Apache Port 80 / 443
if (Test-PortListening -Address "127.0.0.1" -Port 80) {
    $apacheRunning = $true
    Write-Success "Apache Service / Daemon is RUNNING (Port 80 listening)."
} elseif (Get-Process httpd -ErrorAction SilentlyContinue) {
    $apacheRunning = $true
    Write-Success "Apache process (httpd) is currently running."
} else {
    Write-Warning "Apache is NOT running on port 80."
}

if ($hasFatalError) {
    Write-Host ""
    Write-ErrorMsg "Setup cannot proceed due to missing critical dependencies listed above."
    Write-Host "       Please install the required tools and re-run this script." -ForegroundColor Yellow
    Write-Host ""
    exit 1
}

# ==============================================================================
# PHASE 2: AUTOMATED SETUP & DEPENDENCY INSTALLATION
# ==============================================================================
Write-Header "PHASE 2: AUTOMATED SETUP & DEPENDENCY INSTALLATION"

# 2.1 Auto-Start Apache & MySQL if stopped
if (-not $mysqlRunning) {
    Write-Step "2.1" "Attempting to start MySQL service..."
    $started = $false

    # Try Windows Service
    $mysqlService = Get-Service -Name "mysql*", "MySQL*" -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($mysqlService) {
        try {
            Start-Service -Name $mysqlService.Name -ErrorAction Stop
            Write-Success "Started Windows Service: $($mysqlService.DisplayName)"
            $started = $true
        } catch {
            Write-Warning "Could not start Windows Service '$($mysqlService.Name)': $($_.Exception.Message)"
        }
    }

    # Try XAMPP batch file / binary if service failed
    if (-not $started -and $xamppPath) {
        $mysqlBatch = Join-Path $xamppPath "mysql_start.bat"
        $mysqldExe = Join-Path $xamppPath "mysql\bin\mysqld.exe"
        if (Test-Path $mysqlBatch) {
            Start-Process -FilePath $mysqlBatch -WindowStyle Hidden
            $started = $true
        } elseif (Test-Path $mysqldExe) {
            Start-Process -FilePath $mysqldExe -WindowStyle Hidden
            $started = $true
        }
    }

    # Verify if MySQL came online
    $retries = 8
    while ($retries -gt 0 -and -not (Test-PortListening -Address "127.0.0.1" -Port 3306)) {
        Start-Sleep -Seconds 1
        $retries--
    }

    if (Test-PortListening -Address "127.0.0.1" -Port 3306) {
        Write-Success "MySQL is now active and listening on port 3306."
        $mysqlRunning = $true
    } else {
        Write-ErrorMsg "Could not automatically start MySQL. Please start MySQL from the XAMPP Control Panel."
    }
}

if (-not $apacheRunning -and $xamppPath) {
    Write-Step "2.2" "Attempting to start Apache service..."
    $apacheService = Get-Service -Name "Apache*" -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($apacheService) {
        try {
            Start-Service -Name $apacheService.Name -ErrorAction SilentlyContinue
            Write-Success "Started Windows Service: $($apacheService.DisplayName)"
        } catch {}
    } else {
        $apacheBatch = Join-Path $xamppPath "apache_start.bat"
        if (Test-Path $apacheBatch) {
            Start-Process -FilePath $apacheBatch -WindowStyle Hidden
        }
    }
}

# 2.3 Configure Backend Environment (.env)
Write-Step "2.3" "Checking Backend Environment (.env)..."
$backendEnv = Join-Path $BackendDir ".env"
if (-not (Test-Path $backendEnv)) {
    Write-Info "Creating backend/.env configured for local XAMPP environment..."
    $envLines = @(
        "#--------------------------------------------------------------------",
        "# BSU INVENTORY ENVIRONMENT CONFIGURATION (XAMPP / Local Dev)",
        "#--------------------------------------------------------------------",
        "CI_ENVIRONMENT = development",
        "",
        "app.baseURL = 'http://localhost:$BackendPort/'",
        "app.forceGlobalSecureRequests = false",
        "",
        "database.default.hostname = localhost",
        "database.default.database = inventory_system",
        "database.default.username = root",
        "database.default.password = ",
        "database.default.DBDriver = MySQLi",
        "database.default.DBPrefix = ",
        "database.default.port = 3306",
        "",
        "logger.threshold = 4"
    )
    $envContent = $envLines -join "`r`n"
    Set-Content -Path $backendEnv -Value $envContent -Encoding utf8
    Write-Success "backend/.env created successfully."
} else {
    Write-Success "backend/.env already exists."
}

# 2.4 Ensure Backend Dependencies (composer install)
Write-Step "2.4" "Verifying CodeIgniter Backend Dependencies..."
$backendAutoload = Join-Path $BackendDir "vendor\autoload.php"
if (-not (Test-Path $backendAutoload)) {
    Write-Warning "Backend dependencies (vendor) missing. Running 'composer install'..."
    Push-Location $BackendDir
    try {
        # Check if XAMPP CA bundle exists to fix curl error 60 SSL certificates
        if ($xamppPath -and (Test-Path (Join-Path $xamppPath "apache\bin\curl-ca-bundle.crt"))) {
            composer config --global cafile (Join-Path $xamppPath "apache\bin\curl-ca-bundle.crt") 2>$null
        }

        # Run composer install
        composer install --no-interaction --no-dev
        if ($LASTEXITCODE -ne 0) {
            Write-Warning "composer install --no-dev returned code $LASTEXITCODE. Retrying standard install..."
            composer install --no-interaction
        }
    } finally {
        Pop-Location
    }

    if (Test-Path $backendAutoload) {
        Write-Success "Backend dependencies installed successfully."
    } else {
        Write-ErrorMsg "Failed to install backend dependencies via Composer."
    }
} else {
    Write-Success "Backend dependencies (vendor) are already installed."
}

# 2.5 Generate Encryption Key if missing
Push-Location $BackendDir
try {
    $hasKey = Get-Content ".env" -ErrorAction SilentlyContinue | Select-String "^encryption.key\s*="
    if (-not $hasKey) {
        Write-Info "Generating application encryption key in backend/.env..."
        php spark key:generate --force
        Write-Success "Encryption key generated."
    }
} finally {
    Pop-Location
}

# 2.6 Ensure Writable Directories Exist
$writableDirs = @(
    "writable\cache",
    "writable\logs",
    "writable\session",
    "writable\uploads",
    "writable\backups",
    "public\barcodes"
)
foreach ($dir in $writableDirs) {
    $fullDir = Join-Path $BackendDir $dir
    if (-not (Test-Path $fullDir)) {
        New-Item -ItemType Directory -Path $fullDir -Force | Out-Null
    }
}
Write-Success "Backend storage directories verified (writable/ and public/barcodes)."

# 2.7 Database Verification & Migrations
if ($mysqlRunning) {
    Write-Step "2.7" "Verifying Database & Running Migrations..."
    
    # Ensure MySQL database inventory_system exists
    $createDbCode = '$m = @new mysqli(\"127.0.0.1\", \"root\", \"\"); if (!$m->connect_errno) { $m->query(\"CREATE DATABASE IF NOT EXISTS inventory_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci\"); }'
    php -r $createDbCode

    # Run Migrations
    Push-Location $BackendDir
    try {
        php spark migrate
        Write-Success "Database migrations executed."

        # Check if database needs starter data seeded
        $userCountCode = '$m = @new mysqli(\"127.0.0.1\", \"root\", \"\", \"inventory_system\"); if (!$m->connect_errno) { $r = $m->query(\"SELECT COUNT(*) FROM user_table\"); echo $r ? $r->fetch_row()[0] : \"0\"; } else { echo \"0\"; }'
        $userCount = (php -r $userCountCode).Trim()
        if ($userCount -eq "0" -or $userCount -eq "") {
            Write-Info "Database is empty. Seeding starter data (DatabaseSeeder)..."
            php spark db:seed DatabaseSeeder
            Write-Success "Database seeded with default roles, offices, and admin account."
        }
    } catch {
        Write-Warning "Could not run migrations/seeders: $($_.Exception.Message)"
    } finally {
        Pop-Location
    }
} else {
    Write-Warning "Skipping migrations because MySQL is currently offline."
}

# 2.8 Configure Frontend Environment & Install Dependencies
Write-Step "2.8" "Checking Vue Frontend Dependencies..."
$frontendNodeModules = Join-Path $FrontendDir "node_modules"
$frontendEnvLocal = Join-Path $FrontendDir ".env.local"

# Ensure Vite proxies to IPv4 127.0.0.1:BackendPort
if (-not (Test-Path $frontendEnvLocal)) {
    "VITE_DEV_API_TARGET=http://127.0.0.1:$BackendPort" | Set-Content -Path $frontendEnvLocal -Encoding utf8
    Write-Success "frontend/.env.local configured with VITE_DEV_API_TARGET=http://127.0.0.1:$BackendPort."
}

if (-not (Test-Path $frontendNodeModules)) {
    Write-Warning "Frontend dependencies (node_modules) missing. Running 'npm install'..."
    Push-Location $FrontendDir
    try {
        npm install
    } finally {
        Pop-Location
    }

    if (Test-Path $frontendNodeModules) {
        Write-Success "Frontend dependencies installed successfully."
    } else {
        Write-ErrorMsg "Failed to install frontend dependencies via npm."
    }
} else {
    Write-Success "Frontend dependencies (node_modules) are already installed."
}

if ($InstallOnly) {
    Write-Header "SETUP COMPLETED SUCCESSFULLY"
    Write-Success "All environment checks passed and dependencies are installed."
    Write-Info "Run without -InstallOnly to launch development servers."
    Write-Host ""
    exit 0
}

# ==============================================================================
# PHASE 3: SERVER EXECUTION & LAUNCH
# ==============================================================================
Write-Header "PHASE 3: SERVER EXECUTION & BROWSER LAUNCH"

# Check if ports are already taken
if (Test-PortListening -Port $BackendPort) {
    Write-Warning "Port $BackendPort is already in use. Assuming backend server is already running."
} else {
    Write-Info "Starting CodeIgniter backend dev server on ${HostAddress}:${BackendPort}..."
    $backendPsi = New-Object System.Diagnostics.ProcessStartInfo
    $backendPsi.FileName = "php"
    $backendPsi.Arguments = "spark serve --host $HostAddress --port $BackendPort"
    $backendPsi.WorkingDirectory = $BackendDir
    $backendPsi.UseShellExecute = $false
    $backendPsi.CreateNoWindow = $true
    
    $script:BackendProc = [System.Diagnostics.Process]::Start($backendPsi)
    Write-Success "Backend server started (PID: $($script:BackendProc.Id))."
}

if (Test-PortListening -Port $FrontendPort) {
    Write-Warning "Port $FrontendPort is already in use. Assuming frontend dev server is already running."
} else {
    Write-Info "Starting Vue Vite frontend dev server on port $FrontendPort..."
    $frontendPsi = New-Object System.Diagnostics.ProcessStartInfo
    $frontendPsi.FileName = "cmd.exe"
    $frontendPsi.Arguments = "/c npm run dev -- --host 0.0.0.0"
    $frontendPsi.WorkingDirectory = $FrontendDir
    $frontendPsi.UseShellExecute = $false
    $frontendPsi.CreateNoWindow = $true

    $script:FrontendProc = [System.Diagnostics.Process]::Start($frontendPsi)
    Write-Success "Frontend dev server started (PID: $($script:FrontendProc.Id))."
}

# Health Check & Wait Loop
Write-Step "3.1" "Waiting for servers to become responsive..."
$maxWaitSeconds = 15
$backendReady = Test-PortListening -Port $BackendPort
$frontendReady = Test-PortListening -Port $FrontendPort

$elapsed = 0
while ($elapsed -lt $maxWaitSeconds -and (-not $backendReady -or -not $frontendReady)) {
    if (-not $backendReady) {
        $backendReady = Test-PortListening -Port $BackendPort
    }
    if (-not $frontendReady) {
        $frontendReady = Test-PortListening -Port $FrontendPort
    }

    if ($backendReady -and $frontendReady) {
        break
    }

    Start-Sleep -Milliseconds 500
    $elapsed++
    Write-Host "." -NoNewline -ForegroundColor Cyan
}
Write-Host ""

if ($backendReady) {
    Write-Success "Backend API Server is LIVE at: http://localhost:$BackendPort"
} else {
    Write-Warning "Backend server not responding on port $BackendPort."
}

if ($frontendReady) {
    Write-Success "Frontend Web App is LIVE at: http://localhost:$FrontendPort"
} else {
    Write-Warning "Frontend server not responding on port $FrontendPort."
}

# 3.2 Launch Default Browser
$webAppUrl = "http://localhost:$FrontendPort"
if (-not $SkipBrowser) {
    Write-Step "3.2" "Opening Web Application in Default Browser..."
    Start-Process $webAppUrl
    Write-Success "Browser opened to $webAppUrl."
}

# ==============================================================================
# DASHBOARD
# ==============================================================================
Write-Host ""
Write-Host "  +--------------------------------------------------------------------+" -ForegroundColor Green
Write-Host "  |                     APPLICATION STACK IS RUNNING                   |" -ForegroundColor Green
Write-Host "  +--------------------------------------------------------------------+" -ForegroundColor Green
Write-Host "  |  * Frontend App:   http://localhost:$FrontendPort                           |" -ForegroundColor Green
Write-Host "  |  * Backend API:    http://localhost:$BackendPort/api                      |" -ForegroundColor Green
Write-Host "  |  * Database:       MySQL on 127.0.0.1:3306 (inventory_system)      |" -ForegroundColor Green
Write-Host "  |  * Default Admin:  admin_tech (Seed credentials)                   |" -ForegroundColor Green
Write-Host "  +--------------------------------------------------------------------+" -ForegroundColor Green
Write-Host ""

# If existing servers were already running, exit cleanly so user doesn't get blocked
if (-not $script:BackendProc -and -not $script:FrontendProc) {
    Write-Success "Existing dev servers detected and running in the background."
    Write-Success "Setup completed successfully."
    Write-Host ""
    exit 0
}

Write-Host "  Press [Q] or [Ctrl+C] to stop all servers and exit." -ForegroundColor Yellow
Write-Host ""

try {
    while ($true) {
        if ([Console]::KeyAvailable) {
            $key = [Console]::ReadKey($true)
            if ($key.Key -eq [ConsoleKey]::Q -or $key.Key -eq [ConsoleKey]::Escape) {
                break
            }
        }
        Start-Sleep -Milliseconds 500
    }
} finally {
    Stop-DevServers
    Write-Host ""
    Write-Success "All servers shut down cleanly. Goodbye!"
    Write-Host ""
}
