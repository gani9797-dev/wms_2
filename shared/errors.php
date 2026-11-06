<?php
declare(strict_types=1);
/**
 * API журнала ошибок (колокольчик роли «Разработчик»).
 *   GET  ?action=count            — непрочитанные + всего
 *   GET  ?action=list&limit=50    — последние ошибки (+ unread)
 *   POST ?action=read {ids:[..]}  — отметить прочитанными (ids пусто = все)
 *   POST ?action=clear            — очистить журнал
 * Доступ — только право dev_errors (роль «Разработчик»).
 */

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/ErrorLog.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function err_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!wms_auth_user()) {
    err_json(['error' => 'unauthorized'], 401);
}
if (!wms_error_can_view()) {
    err_json(['error' => 'Журнал ошибок доступен только роли «Разработчик»'], 403);
}

$pdo = wms_error_log_pdo();
wms_error_log_ensure($pdo);
$action = (string)($_GET['action'] ?? 'count');

try {
    if ($action === 'count') {
        $unread = (int)$pdo->query('SELECT COUNT(*) FROM wms_error_log WHERE is_read = 0')->fetchColumn();
        $total = (int)$pdo->query('SELECT COUNT(*) FROM wms_error_log')->fetchColumn();
        $last = $pdo->query('SELECT created_at FROM wms_error_log ORDER BY id DESC LIMIT 1')->fetchColumn();
        err_json(['unread' => $unread, 'total' => $total, 'last' => $last ?: null]);
    }

    if ($action === 'list') {
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
        $onlyUnread = (int)($_GET['unread'] ?? 0) === 1;
        $sql = 'SELECT id, level, message, file, line, trace, url, method, module, user_id, user_fio, role, ip, context,
                       seen_count, is_read, created_at
                  FROM wms_error_log'
            . ($onlyUnread ? ' WHERE is_read = 0' : '')
            . ' ORDER BY id DESC LIMIT ' . $limit;
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['is_read'] = (int)$r['is_read'];
            $r['seen_count'] = (int)$r['seen_count'];
            $r['message'] = (string)$r['message'];
        }
        unset($r);
        $unread = (int)$pdo->query('SELECT COUNT(*) FROM wms_error_log WHERE is_read = 0')->fetchColumn();
        err_json(['rows' => $rows, 'unread' => $unread, 'total' => (int)$pdo->query('SELECT COUNT(*) FROM wms_error_log')->fetchColumn()]);
    }

    if ($action === 'read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?: [];
        $ids = array_values(array_filter(array_map('intval', (array)($b['ids'] ?? [])), fn($v) => $v > 0));
        $drv = function_exists('wms_driver') ? wms_driver($pdo) : (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $now = $drv === 'pgsql' ? 'NOW()' : "datetime('now')";
        if ($ids) {
            $in = implode(',', $ids);
            $pdo->exec("UPDATE wms_error_log SET is_read = 1, read_at = $now WHERE id IN ($in)");
        } else {
            $pdo->exec("UPDATE wms_error_log SET is_read = 1, read_at = $now WHERE is_read = 0");
        }
        err_json(['ok' => true, 'unread' => wms_error_unread_count()]);
    }

    if ($action === 'clear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $pdo->exec('DELETE FROM wms_error_log');
        err_json(['ok' => true, 'unread' => 0]);
    }

    err_json(['error' => 'unknown action: count|list|read|clear'], 404);
} catch (Throwable $e) {
    err_json(['error' => $e->getMessage()], 500);
}
