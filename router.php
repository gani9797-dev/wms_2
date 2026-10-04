<?php
declare(strict_types=1);
// Front-controller для встроенного сервера PHP: единая точка входа + защита логином/паролем.
// Запуск: php -S 0.0.0.0:8001 -t <папка wms_2> <папка wms_2>\router.php
// Все запросы сначала проходят через этот файл. Без входа: API отдают 401, страницы ведут на /login.

session_start();

const AUTH_USER = 'admin';
// bcrypt-хэш пароля (смена пароля = заменить хэш, получить: php -r "echo password_hash('новый', PASSWORD_DEFAULT), PHP_EOL;")
const AUTH_HASH = '$2y$10$auTziIUDGZCgKmWnubwhMeM7m6Xkc8JH0SaoOqcBfA9gLz9v5wp9m';
const SESSION_KEY = 'wms_auth_user';

function isAuthed(): bool
{
    return isset($_SESSION[SESSION_KEY]) && $_SESSION[SESSION_KEY] === AUTH_USER;
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
<div class="sub">Вход на портал</div>
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
        $login = (string)($_POST['login'] ?? '');
        $pass = (string)($_POST['password'] ?? '');
        if ($login === AUTH_USER && password_verify($pass, AUTH_HASH)) {
            session_regenerate_id(true);
            $_SESSION[SESSION_KEY] = AUTH_USER;
            header('Location: /02-postupleniya/app.html');
            http_response_code(302);
            exit;
        }
        http_response_code(401);
        echo loginPage('Неверный логин или пароль');
        exit;
    }
    if (isAuthed()) {
        header('Location: /02-postupleniya/app.html');
        http_response_code(302);
        exit;
    }
    echo loginPage();
    exit;
}

// --- Выход ---
if ($uri === '/logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    session_destroy();
    header('Location: /login');
    http_response_code(302);
    exit;
}

// --- Корень ---
if ($uri === '/') {
    header('Location: ' . (isAuthed() ? '/02-postupleniya/app.html' : '/login'));
    http_response_code(302);
    exit;
}

// --- ТСД: отдельная точка. UI доступен без сессии (данные защищает токен в api.php) ---
if (str_starts_with($uri, '/tsd/')) {
    if ($uri === '/tsd/api.php') {
        $tok = $_GET['token'] ?? $_POST['token'] ?? ($_SERVER['HTTP_X_TSD_TOKEN'] ?? '');
        if ($tok === '') {
            $authH = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (str_starts_with($authH, 'Bearer ')) {
                $tok = substr($authH, 7);
            }
        }
        // Токен должен совпадать с TSD_TOKEN в tsd/api.php; иначе — обычная сессия.
        if ($tok === 'tsd-12345' || isAuthed()) {
            return false;
        }
        // Не пускаем дальше к общей проверке — отдадим 401 JSON прямо здесь.
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'unauthorized: нужен ?token= или вход через /login'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // app.html и статика ТСД — без логина (удобно для сканеров в цеху).
    return false;
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

// --- Вошёл, но служебные файлы не отдаём ---
$low = strtolower($uri);
if ($uri === '/router.php'
    || str_ends_with($low, '.sqlite') || str_ends_with($low, '.sqlite-journal')
    || str_ends_with($low, '.db') || str_ends_with($low, '.log')
    || str_ends_with($low, '.exe') || str_ends_with($low, '.bat')
    || str_ends_with($low, '.cmd') || str_ends_with($low, '.lnk')) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

// Отдать файл как обычно / выполнить PHP-скрипт
return false;
