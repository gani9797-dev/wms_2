<?php
declare(strict_types=1);

require_once __DIR__ . '/EmployeeStatus.php';
require_once __DIR__ . '/EmployeeAccess.php';
require_once __DIR__ . '/EmployeeFilter.php';
require_once __DIR__ . '/EmployeeRepository.php';
require_once __DIR__ . '/../shared/pg.php';
require_once __DIR__ . '/../shared/Roles.php';

// Сервис карточки сотрудника: создание/правка, блокировка, права и роли,
// графики, история изменений, выгрузка и печать личного ШК.
final class EmployeeService
{
    public function __construct(private PDO $pdo, private EmployeeRepository $repo) {}

    /** Подписи полей — для вкладки «История изменений». */
    public const FIELD_LABELS = [
        'fio' => 'ФИО',
        'last_name' => 'Фамилия',
        'first_name' => 'Имя',
        'middle_name' => 'Отчество',
        'position' => 'Должность',
        'code' => 'Код',
        'department' => 'Отдел',
        'personnel_no' => 'Табельный номер',
        'pin' => 'PIN',
        'login' => 'Логин',
        'photo_path' => 'Фото',
        'phone_internal' => 'Телефон (внутренний)',
        'phone_mobile' => 'Мобильный телефон',
        'email' => 'Почта',
        'birth_date' => 'Дата рождения',
        'hired_at' => 'Дата приема',
        'status' => 'Статус',
        'role' => 'Роль',
        'zone' => 'Зона',
        'warehouse_id' => 'Склад',
        'tsd_access' => 'Доступ в ТСД',
        'wms_access' => 'Доступ в WMS',
        'is_blocked' => 'Заблокирован',
        'allow_unplanned_exit' => 'Внеплановый выход',
    ];

    /** Поля, которые меняются через карточку (порядок = порядок в истории). */
    private const EDITABLE = [
        'last_name', 'first_name', 'middle_name', 'position', 'code', 'department',
        'personnel_no', 'photo_path', 'phone_internal', 'phone_mobile', 'email',
        'birth_date', 'hired_at', 'status', 'role', 'zone', 'warehouse_id', 'login',
    ];

    /** Обязательные поля формы создания (в макете помечены «*»). */
    private const REQUIRED = [
        'last_name' => 'Фамилия',
        'first_name' => 'Имя',
        'middle_name' => 'Отчество',
        'personnel_no' => 'Табельный номер',
        'birth_date' => 'Дата рождения',
        'hired_at' => 'Дата приема',
        'department' => 'Отдел',
        'position' => 'Должность',
        'phone_internal' => 'Телефон (внутренний)',
        'phone_mobile' => 'Мобильный телефон',
        'email' => 'Почта',
        'warehouse_id' => 'Склад',
    ];

    /** @return int id созданного сотрудника */
    public function create(array $data, int $userId): int
    {
        $d = $this->normalize($data);
        $password = trim((string)($data['password'] ?? ''));
        $this->validate($d);
        $this->validateCredentials($d['login'], $password, true, 0);

        $now = wms_now_sql($this->pdo);
        $sql = "INSERT INTO employees
                (fio, last_name, first_name, middle_name, position, code, department,
                 personnel_no, login, password_hash, photo_path, phone_internal, phone_mobile, email,
                 birth_date, hired_at, status, role, zone, warehouse_id, tsd_access,
                 is_active, is_blocked, allow_unplanned_exit, created_at, updated_at)
                VALUES
                (:fio, :last_name, :first_name, :middle_name, :position, :code, :department,
                 :personnel_no, :login, :password_hash, :photo_path, :phone_internal, :phone_mobile, :email,
                 :birth_date, :hired_at, :status, :role, :zone, :warehouse_id, :tsd_access,
                 :is_active, :is_blocked, :allow_unplanned_exit, $now, $now)";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':fio' => $d['fio'],
            ':last_name' => $d['last_name'],
            ':first_name' => $d['first_name'],
            ':middle_name' => $d['middle_name'],
            ':position' => $d['position'],
            ':code' => $d['code'],
            ':department' => $d['department'],
            ':personnel_no' => $d['personnel_no'],
            ':login' => $d['login'],
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':photo_path' => $d['photo_path'],
            ':phone_internal' => $d['phone_internal'],
            ':phone_mobile' => $d['phone_mobile'],
            ':email' => $d['email'],
            ':birth_date' => $d['birth_date'],
            ':hired_at' => $d['hired_at'],
            ':status' => $d['status'],
            ':role' => $d['role'],
            ':zone' => $d['zone'],
            ':warehouse_id' => $d['warehouse_id'],
            ':tsd_access' => $d['tsd_access'],
            ':is_active' => 1,
            ':is_blocked' => 0,
            ':allow_unplanned_exit' => 0,
        ]);
        $id = (int)$this->pdo->lastInsertId();

        // Первая запись в истории изменений — факт создания карточки.
        $this->log($id, 'fio', 'ФИО', '—', $d['fio'], $userId);

        // Права по умолчанию: полный доступ к модулям WMS и ТСД.
        $this->saveAccess($id, $this->defaultAccess(), $userId, false);
        return $id;
    }

    /** Правка карточки с записью в историю изменений. */
    public function update(int $id, array $data, int $userId): void
    {
        $row = $this->repo->findById($id);
        if (!$row) {
            throw new RuntimeException('Сотрудник не найден');
        }
        $d = $this->normalize($data);
        // Частичное обновление: не переданные поля берём из карточки.
        $merged = [];
        foreach (self::EDITABLE as $f) {
            $in = $data[$f] ?? null;
            if ($in === null || $in === '') {
                $merged[$f] = $f === 'status' ? (string)($row['db_status'] ?? '') : (string)($row[$f] ?? '');
            }
        }
        if (isset($data['warehouse_id']) && (int)$data['warehouse_id'] > 0) {
            $merged['warehouse_id'] = (int)$data['warehouse_id'];
        }
        $d = array_merge($d, $merged);
        $this->validate($d);
        $password = trim((string)($data['password'] ?? ''));
        if ($d['login'] !== '' || $password !== '') {
            $this->validateCredentials($d['login'], $password, false, $id);
        }

        $sets = [];
        $p = [':id' => $id];
        foreach (self::EDITABLE as $f) {
            $old = (string)($row[$f] ?? '');
            $new = (string)($d[$f] ?? '');
            if ($f === 'warehouse_id') {
                $old = (string)(int)($row['warehouse_id'] ?? 0);
                $new = (string)(int)($d['warehouse_id'] ?? 0);
            }
            if ($f === 'status') {
                $old = EmployeeStatus::fromFlags((int)($row['is_blocked'] ?? 0), (string)($row['db_status'] ?? ''));
                $new = EmployeeStatus::fromFlags((int)($row['is_blocked'] ?? 0), $new);
            }
            if ($old === $new) {
                continue;
            }
            $sets[] = "$f = :$f";
            $p[':' . $f] = $new;
            $this->log($id, $f, self::FIELD_LABELS[$f] ?? $f, $old, $new, $userId);
        }
        if ($password !== '') {
            $sets[] = 'password_hash = :password_hash';
            $p[':password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $this->log($id, 'password_hash', 'Пароль', '—', 'изменён', $userId);
        }
        if ($sets) {
            $now = wms_now_sql($this->pdo);
            $sql = "UPDATE employees SET " . implode(', ', $sets) . ", updated_at = $now WHERE id = :id";
            $st = $this->pdo->prepare($sql);
            $st->execute($p);
        }
    }

    /** Блокировка / разблокировка сотрудника. */
    public function setBlocked(int $id, bool $blocked, int $userId): void
    {
        $row = $this->repo->findById($id);
        if (!$row) {
            throw new RuntimeException('Сотрудник не найден');
        }
        $was = (int)($row['is_blocked'] ?? 0) ? 1 : 0;
        if ($was === ($blocked ? 1 : 0)) {
            return;
        }
        $now = wms_now_sql($this->pdo);
        $sets = ['is_blocked = :b', 'updated_at = ' . $now];
        $p = [':b' => $blocked ? 1 : 0, ':id' => $id];
        // Разблокировка возвращает статус «Активный», если он был «Заблокирован».
        if (!$blocked && (string)($row['db_status'] ?? '') === EmployeeStatus::BLOCKED) {
            $sets[] = 'status = :st';
            $p[':st'] = EmployeeStatus::ACTIVE;
        }
        $st = $this->pdo->prepare("UPDATE employees SET " . implode(', ', $sets) . " WHERE id = :id");
        $st->execute($p);
        $this->log($id, 'is_blocked', 'Заблокирован', $was ? 'Да' : 'Нет', $blocked ? 'Да' : 'Нет', $userId);
    }

    /** «Снять ТСД» — снять/выдать доступ к терминалу сбора данных. */
    public function setTsd(int $id, bool $on, int $userId): void
    {
        $row = $this->repo->findById($id);
        if (!$row) {
            throw new RuntimeException('Сотрудник не найден');
        }
        $was = (int)($row['tsd_access'] ?? 0) ? 1 : 0;
        if ($was === ($on ? 1 : 0)) {
            return;
        }
        $now = wms_now_sql($this->pdo);
        $st = $this->pdo->prepare("UPDATE employees SET tsd_access = :t, updated_at = $now WHERE id = :id");
        $st->execute([':t' => $on ? 1 : 0, ':id' => $id]);
        $this->log($id, 'tsd_access', 'Доступ в ТСД', $was ? 'Да' : 'Нет', $on ? 'Да' : 'Нет', $userId);
    }

    /** «Разрешить внеплановый выход». */
    public function allowUnplannedExit(int $id, bool $allow, int $userId): void
    {
        $row = $this->repo->findById($id);
        if (!$row) {
            throw new RuntimeException('Сотрудник не найден');
        }
        $was = (int)($row['allow_unplanned_exit'] ?? 0) ? 1 : 0;
        if ($was === ($allow ? 1 : 0)) {
            return;
        }
        $now = wms_now_sql($this->pdo);
        $st = $this->pdo->prepare("UPDATE employees SET allow_unplanned_exit = :a, updated_at = $now WHERE id = :id");
        $st->execute([':a' => $allow ? 1 : 0, ':id' => $id]);
        $this->log($id, 'allow_unplanned_exit', 'Внеплановый выход', $was ? 'Разрешён' : 'Запрещён',
            $allow ? 'Разрешён' : 'Запрещён', $userId);
    }

    /** Удаление карточки (вместе с графиками, правами и историей). */
    public function delete(int $id, int $userId): void
    {
        $row = $this->repo->findById($id);
        if (!$row) {
            throw new RuntimeException('Сотрудник не найден');
        }
        foreach (['employee_schedules', 'employee_access', 'employee_history', 'employee_exits', 'employee_attendance'] as $t) {
            try {
                $st = $this->pdo->prepare("DELETE FROM $t WHERE employee_id = :id");
                $st->execute([':id' => $id]);
            } catch (Throwable) {
            }
        }
        try {
            $st = $this->pdo->prepare("DELETE FROM employees WHERE id = :id");
            $st->execute([':id' => $id]);
        } catch (Throwable) {
        }
    }

    /**
     * Сохранение прав и ролей.
     *  $items — список {scope, module_key, level}.
     */
    public function saveAccess(int $employeeId, array $items, int $userId, bool $log = true): void
    {
        $row = $this->repo->findById($employeeId);
        if (!$row) {
            throw new RuntimeException('Сотрудник не найден');
        }
        $before = [];
        foreach ($this->repo->access($employeeId) as $a) {
            $before[$a['scope'] . ':' . $a['module_key']] = $a['level_label'];
        }
        try {
            $st = $this->pdo->prepare("DELETE FROM employee_access WHERE employee_id = :id");
            $st->execute([':id' => $employeeId]);
        } catch (Throwable) {
        }
        $ins = $this->pdo->prepare(
            "INSERT INTO employee_access (employee_id, scope, module_key, level) VALUES (:id, :scope, :key, :level)"
        );
        foreach ($items as $it) {
            $scope = (string)($it['scope'] ?? EmployeeAccess::SCOPE_WMS);
            $key = trim((string)($it['module_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $level = EmployeeAccess::normalizeLevel((string)($it['level'] ?? ''));
            if ($scope === EmployeeAccess::SCOPE_TSD) {
                $key = EmployeeAccess::TSD_MODULE;
            }
            $ins->execute([':id' => $employeeId, ':scope' => $scope, ':key' => $key, ':level' => $level]);
        }
        if ($log) {
            $after = [];
            foreach ($this->repo->access($employeeId) as $a) {
                $after[$a['scope'] . ':' . $a['module_key']] = $a['level_label'];
            }
            $oldTxt = $before ? implode(', ', $before) : 'нет доступа';
            $newTxt = $after ? implode(', ', $after) : 'нет доступа';
            if ($oldTxt !== $newTxt) {
                $this->log($employeeId, 'wms_access', 'Права и роли', $oldTxt, $newTxt, $userId);
            }
        }
    }

    /** Копирование прав пользователя WMS сотруднику («по аналогии с пользователем»). */
    /** Справочник ролей. */
    public function roles(): array
    {
        return array_map(static fn(array $r): string => (string)$r['name'], wms_roles_list($this->pdo, true));
    }

    /** Права роли сотрудника (единые для всех сотрудников этой роли). */
    public function roleAccessForEmployee(int $employeeId): array
    {
        $row = $this->repo->findById($employeeId);
        if (!$row) {
            throw new RuntimeException('Сотрудник не найден');
        }
        $role = trim((string)($row['role'] ?? ''));
        $items = $role !== '' ? $this->repo->roleAccess($role) : [];
        if (!$items && $role !== '') {
            $items = $this->roleAccess($role);
            try {
                $this->repo->roleAccessSave($role, $items);
            } catch (Throwable) {
            }
        }
        return ['role' => $role, 'items' => $items];
    }

    /** Сохранить права роли (действуют на всех сотрудников роли). */
    public function saveRoleAccess(int $employeeId, array $items, int $userId): void
    {
        $row = $this->repo->findById($employeeId);
        if (!$row) {
            throw new RuntimeException('Сотрудник не найден');
        }
        $role = trim((string)($row['role'] ?? ''));
        if ($role === '') {
            throw new RuntimeException('Сначала укажите роль сотрудника');
        }
        $this->repo->roleAccessSave($role, $items);
        $this->log($employeeId, 'role_access', 'Права роли', '', 'Роль: ' . $role, $userId);
    }

    public function copyAccessFromUser(int $employeeId, int $sourceUserId, int $userId): void
    {
        // Права теперь ролевые: копируем профиль роли выбранного пользователя.
        $role = $this->repo->userRole($sourceUserId);
        if ($role === '') {
            throw new RuntimeException('У выбранного пользователя не указана роль');
        }
        $items = $this->repo->roleAccess($role);
        if (!$items) {
            $items = $this->roleAccess($role);
        }
        $this->saveRoleAccess($employeeId, $items, $userId);
    }

    /**
     * Профиль прав по роли — для «по аналогии с пользователем»,
     * если у пользователя WMS нет собственной карточки прав.
     */
    public function roleAccess(string $role): array
    {
        $role = trim($role);
        $items = [];
        if ($role === 'Водитель') {
            $items[] = ['scope' => EmployeeAccess::SCOPE_WMS, 'module_key' => 'reestr', 'level' => EmployeeAccess::LEVEL_FULL];
            $items[] = ['scope' => EmployeeAccess::SCOPE_WMS, 'module_key' => 'int', 'level' => EmployeeAccess::LEVEL_FULL];
            $items[] = ['scope' => EmployeeAccess::SCOPE_TSD, 'module_key' => EmployeeAccess::TSD_MODULE, 'level' => EmployeeAccess::LEVEL_NONE];
            return $items;
        }
        foreach (EmployeeAccess::WMS_MODULES as $k => $_label) {
            $items[] = ['scope' => EmployeeAccess::SCOPE_WMS, 'module_key' => $k, 'level' => EmployeeAccess::LEVEL_FULL];
        }
        $items[] = ['scope' => EmployeeAccess::SCOPE_TSD, 'module_key' => EmployeeAccess::TSD_MODULE, 'level' => EmployeeAccess::LEVEL_FULL];
        return $items;
    }

    /** Права по умолчанию: полный доступ ко всем модулям WMS и ТСД. */
    public function defaultAccess(): array
    {
        $items = [];
        foreach (EmployeeAccess::WMS_MODULES as $key => $_label) {
            $items[] = ['scope' => EmployeeAccess::SCOPE_WMS, 'module_key' => $key, 'level' => EmployeeAccess::LEVEL_FULL];
        }
        $items[] = ['scope' => EmployeeAccess::SCOPE_TSD, 'module_key' => EmployeeAccess::TSD_MODULE, 'level' => EmployeeAccess::LEVEL_FULL];
        return $items;
    }

    /** Добавление графика сотруднику. */
    public function addSchedule(int $employeeId, array $data, int $userId): int
    {
        if (!$this->repo->findById($employeeId)) {
            throw new RuntimeException('Сотрудник не найден');
        }
        $shift = trim((string)($data['shift'] ?? ''));
        $pattern = trim((string)($data['pattern'] ?? ''));
        $dateFrom = trim((string)($data['date_from'] ?? ''));
        $dateTo = trim((string)($data['date_to'] ?? ''));
        $workFrom = trim((string)($data['work_from'] ?? ''));
        $workTo = trim((string)($data['work_to'] ?? ''));
        if ($shift === '') {
            throw new RuntimeException('Укажите смену');
        }
        if ($pattern === '') {
            throw new RuntimeException('Укажите график');
        }
        if ($dateFrom === '' || $dateTo === '') {
            throw new RuntimeException('Укажите начало и конец графика');
        }
        if ($workFrom === '' || $workTo === '') {
            throw new RuntimeException('Укажите рабочее время');
        }
        $now = wms_now_sql($this->pdo);
        $st = $this->pdo->prepare(
            "INSERT INTO employee_schedules
                (employee_id, date_from, date_to, pattern, shift, work_from, work_to, created_at)
             VALUES (:id, :df, :dt, :pat, :shift, :wf, :wt, $now)"
        );
        $st->execute([
            ':id' => $employeeId, ':df' => $dateFrom, ':dt' => $dateTo,
            ':pat' => $pattern, ':shift' => $shift, ':wf' => $workFrom, ':wt' => $workTo,
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $this->log($employeeId, 'schedule', 'График', '—',
            $dateFrom . ' - ' . $dateTo . ', ' . $pattern . ', ' . $shift, $userId);
        return $id;
    }

    /** Удаление графика сотрудника. */
    public function deleteSchedule(int $scheduleId, int $userId): void
    {
        $empId = 0;
        try {
            $st = $this->pdo->prepare("SELECT employee_id FROM employee_schedules WHERE id = :id");
            $st->execute([':id' => $scheduleId]);
            $empId = (int)$st->fetchColumn();
        } catch (Throwable) {
        }
        if ($empId <= 0) {
            throw new RuntimeException('График не найден');
        }
        $st = $this->pdo->prepare("DELETE FROM employee_schedules WHERE id = :id");
        $st->execute([':id' => $scheduleId]);
        $this->log($empId, 'schedule', 'График', 'удалён', '—', $userId);
    }

    /** Данные для печати личного ШК. */
    public function printData(int $id): array
    {
        $row = $this->repo->findById($id);
        if (!$row) {
            throw new RuntimeException('Сотрудник не найден');
        }
        $code = trim((string)($row['personnel_no'] ?? ''));
        if ($code === '') {
            $code = (string)($row['id'] ?? '');
        }
        return [
            'id' => (int)$row['id'],
            'code' => $code,
            'name' => (string)($row['fio'] ?? ''),
            'position' => (string)($row['position'] ?? ''),
            'department' => (string)($row['department'] ?? ''),
        ];
    }

    /** Выгрузка реестра в CSV. */
    public function exportCsv(EmployeeFilter $f, EmployeeRepository $repo): string
    {
        $f->page = 1;
        $f->perPage = 5000;
        $res = $repo->paginate($f);
        $out = "\xEF\xBB\xBF";
        $out .= "ID_user;ФИО;Должность;Код;Отдел;Статус;Смена;График;Рабочее время;"
            . "Начало;Конец;Работает;ТСД;Отработано;Внеплановый выход;Заблокирован\r\n";
        foreach ($res['rows'] as $r) {
            $cells = [
                (string)($r['id'] ?? ''),
                (string)($r['fio'] ?? ''),
                (string)($r['position'] ?? ''),
                (string)($r['code'] ?? ''),
                (string)($r['department'] ?? ''),
                (string)($r['status_label'] ?? ''),
                (string)($r['cur_shift'] ?? ''),
                (string)($r['cur_pattern'] ?? ''),
                (string)($r['work_time'] ?? ''),
                (string)($r['start_at'] ?? ''),
                (string)($r['end_at'] ?? ''),
                $this->workedText((int)($r['worked_minutes'] ?? 0)),
                ((int)($r['tsd_access'] ?? 0)) ? 'да' : 'нет',
                ((int)($r['worked_minutes'] ?? 0) > 0) ? 'да' : 'нет',
                ((int)($r['allow_unplanned_exit'] ?? 0)) ? 'разрешён' : 'запрещён',
                ((int)($r['is_blocked'] ?? 0)) ? 'да' : 'нет',
            ];
            $out .= implode(';', array_map([$this, 'csvCell'], $cells)) . "\r\n";
        }
        return $out;
    }

    /** «2 ч 11 мин» */
    public function workedText(int $minutes): string
    {
        return $this->repo->workedText($minutes);
    }

    /** Значения по умолчанию для формы создания. */
    public function defaults(): array
    {
        return [
            'departments' => $this->repo->departments(),
            'positions' => $this->repo->positions(),
            'warehouses' => $this->repo->warehouses(),
            'statuses' => array_map(
                fn(string $s) => ['value' => $s, 'label' => EmployeeStatus::label($s)],
                EmployeeStatus::values()
            ),
            'zones' => ['Все зоны'],
            'roles' => array_map(
                static fn(array $r): string => (string)$r['name'],
                wms_roles_list($this->pdo, true)
            ),
        ];
    }

    /** CSV-экранирование. */
    public function csvCell($v): string
    {
        $s = (string)$v;
        if (strpbrk($s, ";\r\n") !== false) {
            return '"' . str_replace('"', '""', $s) . '"';
        }
        return $s;
    }

    /** Приводит входные данные к набору полей карточки. */
    private function normalize(array $d): array
    {
        $s = fn($k) => trim((string)($d[$k] ?? ''));
        $last = $s('last_name');
        $first = $s('first_name');
        $middle = $s('middle_name');
        $fio = trim($last . ' ' . $first . ' ' . $middle);
        if ($fio === '') {
            $fio = $s('fio');
        }
        $status = $s('status');
        if (!in_array($status, EmployeeStatus::values(), true)) {
            $status = EmployeeStatus::ACTIVE;
        }
        return [
            'fio' => $fio,
            'last_name' => $last,
            'first_name' => $first,
            'middle_name' => $middle,
            'position' => $s('position'),
            'code' => $s('code'),
            'department' => $s('department'),
            'personnel_no' => $s('personnel_no'),
            'login' => $s('login'),
            'photo_path' => $s('photo_path'),
            'phone_internal' => $s('phone_internal'),
            'phone_mobile' => $s('phone_mobile'),
            'email' => $s('email'),
            'birth_date' => $s('birth_date'),
            'hired_at' => $s('hired_at'),
            'status' => $status,
            'role' => $s('role') !== '' ? $s('role') : 'Кладовщик',
            'zone' => $s('zone') !== '' ? $s('zone') : 'Все зоны',
            'warehouse_id' => (int)($d['warehouse_id'] ?? 0) ?: null,
            'tsd_access' => !empty($d['tsd_access']) ? 1 : 0,
        ];
    }

    /** Проверка обязательных полей формы. */
    private function validate(array $d): void
    {
        foreach (self::REQUIRED as $f => $label) {
            $v = $d[$f] ?? null;
            if ($v === null || $v === '' || $v === 0) {
                throw new RuntimeException('Заполните поле «' . $label . '»');
            }
        }
    }

    /** Проверка логина/пароля учётной записи. */
    private function validateCredentials(string $login, string $password, bool $isCreate, int $id): void
    {
        $login = trim($login);
        if ($login === '') {
            throw new RuntimeException('Укажите логин для входа');
        }
        if (function_exists('mb_strlen') ? mb_strlen($login, 'UTF-8') > 100 : strlen($login) > 100) {
            throw new RuntimeException('Логин — до 100 символов');
        }
        if ($isCreate && $password === '') {
            throw new RuntimeException('Укажите пароль для входа');
        }
        if ($password !== '') {
            if (function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') < 4 : strlen($password) < 4) {
                throw new RuntimeException('Пароль — не менее 4 символов');
            }
        }
        try {
            $st = $this->pdo->prepare('SELECT id FROM employees WHERE LOWER(login) = LOWER(:l) AND id <> :id LIMIT 1');
            $st->execute([':l' => $login, ':id' => $id]);
            if ($st->fetchColumn()) {
                throw new RuntimeException('Логин уже занят');
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable) {
            // если колонка ещё не дотянулась — ошибку уникальности поймает БД
        }
    }

    /** Запись в историю изменений. */
    private function log(int $employeeId, string $field, string $label, ?string $old, ?string $new, int $userId): void
    {
        try {
            $now = wms_now_sql($this->pdo);
            $st = $this->pdo->prepare(
                "INSERT INTO employee_history
                    (employee_id, user_id, user_fio, field, field_label, old_value, new_value, created_at)
                 VALUES (:id, :uid, :ufio, :f, :fl, :o, :n, $now)"
            );
            $st->execute([
                ':id' => $employeeId,
                ':uid' => $userId > 0 ? $userId : null,
                ':ufio' => $this->userFio($userId),
                ':f' => $field,
                ':fl' => $label,
                ':o' => (string)($old ?? ''),
                ':n' => (string)($new ?? ''),
            ]);
        } catch (Throwable) {
        }
    }

    /** ФИО пользователя, изменившего карточку. */
    private function userFio(int $userId): string
    {
        if ($userId <= 0) {
            return 'Система';
        }
        try {
            $st = $this->pdo->prepare("SELECT fio FROM users WHERE id = :id");
            $st->execute([':id' => $userId]);
            $fio = trim((string)$st->fetchColumn());
            if ($fio !== '') {
                return $fio;
            }
        } catch (Throwable) {
        }
        return 'Пользователь #' . $userId;
    }
}
