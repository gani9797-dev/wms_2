# deploy\config.ps1 — настройки деплоя TiMLog на Windows-хосте.
# Подключается через dot-sourcing из остальных скриптов: . (Join-Path $PSScriptRoot 'config.ps1')
#
# Любой параметр можно переопределить переменной окружения (так их передаёт
# GitHub Actions и планировщик заданий Windows).

function Get-WmsSetting {
    param(
        [string]$Name,
        [string]$Default
    )
    $value = [Environment]::GetEnvironmentVariable($Name)
    if ([string]::IsNullOrWhiteSpace($value)) { return $Default }
    return $value
}

# Скрипты лежат в <корень>\app\deploy, поэтому корень определяем по своему
# расположению — так не нужно следить, чтобы WMS_ROOT в GitHub совпадал с серверным.

# --- Каталоги установки -------------------------------------------------------
# C:\wms\app    — клон репозитория (код и данные из GitHub)
# C:\wms\bin    — cloudflared.exe и прочие утилиты (вне репозитория, git их не трогает)
# C:\wms\logs   — логи PHP-сервера и деплоя
# C:\wms\state  — текущий SHA, бэкапы БД, локальные переопределения
# C:\wms\etc    — конфиги cloudflared, SSH deploy key
$detectedRoot = ''
$appParent = Split-Path -Parent $PSScriptRoot          # <корень>\app
if ($appParent) {
    $candidate = Split-Path -Parent $appParent         # <корень>
    if ($candidate -and (Test-Path -LiteralPath (Join-Path $candidate 'app'))) {
        $detectedRoot = $candidate
    }
}
if (-not $detectedRoot) { $detectedRoot = 'C:\wms' }

$WmsRoot  = Get-WmsSetting 'WMS_ROOT' $detectedRoot
$AppDir   = Join-Path $WmsRoot 'app'
$BinDir   = Join-Path $WmsRoot 'bin'
$LogDir   = Join-Path $WmsRoot 'logs'
$StateDir = Join-Path $WmsRoot 'state'
$EtcDir   = Join-Path $WmsRoot 'etc'

# --- Репозиторий и приложение -------------------------------------------------
$RepoUrl    = Get-WmsSetting 'WMS_REPO_URL' 'https://github.com/gani9797-dev/wms_2.git'
$GitRemote  = Get-WmsSetting 'WMS_GIT_REMOTE' 'origin'
$Branch     = Get-WmsSetting 'WMS_BRANCH' 'arena/448262c5-wms-2'
$Port       = [int](Get-WmsSetting 'WMS_PORT' '8001')
$PhpExe     = Get-WmsSetting 'WMS_PHP_EXE' 'php'
$RouterFile = Get-WmsSetting 'WMS_ROUTER' 'router.php'

# --- Проверка здоровья --------------------------------------------------------
$HealthPath = Get-WmsSetting 'WMS_HEALTH_PATH' '/login'
$HealthWait = [int](Get-WmsSetting 'WMS_HEALTH_WAIT' '40')

# --- Планировщик заданий Windows ---------------------------------------------
$TaskName     = Get-WmsSetting 'WMS_TASK_NAME' 'WMS-PHP-8001'
$WatchdogTask = Get-WmsSetting 'WMS_WATCHDOG_TASK' 'WMS-Watchdog'

# --- Cloudflare Tunnel --------------------------------------------------------
$CloudflaredExe     = Get-WmsSetting 'WMS_CLOUDFLARED_EXE' (Join-Path $BinDir 'cloudflared.exe')
$CloudflaredService = Get-WmsSetting 'WMS_CLOUDFLARED_SERVICE' 'cloudflared'

# Локальные переопределения (создаёт bootstrap.ps1: реальный путь к php.exe и т.п.)
$WmsLocalConfig = Join-Path $StateDir 'local.ps1'
if (Test-Path -LiteralPath $WmsLocalConfig) {
    . $WmsLocalConfig
}
