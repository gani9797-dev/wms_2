<?php
declare(strict_types=1);
/**
 * Внешнее REST API WMS: /api/v1/*
 *
 * Аутентификация — OAuth 2.0 client_credentials (JWT HS256, TTL ≤ 60 мин),
 * заголовок Authorization: Bearer <token>. Сессия WMS не требуется.
 * Единый формат ошибки: {"code","message","details":[]}.
 * X-Request-Id — сквозной трейс (генерируется, если не передан).
 */

require_once __DIR__ . '/../../shared/IntegrationApi.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$route = '/' . ltrim(substr($path, strlen('/api/v1')), '/');
$route = rtrim($route, '/') ?: '/';
$requestId = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
if ($requestId === '') {
    $requestId = wms_api_random(16);
}
header('X-Request-Id: ' . $requestId);
$started = microtime(true);
$rawBody = (string)file_get_contents('php://input');
$body = [];
if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    $body = is_array($decoded) ? $decoded : [];
}
$clientId = '';
$status = 200;
$response = null;
$errorText = '';
$detail = [];

try {
    $pdo = wms_api_pdo();
    $settings = wms_api_settings($pdo);
    if (empty($settings['enabled'])) {
        throw new WmsApiError('SERVICE_DISABLED', 'Интеграция отключена в настройках', 503);
    }

    $isTokenRoute = ($route === '/oauth/token' || $route === '/token');

    if ($isTokenRoute && $method === 'POST') {
        $grant = (string)($body['grant_type'] ?? ($_POST['grant_type'] ?? 'client_credentials'));
        $inClient = (string)($body['client_id'] ?? ($_POST['client_id'] ?? ''));
        $inSecret = (string)($body['client_secret'] ?? ($_POST['client_secret'] ?? ''));
        if (!hash_equals((string)$settings['client_id'], $inClient)
            || !$inSecret || !hash_equals((string)$settings['client_secret'], $inSecret)) {
            throw new WmsApiError('INVALID_CLIENT', 'Неверные client_id/client_secret', 401);
        }
        if (!in_array($grant, ['client_credentials', 'refresh_token'], true)) {
            throw new WmsApiError('UNSUPPORTED_GRANT', 'Поддерживается client_credentials или refresh_token', 400);
        }
        if ($grant === 'refresh_token') {
            $claims = wms_api_verify_token($pdo, 'Bearer ' . (string)($body['refresh_token'] ?? ($_POST['refresh_token'] ?? '')));
            if (!$claims) {
                throw new WmsApiError('INVALID_TOKEN', 'refresh_token недействителен или истёк', 401);
            }
        }
        $response = wms_api_issue_token($settings);
        $clientId = $inClient;
        $status = 200;
    } else {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($authHeader === '' && function_exists('getallheaders')) {
            $hdrs = getallheaders();
            $authHeader = (string)($hdrs['Authorization'] ?? $hdrs['authorization'] ?? '');
        }
        $claims = wms_api_verify_token($pdo, $authHeader);
        if (!$claims) {
            $status = 401;
            header('WWW-Authenticate: Bearer realm="wms", error="invalid_token"');
            throw new WmsApiError('UNAUTHORIZED', 'Требуется действующий Bearer-токен (POST /api/v1/oauth/token)', 401);
        }
        $clientId = (string)($claims['sub'] ?? '');
        $limit = max(1, (int)($settings['rate_limit'] ?? 100));
        if (!wms_api_rate_ok($pdo, 'sub:' . $clientId, $limit)) {
            header('Retry-After: 1');
            throw new WmsApiError('RATE_LIMITED', 'Превышен лимит запросов: ' . $limit . '/сек на токен', 429);
        }

        if ($route === '/' && $method === 'GET') {
            $response = [
                'service' => 'WMS Integration API',
                'version' => 'v1',
                'auth' => 'OAuth 2.0 client_credentials (JWT Bearer)',
                'endpoints' => [
                    'POST /api/v1/oauth/token', 'POST /api/v1/orders', 'GET /api/v1/orders/{external_id}',
                    'POST /api/v1/nomenclature', 'GET /api/v1/stock', 'GET /api/v1/webhooks', 'GET /api/v1/health',
                ],
            ];
        } elseif ($route === '/orders' && $method === 'POST') {
            $res = wms_api_upsert_order($pdo, $body);
            $status = $res['created'] ? 201 : 200;
            $response = [
                'external_id' => $res['external_id'],
                'order_id' => (int)($res['order']['id'] ?? 0),
                'doc_no' => (string)$res['doc_no'],
                'status' => (string)($res['order']['status'] ?? 'created'),
                'created' => $res['created'],
            ];
        } elseif (preg_match('#^/orders/([^/]+)$#', $route, $m) && $method === 'GET') {
            $ext = rawurldecode($m[1]);
            $row = wms_api_order_by_external($pdo, $ext);
            if (!$row) {
                throw new WmsApiError('NOT_FOUND', 'Заказ с external_id=' . $ext . ' не найден', 404);
            }
            $response = [
                'external_id' => (string)($row['external_id'] ?? $ext),
                'order_id' => (int)$row['id'],
                'doc_no' => (string)$row['doc_no'],
                'status' => (string)$row['status'],
                'consignee' => (string)($row['consignee'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
            ];
        } elseif ($route === '/nomenclature' && $method === 'POST') {
            $res = wms_api_upsert_product($pdo, $body);
            $status = $res['created'] ? 201 : 200;
            $response = ['sku' => $res['sku'], 'product_id' => $res['product_id'], 'created' => $res['created']];
        } elseif ($route === '/stock' && $method === 'GET') {
            $page = (int)($_GET['page'] ?? 1);
            $pageSize = (int)($_GET['page_size'] ?? 100);
            $response = wms_api_stock($pdo, $page, $pageSize);
        } elseif ($route === '/health' && $method === 'GET') {
            $response = wms_api_health($pdo);
        } elseif ($route === '/webhooks' && $method === 'GET') {
            $st = $pdo->query("SELECT id,event,external_id,status,attempts,http_code,last_error,created_at,sent_at
                FROM wms_webhook_outbox ORDER BY id DESC LIMIT 100");
            $response = ['items' => $st->fetchAll(PDO::FETCH_ASSOC)];
        } else {
            throw new WmsApiError('NOT_FOUND', 'Метод не найден: ' . $method . ' ' . $route, 404);
        }
    }
} catch (WmsApiError $e) {
    $status = $e->httpStatus;
    $errorText = $e->apiCode . ': ' . $e->getMessage();
    $detail = $e->details;
    $response = ['code' => $e->apiCode, 'message' => $e->getMessage(), 'details' => $e->details];
} catch (Throwable $e) {
    $status = 500;
    $errorText = 'INTERNAL_ERROR: ' . $e->getMessage();
    $response = ['code' => 'INTERNAL_ERROR', 'message' => 'Внутренняя ошибка сервера', 'details' => []];
}

http_response_code($status);
$encoded = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($encoded === false) {
    $encoded = '{"code":"INTERNAL_ERROR","message":"encode failed","details":[]}';
}
echo $encoded;

try {
    wms_api_log($pdo, [
        'request_id' => $requestId,
        'direction' => 'IN',
        'client_id' => $clientId,
        'method' => $method,
        'path' => $route,
        'status_code' => $status,
        'duration_ms' => (int)round((microtime(true) - $started) * 1000),
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'request_body' => $rawBody,
        'response_body' => $encoded,
        'error' => $errorText,
    ]);
    if (($status === 200 || $status === 201) && $method === 'POST' && $clientId !== '') {
        wms_webhooks_dispatch_due($pdo, 5);
    }
} catch (Throwable) {
}
