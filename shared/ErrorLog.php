<?php
declare(strict_types=1);
/**
 * Журнал ошибок приложения (модуль «Журнал ошибок»).
 *
 * Пишет все сбои процесса работы WMS в таблицу wms_error_log:
 *   - необработанные исключения (set_exception_handler),
 *   - предупреждения/замечания PHP (set_error_handler),
 *   - фатальные ошибки (register_shutdown_function),
 *   - ответы API со статусом >= 400 (модули ловят ошибки сами и отдают JSON — их тоже фиксируем).
 *
 * Повтор одной и той же ошибки в течение 5 минут не размножается:
 * seen_count++, запись поднимается наверх и снова становится непрочитанной.
 *
 * Наружу (колокольчик) журнал виден только роли «Разработчик» (право dev_errors).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pg.php';
require_once __DIR__ . '/Journal.php';

/** PDO журнала ошибок: PostgreSQL — общая база, SQLite — wms_common. */
function wms_error_log_pdo(): PDO
{
    return wms_journal_pdo();
}

/** Таблица wms_error_log (идемпотентно, для всех СУБД). */
function wms_error_log_ensure(?PDO $pdo = null): void
{
    static $done = false;
    if ($done && $pdo === null) {
        return;
    }
    $pdo = $pdo ?: wms_error_log_pdo();
    try {
        $drv = function_exists('wms_driver') ? wms_driver($pdo) : (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($drv === 'pgsql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS wms_error_log (
                id BIGSERIAL PRIMARY KEY,
                level VARCHAR(20) NOT NULL DEFAULT 'error',
                message TEXT,
                file VARCHAR(500) NULL,
                line INTEGER NULL,
                trace TEXT NULL,
                url VARCHAR(1000) NULL,
                method VARCHAR(10) NULL,
                module VARCHAR(100) NULL,
                user_id BIGINT NULL,
                user_fio VARCHAR(150) NULL,
                role VARCHAR(50) NULL,
                ip VARCHAR(64) NULL,
                context TEXT NULL,
                hash VARCHAR(64) NULL,
                seen_count INTEGER NOT NULL DEFAULT 1,
                is_read SMALLINT NOT NULL DEFAULT 0,
                read_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW())");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_err_created ON wms_error_log (created_at)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_err_read ON wms_error_log (is_read)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_err_hash ON wms_error_log (hash)");
        } elseif ($drv === 'mysql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS wms_error_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                level VARCHAR(20) NOT NULL DEFAULT 'error',
                message TEXT,
                file VARCHAR(500) NULL,
                line INT NULL,
                trace TEXT NULL,
                url VARCHAR(1000) NULL,
                method VARCHAR(10) NULL,
                module VARCHAR(100) NULL,
                user_id BIGINT NULL,
                user_fio VARCHAR(150) NULL,
                role VARCHAR(50) NULL,
                ip VARCHAR(64) NULL,
                context TEXT NULL,
                hash VARCHAR(64) NULL,
                seen_count INT NOT NULL DEFAULT 1,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                read_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_err_created (created_at),
                KEY idx_err_read (is_read),
                KEY idx_err_hash (hash))");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS wms_error_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                level TEXT NOT NULL DEFAULT 'error',
                message TEXT,
                file TEXT,
                line INTEGER,
                trace TEXT,
                url TEXT,
                method TEXT,
                module TEXT,
                user_id INTEGER,
                user_fio TEXT,
                role TEXT,
                ip TEXT,
                context TEXT,
                hash TEXT,
                seen_count INTEGER NOT NULL DEFAULT 1,
                is_read INTEGER NOT NULL DEFAULT 0,
                read_at TEXT,
                created_at TEXT DEFAULT (datetime('now')))");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_err_created ON wms_error_log (created_at)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_err_read ON wms_error_log (is_read)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_err_hash ON wms_error_log (hash)");
        }
        $done = true;
    } catch (Throwable) {
    }
}

/** Обрезать строку без mbstring (UTF-8 безопасно по границе символа). */
function wms_error_cut(string $s, int $max): string
{
    if (strlen($s) <= $max) {
        return $s;
    }
    $cut = substr($s, 0, $max);
    $bad = 0;
    for ($i = strlen($cut) - 1; $i >= 0 && $i >= strlen($cut) - 3; $i--) {
        $b = ord($cut[$i]);
        if (($b & 0xC0) !== 0x80) {
            break;
        }
        $bad++;
    }
    return substr($cut, 0, strlen($cut) - $bad) . '…';
}

/** Текущий модуль по URI (для группировки ошибок). */
function wms_error_module(string $url): string
{
    if (preg_match('#^/([^/?]+)/#', $url, $m)) {
        return (string)$m[1];
    }
    return '';
}

/**
 * Записать ошибку в журнал. Никогда не бросает исключений (иначе рекурсия).
 * @param array{file?:string,line?:int|string,trace?:string,context?:mixed,module?:string,
 *              user_id?:int,user_fio?:string,role?:string,url?:string,method?:string,ip?:string} $ctx
 */
function wms_error_log_write(string $level, string $message, array $ctx = []): void
{
    // Отметка «в этом запросе ошибка уже записана» — чтобы не дублировать её из ответа API.
    $GLOBALS['wms_error_logged'] = true;
    try {
        $message = trim($message);
        if ($message === '') {
            $message = 'Неизвестная ошибка';
        }
        $url = (string)($ctx['url'] ?? ($_SERVER['REQUEST_URI'] ?? ''));
        $method = (string)($ctx['method'] ?? ($_SERVER['REQUEST_METHOD'] ?? ''));
        $file = (string)($ctx['file'] ?? '');
        $line = (int)($ctx['line'] ?? 0);
        $hash = sha1($level . '|' . $message . '|' . $file . '|' . $line);
        $pdo = wms_error_log_pdo();
        wms_error_log_ensure($pdo);

        // Пользователь (если есть сессия).
        $userId = $ctx['user_id'] ?? null;
        $userFio = $ctx['user_fio'] ?? null;
        $role = $ctx['role'] ?? null;
        if ($userId === null) {
            try {
                if (function_exists('wms_auth_user')) {
                    $u = wms_auth_user();
                    if (is_array($u)) {
                        $userId = (int)($u['id'] ?? 0) ?: null;
                        $userFio = (string)($u['fio'] ?? '');
                        $role = (string)($u['role'] ?? '');
                    }
                }
            } catch (Throwable) {
            }
        }
        $context = '';
        if (array_key_exists('context', $ctx)) {
            $c = $ctx['context'];
            $context = is_scalar($c) ? (string)$c : (string)json_encode($c, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $module = (string)($ctx['module'] ?? wms_error_module($url));
        $ip = (string)($ctx['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
        $drv = function_exists('wms_driver') ? wms_driver($pdo) : (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $now = $drv === 'pgsql' ? 'NOW()' : "datetime('now')";

        // Дедупликация: такая же ошибка за последние 5 минут.
        $dup = $pdo->prepare(
            "SELECT id FROM wms_error_log WHERE hash = :h AND created_at >= " .
            ($drv === 'pgsql' ? "NOW() - INTERVAL '5 minutes'" : "datetime('now','-5 minutes')") .
            ' ORDER BY id DESC LIMIT 1'
        );
        $dup->execute([':h' => $hash]);
        $dupId = (int)($dup->fetchColumn() ?: 0);
        if ($dupId > 0) {
            $pdo->prepare(
                "UPDATE wms_error_log SET seen_count = seen_count + 1, is_read = 0, read_at = NULL,
                        created_at = $now, user_id = :u, user_fio = :f, role = :r, url = :url
                  WHERE id = :id"
            )->execute([
                ':u' => $userId, ':f' => $userFio, ':r' => $role, ':url' => wms_error_cut($url, 1000),
                ':id' => $dupId,
            ]);
            return;
        }

        $st = $pdo->prepare(
            "INSERT INTO wms_error_log (level,message,file,line,trace,url,method,module,user_id,user_fio,role,ip,context,hash,seen_count,is_read,created_at)
             VALUES (:lv,:msg,:file,:line,:trace,:url,:m,:mod,:uid,:ufio,:role,:ip,:ctx,:h,1,0,$now)"
        );
        $st->execute([
            ':lv' => wms_error_cut($level, 20),
            ':msg' => wms_error_cut($message, 5000),
            ':file' => wms_error_cut($file, 500),
            ':line' => $line ?: null,
            ':trace' => wms_error_cut((string)($ctx['trace'] ?? ''), 8000),
            ':url' => wms_error_cut($url, 1000),
            ':m' => wms_error_cut($method, 10),
            ':mod' => wms_error_cut($module, 100),
            ':uid' => $userId,
            ':ufio' => $userFio !== null ? wms_error_cut((string)$userFio, 150) : null,
            ':role' => $role !== null ? wms_error_cut((string)$role, 50) : null,
            ':ip' => wms_error_cut($ip, 64),
            ':ctx' => wms_error_cut($context, 4000),
            ':h' => $hash,
        ]);
    } catch (Throwable) {
        // Журнал ошибок не должен ронять приложение.
    }
}

/** Ответ API со статусом ошибки (модули ловят исключения сами — фиксируем их текст). */
function wms_error_capture_response(): void
{
    try {
        if (!empty($GLOBALS['wms_error_logged'])) {
            return;
        }
        // Свой API журнала ошибок не логируем (иначе 403 от чужой роли засоряет журнал).
        $reqUri = (string)($_SERVER['REQUEST_URI'] ?? '');
        if (str_contains($reqUri, '/shared/errors.php')) {
            return;
        }
        $status = function_exists('http_response_code') ? (int)http_response_code() : 0;
        if ($status < 400 || $status === 401 || $status === 404) {
            return;
        }
        $body = '';
        if (ob_get_level() > 0) {
            $body = (string)ob_get_contents();
        }
        $body = trim($body);
        if ($body === '' || $body[0] !== '{') {
            return;
        }
        $j = json_decode($body, true);
        if (!is_array($j) || !isset($j['error'])) {
            return;
        }
        $err = is_scalar($j['error']) ? (string)$j['error'] : json_encode($j['error'], JSON_UNESCAPED_UNICODE);
        if (trim($err) === '') {
            return;
        }
        wms_error_log_write('api', $err, [
            'context' => ['status' => $status] + $j,
        ]);
    } catch (Throwable) {
    }
}

/** Страница/JSON для необработанного исключения. */
function wms_error_render(Throwable $e): void
{
    $isApi = false;
    try {
        require_once __DIR__ . '/Auth.php';
        $isApi = function_exists('wms_auth_is_api_uri')
            && (wms_auth_is_api_uri((string)($_SERVER['REQUEST_URI'] ?? '')) || isset($_GET['action']));
    } catch (Throwable) {
    }
    if (!headers_sent()) {
        http_response_code(500);
        header($isApi ? 'Content-Type: application/json; charset=utf-8' : 'Content-Type: text/html; charset=utf-8');
    }
    if ($isApi) {
        echo json_encode(['error' => 'Внутренняя ошибка сервера: ' . $e->getMessage()],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return;
    }
    echo '<!doctype html><meta charset="utf-8"><title>Ошибка</title>'
        . '<div style="font:14px Arial;padding:30px"><h2>Внутренняя ошибка</h2>'
        . '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="color:#888">Ошибка записана в журнал ошибок. Роль «Разработчик» увидит её в колокольчике.</p>'
        . '<p><a href="/00-dashboard/app.html">На дашборд</a></p></div>';
}

/** Установить глобальные обработчики (вызывается из router.php). */
function wms_error_log_install(): void
{
    static $installed = false;
    if ($installed) {
        return;
    }
    $installed = true;

    set_error_handler(function (int $no, string $str, string $file = '', int $line = 0): bool {
        if (!(error_reporting() & $no)) {
            return false;
        }
        if (in_array($no, [E_DEPRECATED, E_USER_DEPRECATED, E_NOTICE, E_USER_NOTICE], true)) {
            // Замечания не засоряют журнал: только предупреждения и серьёзнее.
            return false;
        }
        wms_error_log_write($no === E_WARNING || $no === E_USER_WARNING ? 'warning' : 'php', $str, [
            'file' => $file, 'line' => $line,
        ]);
        return false;
    });

    set_exception_handler(function (Throwable $e): void {
        wms_error_log_write('error', $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
        wms_error_render($e);
    });

    register_shutdown_function(function (): void {
        $err = error_get_last();
        if ($err && in_array((int)$err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            wms_error_log_write('fatal', (string)$err['message'], [
                'file' => (string)$err['file'],
                'line' => (int)$err['line'],
            ]);
        }
        wms_error_capture_response();
    });
}



/** Виден ли журнал ошибок текущему пользователю (только роль «Разработчик»). */
function wms_error_can_view(): bool
{
    try {
        require_once __DIR__ . '/Auth.php';
        if (function_exists('wms_auth_can_permission') && wms_auth_can_permission('dev_errors')) {
            return true;
        }
        $u = function_exists('wms_auth_user') ? wms_auth_user() : null;
        return is_array($u) && trim((string)($u['role'] ?? '')) === 'Разработчик';
    } catch (Throwable) {
        return false;
    }
}

/** Непрочитанные ошибки (для колокольчика). */
function wms_error_unread_count(): int
{
    try {
        $pdo = wms_error_log_pdo();
        wms_error_log_ensure($pdo);
        return (int)$pdo->query('SELECT COUNT(*) FROM wms_error_log WHERE is_read = 0')->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}
