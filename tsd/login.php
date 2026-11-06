<?php
declare(strict_types=1);
/**
 * Вход на ТСД по логину и паролю сотрудника (учётные записи модуля «Сотрудники»).
 *   POST {login, password}        → {ok, id, fio, role} + сессия портала
 *   GET  ?action=me               → {user: {...}} (текущая сессия)
 *   POST ?action=logout           → выход
 * Файл доступен без токена ТСД — он и нужен для авторизации.
 */

require_once __DIR__ . '/../shared/Auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function tsd_login_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_GET['action'] ?? '');

if ($action === 'logout') {
    wms_auth_logout();
    tsd_login_json(['ok' => true]);
}

if ($action === 'me') {
    $u = wms_auth_user();
    if (!$u) {
        tsd_login_json(['error' => 'unauthorized'], 401);
    }
    tsd_login_json(['user' => wms_auth_public_user($u)]);
}

if ($method !== 'POST') {
    tsd_login_json(['error' => 'нужен POST: login, password'], 405);
}

$b = json_decode(file_get_contents('php://input'), true);
if (!is_array($b)) {
    $b = $_POST;
}
$login = trim((string)($b['login'] ?? ''));
$password = (string)($b['password'] ?? '');
if ($login === '' || $password === '') {
    tsd_login_json(['error' => 'Укажите логин и пароль'], 422);
}

$u = wms_auth_attempt($login, $password);
if (!$u) {
    tsd_login_json(['error' => 'Неверный логин или пароль'], 401);
}
if ((int)($u['is_blocked'] ?? 0) === 1 || in_array((string)($u['status'] ?? ''), ['fired', 'blocked'], true)) {
    tsd_login_json(['error' => 'Учётная запись заблокирована'], 403);
}

wms_auth_login($u);
$pub = wms_auth_public_user($u) ?: [];
tsd_login_json([
    'ok' => true,
    'id' => (int)($pub['id'] ?? 0),
    'fio' => (string)($pub['fio'] ?? ''),
    'role' => (string)($pub['role'] ?? ''),
    'login' => (string)($pub['login'] ?? ''),
]);
