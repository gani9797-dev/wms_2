<?php
declare(strict_types=1);

// Авторизация ТСД: общий токен доступа + вход сотрудника по PIN.
//
// Токен берётся из окружения (переменная TSD_TOKEN в .env или в среде),
// то есть в коде он больше не зашит: смена токена = правка конфигурации.
// Значение по умолчанию оставлено для обратной совместимости с ранее
// развёрнутыми ТСД — в рабочем контуре задайте TSD_TOKEN явно.
//
// ВАЖНО: токен уходит в браузер и отправляется обратно, поэтому он НЕ является
// секретом в строгом смысле — это защита «от случайного доступа» к ТСД.
// Подлинная идентификация сотрудника — вход по PIN (tsd/api.php?action=login);
// PIN выдаёт администратор (модуль 10 «Сотрудники»).

require_once __DIR__ . '/pg.php';

if (!function_exists('wms_tsd_token')) {
    /** Токен доступа к ТСД (из окружения, с запасным значением). */
    function wms_tsd_token(): string
    {
        static $token = null;
        if ($token !== null) {
            return $token;
        }
        wms_load_env();
        $t = trim((string)(getenv('TSD_TOKEN') ?: ''));
        if ($t === '') {
            $t = 'tsd-12345';
        }
        $token = $t;
        return $token;
    }
}

if (!function_exists('wms_tsd_token_from_request')) {
    /** Токен из ?token=, POST token, заголовка X-Tsd-Token или Authorization: Bearer. */
    function wms_tsd_token_from_request(): string
    {
        $t = $_GET['token'] ?? $_POST['token'] ?? '';
        if ((string)$t === '') {
            $h = (string)($_SERVER['HTTP_X_TSD_TOKEN'] ?? '');
            if ($h !== '') {
                $t = $h;
            } else {
                $a = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
                if (str_starts_with($a, 'Bearer ')) {
                    $t = substr($a, 7);
                }
            }
        }
        return (string)$t;
    }
}

if (!function_exists('wms_tsd_token_ok')) {
    /** Проверка токена (по умолчанию — из текущего запроса). */
    function wms_tsd_token_ok(?string $given = null): bool
    {
        if ($given === null) {
            $given = wms_tsd_token_from_request();
        }
        $given = (string)$given;
        return $given !== '' && hash_equals(wms_tsd_token(), $given);
    }
}
