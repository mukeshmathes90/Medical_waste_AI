@echo off
setlocal EnableExtensions EnableDelayedExpansion
title Smart Medical-Waste System - Setup Check
color 0B

set "PROJECT_DIR=%~dp0.."
pushd "%PROJECT_DIR%" >nul 2>&1
set "PROJECT_DIR=%CD%"
set "OK=0"
set "WARN=0"
set "ERR=0"

echo ============================================================
echo   Smart Medical-Waste System - Environment Check
echo ============================================================
echo   Project: %PROJECT_DIR%
echo.

REM --- Project files --------------------------------------------------------
echo [Project files]
for %%F in (index.php login.php setup.php start.bat database\schema.sql database\init.php includes\auth.php assets\js\qrcode.min.js assets\js\html5-qrcode.min.js) do (
    if exist "%PROJECT_DIR%\%%F" (
        echo   [OK]   %%F
    ) else (
        echo   [MISS] %%F
        set /a ERR+=1
    )
)
echo.

REM --- PHP ------------------------------------------------------------------
echo [PHP runtime]
set "PHP_EXE="
set "PHP_INI_ARG="
if exist "%PROJECT_DIR%\runtime\php\php.exe" (
    set "PHP_EXE=%PROJECT_DIR%\runtime\php\php.exe"
    if exist "%PROJECT_DIR%\runtime\php\php.ini" set "PHP_INI_ARG=-c "%PROJECT_DIR%\runtime\php\php.ini""
    echo   [OK]   Bundled PHP: runtime\php\php.exe
) else (
    for /f "delims=" %%i in ('where php 2^>nul') do (
        if not defined PHP_EXE set "PHP_EXE=%%i"
    )
    if defined PHP_EXE (
        echo   [OK]   PHP on PATH: !PHP_EXE!
    ) else (
        echo   [ERR]  PHP not found. Put a portable PHP in runtime\php\ or add PHP to PATH.
        set /a ERR+=1
    )
)
echo.

if defined PHP_EXE (
    echo [PHP version]
    for /f "delims=" %%v in ('"%PHP_EXE%" %PHP_INI_ARG% -r "echo PHP_VERSION;" 2^>nul') do echo   Version: %%v
    "%PHP_EXE%" %PHP_INI_ARG% -r "exit(PHP_VERSION_ID < 80000 ? 1 : 0);" >nul 2>&1
    if errorlevel 1 (echo   [ERR]  PHP 8.0+ required.& set /a ERR+=1) else (echo   [OK]   PHP 8.0+)
    echo.

    echo [SQLite extensions]
    "%PHP_EXE%" %PHP_INI_ARG% -r "exit(extension_loaded('pdo_sqlite')?0:1);" >nul 2>&1
    if errorlevel 1 (echo   [ERR]  pdo_sqlite not enabled.& set /a ERR+=1) else (echo   [OK]   pdo_sqlite)
    "%PHP_EXE%" %PHP_INI_ARG% -r "exit(extension_loaded('sqlite3')?0:1);" >nul 2>&1
    if errorlevel 1 (echo   [ERR]  sqlite3 not enabled.& set /a ERR+=1) else (echo   [OK]   sqlite3)
    echo.
)

REM --- Database -------------------------------------------------------------
echo [Database]
if exist "%PROJECT_DIR%\database\medical_waste.sqlite" (
    echo   [OK]   medical_waste.sqlite exists
) else (
    echo   [INFO] Database not created yet. start.bat will create it on first run.
    set /a WARN+=1
)
if exist "%PROJECT_DIR%\database\schema.sql" (echo   [OK]   schema.sql present) else (echo   [ERR]  schema.sql missing& set /a ERR+=1)
echo.

REM --- Port -----------------------------------------------------------------
echo [Network]
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /R /C:"IPv4"') do (
    if not defined LANIP set "LANIP=%%a"
)
set "LANIP=%LANIP: =%"
if defined LANIP (echo   LAN IPv4: %LANIP%   ^(use for ESP32/phones^)) else (echo   [WARN] Could not detect a LAN IPv4 address.)
set "PORT=8000"
netstat -ano | findstr /R /C:"LISTENING" | findstr /C:":%PORT% " >nul 2>&1
if errorlevel 1 (echo   [OK]   Port %PORT% is free) else (echo   [WARN] Port %PORT% is in use - start.bat 8081 to change.& set /a WARN+=1)
echo.

echo ============================================================
echo   Summary:  OK since checks passed, %WARN% warning^(s^), %ERR% error^(s^).
if %ERR% GTR 0 (
    echo   Resolve the errors above before running start.bat.
) else (
    echo   You can start the app with:  start.bat
)
echo ============================================================
echo.
popd >nul 2>&1
pause
endlocal
