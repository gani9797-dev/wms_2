<?php
declare(strict_types=1);
// Модуль 16 «Биллинги» (3PL). API.
//   GET  ?action=refs                     — справочники (типы расчёта, группы, ед., клиенты, услуги)
//   GET  ?action=services                 — каталог услуг
//   POST ?action=service_save             — создать/изменить услугу
//   GET  ?action=tariffs&client_id=       — тарифные соглашения
//   GET  ?action=tariff&id=               — ТС с линиями и ступенями
//   POST ?action=tariff_save              — сохранить ТС (шапка + линии + ступени)
//   POST ?action=tariff_status            — смена статуса ТС
//   GET  ?action=events&client_id=&from=&to= — журнал событий
//   POST ?action=event_add                — ручное событие
//   POST ?action=event_delete
//   POST ?action=run                      — биллинговый прогон {from,to,clients[],mode}
//   GET  ?action=charges&client_id=&from=&to= — начисления
//   POST ?action=adjust                   — корректировка начисления
//   GET  ?action=storage&client_id=&from=&to= — расшифровка суточного хранения
//   GET  ?action=periods                  — периоды
//   POST ?action=period_close / period_open
//   GET  ?action=summary&from=&to=        — ведомость (клиент × услуга)

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/Auth.php';
require_once __DIR__ . '/../shared/Billing.php';

header('Content-Type: application/json; charset=utf-8');

function j(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$pdo = billing_pdo();
$action = (string)($_GET['action'] ?? 'refs');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : $_GET;

$prevFrom = date('Y-m-01', strtotime('first day of previous month'));
$prevTo = date('Y-m-t', strtotime('first day of previous month'));

try {
    if ($action === 'refs') {
        $q = function (string $sql) use ($pdo): array {
            try {
                return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable) {
                return [];
            }
        };
        j([
            'calc_types' => $q('SELECT * FROM billing_calc_type ORDER BY id'),
            'groups' => $q('SELECT * FROM billing_service_group ORDER BY sort_order, id'),
            'uoms' => $q('SELECT * FROM billing_uom ORDER BY id'),
            'clients' => $q('SELECT * FROM billing_client ORDER BY name'),
            'services' => $q('SELECT s.*, g.name AS group_name, c.code AS calc_code, c.name AS calc_name, u.code AS uom_code, u.name AS uom_name
                                FROM billing_service s
                                LEFT JOIN billing_service_group g ON g.id = s.group_id
                                LEFT JOIN billing_calc_type c ON c.id = s.calc_type_id
                                LEFT JOIN billing_uom u ON u.id = s.uom_id
                               ORDER BY g.sort_order, s.sort_order, s.id'),
            'adj_reasons' => $q('SELECT * FROM billing_adj_reason ORDER BY id'),
            'default_period' => ['from' => $prevFrom, 'to' => $prevTo, 'current_from' => date('Y-m-01'), 'current_to' => date('Y-m-t')],
        ]);
    }

    if ($action === 'services') {
        $rows = $pdo->query('SELECT s.*, g.name AS group_name, c.code AS calc_code, c.name AS calc_name, u.code AS uom_code
                               FROM billing_service s
                               LEFT JOIN billing_service_group g ON g.id = s.group_id
                               LEFT JOIN billing_calc_type c ON c.id = s.calc_type_id
                               LEFT JOIN billing_uom u ON u.id = s.uom_id
                              ORDER BY g.sort_order, s.sort_order, s.id')->fetchAll(PDO::FETCH_ASSOC);
        j(['rows' => $rows]);
    }

    if ($action === 'service_save' && $method === 'POST') {
        $id = (int)($body['id'] ?? 0);
        $code = strtoupper(trim((string)($body['code'] ?? '')));
        $name = trim((string)($body['name'] ?? ''));
        if (!preg_match('/^[A-Z][A-Z0-9_]{1,39}$/', $code)) {
            throw new RuntimeException('Код услуги: ЛАТИНИЦА, цифры и _, до 40 символов');
        }
        if ($name === '') {
            throw new RuntimeException('Укажите наименование услуги');
        }
        $p = [
            ':c' => $code, ':n' => $name,
            ':g' => (int)($body['group_id'] ?? 0) ?: null,
            ':t' => (int)($body['calc_type_id'] ?? 0) ?: null,
            ':u' => (int)($body['uom_id'] ?? 0) ?: null,
            ':d' => trim((string)($body['driver_code'] ?? '')) ?: null,
            ':e' => trim((string)($body['event_type_code'] ?? '')) ?: null,
            ':p' => (float)($body['default_price'] ?? 0),
            ':v' => (float)($body['vat_rate'] ?? 20),
            ':a' => !empty($body['is_active']) ? 1 : 0,
            ':m' => trim((string)($body['comment'] ?? '')) ?: null,
            ':s' => (int)($body['sort_order'] ?? 0),
        ];
        if ($id > 0) {
            $p[':id'] = $id;
            $pdo->prepare('UPDATE billing_service SET code=:c,name=:n,group_id=:g,calc_type_id=:t,uom_id=:u,driver_code=:d,
                    event_type_code=:e,default_price=:p,vat_rate=:v,is_active=:a,comment=:m,sort_order=:s WHERE id=:id')->execute($p);
        } else {
            try {
                $pdo->prepare('INSERT INTO billing_service (code,name,group_id,calc_type_id,uom_id,driver_code,event_type_code,
                        default_price,vat_rate,is_active,comment,sort_order) VALUES (:c,:n,:g,:t,:u,:d,:e,:p,:v,:a,:m,:s)')->execute($p);
                $id = (int)$pdo->lastInsertId();
            } catch (Throwable $e) {
                throw new RuntimeException('Не удалось создать услугу (код уже занят?)');
            }
        }
        j(['ok' => true, 'id' => $id]);
    }

    if ($action === 'tariffs') {
        $clientId = (int)($_GET['client_id'] ?? 0);
        $w = $clientId > 0 ? 'WHERE t.client_id=' . $clientId : '';
        $rows = $pdo->query("SELECT t.*, c.name AS client_name,
                (SELECT COUNT(*) FROM billing_tariff_line l WHERE l.tariff_id=t.id) AS lines,
                (SELECT COUNT(*) FROM billing_tariff_line l WHERE l.tariff_id=t.id AND l.is_enabled=1) AS lines_on
                FROM billing_tariff t LEFT JOIN billing_client c ON c.id=t.client_id $w
                ORDER BY t.date_from DESC, t.id DESC")->fetchAll(PDO::FETCH_ASSOC);
        j(['rows' => $rows]);
    }

    if ($action === 'tariff') {
        $id = (int)($_GET['id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM billing_tariff WHERE id=:id');
        $st->execute([':id' => $id]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) {
            j(['error' => 'Тарифное соглашение не найдено'], 404);
        }
        $st = $pdo->prepare('SELECT * FROM billing_tariff_line WHERE tariff_id=:id ORDER BY id');
        $st->execute([':id' => $id]);
        $lines = $st->fetchAll(PDO::FETCH_ASSOC);
        $tierSt = $pdo->prepare('SELECT * FROM billing_tariff_tier WHERE line_id=:l ORDER BY threshold_from');
        foreach ($lines as &$ln) {
            $tierSt->execute([':l' => (int)$ln['id']]);
            $ln['tiers'] = $tierSt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($ln);
        $t['lines'] = $lines;
        j(['tariff' => $t]);
    }

    if ($action === 'tariff_save' && $method === 'POST') {
        if (billing_driver($pdo) === 'pgsql') {
            // OK
        }
        $id = (int)($body['id'] ?? 0);
        $clientId = (int)($body['client_id'] ?? 0);
        $number = trim((string)($body['number'] ?? ''));
        $dateFrom = substr((string)($body['date_from'] ?? ''), 0, 10);
        $dateTo = substr((string)($body['date_to'] ?? ''), 0, 10);
        if ($clientId <= 0 || $number === '' || $dateFrom === '') {
            throw new RuntimeException('Укажите клиента, номер и дату начала ТС');
        }
        if ($dateTo !== '' && $dateTo < $dateFrom) {
            throw new RuntimeException('Дата окончания раньше даты начала');
        }
        $status = in_array((string)($body['status'] ?? ''), ['draft', 'approved', 'archive'], true) ? (string)$body['status'] : 'draft';
        $head = [
            ':c' => $clientId, ':n' => $number, ':f' => $dateFrom, ':t' => ($dateTo !== '' ? $dateTo : null),
            ':st' => $status, ':b' => (string)($body['storage_day_basis'] ?? 'END_OF_DAY'),
            ':fd' => (int)($body['free_storage_days'] ?? 0),
            ':ra' => (float)($body['min_charge_amount'] ?? 0),
            ':no' => trim((string)($body['notes'] ?? '')) ?: null,
        ];
        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $head[':id'] = $id;
                $pdo->prepare('UPDATE billing_tariff SET client_id=:c,number=:n,date_from=:f,date_to=:t,status=:st,
                        storage_day_basis=:b,free_storage_days=:fd,min_charge_amount=:ra,notes=:no,version=version+1 WHERE id=:id')
                    ->execute($head);
                $pdo->prepare('DELETE FROM billing_tariff_tier WHERE line_id IN (SELECT id FROM billing_tariff_line WHERE tariff_id=:id)')
                    ->execute([':id' => $id]);
                $pdo->prepare('DELETE FROM billing_tariff_line WHERE tariff_id=:id')->execute([':id' => $id]);
            } else {
                $pdo->prepare('INSERT INTO billing_tariff (client_id,number,date_from,date_to,status,storage_day_basis,
                        free_storage_days,min_charge_amount,notes,version) VALUES (:c,:n,:f,:t,:st,:b,:fd,:ra,:no,1)')
                    ->execute($head);
                $id = (int)$pdo->lastInsertId();
            }
            $insLine = $pdo->prepare('INSERT INTO billing_tariff_line (tariff_id,service_id,is_enabled,price,min_charge,max_charge,
                    discount_percent,min_qty_threshold,free_qty) VALUES (:t,:s,:e,:p,:mn,:mx,:d,:th,:fq)');
            $insTier = $pdo->prepare('INSERT INTO billing_tariff_tier (line_id,threshold_from,threshold_to,price,mode,basis)
                    VALUES (:l,:f,:t,:p,:m,:b)');
            foreach ((array)($body['lines'] ?? []) as $ln) {
                $sid = (int)($ln['service_id'] ?? 0);
                if ($sid <= 0) {
                    continue;
                }
                $insLine->execute([
                    ':t' => $id, ':s' => $sid, ':e' => !empty($ln['is_enabled']) ? 1 : 0,
                    ':p' => (float)($ln['price'] ?? 0),
                    ':mn' => (isset($ln['min_charge']) && $ln['min_charge'] !== '' && $ln['min_charge'] !== null) ? (float)$ln['min_charge'] : null,
                    ':mx' => (isset($ln['max_charge']) && $ln['max_charge'] !== '' && $ln['max_charge'] !== null) ? (float)$ln['max_charge'] : null,
                    ':d' => (float)($ln['discount_percent'] ?? 0),
                    ':th' => (isset($ln['min_qty_threshold']) && $ln['min_qty_threshold'] !== '' && $ln['min_qty_threshold'] !== null) ? (float)$ln['min_qty_threshold'] : null,
                    ':fq' => (float)($ln['free_qty'] ?? 0),
                ]);
                $lineId = (int)$pdo->lastInsertId();
                $tiers = (array)($ln['tiers'] ?? []);
                usort($tiers, fn($a, $b) => (float)($a['from'] ?? 0) <=> (float)($b['from'] ?? 0));
                $prevTo = null;
                foreach ($tiers as $tr) {
                    $from = (float)($tr['from'] ?? 0);
                    $to = (isset($tr['to']) && $tr['to'] !== '' && $tr['to'] !== null) ? (float)$tr['to'] : null;
                    if ($prevTo !== null && $from <= $prevTo) {
                        throw new RuntimeException('Ступени не должны пересекаться (порог ' . $from . ')');
                    }
                    $prevTo = $to;
                    $insTier->execute([
                        ':l' => $lineId, ':f' => $from, ':t' => $to,
                        ':p' => (float)($tr['price'] ?? 0),
                        ':m' => strtoupper((string)($tr['mode'] ?? 'VOLUME')) === 'PROGRESSIVE' ? 'PROGRESSIVE' : 'VOLUME',
                        ':b' => (string)($tr['basis'] ?? 'PER_DAY'),
                    ]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        j(['ok' => true, 'id' => $id]);
    }

    if ($action === 'tariff_status' && $method === 'POST') {
        $id = (int)($body['id'] ?? 0);
        $to = (string)($body['status'] ?? '');
        if (!in_array($to, ['draft', 'approved', 'archive'], true)) {
            throw new RuntimeException('Неверный статус');
        }
        $pdo->prepare('UPDATE billing_tariff SET status=:s WHERE id=:id')->execute([':s' => $to, ':id' => $id]);
        j(['ok' => true]);
    }

    if ($action === 'events') {
        $clientId = (int)($_GET['client_id'] ?? 0);
        $from = substr((string)($_GET['from'] ?? ''), 0, 10);
        $to = substr((string)($_GET['to'] ?? ''), 0, 10);
        $w = ['1=1'];
        $p = [];
        if ($clientId > 0) {
            $w[] = 'e.client_id=:c';
            $p[':c'] = $clientId;
        }
        if ($from !== '') {
            $w[] = 'e.event_date>=:f';
            $p[':f'] = $from;
        }
        if ($to !== '') {
            $w[] = 'e.event_date<=:t';
            $p[':t'] = $to;
        }
        $st = $pdo->prepare('SELECT e.*, c.name AS client_name, s.name AS service_name
                               FROM billing_event e
                               LEFT JOIN billing_client c ON c.id=e.client_id
                               LEFT JOIN billing_service s ON s.event_type_code=e.event_type_code
                              WHERE ' . implode(' AND ', $w) . ' ORDER BY e.event_date DESC, e.id DESC LIMIT 500');
        $st->execute($p);
        j(['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'event_add' && $method === 'POST') {
        $clientId = (int)($body['client_id'] ?? 0);
        $type = strtoupper(trim((string)($body['event_type_code'] ?? '')));
        $date = substr((string)($body['event_date'] ?? ''), 0, 10);
        $qty = (float)($body['quantity'] ?? 0);
        if ($clientId <= 0 || $type === '' || $date === '' || $qty <= 0) {
            throw new RuntimeException('Укажите клиента, тип события, дату и количество > 0');
        }
        $uom = trim((string)($body['uom_code'] ?? ''));
        if ($uom === '') {
            $q = $pdo->prepare('SELECT u.code FROM billing_service s JOIN billing_uom u ON u.id=s.uom_id WHERE s.event_type_code=:e LIMIT 1');
            $q->execute([':e' => $type]);
            $uom = (string)($q->fetchColumn() ?: '');
        }
        $pdo->prepare("INSERT INTO billing_event (external_id,source,client_id,event_type_code,event_date,quantity,uom_code,doc_type,doc_no,status,reason)
                VALUES (:e,'MANUAL',:c,:t,:d,:q,:u,:dt,:dn,'NEW',:r)")
            ->execute([
                ':e' => 'MAN-' . date('YmdHis') . '-' . random_int(100, 999),
                ':c' => $clientId, ':t' => $type, ':d' => $date, ':q' => $qty, ':u' => ($uom !== '' ? $uom : null),
                ':dt' => trim((string)($body['doc_type'] ?? '')) ?: null,
                ':dn' => trim((string)($body['doc_no'] ?? '')) ?: null,
                ':r' => trim((string)($body['reason'] ?? '')) ?: null,
            ]);
        j(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    }

    if ($action === 'event_delete' && $method === 'POST') {
        $id = (int)($body['id'] ?? 0);
        $pdo->prepare("DELETE FROM billing_event WHERE id=:id AND status IN ('NEW','NO_TARIFF','SERVICE_DISABLED')")->execute([':id' => $id]);
        j(['ok' => true]);
    }

    if ($action === 'run' && $method === 'POST') {
        $res = billing_run($pdo, [
            'from' => (string)($body['from'] ?? ''),
            'to' => (string)($body['to'] ?? ''),
            'clients' => (array)($body['clients'] ?? []),
            'mode' => (string)($body['mode'] ?? 'FULL'),
        ]);
        j($res);
    }

    if ($action === 'charges') {
        $clientId = (int)($_GET['client_id'] ?? 0);
        $from = substr((string)($_GET['from'] ?? ''), 0, 10);
        $to = substr((string)($_GET['to'] ?? ''), 0, 10);
        $status = trim((string)($_GET['status'] ?? ''));
        $w = ['1=1'];
        $p = [];
        if ($clientId > 0) {
            $w[] = 'c.client_id=:cl';
            $p[':cl'] = $clientId;
        }
        if ($from !== '') {
            $w[] = 'c.period_from>=:f';
            $p[':f'] = $from;
        }
        if ($to !== '') {
            $w[] = 'c.period_to<=:t';
            $p[':t'] = $to;
        }
        if ($status !== '') {
            $w[] = 'c.status=:st';
            $p[':st'] = $status;
        }
        $st = $pdo->prepare("SELECT c.*, s.code AS service_code, s.name AS service_name, cl.name AS client_name,
                t.number AS tariff_number
                FROM billing_charge c
                LEFT JOIN billing_service s ON s.id=c.service_id
                LEFT JOIN billing_client cl ON cl.id=c.client_id
                LEFT JOIN billing_tariff t ON t.id=c.tariff_id
                WHERE " . implode(' AND ', $w) . " ORDER BY c.charge_date DESC, c.id DESC LIMIT 1000");
        $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $sum = 0.0;
        foreach ($rows as $r) {
            if ((string)$r['status'] === 'ACTIVE') {
                $sum += (float)$r['amount_net'];
            }
        }
        j(['rows' => $rows, 'total_net' => round($sum, 2)]);
    }

    if ($action === 'adjust' && $method === 'POST') {
        $chargeId = (int)($body['charge_id'] ?? 0);
        $amount = (float)($body['amount'] ?? 0);
        $reason = trim((string)($body['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('Укажите причину корректировки');
        }
        $id = billing_adjustment($pdo, $chargeId, $amount, $reason, function_exists('wms_auth_actor_id') ? wms_auth_actor_id() : 0);
        j(['ok' => true, 'id' => $id]);
    }

    if ($action === 'storage') {
        $clientId = (int)($_GET['client_id'] ?? 0);
        $from = substr((string)($_GET['from'] ?? ''), 0, 10);
        $to = substr((string)($_GET['to'] ?? ''), 0, 10);
        $w = ['1=1'];
        $p = [];
        if ($clientId > 0) {
            $w[] = 'd.client_id=:c';
            $p[':c'] = $clientId;
        }
        if ($from !== '') {
            $w[] = 'd.day>=:f';
            $p[':f'] = $from;
        }
        if ($to !== '') {
            $w[] = 'd.day<=:t';
            $p[':t'] = $to;
        }
        $st = $pdo->prepare('SELECT d.*, c.name AS client_name, s.name AS service_name, s.code AS service_code
                               FROM billing_stock_daily d
                               LEFT JOIN billing_client c ON c.id=d.client_id
                               LEFT JOIN billing_service s ON s.id=d.service_id
                              WHERE ' . implode(' AND ', $w) . ' ORDER BY d.day, d.client_id, d.id LIMIT 2000');
        $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $total = 0.0;
        foreach ($rows as $r) {
            $total += (float)$r['amount_net'];
        }
        j(['rows' => $rows, 'total_net' => round($total, 2)]);
    }

    if ($action === 'periods') {
        $rows = $pdo->query('SELECT * FROM billing_period ORDER BY period_from DESC, id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
        j(['rows' => $rows]);
    }

    if ($action === 'period_close' && $method === 'POST') {
        j(billing_close_period($pdo, substr((string)($body['from'] ?? ''), 0, 10), substr((string)($body['to'] ?? ''), 0, 10)));
    }

    if ($action === 'period_open' && $method === 'POST') {
        j(billing_open_period($pdo, substr((string)($body['from'] ?? ''), 0, 10), substr((string)($body['to'] ?? ''), 0, 10),
            (string)($body['reason'] ?? '')));
    }

    if ($action === 'summary') {
        $from = substr((string)($_GET['from'] ?? ''), 0, 10);
        $to = substr((string)($_GET['to'] ?? ''), 0, 10);
        $w = "c.status='ACTIVE'";
        $p = [];
        if ($from !== '') {
            $w .= ' AND c.period_from>=:f';
            $p[':f'] = $from;
        }
        if ($to !== '') {
            $w .= ' AND c.period_to<=:t';
            $p[':t'] = $to;
        }
        $st = $pdo->prepare("SELECT cl.name AS client_name, s.code AS service_code, s.name AS service_name,
                g.name AS group_name, SUM(c.quantity) AS qty, SUM(c.amount_net) AS amount_net, SUM(c.amount_gross) AS amount_gross
                FROM billing_charge c
                LEFT JOIN billing_service s ON s.id=c.service_id
                LEFT JOIN billing_service_group g ON g.id=s.group_id
                LEFT JOIN billing_client cl ON cl.id=c.client_id
                WHERE $w GROUP BY cl.name, s.code, s.name, g.name ORDER BY cl.name, g.name, s.name");
        $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $total = 0.0;
        foreach ($rows as $r) {
            $total += (float)$r['amount_net'];
        }
        j(['rows' => $rows, 'total_net' => round($total, 2)]);
    }

    j(['error' => 'unknown action'], 404);
} catch (Throwable $e) {
    j(['error' => $e->getMessage()], 400);
}
