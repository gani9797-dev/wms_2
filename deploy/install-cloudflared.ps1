#requires -Version 5.1
<#
.SYNOPSIS
    Устанавливает cloudflared и поднимает туннель до портала TiMLog.
    Порты наружу открывать не нужно — трафик идёт исходящим соединением.

.DESCRIPTION
    Вариант А (рекомендуемый, туннель управляется из панели Cloudflare):
      В Zero Trust -> Networks -> Tunnels создайте туннель, скопируйте токен
      и запустите:  .\install-cloudflared.ps1 -TunnelToken <токен>
      Публичные hostname настраиваются там же, в панели.

    Вариант Б (классический, маршруты в config.yml на сервере):
      .\install-cloudflared.ps1 -TunnelName wms -Hostname wms.example.com
      Скрипт выполнит cloudflared tunnel login, создаст туннель, запишет
      C:\wms\etc\cloudflared.yml и установит службу.

    Чтобы деплоить по SSH без открытого 22 порта, добавьте в туннель
    второй маршрут:  ssh.example.com -> ssh://localhost:22
    и используйте workflow "Deploy WMS (Windows, SSH через Cloudflare Tunnel)".
#>
[CmdletBinding()]
param(
    [string]$WmsRoot = 'C:\wms',
    [int]$Port = 8001,
    [string]$TunnelToken = '',
    [string]$TunnelName = 'wms',
    [string]$Hostname = '',
    [string]$DownloadUrl = 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe'
)

$ErrorActionPreference = 'Stop'

$binDir = Join-Path $WmsRoot 'bin'
$etcDir = Join-Path $WmsRoot 'etc'
$exe = Join-Path $binDir 'cloudflared.exe'

foreach ($dir in @($binDir, $etcDir)) {
    if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
}

if (-not (Test-Path -LiteralPath $exe)) {
    Write-Host "Скачиваю cloudflared в $exe ..." -ForegroundColor Yellow
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    Invoke-WebRequest -Uri $DownloadUrl -OutFile $exe -UseBasicParsing
    Write-Host 'Готово' -ForegroundColor Green
} else {
    Write-Host "cloudflared уже есть: $exe" -ForegroundColor DarkGray
}

Write-Host "Версия: $(& $exe --version)" -ForegroundColor Green

if ($TunnelToken) {
    Write-Host 'Устанавливаю службу cloudflared по токену (управляется из панели Cloudflare)...' -ForegroundColor Cyan
    & $exe service install $TunnelToken
    if ($LASTEXITCODE -ne 0) { throw "cloudflared service install завершился с кодом $LASTEXITCODE" }
} else {
    Write-Host 'Интерактивный режим: авторизация в Cloudflare...' -ForegroundColor Cyan
    & $exe tunnel login
    if ($LASTEXITCODE -ne 0) { throw 'cloudflared tunnel login не выполнен' }

    $existing = & $exe tunnel list 2>&1
    if ($existing -match $TunnelName) {
        Write-Host "Туннель $TunnelName уже существует" -ForegroundColor DarkGray
    } else {
        Write-Host "Создаю туннель $TunnelName ..." -ForegroundColor Cyan
        & $exe tunnel create $TunnelName
        if ($LASTEXITCODE -ne 0) { throw "Не удалось создать туннель $TunnelName" }
    }

    if (-not $Hostname) {
        $Hostname = Read-Host 'Публичный домен портала (например wms.example.com)'
    }

    $configPath = Join-Path $etcDir 'cloudflared.yml'
    $config = @"
# Маршруты туннеля $TunnelName. После правки: Restart-Service cloudflared
url: http://127.0.0.1:$Port
tunnel: $TunnelName
credentials-file: C:\Users\$env:USERNAME\.cloudflared\$TunnelName.json

ingress:
  - hostname: $Hostname
    service: http://127.0.0.1:$Port
  - service: http_status:404
"@
    Set-Content -LiteralPath $configPath -Value $config -Encoding ASCII
    Write-Host "Конфиг записан: $configPath" -ForegroundColor Green

    & $exe tunnel route dns $TunnelName $Hostname
    & $exe service install
    if ($LASTEXITCODE -ne 0) { throw 'cloudflared service install не выполнен' }

    Write-Host 'Копирую конфиг в системный профиль службы...' -ForegroundColor Cyan
    $systemProfile = Join-Path $env:SystemRoot 'System32\config\systemprofile\.cloudflared'
    if (-not (Test-Path -LiteralPath $systemProfile)) { New-Item -ItemType Directory -Path $systemProfile -Force | Out-Null }
    Copy-Item -LiteralPath $configPath -Destination (Join-Path $systemProfile 'config.yml') -Force
}

Start-Service cloudflared -ErrorAction SilentlyContinue
Start-Sleep -Seconds 3
$svc = Get-Service -Name cloudflared -ErrorAction SilentlyContinue
if ($svc) { Write-Host "Служба cloudflared: $($svc.Status)" -ForegroundColor Green }

Write-Host ''
Write-Host 'Проверьте портал по внешнему адресу.' -ForegroundColor Yellow
Write-Host 'Логи туннеля: событийный журнал Windows -> Приложения -> cloudflared,' -ForegroundColor Yellow
Write-Host '  либо: cloudflared.exe tunnel info <имя>' -ForegroundColor Yellow
Write-Host ''
Write-Host 'Если хотите деплоить по SSH через туннель, добавьте маршрут:' -ForegroundColor Yellow
Write-Host '  ssh.example.com -> ssh://localhost:22' -ForegroundColor Yellow
