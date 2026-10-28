<?php
declare(strict_types=1);
// Модуль 13 «Настройки»: API для зон ячеек.
// Зоны сохраняются в storage_zones (та же БД, что модуль 05), поэтому
// созданная здесь зона сразу доступна в форме ячейки и в подборе ТСД.
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/StorageZones.php';
require_once __DIR__ . '/../shared/CellZones.php';
require_once __DIR__ . '/../shared/ImportRobot.php';
require_once __DIR__ . '/../shared/Roles.php';
require_once __DIR__ . '/../10-sotrudniki/EmployeeAccess.php';

header('Content-Type: application/json; charset=utf-8');

$action = (string)($_GET['action'] ?? 'zones');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$pdo = wms_storage_pdo();
// Ленивый таймер робота: при любом заходе в настройки проверяем, не пора ли
// выполнить импорт in/out. Точный фоновый запуск — php robot.php --loop.
try {
    wms_robot_tick();
} catch (Throwable) {
}

function zj(mixed $d, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function zb(mixed $v): int
{
    return in_array($v, [1, '1', true, 'true', 'on', 'yes'], true) ? 1 : 0;
}

try {
    if ($action === 'zones') {
        zj(['rows' => wms_storage_zone_rows($pdo, false)]);
    }

    if ($action === 'contractors') {
        require_once __DIR__ . '/../shared/Counterparties.php';
        $rows = [];
        try {
            foreach (WmsCounterparties::listAll(wms_nomenclature_pdo(), false) as $c) {
                $rows[] = ['id' => (int)($c['id'] ?? 0), 'name' => (string)($c['name'] ?? '')];
            }
        } catch (Throwable) {
        }
        zj(['rows' => $rows]);
    }

    if ($action === 'zone_save' && $method === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int)($b['id'] ?? 0);
        $code = trim((string)($b['code'] ?? ''));
        $name = trim((string)($b['name'] ?? ''));
        if ($code === '' || $name === '') {
            zj(['error' => 'Укажите код и наименование зоны'], 422);
        }
        if (!preg_match('/^[A-Za-zА-Яа-я0-9_.-]{1,30}$/u', $code)) {
            zj(['error' => 'Код зоны: буквы, цифры, . _ - (до 30 символов)'], 422);
        }
        wms_storage_zones_ensure($pdo);
        $dup = $pdo->prepare("SELECT id FROM storage_zones WHERE LOWER(code)=LOWER(:c) AND id<>:id LIMIT 1");
        $dup->execute([':c' => $code, ':id' => $id]);
        if ($dup->fetchColumn()) {
            zj(['error' => 'Зона с таким кодом уже существует'], 409);
        }
        $p = [
            ':code' => $code,
            ':name' => $name,
            ':purpose' => trim((string)($b['purpose'] ?? '')) ?: null,
            ':ap' => zb($b['affects_placement'] ?? 0),
            ':ar' => zb($b['affects_reservation'] ?? 0),
            ':cid' => (int)($b['contractor_id'] ?? 0) ?: null,
            ':virt' => zb($b['is_virtual'] ?? 0),
            ':act' => zb($b['is_active'] ?? 1),
            ':wh' => (int)($b['warehouse_id'] ?? 0) ?: null,
        ];
        if ($id > 0) {
            $oldSt = $pdo->prepare("SELECT code FROM storage_zones WHERE id=:id LIMIT 1");
            $oldSt->execute([':id' => $id]);
            $oldCode = (string)($oldSt->fetchColumn() ?: '');
            $st = $pdo->prepare(
                "UPDATE storage_zones SET code=:code, name=:name, purpose=:purpose,
                        affects_placement=:ap, affects_reservation=:ar, contractor_id=:cid,
                        is_virtual=:virt, is_active=:act, warehouse_id=:wh
                  WHERE id=:id"
            );
            $p[':id'] = $id;
            $st->execute($p);
            if ($oldCode !== '' && strcasecmp($oldCode, $code) !== 0) {
                try {
                    $pdo->prepare("UPDATE storage_cells SET zone=:new WHERE LOWER(zone)=LOWER(:old)")
                        ->execute([':new' => $code, ':old' => $oldCode]);
                } catch (Throwable) {
                }
                try {
                    $ids = $pdo->prepare("SELECT DISTINCT cell_id FROM storage_cell_extra_zones WHERE LOWER(zone)=LOWER(:old)");
                    $ids->execute([':old' => $oldCode]);
                    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $cellId) {
                        try {
                            $pdo->prepare("INSERT INTO storage_cell_extra_zones (cell_id, zone) VALUES (:c,:z)")
                                ->execute([':c' => (int)$cellId, ':z' => $code]);
                        } catch (Throwable) {
                        }
                        $pdo->prepare("DELETE FROM storage_cell_extra_zones WHERE cell_id=:c AND LOWER(zone)=LOWER(:old)")
                            ->execute([':c' => (int)$cellId, ':old' => $oldCode]);
                    }
                } catch (Throwable) {
                }
            }
        } else {
            $st = $pdo->prepare(
                "INSERT INTO storage_zones (code,name,purpose,affects_placement,affects_reservation,
                        contractor_id,is_virtual,is_active,warehouse_id)
                 VALUES (:code,:name,:purpose,:ap,:ar,:cid,:virt,:act,:wh)"
            );
            $st->execute($p);
            $id = (int)$pdo->lastInsertId();
        }
        $row = null;
        foreach (wms_storage_zone_rows($pdo, false) as $z) {
            if ((int)$z['id'] === $id) {
                $row = $z;
                break;
            }
        }
        zj(['ok' => true, 'zone' => $row]);
    }

    if ($action === 'zone_delete' && $method === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int)($b['id'] ?? 0);
        $code = trim((string)($b['code'] ?? ''));
        if ($id <= 0 && $code === '') {
            zj(['error' => 'Не указана зона'], 422);
        }
        $st = $id > 0
            ? $pdo->prepare("SELECT * FROM storage_zones WHERE id=:id LIMIT 1")
            : $pdo->prepare("SELECT * FROM storage_zones WHERE LOWER(code)=LOWER(:code) LIMIT 1");
        $st->execute($id > 0 ? [':id' => $id] : [':code' => $code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            zj(['error' => 'Зона не найдена'], 404);
        }
        $zoneCode = (string)($row['code'] ?? '');
        $used = $pdo->prepare("SELECT COUNT(*) FROM storage_cells WHERE LOWER(zone)=LOWER(:z)");
        $used->execute([':z' => $zoneCode]);
        $cnt = (int)$used->fetchColumn();
        try {
            $extra = $pdo->prepare("SELECT COUNT(*) FROM storage_cell_extra_zones WHERE LOWER(zone)=LOWER(:z)");
            $extra->execute([':z' => $zoneCode]);
            $cnt += (int)$extra->fetchColumn();
        } catch (Throwable) {
        }
        if ($cnt > 0) {
            zj(['error' => 'Зона используется в ячейках (' . $cnt . '). Сначала смените зону у ячеек.'], 409);
        }
        $pdo->prepare("DELETE FROM storage_zones WHERE id=:id")->execute([':id' => (int)$row['id']]);
        zj(['ok' => true]);
    }

    if ($action === 'roles_list') {
        zj(['rows' => wms_roles_list(wms_employees_pdo(), false)]);
    }

    if ($action === 'role_create' && $method === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = wms_role_create(wms_employees_pdo(), (string)($b['name'] ?? ''));
        zj(['ok' => true, 'id' => $id, 'rows' => wms_roles_list(wms_employees_pdo(), false)]);
    }

    if ($action === 'role_rename' && $method === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        wms_role_rename(wms_employees_pdo(), (int)($b['id'] ?? 0), (string)($b['name'] ?? ''));
        zj(['ok' => true, 'rows' => wms_roles_list(wms_employees_pdo(), false)]);
    }

    if ($action === 'role_delete' && $method === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        wms_role_delete(wms_employees_pdo(), (int)($b['id'] ?? 0));
        zj(['ok' => true, 'rows' => wms_roles_list(wms_employees_pdo(), false)]);
    }

    if ($action === 'role_access_get') {
        $role = trim((string)($_GET['role'] ?? ''));
        $emp = wms_employees_pdo();
        $items = [];
        if ($role !== '') {
            $st = $emp->prepare("SELECT scope, module_key, level FROM role_access WHERE role=:r ORDER BY scope, module_key");
            $st->execute([':r' => $role]);
            $items = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$items) {
                $limited = $role === 'Водитель';
                foreach (EmployeeAccess::WMS_MODULES as $key => $_label) {
                    $items[] = [
                        'scope' => EmployeeAccess::SCOPE_WMS,
                        'module_key' => $key,
                        'level' => (!$limited || in_array($key, ['reestr', 'int'], true))
                            ? EmployeeAccess::LEVEL_FULL : EmployeeAccess::LEVEL_NONE,
                    ];
                }
                $items[] = [
                    'scope' => EmployeeAccess::SCOPE_TSD,
                    'module_key' => EmployeeAccess::TSD_MODULE,
                    'level' => $limited ? EmployeeAccess::LEVEL_NONE : EmployeeAccess::LEVEL_FULL,
                ];
            }
        }
        zj(['role' => $role, 'items' => $items,
            'modules' => EmployeeAccess::WMS_MODULES, 'levels' => EmployeeAccess::levels(),
            'permissions' => $role !== '' ? wms_role_permissions_get($emp, $role) : [],
            'permissions_catalog' => wms_permissions_catalog()]);
    }

    if ($action === 'role_access_save' && $method === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $role = trim((string)($b['role'] ?? ''));
        if ($role === '') {
            zj(['error' => 'Не указана роль'], 422);
        }
        $items = (array)($b['items'] ?? []);
        $emp = wms_employees_pdo();
        $emp->beginTransaction();
        try {
            $emp->prepare("DELETE FROM role_access WHERE role=:r")->execute([':r' => $role]);
            $ins = $emp->prepare("INSERT INTO role_access (role,scope,module_key,level) VALUES (:r,:sc,:mk,:lv)");
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $key = trim((string)($it['module_key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $scope = (string)($it['scope'] ?? EmployeeAccess::SCOPE_WMS);
                if ($scope === EmployeeAccess::SCOPE_TSD) {
                    $key = EmployeeAccess::TSD_MODULE;
                }
                $ins->execute([':r' => $role, ':sc' => $scope, ':mk' => $key,
                    ':lv' => EmployeeAccess::normalizeLevel((string)($it['level'] ?? ''))]);
            }
            $emp->commit();
            if (array_key_exists('permissions', $b) && is_array($b['permissions'])) {
                wms_role_permissions_save($emp, $role, $b['permissions']);
            }
        } catch (Throwable $e) {
            if ($emp->inTransaction()) {
                $emp->rollBack();
            }
            zj(['error' => $e->getMessage()], 400);
        }
        zj(['ok' => true]);
    }

    if ($action === 'robot_get') {
        $run = wms_robot_tick();
        $s = wms_robot_settings();
        zj(['settings' => $s, 'paths' => wms_robot_paths(), 'last_tick' => $run,
            'log' => wms_robot_log_rows(40)]);
    }

    if ($action === 'robot_save' && $method === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $s = wms_robot_settings_save($b);
        zj(['ok' => true, 'settings' => $s]);
    }

    if ($action === 'robot_run' && $method === 'POST') {
        $res = wms_robot_run();
        $res['log'] = wms_robot_log_rows(40);
        $res['settings'] = wms_robot_settings();
        zj($res);
    }

    if ($action === 'robot_log') {
        zj(['rows' => wms_robot_log_rows((int)($_GET['limit'] ?? 100))]);
    }

    zj(['error' => 'Неизвестное действие: zones|zone_save|zone_delete|contractors|robot_get|robot_save|robot_run|robot_log'], 404);
} catch (Throwable $e) {
    zj(['error' => $e->getMessage()], 400);
}
