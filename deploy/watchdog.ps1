#requires -Version 5.1
<#
.SYNOPSIS
    Сторож: если портал не отвечает — перезапускает PHP-сервер.
    Запускается планировщиком Windows каждые 5 минут (создаётся bootstrap.ps1).
#>
[CmdletBinding()]
param(
    [int]$Port = 0,
    [string]$Root = ''
)

$ErrorActionPreference = 'Stop'

if ($Root) { $env:WMS_ROOT = $Root }
if ($Port -gt 0) { $env:WMS_PORT = [string]$Port }

. (Join-Path $PSScriptRoot 'common.ps1')

try {
    if (Test-DeployLock) {
        Write-Log 'Идёт деплой (deploy.lock) — сторож пропускает проверку' 'DEBUG'
        exit 0
    }

    if (Test-WmsHealth -LocalPort $Port -Path $HealthPath -TimeoutSec 10) {
        Write-Log "Сторож: портал отвечает на порту $Port" 'DEBUG'
        exit 0
    }

    Write-Log "Сторож: портал не отвечает, перезапускаю" 'WARN'
    Stop-WmsApp -LocalPort $Port
    Start-WmsApp -LocalPort $Port | Out-Null

    if (Test-WmsHealth -LocalPort $Port -Path $HealthPath -TimeoutSec $HealthWait) {
        Write-Log 'Сторож: портал снова отвечает'
        exit 0
    }

    Write-Log 'Сторож: перезапуск не помог' 'ERROR'
    exit 1
} catch {
    Write-Log ("СТОРОЖ: " + $_.Exception.Message) 'ERROR'
    exit 1
}
