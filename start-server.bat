@echo off
rem Автозапуск портала TiMLog: PHP-сервер на 0.0.0.0:8001 через router.php (защита логином).
rem Если порт уже слушается - ничего не делает (защита от дублей).
netstat -ano | findstr /C:":8001 " | findstr /C:"LISTENING" >nul
if %errorlevel%==0 exit /b 0
start "WMS-PHP-8001" /min C:\Users\1678~1\AppData\Local\MICROS~1\WinGet\Packages\PHPPHP~1.SOU\php.exe -S 0.0.0.0:8001 -t C:\Users\1678~1\DOCUME~1\wms_2 C:\Users\1678~1\DOCUME~1\wms_2\router.php
