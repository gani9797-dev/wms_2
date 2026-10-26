<?php
declare(strict_types=1);

// Подсистема уведомлений для схемы 8 v31 (колокол 🔔 в шапке).
// События: shipment / receipt / task / nomenclature.
// Каналы (Web / Email / Telegram) пока только хранят предпочтения,
// реальная отправка — за пределами модуля.

final class Notifications
{
    public const KINDS = ['receipt', 'shipment', 'task', 'nomenclature', 'robot'];

    public const KIND_LABEL = [
        'receipt' => 'Поступления',
        'shipment' => 'Отгрузки',
        'task' => 'Задания',
        'nomenclature' => 'Номенклатура',
        'robot' => 'Робот',
    ];

    /** @return array{rows: array, counts: array{all:int,unread:int,important:int}} */
    public static function list(PDO $pdo, string $tab = 'all', ?string $search = null, ?string $type = null, int $limit = 50): array
    {
        $w = ['1=1']; $p = [];
        if ($tab === 'unread') $w[] = 'is_read = 0';
        elseif ($tab === 'important') $w[] = 'is_important = 1';
        if ($type !== null && $type !== '' && $type !== 'all') { $w[] = 'kind = :t'; $p[':t'] = $type; }
        if ($search !== null && $search !== '') { $w[] = '(title LIKE :q OR body LIKE :q OR sku LIKE :q)'; $p[':q'] = '%' . $search . '%'; }
        $where = 'WHERE ' . implode(' AND ', $w);
        $st = $pdo->prepare("SELECT * FROM notifications $where ORDER BY created_at DESC, id DESC LIMIT :lim");
        foreach ($p as $k => $v) $st->bindValue($k, $v);
        $st->bindValue(':lim', max(1, min(200, $limit)), PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return ['rows' => $rows, 'counts' => self::counts($pdo)];
    }

    /** @return array{all:int,unread:int,important:int} */
    public static function counts(PDO $pdo): array
    {
        try {
            return [
                'all' => (int)$pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn(),
                'unread' => (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn(),
                'important' => (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE is_important = 1")->fetchColumn(),
                'robot' => (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE kind = 'robot'")->fetchColumn(),
            ];
        } catch (Throwable) {
            return ['all' => 0, 'unread' => 0, 'important' => 0];
        }
    }

    public static function markRead(PDO $pdo, int $id): void
    {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id")->execute([':id' => $id]);
    }

    public static function markAllRead(PDO $pdo): int
    {
        $st = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
        $st->execute();
        return $st->rowCount();
    }

    public static function subscriptions(PDO $pdo, int $userId = 1): array
    {
        try {
            $st = $pdo->prepare("SELECT * FROM notification_subscriptions WHERE user_id = :u ORDER BY id");
            $st->execute([':u' => $userId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array{event_kind:string,ch_web?:int,ch_email?:int,ch_tg?:int,cond_label?:string,is_on?:int} $b */
    public static function saveSubscription(PDO $pdo, array $b, int $userId = 1): array
    {
        $kind = (string)($b['event_kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) throw new RuntimeException('Неизвестный тип подписки: ' . $kind);
        $row = [
            ':u' => $userId,
            ':k' => $kind,
            ':w' => (int)($b['ch_web'] ?? 0),
            ':e' => (int)($b['ch_email'] ?? 0),
            ':t' => (int)($b['ch_tg'] ?? 0),
            ':c' => (string)($b['cond_label'] ?? ''),
            ':on' => (int)($b['is_on'] ?? 1),
        ];
        try {
            $pdo->prepare(
                "INSERT INTO notification_subscriptions (user_id, event_kind, ch_web, ch_email, ch_tg, cond_label, is_on)
                 VALUES (:u,:k,:w,:e,:t,:c,:on)
                 ON CONFLICT(user_id, event_kind) DO UPDATE SET ch_web=excluded.ch_web, ch_email=excluded.ch_email, ch_tg=excluded.ch_tg, cond_label=excluded.cond_label, is_on=excluded.is_on"
            )->execute($row);
        } catch (Throwable) {
            // MySQL-fallback
            try {
                $pdo->prepare(
                    "INSERT INTO notification_subscriptions (user_id, event_kind, ch_web, ch_email, ch_tg, cond_label, is_on)
                     VALUES (:u,:k,:w,:e,:t,:c,:on)
                     ON DUPLICATE KEY UPDATE ch_web=VALUES(ch_web), ch_email=VALUES(ch_email), ch_tg=VALUES(ch_tg), cond_label=VALUES(cond_label), is_on=VALUES(is_on)"
                )->execute($row);
            } catch (Throwable) {
                // Последний шанс: update-or-insert
                $upd = $pdo->prepare("UPDATE notification_subscriptions SET ch_web=:w, ch_email=:e, ch_tg=:t, cond_label=:c, is_on=:on WHERE user_id=:u AND event_kind=:k");
                $upd->execute($row);
                if ($upd->rowCount() === 0) {
                    $pdo->prepare("INSERT INTO notification_subscriptions (user_id, event_kind, ch_web, ch_email, ch_tg, cond_label, is_on) VALUES (:u,:k,:w,:e,:t,:c,:on)")->execute($row);
                }
            }
        }
        return ['event_kind' => $kind, 'ok' => true];
    }

    public static function deleteSubscription(PDO $pdo, string $kind, int $userId = 1): void
    {
        $pdo->prepare("DELETE FROM notification_subscriptions WHERE user_id = :u AND event_kind = :k")
            ->execute([':u' => $userId, ':k' => $kind]);
    }

    /**
     * Создать уведомление (тихий no-op, если таблицы нет).
     * Учитывает подписку: если вид событий выключен — не создаём.
     * Лента уведомлений общая для склада, поэтому для broadcast-вызова
     * (без user_id) вид считается выключенным только если его выключили ВСЕ
     * подписчики. Раньше достаточно было ОДНОГО выключенного пользователя,
     * чтобы отключить вид событий для всех остальных.
     */
    public static function push(PDO $pdo, string $kind, string $title, ?string $body = null, ?string $sku = null, int $important = 0, ?int $userId = null): void
    {
        try {
            if (in_array($kind, self::KINDS, true)) {
                try {
                    if ($userId !== null) {
                        $st = $pdo->prepare("SELECT is_on FROM notification_subscriptions WHERE event_kind = :k AND user_id = :u LIMIT 1");
                        $st->execute([':k' => $kind, ':u' => $userId]);
                        $on = $st->fetchColumn();
                        if ($on !== false && (int)$on === 0) {
                            return;
                        }
                    } else {
                        $st = $pdo->prepare("SELECT COUNT(*) FROM notification_subscriptions WHERE event_kind = :k AND is_on = 1");
                        $st->execute([':k' => $kind]);
                        $enabled = (int)$st->fetchColumn();
                        if ($enabled === 0) {
                            $any = $pdo->prepare("SELECT COUNT(*) FROM notification_subscriptions WHERE event_kind = :k");
                            $any->execute([':k' => $kind]);
                            // Подписчиков на вид нет вовсе — вид считаем включённым.
                            if ((int)$any->fetchColumn() > 0) {
                                return;
                            }
                        }
                    }
                } catch (Throwable) {}
            }
            $pdo->prepare(
                "INSERT INTO notifications (user_id, kind, title, body, sku, is_important, is_read) VALUES (:u,:k,:t,:b,:s,:i,0)"
            )->execute([':u' => $userId, ':k' => $kind, ':t' => $title, ':b' => $body, ':s' => $sku, ':i' => $important]);
        } catch (Throwable) {}
    }
}
