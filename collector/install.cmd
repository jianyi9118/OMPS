@echo off
setlocal
rem OMPS device collector - install. Right-click and choose "Run as administrator".
net session >nul 2>&1
if errorlevel 1 (
  echo Please right-click this file and choose "Run as administrator".
  pause
  exit /b 1
)
echo.
echo OMPS Device Collector
echo Reports only the hardware items the device administrator selected in "Collection scope":
echo computer name, operating system, CPU, memory, disks, motherboard, monitors, network adapters.
echo It never collects keystrokes, screen contents, files, browsing history or login accounts. See readme.txt.
echo.
set /p SERVER=Server address (for example http://10.0.0.5/omps):
set /p CODE=Registration code (Collector tab of the device in OMPS):
set "DIR=%ProgramFiles%\OMPS Collector"
if not exist "%DIR%" mkdir "%DIR%"
copy /y "%~dp0omps-collector.ps1" "%DIR%\" >nul
copy /y "%~dp0uninstall.cmd" "%DIR%\" >nul
copy /y "%~dp0readme.txt" "%DIR%\" >nul
if not exist "%ProgramData%\OMPS" mkdir "%ProgramData%\OMPS"
rem only SYSTEM and administrators may read the device token
icacls "%ProgramData%\OMPS" /inheritance:r /grant:r *S-1-5-18:(OI)(CI)F *S-1-5-32-544:(OI)(CI)F >nul
powershell -NoProfile -ExecutionPolicy Bypass -File "%DIR%\omps-collector.ps1" -Register -Server "%SERVER%" -Code "%CODE%"
if errorlevel 1 (
  echo Installation was not completed.
  pause
  exit /b 1
)
schtasks /create /f /tn "OMPS Collector (boot)" /sc onstart /delay 0005:00 /ru SYSTEM /tr "powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File \"%DIR%\omps-collector.ps1\" -Event boot" >nul
schtasks /create /f /tn "OMPS Collector (daily)" /sc daily /st 10:00 /ru SYSTEM /tr "powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File \"%DIR%\omps-collector.ps1\" -Event daily" >nul
echo.
echo Installed. This computer reports 5 minutes after each start-up and every day at 10:00.
echo To uninstall, run "%DIR%\uninstall.cmd" as administrator.
pause
