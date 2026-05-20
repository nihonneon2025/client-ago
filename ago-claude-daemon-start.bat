@echo off
chcp 65001 > nul
echo AGO Claude Daemon 起動中...
powershell -WindowStyle Normal -ExecutionPolicy Bypass -File "%~dp0ago-claude-daemon.ps1"
pause
