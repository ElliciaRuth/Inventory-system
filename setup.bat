@echo off
setlocal EnableExtensions EnableDelayedExpansion
title BSU Inventory System - Setup

rem ==========================================================================
rem  BSU Integrated Inventory Monitoring System - setup and launcher
rem
rem    setup.bat              OFFLINE HTTPS SERVER (the office PC). Needs no internet.
rem                           Sets up XAMPP's Apache to serve the system on
rem                           https://<this PC's IP>/ with a local certificate, runs the
rem                           database migrations and starts it with Windows.
rem                           See deploy\setup-server.ps1.
rem
rem    setup.bat --prepare    ON A PC WITH INTERNET, once per update: installs the PHP and
rem                           npm packages and builds the web app into frontend\dist.
rem                           Then copy the whole folder (USB) to the office PC.
rem
rem    setup.bat --dev        DEVELOPMENT: the CodeIgniter and Vite dev servers over HTTP
rem                           (first run installs packages, so it needs internet).
rem                           Extra options: --fast (skip the checks), --no-browser.
rem ==========================================================================

cd /d "%~dp0"
set "ROOT=%~dp0"
set "BACKEND_PORT=8080"
set "FRONTEND_PORT=5173"

set "MODE=server"
set "SKIP_CHECKS="
set "NO_BROWSER="
for %%A in (%*) do (
    if /i "%%~A"=="--dev" set "MODE=dev"
    if /i "%%~A"=="--prepare" set "MODE=prepare"
    if /i "%%~A"=="--fast" set "SKIP_CHECKS=1"
    if /i "%%~A"=="--no-browser" set "NO_BROWSER=1"
)

if "%MODE%"=="server" goto :server
if "%MODE%"=="prepare" goto :prepare

title BSU Inventory System - Local Network Dev Servers
echo ========================================================================
echo   BSU INTEGRATED INVENTORY MONITORING SYSTEM - LOCAL NETWORK LAUNCHER
echo ========================================================================
echo.

rem --------------------------------------------------------------------------
rem  Step 1: environment checks and installs (PHP, Composer, Node, MySQL,
rem  packages, .env, database migrations). The servers are started below.
rem --------------------------------------------------------------------------
if defined SKIP_CHECKS (
    echo [*] Skipping environment checks ^(--fast^).
) else (
    echo [*] Checking the environment and installing anything missing...
    echo.
    powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%ROOT%setup.ps1" -InstallOnly -SkipBrowser
    if errorlevel 1 (
        echo.
        echo [!] The environment checks failed. Fix the problem above and run setup.bat again.
        pause
        exit /b 1
    )
)

rem Use XAMPP's PHP when "php" is not on the PATH. The server windows started
rem below inherit this PATH.
where php >nul 2>&1
if errorlevel 1 (
    if exist "C:\xampp\php\php.exe" (
        set "PATH=C:\xampp\php;!PATH!"
    ) else (
        echo [!] PHP was not found. Install XAMPP ^(PHP 8.2+^) or add php.exe to the PATH.
        pause
        exit /b 1
    )
)

rem --------------------------------------------------------------------------
rem  Step 2: find this PC's local network IPv4 address.
rem
rem  How the address is extracted:
rem   - "route print -4 0.0.0.0" lists the IPv4 default route(s): the route to
rem     the internet/router. Each line reads:
rem         0.0.0.0   0.0.0.0   <gateway>   <interface IP>   <metric>
rem   - The 4th column is the IP of the network adapter that reaches the router,
rem     i.e. the address other devices on the same LAN/Wi-Fi can use. Virtual
rem     adapters (VirtualBox, VMware, Hyper-V, WSL) have no default route, so
rem     they are skipped automatically.
rem   - With several active adapters (e.g. Wi-Fi and Ethernet) the one with the
rem     lowest metric wins, which is the one Windows itself prefers.
rem   - Lines whose 4th column has no dots (the "Persistent Routes" table lists
rem     a metric there) are ignored.
rem --------------------------------------------------------------------------
set "LOCAL_IP="
set "BEST_METRIC=999999"
rem  ALL_IPS collects every adapter with a default route (e.g. the router cable AND a
rem  phone's USB tethering), because devices on each network need that network's address.
set "ALL_IPS= "
for /f "tokens=1-5" %%a in ('route print -4 0.0.0.0 ^| findstr /r /c:"^ *0\.0\.0\.0 *0\.0\.0\.0 "') do (
    echo %%d| findstr /r "^[0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*$" >nul
    if not errorlevel 1 (
        if "!ALL_IPS: %%d =!"=="!ALL_IPS!" set "ALL_IPS=!ALL_IPS!%%d "
        if %%e LSS !BEST_METRIC! (
            set "BEST_METRIC=%%e"
            set "LOCAL_IP=%%d"
        )
    )
)

rem  Fallback: the first "IPv4 Address" line from ipconfig ("...: 192.168.1.50"),
rem  split at the colon, skipping 169.254.x.x (no network / no DHCP answer).
if not defined LOCAL_IP (
    for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4"') do (
        if not defined LOCAL_IP (
            set "CANDIDATE=%%a"
            set "CANDIDATE=!CANDIDATE: =!"
            set "CANDIDATE=!CANDIDATE:(Preferred)=!"
            if not "!CANDIDATE:~0,8!"=="169.254." set "LOCAL_IP=!CANDIDATE!"
        )
    )
)

if not defined LOCAL_IP (
    set "LOCAL_IP=localhost"
    echo [!] No local network address was found. Is this PC connected to Wi-Fi or a LAN cable?
    echo     The system will still work on this PC at http://localhost:%FRONTEND_PORT%
)

rem  Windows Mobile Hotspot always gives this PC the address 192.168.137.1. Devices joined
rem  to the PC's own hotspot use that address instead (handy when the router keeps Wi-Fi
rem  devices apart from wired PCs).
set "HOTSPOT_IP="
ipconfig | findstr /c:"192.168.137.1" >nul && set "HOTSPOT_IP=192.168.137.1"

rem --------------------------------------------------------------------------
rem  Step 3 and 4: start both servers at the same time, each in its own window.
rem  "start" returns immediately, so the two launch together. A server whose
rem  port is already in use is assumed to be running and is not started twice.
rem --------------------------------------------------------------------------
echo.
netstat -ano | findstr /r /c:":%BACKEND_PORT% .*LISTENING" >nul
if errorlevel 1 (
    echo [*] Starting the CodeIgniter 4 backend on port %BACKEND_PORT%...
    start "BSU Backend - CodeIgniter 4 (port %BACKEND_PORT%)" /D "%ROOT%backend" cmd /k php spark serve --host 0.0.0.0
) else (
    echo [i] Port %BACKEND_PORT% is already in use - the backend seems to be running already.
)

netstat -ano | findstr /r /c:":%FRONTEND_PORT% .*LISTENING" >nul
if errorlevel 1 (
    echo [*] Starting the Vue 3 ^(Vite^) frontend on port %FRONTEND_PORT%...
    start "BSU Frontend - Vue 3 Vite (port %FRONTEND_PORT%)" /D "%ROOT%frontend" cmd /k npm run dev -- --host
) else (
    echo [i] Port %FRONTEND_PORT% is already in use - the frontend seems to be running already.
)

rem Give Vite a few seconds to start before opening the browser
rem (ping waits about 1 second per reply; unlike "timeout" it also works without a keyboard console)
ping -n 6 127.0.0.1 >nul

echo.
echo ========================================================================
echo.
echo     OPEN THE SYSTEM
echo.
echo     On this computer:          http://localhost:%FRONTEND_PORT%
echo.
echo     On phones, tablets and other computers on the SAME Wi-Fi / LAN:
echo.
echo                     http://%LOCAL_IP%:%FRONTEND_PORT%
echo.
rem  This PC is on more than one network (e.g. router cable + phone USB tethering):
rem  devices on the other network(s) use these addresses instead
for %%I in (%ALL_IPS%) do (
    if not "%%I"=="%LOCAL_IP%" (
        echo     Devices on this PC's other network ^(e.g. a phone's tethering/hotspot^):
        echo.
        echo                     http://%%I:%FRONTEND_PORT%
        echo.
    )
)
if defined HOTSPOT_IP (
    echo     On devices joined to THIS PC's Mobile Hotspot:
    echo.
    echo                     http://%HOTSPOT_IP%:%FRONTEND_PORT%
    echo.
)
echo     Backend API ^(for reference^): http://%LOCAL_IP%:%BACKEND_PORT%/api
echo.
echo ========================================================================
echo.
echo  If other devices cannot connect, allow the two ports through the Windows
echo  firewall once ^(PowerShell as Administrator^):
echo.
echo    New-NetFirewallRule -DisplayName "BSU Inventory (dev)" -Direction Inbound -Protocol TCP -LocalPort %FRONTEND_PORT%,%BACKEND_PORT% -Action Allow -Profile Private,Domain
echo.
echo  Antivirus with its own firewall ^(Avast, AVG, Kaspersky, Norton, ESET...^) replaces the
echo  Windows firewall: in its Firewall settings mark this network as Private/Trusted,
echo  or allow node.exe and php.exe. Phones must use the same Wi-Fi with mobile data off.
echo.
echo  "Unreachable" on every device usually means the router keeps them apart: check that
echo  the phone's IP starts like this PC's, and turn off AP/client isolation or guest mode
echo  on the router. Or turn on Windows Mobile Hotspot on this PC and join it instead.
echo.
echo  A new network ^(phone USB tethering, new Wi-Fi^) starts as "Public", which the
echo  firewall rule above does not cover. Mark it Private ^(PowerShell as Administrator^):
echo.
echo    Get-NetConnectionProfile ^| Where-Object NetworkCategory -eq 'Public' ^| Set-NetConnectionProfile -NetworkCategory Private
echo.
echo  The servers run in their own windows. Close those windows to stop them.
echo.

if not defined NO_BROWSER start "" "http://localhost:%FRONTEND_PORT%"

pause
endlocal
exit /b 0

rem ==========================================================================
rem  OFFLINE HTTPS SERVER (default)
rem ==========================================================================
:server
title BSU Inventory System - Offline HTTPS Server Setup
echo ========================================================================
echo   BSU INTEGRATED INVENTORY MONITORING SYSTEM - OFFLINE HTTPS SERVER SETUP
echo ========================================================================
echo.
echo  This sets up XAMPP's Apache to serve the system over HTTPS on this PC.
echo  No internet is needed. Windows will ask for administrator permission.
echo.
echo  (For the development servers instead, run:  setup.bat --dev)
echo.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%ROOT%deploy\setup-server.ps1"
if errorlevel 1 (
    echo.
    echo [!] The setup did not finish. See the messages in the setup window.
    pause
    exit /b 1
)
if not defined NO_BROWSER start "" "https://localhost/"
endlocal
exit /b 0

rem ==========================================================================
rem  PREPARE (on a PC with internet): packages + built web app, for copying
rem ==========================================================================
:prepare
title BSU Inventory System - Prepare Offline Copy
echo ========================================================================
echo   PREPARE THE PROJECT FOLDER FOR AN OFFLINE PC (needs internet, once)
echo ========================================================================
echo.
where php >nul 2>&1
if errorlevel 1 (
    if exist "C:\xampp\php\php.exe" (
        set "PATH=C:\xampp\php;!PATH!"
    ) else (
        echo [!] PHP was not found. Install XAMPP ^(PHP 8.2+^) or add php.exe to the PATH.
        pause
        exit /b 1
    )
)
where composer >nul 2>&1
if errorlevel 1 (
    echo [!] Composer was not found. Install it from https://getcomposer.org and run this again.
    pause
    exit /b 1
)
where npm >nul 2>&1
if errorlevel 1 (
    echo [!] Node.js / npm was not found. Install Node.js 22 and run this again.
    pause
    exit /b 1
)

echo [*] Installing the PHP packages ^(backend\vendor^)...
pushd "%ROOT%backend"
call composer install --no-interaction --optimize-autoloader
if errorlevel 1 (
    popd
    echo [!] composer install failed.
    pause
    exit /b 1
)
popd

echo.
echo [*] Installing the npm packages and building the web app ^(frontend\dist^)...
pushd "%ROOT%frontend"
call npm ci --no-audit --no-fund
if errorlevel 1 (
    popd
    echo [!] npm ci failed.
    pause
    exit /b 1
)
call npm run build
if errorlevel 1 (
    popd
    echo [!] The web app build failed.
    pause
    exit /b 1
)
popd

echo.
echo ========================================================================
echo   READY. Copy this WHOLE folder to the office PC ^(USB drive^), including
echo   backend\vendor and frontend\dist. Leave out backend\.env and
echo   backend\writable\backups: they belong to this PC.
echo.
echo   On the office PC: install XAMPP ^(PHP 8.2+^) from its offline installer,
echo   then double-click setup.bat in the copied folder.
echo ========================================================================
echo.
pause
endlocal
exit /b 0
