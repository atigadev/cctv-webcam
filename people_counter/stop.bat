@echo off
rem Hentikan worker penghitung orang.
cd /d "%~dp0"
if not exist counter.pid (
    echo Worker tidak berjalan.
    exit /b 0
)
set /p PID=<counter.pid
taskkill /PID %PID% /F >nul 2>&1 && echo Worker (PID %PID%) dihentikan. || echo Worker tidak berjalan.
del counter.pid >nul 2>&1
