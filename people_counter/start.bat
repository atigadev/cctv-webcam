@echo off
rem Jalankan worker penghitung orang di latar belakang (tanpa jendela).
cd /d "%~dp0"
if not exist ".venv\Scripts\pythonw.exe" (
    echo Virtualenv belum ada. Jalankan dulu: powershell -ExecutionPolicy Bypass -File setup.ps1
    pause
    exit /b 1
)
start "" ".venv\Scripts\pythonw.exe" counter.py
echo Worker dijalankan. Log: %~dp0counter.log
