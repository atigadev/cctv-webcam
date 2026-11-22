# =====================================================
# GIIAS People Counter - instalasi sekali jalan (Windows)
#
#   powershell -ExecutionPolicy Bypass -File setup.ps1            # venv + paket
#   powershell -ExecutionPolicy Bypass -File setup.ps1 -Autostart # + jalan otomatis saat login
#   powershell -ExecutionPolicy Bypass -File setup.ps1 -RemoveAutostart
#
# onnxruntime hanya tersedia untuk Python 64-bit versi 3.10 - 3.13,
# jadi skrip ini mencari Python yang cocok (atau memasang 3.11).
# =====================================================
param(
    [switch]$Autostart,
    [switch]$RemoveAutostart
)
$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$taskName = 'GIIAS People Counter'
$checkPy = 'import sys,struct; v=sys.version_info; b=struct.calcsize(''P'')*8; print(''%d.%d %dbit'' % (v[0], v[1], b)); sys.exit(0 if (3,10)<=tuple(v[:2])<=(3,13) and b==64 else 1)'

if ($RemoveAutostart) {
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue
    Write-Host "Autostart dihapus."
    exit 0
}

function Test-Python([string[]]$cmd) {
    try {
        $exe = $cmd[0]
        $args2 = @($cmd | Select-Object -Skip 1) + @('-c', $checkPy)
        $out = & $exe @args2 2>$null
        $ok = ($LASTEXITCODE -eq 0)
        if ($out) { Write-Host ("  {0,-55} -> {1}{2}" -f ($cmd -join ' '), $out, $(if ($ok) { '  (cocok)' } else { '  (tidak didukung)' })) }
        return $ok
    } catch { return $false }
}

function Find-Python {
    $candidates = @()
    foreach ($v in '311', '312', '310', '313') {
        $candidates += , @("$env:LOCALAPPDATA\Programs\Python\Python$v\python.exe")
        $candidates += , @("$env:ProgramFiles\Python$v\python.exe")
    }
    foreach ($v in '3.11', '3.12', '3.10', '3.13') { $candidates += , @('py', "-$v-64") }
    $candidates += , @('python')
    foreach ($c in $candidates) {
        if ($c[0] -like '*\*' -and -not (Test-Path $c[0])) { continue }
        if (Test-Python $c) { return , $c }
    }
    return $null
}

# 1. Python 64-bit 3.10 - 3.13
Write-Host "Mencari Python 64-bit versi 3.10 - 3.13..."
$py = Find-Python
if (-not $py) {
    Write-Host "Tidak ada yang cocok. Memasang Python 3.11 (64-bit) lewat winget..."
    winget install --id Python.Python.3.11 --architecture x64 --scope user --silent --accept-package-agreements --accept-source-agreements
    $py = @("$env:LOCALAPPDATA\Programs\Python\Python311\python.exe")
    if (-not (Test-Python $py)) {
        throw "Python 3.11 64-bit gagal dipasang. Pasang manual dari https://www.python.org/downloads/release/python-3119/ (Windows installer 64-bit), lalu jalankan skrip ini lagi."
    }
}
Write-Host "Memakai: $($py -join ' ')"

# 2. Virtualenv (buat ulang kalau dibuat dari Python yang tidak cocok)
$venvDir = Join-Path $here '.venv'
$venvPy = Join-Path $venvDir 'Scripts\python.exe'
if ((Test-Path $venvPy) -and -not (Test-Python @($venvPy))) {
    Write-Host "Virtualenv lama memakai Python yang tidak didukung, dibuat ulang..."
    Remove-Item -Recurse -Force $venvDir
}
if (-not (Test-Path $venvPy)) {
    $exe = $py[0]; $rest = @($py | Select-Object -Skip 1)
    & $exe @rest -m venv $venvDir
    if ($LASTEXITCODE -ne 0) { throw "Gagal membuat virtualenv." }
}

# 3. Paket runtime (tanpa torch, cukup onnxruntime)
& $venvPy -m pip install --disable-pip-version-check -q --upgrade pip
& $venvPy -m pip install --disable-pip-version-check -q -r (Join-Path $here 'requirements.txt')
if ($LASTEXITCODE -ne 0) { throw "Instal paket gagal (lihat pesan pip di atas)." }
& $venvPy -c "import onnxruntime, cv2, numpy; print('Paket OK: onnxruntime', onnxruntime.__version__, '| opencv', cv2.__version__)"
if ($LASTEXITCODE -ne 0) { throw "Paket terpasang tapi gagal di-import." }

# 4. Model
$model = Join-Path $here 'models\yolo11s_1920.onnx'
if (-not (Test-Path $model)) {
    Write-Warning "Model $model belum ada. Salin dari repo / komputer lain (lihat README)."
} elseif ((Get-Item $model).Length -lt 1MB) {
    Write-Warning "Model $model terlalu kecil - kemungkinan hanya pointer Git LFS / unduhan rusak."
}

# 5. Autostart (Task Scheduler, saat user login, restart otomatis kalau mati)
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

Write-Host ""
Write-Host "Selesai. Uji: .venv\Scripts\python.exe counter.py --once"
