<?php
declare(strict_types=1);

// Буфер отбора ТСД: виртуальная промежуточная ячейка пользователя + сборочная паллета.
//
// Требование заказчика:
//   «при отборе товар перемещается в виртуальную промежуточную ячейку пользователя,
//    пока товар не будет перемещён на ворота», а «сборка происходит на сборочной
//    паллете (сканируем sscc)» — значит, до сборки на сборочной паллете следующий
//    отбор запрещено взять.
//
// Буфер — это НЕ реальная ячейка склада (в storage_cells ничего не создаётся),
// только учёт: сколько и какого товара лежит в «руках» оператора.
//
// Статусы буфера (tsd_pick_buffers.status):
//   picked    — отобрано в виртуальную промежуточную ячейку пользователя;
//   at_gate   — паллета перенесена на ворота сортировки (gate заполнен);
//   assembled — собрано на сборочной паллете (assembly_sscc заполнен).
//
// Открытые позиции (picked/at_gate) — это те, которые запрещают следующий отбор.

require_once __DIR__ . '/db.php';

if (!defined('PICK_BUFFER_OPEN_STATUSES')) {
    /** Статусы буфера, которые запрещают следующий отбор. */
    define('PICK_BUFFER_OPEN_STATUSES', ['picked', 'at_gate']);
}

/** Таблица буфера отбора (в SQLite — сквозная wms_common). */
function wms_pick_buffer_table(PDO $pdo): string
{
    return wms_ext_table($pdo, 'tsd_pick_buffers');
}

/** Схема буфера создаётся один раз на запрос (идемпотентно). */
function wms_pick_buffer_ready(PDO $pdo): void
{
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    $done[$key] = true;
    try {
        wms_ensure_ext_schema($pdo);
    } catch (Throwable) {
    }
}

/**
 * Код виртуальной (промежуточной) ячейки пользователя: VIRT-<user_id>.
 * Если у пользователя нет id — VIRT-1.
 */
function wms_pick_buffer_cell(?int $userId): string
{
    $id = (int)($userId ?? 0);
    return 'VIRT-' . ($id > 0 ? $id : 1);
}

/** Текущее время в формате БД. */
function wms_pick_buffer_now(PDO $pdo): string
{
    return wms_is_sqlite($pdo) ? "datetime('now')" : 'NOW()';
}

/**
 * Открытые позиции буфера пользователя по отгрузке (picked/at_gate).
 * @return array[] строки буфера
 */
function wms_pick_buffer_open(PDO $pdo, int $shipmentId, ?int $userId = null): array
{
    wms_pick_buffer_ready($pdo);
    $t = wms_pick_buffer_table($pdo);
    $sql = "SELECT * FROM $t WHERE shipment_id=:s AND status IN ('picked','at_gate')";
    $params = [':s' => $shipmentId];
    if ($userId !== null) {
        $sql .= ' AND user_id=:u';
        $params[':u'] = $userId;
    } else {
        // Пользователь неизвестен — его буфер (анонимный, VIRT-1).
        $sql .= ' AND user_id IS NULL';
    }
    $sql .= ' ORDER BY id';
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
}

/**
 * Добавить позицию в виртуальную промежуточную ячейку пользователя.
 * @return int id созданной строки (0 — не создана)
 */
function wms_pick_buffer_add(
    PDO $pdo,
    int $shipmentId,
    ?int $taskId,
    string $sku,
    string $productName,
    float $qty,
    ?int $userId,
    string $userFio = ''
): int {
    wms_pick_buffer_ready($pdo);
    $t = wms_pick_buffer_table($pdo);
    $now = wms_pick_buffer_now($pdo);
    try {
        $st = $pdo->prepare(
            "INSERT INTO $t (shipment_id, task_id, sku, product_name, qty, user_id, user_fio,
                    buffer_cell, assembly_sscc, gate, status, created_at, updated_at)
             VALUES (:s, :t, :sku, :pn, :q, :u, :f, :cell, NULL, NULL, 'picked', $now, $now)"
        );
        $st->execute([
            ':s' => $shipmentId,
            ':t' => $taskId,
            ':sku' => $sku,
            ':pn' => ($productName !== '' ? $productName : null),
            ':q' => $qty,
            ':u' => $userId,
            ':f' => ($userFio !== '' ? $userFio : null),
            ':cell' => wms_pick_buffer_cell($userId),
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Перенос паллеты на ворота: picked -> at_gate (ворота записываются в буфер).
 * @return int число обновлённых позиций
 */
function wms_pick_buffer_to_gate(PDO $pdo, int $shipmentId, string $gate, ?int $userId = null): int
{
    wms_pick_buffer_ready($pdo);
    $t = wms_pick_buffer_table($pdo);
    $sql = "UPDATE $t SET status='at_gate', gate=:g, assembly_sscc=NULL, updated_at=" . wms_pick_buffer_now($pdo)
        . " WHERE shipment_id=:s AND status='picked'";
    $params = [':g' => ($gate !== '' ? $gate : null), ':s' => $shipmentId];
    if ($userId !== null) {
        $sql .= ' AND user_id=:u';
        $params[':u'] = $userId;
    } else {
        $sql .= ' AND user_id IS NULL';
    }
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Состояние буфера по отгрузке: сколько позиций на промежуточной ячейке,
 * на воротах и собрано; SSCC собранной сборочной паллеты.
 * @return array{picked:int,at_gate:int,assembled:int,open:int,cells:string[],assembly_sscc:?string}
 */
function wms_pick_buffer_state(PDO $pdo, int $shipmentId): array
{
    wms_pick_buffer_ready($pdo);
    $t = wms_pick_buffer_table($pdo);
    $out = ['picked' => 0, 'at_gate' => 0, 'assembled' => 0, 'open' => 0, 'cells' => [], 'assembly_sscc' => null];
    try {
        $st = $pdo->prepare("SELECT status, COUNT(*) c FROM $t WHERE shipment_id=:s GROUP BY status");
        $st->execute([':s' => $shipmentId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = (string)$r['status'];
            if (isset($out[$k])) {
                $out[$k] = (int)$r['c'];
            }
        }
        $st = $pdo->prepare("SELECT DISTINCT buffer_cell FROM $t WHERE shipment_id=:s ORDER BY buffer_cell");
        $st->execute([':s' => $shipmentId]);
        $out['cells'] = array_values(array_filter(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN))));
        $st = $pdo->prepare(
            "SELECT assembly_sscc FROM $t WHERE shipment_id=:s AND status='assembled' AND assembly_sscc IS NOT NULL ORDER BY id DESC LIMIT 1"
        );
        $st->execute([':s' => $shipmentId]);
        $v = $st->fetchColumn();
        $out['assembly_sscc'] = ($v !== false && $v !== null && $v !== '') ? (string)$v : null;
    } catch (Throwable) {
    }
    $out['open'] = $out['picked'] + $out['at_gate'];
    return $out;
}

/**
 * Сборка на сборочной паллете по SSCC: at_gate -> assembled.
 * Проверки: SSCC обязателен; товар должен быть перемещён на ворота;
 * если по отгрузке уже собрана сборочная паллета с ДРУГИМ SSCC — отказ.
 * @return array{ok:bool,code:int,error:?string,updated:int,state:array}
 */
function wms_pick_buffer_assemble(PDO $pdo, int $shipmentId, string $sscc): array
{
    $sscc = trim($sscc);
    $state = wms_pick_buffer_state($pdo, $shipmentId);
    $res = ['ok' => false, 'code' => 422, 'error' => null, 'updated' => 0, 'state' => $state];
    if ($sscc === '') {
        $res['error'] = 'Отсканируй SSCC сборочной паллеты';
        $res['code'] = 422;
        return $res;
    }
    // Уже собрана сборочная паллета с другим SSCC — отказ.
    $samePallet = ($state['assembly_sscc'] !== null && $state['assembly_sscc'] === $sscc);
    if ($state['assembly_sscc'] !== null && !$samePallet) {
        $res['error'] = 'Сборочная паллета уже собрана: SSCC ' . $state['assembly_sscc'];
        $res['code'] = 409;
        return $res;
    }
    // Товар, отобранный, но не перенесённый на ворота, собирать нельзя.
    if ($state['picked'] > 0) {
        $res['error'] = 'Товар не перемещён на ворота: ' . $state['picked'] . ' позиций';
        $res['code'] = 409;
        return $res;
    }
    if ($state['at_gate'] <= 0) {
        if ($samePallet) {
            // Та же сборочная паллета — повторное подтверждение (ничего не меняем).
            $res['ok'] = true;
            $res['code'] = 200;
            return $res;
        }
        $res['error'] = 'На воротах нет товара для сборки';
        $res['code'] = 409;
        return $res;
    }
    wms_pick_buffer_ready($pdo);
    $t = wms_pick_buffer_table($pdo);
    try {
        $st = $pdo->prepare(
            "UPDATE $t SET status='assembled', assembly_sscc=:sscc, updated_at=" . wms_pick_buffer_now($pdo)
            . " WHERE shipment_id=:s AND status='at_gate'"
        );
        $st->execute([':sscc' => $sscc, ':s' => $shipmentId]);
        $res['updated'] = $st->rowCount();
    } catch (Throwable $e) {
        $res['error'] = $e->getMessage();
        $res['code'] = 500;
        return $res;
    }
    $res['ok'] = true;
    $res['code'] = 200;
    $res['state'] = wms_pick_buffer_state($pdo, $shipmentId);
    return $res;
}

/**
 * Ворота отгрузки — источник истины: shipments.gate + shipments.gates_extra
 * (в gates_extra ворота могут быть перечислены через запятую).
 * @return array{shipment_id:int,doc_no:string,gate:?string,gates_extra:?string,gates:string[],has_gates:bool}
 */
function wms_pick_shipment_gates(PDO $ship, int $shipmentId): array
{
    $out = [
        'shipment_id' => $shipmentId, 'doc_no' => '', 'gate' => null,
        'gates_extra' => null, 'gates' => [], 'has_gates' => false,
    ];
    try {
        $st = $ship->prepare('SELECT id, doc_no, gate, gates_extra FROM shipments WHERE id=:id');
        $st->execute([':id' => $shipmentId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $r = null;
    }
    if (!$r) {
        return $out;
    }
    $out['doc_no'] = (string)($r['doc_no'] ?? '');
    $out['gate'] = ($r['gate'] ?? null) !== null && trim((string)$r['gate']) !== '' ? trim((string)$r['gate']) : null;
    $out['gates_extra'] = ($r['gates_extra'] ?? null) !== null && trim((string)$r['gates_extra']) !== ''
        ? trim((string)$r['gates_extra']) : null;
    $gates = [];
    foreach ([$out['gate'], $out['gates_extra']] as $part) {
        if ($part === null || $part === '') {
            continue;
        }
        foreach (preg_split('/[,;\/]+/u', (string)$part) as $g) {
            $g = trim($g);
            if ($g !== '' && !in_array($g, $gates, true)) {
                $gates[] = $g;
            }
        }
    }
    $out['gates'] = $gates;
    $out['has_gates'] = (bool)$gates;
    return $out;
}

/** Есть ли ворота в списке ворот отгрузки (без учёта регистра). */
function wms_pick_gate_allowed(array $gates, string $gate): bool
{
    $gate = trim($gate);
    if ($gate === '') {
        return false;
    }
    $low = function_exists('mb_strtolower') ? fn($v) => mb_strtolower($v, 'UTF-8') : fn($v) => strtolower($v);
    foreach ($gates as $g) {
        if ($low(trim((string)$g)) === $low($gate)) {
            return true;
        }
    }
    return false;
}

/**
 * Текст запрета следующего отбора (в буфер — сообщение для оператора).
 * @param array $open открытые позиции буфера
 */
function wms_pick_buffer_block_text(array $open): string
{
    $n = count($open);
    return 'Сначала переместите паллету на ворота и соберите её на сборочной паллете (открытых позиций: ' . $n . ')';
}

/**
 * Активна ли сессия отбора пользователя по отгрузке (оператор продолжает
 * текущий отбор, а не начинает следующий).
 */
function wms_pick_session_shipment(PDO $pdo, int $shipmentId, ?int $userId): bool
{
    try {
        if ($userId === null) {
            $st = $pdo->prepare("SELECT shipment_id FROM tsd_otbor_locks WHERE shipment_id=:s AND user_id IS NULL AND status='work' LIMIT 1");
            $st->execute([':s' => $shipmentId]);
            return (bool)$st->fetchColumn();
        }
        $st = $pdo->prepare("SELECT shipment_id FROM tsd_otbor_locks WHERE shipment_id=:s AND user_id=:u AND status='work' LIMIT 1");
        $st->execute([':s' => $shipmentId, ':u' => $userId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

/** Активна ли сессия отбора по заданию (режим «Отбор по заданиям»). */
function wms_pick_session_task(PDO $pdo, int $taskId, ?int $userId): bool
{
    try {
        if ($userId === null) {
            $st = $pdo->prepare("SELECT task_id FROM tsd_task_locks WHERE task_id=:t AND user_id IS NULL AND status='work' LIMIT 1");
            $st->execute([':t' => $taskId]);
            return (bool)$st->fetchColumn();
        }
        $st = $pdo->prepare("SELECT task_id FROM tsd_task_locks WHERE task_id=:t AND user_id=:u AND status='work' LIMIT 1");
        $st->execute([':t' => $taskId, ':u' => $userId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}
