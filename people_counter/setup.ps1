# =====================================================
# GIIAS People Counter - instalasi sekali jalan (Windows)
#
#   powershell -ExecutionPolicy Bypass -File setup.ps1            # venv + paket
#   powershell -ExecutionPolicy Bypass -File setup.ps1 -Autostart # + jalan otomatis saat login
#   powershell -ExecutionPolicy Bypass -File setup.ps1 -RemoveAutostart
# =====================================================
param(
    [switch]$Autostart,
    [switch]$RemoveAutostart
)
$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$taskName = 'GIIAS People Counter'

if ($RemoveAutostart) {
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue
    Write-Host "Autostart dihapus."
    exit 0
}

# 1. Python 3.10+
$py = $null
foreach ($c in @("$env:LOCALAPPDATA\Programs\Python\Python311\python.exe", "$env:LOCALAPPDATA\Programs\Python\Python312\python.exe", 'py', 'python')) {
    try { & $c -c "import sys; assert sys.version_info >= (3,10)" 2>$null; if ($LASTEXITCODE -eq 0) { $py = $c; break } } catch {}
}
if (-not $py) {
    Write-Host "Python belum ada, memasang Python 3.11 lewat winget..."
    winget install --id Python.Python.3.11 --scope user --silent --accept-package-agreements --accept-source-agreements
    $py = "$env:LOCALAPPDATA\Programs\Python\Python311\python.exe"
}

# 2. Virtualenv + paket runtime (tanpa torch, cukup onnxruntime)
$venvPy = Join-Path $here '.venv\Scripts\python.exe'
if (-not (Test-Path $venvPy)) { & $py -m venv (Join-Path $here '.venv') }
& $venvPy -m pip install --disable-pip-version-check -q -r (Join-Path $here 'requirements.txt')

# 3. Model
$model = Join-Path $here 'models\yolo11s_1920.onnx'
if (-not (Test-Path $model)) {
    Write-Warning "Model $model belum ada. Salin dari komputer lain atau ekspor (lihat README)."
}

# 4. Autostart (Task Scheduler, saat user login, restart otomatis kalau mati)
if ($Autostart) {
    $pyw = Join-Path $here '.venv\Scripts\pythonw.exe'
    $action = New-ScheduledTaskAction -Execute $pyw -Argument "`"$here\counter.py`"" -WorkingDirectory $here
    $trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
        -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -MultipleInstances IgnoreNew
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings `
        -Description 'Hitung orang dari kamera Agent DVR untuk GIIAS Monitor' -Force | Out-Null
    Start-ScheduledTask -TaskName $taskName
    Write-Host "Autostart terpasang dan worker dijalankan (Task Scheduler: '$taskName')."
}

Write-Host "Selesai. Uji: .venv\Scripts\python.exe counter.py --once"
