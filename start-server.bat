@echo off
rem TiMLog: Caddy :8001 + 4 PHP-воркера (8011-8014), gzip включён.
rem Подробности: Caddyfile, start-portal.ps1, stop-portal.ps1.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-portal.ps1"
