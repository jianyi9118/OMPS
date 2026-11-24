@echo off
setlocal
rem OMPS device collector - uninstall. Right-click and choose "Run as administrator".
net session >nul 2>&1
if errorlevel 1 (
  echo Please right-click this file and choose "Run as administrator".
  pause
  exit /b 1
)
schtasks /delete /f /tn "OMPS Collector (boot)" >nul 2>&1
schtasks /delete /f /tn "OMPS Collector (daily)" >nul 2>&1
if exist "%ProgramData%\OMPS\collector.ini" del /f /q "%ProgramData%\OMPS\collector.ini"
echo Reporting stopped and the device token was deleted.
echo Ask the device administrator to revoke this computer under "Usage & monitoring - Collector clients".
echo The program folder "%ProgramFiles%\OMPS Collector" can now be deleted by hand.
pause
