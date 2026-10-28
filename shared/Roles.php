<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Справочник ролей сотрудников (единый для модулей 10 и 13). */
function wms_roles_ensure(PDO $pdo): void
{
    try {
        if (!wms_has_column($pdo, 'wms_roles', 'id')) {
            $drv = wms_driver($pdo);
            if ($drv === 'pgsql') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS wms_roles (
                    id BIGSERIAL PRIMARY KEY,
                    name VARCHAR(50) NOT NULL UNIQUE,
                    is_active SMALLINT NOT NULL DEFAULT 1,
                    created_at TIMESTAMP NOT NULL DEFAULT NOW())");
            } elseif ($drv === 'mysql') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS wms_roles (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(50) NOT NULL UNIQUE,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
            } else {
                $pdo->exec("CREATE TABLE IF NOT EXISTS wms_roles (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL UNIQUE,
                    is_active INTEGER NOT NULL DEFAULT 1,
                    created_at TEXT DEFAULT (datetime('now')))");
            }
        }
        try {
            if (function_exists('wms_is_pgsql') && wms_is_pgsql($pdo)) {
                $pdo->exec("INSERT INTO role_access (role,scope,module_key,level) SELECT name,'wms','labels','full' FROM wms_roles ON CONFLICT DO NOTHING");
            } elseif (function_exists('wms_is_mysql') && wms_is_mysql($pdo)) {
                $pdo->exec("INSERT IGNORE INTO role_access (role,scope,module_key,level) SELECT name,'wms','labels','full' FROM wms_roles");
            } else {
                $pdo->exec("INSERT OR IGNORE INTO role_access (role,scope,module_key,level) SELECT name,'wms','labels','full' FROM wms_roles");
            }
        } catch (Throwable) {
        }
        foreach (['Администратор', 'Кладовщик', 'Водитель', 'Приемщик', 'Менеджер'] as $name) {
            try {
                if (wms_is_pgsql($pdo)) {
                    $pdo->prepare("INSERT INTO wms_roles (name) VALUES (:n) ON CONFLICT (name) DO NOTHING")
                        ->execute([':n' => $name]);
                } elseif (wms_is_mysql($pdo)) {
                    $pdo->prepare("INSERT IGNORE INTO wms_roles (name) VALUES (:n)")->execute([':n' => $name]);
                } else {
                    $pdo->prepare("INSERT OR IGNORE INTO wms_roles (name) VALUES (:n)")->execute([':n' => $name]);
                }
            } catch (Throwable) {
            }
        }
    } catch (Throwable) {
    }
}

/** @return array<int,array{id:int,name:string,is_active:int,employees:int}> */
function wms_roles_list(PDO $pdo, bool $activeOnly = false): array
{
    wms_roles_ensure($pdo);
    $out = [];
    try {
        $sql = "SELECT r.*, (SELECT COUNT(*) FROM employees e WHERE e.role = r.name) AS employees
                FROM wms_roles r";
        if ($activeOnly) {
            $sql .= " WHERE COALESCE(r.is_active,1)=1";
        }
        $sql .= " ORDER BY r.name";
        foreach ($pdo->query($sql) as $r) {
            $out[] = [
                'id' => (int)($r['id'] ?? 0),
                'name' => (string)($r['name'] ?? ''),
                'is_active' => (int)($r['is_active'] ?? 1),
                'employees' => (int)($r['employees'] ?? 0),
            ];
        }
    } catch (Throwable) {
    }
    return $out;
}

function wms_role_create(PDO $pdo, string $name): int
{
    wms_roles_ensure($pdo);
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('Укажите название роли');
    }
    if (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') > 50 : strlen($name) > 50) {
        throw new RuntimeException('Название роли — до 50 символов');
    }
    $dup = $pdo->prepare("SELECT id FROM wms_roles WHERE LOWER(name)=LOWER(:n) LIMIT 1");
    $dup->execute([':n' => $name]);
    $id = (int)($dup->fetchColumn() ?: 0);
    if ($id > 0) {
        throw new RuntimeException('Роль с таким названием уже существует');
    }
    $pdo->prepare("INSERT INTO wms_roles (name) VALUES (:n)")->execute([':n' => $name]);
    return (int)$pdo->lastInsertId();
}

function wms_role_rename(PDO $pdo, int $id, string $name): void
{
    wms_roles_ensure($pdo);
    $name = trim($name);
    if ($id <= 0 || $name === '') {
        throw new RuntimeException('Укажите роль и название');
    }
    $st = $pdo->prepare("SELECT name FROM wms_roles WHERE id=:id");
    $st->execute([':id' => $id]);
    $old = (string)($st->fetchColumn() ?: '');
    if ($old === '') {
        throw new RuntimeException('Роль не найдена');
    }
    if (strcasecmp($old, $name) === 0) {
        return;
    }
    $dup = $pdo->prepare("SELECT id FROM wms_roles WHERE LOWER(name)=LOWER(:n) AND id<>:id LIMIT 1");
    $dup->execute([':n' => $name, ':id' => $id]);
    if ($dup->fetchColumn()) {
        throw new RuntimeException('Роль с таким названием уже существует');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE wms_roles SET name=:n WHERE id=:id")->execute([':n' => $name, ':id' => $id]);
        try {
            $pdo->prepare("UPDATE employees SET role=:n WHERE role=:o")->execute([':n' => $name, ':o' => $old]);
        } catch (Throwable) {
        }
        try {
            $pdo->prepare("UPDATE role_access SET role=:n WHERE role=:o")->execute([':n' => $name, ':o' => $old]);
        } catch (Throwable) {
        }
        try {
            $pdo->prepare("UPDATE role_permissions SET role=:n WHERE role=:o")->execute([':n' => $name, ':o' => $old]);
        } catch (Throwable) {
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function wms_role_delete(PDO $pdo, int $id): void
{
    wms_roles_ensure($pdo);
    $st = $pdo->prepare("SELECT name FROM wms_roles WHERE id=:id");
    $st->execute([':id' => $id]);
    $name = (string)($st->fetchColumn() ?: '');
    if ($name === '') {
        throw new RuntimeException('Роль не найдена');
    }
    $used = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE role=:n");
    $used->execute([':n' => $name]);
    if ((int)$used->fetchColumn() > 0) {
        throw new RuntimeException('Роль используется сотрудниками. Сначала переведите их на другую роль.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM wms_roles WHERE id=:id")->execute([':id' => $id]);
        try {
            $pdo->prepare("DELETE FROM role_access WHERE role=:n")->execute([':n' => $name]);
        } catch (Throwable) {
        }
        try {
            $pdo->prepare("DELETE FROM role_permissions WHERE role=:n")->execute([':n' => $name]);
        } catch (Throwable) {
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Каталог отдельных прав (действий), которые настраиваются по роли. */
function wms_permissions_catalog(): array
{
    return [
        'doc_edit_receipt' => 'Редактирование приходных документов (строки)',
        'doc_edit_order' => 'Редактирование расходных заказов (строки)',
    ];
}

/** Таблица role_permissions + права администратора по умолчанию. */
function wms_role_permissions_ensure(PDO $pdo): void
{
    try {
        $drv = function_exists('wms_driver') ? wms_driver($pdo) : $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($drv === 'pgsql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS role_permissions (
                id BIGSERIAL PRIMARY KEY,
                role VARCHAR(50) NOT NULL,
                permission_key VARCHAR(50) NOT NULL,
                allowed SMALLINT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE (role, permission_key))");
        } elseif ($drv === 'mysql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS role_permissions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                role VARCHAR(50) NOT NULL,
                permission_key VARCHAR(50) NOT NULL,
                allowed TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_role_permission (role, permission_key))");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS role_permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                role TEXT NOT NULL,
                permission_key TEXT NOT NULL,
                allowed INTEGER NOT NULL DEFAULT 0,
                created_at TEXT DEFAULT (datetime('now')),
                UNIQUE(role, permission_key))");
        }
        foreach (wms_permissions_catalog() as $key => $_label) {
            try {
                if ($drv === 'pgsql') {
                    $pdo->prepare("INSERT INTO role_permissions (role, permission_key, allowed) VALUES ('Администратор', :k, 1) ON CONFLICT (role, permission_key) DO NOTHING")
                        ->execute([':k' => $key]);
                } elseif ($drv === 'mysql') {
                    $pdo->prepare("INSERT IGNORE INTO role_permissions (role, permission_key, allowed) VALUES ('Администратор', :k, 1)")
                        ->execute([':k' => $key]);
                } else {
                    $pdo->prepare("INSERT OR IGNORE INTO role_permissions (role, permission_key, allowed) VALUES ('Администратор', :k, 1)")
                        ->execute([':k' => $key]);
                }
            } catch (Throwable) {
            }
        }
    } catch (Throwable) {
    }
}

/** @return array<string,bool> права роли по каталогу */
function wms_role_permissions_get(PDO $pdo, string $role): array
{
    if (function_exists('wms_ensure_cached')) {
        wms_ensure_cached($pdo, 'role_permissions', 'wms_role_permissions_ensure');
    } else {
        wms_role_permissions_ensure($pdo);
    }
    $out = array_fill_keys(array_keys(wms_permissions_catalog()), false);
    $role = trim($role);
    if ($role === '') {
        return $out;
    }
    try {
        $st = $pdo->prepare("SELECT permission_key, allowed FROM role_permissions WHERE role=:r");
        $st->execute([':r' => $role]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $key = (string)($row['permission_key'] ?? '');
            if (array_key_exists($key, $out)) {
                $out[$key] = (int)($row['allowed'] ?? 0) === 1;
            }
        }
        // Если для администратора строк ещё нет — не запираем редактирование.
        if ($role === 'Администратор' && !$rows) {
            foreach ($out as $key => $_v) {
                $out[$key] = true;
            }
        }
    } catch (Throwable) {
    }
    return $out;
}

/** Сохранить права роли (заменяет набор по каталогу). */
function wms_role_permissions_save(PDO $pdo, string $role, array $permissions): void
{
    if (function_exists('wms_ensure_cached')) {
        wms_ensure_cached($pdo, 'role_permissions', 'wms_role_permissions_ensure');
    } else {
        wms_role_permissions_ensure($pdo);
    }
    $role = trim($role);
    if ($role === '') {
        throw new RuntimeException('Не указана роль');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM role_permissions WHERE role=:r")->execute([':r' => $role]);
        $st = $pdo->prepare("INSERT INTO role_permissions (role, permission_key, allowed) VALUES (:r, :k, :a)");
        foreach (array_keys(wms_permissions_catalog()) as $key) {
            $v = $permissions[$key] ?? false;
            $allowed = (is_bool($v) ? $v : !empty($v)) ? 1 : 0;
            $st->execute([':r' => $role, ':k' => $key, ':a' => $allowed]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
