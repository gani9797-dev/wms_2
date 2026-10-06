#requires -Version 5.1
<#
.SYNOPSIS
    Остановка PHP-сервера TiMLog (освобождает порт и .sqlite-файлы).
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
    Stop-WmsApp -LocalPort $Port
    Write-Log 'Остановка выполнена'
    exit 0
} catch {
    Write-Log ("ОШИБКА ОСТАНОВКИ: " + $_.Exception.Message) 'ERROR'
    exit 1
}
