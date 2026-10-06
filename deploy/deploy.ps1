#requires -Version 5.1
<#
.SYNOPSIS
    Деплой TiMLog (WMS) на Windows-сервер. Скрипт запускается на сервере
    из GitHub Actions по SSH (appleboy/ssh-action).

.DESCRIPTION
    1. При первом запуске клонирует репозиторий в C:\wms\app.
    2. Останавливает PHP-сервер (иначе Windows не даст перезаписать .sqlite).
    3. Забирает из GitHub нужный коммит и накатывает его (git checkout -f + reset --hard).
       Так как .sqlite-файлы лежат в репозитории, они тоже обновляются —
       данные склада перезаливаются тем, что запушено в GitHub.
    4. Запускает PHP-сервер и проверяет, что портал отвечает.
    5. Если портал не поднялся — автоматически откатывается на предыдущий коммит.

.EXAMPLE
    powershell -NoProfile -File C:\wms\app\deploy\deploy.ps1 -Sha 9f3c1ab -Port 8001

.EXAMPLE
    powershell -NoProfile -File C:\wms\app\deploy\deploy.ps1 -Ref master -KeepData
#>
[CmdletBinding()]
param(
    # Конкретный коммит (передаёт GitHub Actions)
    [string]$Sha = '',
    # Ветка, тег или SHA, если коммит не задан
    [string]$Ref = '',
    # Корень установки (по умолчанию C:\wms или WMS_ROOT)
    [string]$Root = '',
    # Порт PHP-сервера (по умолчанию 8001 или WMS_PORT)
    [int]$Port = 0,
    # Не перезаливать SQLite из GitHub, а вернуть то, что было на сервере
    [switch]$KeepData,
    # Перезапустить службу cloudflared после деплоя
    [switch]$RestartTunnel,
    # Удалить неотслеженные файлы в папке приложения (git clean -fd)
    [switch]$Clean,
    [switch]$SkipLint,
    [switch]$SkipSmokeTest,
    # Только проверка хоста без деплоя (использует workflow "Проверка хоста")
    [switch]$Preflight
)

$ErrorActionPreference = 'Stop'

if ($Root) { $env:WMS_ROOT = $Root }
if ($Port -gt 0) { $env:WMS_PORT = [string]$Port }

. (Join-Path $PSScriptRoot 'common.ps1')

# ---------------------------------------------------------------------------
# Предпроверка
# ---------------------------------------------------------------------------
if ($Preflight) {
    Write-Log '=== Предпроверка хоста ==='
    Write-Log "WMS_ROOT : $WmsRoot"
    Write-Log "AppDir   : $AppDir"
    Write-Log "Порт     : $Port"

    $php = Resolve-Php
    Write-Log "PHP      : $php"
    $version = & $php -r 'echo PHP_VERSION;' 2>&1
    Write-Log "Версия   : $version"

    $modules = @(& $php -m 2>&1)
    foreach ($m in @('pdo_sqlite', 'sqlite3', 'mbstring', 'json', 'session')) {
        if ($modules -contains $m) {
            Write-Log "Расширение $m : OK"
        } else {
            Write-Log "Расширение $m : ОТСУТСТВУЕТ (включите в php.ini)" 'ERROR'
        }
    }

    $git = Get-GitExe
    $gitVersion = & $git --version 2>&1
    Write-Log "Git      : $git ($gitVersion)"

    foreach ($d in @($WmsRoot, $AppDir, $BinDir, $LogDir, $StateDir, $EtcDir)) {
        $exists = Test-Path -LiteralPath $d
        if ($exists) { Write-Log "Каталог $d : есть" } else { Write-Log "Каталог $d : отсутствует" 'WARN' }
    }

    $disk = Get-PSDrive -Name ($WmsRoot.Substring(0, 1)) -ErrorAction SilentlyContinue
    if ($disk) { Write-Log ("Свободно на диске {0}: {1:N0} МБ" -f $disk.Name, ($disk.Free / 1MB)) }

    if (Test-Path -LiteralPath (Join-Path $AppDir '.git')) {
        Write-Log "Репозиторий: развёрнут"
        $remote = & $git -C $AppDir remote get-url $GitRemote 2>&1
        Write-Log "Remote     : $remote"
        $current = Get-CurrentSha
        Write-Log "Коммит     : $current"
        $dirty = & $git -C $AppDir status --porcelain 2>&1
        if ($dirty) { Write-Log 'Внимание: в рабочей копии есть незакоммиченные изменения (деплой их затрёт)' 'WARN' }
        $files = Get-SqliteFiles
        Write-Log ("Файлов БД : " + $files.Count)
    } else {
        Write-Log "Репозиторий: ЕЩЁ НЕ клонирован (запустите bootstrap.ps1)" 'WARN'
    }

    $listeners = Get-ListeningProcessId -LocalPort $Port
    if ($listeners.Count -gt 0) {
        Write-Log "Порт $Port слушают PID: $($listeners -join ', ')"
    } else {
        Write-Log "Порт $Port свободен (приложение не запущено)" 'WARN'
    }

    $tunnel = Get-Service -Name $CloudflaredService -ErrorAction SilentlyContinue
    if ($tunnel) { Write-Log "Туннель   : служба $CloudflaredService -> $($tunnel.Status)" }
    else { Write-Log "Туннель   : служба $CloudflaredService не установлена" 'WARN' }

    Write-Log '=== Предпроверка завершена ==='
    exit 0
}

# ---------------------------------------------------------------------------
# Деплой
# ---------------------------------------------------------------------------
$backupDir = ''
try {
    Write-Log '=== Деплой TiMLog ==='
    Ensure-Dir $WmsRoot
    Ensure-Dir $BinDir
    Ensure-Dir $LogDir
    Ensure-Dir $StateDir
    Ensure-Dir $EtcDir

    New-DeployLock

    # --- 1. Клон репозитория при первом деплое ------------------------------
    if (-not (Test-Path -LiteralPath (Join-Path $AppDir '.git'))) {
        $gitExe = Get-GitExe
        if ((Test-Path -LiteralPath $AppDir) -and (Get-ChildItem -LiteralPath $AppDir -Force | Measure-Object).Count -gt 0) {
            throw "Каталог $AppDir существует, но это не git-репозиторий. Удалите его или укажите другой WMS_ROOT."
        }
        Write-Log "Первый деплой: клонирую $RepoUrl -> $AppDir"
        $cloneOutput = & $gitExe clone $RepoUrl $AppDir 2>&1
        $cloneCode = $LASTEXITCODE
        foreach ($line in $cloneOutput) { Write-Log "  $line" 'DEBUG' }
        if ($cloneCode -ne 0) {
            throw "Не удалось клонировать $RepoUrl. Проверьте доступ к приватному репозиторию: положите deploy key в $EtcDir\deploy_key или сохраните учётные данные в диспетчере учётных данных Windows."
        }
    }

    # --- 2. Что деплоим -----------------------------------------------------
    $target = $Sha
    if (-not $target) { $target = $Ref }
    if (-not $target) { $target = $Branch }
    Write-Log "Цель деплоя: $target"

    Invoke-Git @('fetch', '--prune', '--tags', $GitRemote, '+refs/heads/*:refs/remotes/origin/*')
    $commitSha = Resolve-TargetSha $target
    Write-Log "Коммит: $commitSha"

    $previousSha = Get-CurrentSha
    if ($previousSha) { Write-Log "Предыдущий коммит: $previousSha" } else { Write-Log 'Предыдущий коммит: нет' }

    # --- 3. Остановка приложения -------------------------------------------
    Stop-WmsApp -LocalPort $Port

    # Журналы SQLite удаляем ДО обновления файлов, иначе старые транзакции
    # накатятся поверх данных из GitHub.
    Remove-SqliteJournals

    # --- 4. Сохранение данных (если просили) --------------------------------
    if ($KeepData) {
        $backupDir = Join-Path $StateDir 'db-backup'
        $count = Backup-SqliteData -Destination $backupDir
        if ($count -eq 0) { $backupDir = '' }
    } else {
        Write-Log 'Политика: данные SQLite перезаливаются из GitHub (-KeepData не указан)'
    }

    # --- 5. Обновление кода -------------------------------------------------
    Invoke-Git @('checkout', '-f', $commitSha)
    Invoke-Git @('reset', '--hard', $commitSha)
    if ($Clean) {
        Write-Log 'Удаляю неотслеженные файлы (git clean -fd)' 'WARN'
        Invoke-Git @('clean', '-fd')
    }

    if ($KeepData -and $backupDir) {
        Restore-SqliteData -Source $backupDir
    }

    $headSha = Get-CurrentSha
    Write-Log "Рабочая копия обновлена до $headSha"

    # --- 6. Проверка синтаксиса --------------------------------------------
    if (-not $SkipLint) {
        Test-PhpSyntax
    }

    # --- 7. Запуск ----------------------------------------------------------
    Start-WmsApp -LocalPort $Port | Out-Null

    if (-not $SkipSmokeTest) {
        if (Test-WmsHealth -LocalPort $Port -Path $HealthPath -TimeoutSec $HealthWait) {
            Write-Log "Проверка доступности пройдена: http://127.0.0.1:$Port$HealthPath"
        } else {
            Write-Log 'Приложение не отвечает — выполняю откат' 'ERROR'
            if ($previousSha -and $previousSha -ne $commitSha) {
                Stop-WmsApp -LocalPort $Port
                Remove-SqliteJournals
                Invoke-Git @('checkout', '-f', $previousSha)
                Invoke-Git @('reset', '--hard', $previousSha)
                if ($backupDir) { Restore-SqliteData -Source $backupDir }
                Start-WmsApp -LocalPort $Port | Out-Null
                if (Test-WmsHealth -LocalPort $Port -Path $HealthPath -TimeoutSec $HealthWait) {
                    Write-Log "Откат выполнен, работает коммит $previousSha"
                } else {
                    Write-Log 'Откат не помог — проверьте логи PHP в папке logs' 'ERROR'
                }
            }
            throw "Деплой коммита $commitSha не прошёл проверку доступности"
        }
    }

    # --- 8. Служебные действия ---------------------------------------------
    Set-CurrentSha $commitSha
    $summary = @{
        sha          = $commitSha
        target       = $target
        previous_sha = $previousSha
        deployed_at  = (Get-Date).ToString('o')
        keep_data    = [bool]$KeepData
        port         = $Port
    }
    ($summary | ConvertTo-Json) | Set-Content -LiteralPath (Join-Path $StateDir 'last-deploy.json') -Encoding UTF8

    if ($RestartTunnel) {
        Restart-CloudflaredService
    }

    Write-Log '=== Деплой завершён успешно ==='
    exit 0
} catch {
    Write-Log ("ОШИБКА ДЕПЛОЯ: " + $_.Exception.Message) 'ERROR'
    Write-Log ($_.ScriptStackTrace) 'DEBUG'
    exit 1
} finally {
    Remove-DeployLock
}
