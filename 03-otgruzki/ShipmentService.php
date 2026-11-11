<?php
declare(strict_types=1);

require_once __DIR__ . '/ShipmentStatus.php';
require_once __DIR__ . '/../shared/DocNo.php';

// Р‘РёР·РЅРµСЃ-Р»РѕРіРёРєР° РґРѕРєСѓРјРµРЅС‚Р°: РЅСѓРјРµСЂР°С†РёСЏ, РїРµСЂРµСЃС‡РµС‚ СЃСѓРјРј, РџРљРњ-РґРµР№СЃС‚РІРёСЏ.
// Р’С‹РЅРµСЃРµРЅР° РёР· РєРѕРЅС‚СЂРѕР»Р»РµСЂР°, С‡С‚РѕР±С‹ СЂР°СЃС‚Рё: СЃСЋРґР° РїРѕРґРєР»СЋС‡Р°СЋС‚СЃСЏ РѕС‡РµСЂРµРґРё,
// СЂРµР·РµСЂРІРёСЂРѕРІР°РЅРёРµ РѕСЃС‚Р°С‚РєРѕРІ, РїРµС‡Р°С‚СЊ РЈРџР”/РўРўРќ, РѕС‚РїСЂР°РІРєР° Email/EDI.

final class ShipmentService
{
    public function __construct(private PDO $pdo) {}

    // РЎР»РµРґСѓСЋС‰РёР№ РЅРѕРјРµСЂ РѕС‚РіСЂСѓР·РєРё: S-205101, S-205102, ... (СЃРј. shared/DocNo.php).
    public function nextDocNo(): string
    {
        return wms_next_shipment_no($this->pdo);
    }

    /**
     * РЎРѕР·РґР°С‚СЊ С‡РµСЂРЅРѕРІРёРє РѕС‚РіСЂСѓР·РєРё РёР· РЅР°Р±РѕСЂР° РїРѕР»РµР№.
     * РћР±С‰Р°СЏ С‚РѕС‡РєР° РґР»СЏ В«+ РЎРѕР·РґР°С‚СЊВ» Рё РґР»СЏ РјР°СЃСЃРѕРІРѕРіРѕ РёРјРїРѕСЂС‚Р° РёР· Excel:
     * СЂР°РЅСЊС€Рµ РёРјРїРѕСЂС‚ Р·РІР°Р» store(), РєРѕС‚РѕСЂС‹Р№ СЃР°Рј РїРµС‡Р°С‚Р°Р» JSON, вЂ” С†РёРєР» РІС‹РґР°РІР°Р»
     * N СЃРєР»РµРµРЅРЅС‹С… JSON-РґРѕРєСѓРјРµРЅС‚РѕРІ, Р° СЃС‡С‘С‚С‡РёРє created СЂРѕСЃ РґР°Р¶Рµ РїСЂРё РѕС€РёР±РєРµ СЃС‚СЂРѕРєРё.
     * @return array{id:int,doc_no:string}
     */
    public function createShipment(array $body, ?int $userId): array
    {
        if (empty($body['contractor_id']) || empty($body['warehouse_id'])) {
            throw new RuntimeException('contractor_id Рё warehouse_id РѕР±СЏР·Р°С‚РµР»СЊРЅС‹');
        }
        // РќРѕРјРµСЂ вЂ” Р”Рћ С‚СЂР°РЅР·Р°РєС†РёРё: РЅР° MySQL LOCK TABLES РЅРµСЏРІРЅРѕ РєРѕРјРјРёС‚РёС‚ С‚СЂР°РЅР·Р°РєС†РёСЋ.
        $no = $this->nextDocNo();
        $now = wms_is_sqlite($this->pdo) ? "datetime('now')" : 'NOW()';
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare(
                "INSERT INTO shipments (doc_no,doc_date,order_id,contractor_id,contract_no,warehouse_id,
                 status,vat_rate,plan_shipment_date,plan_delivery_date,delivery_type,delivery_method,
                 responsible_id,comment,created_by,consignee,gate,vehicle,loading_type,priority,cargo_places)
                 VALUES (:no,$now,:ord,:ctr,:cn,:wh,'draft',:vat,:psd,:pdd,:dt,:dm,:resp,:cmt,:cb,
                 :csg,:gate,:veh,:lt,:pri,:cp)"
            );
            $st->execute([
                ':no' => $no, ':ord' => $body['order_id'] ?? null, ':ctr' => (int)$body['contractor_id'],
                ':cn' => $body['contract_no'] ?? null, ':wh' => (int)$body['warehouse_id'],
                ':vat' => (int)($body['vat_rate'] ?? 20), ':psd' => $body['plan_shipment_date'] ?? null,
                ':pdd' => $body['plan_delivery_date'] ?? null, ':dt' => $body['delivery_type'] ?? null,
                ':dm' => $body['delivery_method'] ?? null, ':resp' => $body['responsible_id'] ?? $userId,
                ':cmt' => $body['comment'] ?? null, ':cb' => $userId,
                ':csg' => $body['consignee'] ?? null, ':gate' => $body['gate'] ?? null,
                ':veh' => $body['vehicle'] ?? null, ':lt' => $body['loading_type'] ?? null,
                ':pri' => $body['priority'] ?? 'normal', ':cp' => (int)($body['cargo_places'] ?? 0),
            ]);
            $id = (int)$this->pdo->lastInsertId();
            $this->recalc($id);
            $this->pdo->commit();
            return ['id' => $id, 'doc_no' => $no];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // РџРµСЂРµСЃС‡РµС‚ С€Р°РїРєРё РёР· РїРѕР·РёС†РёР№: СЃСѓРјРјР°, РќР”РЎ 20%, РёС‚РѕРіРѕ, РґРѕР»Рі, SKU, С€С‚СѓРєРё. Р’С‹Р·С‹РІР°С‚СЊ РІ С‚СЂР°РЅР·Р°РєС†РёРё.
    public function recalc(int $shipmentId): void
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) c, COALESCE(SUM(qty*price),0) s, COALESCE(SUM(qty),0) q,
                    COUNT(DISTINCT sku) sk FROM shipment_items WHERE shipment_id=:id"
        );
        $st->execute([':id' => $shipmentId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $total = round((float)$r['s'], 2);
        $count = (int)$r['c'];

        $h = $this->pdo->prepare("SELECT vat_rate, paid FROM shipments WHERE id=:id");
        $h->execute([':id' => $shipmentId]);
        $head = $h->fetch(PDO::FETCH_ASSOC);
        $vatRate = (int)($head['vat_rate'] ?? 20);
        $paid = (float)($head['paid'] ?? 0);

        // Р’ РјР°РєРµС‚Рµ СЃСѓРјРјР° СѓР¶Рµ РІРєР»СЋС‡Р°РµС‚ РќР”РЎ: РІС‹РґРµР»СЏРµРј РќР”РЎ = total*rate/(100+rate).
        $vatSum = round($total * $vatRate / (100 + $vatRate), 2);
        $debt = round($total - $paid, 2);

        $u = $this->pdo->prepare(
            "UPDATE shipments SET total=:t, vat_sum=:v, total_with_vat=:t,
             items_count=:c, sku_count=:sk, qty_total=:q, debt=:d WHERE id=:id"
        );
        $u->execute([':t' => $total, ':v' => $vatSum, ':c' => $count,
            ':sk' => (int)($r['sk'] ?? 0), ':q' => (float)($r['q'] ?? 0),
            ':d' => $debt, ':id' => $shipmentId]);
    }

    // Р¤Р»Р°РіРё РґРѕРєСѓРјРµРЅС‚Р° (РіР°Р»РєРё РІ РєР°СЂС‚РѕС‡РєРµ): is_crossdock вЂ” РїСЂРёР·РЅР°Рє РєСЂРѕСЃСЃРґРѕРєР°.
    // Р¤Р»Р°Рі СЃС‚Р°РІРёС‚СЃСЏ РЅР° СѓСЂРѕРІРЅРµ РґРѕРєСѓРјРµРЅС‚Р° РѕС‚РіСЂСѓР·РєРё: РўРЎР” РїРѕ РЅРµРјСѓ РІС‹Р±РёСЂР°РµС‚
    // РІРµС‚РєСѓ Р°Р»РіРѕСЂРёС‚РјР° СЃРѕСЂС‚РёСЂРѕРІРєРё (СЃРєР°РЅРёСЂРѕРІР°РЅРёРµ РїР°Р»Р»РµС‚ РІРјРµСЃС‚Рѕ РїРѕСЃС‚СЂРѕС‡РЅРѕРіРѕ СЃРєР°РЅР°).
    public function setFlag(int $id, string $field, int $value, ?int $userId): void
    {
        $allowed = [
            'is_crossdock' => 'РљСЂРѕСЃРґРѕРє',
            'serial_scan' => 'РЎРєР°РЅРёСЂРѕРІР°РЅРёРµ СЃРµСЂРёР№РЅС‹С… РЅРѕРјРµСЂРѕРІ',
            'marking_scan' => 'РЎРєР°РЅРёСЂРѕРІР°РЅРёРµ РјР°СЂРєРёСЂРѕРІРєРё Р§Р—',
            'need_check' => 'РўСЂРµР±СѓРµС‚СЃСЏ РїСЂРѕРІРµСЂРєР°',
            'need_pack' => 'РўСЂРµР±СѓРµС‚СЃСЏ СѓРїР°РєРѕРІРєР°',
            'is_danger' => 'РћРїР°СЃРЅС‹Р№ РіСЂСѓР·',
        ];
        if (!isset($allowed[$field])) {
            throw new RuntimeException('РќРµРёР·РІРµСЃС‚РЅС‹Р№ С„Р»Р°Рі: ' . $field);
        }
        $st = $this->pdo->prepare("SELECT $field AS v FROM shipments WHERE id=:id");
        $st->execute([':id' => $id]);
        $cur = $st->fetchColumn();
        if ($cur === false) {
            throw new RuntimeException('РћС‚РіСЂСѓР·РєР° РЅРµ РЅР°Р№РґРµРЅР°');
        }
        $old = (int)$cur;
        $new = $value ? 1 : 0;
        try {
            $this->pdo->prepare("UPDATE shipments SET $field=:v WHERE id=:id")
                ->execute([':v' => $new, ':id' => $id]);
        } catch (Throwable $e) {
            throw new RuntimeException('РџРѕР»Рµ ' . $field . ' РѕС‚СЃСѓС‚СЃС‚РІСѓРµС‚ РІ Р‘Р” вЂ” РїСЂРёРјРµРЅРёС‚Рµ schema.sql');
        }
        if ($old !== $new) {
            $this->historyField($id, $userId, 'update', $allowed[$field], $old ? 'РґР°' : 'РЅРµС‚', $new ? 'РґР°' : 'РЅРµС‚');
        }
    }

    private function history(int $id, ?int $userId, string $action, ?string $old, ?string $new): void
    {
        [$fio, $pos] = $this->actor();
        $st = $this->pdo->prepare(
            "INSERT INTO shipment_history (shipment_id,user_id,user_fio,position,action,old_status,new_status) VALUES (?,?,?,?,?,?,?)"
        );
        $st->execute([$id, $userId, $fio, $pos, $action, $old, $new]);
    }

    /** Р¤РРћ Рё РґРѕР»Р¶РЅРѕСЃС‚СЊ Р·Р°Р»РѕРіРёРЅРµРЅРЅРѕРіРѕ РЅР° РїРѕСЂС‚Р°Р» СЃРѕС‚СЂСѓРґРЅРёРєР°. */
    private function actor(): array
    {
        if (function_exists('wms_auth_user')) {
            $u = wms_auth_user();
            if ($u) {
                return [(string)($u['fio'] ?? ''), (string)($u['position'] ?? '')];
            }
        }
        $fio = function_exists('wms_auth_fio') ? wms_auth_fio('') : '';
        return [$fio, ''];
    }

    /** РСЃС‚РѕСЂРёСЏ СЃ РїРѕР»РµРј/Р·РЅР°С‡РµРЅРёРµРј (РґР»СЏ С„Р»Р°РіРѕРІ РґРѕРєСѓРјРµРЅС‚Р°). */
    private function historyField(int $id, ?int $userId, string $action, string $field, ?string $old, ?string $new): void
    {
        [$fio, $pos] = $this->actor();
        $st = $this->pdo->prepare(
            "INSERT INTO shipment_history (shipment_id,user_id,user_fio,position,action,field,old_value,new_value) VALUES (?,?,?,?,?,?,?,?)"
        );
        $st->execute([$id, $userId, $fio, $pos, $action, $field, $old, $new]);
    }

    // Р—Р°РґР°РЅРёСЏ РЅР° РѕС‚Р±РѕСЂ, Р±Р»РѕРєРёСЂСѓСЋС‰РёРµ СѓРґР°Р»РµРЅРёРµ/СЂР°СЃС„РѕСЂРјРёСЂРѕРІР°РЅРёРµ РґРѕРєСѓРјРµРЅС‚Р°.
    // @return string[] РЅРѕРјРµСЂР° Р·Р°РґР°РЅРёР№
    private function pickTasksBlocking(int $shipmentId, string $docNo): array
    {
        require_once __DIR__ . '/../shared/PickIssue.php';
        $taskPdos = [$this->pdo];
        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                require_once __DIR__ . '/../shared/db.php';
                $extra = wms_tasks_pdos();
                if ($extra) {
                    $taskPdos = $extra;
                }
            }
        } catch (Throwable) {
        }
        $blocking = [];
        foreach ($taskPdos as $tPdo) {
            foreach (wms_find_pick_tasks($tPdo, [$shipmentId], [$docNo]) as $t) {
                $blocking[$t['doc_no']] = true;
            }
        }
        return array_keys($blocking);
    }

    // РџРѕР»РЅРѕРµ СѓРґР°Р»РµРЅРёРµ РґРѕРєСѓРјРµРЅС‚Р° РѕС‚РіСЂСѓР·РєРё СЃРѕ СЃРІСЏР·РєР°РјРё Рё СЃС‚СЂРѕРєР°РјРё (РЅР°СЂСЏРґС‹ РЅРµ С‚СЂРѕРіР°РµС‚).
    private function deleteShipmentRows(int $id): void
    {
        $this->pdo->beginTransaction();
        try {
            foreach (['shipment_items', 'shipment_orders', 'shipment_history',
                      'shipment_documents', 'shipment_flags', 'tsd_picks',
                      // РњР°СЂРєРё Р§Р— Рё Р¶СѓСЂРЅР°Р» СЃРѕСЂС‚РёСЂРѕРІРєРё С‚РѕР¶Рµ РїСЂРёРЅР°РґР»РµР¶Р°С‚ РѕС‚РіСЂСѓР·РєРµ:
                      // Р±РµР· РёС… СѓРґР°Р»РµРЅРёСЏ РјР°СЂРєРё В«РІРёСЃСЏС‚В» РІ СЂРµРµСЃС‚СЂРµ Рё Р±Р»РѕРєРёСЂСѓСЋС‚
                      // Р·Р°РіСЂСѓР·РєСѓ СЌС‚РѕР№ Р¶Рµ РјР°СЂРєРё РІ РґСЂСѓРіСѓСЋ РѕС‚РіСЂСѓР·РєСѓ, Р° Р¶СѓСЂРЅР°Р»
                      // СЃРѕСЂС‚РёСЂРѕРІРєРё РґР°С‘С‚ Р»РѕР¶РЅС‹Рµ В«СѓР¶Рµ РѕС‚СЃРєР°РЅРёСЂРѕРІР°РЅРѕВ».
                      'shipment_marks', 'shipment_sort_log'] as $tbl) {
                try {
                    $this->pdo->prepare("DELETE FROM $tbl WHERE shipment_id=:id")->execute([':id' => $id]);
                } catch (Throwable) {
                    // РќРµС‚ С‚Р°Р±Р»РёС†С‹/РєРѕР»РѕРЅРєРё РІ СЃС‚Р°СЂРѕР№ Р‘Р” вЂ” РїСЂРѕРїСѓСЃРєР°РµРј.
                }
            }
            $this->pdo->prepare('DELETE FROM shipments WHERE id=:id')->execute([':id' => $id]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // Р’РєР»Р°РґРєР° "РќР°СЂСЏРґС‹", РџРљРњ: СѓР±СЂР°С‚СЊ Р·Р°РєР°Р·С‹ РёР· РѕС‚РіСЂСѓР·РєРё.
    // РЈРґР°Р»СЏРµС‚ СЃРІСЏР·РєСѓ (shipment_orders) Рё СЃС‚СЂРѕРєРё СЃРѕСЃС‚Р°РІР° СЌС‚РѕРіРѕ Р·Р°РєР°Р·Р° (shipment_items.order_id),
    // Р·Р°С‚РµРј РїРµСЂРµСЃС‡РёС‚С‹РІР°РµС‚ С€Р°РїРєСѓ. РЎР°Рј РЅР°СЂСЏРґ (client_orders) РЅРµ СѓРґР°Р»СЏРµС‚СЃСЏ.
    // РћС‚РіСЂСѓР·РєР° Р±РµР· РЅР°СЂСЏРґРѕРІ СЃСѓС‰РµСЃС‚РІРѕРІР°С‚СЊ РЅРµ РјРѕР¶РµС‚: РµСЃР»Рё СѓР±СЂР°РЅ РїРѕСЃР»РµРґРЅРёР№ РЅР°СЂСЏРґ,
    // РґРѕРєСѓРјРµРЅС‚ СѓРґР°Р»СЏРµС‚СЃСЏ Р°РІС‚РѕРјР°С‚РёС‡РµСЃРєРё (РїСЂРё Р¶РёРІС‹С… Р·Р°РґР°РЅРёСЏС… РЅР° РѕС‚Р±РѕСЂ вЂ” РѕС‚РєР°Р·,
    // СЃРЅР°С‡Р°Р»Р° РџРљРњ в†’ В«РЎРЅСЏС‚СЊ СЂРµР·РµСЂРІВ»).
    public function unlinkOrders(int $shipmentId, array $orderIds, ?int $userId): array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        $orderIds = array_values(array_filter($orderIds, fn($v) => $v > 0));
        if (!$orderIds) {
            throw new RuntimeException('Р’С‹Р±РµСЂРё РЅР°СЂСЏРґС‹ (РіР°Р»РєР° РёР»Рё РџРљРњ)');
        }
        $st = $this->pdo->prepare('SELECT doc_no FROM shipments WHERE id=:id');
        $st->execute([':id' => $shipmentId]);
        $shipDoc = $st->fetchColumn();
        if (!$shipDoc) {
            throw new RuntimeException('Р”РѕРєСѓРјРµРЅС‚ РЅРµ РЅР°Р№РґРµРЅ');
        }
        $shipDoc = (string)$shipDoc;
        // РЎРЅРёРјРѕРє СЃРІСЏР·РµР№: РїРѕР№РјС‘Рј Р·Р°СЂР°РЅРµРµ, РѕРїСѓСЃС‚РµРµС‚ Р»Рё РѕС‚РіСЂСѓР·РєР° РїРѕСЃР»Рµ РѕРїРµСЂР°С†РёРё.
        $linkedBefore = null;
        try {
            $q = $this->pdo->prepare('SELECT order_id FROM shipment_orders WHERE shipment_id=:s');
            $q->execute([':s' => $shipmentId]);
            $linkedBefore = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {
        }
        if (is_array($linkedBefore) && !array_diff($linkedBefore, $orderIds)) {
            $blocking = $this->pickTasksBlocking($shipmentId, $shipDoc);
            if ($blocking) {
                throw new RuntimeException(
                    'Р­С‚Рѕ РїРѕСЃР»РµРґРЅРёР№ РЅР°СЂСЏРґ, РЅРѕ РїРѕ РѕС‚РіСЂСѓР·РєРµ РµСЃС‚СЊ Р·Р°РґР°РЅРёСЏ РЅР° РѕС‚Р±РѕСЂ (' .
                    implode(', ', $blocking) . ') вЂ” СЃРЅР°С‡Р°Р»Р° РџРљРњ в†’ В«РЎРЅСЏС‚СЊ СЂРµР·РµСЂРІВ»'
                );
            }
        }
        $unlinked = [];
        $doneIds = [];
        $errors = [];
        foreach ($orderIds as $oid) {
            try {
                $doc = null;
                try {
                    $q = $this->pdo->prepare('SELECT doc_no FROM client_orders WHERE id=:id');
                    $q->execute([':id' => $oid]);
                    $doc = $q->fetchColumn() ?: null;
                } catch (Throwable) {
                }
                $label = $doc !== null && $doc !== '' ? (string)$doc : ('#' . $oid);
                try {
                    $chk = $this->pdo->prepare(
                        'SELECT 1 FROM shipment_orders WHERE shipment_id=:s AND order_id=:o LIMIT 1'
                    );
                    $chk->execute([':s' => $shipmentId, ':o' => $oid]);
                    $linked = (bool)$chk->fetchColumn();
                } catch (Throwable) {
                    throw new RuntimeException($label . ': РЅРµС‚ СЃРІСЏР·Рё СЃ РѕС‚РіСЂСѓР·РєРѕР№');
                }
                if (!$linked) {
                    throw new RuntimeException($label . ': РЅРµ СЃРІСЏР·Р°РЅ СЃ РѕС‚РіСЂСѓР·РєРѕР№');
                }
                $this->pdo->beginTransaction();
                try {
                    $this->pdo->prepare(
                        'DELETE FROM shipment_orders WHERE shipment_id=:s AND order_id=:o'
                    )->execute([':s' => $shipmentId, ':o' => $oid]);
                    try {
                        $this->pdo->prepare(
                            'DELETE FROM shipment_items WHERE shipment_id=:s AND order_id=:o'
                        )->execute([':s' => $shipmentId, ':o' => $oid]);
                    } catch (Throwable) {
                    }
                    $this->recalc($shipmentId);
                    $this->history($shipmentId, $userId, 'unlink', $label, null);
                    $this->pdo->commit();
                } catch (Throwable $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw $e;
                }
                $unlinked[] = $label;
                $doneIds[] = $oid;
            } catch (Throwable $e) {
                $errors[] = '#' . $oid . ': ' . $e->getMessage();
            }
        }
        if (!$unlinked) {
            throw new RuntimeException(implode('; ', $errors) ?: 'РќРµС‡РµРіРѕ СѓР±РёСЂР°С‚СЊ');
        }
        // РћС‚РіСЂСѓР·РєР° Р±РµР· РЅР°СЂСЏРґРѕРІ СЃСѓС‰РµСЃС‚РІРѕРІР°С‚СЊ РЅРµ РјРѕР¶РµС‚ вЂ” СѓРґР°Р»СЏРµРј РґРѕРєСѓРјРµРЅС‚ Р°РІС‚РѕРјР°С‚РёС‡РµСЃРєРё.
        $shipmentDeleted = false;
        if (is_array($linkedBefore) && !array_diff($linkedBefore, $doneIds)) {
            $this->deleteShipmentRows($shipmentId);
            $shipmentDeleted = true;
        }
        return ['unlinked' => $unlinked, 'errors' => $errors,
                'shipment_deleted' => $shipmentDeleted, 'shipment_doc' => $shipDoc];
    }

    // РџРљРњ: РљРѕРїРёСЂРѕРІР°С‚СЊ вЂ” РЅРѕРІС‹Р№ С‡РµСЂРЅРѕРІРёРє СЃ С‚РµРјРё Р¶Рµ РїРѕР·РёС†РёСЏРјРё Рё РЅР°СЂСЏРґР°РјРё.
    public function copy(int $id, ?int $userId): int
    {
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare("SELECT * FROM shipments WHERE id=:id FOR UPDATE");
            $st->execute([':id' => $id]);
            $src = $st->fetch(PDO::FETCH_ASSOC);
            if (!$src) {
                throw new RuntimeException('Р”РѕРєСѓРјРµРЅС‚ РЅРµ РЅР°Р№РґРµРЅ');
            }
            $newNo = $this->nextDocNo();
            // NOW() РЅРµ РїРѕРЅРёРјР°РµС‚ SQLite вЂ” РёСЃРїРѕР»СЊР·СѓРµРј РїРµСЂРµРЅРѕСЃРёРјРѕРµ РІС‹СЂР°Р¶РµРЅРёРµ.
            $now = wms_is_sqlite($this->pdo) ? "datetime('now')" : 'NOW()';
            $ins = $this->pdo->prepare(
                "INSERT INTO shipments (doc_no,doc_date,order_id,contractor_id,contract_no,warehouse_id,
                 status,vat_rate,paid,plan_shipment_date,plan_delivery_date,delivery_type,delivery_method,
                 responsible_id,comment,created_by,consignee,gate,vehicle,loading_type,priority,cargo_places,volume,weight)
                 VALUES (:no,$now,:ord,:ctr,:cn,:wh,'draft',:vat,0,:psd,:pdd,:dt,:dm,
                 :resp,:cmt,:cb,:csg,:gate,:veh,:lt,:pri,:cp,:vol,:wgt)"
            );
            $ins->execute([
                ':no' => $newNo, ':ord' => $src['order_id'] ?? null, ':ctr' => $src['contractor_id'],
                ':cn' => $src['contract_no'] ?? null, ':wh' => $src['warehouse_id'], ':vat' => $src['vat_rate'],
                ':psd' => $src['plan_shipment_date'] ?? null, ':pdd' => $src['plan_delivery_date'] ?? null,
                ':dt' => $src['delivery_type'] ?? null, ':dm' => $src['delivery_method'] ?? null,
                ':resp' => $userId, ':cmt' => $src['comment'] ?? null, ':cb' => $userId,
                ':csg' => $src['consignee'] ?? null, ':gate' => $src['gate'] ?? null,
                ':veh' => $src['vehicle'] ?? null, ':lt' => $src['loading_type'] ?? null,
                ':pri' => $src['priority'] ?? 'normal', ':cp' => $src['cargo_places'] ?? 0,
                ':vol' => $src['volume'] ?? 0, ':wgt' => $src['weight'] ?? 0,
            ]);
            $newId = (int)$this->pdo->lastInsertId();
            // РџРѕР·РёС†РёРё: РєРѕРїРёСЂСѓРµРј Р’РЎР• РїРѕР»СЏ СЃС‚СЂРѕРєРё (РєРѕРґ, РµРґРёРЅРёС†Р°, РЅР°СЂСЏРґ, СЌС‚РёРєРµС‚РєР°,
            // РґР°С‚Р° РїСЂРѕРёР·РІРѕРґСЃС‚РІР°) вЂ” СЂР°РЅСЊС€Рµ С‚РµСЂСЏР»РёСЃСЊ code/unit/order_id/check_label.
            $itemCols = ['code', 'order_id', 'sku', 'name', 'unit', 'qty', 'price', 'check_label',
                'warehouse_id', 'comment', 'prod_date', 'supplier_batch'];
            $has = wms_table_columns($this->pdo, 'shipment_items');
            $cols = [];
            foreach ($itemCols as $c) {
                if (in_array($c, $has, true)) {
                    $cols[] = $c;
                }
            }
            $sumCol = wms_quote_ident($this->pdo, 'sum');
            $sel = [];
            foreach ($cols as $c) {
                $sel[] = ($c === 'prod_date') ? 'prod_date' : $c;
            }
            $this->pdo->prepare(
                "INSERT INTO shipment_items (shipment_id," . implode(',', $cols) . "," . $sumCol . ")
                 SELECT :nid," . implode(',', $sel) . ", qty*price FROM shipment_items WHERE shipment_id=:oid"
            )->execute([':nid' => $newId, ':oid' => $id]);
            // РќР°СЂСЏРґС‹ (shipment_orders): Р±РµР· РЅРёС… РѕС‚РіСЂСѓР·РєР° В«Р±РµР· РЅР°СЂСЏРґРѕРІВ»,
            // Р° РјРѕРґСѓР»СЊ СЃР°Рј СЃС‡РёС‚Р°РµС‚ С‚Р°РєРѕР№ РґРѕРєСѓРјРµРЅС‚ РЅРµРєРѕСЂСЂРµРєС‚РЅС‹Рј.
            try {
                $this->pdo->prepare(
                    "INSERT INTO shipment_orders (shipment_id,order_id,status,qty_total,collected,checked,packed,shipped,sum_novat,vat,sum_vat)
                     SELECT :nid,order_id,status,qty_total,0,0,0,0,sum_novat,vat,sum_vat
                     FROM shipment_orders WHERE shipment_id=:oid"
                )->execute([':nid' => $newId, ':oid' => $id]);
            } catch (Throwable) {
            }
            $this->recalc($newId);
            $this->history($newId, $userId, 'create', null, 'draft');
            $this->pdo->commit();
            return $newId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // РџРљРњ: РџСЂРѕРІРµСЃС‚Рё / СЃРјРµРЅР° СЃС‚Р°С‚СѓСЃР° РїРѕ Р°РІС‚РѕРјР°С‚Сѓ ShipmentStatus::canTransitTo.
    public function changeStatus(int $id, ShipmentStatus $to, ?int $userId): void
    {
        $st = $this->pdo->prepare("SELECT status, doc_no FROM shipments WHERE id=:id FOR UPDATE");
        $this->pdo->beginTransaction();
        try {
            $st->execute([':id' => $id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('Р”РѕРєСѓРјРµРЅС‚ РЅРµ РЅР°Р№РґРµРЅ');
            }
            $from = ShipmentStatus::from($row['status']);
            if (!$from->canTransitTo($to)) {
                throw new RuntimeException("РќРµР»СЊР·СЏ РїРµСЂРµРІРµСЃС‚Рё {$from->label()} в†’ {$to->label()}");
            }
            $posted = in_array($to, [ShipmentStatus::Assembled, ShipmentStatus::Delivering, ShipmentStatus::Delivered], true) ? 1 : 0;
            $extra = '';
            $params = [':s' => $to->value, ':p' => $posted, ':id' => $id];
            if ($to === ShipmentStatus::Delivered) {
                $extra = ', fact_shipment_date = COALESCE(fact_shipment_date, CURRENT_DATE), fact_delivery_date = COALESCE(fact_delivery_date, CURRENT_DATE)';
            }
            $this->pdo->prepare("UPDATE shipments SET status=:s, is_posted=:p $extra WHERE id=:id")->execute($params);
            $this->history($id, $userId, 'status', $from->value, $to->value);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        // Р–СѓСЂРЅР°Р» СЃРєР»Р°РґР° (РјРѕРґСѓР»СЊ 14): РѕС‚РіСЂСѓР·РєР° вЂ” РїР°Р»Р»РµС‚С‹ СѓС€Р»Рё СЃРѕ СЃРєР»Р°РґР°.
        if ($to === ShipmentStatus::Delivering) {
            $this->journalShipment($id, (string)($row['doc_no'] ?? ''), $userId);
        }
        // РРЅС‚РµРіСЂР°С†РёСЏ: РІРµР±С…СѓРє СЃРјРµРЅС‹ СЃС‚Р°С‚СѓСЃР° Р·Р°РєР°Р·Р° РІРѕ РІРЅРµС€РЅСЋСЋ СЃРёСЃС‚РµРјСѓ (outbox).
        try {
            require_once __DIR__ . '/../shared/IntegrationApi.php';
            wms_webhook_shipment_status($this->pdo, $id, $to->value, ['user_id' => $userId]);
        } catch (Throwable) {
        }
    }

    /**
     * Р–СѓСЂРЅР°Р» СЃРєР»Р°РґР° (РјРѕРґСѓР»СЊ 14): РѕС‚РіСЂСѓР·РєР° вЂ” СЃРѕСЃС‚Р°РІ РїР°Р»Р»РµС‚, СѓС€РµРґС€РёС… СЃРѕ СЃРєР»Р°РґР°.
     * РСЃС‚РѕС‡РЅРёРє С„Р°РєС‚Р° вЂ” СЃРѕСЂС‚РёСЂРѕРІРєР° РўРЎР” (tsd_picks): РїР°Р»Р»РµС‚Р° = SSCC.
     */
    private function journalShipment(int $id, string $docNo, ?int $userId): void
    {
        try {
            require_once __DIR__ . '/../shared/Journal.php';
            $picks = $this->pdo->prepare(
                "SELECT sku, sscc, SUM(qty) AS q FROM tsd_picks WHERE shipment_id=:s GROUP BY sku, sscc"
            );
            $picks->execute([':s' => $id]);
            $rows = $picks->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                return;
            }
            $names = [];
            try {
                $it = $this->pdo->prepare("SELECT sku, MAX(name) AS name FROM shipment_items WHERE shipment_id=:s GROUP BY sku");
                $it->execute([':s' => $id]);
                foreach ($it->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $names[(string)($r['sku'] ?? '')] = (string)($r['name'] ?? '');
                }
            } catch (Throwable) {
            }
            foreach ($rows as $r) {
                $sku = (string)($r['sku'] ?? '');
                WmsJournal::log(wms_journal_pdo(), [
                    'op_type' => 'РѕС‚РіСЂСѓР·РєР°',
                    'doc_type' => 'РћС‚РіСЂСѓР·РєР°',
                    'doc_no' => $docNo,
                    'sku' => $sku,
                    'product_name' => $names[$sku] ?? '',
                    'qty' => (float)($r['q'] ?? 0),
                    'pallet' => (string)($r['sscc'] ?? ''),
                    'sscc' => (string)($r['sscc'] ?? ''),
                    'zone' => 'РћС‚РіСЂСѓР·РєР°',
                    'user_id' => $userId,
                    'comment' => 'РћС‚РіСЂСѓР·РєР° СЃРѕ СЃРєР»Р°РґР°',
                ]);
            }
        } catch (Throwable) {
        }
    }

    // РџРљРњ: РЈРґР°Р»РёС‚СЊ (С‚РѕР»СЊРєРѕ С‡РµСЂРЅРѕРІРёРє/РѕС‚РјРµРЅРµРЅР°) / РђСЂС…РёРІРёСЂРѕРІР°С‚СЊ (С‚РѕР»СЊРєРѕ РґРѕСЃС‚Р°РІР»РµРЅР°/РѕС‚РјРµРЅРµРЅР°).
    public function delete(int $id, ?int $userId): void
    {
        $st = $this->pdo->prepare("SELECT doc_no, status FROM shipments WHERE id=:id");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Р”РѕРєСѓРјРµРЅС‚ РЅРµ РЅР°Р№РґРµРЅ');
        }
        if (!in_array($row['status'], ['draft', 'cancelled'], true)) {
            throw new RuntimeException('РЈРґР°Р»СЏС‚СЊ РјРѕР¶РЅРѕ С‚РѕР»СЊРєРѕ С‡РµСЂРЅРѕРІРёРє РёР»Рё РѕС‚РјРµРЅРµРЅРЅСѓСЋ');
        }
        $docNo = (string)($row['doc_no'] ?? '');
        // РЎРІСЏР·СЊ СЃ Р·Р°РґР°РЅРёСЏРјРё РЅР° РѕС‚Р±РѕСЂ: СѓРґР°Р»СЏС‚СЊ РѕС‚РіСЂСѓР·РєСѓ СЃ Р¶РёРІС‹РјРё Р·Р°РґР°РЅРёСЏРјРё РЅРµР»СЊР·СЏ вЂ”
        // РёРЅР°С‡Рµ РІ РўРЎР” РѕСЃС‚Р°СЋС‚СЃСЏ Р·Р°РґР°РЅРёСЏ РїРѕ РЅРµСЃСѓС‰РµСЃС‚РІСѓСЋС‰РµР№ РѕС‚РіСЂСѓР·РєРµ.
        $blocking = $this->pickTasksBlocking($id, $docNo);
        if ($blocking) {
            throw new RuntimeException(
                'РџРѕ РѕС‚РіСЂСѓР·РєРµ РµСЃС‚СЊ Р·Р°РґР°РЅРёСЏ РЅР° РѕС‚Р±РѕСЂ (' . implode(', ', $blocking) .
                ') вЂ” СЃРЅР°С‡Р°Р»Р° РџРљРњ в†’ В«РЎРЅСЏС‚СЊ СЂРµР·РµСЂРІВ»'
            );
        }
        $this->dropPickLists($id, $docNo);
        $this->deleteShipmentRows($id);
    }

    // РЎРїРёСЃРєРё РѕС‚Р±РѕСЂР° (PK-вЂ¦) РѕС‚РіСЂСѓР·РєРё вЂ” СЃРЅРѕСЃСЏС‚СЃСЏ РІРјРµСЃС‚Рµ СЃ РѕС‚РіСЂСѓР·РєРѕР№.
    private function dropPickLists(int $shipmentId, string $docNo): void
    {
        require_once __DIR__ . '/../shared/PickLists.php';
        $taskPdos = [$this->pdo];
        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                require_once __DIR__ . '/../shared/db.php';
                $extra = wms_tasks_pdos();
                if ($extra) {
                    $taskPdos = $extra;
                }
            }
        } catch (Throwable) {
        }
        foreach ($taskPdos as $tPdo) {
            try {
                $st = $tPdo->prepare('SELECT id FROM task_lists WHERE shipment_id = :s');
                $st->execute([':s' => $shipmentId]);
                $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                if ($docNo !== '') {
                    $st = $tPdo->prepare('SELECT id FROM task_lists WHERE shipment_doc_no = :d');
                    $st->execute([':d' => $docNo]);
                    $ids = array_merge($ids, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
                }
                foreach (array_unique($ids) as $lid) {
                    wms_pick_list_drop($tPdo, (int)$lid);
                }
            } catch (Throwable) {
            }
        }
    }

    // РџРљРњ: Р Р°СЃС„РѕСЂРјРёСЂРѕРІР°С‚СЊ РѕС‚РіСЂСѓР·РєСѓ вЂ” РєРѕРґ РѕС‚РіСЂСѓР·РєРё СѓРґР°Р»СЏРµС‚СЃСЏ, РЅР°СЂСЏРґС‹ РѕСЃС‚Р°СЋС‚СЃСЏ.
    // РЈРґР°Р»СЏРµС‚ РґРѕРєСѓРјРµРЅС‚, СЃРІСЏР·РєРё, СЃС‚СЂРѕРєРё СЃРѕСЃС‚Р°РІР° Рё РёСЃС‚РѕСЂРёСЋ; СЃР°РјРё Р·Р°РєР°Р·С‹ РЅРµ С‚СЂРѕРіР°РµС‚.
    // Р•СЃР»Рё РїРѕ РѕС‚РіСЂСѓР·РєРµ РІС‹РїСѓС‰РµРЅС‹ Р·Р°РґР°РЅРёСЏ РЅР° РѕС‚Р±РѕСЂ вЂ” СЃРЅР°С‡Р°Р»Р° С‚СЂРµР±СѓРµРј "РЎРЅСЏС‚СЊ СЂРµР·РµСЂРІ".
    public function disband(int $id, ?int $userId): array
    {
        $st = $this->pdo->prepare('SELECT doc_no FROM shipments WHERE id=:id');
        $st->execute([':id' => $id]);
        $head = $st->fetch(PDO::FETCH_ASSOC);
        if (!$head) {
            throw new RuntimeException('Р”РѕРєСѓРјРµРЅС‚ РЅРµ РЅР°Р№РґРµРЅ');
        }
        $docNo = (string)$head['doc_no'];
        $blocking = $this->pickTasksBlocking($id, $docNo);
        if ($blocking) {
            throw new RuntimeException(
                'РџРѕ РѕС‚РіСЂСѓР·РєРµ РµСЃС‚СЊ Р·Р°РґР°РЅРёСЏ РЅР° РѕС‚Р±РѕСЂ (' . implode(', ', $blocking) .
                ') вЂ” СЃРЅР°С‡Р°Р»Р° РџРљРњ в†’ В«РЎРЅСЏС‚СЊ СЂРµР·РµСЂРІВ»'
            );
        }
        $this->dropPickLists($id, $docNo);
        $this->deleteShipmentRows($id);
        return ['doc_no' => $docNo];
    }

    public function archive(int $id, ?int $userId): void
    {
        $this->changeStatus($id, ShipmentStatus::Archived, $userId);
    }

    // РџРљРњ: РџСЂРёРѕСЂРёС‚РµС‚ > РџРѕРІС‹СЃРёС‚СЊ / РџРѕРЅРёР·РёС‚СЊ
    public function priority(int $id, string $priority, ?int $userId): void
    {
        if (!in_array($priority, ['low', 'normal', 'high'], true)) {
            throw new RuntimeException('РќРµРІРµСЂРЅС‹Р№ РїСЂРёРѕСЂРёС‚РµС‚');
        }
        try {
            $this->pdo->prepare("UPDATE shipments SET priority=:p WHERE id=:id")
                ->execute([':p' => $priority, ':id' => $id]);
            $this->history($id, $userId, 'update', null, null);
        } catch (Throwable $e) {
            throw new RuntimeException('РџРѕР»Рµ priority РѕС‚СЃСѓС‚СЃС‚РІСѓРµС‚ РІ Р‘Р” вЂ” РїСЂРёРјРµРЅРёС‚Рµ schema.sql');
        }
    }

    // РџРљРњ: Р’С‹РїСѓСЃРє Р·Р°РґР°РЅРёР№ РЅР° РѕС‚Р±РѕСЂ (РІРѕР»РЅРѕР№ / РїРѕСЃС‚СЂРѕС‡РЅРѕ).
    // Р’РѕР»РЅР°: РѕРґРёРЅ SKU = РѕРґРЅРѕ РѕР±СЉРµРґРёРЅС‘РЅРЅРѕРµ Р·Р°РґР°РЅРёРµ; РІРѕСЂРѕС‚Р° Сѓ РѕС‚РіСЂСѓР·РѕРє РґРѕР»Р¶РЅС‹ СЃРѕРІРїР°РґР°С‚СЊ.
    // РџРѕСЃС‚СЂРѕС‡РЅРѕ: РєР°Р¶РґР°СЏ СЃС‚СЂРѕРєР° shipment_items = РѕС‚РґРµР»СЊРЅРѕРµ Р·Р°РґР°РЅРёРµ.
    public function issuePickTasks(array $shipmentIds, string $mode, ?int $userId): array
    {
        require_once __DIR__ . '/../shared/PickIssue.php';
        // РџСЂРѕРґ MySQL: Р·Р°РґР°РЅРёСЏ Рё РѕС‚РіСЂСѓР·РєРё РІ РѕРґРЅРѕР№ Р‘Р” `wms` вЂ” РїРёС€РµРј РІ С‚РѕС‚ Р¶Рµ PDO.
        // Р”РµРјРѕ SQLite: tasks Р¶РёРІСѓС‚ РѕС‚РґРµР»СЊРЅРѕ вЂ” РїРѕРґС…РІР°С‚С‹РІР°РµРј РѕР±Р° С„Р°Р№Р»Р° Р·Р°РґР°РЅРёР№.
        $taskPdos = [$this->pdo];
        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                require_once __DIR__ . '/../shared/db.php';
                $extra = wms_tasks_pdos();
                if ($extra) {
                    $taskPdos = $extra;
                }
            }
        } catch (Throwable) {
        }
        return wms_issue_pick_tasks($this->pdo, $taskPdos, $shipmentIds, $mode, $userId);
    }

    // РџРљРњ: РЎРЅСЏС‚СЊ СЂРµР·РµСЂРІ вЂ” СѓРґР°Р»СЏРµС‚ Р’РЎР• Р·Р°РґР°РЅРёСЏ РЅР° РѕС‚Р±РѕСЂ, СЃРѕР·РґР°РЅРЅС‹Рµ РїРѕ РґРѕРєСѓРјРµРЅС‚Р°Рј.
    public function releasePickTasks(array $shipmentIds, ?int $userId): array
    {
        require_once __DIR__ . '/../shared/PickIssue.php';
        $taskPdos = [$this->pdo];
        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                require_once __DIR__ . '/../shared/db.php';
                $extra = wms_tasks_pdos();
                if ($extra) {
                    $taskPdos = $extra;
                }
            }
        } catch (Throwable) {
        }
        return wms_release_pick_tasks($this->pdo, $taskPdos, $shipmentIds, $userId);
    }

    // Р’РєР»Р°РґРєР° "Р—Р°РґР°РЅРёСЏ" РєР°СЂС‚РѕС‡РєРё: Р·Р°РґР°РЅРёСЏ РЅР° РѕС‚Р±РѕСЂ, СЃРѕР·РґР°РЅРЅС‹Рµ РїРѕ РѕС‚РіСЂСѓР·РєРµ.
    public function pickTasksForShipment(int $shipmentId): array
    {
        require_once __DIR__ . '/../shared/PickIssue.php';
        $st = $this->pdo->prepare('SELECT id, doc_no FROM shipments WHERE id=:id');
        $st->execute([':id' => $shipmentId]);
        $head = $st->fetch(PDO::FETCH_ASSOC);
        if (!$head) {
            throw new RuntimeException('Р”РѕРєСѓРјРµРЅС‚ РЅРµ РЅР°Р№РґРµРЅ');
        }
        $taskPdos = [$this->pdo];
        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                require_once __DIR__ . '/../shared/db.php';
                $extra = wms_tasks_pdos();
                if ($extra) {
                    // Р”РµС‚Р°Р»РёР·Р°С†РёСЋ Р±РµСЂС‘Рј РёР· primary, С‡С‚РѕР±С‹ РЅРµ РґСѓР±Р»РёСЂРѕРІР°С‚СЊ.
                    return wms_pick_tasks_detail($extra[0], [$shipmentId], [(string)$head['doc_no']]);
                }
            }
        } catch (Throwable) {
        }
        return wms_pick_tasks_detail($taskPdos[0], [$shipmentId], [(string)$head['doc_no']]);
    }

    // РџРљРњ: Р—Р°РїСѓСЃС‚РёС‚СЊ СЃР±РѕСЂРєСѓ / РџРѕРґРіРѕС‚РѕРІРёС‚СЊ Р°РІС‚РѕРјР°С‚РёС‡РµСЃРєРё (Р’РѕР»РЅР°)
    public function startPicking(int $id, ?int $userId, string $mode = 'wave'): void
    {
        $st = $this->pdo->prepare("SELECT status FROM shipments WHERE id=:id");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Р”РѕРєСѓРјРµРЅС‚ РЅРµ РЅР°Р№РґРµРЅ');
        }
        try {
            $this->pdo->prepare(
                "UPDATE shipments SET launched_pick=:m, assembly_start=COALESCE(assembly_start, CURRENT_TIMESTAMP) WHERE id=:id"
            )->execute([':m' => $mode === 'auto' ? 'РђРІС‚Рѕ' : 'Р’РѕР»РЅР°', ':id' => $id]);
        } catch (Throwable $e) {
            // СЃС‚Р°СЂС‹Рµ Р‘Р” Р±РµР· РєРѕР»РѕРЅРѕРє вЂ” С‚РѕР»СЊРєРѕ РёСЃС‚РѕСЂРёСЏ
        }
        $this->history($id, $userId, 'picking', $row['status'], $row['status']);
    }

    /**
     * РњР°СЃС‚РµСЂ "Р¤РѕСЂРјРёСЂРѕРІР°РЅРёРµ РѕС‚РіСЂСѓР·РєРё", С€Р°Рі 2: СЃРѕР·РґР°С‚СЊ РґРѕРєСѓРјРµРЅС‚ РёР· N Р·Р°РєР°Р·РѕРІ.
     * $params: contractor_id, warehouse_id, plan_shipment_date, plan_time,
     *          vehicle, gate, loading_type, delivery_type, priority, consignee...
     * Р’РѕР·РІСЂР°С‰Р°РµС‚ id РЅРѕРІРѕР№ РѕС‚РіСЂСѓР·РєРё. РЎРѕСЃС‚Р°РІ Рё РёС‚РѕРіРё СЃС‡РёС‚Р°СЋС‚СЃСЏ РёР· Р·Р°РєР°Р·РѕРІ.
     */
    // РџРµСЂРµРЅРѕСЃРёРјС‹Р№ РЅРѕРјРµСЂ РґР»СЏ SQLite-РґРµРјРѕ (Р±РµР· LOCK TABLES) вЂ” С‚Р° Р¶Рµ СЃРµСЂРёСЏ S-205101...
    private function nextDocNoPortable(): string
    {
        return wms_next_shipment_no($this->pdo);
    }

    public function formFromOrders(array $orderIds, array $params, ?int $userId): int
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        if (!$orderIds) {
            throw new RuntimeException('Р’С‹Р±РµСЂРёС‚Рµ Р·Р°РєР°Р·С‹ (С€Р°Рі 1 РјР°СЃС‚РµСЂР°)');
        }
        if (empty($params['warehouse_id'])) {
            throw new RuntimeException('РЈРєР°Р¶РёС‚Рµ СЃРєР»Р°Рґ (С€Р°Рі 2 РјР°СЃС‚РµСЂР°)');
        }
        // РћРґРёРЅ РЅР°СЂСЏРґ вЂ” С‚РѕР»СЊРєРѕ РІ РѕРґРЅСѓ РѕС‚РіСЂСѓР·РєСѓ: СѓР¶Рµ СЃРІСЏР·Р°РЅРЅС‹Рµ Р·Р°РєР°Р·С‹ РѕС‚РєР»РѕРЅСЏРµРј.
        try {
            $in = implode(',', $orderIds);
            $busy = $this->pdo->query(
                "SELECT o.doc_no AS order_no, s.doc_no AS ship_no
                 FROM shipment_orders so
                 JOIN shipments s ON s.id = so.shipment_id
                 JOIN client_orders o ON o.id = so.order_id
                 WHERE so.order_id IN ($in)"
            )->fetchAll(PDO::FETCH_ASSOC);
            if ($busy) {
                $names = [];
                foreach ($busy as $b) {
                    $names[] = $b['order_no'] . ' (СѓР¶Рµ РІ ' . $b['ship_no'] . ')';
                }
                throw new RuntimeException('РќР°СЂСЏРґС‹ СѓР¶Рµ РґРѕР±Р°РІР»РµРЅС‹ РІ РґСЂСѓРіРёРµ РѕС‚РіСЂСѓР·РєРё: ' . implode(', ', $names));
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable) {
            // РќРµС‚ С‚Р°Р±Р»РёС†С‹ СЃРІСЏР·РµР№ вЂ” РїСЂРѕРІРµСЂРєР° РЅРµРІРѕР·РјРѕР¶РЅР°, РёРґС‘Рј РґР°Р»СЊС€Рµ.
        }
        $isSqlite = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $nowFn = $isSqlite ? "datetime('now')" : 'NOW()';
        $this->pdo->beginTransaction();
        try {
            $no = $isSqlite ? $this->nextDocNoPortable() : $this->nextDocNo();
            $cols = "doc_no,doc_date,contractor_id,warehouse_id,status,plan_shipment_date,
                     vehicle,gate,loading_type,delivery_type,priority,consignee,
                     responsible_id,comment,created_by";
            $st = $this->pdo->prepare(
                "INSERT INTO shipments ($cols) VALUES (:no,$nowFn,:ctr,:wh,'assembling',:psd,:veh,:gate,:lt,:dt,:pri,:csg,:resp,:cmt,:cb)"
            );
            // РљРѕРЅС‚СЂР°РіРµРЅС‚ вЂ” РїРѕ РїРµСЂРІРѕРјСѓ Р·Р°РєР°Р·Сѓ, РµСЃР»Рё РЅРµ Р·Р°РґР°РЅ СЏРІРЅРѕ
            $ctr = (int)($params['contractor_id'] ?? 0);
            if (!$ctr) {
                try {
                    $q = $this->pdo->prepare("SELECT contractor_id FROM client_orders WHERE id=:id");
                    $q->execute([':id' => $orderIds[0]]);
                    $ctr = (int)$q->fetchColumn();
                } catch (Throwable $e) {
                }
            }
            $st->execute([
                ':no' => $no, ':ctr' => $ctr ?: 1, ':wh' => (int)$params['warehouse_id'],
                ':psd' => $params['plan_shipment_date'] ?? null,
                ':veh' => $params['vehicle'] ?? null, ':gate' => $params['gate'] ?? null,
                ':lt' => $params['loading_type'] ?? null, ':dt' => $params['delivery_type'] ?? 'Р”РѕСЃС‚Р°РІРєР°',
                ':pri' => $params['priority'] ?? 'normal', ':csg' => $params['consignee'] ?? null,
                ':resp' => $userId, ':cmt' => $params['comment'] ?? null, ':cb' => $userId,
            ]);
            $newId = (int)$this->pdo->lastInsertId();
            // РЎРІСЏР·РєР° Р·Р°РєР°Р·РѕРІ (shipment_orders) вЂ” best effort, РµСЃР»Рё С‚Р°Р±Р»РёС†С‹ РЅРµС‚ вЂ” РїСЂРѕРїСѓСЃРєР°РµРј
            try {
                $ins = $this->pdo->prepare(
                    "INSERT INTO shipment_orders (shipment_id, order_id) VALUES (:s,:o)"
                );
                foreach ($orderIds as $oid) {
                    $ins->execute([':s' => $newId, ':o' => $oid]);
                }
            } catch (Throwable $e) {
            }
            // РЎРѕСЃС‚Р°РІ РёР· Р·Р°РєР°Р·РѕРІ: СЃС‚СЂРѕРєРё РЅР°СЂСЏРґРѕРІ (client_order_items) -> РїРѕР·РёС†РёРё РѕС‚РіСЂСѓР·РєРё.
            // РќР°Р»РёС‡РёРµ РєРѕР»РѕРЅРєРё shipment_items.prod_date РїСЂРѕРІРµСЂСЏРµРј РћР”РРќ СЂР°Р· РґРѕ С†РёРєР»Р°:
            // РїСЂРё С‡Р°СЃС‚РёС‡РЅРѕРј СЃР±РѕРµ РїСЂРµР¶РЅРёР№ fallback (РІСЃС‚Р°РІРєР° Р±РµР· prod_date) РїСЂРѕРґСѓР±Р»РёСЂРѕРІР°Р»
            // СѓР¶Рµ РІСЃС‚Р°РІР»РµРЅРЅС‹Рµ СЃС‚СЂРѕРєРё РЅР°СЂСЏРґР°.
            $hasProdDate = function_exists('wms_has_column')
                ? wms_has_column($this->pdo, 'shipment_items', 'prod_date') : true;
            $hasBatch = function_exists('wms_has_column')
                ? (wms_has_column($this->pdo, 'shipment_items', 'supplier_batch')
                    && wms_has_column($this->pdo, 'client_order_items', 'supplier_batch')) : false;
            try {
                $ins = $this->pdo->prepare(
                    "INSERT INTO shipment_items (shipment_id,order_id,sku,name,unit,qty,price," . wms_quote_ident($this->pdo, 'sum') . ($hasProdDate ? ',prod_date' : '') . ($hasBatch ? ',supplier_batch' : '') . ")
                     SELECT :s, order_id, sku, name, unit, qty, 0, 0" . ($hasProdDate ? ', prod_date' : '') . ($hasBatch ? ', supplier_batch' : '') . "
                     FROM client_order_items WHERE order_id=:o"
                );
                foreach ($orderIds as $oid) {
                    $ins->execute([':s' => $newId, ':o' => $oid]);
                }
            } catch (Throwable $e) {
            }
            $this->recalc($newId);
            $this->history($newId, $userId, 'create', null, 'assembling');
            $this->pdo->commit();
            return $newId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // Р”Р°РЅРЅС‹Рµ РґР»СЏ РїРµС‡Р°С‚Рё РїРѕ РјР°РєРµС‚Сѓ: РђРєС‚, РњРҐ3, РЎР±РѕСЂРѕС‡РЅС‹Р№/РЈРїР°РєРѕРІРѕС‡РЅС‹Р№ Р»РёСЃС‚, РЁРљ, РџР°Р»Р»РµС‚РЅС‹Р№, Р РµРµСЃС‚СЂ,
    // РќР°СЂСЏРґ, РЈРџР”, РўРќ, РўРўРќ.
    public function printData(int $id, string $kind, string $sscc = ''): array
    {
        if ($kind === 'pick_list' || $kind === 'p-sb') {
            return $this->pickDoc($id);
        }
        if ($kind === 'pack_list' || $kind === 'p-up') {
            return $this->packDoc($id, $sscc);
        }
        if ($kind === 'nar' || $kind === 'p-nar') {
            return $this->narDoc($id);
        }
        if ($kind === 'summary' || $kind === 'p-summary') {
            return $this->summaryDoc($id);
        }
        if ($kind === 'upd' || $kind === 'p-upd') {
            return $this->updDoc($id);
        }
        if ($kind === 'tn' || $kind === 'p-tn') {
            return $this->tnDoc($id);
        }
        if ($kind === 'ttn' || $kind === 'p-ttn') {
            return $this->ttnDoc($id);
        }
        $st = $this->pdo->prepare("SELECT * FROM shipments WHERE id=:id");
        $st->execute([':id' => $id]);
        $head = $st->fetch(PDO::FETCH_ASSOC);
        if (!$head) {
            throw new RuntimeException('Р”РѕРєСѓРјРµРЅС‚ РЅРµ РЅР°Р№РґРµРЅ');
        }
        $items = $this->pdo->prepare("SELECT * FROM shipment_items WHERE shipment_id=:id ORDER BY id");
        $items->execute([':id' => $id]);
        return ['kind' => $kind, 'head' => $head, 'items' => $items->fetchAll(PDO::FETCH_ASSOC)];
    }

    /**
     * РќР°СЂСЏРґ (Р±РµР· С†РµРЅ): РІСЃРµ РЅР°СЂСЏРґС‹ (Р·Р°РєР°Р·С‹) РѕС‚РіСЂСѓР·РєРё СЃРѕ С€С‚СЂРёС…РєРѕРґР°РјРё РґРѕРєСѓРјРµРЅС‚Р° Рё РєР°Р¶РґРѕРіРѕ РЅР°СЂСЏРґР°.
     * Р’ С€Р°РїРєРµ: РєР»РёРµРЅС‚, Р°РґСЂРµСЃ РґРѕСЃС‚Р°РІРєРё, РІРѕСЂРѕС‚Р° РѕС‚РіСЂСѓР·РєРё. РўР°Р±Р»РёС†Р°: Рї/Рї, Р°СЂС‚РёРєСѓР», РЅР°РёРјРµРЅРѕРІР°РЅРёРµ, РєРѕР»-РІРѕ.
     * Р”СЂСѓРі Р·Р° РґСЂСѓРіРѕРј: СЃРЅР°С‡Р°Р»Р° РѕС‚РіСЂСѓР·РєР°, РїРѕС‚РѕРј РєР°Р¶РґС‹Р№ РЅР°СЂСЏРґ.
     */
    /**
     * РћР±СЉС‘Рј Рё РІРµСЃ РµРґРёРЅРёС†С‹ С‚РѕРІР°СЂР° РёР· РЅРѕРјРµРЅРєР»Р°С‚СѓСЂС‹: sku => [volume_m3, weight_kg].
     * Р•СЃР»Рё РѕР±СЉС‘Рј РЅРµ Р·Р°РїРѕР»РЅРµРЅ вЂ” СЃС‡РёС‚Р°РµРј РїРѕ РіР°Р±Р°СЂРёС‚Р°Рј, РІРµСЃ вЂ” РёР· weight_g.
     */
    private function productMetrics(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $skus)), fn($s) => $s !== '')));
        if (!$skus) {
            return [];
        }
        $out = [];
        try {
            require_once __DIR__ . '/../shared/db.php';
            $pdo = wms_nomenclature_pdo();
            $cols = wms_table_columns($pdo, 'products');
            $hasVol = in_array('volume_m3', $cols, true);
            $hasW = in_array('weight_g', $cols, true);
            $hasDims = in_array('length_mm', $cols, true) && in_array('width_mm', $cols, true) && in_array('height_mm', $cols, true);
            $sel = 'sku' . ($hasVol ? ', volume_m3' : '') . ($hasW ? ', weight_g' : '')
                . ($hasDims ? ', length_mm, width_mm, height_mm' : '');
            $ph = implode(',', array_fill(0, count($skus), '?'));
            $st = $pdo->prepare("SELECT $sel FROM products WHERE sku IN ($ph)");
            $st->execute($skus);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $vol = $hasVol ? (float)($r['volume_m3'] ?? 0) : 0.0;
                if ($vol <= 0 && $hasDims) {
                    $vol = ((float)($r['length_mm'] ?? 0) / 1000)
                        * ((float)($r['width_mm'] ?? 0) / 1000)
                        * ((float)($r['height_mm'] ?? 0) / 1000);
                }
                $wt = $hasW ? (float)($r['weight_g'] ?? 0) / 1000.0 : 0.0;
                $out[(string)$r['sku']] = ['volume_m3' => round($vol, 6), 'weight_kg' => round($wt, 4)];
            }
        } catch (Throwable) {
        }
        return $out;
    }

    /** Р”РѕРїРѕР»РЅРёС‚СЊ СЃС‚СЂРѕРєРё РѕР±СЉС‘РјРѕРј/РІРµСЃРѕРј РїРѕ РєРѕР»РёС‡РµСЃС‚РІСѓ Рё РїРѕСЃС‡РёС‚Р°С‚СЊ РёС‚РѕРіРё. */
    private function applyItemMetrics(array $items): array
    {
        $metrics = $this->productMetrics(array_column($items, 'sku'));
        $qty = 0.0;
        $vol = 0.0;
        $wt = 0.0;
        foreach ($items as &$it) {
            $q = (float)($it['qty'] ?? 0);
            $m = $metrics[(string)($it['sku'] ?? '')] ?? ['volume_m3' => 0.0, 'weight_kg' => 0.0];
            $it['volume'] = round($q * (float)$m['volume_m3'], 6);
            $it['weight'] = round($q * (float)$m['weight_kg'], 3);
            $qty += $q;
            $vol += (float)$it['volume'];
            $wt += (float)$it['weight'];
        }
        unset($it);
        return ['items' => $items, 'qty' => round($qty, 3), 'volume' => round($vol, 6), 'weight' => round($wt, 3)];
    }

    public function narDoc(int $id): array
    {
        $st = $this->pdo->prepare("SELECT * FROM shipments WHERE id=:id");
        $st->execute([':id' => $id]);
        $head = $st->fetch(PDO::FETCH_ASSOC);
        if (!$head) {
            throw new RuntimeException('Р”РѕРєСѓРјРµРЅС‚ РЅРµ РЅР°Р№РґРµРЅ');
        }

        // Р’СЃРµ РЅР°СЂСЏРґС‹ (Р·Р°РєР°Р·С‹) РІ СЌС‚РѕР№ РѕС‚РіСЂСѓР·РєРµ.
        $orders = $this->pdo->prepare(
            "SELECT o.id, o.doc_no, o.created_at AS doc_date, o.consignee, o.address, o.phone, o.status,
                    o.total, o.warehouse_id
             FROM shipment_orders so
             JOIN client_orders o ON o.id = so.order_id
             WHERE so.shipment_id = :id
             ORDER BY o.doc_no"
        );
        $orders->execute([':id' => $id]);
        $orderList = $orders->fetchAll(PDO::FETCH_ASSOC);

        // РћР±С‰РёРµ РёС‚РѕРіРё РѕС‚РіСЂСѓР·РєРё вЂ” РІСЃРµРіРґР° РїРѕ С„Р°РєС‚РёС‡РµСЃРєРёРј СЃС‚СЂРѕРєР°Рј shipment_items.
        $allItems = $this->pdo->prepare(
            "SELECT sku, name, unit, qty FROM shipment_items WHERE shipment_id=:id ORDER BY id"
        );
        $allItems->execute([':id' => $id]);
        $allRows = $allItems->fetchAll(PDO::FETCH_ASSOC);
        $allCalc = $this->applyItemMetrics($allRows);

        $orderDocs = [];
        foreach ($orderList as $order) {
            // РЎРѕСЃС‚Р°РІ РЅР°СЂСЏРґР° Р±РµСЂС‘Рј РёР· С„Р°РєС‚РёС‡РµСЃРєРёС… СЃС‚СЂРѕРє РѕС‚РіСЂСѓР·РєРё (shipment_items):
            // client_order_items РјРѕР¶РµС‚ Р±С‹С‚СЊ РїСѓСЃС‚С‹Рј, РµСЃР»Рё РѕС‚РіСЂСѓР·РєР° СЃРѕР±СЂР°РЅР° РёР· РґСЂСѓРіРѕРіРѕ РёСЃС‚РѕС‡РЅРёРєР°.
            $items = $this->pdo->prepare(
                "SELECT sku, name, unit, qty FROM shipment_items
                  WHERE shipment_id = :sid AND order_id = :oid ORDER BY id"
            );
            $items->execute([':sid' => $id, ':oid' => $order['id']]);
            $rows = $items->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                $fallback = $this->pdo->prepare(
                    "SELECT sku, name, unit, qty FROM client_order_items WHERE order_id = :oid ORDER BY id"
                );
                $fallback->execute([':oid' => $order['id']]);
                $rows = $fallback->fetchAll(PDO::FETCH_ASSOC);
            }
            // Р’ РѕС‚РіСЂСѓР·РєРµ РѕРґРёРЅ РЅР°СЂСЏРґ Рё СЃС‚СЂРѕРєРё Р±РµР· order_id вЂ” РІРµСЃСЊ СЃРѕСЃС‚Р°РІ РѕС‚РЅРѕСЃРёС‚СЃСЏ Рє РЅРµРјСѓ.
            if (!$rows && count($orderList) === 1) {
                $rows = $allRows;
            }
            $calc = $this->applyItemMetrics($rows);
            $order['items'] = $calc['items'];
            $order['qty_total'] = $calc['qty'];
            $order['volume_total'] = $calc['volume'];
            $order['weight_total'] = $calc['weight'];
            $orderDocs[] = $order;
        }
        $head['qty_total'] = $allCalc['qty'];
        $head['volume_total'] = $allCalc['volume'];
        $head['weight_total'] = $allCalc['weight'];

        return [
            'kind' => 'nar', 'title' => 'РџРѕ РѕС‚РіСЂСѓР·РєРµ ' . $head['doc_no'],
            'head' => $head, 'orders' => $orderDocs,
        ];
    }

    /**
     * РЎРІРѕРґРЅС‹Р№ РґРѕРєСѓРјРµРЅС‚ РїРѕ РѕС‚РіСЂСѓР·РєРµ: РѕРґРёРЅР°РєРѕРІС‹Рµ SKU РёР· СЂР°Р·РЅС‹С… РЅР°СЂСЏРґРѕРІ
     * РѕР±СЉРµРґРёРЅСЏСЋС‚СЃСЏ РІ РѕРґРЅСѓ СЃС‚СЂРѕРєСѓ, РєРѕР»РёС‡РµСЃС‚РІРѕ/РѕР±СЉС‘Рј/РІРµСЃ СЃСѓРјРјРёСЂСѓСЋС‚СЃСЏ.
     */
    public function summaryDoc(int $id): array
    {
        $st = $this->pdo->prepare("SELECT * FROM shipments WHERE id=:id");
        $st->execute([':id' => $id]);
        $head = $st->fetch(PDO::FETCH_ASSOC);
        if (!$head) {
            throw new RuntimeException('РћС‚РіСЂСѓР·РєР° РЅРµ РЅР°Р№РґРµРЅР°');
        }
        $items = $this->pdo->prepare(
            "SELECT code, sku, name, unit, SUM(qty) AS qty
             FROM shipment_items WHERE shipment_id=:id
             GROUP BY code, sku, name, unit ORDER BY MIN(id)"
        );
        $items->execute([':id' => $id]);
        $calc = $this->applyItemMetrics($items->fetchAll(PDO::FETCH_ASSOC));
        $head['qty_total'] = $calc['qty'];
        $head['volume_total'] = $calc['volume'];
        $head['weight_total'] = $calc['weight'];
        return ['kind' => 'summary', 'title' => 'РЎРІРѕРґРЅС‹Р№',
            'head' => $head, 'items' => $calc['items']];
    }

    /**
     * РЈРџР” вЂ” РЈРЅРёРІРµСЂСЃР°Р»СЊРЅС‹Р№ РїРµСЂРµРґР°С‚РѕС‡РЅС‹Р№ РґРѕРєСѓРјРµРЅС‚ (С„РѕСЂРјР° РїРѕ РїСЂРёРєР°Р·Сѓ Р¤РќРЎ Р РѕСЃСЃРёРё в„– РњРњР’-7-15/82@).
     * Р‘РµР· С†РµРЅ РІ СЃС‚СЂРѕРєРѕРІРѕР№ С‡Р°СЃС‚Рё (С‚РѕР»СЊРєРѕ РєРѕР»-РІРѕ, Р°СЂС‚РёРєСѓР», РЅР°РёРјРµРЅРѕРІР°РЅРёРµ), С†РµРЅС‹ вЂ” РѕРїС†РёРѕРЅР°Р»СЊРЅРѕ РІ С€Р°РїРєРµ.
     */
    public function updDoc(int $id): array
    {
        return $this->primaryDoc($id, 'upd', 'РЈРџР” вЂ” РЈРЅРёРІРµСЂСЃР°Р»СЊРЅС‹Р№ РїРµСЂРµРґР°С‚РѕС‡РЅС‹Р№ РґРѕРєСѓРјРµРЅС‚');
    }

    /**
     * РўРќ вЂ” РўРѕСЂРіРѕРІР°СЏ РЅР°РєР»Р°РґРЅР°СЏ (С„РѕСЂРјР° РўРћР“-1).
     */
    public function tnDoc(int $id): array
    {
        return $this->primaryDoc($id, 'tn', 'РўРѕСЂРіРѕРІР°СЏ РЅР°РєР»Р°РґРЅР°СЏ');
    }

    /**
     * РўРўРќ вЂ” РўСЂР°РЅСЃРїРѕСЂС‚РЅР°СЏ РЅР°РєР»Р°РґРЅР°СЏ (С„РѕСЂРјР° РїРѕ РїСЂРёРєР°Р·Сѓ РњРёРЅС‚СЂР°РЅСЃР° Р РѕСЃСЃРёРё в„– 272).
     */
    public function ttnDoc(int $id): array
    {
        return $this->primaryDoc($id, 'ttn', 'РўСЂР°РЅСЃРїРѕСЂС‚РЅР°СЏ РЅР°РєР»Р°РґРЅР°СЏ');
    }

    /**
     * РћР±С‰Р°СЏ РїРѕРґРіРѕС‚РѕРІРєР° РґР°РЅРЅС‹С… РґР»СЏ РїРµСЂРІРёС‡РЅС‹С… РґРѕРєСѓРјРµРЅС‚РѕРІ (РЈРџР”, РўРќ, РўРўРќ).
     * Р’РѕР·РІСЂР°С‰Р°РµС‚ РіРѕР»РѕРІСѓ + РїРѕР·РёС†РёРё РѕС‚РіСЂСѓР·РєРё Р±РµР· С†РµРЅ (С‚РѕРІР°СЂРЅР°СЏ РЅР°РєР»Р°РґРЅР°СЏ С‡Р°СЃС‚СЊ).
     */
    private function primaryDoc(int $id, string $kind, string $title): array
    {
        $st = $this->pdo->prepare(
            "SELECT s.*, c.name AS contractor, w.name AS warehouse
             FROM shipments s
             LEFT JOIN contractors c ON c.id = s.contractor_id
             LEFT JOIN warehouses w ON w.id = s.warehouse_id
             WHERE s.id=:id"
        );
        $st->execute([':id' => $id]);
        $head = $st->fetch(PDO::FETCH_ASSOC);
        if (!$head) {
            throw new RuntimeException('Р”РѕРєСѓРјРµРЅС‚ РЅРµ РЅР°Р№РґРµРЅ');
        }
        // РџРѕР·РёС†РёРё СЃ С†РµРЅР°РјРё вЂ” РґР»СЏ РЈРџР”/РўРќ/РўРўРќ; РѕР±СЉС‘Рј Рё РІРµСЃ СЃС‡РёС‚Р°РµРј РёР· РЅРѕРјРµРЅРєР»Р°С‚СѓСЂС‹.
        $items = $this->pdo->prepare(
            "SELECT code, sku, name, unit, qty, price, sum FROM shipment_items
             WHERE shipment_id=:id ORDER BY id"
        );
        $items->execute([':id' => $id]);
        $calc = $this->applyItemMetrics($items->fetchAll(PDO::FETCH_ASSOC));
        $head['qty_total'] = $calc['qty'];
        $head['volume_total'] = $calc['volume'];
        $head['weight_total'] = $calc['weight'];
        return ['kind' => $kind, 'title' => $title, 'head' => $head, 'items' => $calc['items']];
    }

    /**
     * РЎР±РѕСЂРѕС‡РЅС‹Р№ Р»РёСЃС‚: СЃРѕСЃС‚Р°РІ РѕС‚РіСЂСѓР·РєРё (РїР»Р°РЅ/С„Р°РєС‚ РѕС‚Р±РѕСЂР° С‡РµСЂРµР· РўРЎР”).
     * Р’ С€С‚СЂРёС…РєРѕРґ Р·Р°С€РёРІР°РµС‚СЃСЏ РЅРѕРјРµСЂ РѕС‚РіСЂСѓР·РєРё (РЅР° СЃС‚РѕСЂРѕРЅРµ РїРµС‡Р°С‚Рё).
     */
    public function pickDoc(int $id): array
    {
        $st = $this->pdo->prepare("SELECT * FROM shipments WHERE id=:id");
        $st->execute([':id' => $id]);
        $head = $st->fetch(PDO::FETCH_ASSOC);
        if (!$head) {
            throw new RuntimeException('Р”РѕРєСѓРјРµРЅС‚ РЅРµ РЅР°Р№РґРµРЅ');
        }
        try {
            $rows = $this->pdo->prepare(
                "SELECT i.id, i.code, i.sku, i.name, i.unit, i.qty AS plan_qty
                 FROM shipment_items i WHERE i.shipment_id = :id ORDER BY i.id"
            );
            $rows->execute([':id' => $id]);
            $items = $rows->fetchAll(PDO::FETCH_ASSOC);
            // РћС‚Р±РѕСЂ РІ tsd_picks РІРµРґС‘С‚СЃСЏ РїРѕ SKU (Р±РµР· РїСЂРёРІСЏР·РєРё Рє РЅР°СЂСЏРґСѓ), РїРѕСЌС‚РѕРјСѓ
            // С„Р°РєС‚ РїРѕ SKU РЅСѓР¶РЅРѕ СЂР°Р·Р»РѕР¶РёС‚СЊ РїРѕ РµРіРѕ СЃС‚СЂРѕРєР°Рј РѕС‚РіСЂСѓР·РєРё. РРЅР°С‡Рµ РїСЂРё
            // РїРѕРІС‚РѕСЂРµ Р°СЂС‚РёРєСѓР»Р° РІ РґРІСѓС… РЅР°СЂСЏРґР°С… РѕР±Рµ СЃС‚СЂРѕРєРё РїРѕРєР°Р·С‹РІР°Р»Рё Р±С‹ РѕР±С‰РёР№
            // С„Р°РєС‚ (РЅР°РїСЂРёРјРµСЂ 10/5 Рё 10/5 вЂ” РїРѕ 200%).
            $picks = $this->pdo->prepare(
                "SELECT sku, COALESCE(SUM(qty),0) AS q FROM tsd_picks WHERE shipment_id = :id GROUP BY sku"
            );
            $picks->execute([':id' => $id]);
            $facts = [];
            foreach ($picks->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $facts[(string)$p['sku']] = (float)$p['q'];
            }
            $dist = self::distributePickFact($items, $facts);
            foreach ($items as &$it) {
                $it['fact_qty'] = $dist[(string)$it['id']] ?? 0.0;
            }
            unset($it);
        } catch (Throwable) {
            $rows = $this->pdo->prepare("SELECT id, code, sku, name, unit, qty AS plan_qty, 0 AS fact_qty FROM shipment_items WHERE shipment_id = :id ORDER BY id");
            $rows->execute([':id' => $id]);
            $items = $rows->fetchAll(PDO::FETCH_ASSOC);
        }
        return ['kind' => 'pick_list', 'title' => 'РЎР±РѕСЂРѕС‡РЅС‹Р№ Р»РёСЃС‚', 'head' => $head, 'items' => $items];
    }

    /**
     * Р Р°СЃРєР»Р°РґС‹РІР°РµС‚ С„Р°РєС‚ РѕС‚Р±РѕСЂР° РїРѕ SKU РїСЂРѕРїРѕСЂС†РёРѕРЅР°Р»СЊРЅРѕ РїР»Р°РЅСѓ РµРіРѕ СЃС‚СЂРѕРє.
     * РћСЃС‚Р°С‚РѕРє РѕС‚ РґРµР»РµРЅРёСЏ РѕС‚РґР°С‘Рј РїРѕСЃР»РµРґРЅРµР№ СЃС‚СЂРѕРєРµ вЂ” СЃСѓРјРјР° С„Р°РєС‚Р° РїРѕ СЃС‚СЂРѕРєР°Рј
     * СЃРѕРІРїР°РґР°РµС‚ СЃ С„Р°РєС‚РѕРј РѕС‚Р±РѕСЂР° СЃ С‚РѕС‡РЅРѕСЃС‚СЊСЋ РґРѕ РєРѕРїРµР№РєРё.
     * @param array $items СЃС‚СЂРѕРєРё РѕС‚РіСЂСѓР·РєРё (id, sku, qty)
     * @param array $facts sku => РѕС‚РѕР±СЂР°РЅРЅРѕРµ РєРѕР»РёС‡РµСЃС‚РІРѕ
     * @return array id СЃС‚СЂРѕРєРё => С„Р°РєС‚
     */
    private static function distributePickFact(array $items, array $facts): array
    {
        $bySku = [];
        foreach ($items as $it) {
            $bySku[(string)($it['sku'] ?? '')][] = $it;
        }
        $out = [];
        foreach ($bySku as $sku => $rows) {
            $total = (float)($facts[$sku] ?? 0.0);
            if (count($rows) === 1) {
                $out[(string)$rows[0]['id']] = $total;
                continue;
            }
            $planSum = 0.0;
            foreach ($rows as $r) {
                // Р’ РІС‹Р±РѕСЂРєРµ РїР»Р°РЅ РЅР°Р·С‹РІР°РµС‚СЃСЏ plan_qty, РІ РѕР±С‰РµРј РІРёРґРµ вЂ” qty.
                $planSum += (float)($r['qty'] ?? $r['plan_qty'] ?? 0);
            }
            $acc = 0.0;
            $n = count($rows);
            foreach ($rows as $k => $r) {
                if ($k === $n - 1) {
                    $out[(string)$r['id']] = round($total - $acc, 3);
                    break;
                }
                $plan = (float)($r['qty'] ?? $r['plan_qty'] ?? 0);
                $share = $planSum > 0 ? round($total * $plan / $planSum, 3) : 0.0;
                $out[(string)$r['id']] = $share;
                $acc += $share;
            }
        }
        return $out;
    }

    /**
     * РЈРїР°РєРѕРІРѕС‡РЅС‹Р№ Р»РёСЃС‚: СЃРѕРґРµСЂР¶РёРјРѕРµ РћР”РќРћР™ SSCC (С„Р°РєС‚ СЃРѕСЂС‚РёСЂРѕРІРєРё С‡РµСЂРµР· РўРЎР”).
     * Р’ С€С‚СЂРёС…РєРѕРґ Р·Р°С€РёРІР°РµС‚СЃСЏ РЅРѕРјРµСЂ SSCC (РЅР° СЃС‚РѕСЂРѕРЅРµ РїРµС‡Р°С‚Рё).
     */
    public function packDoc(int $id, string $sscc): array
    {
        $sscc = trim($sscc);
        if ($sscc === '') {
            throw new RuntimeException('РЈРєР°Р¶РёС‚Рµ SSCC');
        }
        $st = $this->pdo->prepare("SELECT * FROM shipments WHERE id=:id");
        $st->execute([':id' => $id]);
        $head = $st->fetch(PDO::FETCH_ASSOC);
        if (!$head) {
            throw new RuntimeException('Р”РѕРєСѓРјРµРЅС‚ РЅРµ РЅР°Р№РґРµРЅ');
        }
        $head['sscc'] = $sscc;
        try {
            // PG РЅРµ СЂР°Р·СЂРµС€Р°РµС‚ РєРѕСЂСЂРµР»РёСЂРѕРІР°РЅРЅС‹Рµ РїРѕРґР·Р°РїСЂРѕСЃС‹ Рє РЅРµРіСЂСѓРїРїРёСЂРѕРІР°РЅРЅС‹Рј СЃС‚РѕР»Р±С†Р°Рј:
            // Р°РіСЂРµРіРёСЂСѓРµРј С„Р°РєС‚ РѕС‚РґРµР»СЊРЅРѕ Рё РїРѕРґРєР»РµРёРІР°РµРј СЃРїСЂР°РІРѕС‡РЅРёРє РїРѕ sku С‡РµСЂРµР· MIN(id).
            $rows = $this->pdo->prepare(
                "SELECT COALESCE(i.code, '') AS code, t.sku,
                        COALESCE(i.name, t.sku) AS name, COALESCE(i.unit, 'С€С‚') AS unit, t.qty
                   FROM (SELECT sku, SUM(qty) AS qty, MIN(id) AS fid FROM tsd_picks
                          WHERE shipment_id = :id AND sscc = :sscc GROUP BY sku) t
                   LEFT JOIN (SELECT sku, MIN(id) AS id FROM shipment_items
                               WHERE shipment_id = :id2 GROUP BY sku) mi ON mi.sku = t.sku
                   LEFT JOIN shipment_items i ON i.id = mi.id
                  ORDER BY t.fid"
            );
            $rows->execute([':id' => $id, ':id2' => $id, ':sscc' => $sscc]);
            $items = $rows->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $items = [];
        }
        return ['kind' => 'pack_list', 'title' => 'РЈРїР°РєРѕРІРѕС‡РЅС‹Р№ Р»РёСЃС‚', 'sscc' => $sscc, 'head' => $head, 'items' => $items];
    }

    /** SSCC СЃРѕСЂС‚РёСЂРѕРІРєРё РїРѕ РѕС‚РіСЂСѓР·РєРµ: [{sscc, lines, qty}] вЂ” РґР»СЏ РІС‹Р±РѕСЂР° СѓРїР°РєРѕРІРѕС‡РЅРѕРіРѕ Р»РёСЃС‚Р°. */
    public function ssccList(int $id): array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT sscc, COUNT(*) AS lines, COALESCE(SUM(qty), 0) AS qty
                 FROM tsd_picks WHERE shipment_id = :id AND sscc IS NOT NULL AND sscc <> ''
                 GROUP BY sscc ORDER BY MIN(id)"
            );
            $st->execute([':id' => $id]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    // Р­РєСЃРїРѕСЂС‚ СЂРµРµСЃС‚СЂР° РІ CSV (РґР»СЏ Р±РѕР»СЊС€РёС… РѕР±СЉРµРјРѕРІ вЂ” РѕС‚РґР°РІР°С‚СЊ С‡РµСЂРµР· РѕС‡РµСЂРµРґСЊ С‡Р°РЅРєР°РјРё).
    public function exportCsv(ShipmentFilter $f, object $repo): string
    {
        $page = $repo->paginate($f);
        $out = fopen('php://temp', 'r+');
        fputs($out, "\xEF\xBB\xBF"); // BOM РґР»СЏ Excel
        fputcsv($out, ['в„– РґРѕРєСѓРјРµРЅС‚Р°', 'Р”Р°С‚Р°', 'Р—Р°РєР°Р·', 'РљРѕРЅС‚СЂР°РіРµРЅС‚', 'РЎРєР»Р°Рґ', 'РЎС‚Р°С‚СѓСЃ', 'РЎСѓРјРјР°', 'РџРѕР·РёС†РёР№', 'РџР»Р°РЅ. РѕС‚РіСЂСѓР·РєР°', 'РЎРѕР·РґР°РЅ'], ';');
        foreach ($page['rows'] as $r) {
            fputcsv($out, [$r['doc_no'], $r['doc_date'], $r['order_no'], $r['contractor'],
                $r['warehouse'], ShipmentStatus::from($r['status'])->label(),
                $r['total'], $r['items_count'], $r['plan_shipment_date'], $r['created_by']], ';');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return $csv;
    }
}

