#requires -Version 5.1
<#
.SYNOPSIS
    Запуск PHP-сервера TiMLog (используется планировщиком заданий Windows при старте системы).

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File C:\wms\app\deploy\start-app.ps1
    powershell -NoProfile -ExecutionPolicy Bypass -File C:\wms\app\deploy\start-app.ps1 -Wait
#>
[CmdletBinding()]
param(
    [int]$Port = 0,
    [string]$Root = '',
    # Ждать, пока портал не начнёт отвечать
    [switch]$Wait
)

$ErrorActionPreference = 'Stop'

if ($Root) { $env:WMS_ROOT = $Root }
if ($Port -gt 0) { $env:WMS_PORT = [string]$Port }

. (Join-Path $PSScriptRoot 'common.ps1')

try {
    $listeners = Get-ListeningProcessId -LocalPort $Port
    if ($listeners.Count -gt 0) {
        Write-Log "Портал уже работает на порту $Port (PID $($listeners -join ', '))"
        exit 0
    }

    Start-WmsApp -LocalPort $Port | Out-Null

    if ($Wait) {
        if (Test-WmsHealth -LocalPort $Port -Path $HealthPath -TimeoutSec $HealthWait) {
            Write-Log "Портал отвечает: http://127.0.0.1:$Port$HealthPath"
            exit 0
        }
        Write-Log 'Портал не отвечает после запуска' 'ERROR'
        exit 1
    }

    exit 0
} catch {
    Write-Log ("ОШИБКА ЗАПУСКА: " + $_.Exception.Message) 'ERROR'
    exit 1
}
