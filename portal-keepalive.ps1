$ErrorActionPreference = 'SilentlyContinue'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$php = 'C:\Users\1678~1\AppData\Local\MICROS~1\WinGet\Packages\PHPPHP~1.SOU\php.exe'
$tailscale = 'C:\Program Files\Tailscale\tailscale.exe'

if (-not (Get-NetTCPConnection -State Listen -LocalPort 8001)) {
    if (Test-Path $php) {
        Start-Process -FilePath $php -ArgumentList '-S', '0.0.0.0:8001', '-t', $root, "$root\router.php" -WindowStyle Hidden
    }
}

if (Test-Path $tailscale) {
    & $tailscale funnel --bg --yes 8001 2>$null | Out-Null
}
