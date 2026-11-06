<?php
declare(strict_types=1);
/**
 * CLI-помощник интеграционных тестов: настройка/восстановление окружения,
 * принудительная отправка очереди вебхуков, очистка тестовых данных.
 *
 *   php config.php save
 *   php config.php apply --callback=http://127.0.0.1:9099/hook --secret=smokehook \
 *        --client-id=smoke-client --client-secret=smoke-secret [--rate=100]
 *   php config.php restore
 *   php config.php dispatch [--force]
 *   php config.php cleanup
 */

require_once __DIR__ . '/../../../shared/IntegrationApi.php';

$pdo = wms_api_pdo();
$cmd = (string)($argv[1] ?? '');
$opts = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--([a-z0-9_-]+)(?:=(.*))?$/i', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? '1';
    }
}
$snap = __DIR__ . '/.settings_snapshot.json';

function cfg_update_all(PDO $pdo, array $s): void
{
    $pdo->prepare('UPDATE wms_api_settings SET enabled=:en,client_id=:ci,client_secret=:cs,jwt_secret=:js,
            callback_url=:cb,webhook_secret=:ws,rate_limit=:rl,token_ttl=:tt WHERE id=1')
        ->execute([
            ':en' => (int)($s['enabled'] ?? 1), ':ci' => (string)($s['client_id'] ?? ''),
            ':cs' => (string)($s['client_secret'] ?? ''), ':js' => (string)($s['jwt_secret'] ?? ''),
            ':cb' => (string)($s['callback_url'] ?? ''), ':ws' => (string)($s['webhook_secret'] ?? ''),
            ':rl' => (int)($s['rate_limit'] ?? 100), ':tt' => (int)($s['token_ttl'] ?? 3600),
        ]);
}

switch ($cmd) {
    case 'save':
        file_put_contents($snap, json_encode(wms_api_settings($pdo), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo "snapshot saved\n";
        break;

    case 'apply':
        $cur = wms_api_settings($pdo);
        if (!is_file($snap)) {
            file_put_contents($snap, json_encode($cur, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        cfg_update_all($pdo, [
            'enabled' => 1,
            'client_id' => $opts['client-id'] ?? 'smoke-client',
            'client_secret' => $opts['client-secret'] ?? 'smoke-secret',
            'jwt_secret' => $opts['jwt-secret'] ?? ('smoke-jwt-' . substr(md5((string)time()), 0, 12)),
            'callback_url' => $opts['callback'] ?? '',
            'webhook_secret' => $opts['secret'] ?? 'smoke-hook-secret',
            'rate_limit' => (int)($opts['rate'] ?? 100),
            'token_ttl' => (int)($opts['ttl'] ?? 3600),
        ]);
        echo json_encode(wms_api_settings($pdo), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
        break;

    case 'restore':
        if (!is_file($snap)) {
            fwrite(STDERR, "no snapshot\n");
            exit(1);
        }
        $s = json_decode((string)file_get_contents($snap), true);
        cfg_update_all($pdo, is_array($s) ? $s : []);
        @unlink($snap);
        echo "restored\n";
        break;

    case 'dispatch':
        if (isset($opts['force'])) {
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("UPDATE wms_webhook_outbox SET next_attempt_at=:n WHERE status IN ('PENDING','RETRY')")
                ->execute([':n' => $now]);
        }
        echo json_encode(wms_webhooks_dispatch_due($pdo, 100), JSON_UNESCAPED_UNICODE), "\n";
        break;

    case 'health':
        echo json_encode(wms_api_health($pdo), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
        break;

    case 'cleanup':
        $pdo->prepare("DELETE FROM wms_webhook_outbox WHERE external_id LIKE 'SMOKE-%' OR payload LIKE '%SMOKE-%'")->execute();
        $pdo->prepare("DELETE FROM client_orders WHERE external_id LIKE 'SMOKE-%'")->execute();
        $pdo->prepare("DELETE FROM products WHERE sku LIKE 'SMOKE-%'")->execute();
        $pdo->prepare("DELETE FROM product_barcodes WHERE barcode LIKE 'SMOKE-%' OR barcode LIKE '4600000000%'")->execute();
        $pdo->prepare("DELETE FROM wms_api_rate")->execute();
        echo "cleaned\n";
        break;

    default:
        fwrite(STDERR, "usage: php config.php save|apply|restore|dispatch|health|cleanup\n");
        exit(2);
}
