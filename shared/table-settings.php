<?php
declare(strict_types=1);
/**
 * Пер-пользовательские настройки таблиц (видимость/порядок столбцов, строк на странице).
 * Используется shared/table-tools.js на всех модулях.
 *
 *   GET  ?action=get&form=tt_14_zhurnal_app_html
 *   POST ?action=save {form:"...", cols:{order:[], cols:{}, perPage:null}}
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/UserColumns.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = wms_nomenclature_pdo();
    $action = (string)($_GET['action'] ?? 'get');
    if ($action === 'get') {
        $form = (string)($_GET['form'] ?? '');
        if (!preg_match('/^[a-z0-9_]{1,40}$/', $form)) {
            throw new RuntimeException('Некорректный ключ формы');
        }
        echo json_encode(['ok' => true, 'cols' => wms_columns_get($pdo, $form)], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'save' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $b = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($b)) {
            throw new RuntimeException('Некорректные данные');
        }
        $form = (string)($b['form'] ?? ($_GET['form'] ?? ''));
        $cols = $b['cols'] ?? null;
        wms_columns_save($pdo, $form, $cols);
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Неизвестное действие']);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
