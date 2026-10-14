@echo off
setlocal EnableExtensions EnableDelayedExpansion
title Smart Medical-Waste System - Launcher
color 0A

REM ==========================================================================
REM  Smart Medical-Waste Collection and Segregation System
REM  Launcher: finds PHP + SQLite, prepares the database, starts the PHP
REM  built-in web server and opens the application in the browser.
REM
REM  Usage:  start.bat [port]         (default port 8000)
REM ==========================================================================

REM --- Locate this project (based on this file's location) -------------------
set "PROJECT_DIR=%~dp0"
if "%PROJECT_DIR:~-1%"=="\" set "PROJECT_DIR=%PROJECT_DIR:~0,-1%"

set "PORT=%~1"
if "%PORT%"=="" set "PORT=8090"

echo ============================================================
echo   Smart Medical-Waste System
echo   Project: %PROJECT_DIR%
echo ============================================================
echo.

REM --- Sanity: project files present? ---------------------------------------
if not exist "%PROJECT_DIR%\index.php" (
    echo [ERROR] index.php not found. Run this launcher from the project folder.
    echo         Expected: %PROJECT_DIR%\index.php
    goto :fail
)
if not exist "%PROJECT_DIR%\database\schema.sql" (
    echo [ERROR] database\schema.sql is missing. The project files are incomplete.
    goto :fail
)

REM --- Locate PHP: bundled portable runtime first, then PATH ---------------
set "PHP_EXE="
set "PHP_INI_ARG="
if exist "%PROJECT_DIR%\runtime\php\php.exe" (
    set "PHP_EXE=%PROJECT_DIR%\runtime\php\php.exe"
    if exist "%PROJECT_DIR%\runtime\php\php.ini" set "PHP_INI_ARG=-c "%PROJECT_DIR%\runtime\php\php.ini""
)

if not defined PHP_EXE (
    for /f "delims=" %%i in ('where php 2^>nul') do (
        if not defined PHP_EXE set "PHP_EXE=%%i"
    )
)

if not defined PHP_EXE (
    echo [ERROR] PHP was not found.
    echo.
    echo   This project expects either:
    echo     1. A portable runtime at  runtime\php\php.exe  - recommended
    echo     2. PHP 8+ installed and available on your PATH.
    echo.
    echo   To install PHP:
    echo     - Download PHP 8 for Windows x64 - Thread Safe or Non Thread Safe
    echo       from https://windows.php.net/download
    echo     - Extract it so that  runtime\php\php.exe  exists inside this project,
    echo       OR add the folder containing php.exe to your PATH.
    echo     - Make sure the php.ini enables: pdo_sqlite, sqlite3
    goto :fail
)

echo [OK]  PHP: %PHP_EXE%

REM --- Verify PHP version (8.0+) --------------------------------------------
"%PHP_EXE%" %PHP_INI_ARG% -r "exit(PHP_VERSION_ID < 80000 ? 1 : 0);" >nul 2>&1
if errorlevel 1 (
    echo [ERROR] PHP 8.0 or newer is required.
    "%PHP_EXE%" -v
    goto :fail
)

REM --- Verify required SQLite extensions ------------------------------------
"%PHP_EXE%" %PHP_INI_ARG% -r "exit((extension_loaded('pdo_sqlite') && extension_loaded('sqlite3')) ? 0 : 1);" >nul 2>&1
if errorlevel 1 (
    echo [ERROR] The PHP SQLite extensions are not enabled.
    echo         Enable  extension=pdo_sqlite  and  extension=sqlite3  in php.ini.
    echo         With the bundled runtime this is already handled by runtime\php\php.ini.
    goto :fail
)
echo [OK]  PHP 8+ with SQLite support

REM --- Initialise the database on first run ---------------------------------
if not exist "%PROJECT_DIR%\database\medical_waste.sqlite" (
    echo [..] First run detected - creating the SQLite database...
    "%PHP_EXE%" %PHP_INI_ARG% "%PROJECT_DIR%\database\init.php" --demo=1
    if errorlevel 1 (
        echo [ERROR] Database initialisation failed.
        goto :fail
    )
    echo [OK]  Database created. First-run setup will let you create the admin.
) else (
    echo [OK]  Database found
)

REM --- Pick a free port (auto-fallback when the default is busy) ------------
set "PORT_TRIES=0"
:findport
    set "PORT_BUSY="
    for /f "delims=" %%L in ('netstat -ano ^| findstr /R /C:"LISTENING" ^| findstr /C:":%PORT% "') do set "PORT_BUSY=1"
    if defined PORT_BUSY (
        set /a PORT+=1
        set /a PORT_TRIES+=1
        if !PORT_TRIES! GEQ 25 goto :portfail
        goto :findport
    )

REM --- Detect LAN IP (for ESP32 / phone access) -----------------------------
set "LANIP="
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /R /C:"IPv4"') do (
    if not defined LANIP set "LANIP=%%a"
)
set "LANIP=%LANIP: =%"

echo.
echo Starting PHP web server on port %PORT% ...
echo Do not close the "MWS PHP Server" window while using the app.
echo.

REM Allow a few concurrent requests (browser + ESP32).
set "PHP_CLI_SERVER_WORKERS=4"

start "MWS PHP Server" /min "%PHP_EXE%" %PHP_INI_ARG% -S 0.0.0.0:%PORT% -t "%PROJECT_DIR%"

REM Give the server a moment to bind (ping delay avoids stdin issues).
ping -n 3 127.0.0.1 >nul

echo ============================================================
echo   Application is running:
echo.
echo     Local:   http://localhost:%PORT%/
if defined LANIP echo     Network: http://%LANIP%:%PORT%/   ^(use this for ESP32/phones^)
echo.
echo   First time? Open the link and create the administrator account.
echo   Stop the server by closing the "MWS PHP Server" window.
echo ============================================================

start "" "http://localhost:%PORT%/"

echo.
echo Press any key to close this launcher window (the server keeps running).
pause >nul
exit /b 0

:portfail
echo [ERROR] Could not find a free TCP port in the 8090-8114 range.
echo         Close some other servers, or run:  start.bat 9000
echo.
pause
exit /b 1

:fail
echo.
echo ------------------------------------------------------------
echo  Launcher stopped because of the error above.
echo  Fix it and run start.bat again, or run scripts\setup_check.bat
echo  to diagnose your environment.
echo ------------------------------------------------------------
echo.
pause
exit /b 1
