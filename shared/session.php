<?php
declare(strict_types=1);

require_once __DIR__ . '/Auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$user = wms_auth_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'user' => wms_auth_public_user($user),
    'permissions' => wms_auth_permissions(),
    'module_levels' => wms_auth_module_levels(),
], JSON_UNESCAPED_UNICODE);
