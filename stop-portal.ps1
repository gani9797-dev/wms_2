$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$pidFile = Join-Path $root '.portal-pids.json'

function Get-PortOwner([int]$port) {
    try {
        $c = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction Stop | Select-Object -First 1
        if ($c) { return [int]$c.OwningProcess }
    } catch {}
    return 0
}

$stopped = @()
if (Test-Path -LiteralPath $pidFile) {
    try {
        $rows = Get-Content -LiteralPath $pidFile -Raw | ConvertFrom-Json
        foreach ($row in @($rows)) {
            $p = Get-Process -Id ([int]$row.pid) -ErrorAction SilentlyContinue
            if ($p) {
                try { Stop-Process -Id $p.Id -Force -ErrorAction Stop; $stopped += "$($row.role) ($($row.pid))" } catch {}
            }
        }
    } catch {}
}

foreach ($port in @(8001, 8011, 8012, 8013, 8014)) {
    $procId = Get-PortOwner $port
    if ($procId -gt 0) {
        $p = Get-Process -Id $procId -ErrorAction SilentlyContinue
        if ($p -and ($p.ProcessName -match 'php|caddy')) {
            try { Stop-Process -Id $procId -Force -ErrorAction Stop; $stopped += "$($p.ProcessName) ($procId)" } catch {}
        }
    }
}

Remove-Item -LiteralPath $pidFile -ErrorAction SilentlyContinue
if ($stopped.Count -gt 0) {
    Write-Output ("Остановлено: " + (($stopped | Select-Object -Unique) -join ', '))
} else {
    Write-Output 'Процессы портала не найдены'
}
