#requires -Version 5.1
<#
.SYNOPSIS
    Первичная настройка Windows-сервера под TiMLog (WMS). Запускается ОДИН раз, вручную.

.DESCRIPTION
    Что делает:
      1. Проверяет/устанавливает Git и PHP 8.3 (через winget), включает pdo_sqlite и sqlite3.
      2. Создаёт C:\wms\{app,bin,logs,state,etc}.
      3. Клонирует репозиторий GitHub в C:\wms\app (код и данные).
      4. Создаёт в планировщике Windows автозапуск портала при старте системы
         и сторож, который поднимает его каждые 5 минут, если он упал.
      5. При необходимости ставит OpenSSH-сервер и кладёт ваш публичный ключ.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\bootstrap.ps1

.EXAMPLE
    # приватный репозиторий + сразу настроить SSH
    powershell -ExecutionPolicy Bypass -File .\bootstrap.ps1 -GitPat ghp_xxx -GitHubUser gani9797-dev -InstallSshd -SshPublicKey C:\Users\Admin\wms_deploy.pub
#>
[CmdletBinding()]
param(
    [string]$WmsRoot = 'C:\wms',
    [int]$Port = 8001,
    [string]$RepoUrl = 'https://github.com/gani9797-dev/wms_2.git',
    [string]$Branch = 'arena/448262c5-wms-2',
    [string]$GitPat = '',
    [string]$GitHubUser = '',
    [string]$SshPublicKey = '',
    [switch]$InstallSshd,
    [switch]$SkipPhp,
    [switch]$SkipGit,
    [switch]$SkipTasks
)

$ErrorActionPreference = 'Stop'

function Say {
    param(
        [string]$Message,
        [string]$Color = 'White',
        [switch]$NoNewline
    )
    if ($NoNewline) { Write-Host $Message -ForegroundColor $Color -NoNewline }
    else { Write-Host $Message -ForegroundColor $Color }
}

function Step {
    param([string]$Message)
    Write-Host ''
    Say "== $Message" Cyan
}

Step '0. Проверка прав'
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
$admin = $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if ($admin) { Say "Запущено от имени администратора: $($identity.Name)" Green }
else { Say 'ВНИМАНИЕ: без прав администратора не получится создать задачи планировщика и установить OpenSSH.' Yellow }

$appDir   = Join-Path $WmsRoot 'app'
$binDir   = Join-Path $WmsRoot 'bin'
$logDir   = Join-Path $WmsRoot 'logs'
$stateDir = Join-Path $WmsRoot 'state'
$etcDir   = Join-Path $WmsRoot 'etc'

Step "1. Каталоги в $WmsRoot"
foreach ($dir in @($WmsRoot, $binDir, $logDir, $stateDir, $etcDir)) {
    if (-not (Test-Path -LiteralPath $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
        Say "  создан $dir"
    } else {
        Say "  уже есть $dir" DarkGray
    }
}

# ---------------------------------------------------------------------------
Step '2. Git'
$git = Get-Command git -ErrorAction SilentlyContinue
if (-not $git) { $git = Get-Command 'C:\Program Files\Git\cmd\git.exe' -ErrorAction SilentlyContinue }
if (-not $git -and -not $SkipGit) {
    Say '  Git не найден, устанавливаю через winget...' Yellow
    winget install --id Git.Git -e --accept-source-agreements --accept-package-agreements
    $git = Get-Command git -ErrorAction SilentlyContinue
}
if ($git) { Say "  Git: $($git.Source)" Green } else { Say '  Git НЕ найден — установите вручную и перезапустите скрипт' Red }
$gitExe = if ($git) { $git.Source } else { $null }

# ---------------------------------------------------------------------------
Step '3. PHP 8.3'
$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php -and -not $SkipPhp) {
    Say '  PHP не найден, устанавливаю через winget...' Yellow
    winget install --id PHP.PHP.8.3 -e --accept-source-agreements --accept-package-agreements
    $php = Get-Command php -ErrorAction SilentlyContinue
}
if (-not $php) {
    foreach ($candidate in @('C:\tools\php83\php.exe', 'C:\Program Files\PHP\v8.3\php.exe', 'C:\php\php.exe')) {
        if (Test-Path -LiteralPath $candidate) { $php = Get-Command $candidate; break }
    }
}
if (-not $php) { throw 'PHP не найден. Установите PHP 8.x и перезапустите bootstrap.ps1' }
$phpExe = $php.Source
Say "  PHP: $phpExe" Green
& $phpExe -v | Select-Object -First 1

# php.ini и расширения
$iniLine = (& $phpExe --ini 2>&1) | Where-Object { $_ -match 'Loaded Configuration File' }
$iniPath = if ($iniLine) { ($iniLine -split ':', 2)[1].Trim() } else { '' }
if (-not $iniPath -or $iniPath -eq 'none') {
    $phpDir = Split-Path -Parent $phpExe
    $dev = Join-Path $phpDir 'php.ini-development'
    $prod = Join-Path $phpDir 'php.ini-production'
    $source = if (Test-Path -LiteralPath $prod) { $prod } elseif (Test-Path -LiteralPath $dev) { $dev } else { '' }
    if ($source) {
        $iniPath = Join-Path $phpDir 'php.ini'
        Copy-Item -LiteralPath $source -Destination $iniPath -Force
        Say "  создан php.ini из $source" Yellow
    }
}
if ($iniPath -and (Test-Path -LiteralPath $iniPath)) {
    Say "  php.ini: $iniPath"
    $lines = @(Get-Content -LiteralPath $iniPath)
    $changed = $false
    $result = New-Object System.Collections.Generic.List[string]
    foreach ($line in $lines) {
        if ($line -match '^\s*;\s*extension_dir\s*=') {
            $result.Add('extension_dir = "ext"'); $changed = $true
        } elseif ($line -match '^\s*;?\s*extension\s*=\s*(pdo_sqlite|sqlite3|mbstring|openssl)\s*$') {
            $result.Add("extension=$($Matches[1])"); $changed = $true
        } else {
            $result.Add($line)
        }
    }
    foreach ($ext in @('pdo_sqlite', 'sqlite3', 'mbstring', 'openssl')) {
        if (-not ($result -contains "extension=$ext")) {
            $result.Add("extension=$ext"); $changed = $true
        }
    }
    if ($changed) {
        Copy-Item -LiteralPath $iniPath -Destination "$iniPath.bak" -Force
        Set-Content -LiteralPath $iniPath -Value $result -Encoding ASCII
        Say '  в php.ini включены pdo_sqlite, sqlite3, mbstring, openssl (копия: php.ini.bak)' Green
    }
    $modules = @(& $phpExe -m 2>&1)
    foreach ($m in @('pdo_sqlite', 'sqlite3', 'mbstring')) {
        if ($modules -contains $m) { Say "  расширение $m : OK" Green }
        else { Say "  расширение $m : ОТСУТСТВУЕТ! Проверьте php.ini" Red }
    }
}

# ---------------------------------------------------------------------------
Step '4. Доступ к репозиторию GitHub'
if ($GitPat -and $GitHubUser) {
    Say '  Сохраняю PAT в диспетчер учётных данных Windows (для неинтерактивного git fetch)'
    cmdkey /generic:git:https://github.com /user:$GitHubUser /pass:$GitPat | Out-Null
} elseif ($GitPat) {
    Say '  PAT передан без -GitHubUser — сохраните учётные данные вручную:' Yellow
    Say '    cmdkey /generic:git:https://github.com /user:<ваш-логин> /pass:<PAT>' Yellow
}
$deployKey = Join-Path $etcDir 'deploy_key'
if (Test-Path -LiteralPath $deployKey) { Say "  найден deploy key: $deployKey" Green }
else { Say "  deploy key не найден ($deployKey) — для приватного репозитория нужен PAT или ключ" DarkGray }

Step '5. Клонирование репозитория'
if (-not $gitExe) { throw 'Git недоступен — не могу клонировать репозиторий' }
if (Test-Path -LiteralPath (Join-Path $appDir '.git')) {
    Say "  репозиторий уже развёрнут в $appDir" DarkGray
} else {
    Say "  клонирую $RepoUrl -> $appDir"
    $out = & $gitExe clone --branch $Branch $RepoUrl $appDir 2>&1
    if ($LASTEXITCODE -ne 0) {
        Say '  клонирование с --branch не удалось, пробую без указания ветки' Yellow
        $out = & $gitExe clone $RepoUrl $appDir 2>&1
        if ($LASTEXITCODE -ne 0) {
            $out | ForEach-Object { Say "    $_" Red }
            throw "Не удалось клонировать $RepoUrl. Проверьте PAT/deploy key и доступ к приватному репозиторию."
        }
    }
    Say '  репозиторий клонирован' Green
}

# ---------------------------------------------------------------------------
Step '6. Локальные настройки'
$localFile = Join-Path $stateDir 'local.ps1'
$localContent = @"
# Создан bootstrap.ps1 — лежит вне репозитория, не затирается деплоем.
`$PhpExe  = '$phpExe'
`$Port    = $Port
`$WmsRoot = '$WmsRoot'
"@
Set-Content -LiteralPath $localFile -Value $localContent -Encoding UTF8
Say "  записан $localFile" Green

# ---------------------------------------------------------------------------
Step '7. Автозапуск портала (планировщик Windows)'
if ($SkipTasks) {
    Say '  пропущено (-SkipTasks)' Yellow
} else {
    $powershell = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
    $startScript = Join-Path $appDir 'deploy\start-app.ps1'
    $watchdogScript = Join-Path $appDir 'deploy\watchdog.ps1'

    $startCmd = "`"$powershell`" -NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$startScript`" -Root `"$WmsRoot`" -Port $Port"
    $watchCmd = "`"$powershell`" -NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$watchdogScript`" -Root `"$WmsRoot`" -Port $Port"

    Say '  задача автозапуска при старте системы...'
    schtasks /Create /TN 'WMS-PHP-8001' /TR $startCmd /SC ONSTART /RU SYSTEM /RL HIGHEST /F | Out-Null
    Say '  задача-сторож каждые 5 минут...'
    schtasks /Create /TN 'WMS-Watchdog' /TR $watchCmd /SC MINUTE /MO 5 /RU SYSTEM /RL HIGHEST /F | Out-Null
    Say '  задачи созданы (WMS-PHP-8001, WMS-Watchdog)' Green

    Say '  запускаю портал прямо сейчас...'
    & $powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $startScript -Root $WmsRoot -Port $Port -Wait
}

# ---------------------------------------------------------------------------
if ($InstallSshd) {
    Step '8. OpenSSH-сервер (для GitHub Actions)'
    $cap = Get-WindowsCapability -Online -Name 'OpenSSH.Server~~~~0.0.1.0' -ErrorAction SilentlyContinue
    if ($cap -and $cap.State -ne 'Installed') {
        Say '  устанавливаю OpenSSH.Server...' Yellow
        Add-WindowsCapability -Online -Name 'OpenSSH.Server~~~~0.0.1.0' | Out-Null
    }
    $svc = Get-Service -Name sshd -ErrorAction SilentlyContinue
    if ($svc) {
        Set-Service -Name sshd -StartupType Automatic
        Start-Service sshd
        Say "  служба sshd: $((Get-Service sshd).Status)" Green
        if (-not (Get-NetFirewallRule -Name 'OpenSSH-Server-In-TCP' -ErrorAction SilentlyContinue)) {
            New-NetFirewallRule -Name 'OpenSSH-Server-In-TCP' -DisplayName 'OpenSSH Server (sshd)' `
                -Enabled True -Direction Inbound -Protocol TCP -Action Allow -LocalPort 22 | Out-Null
            Say '  открыт порт 22 в брандмауэре' Green
        }
    } else {
        Say '  OpenSSH.Server не установлен (Install-WindowsFeature OpenSSH.Server?)' Red
    }

    if ($SshPublicKey -and (Test-Path -LiteralPath $SshPublicKey)) {
        $pub = (Get-Content -LiteralPath $SshPublicKey -Raw).Trim()
        $isAdminGroup = $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
        if ($isAdminGroup) {
            $authorized = Join-Path $env:ProgramData 'ssh\administrators_authorized_keys'
        } else {
            $authorized = Join-Path $HOME '.ssh\authorized_keys'
        }
        $dir = Split-Path -Parent $authorized
        if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
        if ((Test-Path -LiteralPath $authorized) -and (Get-Content -LiteralPath $authorized -Raw) -match [regex]::Escape($pub)) {
            Say '  публичный ключ уже добавлен' DarkGray
        } else {
            Add-Content -LiteralPath $authorized -Value $pub -Encoding UTF8
            Say "  ключ добавлен в $authorized" Green
        }
        if ($isAdminGroup) {
            & icacls $authorized /inheritance:r /grant 'SYSTEM:(F)' /grant 'BUILTIN\Administrators:(F)' | Out-Null
            Say '  права на administrators_authorized_keys ужесточены (иначе sshd ключ не примет)' Green
        }
    } elseif ($SshPublicKey) {
        Say "  файл ключа не найден: $SshPublicKey" Red
    }
}

# ---------------------------------------------------------------------------
Step 'Готово'
Say "  Код:        $appDir"
Say "  Логи:       $logDir"
Say "  Локально:   http://localhost:$Port  (вход: /login)"
Say ''
Say 'Дальше:' Yellow
Say "  1) Если нужен доступ из интернета — запустите .\install-cloudflared.ps1 (см. deploy\README.md)." Yellow
Say '  2) В GitHub: Settings -> Secrets and variables -> Actions, добавьте секреты:' Yellow
Say '       SSH_HOST, SSH_PORT, SSH_USER, SSH_KEY (и SSH_PASSPHRASE, если ключ с паролем)' Yellow
Say '     и переменные (Variables) при необходимости: WMS_ROOT, WMS_PORT, WMS_KEEP_DATA.' Yellow
Say '  3) Сделайте push в ветку ' -NoNewline
Say $Branch Cyan -NoNewline
Say ' — запустится workflow "Deploy WMS (Windows, SSH)".' Yellow
Say '  4) Проверить хост до первого деплоя: Actions -> "Проверка хоста" -> Run workflow.' Yellow

if (Test-Path -LiteralPath (Join-Path $appDir 'deploy\README.md')) {
    Say ''
    Say "Подробная инструкция: $(Join-Path $appDir 'deploy\README.md')" Cyan
}
