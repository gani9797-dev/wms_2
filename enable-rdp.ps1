# Включение RDP для локального подключения
# ЗАПУСКАТЬ: правой кнопкой -> Запуск от имени администратора
#Requires -RunAsAdministrator

Write-Host "=== Включение Удаленного рабочего стола ===" -ForegroundColor Green

# 1. Разрешить RDP
Set-ItemProperty -Path 'HKLM:\System\CurrentControlSet\Control\Terminal Server' -Name "fDenyTSConnections" -Value 0
Write-Host "[OK] RDP разрешен в реестре" 

# 2. Оставить NLA включенным (безопаснее). Если ноутбук очень старый - поставьте 0
# Set-ItemProperty -Path 'HKLM:\System\CurrentControlSet\Control\Terminal Server\WinStations\RDP-Tcp' -Name "UserAuthentication" -Value 1

# 3. Служба Remote Desktop Services -> Автоматом + запустить
Set-Service -Name TermService -StartupType Automatic
Start-Service -Name TermService
Write-Host "[OK] Служба TermService запущена"

# 4. Разрешить в брандмауэре
Enable-NetFirewallRule -DisplayGroup "Remote Desktop"
Write-Host "[OK] Правило firewall включено"

# 5. Сеть Daniil_5G сделать частной (чтобы ноутбук видел ПК)
try {
  Set-NetConnectionProfile -Name "Daniil_5G" -NetworkCategory Private -ErrorAction Stop
  Write-Host "[OK] Сеть Daniil_5G -> Частная"
} catch {
  Write-Host "[!] Не удалось сменить профиль сети: $_" -ForegroundColor Yellow
}

# 6. Запретить уход в сон (иначе RDP отвалится)
powercfg /change standby-timeout-ac 0
powercfg /change standby-timeout-dc 30
Write-Host "[OK] Сон от сети отключен"

# 7. Показать данные для подключения
Write-Host ""
Write-Host "=== ДАННЫЕ ДЛЯ ПОДКЛЮЧЕНИЯ С НОУТБУКА ===" -ForegroundColor Cyan
Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -like "192.168.*" -and $_.PrefixOrigin -ne "WellKnown" } | Format-Table InterfaceAlias, IPAddress -AutoSize
Write-Host "Имя ПК: $env:COMPUTERNAME"
Write-Host "Пользователь: $env:USERNAME\$env:USERDOMAIN или .\Даниил"
Write-Host ""
Write-Host "На ноутбуке: Win+R -> mstsc -> введите IP (например 192.168.50.39) -> Подключить" -ForegroundColor Yellow
Write-Host "ВАЖНО: у учетки должен быть ПАРОЛЬ, без пароля RDP не пустит!" -ForegroundColor Red
Write-Host "Задать пароль: Параметры -> Учетные записи -> Параметры входа -> Пароль"
Write-Host ""
pause
