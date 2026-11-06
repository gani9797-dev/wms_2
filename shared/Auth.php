<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Roles.php';

/**
 * Авторизация WMS: учётные записи сотрудников (login/password_hash в employees)
 * и динамические права роли (role_access / role_permissions).
 */

const WMS_AUTH_SESSION_EMPLOYEE = 'wms_employee_id';
const WMS_AUTH_SESSION_LOGIN = 'wms_login';
const WMS_AUTH_SESSION_MARK = 'wms_auth_user';

/** Ключи модулей WMS (совпадают с EmployeeAccess::WMS_MODULES). */
function wms_auth_module_keys(): array
{
    return [
        'dashboard', 'reestr', 'post', 'otg', 'orders', 'pack', 'check', 'int',
        'yacheyki', 'karta', 'inv', 'zad', 'nomen', 'stock', 'cp', 'billing',
        'emp', 'kpi', 'reports', 'notif', 'settings', 'journal', 'labels',
    ];
}

/** URI => ключ модуля. null — служебный/общедоступный путь. */
function wms_auth_module_for_uri(string $uri): ?string
{
    $map = [
        '/00-dashboard/' => 'dashboard',
        '/01-reestr-pribytiya-ts/' => 'reestr',
        '/01.1-spravochnik-ts/' => 'reestr',
        '/01.2-karta-vorot/' => 'reestr',
        '/02-postupleniya/' => 'post',
        '/02.1-priem-postupleniy/' => 'post',
        '/03-otgruzki/' => 'otg',
        '/03.1-zakazy/' => 'orders',
        '/03.2-upakovka-zakazov/' => 'pack',
        '/03.3-proverka-zakazov/' => 'check',
        '/04-vnutriskladskie-operatsii/' => 'int',
        '/05-yacheyki-hraneniya/' => 'yacheyki',
        '/05.1-karta-sklada-3d/' => 'karta',
        '/06-inventarizatsiya/' => 'inv',
        '/07-zadaniya/' => 'zad',
        '/08-nomenklatura/' => 'nomen',
        '/08.1-tovarny-zapas/' => 'stock',
        '/09-kontragenty-i-gruzopoluchateli/' => 'cp',
        '/09.1-billing/' => 'billing',
        '/10-sotrudniki/' => 'emp',
        '/10.1-nachisleniya-kpi/' => 'kpi',
        '/10.2-prosmotr-sdelki/' => 'kpi',
        '/16-billing/' => 'billing',
        '/11-nablyudeniya/' => 'reports',
        '/11-otchety/' => 'reports',
        '/12-uvedomleniya/' => 'notif',
        '/13-nastroyki/' => 'settings',
        '/13.1-avtoformirovanie-nakladnyh/' => 'settings',
        '/13.2-avtoformirovanie-schetov/' => 'settings',
        '/13.3-pechat-lineyki/' => 'settings',
        '/13.4-nastroyka-kpi/' => 'kpi',
        '/14-zhurnal/' => 'journal',
        '/15-etiketki/' => 'labels',
    ];
    foreach ($map as $prefix => $module) {
        if (str_starts_with($uri, $prefix)) {
            return $module;
        }
    }
    return null;
}

/** Куда отправить сотрудника после входа (первый доступный модуль). */
function wms_auth_landing_url(): string
{
    $homes = [
        'dashboard' => '/00-dashboard/app.html',
        'post' => '/02-postupleniya/app.html',
        'orders' => '/03.1-zakazy/app.html',
        'otg' => '/03-otgruzki/app.html',
        'reestr' => '/01-reestr-pribytiya-ts/app.html',
        'zad' => '/07-zadaniya/app.html',
        'stock' => '/08.1-tovarny-zapas/app.html',
        'nomen' => '/08-nomenklatura/app.html',
        'emp' => '/10-sotrudniki/app.html',
        'settings' => '/13-nastroyki/app.html',
        'journal' => '/14-zhurnal/app.html',
        'labels' => '/15-etiketki/app.html',
    ];
    $levels = wms_auth_module_levels();
    foreach ($homes as $module => $url) {
        if (($levels[$module] ?? 'none') !== 'none') {
            return $url;
        }
    }
    return '/00-dashboard/app.html';
}

function wms_auth_is_api_uri(string $uri): bool
{
    return str_ends_with(strtolower($uri), '.php');
}

function wms_auth_virtual_admin(): array
{
    return [
        'id' => 0,
        'login' => 'admin',
        'fio' => 'Администратор',
        'role' => 'Администратор',
        'position' => '',
        'department' => '',
        'status' => 'active',
        'is_blocked' => 0,
        'warehouse_id' => null,
        'tsd_access' => 1,
        'virtual' => true,
    ];
}

function wms_auth_normalize_employee(array $row): array
{
    return [
        'id' => (int)($row['id'] ?? 0),
        'login' => (string)($row['login'] ?? ''),
        'fio' => (string)($row['fio'] ?? ''),
        'role' => (string)($row['role'] ?? ''),
        'position' => (string)($row['position'] ?? ''),
        'department' => (string)($row['department'] ?? ''),
        'status' => (string)($row['status'] ?? 'active'),
        'is_blocked' => (int)($row['is_blocked'] ?? 0),
        'warehouse_id' => isset($row['warehouse_id']) ? (int)$row['warehouse_id'] : null,
        'tsd_access' => (int)($row['tsd_access'] ?? 0),
        // Учётная запись клиента (модуль 9): контрагент, владелец товара, отчёты.
        'counterparty_id' => isset($row['counterparty_id']) ? (int)$row['counterparty_id'] : null,
        'data_owner' => trim((string)($row['data_owner'] ?? '')),
        'dashboard_reports' => trim((string)($row['dashboard_reports'] ?? '')),
        'virtual' => false,
    ];
}

/** Найти сотрудника по логину (без проверки пароля). */
function wms_auth_employee_by_login(string $login): ?array
{
    $login = trim($login);
    if ($login === '') {
        return null;
    }
    try {
        $pdo = wms_employees_pdo();
        $st = $pdo->prepare('SELECT * FROM employees WHERE LOWER(login) = LOWER(:l) LIMIT 1');
        $st->execute([':l' => $login]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable) {
        return null;
    }
}

function wms_auth_employee_by_id(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    try {
        $pdo = wms_employees_pdo();
        $st = $pdo->prepare('SELECT * FROM employees WHERE id = :id LIMIT 1');
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable) {
        return null;
    }
}

/** Проверить логин/пароль. Возвращает сотрудника или null. */
function wms_auth_attempt(string $login, string $password): ?array
{
    $row = wms_auth_employee_by_login($login);
    if (!$row) {
        return null;
    }
    $hash = (string)($row['password_hash'] ?? '');
    if ($hash === '' || !password_verify($password, $hash)) {
        return null;
    }
    if ((int)($row['is_blocked'] ?? 0) === 1 || in_array((string)($row['status'] ?? ''), ['fired', 'blocked'], true)) {
        return null;
    }
    return wms_auth_normalize_employee($row);
}

/** Первый сотрудник-администратор — для совместимости со старым входом admin. */
/**
 * Служебный вход admin → реальная карточка сотрудника с логином admin.
 *
 * Раньше вход под admin «приклеивался» к первому сотруднику с ролью
 * «Администратор» (в демо-данных это Иванов А.В.), из-за чего в шапке
 * показывался человек, которого оператор не выбирал. Теперь учётная запись
 * admin единая: при первом входе создаётся карточка «Администратор» с логином
 * admin (пароль — тот же служебный), она видна в модуле «Сотрудники».
 */
function wms_auth_admin_employee(): array
{
    try {
        $pdo = wms_employees_pdo();
        $st = $pdo->prepare("SELECT * FROM employees WHERE LOWER(login) = 'admin' LIMIT 1");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$row) {
            // Первый вход под admin: создаём единую учётную запись администратора.
            $now = function_exists('wms_now_sql') ? wms_now_sql($pdo) : "datetime('now')";
            $hash = defined('AUTH_HASH') ? (string)AUTH_HASH : '';
            $ins = $pdo->prepare(
                "INSERT INTO employees (fio, last_name, first_name, middle_name, position, code, department,
                    personnel_no, login, password_hash, status, role, zone, is_active, is_blocked,
                    allow_unplanned_exit, created_at, updated_at)
                 VALUES (:fio, 'Администратор', '', '', 'Администратор', 'А', 'Администрирование',
                    'ADMIN', 'admin', :ph, 'active', 'Администратор', 'Все зоны', 1, 0, 0, $now, $now)"
            );
            $ins->execute([':fio' => 'Администратор', ':ph' => ($hash !== '' ? $hash : null)]);
            $st->execute();
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($row) {
            // Досоздаём служебный пароль, если карточку создали без него (первый вход).
            if (trim((string)($row['password_hash'] ?? '')) === '' && defined('AUTH_HASH') && (string)AUTH_HASH !== '') {
                try {
                    $pdo->prepare('UPDATE employees SET password_hash = :ph WHERE id = :id')
                        ->execute([':ph' => (string)AUTH_HASH, ':id' => (int)$row['id']]);
                    $row['password_hash'] = (string)AUTH_HASH;
                } catch (Throwable) {
                }
            }
            $u = wms_auth_normalize_employee($row);
            if ((int)$u['is_blocked'] === 0 && !in_array($u['status'], ['fired', 'blocked'], true)) {
                return $u;
            }
        }
    } catch (Throwable) {
    }
    return wms_auth_virtual_admin();
}

function wms_auth_login(array $user): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        try {
            session_regenerate_id(true);
        } catch (Throwable) {
        }
    }
    $id = (int)($user['id'] ?? 0);
    if ($id > 0) {
        $_SESSION[WMS_AUTH_SESSION_EMPLOYEE] = $id;
    }
    $login = (string)($user['login'] ?? '');
    $_SESSION[WMS_AUTH_SESSION_LOGIN] = $login !== '' ? $login : (string)$id;
    $_SESSION[WMS_AUTH_SESSION_MARK] = $login !== '' ? $login : (string)$id;
}

function wms_auth_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}


/**
 * Владелец товара, доступный текущей учётной записи ('' = весь товар).
 * Используется во всех модулях для сквозного ограничения данных.
 */
function wms_data_owner_scope(): string
{
    static $loaded = false;
    static $scope = '';
    if ($loaded) {
        return $scope;
    }
    $loaded = true;
    try {
        $u = wms_auth_user();
        if (is_array($u)) {
            $scope = trim((string)($u['data_owner'] ?? ''));
        }
    } catch (Throwable) {
    }
    return $scope;
}

/** Отчёты дашборда, доступные учётной записи (пусто = все). */
function wms_auth_dashboard_reports(): array
{
    try {
        $u = wms_auth_user();
        $raw = is_array($u) ? trim((string)($u['dashboard_reports'] ?? '')) : '';
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== ''));
    } catch (Throwable) {
        return [];
    }
}

/** SQL-условие по владельцу товара для алиаса products: '' = без ограничения. */
function wms_owner_sql(string $alias = 'p', string $param = ':owner_scope'): string
{
    $scope = wms_data_owner_scope();
    return $scope === '' ? '' : ' AND COALESCE(' . $alias . ".owner,'') = $param";
}

/** Значение параметра владельца для привязки (или null, если ограничения нет). */
function wms_owner_param(): ?string
{
    $scope = wms_data_owner_scope();
    return $scope === '' ? null : $scope;
}

/** Администратор или разработчик (служебные действия: пароли, коды этикеток). */
function wms_auth_is_admin_or_dev(): bool
{
    try {
        $u = wms_auth_user();
        $role = is_array($u) ? trim((string)($u['role'] ?? '')) : '';
        return $role === 'Администратор' || $role === 'Разработчик';
    } catch (Throwable) {
        return false;
    }
}
/** Текущий сотрудник (перечитывается из БД на каждом запросе). */
function wms_auth_user(): ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    $employeeId = (int)($_SESSION[WMS_AUTH_SESSION_EMPLOYEE] ?? 0);
    if ($employeeId > 0) {
        $row = wms_auth_employee_by_id($employeeId);
        if (!$row) {
            return $user = null;
        }
        $u = wms_auth_normalize_employee($row);
        if ((int)$u['is_blocked'] === 1 || in_array($u['status'], ['fired', 'blocked'], true)) {
            return $user = null;
        }
        if ($u['login'] === '') {
            $u['login'] = (string)($_SESSION[WMS_AUTH_SESSION_LOGIN] ?? '');
        }
        return $user = $u;
    }
    // Совместимость со старой сессией (admin без employee_id).
    if (!empty($_SESSION[WMS_AUTH_SESSION_MARK])) {
        return $user = wms_auth_admin_employee();
    }
    return $user = null;
}

function wms_auth_actor_id(): int
{
    $u = wms_auth_user();
    $id = (int)($u['id'] ?? 0);
    return $id > 0 ? $id : 1;
}

/**
 * ФИО того, кто залогинился на портал. Единая точка для всех модулей:
 * заголовки, журналы, истории, поля «Приемщик/Кладовщик/Изменил».
 * Если сессии нет (CLI, робот) — вернёт $fallback.
 */
function wms_auth_fio(string $fallback = 'Система'): string
{
    $u = wms_auth_user();
    if (!$u) {
        return $fallback;
    }
    $fio = trim((string)($u['fio'] ?? ''));
    if ($fio !== '') {
        return $fio;
    }
    $login = trim((string)($u['login'] ?? ''));
    return $login !== '' ? $login : $fallback;
}

/** Должность/роль для подписи под ФИО. */
function wms_auth_position(): string
{
    $u = wms_auth_user();
    if (!$u) {
        return '';
    }
    $pos = trim((string)($u['position'] ?? ''));
    if ($pos !== '') {
        return $pos;
    }
    return trim((string)($u['role'] ?? ''));
}

/**
 * ФИО для записи в журнал/историю. Если передано пустое значение или старое
 * демо-значение «Иванов И.И.» — подставляем ФИО залогиненного на портал сотрудника.
 */
function wms_resolve_fio(?string $fio, string $fallback = 'Система'): string
{
    $fio = trim((string)$fio);
    if ($fio !== '' && $fio !== 'Иванов И.И.') {
        return $fio;
    }
    $real = wms_auth_fio('');
    if ($real !== '') {
        return $real;
    }
    return $fio !== '' ? $fio : $fallback;
}

/** Уровни доступа по модулям для роли текущего сотрудника. */
function wms_auth_module_levels(): array
{
    static $loaded = false;
    static $levels = [];
    if ($loaded) {
        return $levels;
    }
    $loaded = true;
    $u = wms_auth_user();
    if (!$u) {
        return $levels = [];
    }
    $keys = wms_auth_module_keys();
    if (!empty($u['virtual'])) {
        return $levels = array_fill_keys($keys, 'full');
    }
    $role = trim((string)($u['role'] ?? ''));
    // Персональные права применяем только для учётной записи клиента (модуль 9):
    // у обычных сотрудников доступ по-прежнему задаётся ролью.
    $isClientAccount = !empty($u['counterparty_id']);
    try {
        if (!$isClientAccount) {
            throw new RuntimeException('role-based');
        }
        $epdo = wms_employees_pdo();
        $est = $epdo->prepare("SELECT module_key, level FROM employee_access WHERE employee_id=:id AND scope='wms'");
        $est->execute([':id' => (int)($u['id'] ?? 0)]);
        $empRows = $est->fetchAll(PDO::FETCH_ASSOC);
        if ($empRows) {
            $levels = array_fill_keys($keys, 'none');
            foreach ($empRows as $row) {
                $key = (string)($row['module_key'] ?? '');
                if (in_array($key, $keys, true)) {
                    $lv = (string)($row['level'] ?? 'none');
                    $levels[$key] = in_array($lv, ['full', 'view', 'none'], true) ? $lv : 'none';
                }
            }
            return $levels;
        }
    } catch (Throwable) {
    }
    $rows = [];
    try {
        $pdo = wms_employees_pdo();
        $st = $pdo->prepare("SELECT module_key, level FROM role_access WHERE role=:r AND scope='wms'");
        $st->execute([':r' => $role]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
    }
    if ($rows) {
        $levels = array_fill_keys($keys, 'none');
        foreach ($rows as $row) {
            $key = (string)($row['module_key'] ?? '');
            if (in_array($key, $keys, true)) {
                $levels[$key] = in_array((string)($row['level'] ?? ''), ['full', 'view', 'none'], true)
                    ? (string)$row['level'] : 'none';
            }
        }
        return $levels;
    }
    // Для роли без явных строк — прежние значения по умолчанию.
    $levels = array_fill_keys($keys, 'full');
    if ($role === 'Водитель') {
        $levels = array_fill_keys($keys, 'none');
        $levels['reestr'] = 'full';
        $levels['int'] = 'full';
    }
    return $levels;
}

function wms_auth_can_module(string $module, string $need = 'view'): bool
{
    $levels = wms_auth_module_levels();
    $level = $levels[$module] ?? 'none';
    if ($need === 'view') {
        return $level === 'full' || $level === 'view';
    }
    return $level === 'full';
}

/** Отдельные права действий (редактирование документов и т.п.). */
function wms_auth_permissions(): array
{
    static $loaded = false;
    static $perms = [];
    if ($loaded) {
        return $perms;
    }
    $loaded = true;
    $u = wms_auth_user();
    if (!$u) {
        return $perms = [];
    }
    if (!empty($u['virtual'])) {
        return $perms = array_fill_keys(array_keys(wms_permissions_catalog()), true);
    }
    try {
        $perms = wms_role_permissions_get(wms_employees_pdo(), (string)($u['role'] ?? ''));
    } catch (Throwable) {
        $perms = array_fill_keys(array_keys(wms_permissions_catalog()), false);
    }
    return $perms;
}

function wms_auth_can_permission(string $key): bool
{
    $perms = wms_auth_permissions();
    return !empty($perms[$key]);
}

function wms_auth_require_permission(string $key): void
{
    if (wms_auth_can_permission($key)) {
        return;
    }
    http_response_code(403);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => 'Недостаточно прав для редактирования документа'], JSON_UNESCAPED_UNICODE);
    exit;
}

function wms_auth_require_module(string $module, string $need = 'view'): void
{
    if (wms_auth_can_module($module, $need)) {
        return;
    }
    http_response_code(403);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => 'Нет доступа к модулю'], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Публичное представление пользователя для клиента. */
function wms_auth_public_user(?array $u = null): ?array
{
    $u = $u ?? wms_auth_user();
    if (!$u) {
        return null;
    }
    return [
        'id' => (int)($u['id'] ?? 0),
        'login' => (string)($u['login'] ?? ''),
        'fio' => (string)($u['fio'] ?? ''),
        'role' => (string)($u['role'] ?? ''),
        'position' => (string)($u['position'] ?? ''),
        'department' => (string)($u['department'] ?? ''),
        'counterparty_id' => isset($u['counterparty_id']) ? (int)$u['counterparty_id'] : null,
        'data_owner' => (string)($u['data_owner'] ?? ''),
        'dashboard_reports' => (string)($u['dashboard_reports'] ?? ''),
        'virtual' => !empty($u['virtual']),
    ];
}
