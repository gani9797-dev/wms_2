<?php
declare(strict_types=1);
// Демо «Сотрудники» без MySQL: SQLite + те же классы реестра.
// Открыть: /10-sotrudniki/demo_sqlite.php?action=list
// Вкладки: ?action=show|schedules|access|history|exits&id=1
// ПКМ: POST ?action=store|update|block|unblock|tsd|unplanned|access_save|schedule_add&id=1
// Печать личного ШК: ?action=print&id=1

require_once __DIR__ . '/EmployeeStatus.php';
require_once __DIR__ . '/EmployeeAccess.php';
require_once __DIR__ . '/EmployeeFilter.php';
require_once __DIR__ . '/EmployeeRepository.php';
require_once __DIR__ . '/EmployeeService.php';
require_once __DIR__ . '/EmployeeController.php';

require_once __DIR__ . '/../shared/db.php';

$pdo = wms_employees_pdo();
$userId = 1;
$isSqlite = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';

if ($isSqlite) {
    // Склады — справочник модуля (в PostgreSQL он общий).
    $pdo->exec("CREATE TABLE IF NOT EXISTS warehouses (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)");
}

// --- Сид из макета 10, если реестр пуст ---
if ((int)$pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn() === 0) {
    if ($isSqlite) {
        $pdo->exec("INSERT INTO warehouses (id,name) VALUES (1,'Основной склад')");
    }

    // [id, фамилия, имя, отчество, должность, код, отдел, таб.номер, д.рождения, д.приема,
    //  тел.внутр, тел.моб, почта, статус, роль, склад, TСД, заблокирован]
    $rows = [
        [1289, 'Иванов', 'Алексей', 'Владимирович', 'Начальник смены', 'НС', 'Транспортная логистика', '2355895412', '1987-02-01', '2020-02-22', '120', '8(967)967-67*67', 'a.ivanov@timlog.ru', 'active', 'Администратор', 1, 1, 0],
        [589, 'Петров', 'Михаил', 'Андреевич', 'Кладовщик', 'К', 'Склад', '589-2021', '1985-06-15', '2021-03-03', '121', '8(903)111-22-33', 'm.petrov@timlog.ru', 'active', 'Кладовщик', 1, 1, 0],
        [127, 'Сидорова', 'Мария', 'Игоревна', 'Упаковщица', 'У', 'Комплектация', '127-2022', '1998-11-30', '2022-09-12', '133', '8(911)555-77-88', 'm.sidorova@timlog.ru', 'active', 'Кладовщик', 1, 0, 0],
        [1190, 'Кузнецов', 'Дмитрий', 'Олегович', 'Штаберел', 'Ш', 'Склад', '1190-2021', '1992-04-05', '2021-05-17', '145', '8(926)444-11-22', 'd.kuznetsov@timlog.ru', 'active', 'Кладовщик', 1, 0, 0],
        [57, 'Волков', 'Сергей', 'Петрович', 'Сборщик', 'С', 'Комплектация', '57-2023', '1995-09-21', '2023-02-01', '156', '8(915)333-44-55', 's.volkov@timlog.ru', 'active', 'Кладовщик', 1, 0, 1],
        [1287, 'Феничев', 'Олег', 'Шамильевич', 'Кладовщик', 'Ф', 'Склад', '1287-2020', '1990-07-19', '2020-11-02', '112', '8(905)210-30-40', 'o.fenichev@timlog.ru', 'active', 'Кладовщик', 1, 1, 0],
        [1220, 'Михайлова', 'Ольга', 'Сергеевна', 'Кладовщик', 'М', 'Склад', '1220-2022', '1994-03-11', '2022-01-17', '117', '8(917)101-20-30', 'o.mikhaylova@timlog.ru', 'active', 'Кладовщик', 1, 0, 0],
        [1465, 'Ионова', 'Мария', 'Ивановна', 'Приемщик', 'И', 'Приемка', '1465-2019', '1991-12-08', '2019-06-10', '104', '8(906)505-60-70', 'm.ionova@timlog.ru', 'active', 'Приемщик', 1, 1, 0],
        [3315, 'Иванов', 'Павел', 'Сергеевич', 'Водитель', 'В', 'Транспортная логистика', '3315-2021', '1983-08-25', '2021-08-30', '107', '8(962)707-80-90', 'p.ivanov@timlog.ru', 'active', 'Водитель', 1, 0, 0],
        [512, 'Иванов', 'Михаил', 'Юрьевич', 'Кладовщик', 'И', 'Склад', '512-2018', '1980-02-17', '2018-04-02', '109', '8(909)909-10-20', 'm.ivanov@timlog.ru', 'active', 'Кладовщик', 1, 0, 0],
        [258, 'Смирнов', 'Алексей', 'Сергеевич', 'Менеджер', 'С', 'Продажи', '258-2020', '1996-05-09', '2020-10-05', '138', '8(985)111-30-40', 'a.smirnov@timlog.ru', 'active', 'Менеджер', 1, 0, 0],
        [1788, 'Смирнов', 'Николай', 'Петрович', 'Менеджер', 'С', 'Продажи', '1788-2022', '1993-01-30', '2022-07-11', '139', '8(925)222-30-40', 'n.smirnov@timlog.ru', 'active', 'Менеджер', 1, 0, 0],
        [819, 'Шветцов', 'Алексей', 'Викторович', 'Кладовщик', 'Ш', 'Склад', '819-2017', '1979-10-14', '2017-02-20', '123', '8(903)333-40-50', 'a.shvetsov@timlog.ru', 'fired', 'Кладовщик', 1, 0, 0],
        [233, 'Петрова', 'Марина', 'Алексеевна', 'Оператор', 'П', 'Комплектация', '233-2023', '2000-06-06', '2023-05-15', '128', '8(926)444-50-60', 'm.petrova@timlog.ru', 'active', 'Кладовщик', 1, 0, 0],
    ];
    $now = date('Y-m-d H:i:s');
    $ins = $pdo->prepare(
        "INSERT INTO employees
            (id, fio, last_name, first_name, middle_name, position, code, department, personnel_no,
             photo_path, phone_internal, phone_mobile, email, birth_date, hired_at,
             status, role, zone, warehouse_id, tsd_access, is_active, is_blocked, allow_unplanned_exit,
             created_at, updated_at)
         VALUES
            (:id, :fio, :last_name, :first_name, :middle_name, :position, :code, :department, :personnel_no,
             NULL, :phone_internal, :phone_mobile, :email, :birth_date, :hired_at,
             :status, :role, 'Все зоны', :warehouse_id, :tsd_access, 1, :is_blocked, 0,
             :created_at, :updated_at)"
    );
    foreach ($rows as $r) {
        $ins->execute([
            ':id' => $r[0],
            ':fio' => $r[1] . ' ' . $r[2] . ' ' . $r[3],
            ':last_name' => $r[1], ':first_name' => $r[2], ':middle_name' => $r[3],
            ':position' => $r[4], ':code' => $r[5], ':department' => $r[6], ':personnel_no' => $r[7],
            ':phone_internal' => $r[10], ':phone_mobile' => $r[11], ':email' => $r[12],
            ':birth_date' => $r[8], ':hired_at' => $r[9],
            ':status' => $r[13], ':role' => $r[14], ':warehouse_id' => $r[15],
            ':tsd_access' => $r[16], ':is_blocked' => $r[17],
            ':created_at' => $now, ':updated_at' => $now,
        ]);
    }

    // --- Графики (вкладка «График») ---
    $sch = $pdo->prepare(
        "INSERT INTO employee_schedules (employee_id, date_from, date_to, pattern, shift, work_from, work_to)
         VALUES (:id, :df, :dt, :pat, :shift, :wf, :wt)"
    );
    $schedules = [
        [1289, '2024-09-01', '2024-09-30', '1/3', '1 смена', '08:00', '08:00'],
        [1289, '2024-01-01', '2024-08-31', '1/3', '4 смена', '08:00', '08:00'],
        [589, '2024-09-01', '2024-12-31', '2/2', '1 смена', '08:00', '20:00'],
        [589, '2024-01-01', '2024-08-31', '2/2', '3 смена', '08:00', '16:00'],
        [127, '2024-09-01', '2024-12-31', '5/2', '2 смена', '09:00', '18:00'],
        [1190, '2024-09-01', '2024-12-31', '2/2', '2 смена', '20:00', '08:00'],
        [57, '2024-09-01', '2024-12-31', '2/2', '1 смена', '08:00', '20:00'],
    ];
    foreach ($schedules as $s) {
        $sch->execute([
            ':id' => $s[0], ':df' => $s[1], ':dt' => $s[2], ':pat' => $s[3],
            ':shift' => $s[4], ':wf' => $s[5], ':wt' => $s[6],
        ]);
    }

    // --- Права и роли (вкладка «Права и роли») ---
    $acc = $pdo->prepare(
        "INSERT INTO employee_access (employee_id, scope, module_key, level) VALUES (:id, :scope, :key, :level)"
    );
    $full = array_keys(EmployeeAccess::WMS_MODULES);
    foreach ($rows as $r) {
        $empId = $r[0];
        $limited = $empId === 57; // заблокированный сотрудник — ограниченный доступ
        foreach ($full as $k) {
            $level = ($limited && in_array($k, ['otg', 'inv'], true))
                ? EmployeeAccess::LEVEL_NONE
                : EmployeeAccess::LEVEL_FULL;
            $acc->execute([':id' => $empId, ':scope' => EmployeeAccess::SCOPE_WMS, ':key' => $k, ':level' => $level]);
        }
        $acc->execute([
            ':id' => $empId, ':scope' => EmployeeAccess::SCOPE_TSD,
            ':key' => EmployeeAccess::TSD_MODULE,
            ':level' => $limited ? EmployeeAccess::LEVEL_NONE : EmployeeAccess::LEVEL_FULL,
        ]);
    }

    // --- Присутствие за смену (Начало/Конец/Работает/Отработано) ---
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $att = $pdo->prepare(
        "INSERT INTO employee_attendance (employee_id, work_date, start_at, end_at, worked_minutes, on_shift)
         VALUES (:id, :wd, :sa, :ea, :wm, :os)"
    );
    $attendance = [
        [1289, $today, '07:49', null, 131, 1],
        [589, $today, '07:29', null, 151, 1],
        [127, $today, '08:52', null, 68, 0],
        [1190, $today, '19:50', '08:01', 731, 0],
        [1289, $yesterday, '07:52', '16:01', 489, 0],
        [589, $yesterday, '07:30', '16:02', 512, 0],
    ];
    foreach ($attendance as $a) {
        $att->execute([
            ':id' => $a[0], ':wd' => $a[1], ':sa' => $a[2], ':ea' => $a[3],
            ':wm' => $a[4], ':os' => $a[5],
        ]);
    }

    // --- История выходов по СКУД (вкладка «История выходов») ---
    $ex = $pdo->prepare(
        "INSERT INTO employee_exits (employee_id, event_at, checkpoint, direction) VALUES (:id, :at, :cp, :dir)"
    );
    $exits = [
        [1289, '2023-03-28 20:08:00', '125890', 'exit'],
        [1289, '2023-03-28 09:27:00', '125890', 'in'],
        [1289, '2023-03-27 21:30:00', '125889', 'exit'],
        [1289, '2023-03-27 11:10:00', '125889', 'in'],
        [1289, '2023-03-26 20:20:00', '125890', 'exit'],
        [1289, '2023-03-26 08:19:00', '125890', 'in'],
        [1289, '2023-03-25 19:11:00', '125889', 'exit'],
        [1289, '2023-03-25 11:27:00', '125889', 'in'],
    ];
    foreach ($exits as $e) {
        $ex->execute([':id' => $e[0], ':at' => $e[1], ':cp' => $e[2], ':dir' => $e[3]]);
    }

    // --- История изменений (вкладка «История изменений») ---
    $his = $pdo->prepare(
        "INSERT INTO employee_history (employee_id, user_id, user_fio, field, field_label, old_value, new_value, created_at)
         VALUES (:id, :uid, :ufio, :f, :fl, :o, :n, :at)"
    );
    $history = [
        [1289, 1, 'Иванов И.И.', 'position', 'Должность', 'Кладовщик', 'Начальник смены', '2023-03-25 11:27:00'],
        [1289, 1, 'Иванов И.И.', 'zone', 'Зона', 'Зона 1', 'Все зоны', '2023-03-26 08:19:00'],
        [1289, 1, 'Иванов И.И.', 'wms_access', 'Права и роли', 'нет доступа', 'Полный доступ', '2023-03-27 11:10:00'],
        [589, 1, 'Иванов И.И.', 'department', 'Отдел', 'Комплектация', 'Склад', '2023-03-28 09:27:00'],
    ];
    foreach ($history as $h) {
        $his->execute([
            ':id' => $h[0], ':uid' => $h[1], ':ufio' => $h[2], ':f' => $h[3], ':fl' => $h[4],
            ':o' => $h[5], ':n' => $h[6], ':at' => $h[7],
        ]);
    }
}

$repo = new EmployeeRepository($pdo);
$service = new EmployeeService($pdo, $repo);
$controller = new EmployeeController($repo, $service, $userId);

$action = (string)($_GET['action'] ?? 'list');
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$id = (int)($_GET['id'] ?? 0);
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

try {
    if ($action === 'list') {
        $controller->index($_GET);
    } elseif ($action === 'show') {
        $controller->show($id);
    } elseif ($action === 'store' && $isPost) {
        $controller->store($body);
    } elseif ($action === 'update' && $isPost) {
        $controller->update($id, $body);
    } elseif ($action === 'delete' && $isPost) {
        $controller->delete($id);
    } elseif ($action === 'block' && $isPost) {
        $controller->block($id);
    } elseif ($action === 'unblock' && $isPost) {
        $controller->unblock($id);
    } elseif ($action === 'tsd' && $isPost) {
        $controller->tsd($id, $body);
    } elseif ($action === 'unplanned' && $isPost) {
        $controller->unplanned($id, $body);
    } elseif ($action === 'access') {
        $controller->access($id);
    } elseif ($action === 'access_save' && $isPost) {
        $controller->accessSave($id, $body);
    } elseif ($action === 'access_copy' && $isPost) {
        $controller->accessCopy($id, $body);
    } elseif ($action === 'schedules') {
        $controller->schedules($id);
    } elseif ($action === 'schedule_add' && $isPost) {
        $controller->scheduleAdd($id, $body);
    } elseif ($action === 'schedule_delete' && $isPost) {
        $controller->scheduleDelete((int)($body['schedule_id'] ?? $_GET['schedule_id'] ?? 0));
    } elseif ($action === 'history') {
        $controller->history($id);
    } elseif ($action === 'exits') {
        $controller->exits($id);
    } elseif ($action === 'defaults') {
        $controller->defaults();
    } elseif ($action === 'print') {
        $controller->print($id, $_GET);
    } elseif ($action === 'export') {
        $controller->export($_GET);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'unknown action, use ?action=list|show|schedules|access|history|exits&id=1'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
