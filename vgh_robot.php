<?php
declare(strict_types=1);
/**
 * Робот обновления ВГХ (вес-габаритные характеристики товарного запаса).
 *
 *   php vgh_robot.php            — запуск по расписанию (если включён и окно активно)
 *   php vgh_robot.php --force    — разовый запуск прямо сейчас
 *   php vgh_robot.php --loop     — опрос расписания каждые 60 секунд (для cron/службы)
 *
 * Настройки — модуль 13 → «Роботы» → «Робот по обновлению ВГХ».
 */

require_once __DIR__ . '/shared/VghRobot.php';

$pdo = wms_nomenclature_pdo();
$args = $_SERVER['argv'] ?? [];
$force = in_array('--force', $args, true);
$loop = in_array('--loop', $args, true);

function vgh_cli_out(?array $r): void
{
    echo json_encode($r ?? ['skipped' => true, 'message' => 'Вне окна расписания или робот выключен'],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
}

if ($loop) {
    fwrite(STDERR, "VGH robot loop started\n");
    while (true) {
        try {
            $r = wms_vgh_robot_tick($pdo);
            if ($r) {
                echo date('Y-m-d H:i:s'), ' ', json_encode($r, JSON_UNESCAPED_UNICODE), PHP_EOL;
            }
        } catch (Throwable $e) {
            fwrite(STDERR, date('Y-m-d H:i:s') . ' ERROR ' . $e->getMessage() . PHP_EOL);
        }
        sleep(60);
    }
}

vgh_cli_out($force ? wms_vgh_robot_run($pdo, true) : wms_vgh_robot_tick($pdo));
