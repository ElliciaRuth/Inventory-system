@echo off
setlocal
title BSU Inventory System - Automated Stack Setup

cd /d "%~dp0"

echo ========================================================================
echo   BSU INTEGRATED INVENTORY MONITORING SYSTEM - SETUP LAUNCHER
echo ========================================================================
echo.
echo [*] Launching automated PowerShell setup script...
echo.

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup.ps1" %*
set "EXIT_CODE=%ERRORLEVEL%"

if %EXIT_CODE% neq 0 (
    echo.
    echo [!] Setup exited with code %EXIT_CODE%.
)

if "%~1"=="" (
    echo.
    pause
)

exit /b %EXIT_CODE%