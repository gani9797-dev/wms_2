<?php
declare(strict_types=1);

// Пер-пользовательские настройки столбцов реестров (шестеренка в шапке таблицы).
// Хранилище — таблица user_columns в БД модуля (в проде все модули живут в одной
// БД wms, в демо — в sqlite-файле модуля). Место под будущих пользователей готово:
// ключ — логин сессии (router.php), пока все ходят под admin.
//
// Формат cols — произвольный JSON-объект на усмотрение формы:
//   08: {code:1, sku:0, ...} (ключи data-col),
//   остальные: {hidden:[2,5]} (индексы колонок thead).

function wms_columns_ensure(PDO $pdo): void
{
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_columns (
              user_key VARCHAR(100) NOT NULL, form VARCHAR(50) NOT NULL,
              cols TEXT NOT NULL,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (user_key, form)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_columns (
              user_key TEXT NOT NULL, form TEXT NOT NULL, cols TEXT NOT NULL,
              updated_at TEXT DEFAULT (datetime('now')),
              PRIMARY KEY (user_key, form))");
        }
    } catch (Throwable) {
    }
}

/** Ключ текущего пользователя: логин сессии либо 'admin'. */
function wms_columns_user(): string
{
    try {
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['wms_auth_user'])) {
            return (string)$_SESSION['wms_auth_user'];
        }
    } catch (Throwable) {
    }
    return 'admin';
}

/** @return array сохранённый объект cols либо [] */
function wms_columns_get(PDO $pdo, string $form): array
{
    wms_columns_ensure($pdo);
    try {
        $st = $pdo->prepare('SELECT cols FROM user_columns WHERE user_key=:u AND form=:f LIMIT 1');
        $st->execute([':u' => wms_columns_user(), ':f' => $form]);
        $raw = $st->fetchColumn();
        if ($raw !== false && $raw !== null && $raw !== '') {
            $d = json_decode((string)$raw, true);
            if (is_array($d)) {
                return $d;
            }
        }
    } catch (Throwable) {
    }
    return [];
}

function wms_columns_save(PDO $pdo, string $form, $cols): void
{
    wms_columns_ensure($pdo);
    $form = trim($form);
    if (!preg_match('/^[a-z0-9_]{1,40}$/', $form) || !is_array($cols)) {
        throw new RuntimeException('Некорректные данные настроек');
    }
    $json = json_encode($cols, JSON_UNESCAPED_UNICODE);
    if ($json === false || strlen($json) > 20000) {
        throw new RuntimeException('Некорректные данные настроек');
    }
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->prepare("INSERT INTO user_columns (user_key, form, cols) VALUES (:u,:f,:c)
                ON DUPLICATE KEY UPDATE cols=:c")->execute([':u' => wms_columns_user(), ':f' => $form, ':c' => $json]);
        } else {
            $pdo->prepare("INSERT INTO user_columns (user_key, form, cols) VALUES (:u,:f,:c)
                ON CONFLICT(user_key, form) DO UPDATE SET cols=:c, updated_at=datetime('now')")
                ->execute([':u' => wms_columns_user(), ':f' => $form, ':c' => $json]);
        }
    } catch (Throwable $e) {
        throw new RuntimeException('Не сохранилось: ' . $e->getMessage());
    }
}
