<?php
declare(strict_types=1);
// Front-controller для встроенного сервера PHP: единая точка входа + защита логином/паролем.
// Запуск: php -S 0.0.0.0:8001 -t <папка wms_2> <папка wms_2>\router.php
// Все запросы сначала проходят через этот файл. Без входа: API отдают 401, страницы ведут на /login.

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/shared/Auth.php';

const AUTH_USER = 'admin';
// bcrypt-хэш пароля (смена пароля = заменить хэш, получить: php -r "echo password_hash('новый', PASSWORD_DEFAULT), PHP_EOL;")
const AUTH_HASH = '$2y$10$auTziIUDGZCgKmWnubwhMeM7m6Xkc8JH0SaoOqcBfA9gLz9v5wp9m';
const SESSION_KEY = 'wms_auth_user';

function isAuthed(): bool
{
    return wms_auth_user() !== null;
}

function loginPage(string $error = ''): string
{
    $err = $error !== '' ? '<div class="err">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>' : '';
    return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Вход — TiMLog</title>
<style>
body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#F4F5F7;display:flex;align-items:center;justify-content:center;height:100vh}
.box{background:#fff;border:1px solid #EAEAEA;border-radius:12px;padding:28px;width:340px;box-shadow:0 8px 24px rgba(0,0,0,.08)}
h1{margin:0 0 4px;font-size:22px}.sub{color:#8A92A6;font-size:13px;margin-bottom:16px}
label{font-size:12px;color:#4A4A4A;font-weight:700;display:block;margin:12px 0 4px}
input{width:100%;padding:10px;border:1px solid #EAEAEA;border-radius:8px;box-sizing:border-box}
button{width:100%;margin-top:16px;background:#6432FF;color:#fff;border:0;border-radius:10px;padding:12px;font-size:15px;font-weight:700;cursor:pointer}
.err{background:#FFEBEE;color:#C62828;border:1px solid #EF9A9A;border-radius:8px;padding:8px 10px;font-size:13px;margin-bottom:8px}
</style>
</head>
<body>
<div class="box">
<h1>TiMLog</h1>
<div class="sub">Вход на портал. Логин и пароль задаются в карточке сотрудника (модуль 10).</div>
{$err}
<form method="POST" action="/login">
<label>Логин</label><input name="login" type="text" autocomplete="username" required>
<label>Пароль</label><input name="password" type="password" autocomplete="current-password" required>
<button type="submit">Войти</button>
</form>
</div>
</body>
</html>
HTML;
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// --- Вход ---
if ($uri === '/login') {
    if ($method === 'POST') {
        $login = trim((string)($_POST['login'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $employee = wms_auth_attempt($login, $pass);
        if ($employee) {
            wms_auth_login($employee);
            header('Location: ' . wms_auth_landing_url());
            http_response_code(302);
            exit;
        }
        // Совместимость со старым входом admin: работает, пока у учётной
        // записи admin в карточке сотрудника не задан собственный пароль.
        $adminRow = wms_auth_employee_by_login(AUTH_USER);
        $adminHasPassword = $adminRow && trim((string)($adminRow['password_hash'] ?? '')) !== '';
        if (!$adminHasPassword && $login === AUTH_USER && password_verify($pass, AUTH_HASH)) {
            wms_auth_login(wms_auth_admin_employee());
            header('Location: ' . wms_auth_landing_url());
            http_response_code(302);
            exit;
        }
        http_response_code(401);
        echo loginPage('Неверный логин или пароль');
        exit;
    }
    if (isAuthed()) {
        header('Location: ' . wms_auth_landing_url());
        http_response_code(302);
        exit;
    }
    echo loginPage();
    exit;
}

// --- Выход ---
if ($uri === '/logout') {
    wms_auth_logout();
    header('Location: /login');
    http_response_code(302);
    exit;
}

// --- Корень ---
if ($uri === '/') {
    header('Location: ' . (isAuthed() ? wms_auth_landing_url() : '/login'));
    http_response_code(302);
    exit;
}

// --- ТСД: отдельная точка. UI доступен без сессии (данные защищает токен в api.php) ---
if (str_starts_with($uri, '/tsd/')) {
    require_once __DIR__ . '/shared/tsd_auth.php';
    if ($uri === '/tsd/api.php') {
        // Токен берётся из окружения (TSD_TOKEN) — см. shared/tsd_auth.php.
        if (wms_tsd_token_ok() || isAuthed()) {
            return false;
        }
        // Не пускаем дальше к общей проверке — отдадим 401 JSON прямо здесь.
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'unauthorized: нужен ?token= или вход через /login'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // app.html и статика ТСД — без логина (удобно для сканеров в цеху).
    // HTML ТСД отдаем без кэша, иначе сканеры работают на старой версии интерфейса.
    if (str_ends_with($uri, '.html')) {
        $f = __DIR__ . $uri;
        if (is_file($f)) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            // Токен отдаём странице из конфигурации, а не из исходника HTML:
            // смена TSD_TOKEN в .env меняет его во всех ТСД-страницах сразу.
            $html = (string)file_get_contents($f);
            $inj = '<script>window.WMS_TSD_TOKEN=' . json_encode(wms_tsd_token(), JSON_UNESCAPED_UNICODE) . ';</script>';
            if (preg_match('/<script\b/i', $html)) {
                $html = preg_replace('/<script\b/i', $inj . '<script', $html, 1);
            } else {
                $html = str_ireplace('</body>', $inj . '</body>', $html);
            }
            echo $html;
            exit;
        }
    }
    return false;
}

// --- Служебные файлы не отдаём (ни вошедшим, ни невошедшим) ---
// Базы, схемы, конфигурация, служебные каталоги и макеты — внутри веб-корня,
// но наружу их отдавать нельзя.
$low = strtolower($uri);
$blockedExt = [
    '.sqlite', '.sqlite-journal', '.sqlite-wal', '.sqlite-shm', '.db',
    '.sql', '.log', '.exe', '.bat', '.cmd', '.lnk', '.ps1', '.py',
    '.env', '.yml', '.yaml', '.ini', '.conf', '.drawio', '.xlsx', '.xls',
    '.docx', '.doc', '.zip', '.bak', '.tar', '.gz',
];
$isService = $uri === '/router.php'
    || str_contains($low, '/.git/') || str_contains($low, '/.vs/')
    || str_starts_with($low, '/.git') || str_starts_with($low, '/.vs');
if (!$isService) {
    foreach ($blockedExt as $ext) {
        if (str_ends_with($low, $ext)) {
            $isService = true;
            break;
        }
    }
}
if ($isService) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

// --- Не вошёл: API -> 401 JSON, остальное -> на форму входа ---
if (!isAuthed()) {
    $isApi = str_ends_with($uri, '.php') || isset($_GET['action']);
    if ($isApi) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: /login');
    http_response_code(302);
    exit;
}

// --- Права роли на модуль (перечитываются из БД на каждом запросе) ---
$authUser = wms_auth_user();
$moduleKey = wms_auth_module_for_uri($uri);
$moduleLevel = $moduleKey !== null ? (wms_auth_module_levels()[$moduleKey] ?? 'none') : null;
$isApiRequest = wms_auth_is_api_uri($uri) || isset($_GET['action']);
if ($moduleKey !== null) {
    $denied = $moduleLevel === 'none' || ($method !== 'GET' && $moduleLevel !== 'full');
    if ($denied) {
        http_response_code(403);
        if ($isApiRequest) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Нет доступа к модулю или недостаточно прав'], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Нет доступа — TiMLog</title>'
                . '<style>body{font-family:Arial,Helvetica,sans-serif;background:#F4F5F7;margin:0;display:flex;align-items:center;justify-content:center;height:100vh;color:#1C2331}'
                . '.b{background:#fff;border:1px solid #EAEAEA;border-radius:12px;padding:26px 30px;max-width:520px;box-shadow:0 8px 24px rgba(0,0,0,.08)}'
                . 'a{color:#6432FF}</style></head><body><div class="b"><h2 style="margin-top:0">Нет доступа</h2>'
                . '<p>У вашей роли нет прав на этот модуль. Обратитесь к администратору.</p>'
                . '<p><a href="/00-dashboard/app.html">Перейти на дашборд</a></p></div></body></html>';
        }
        exit;
    }
}

// --- Общие инструменты таблиц + блок пользователя (все модули, кроме ТСД) ---
// Внедряем один общий скрипт: плавающий горизонтальный бегунок, настройка
// столбцов/порядка и количества строк через шестерёнку. Плюс общий виджет
// пользователя (ФИО, роль, логин, выход) на каждой вкладке.
if (preg_match('/\.html$/i', $uri)) {
    $file = __DIR__ . $uri;
    $real = realpath($file);
    $root = realpath(__DIR__);
    if ($real !== false && $root !== false
        && str_starts_with($real, $root . DIRECTORY_SEPARATOR)
        && is_file($real)) {
        $html = (string)file_get_contents($real);
        $authJsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $authInj = '<script>window.WMS_USER=' . json_encode(wms_auth_public_user($authUser), $authJsonFlags)
            . ';window.WMS_MODULE=' . json_encode($moduleKey)
            . ';window.WMS_MODULE_LEVEL=' . json_encode($moduleLevel)
            . ';window.WMS_MODULE_LEVELS=' . json_encode(wms_auth_module_levels())
            . ';window.WMS_PERMS=' . json_encode(wms_auth_permissions())
            . ';</script><script src="/shared/auth-client.js" defer></script>';
        if (stripos($html, '</head>') !== false) {
            $html = preg_replace('/<\/head>/i', $authInj . '</head>', $html, 1);
        } else {
            $html = $authInj . $html;
        }
        $inj = '<script src="/shared/table-tools.js"></script>';
        if (stripos($html, '</body>') !== false) {
            $html = preg_replace('/<\/body>/i', $inj . '</body>', $html, 1);
        } else {
            $html .= $inj;
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo $html;
        exit;
    }
}

// Отдать файл как обычно / выполнить PHP-скрипт
return false;
