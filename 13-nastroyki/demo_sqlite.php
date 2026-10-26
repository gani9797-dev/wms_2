<?php
declare(strict_types=1);
// Модуль 13 «Настройки»: API для зон ячеек.
// Зоны сохраняются в storage_zones (та же БД, что модуль 05), поэтому
// созданная здесь зона сразу доступна в форме ячейки и в подборе ТСД.
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/StorageZones.php';
require_once __DIR__ . '/../shared/ImportRobot.php';

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
            $st = $pdo->prepare(
                "UPDATE storage_zones SET code=:code, name=:name, purpose=:purpose,
                        affects_placement=:ap, affects_reservation=:ar, contractor_id=:cid,
                        is_virtual=:virt, is_active=:act, warehouse_id=:wh
                  WHERE id=:id"
            );
            $p[':id'] = $id;
            $st->execute($p);
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
        if ($cnt > 0) {
            zj(['error' => 'Зона используется в ячейках (' . $cnt . '). Сначала смените зону у ячеек.'], 409);
        }
        $pdo->prepare("DELETE FROM storage_zones WHERE id=:id")->execute([':id' => (int)$row['id']]);
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
