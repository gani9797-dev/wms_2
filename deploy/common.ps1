# deploy\common.ps1 — общие функции деплоя TiMLog (Windows).
# Подключение: . (Join-Path $PSScriptRoot 'common.ps1')   (после этого доступен config.ps1)

. (Join-Path $PSScriptRoot 'config.ps1')

# Чтобы git не пытался интерактивно спросить логин/пароль.
$env:GIT_TERMINAL_PROMPT = '0'

# Если в C:\wms\etc\deploy_key лежит приватный ключ — используем его для доступа к GitHub.
$WmsDeployKey = Join-Path $EtcDir 'deploy_key'
if (Test-Path -LiteralPath $WmsDeployKey) {
    $env:GIT_SSH_COMMAND = "ssh -i $WmsDeployKey -o StrictHostKeyChecking=accept-new -o IdentitiesOnly=yes"
}

function Ensure-Dir {
    param([string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) {
        New-Item -ItemType Directory -Path $Path -Force | Out-Null
    }
}

function Write-Log {
    param(
        [string]$Message,
        [string]$Level = 'INFO'
    )
    $line = "[{0}] [{1}] {2}" -f (Get-Date).ToString('yyyy-MM-dd HH:mm:ss'), $Level, $Message
    Write-Host $line
    try {
        Ensure-Dir $LogDir
        Add-Content -LiteralPath (Join-Path $LogDir 'deploy.log') -Value $line -Encoding UTF8
    } catch {
        # лог не должен ломать деплой
    }
}

function Get-GitExe {
    $cmd = Get-Command git -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    $fallback = 'C:\Program Files\Git\cmd\git.exe'
    if (Test-Path -LiteralPath $fallback) { return $fallback }
    throw 'git не найден. Установите: winget install --id Git.Git -e'
}

function Invoke-Git {
    param(
        [Parameter(ValueFromRemainingArguments = $true)]
        [string[]]$GitArgs
    )
    $git = Get-GitExe
    $all = @('-C', $AppDir) + $GitArgs
    Write-Log ("git " + ($all -join ' ')) 'DEBUG'
    $output = & $git @all 2>&1
    $code = $LASTEXITCODE
    foreach ($line in $output) { Write-Log ("  " + $line) 'DEBUG' }
    if ($code -ne 0) {
        throw "git завершился с кодом $code : git $($GitArgs -join ' ')"
    }
    return $output
}

function Resolve-Php {
    # 1) явный путь из настроек, 2) php в PATH, 3) типовые пути установки
    if ($PhpExe -and (Test-Path -LiteralPath $PhpExe)) { return $PhpExe }
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    $candidates = @(
        'C:\tools\php83\php.exe',
        'C:\tools\php82\php.exe',
        'C:\Program Files\PHP\v8.3\php.exe',
        'C:\Program Files\PHP\v8.2\php.exe',
        'C:\php\php.exe'
    )
    foreach ($c in $candidates) {
        if (Test-Path -LiteralPath $c) { return $c }
    }
    throw 'php.exe не найден. Установите: winget install --id PHP.PHP.8.3 -e (нужны расширения pdo_sqlite и sqlite3)'
}

function Get-ListeningProcessId {
    param([int]$LocalPort)
    $result = @()
    $connections = Get-NetTCPConnection -LocalPort $LocalPort -State Listen -ErrorAction SilentlyContinue
    foreach ($c in $connections) {
        if ($result -notcontains $c.OwningProcess) { $result += $c.OwningProcess }
    }
    return $result
}

function Stop-WmsApp {
    param([int]$LocalPort)
    $processIds = Get-ListeningProcessId -LocalPort $LocalPort
    if ($processIds.Count -eq 0) {
        Write-Log "Порт $LocalPort свободен — останавливать нечего"
        return
    }
    foreach ($procId in $processIds) {
        $proc = Get-Process -Id $procId -ErrorAction SilentlyContinue
        $name = if ($proc) { $proc.ProcessName } else { 'неизвестен' }
        Write-Log "Останавливаю процесс $procId ($name), слушающий порт $LocalPort"
        Stop-Process -Id $procId -Force -ErrorAction SilentlyContinue
    }
    for ($i = 0; $i -lt 40; $i++) {
        Start-Sleep -Milliseconds 250
        if ((Get-ListeningProcessId -LocalPort $LocalPort).Count -eq 0) {
            Write-Log "Порт $LocalPort освобождён"
            return
        }
    }
    throw "Порт $LocalPort всё ещё занят, приложение не остановилось"
}

function Start-WmsApp {
    param([int]$LocalPort)
    $php = Resolve-Php
    $router = Join-Path $AppDir $RouterFile
    if (-not (Test-Path -LiteralPath $router)) {
        throw "Не найден $router — репозиторий не распакован?"
    }
    Ensure-Dir $LogDir
    $stamp = (Get-Date).ToString('yyyyMMdd-HHmmss')
    $outLog = Join-Path $LogDir "php-$stamp.out.log"
    $errLog = Join-Path $LogDir "php-$stamp.err.log"

    Write-Log "Запуск: $php -S 0.0.0.0:$LocalPort -t `"$AppDir`" `"$router`""

    # Start-Process с редиректом в файлы: процесс живёт после выхода скрипта
    # и не блокируется на переполнении буфера вывода.
    $proc = Start-Process -FilePath $php `
        -ArgumentList @('-S', "0.0.0.0:$LocalPort", '-t', $AppDir, $router) `
        -WorkingDirectory $AppDir `
        -RedirectStandardOutput $outLog `
        -RedirectStandardError $errLog `
        -WindowStyle Hidden `
        -PassThru

    Write-Log "PHP-сервер запущен, PID $($proc.Id), логи: $outLog / $errLog"
    return $proc
}

function Test-WmsHealth {
    param(
        [int]$LocalPort,
        [string]$Path = '/login',
        [int]$TimeoutSec = 40
    )
    $deadline = (Get-Date).AddSeconds($TimeoutSec)
    while ((Get-Date) -lt $deadline) {
        $client = New-Object System.Net.Sockets.TcpClient
        try {
            $task = $client.ConnectAsync('127.0.0.1', $LocalPort)
            if ($task.Wait(2000) -and $client.Connected) {
                try {
                    $response = Invoke-WebRequest -Uri "http://127.0.0.1:$LocalPort$Path" `
                        -UseBasicParsing -TimeoutSec 5 -ErrorAction Stop
                    if ([int]$response.StatusCode -eq 200) { return $true }
                } catch {
                    Write-Log ("  HTTP-проверка не удалась: " + $_.Exception.Message) 'DEBUG'
                }
            }
        } catch {
            Write-Log ("  Порт недоступен: " + $_.Exception.Message) 'DEBUG'
        } finally {
            $client.Dispose()
        }
        Start-Sleep -Seconds 1
    }
    return $false
}

# --- Блокировка деплоя --------------------------------------------------------
# Пока идёт деплой, сторож не должен поднимать портал: он снова займёт .sqlite-файлы
# и git не сможет их перезаписать.
function Get-DeployLockFile {
    return (Join-Path $StateDir 'deploy.lock')
}

function New-DeployLock {
    Ensure-Dir $StateDir
    Set-Content -LiteralPath (Get-DeployLockFile) -Value ("pid=$PID started=" + (Get-Date).ToString('o')) -Encoding ASCII
    Write-Log 'Взята блокировка деплоя (сторож не будет мешать)'
}

function Remove-DeployLock {
    Remove-Item -LiteralPath (Get-DeployLockFile) -Force -ErrorAction SilentlyContinue
}

function Test-DeployLock {
    $lock = Get-DeployLockFile
    if (-not (Test-Path -LiteralPath $lock)) { return $false }
    $age = ((Get-Date) - (Get-Item -LiteralPath $lock).LastWriteTime).TotalMinutes
    if ($age -gt 20) {
        Write-Log 'Найден протухший deploy.lock (деплой аварийно завершился) — игнорирую' 'WARN'
        return $false
    }
    return $true
}

function Get-SqliteFiles {
    if (-not (Test-Path -LiteralPath $AppDir)) { return @() }
    return @(Get-ChildItem -LiteralPath $AppDir -Recurse -File -Force |
        Where-Object { $_.Extension -in @('.sqlite', '.sqlite3', '.db') } |
        Where-Object { $_.FullName -notmatch '[\\/]\.git[\\/]' })
}

function Remove-SqliteJournals {
    param([string]$Path = $AppDir)
    # WAL/SHM/journal — это «хвост» предыдущей работы с БД. Если их оставить,
    # SQLite накатит старые транзакции поверх файлов, взятых из GitHub.
    $files = @(Get-ChildItem -LiteralPath $Path -Recurse -File -Force -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -match '\.(sqlite-wal|sqlite-shm|sqlite-journal|db-wal|db-shm|db-journal)$' })
    foreach ($f in $files) {
        Write-Log "Удаляю журнал БД: $($f.FullName)"
        Remove-Item -LiteralPath $f.FullName -Force -ErrorAction SilentlyContinue
    }
    if ($files.Count -gt 0) { Write-Log "Удалено журналов БД: $($files.Count)" }
}

function Backup-SqliteData {
    param([string]$Destination)
    if (Test-Path -LiteralPath $Destination) {
        Remove-Item -LiteralPath $Destination -Recurse -Force -ErrorAction SilentlyContinue
    }
    Ensure-Dir $Destination
    $files = Get-SqliteFiles
    foreach ($f in $files) {
        $relative = $f.FullName.Substring($AppDir.Length).TrimStart('\', '/')
        $target = Join-Path $Destination $relative
        Ensure-Dir (Split-Path -Parent $target)
        Copy-Item -LiteralPath $f.FullName -Destination $target -Force
    }
    Write-Log "Сохранено файлов БД перед обновлением: $($files.Count)"
    return $files.Count
}

function Restore-SqliteData {
    param([string]$Source)
    if (-not (Test-Path -LiteralPath $Source)) { return }
    $files = @(Get-ChildItem -LiteralPath $Source -Recurse -File -Force)
    foreach ($f in $files) {
        $relative = $f.FullName.Substring($Source.Length).TrimStart('\', '/')
        $target = Join-Path $AppDir $relative
        Ensure-Dir (Split-Path -Parent $target)
        Copy-Item -LiteralPath $f.FullName -Destination $target -Force
        Write-Log "Восстановлен файл БД: $relative"
    }
    Write-Log "Восстановлено файлов БД: $($files.Count)"
}

function Test-PhpSyntax {
    param([string]$Path = $AppDir)
    $php = Resolve-Php
    $files = @(Get-ChildItem -LiteralPath $Path -Recurse -File -Filter '*.php' |
        Where-Object { $_.FullName -notmatch '[\\/]\.git[\\/]' })
    $bad = 0
    foreach ($f in $files) {
        $output = & $php -l $f.FullName 2>&1
        if ($LASTEXITCODE -ne 0) {
            $bad++
            Write-Log ("Ошибка синтаксиса: " + ($output -join ' ')) 'ERROR'
        }
    }
    if ($bad -gt 0) { throw "Файлов с синтаксическими ошибками: $bad" }
    Write-Log "PHP lint: проверено $($files.Count) файлов, ошибок нет"
}

function Get-CurrentSha {
    if (-not (Test-Path -LiteralPath (Join-Path $AppDir '.git'))) { return '' }
    $git = Get-GitExe
    $sha = & $git -C $AppDir rev-parse HEAD 2>$null
    if ($LASTEXITCODE -eq 0 -and $sha) { return $sha.Trim() }
    return ''
}

function Set-CurrentSha {
    param([string]$Sha)
    Ensure-Dir $StateDir
    Set-Content -LiteralPath (Join-Path $StateDir 'current.sha') -Value $Sha -Encoding UTF8
}

function Resolve-TargetSha {
    param([string]$Target)
    $git = Get-GitExe
    $candidates = @("refs/remotes/$GitRemote/$Target", "refs/tags/$Target", "refs/heads/$Target", $Target)
    foreach ($candidate in $candidates) {
        $sha = & $git -C $AppDir rev-parse --verify --quiet "$candidate^{commit}" 2>$null
        if ($LASTEXITCODE -eq 0 -and $sha) {
            $sha = ($sha | Select-Object -First 1).ToString().Trim()
            if ($sha) { return $sha }
        }
    }
    throw "Не удалось найти коммит '$Target' в $AppDir (проверьте, что он запушен в GitHub)"
}

function Restart-CloudflaredService {
    $service = Get-Service -Name $CloudflaredService -ErrorAction SilentlyContinue
    if (-not $service) {
        Write-Log "Служба '$CloudflaredService' не найдена — пропускаю перезапуск" 'WARN'
        return
    }
    Write-Log "Перезапуск службы $CloudflaredService"
    Restart-Service -Name $CloudflaredService -Force -ErrorAction Stop
    Write-Log "Служба $CloudflaredService перезапущена"
}
