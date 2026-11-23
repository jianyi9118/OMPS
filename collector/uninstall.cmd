@echo off
setlocal
rem OMPS 设备采集客户端卸载。请右键“以管理员身份运行”。
net session >nul 2>&1
if errorlevel 1 (
  echo 请右键本文件，选择“以管理员身份运行”。
  pause
  exit /b 1
)
schtasks /delete /f /tn "OMPS Collector (boot)" >nul 2>&1
schtasks /delete /f /tn "OMPS Collector (daily)" >nul 2>&1
if exist "%ProgramData%\OMPS\collector.ini" del /f /q "%ProgramData%\OMPS\collector.ini"
echo 已停止上报并删除本机令牌。请设备管理员在“使用与监测 - 采集客户端”中撤销本机。
echo 程序文件夹 "%ProgramFiles%\OMPS Collector" 可以手动删除。
pause
