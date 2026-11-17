$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$logDir = Join-Path $root 'logs'
$pidFile = Join-Path $root '.portal-pids.json'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null

function Get-PortOwner([int]$port) {
    try {
        $c = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction Stop | Select-Object -First 1
        if ($c) { return [int]$c.OwningProcess }
    } catch {}
    return 0
}

$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php) {
    $php = (Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages" -Filter 'php.exe' -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1).FullName
}
if (-not $php) { throw 'php.exe не найден' }

$caddy = (Get-Command caddy -ErrorAction SilentlyContinue).Source
if (-not $caddy) {
    $caddy = (Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages" -Filter 'caddy.exe' -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1).FullName
}
if (-not $caddy) { throw 'caddy.exe не найден (winget install CaddyServer.Caddy)' }

# Останавливаем прежний php -S :8001 и наши прошлые воркеры/прокси.
foreach ($port in @(8001, 8011, 8012, 8013, 8014)) {
    $procId = Get-PortOwner $port
    if ($procId -gt 0) {
        $p = Get-Process -Id $procId -ErrorAction SilentlyContinue
        if ($p -and ($p.ProcessName -match 'php|caddy')) {
            try { Stop-Process -Id $procId -Force -ErrorAction Stop } catch {}
        }
    }
}
Start-Sleep -Milliseconds 700

$started = @()
for ($i = 0; $i -lt 4; $i++) {
    $port = 8011 + $i
    $args = @('-S', "127.0.0.1:$port", '-t', $root, (Join-Path $root 'router.php'))
    $p = Start-Process -FilePath $php -ArgumentList $args -WorkingDirectory $root -WindowStyle Hidden -PassThru
    $started += [pscustomobject]@{ role = "php-$port"; pid = $p.Id; port = $port }
}

Start-Sleep -Seconds 1
$caddyArgs = @('run', '--config', (Join-Path $root 'Caddyfile'), '--adapter', 'caddyfile')
$cp = Start-Process -FilePath $caddy -ArgumentList $caddyArgs -WorkingDirectory $root -WindowStyle Hidden -PassThru
$started += [pscustomobject]@{ role = 'caddy'; pid = $cp.Id; port = 8001 }

$started | ConvertTo-Json | Set-Content -LiteralPath $pidFile -Encoding UTF8
Start-Sleep -Seconds 2

$code = (& curl.exe -s -o NUL -w "%{http_code}" --max-time 10 'http://127.0.0.1:8001/login').Trim()
if ($code -ne '200') {
    Write-Error "Портал не отвечает на :8001 (HTTP $code)"
    throw
}
Write-Output "Портал запущен: HTTP 200, воркеров PHP: 4, прокси Caddy :8001"
$started | Format-Table -AutoSize

