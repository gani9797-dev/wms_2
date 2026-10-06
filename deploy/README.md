# Деплой TiMLog (WMS) на свой Windows-сервер через GitHub Actions

Код и данные проекта живут в GitHub. Вы делаете `push` — GitHub Actions подключается к
вашему серверу по SSH и обновляет портал. Никаких «скопировать файлы руками».

```
разработчик ──push──> GitHub ──GitHub Actions──> SSH (appleboy/ssh-action) ──> сервер
                                                                                │
                              deploy\deploy.ps1: стоп портала → git fetch/checkout → старт
                                                                                │
                       cloudflared (туннель) <── исходящее соединение ──> Cloudflare ──> интернет
```

* **Источник правды — GitHub.** Сервер всегда приводится к конкретному коммиту.
* **Наружу не открыто ничего**, кроме (по желанию) 22-го порта для SSH. Портал наружу
  отдаёт cloudflared-туннель.
* **Данные**: `.sqlite`-файлы лежат в репозитории, поэтому при деплое они перезаливаются
  тем, что запушено в GitHub (см. [Политику данных](#данные-и-бд)).

---

## 1. Быстрый старт

### 1.1. Что нужно на сервере

* Windows 10/11 или Windows Server 2019/2022, права администратора;
* PHP 8.x с расширениями `pdo_sqlite`, `sqlite3`, `mbstring`;
* Git для Windows;
* OpenSSH Server (ставится bootstrap'ом);
* cloudflared — если нужен доступ из интернета.

### 1.2. Ключ для GitHub Actions

На своей машине (не на сервере):

```powershell
ssh-keygen -t ed25519 -C "wms-deploy" -f $HOME\.ssh\wms_deploy
# wms_deploy     -> приватный, его положим в секрет SSH_KEY
# wms_deploy.pub -> публичный, его положим на сервер
```

### 1.3. Подготовка сервера

Скопируйте папку `deploy` из репозитория на сервер (например в `C:\Temp\deploy`) и в
PowerShell от имени администратора:

```powershell
cd C:\Temp\deploy
powershell -ExecutionPolicy Bypass -File .\bootstrap.ps1 `
    -GitPat ghp_xxxxxxxxxxxxxxxxxxxx `
    -GitHubUser gani9797-dev `
    -InstallSshd `
    -SshPublicKey C:\Temp\wms_deploy.pub
```

`bootstrap.ps1` поставит Git/PHP, создаст `C:\wms`, клонирует репозиторий в `C:\wms\app`,
создаст задачи планировщика и поднимет портал на `http://localhost:8001`.

> Репозиторий приватный. Доступ даётся либо PAT (`-GitPat`, сохраняется в диспетчер
> учётных данных Windows), либо deploy key: положите приватный ключ в
> `C:\wms\etc\deploy_key` — скрипты подхватят его автоматически.

### 1.4. Секреты GitHub

`Settings → Secrets and variables → Actions`:

| Тип | Имя | Значение |
|---|---|---|
| Secret | `SSH_HOST` | IP или hostname сервера |
| Secret | `SSH_PORT` | `22` |
| Secret | `SSH_USER` | пользователь Windows (например `Administrator`) |
| Secret | `SSH_KEY` | содержимое `wms_deploy` целиком |
| Secret | `SSH_PASSPHRASE` | пароль ключа, если задавали |
| Variable | `WMS_ROOT` | `C:\wms` (необязательно) |
| Variable | `WMS_PORT` | `8001` (необязательно) |
| Variable | `WMS_KEEP_DATA` | `true` — не перезаливать БД из GitHub (необязательно) |

### 1.5. Проверка

`Actions → Проверка хоста → Run workflow` — убедитесь, что SSH-доступ есть, PHP и Git
на месте, репозиторий развёрнут. Затем сделайте любой `push` в ветку
`arena/448262c5-wms-2` — запустится `Deploy WMS (Windows, SSH)`.

### 1.6. Доступ из интернета

```powershell
cd C:\wms\app\deploy
powershell -ExecutionPolicy Bypass -File .\install-cloudflared.ps1 -TunnelToken <токен из панели Cloudflare>
```

В панели Cloudflare (Zero Trust → Networks → Tunnels) маршрут:
`wms.example.com → http://127.0.0.1:8001`.

---

## 2. Что происходит при каждом push

Workflow `.github/workflows/deploy.yml`:

1. **Проверка кода** (`_verify.yml`): `php -l` для всех файлов + smoke-тест — поднимает
   `php -S` с `router.php` и проверяет, что `/login` отдаёт форму, закрытые страницы
   редиректят (302), а `/tsd/api.php` без токена отвечает 401. Если что-то сломано —
   деплой **не** начинается.
2. **Деплой** (`appleboy/ssh-action`) — одна команда на сервере:
   ```
   powershell.exe -File C:\wms\app\deploy\deploy.ps1 -Sha <коммит> -Port 8001
   ```
3. На сервере `deploy.ps1`:
   1. при первом запуске — `git clone`;
   2. `git fetch` и поиск нужного коммита (по SHA, ветке или тегу);
   3. остановка PHP-сервера (иначе Windows не даст перезаписать `.sqlite`);
   4. удаление `*.sqlite-wal/-shm/-journal` — иначе SQLite накатит старые транзакции;
   5. `git checkout -f` + `git reset --hard` → код **и** данные приводятся к GitHub;
   6. `php -l` всех файлов уже на сервере;
   7. запуск PHP-сервера, ожидание ответа `/login`;
   8. при неудаче — автоматический откат на предыдущий коммит;
   9. запись `C:\wms\state\last-deploy.json`.

Ручной запуск: `Actions → Deploy WMS (Windows, SSH) → Run workflow`, там же можно
указать ветку/тег/SHA и отметить «Сохранить данные сервера».

---

## 3. Файлы

| Файл | Назначение |
|---|---|
| `.github/workflows/deploy.yml` | основной деплой по SSH |
| `.github/workflows/deploy-tunnel.yml` | то же, но SSH идёт внутри Cloudflare Tunnel |
| `.github/workflows/check-host.yml` | ручная предпроверка сервера |
| `.github/workflows/_verify.yml` | линт + smoke-тест (переиспользуемый) |
| `deploy/deploy.ps1` | сам деплой (выполняется на сервере) |
| `deploy/bootstrap.ps1` | первичная настройка сервера |
| `deploy/start-app.ps1` / `stop-app.ps1` | запуск/остановка портала |
| `deploy/watchdog.ps1` | сторож: поднимает портал, если он упал |
| `deploy/install-cloudflared.ps1` | установка и настройка туннеля |
| `deploy/common.ps1`, `deploy/config.ps1` | общие функции и настройки |
| `deploy/etc/cloudflared-config.example.yml` | пример маршрутов туннеля |

Каталоги на сервере:

```
C:\wms\app     — клон репозитория (код + данные из GitHub)
C:\wms\bin     — cloudflared.exe (вне репозитория, git его не трогает)
C:\wms\logs    — логи PHP-сервера и деплоя
C:\wms\state   — current.sha, last-deploy.json, бэкапы БД, local.ps1
C:\wms\etc     — конфиги cloudflared, deploy_key
```

---

## 4. Данные и БД

Рабочие базы — SQLite-файлы внутри репозитория:

```
02-postupleniya\wms_receipts.sqlite     — поступления
03-otgruzki\wms_demo.sqlite             — отгрузки
05-yacheyki-hraneniya\wms_storage.sqlite— ячейки хранения
07-zadaniya\wms_tasks.sqlite            — задания
08-nomenklatura\wms_nomenclature.sqlite — номенклатура
tasks\wms_tasks.sqlite                  — задания (ТСД)
receipts\wms_receipts.sqlite            — легаси-копия
```

Так как они отслеживаются git, `git reset --hard` возвращает их к состоянию GitHub.

* **По умолчанию: данные перезаливаются из GitHub.** Удобно для демо и обкатки:
  запушили свежий дамп — на сервере он появился. Но работа склада между деплоями
  при этом теряется.
* **Сохранять данные сервера:**
  * разово — галка *«Сохранить данные сервера»* при ручном запуске workflow
    (`-KeepData`); данные копируются в `C:\wms\state\db-backup` и возвращаются
    после обновления кода;
  * постоянно — заведите переменную `WMS_KEEP_DATA=true`.
* Для настоящего прода рабочие данные лучше держать в MySQL (в модулях уже есть
  `*index.php` с подключением к MySQL) или вынести `.sqlite` из репозитория.

---

## 5. Ручные операции на сервере

```powershell
# Запустить / остановить
powershell -ExecutionPolicy Bypass -File C:\wms\app\deploy\start-app.ps1 -Wait
powershell -ExecutionPolicy Bypass -File C:\wms\app\deploy\stop-app.ps1

# Перезапустить туннель
Restart-Service cloudflared

# Деплой вручную (без GitHub)
powershell -ExecutionPolicy Bypass -File C:\wms\app\deploy\deploy.ps1 -Ref arena/448262c5-wms-2

# Откат на предыдущий коммит
powershell -ExecutionPolicy Bypass -File C:\wms\app\deploy\deploy.ps1 -Sha (Get-Content C:\wms\state\current.sha)

# Логи
Get-Content C:\wms\logs\deploy.log -Tail 50
Get-ChildItem C:\wms\logs\php-*.err.log | Sort-Object LastWriteTime | Select-Object -Last 1 | Get-Content -Tail 50

# Состояние
Get-Content C:\wms\state\last-deploy.json
schtasks /Query /TN WMS-PHP-8001
Get-Service cloudflared
```

---

## 6. Автозапуск и отказоустойчивость

`bootstrap.ps1` создаёт две задачи планировщика (от имени `SYSTEM`):

| Задача | Триггер | Что делает |
|---|---|---|
| `WMS-PHP-8001` | при старте системы | запускает `php -S 0.0.0.0:8001 -t C:\wms\app C:\wms\app\router.php` |
| `WMS-Watchdog` | каждые 5 минут | если `/login` не отвечает — перезапускает сервер |

Посмотреть: `taskschd.msc` → библиотека планировщика.

---

## 7. ТСД (сканеры)

* Интерфейсы: `http://<адрес>/tsd/app.html`, `/tsd/prihod.html`, `/tsd/otbor.html`.
* Токен задан константой `TSD_TOKEN` в `tsd/api.php`, `tsd/otbor.php`, `tsd/prihod.php`,
  `tsd/ship.php`, `tsd/tasks.php`, `tsd/info.php` и в `router.php` (сейчас `tsd-12345`).
  Для прода поменяйте его и вынесите в конфиг/переменную окружения.
* ТСД-страницы отдаются без логина, но `router.php` не даёт скачать `.sqlite`, `.log`,
  `.bat` и `router.php` даже авторизованным пользователям.
* Если сканеры ходят напрямую на сервер (по локальной сети, минуя туннель), откройте
  порт в брандмауэре:

  ```powershell
  New-NetFirewallRule -DisplayName 'WMS 8001' -Direction Inbound -Protocol TCP `
      -LocalPort 8001 -Action Allow -Profile Private
  ```

---

## 8. Troubleshooting

| Симптом | Причина и лечение |
|---|---|
| `Permission denied (publickey)` в логе Actions | публичный ключ не там: для администраторов Windows он должен лежать в `C:\ProgramData\ssh\administrators_authorized_keys` (не в `%USERPROFILE%\.ssh`), с правами только SYSTEM и Администраторы |
| `Не удалось клонировать ...` | приватный репозиторий: нет PAT в диспетчере учётных данных или файла `C:\wms\etc\deploy_key` |
| `Порт 8001 всё ещё занят` | процесс висит старый: `netstat -ano \| findstr :8001`, затем `taskkill /PID <pid> /F` |
| Деплой прошёл, но портал не открывается | `Get-Content C:\wms\logs\php-*.err.log -Tail 50`; обычно не хватает `pdo_sqlite` в php.ini |
| Отваливается cloudflared | `Get-Service cloudflared`, логи в событийном журнале (Приложения → cloudflared) |
| Данные не обновились | проверьте, что не стоит `WMS_KEEP_DATA=true`; удалите `*.sqlite-wal` вручную |
| PHP-сервер тормозит при нагрузке | встроенный сервер PHP однопроцессный; для серьёзной нагрузки поставьте nginx для Windows как reverse-proxy на 2–3 инстанса `php -S` (8001–8003) |

---

## 9. Безопасность

* SSH — только по ключу (`PasswordAuthentication no` в
  `C:\ProgramData\ssh\sshd_config`), порт 22 желательно закрыть фаерволом для всех,
  кроме вашего IP, либо вообще убрать в туннель (`deploy-tunnel.yml`).
* Пароль портала — bcrypt-хэш в `router.php`; смените его через
  `php -r "echo password_hash('новый', PASSWORD_DEFAULT), PHP_EOL;"`.
* Токен ТСД — смените дефолтный `tsd-12345`.
* Секреты не попадают в код: все чувствительные значения — в Secrets GitHub,
  приватный ключ репозитория — в `C:\wms\etc\deploy_key`.
