<?php
declare(strict_types=1);
// Робот импорта in/out. Запуск вручную:
//   php robot.php
// Режим постоянного таймера (для планировщика/фонового процесса):
//   php robot.php --loop
require_once __DIR__ . '/shared/ImportRobot.php';

$isCli = PHP_SAPI === 'cli';
$loop = $isCli && in_array('--loop', $argv ?? [], true);
if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
}

do {
    try {
        $res = wms_robot_run();
        if ($isCli) {
            fwrite(STDOUT, json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
        } else {
            echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
    } catch (Throwable $e) {
        if ($isCli) {
            fwrite(STDERR, 'Ошибка робота: ' . $e->getMessage() . PHP_EOL);
        } else {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
    if ($loop) {
        $s = wms_robot_settings();
        sleep(max(60, (int)$s['interval_min'] * 60));
    }
} while ($loop);
