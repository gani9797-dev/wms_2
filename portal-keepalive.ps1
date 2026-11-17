$ErrorActionPreference = 'SilentlyContinue'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$tailscale = 'C:\Program Files\Tailscale\tailscale.exe'

function Test-Port([int]$port) {
    return [bool](Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue)
}

# Портал = Caddy :8001 + 4 PHP-воркера 8011-8014.
$ok = (Test-Port 8001) -and (Test-Port 8011) -and (Test-Port 8012) -and (Test-Port 8013) -and (Test-Port 8014)
if (-not $ok) {
    Start-Process -FilePath 'powershell.exe' -ArgumentList @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $root 'start-portal.ps1')
    ) -WindowStyle Hidden
}

if (Test-Path $tailscale) {
    & $tailscale funnel --bg --yes 8001 2>$null | Out-Null
}
