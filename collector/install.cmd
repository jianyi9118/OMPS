@echo off
setlocal
rem OMPS 设备采集客户端安装。请右键“以管理员身份运行”。
net session >nul 2>&1
if errorlevel 1 (
  echo 请右键本文件，选择“以管理员身份运行”。
  pause
  exit /b 1
)
echo.
echo OMPS 设备采集客户端
echo 只上报设备管理员在“采集范围”中列出的硬件信息：计算机名、操作系统、CPU、内存、硬盘、主板、显示器、网卡。
echo 不采集键盘输入、屏幕、文件、浏览记录和登录账号。详见 readme.txt。
echo.
set /p SERVER=服务器地址（例如 http://10.0.0.5/omps）：
set /p CODE=注册码（设备管理员在设备档案中生成）：
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
  echo 安装没有完成。
  pause
  exit /b 1
)
schtasks /create /f /tn "OMPS Collector (boot)" /sc onstart /delay 0005:00 /ru SYSTEM /tr "powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File \"%DIR%\omps-collector.ps1\" -Event boot" >nul
schtasks /create /f /tn "OMPS Collector (daily)" /sc daily /st 10:00 /ru SYSTEM /tr "powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File \"%DIR%\omps-collector.ps1\" -Event daily" >nul
echo.
echo 安装完成：开机 5 分钟后和每天 10:00 各上报一次。卸载请运行 "%DIR%\uninstall.cmd"。
pause
