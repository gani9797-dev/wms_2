<?php
declare(strict_types=1);

require_once __DIR__ . '/EmployeeStatus.php';
require_once __DIR__ . '/EmployeeFilter.php';
require_once __DIR__ . '/EmployeeAccess.php';
require_once __DIR__ . '/../shared/pg.php';

// Репозиторий реестра «Сотрудники». PDO, работает и с MySQL, и с SQLite (демо).
//  1. Поиск (ID_user, ФИО, должность, код) -> WHERE LIKE
//  2. Фильтры панели -> AND-условия
//  3. COUNT(*) для «Показано X–Y из Z»
//  4. Сортировка + LIMIT/OFFSET
//  5. active_filters для бейджа «Фильтры (N)».
final class EmployeeRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return array{rows: array, total: int, from: int, to: int, active_filters: int} */
    public function paginate(EmployeeFilter $f): array
    {
        [$where, $params] = $this->buildWhere($f);
        $cnt = $this->pdo->prepare("SELECT COUNT(*) FROM employees e $where");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $offset = ($f->page - 1) * $f->perPage;
        $sortMap = [
            'id' => ['e.id', 'n'], 'fio' => ['e.fio', 't'],
            'position' => ['e.position', 't'], 'code' => ['e.code', 't'],
            'department' => ['e.department', 't'],
            'status' => ['(CASE WHEN e.is_blocked = 1 THEN 2 WHEN e.status = \'fired\' THEN 1 ELSE 0 END)', 'n'],
            'hired_at' => ['e.hired_at', 'd'], 'created_at' => ['e.created_at', 'd'],
            'updated_at' => ['e.updated_at', 'd'], 'shift' => ['cur_shift', 't'],
        ];
        $orderBy = wms_order_by($this->pdo, $f->sort, $f->dir, $sortMap, 'e.fio', 't');
        $sql = "SELECT e.*,
                  s.shift AS cur_shift, s.work_from AS cur_work_from, s.work_to AS cur_work_to,
                  s.pattern AS cur_pattern, s.date_from AS cur_from, s.date_to AS cur_to,
                  a.start_at, a.end_at, a.worked_minutes, a.on_shift, a.work_date,
                  (SELECT COUNT(*) FROM employee_access ea
                    WHERE ea.employee_id = e.id AND ea.scope = 'wms' AND ea.level <> 'none') AS wms_modules
                FROM employees e
                LEFT JOIN employee_schedules s ON s.id = (
                    SELECT s2.id FROM employee_schedules s2
                     WHERE s2.employee_id = e.id ORDER BY s2.date_from DESC, s2.id DESC LIMIT 1)
                LEFT JOIN employee_attendance a ON a.employee_id = e.id AND a.work_date = " . $this->pdo->quote($this->today()) . "
                $where
                ORDER BY $orderBy LIMIT :lim OFFSET :off";
        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->bindValue(':lim', $f->perPage, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['status'] = EmployeeStatus::fromFlags((int)($r['is_blocked'] ?? 0), (string)($r['status'] ?? ''));
            $r['status_label'] = EmployeeStatus::label($r['status']);
            $r['tsd_checked'] = (int)($r['tsd_access'] ?? 0) ? 1 : 0;
            $r['worked'] = ((int)($r['worked_minutes'] ?? 0) > 0) ? 1 : 0;
            $r['on_shift'] = (int)($r['on_shift'] ?? 0) ? 1 : 0;
            $r['worked_text'] = $this->workedText((int)($r['worked_minutes'] ?? 0));
            $r['work_time'] = $this->workTime((string)($r['cur_work_from'] ?? ''), (string)($r['cur_work_to'] ?? ''));
        }
        unset($r);
        $from = $total === 0 ? 0 : $offset + 1;
        $to = min($offset + $f->perPage, $total);
        return ['rows' => $rows, 'total' => $total, 'from' => $from, 'to' => $to,
                'active_filters' => $f->activeCount()];
    }

    /** Карточка сотрудника (шапка + сводные поля). */
    public function findById(int $id): ?array
    {
        $st = $this->pdo->prepare(
            "SELECT e.*,
                    (SELECT COUNT(*) FROM employee_access ea
                      WHERE ea.employee_id = e.id AND ea.scope = 'wms' AND ea.level <> 'none') AS wms_modules
               FROM employees e WHERE e.id = :id"
        );
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $rawStatus = (string)($row['status'] ?? '');
        $row['status'] = EmployeeStatus::fromFlags((int)($row['is_blocked'] ?? 0), $rawStatus);
        $row['status_label'] = EmployeeStatus::label($row['status']);
        $row['db_status'] = $rawStatus;
        $row['experience'] = $this->experience((string)($row['hired_at'] ?? ''));
        return $row;
    }

    /** Права сотрудника: список {scope, module_key, module_label, level, level_label}. */
    public function access(int $employeeId): array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT scope, module_key, level FROM employee_access WHERE employee_id = :id ORDER BY scope, module_key"
            );
            $st->execute([':id' => $employeeId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $rows = [];
        }
        $out = [];
        foreach ($rows as $r) {
            $scope = (string)($r['scope'] ?? EmployeeAccess::SCOPE_WMS);
            $key = (string)($r['module_key'] ?? '');
            $level = EmployeeAccess::normalizeLevel((string)($r['level'] ?? ''));
            $out[] = [
                'scope' => $scope,
                'module_key' => $key,
                'module_label' => EmployeeAccess::moduleLabel($scope, $key),
                'level' => $level,
                'level_label' => EmployeeAccess::levelLabel($level),
            ];
        }
        return $out;
    }

    /** Права роли: единые для всех сотрудников с этой ролью. */
    public function roleAccess(string $role): array
    {
        $role = trim($role);
        if ($role === '') {
            return [];
        }
        try {
            $st = $this->pdo->prepare(
                "SELECT scope, module_key, level FROM role_access WHERE role = :r ORDER BY scope, module_key"
            );
            $st->execute([':r' => $role]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $rows = [];
        }
        $out = [];
        foreach ($rows as $r) {
            $scope = (string)($r['scope'] ?? EmployeeAccess::SCOPE_WMS);
            $key = (string)($r['module_key'] ?? '');
            $level = EmployeeAccess::normalizeLevel((string)($r['level'] ?? ''));
            $out[] = [
                'scope' => $scope,
                'module_key' => $key,
                'module_label' => EmployeeAccess::moduleLabel($scope, $key),
                'level' => $level,
                'level_label' => EmployeeAccess::levelLabel($level),
            ];
        }
        return $out;
    }

    /** Сохранить права роли (заменяет набор целиком). */
    public function roleAccessSave(string $role, array $items): void
    {
        $role = trim($role);
        if ($role === '') {
            throw new RuntimeException('Не указана роль');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("DELETE FROM role_access WHERE role = :r")->execute([':r' => $role]);
            $st = $this->pdo->prepare(
                "INSERT INTO role_access (role, scope, module_key, level) VALUES (:r, :sc, :mk, :lv)"
            );
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $key = trim((string)($it['module_key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $scope = (string)($it['scope'] ?? EmployeeAccess::SCOPE_WMS);
                if ($scope === EmployeeAccess::SCOPE_TSD) {
                    $key = EmployeeAccess::TSD_MODULE;
                }
                $st->execute([
                    ':r' => $role, ':sc' => $scope, ':mk' => $key,
                    ':lv' => EmployeeAccess::normalizeLevel((string)($it['level'] ?? '')),
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Графики сотрудника (вкладка «График»). */
    public function schedules(int $employeeId): array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT * FROM employee_schedules WHERE employee_id = :id ORDER BY date_from DESC, id DESC"
            );
            $st->execute([':id' => $employeeId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    /** История изменений карточки (вкладка «История изменений»). */
    public function history(int $employeeId): array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT * FROM employee_history WHERE employee_id = :id ORDER BY created_at DESC, id DESC LIMIT 200"
            );
            $st->execute([':id' => $employeeId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    /** История выходов по СКУД (вкладка «История выходов»). */
    public function exits(int $employeeId): array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT * FROM employee_exits WHERE employee_id = :id ORDER BY event_at DESC, id DESC LIMIT 200"
            );
            $st->execute([':id' => $employeeId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    /** Отделы и должности — для выпадающих списков фильтров и формы. */
    public function departments(): array
    {
        return $this->distinct('department');
    }

    public function positions(): array
    {
        return $this->distinct('position');
    }

    /** Склады (для фильтра и формы). */
    public function warehouses(): array
    {
        try {
            return $this->pdo->query("SELECT id, name FROM warehouses ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    /** Пользователи WMS — источник для «добавить по аналогии с пользователем». */
    public function users(): array
    {
        try {
            return $this->pdo->query("SELECT id, fio, role, position FROM users ORDER BY fio LIMIT 500")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    /** Права пользователя WMS — чтобы скопировать их сотруднику. */
    public function userAccess(int $userId): array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT scope, module_key, level FROM employee_access WHERE employee_id = :id ORDER BY scope, module_key"
            );
            $st->execute([':id' => $userId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    /** Роль пользователя WMS. */
    public function userRole(int $userId): string
    {
        try {
            $st = $this->pdo->prepare("SELECT role FROM users WHERE id = :id");
            $st->execute([':id' => $userId]);
            return trim((string)$st->fetchColumn());
        } catch (Throwable) {
            return '';
        }
    }

    /** Стаж в компании человекочитаемой строкой: «4 года и 3 месяца». */
    public function experience(string $hiredAt): string
    {
        if (trim($hiredAt) === '') {
            return '—';
        }
        try {
            $from = new DateTimeImmutable(substr($hiredAt, 0, 10));
        } catch (Throwable) {
            return '—';
        }
        $now = new DateTimeImmutable('today');
        if ($from > $now) {
            return '—';
        }
        $diff = $from->diff($now);
        $y = (int)$diff->y;
        $m = (int)$diff->m;
        $parts = [];
        if ($y > 0) {
            $parts[] = $y . ' ' . self::plural($y, 'год', 'года', 'лет');
        }
        if ($m > 0 || $y === 0) {
            $parts[] = $m . ' ' . self::plural($m, 'месяц', 'месяца', 'месяцев');
        }
        if (!$parts) {
            $parts[] = 'менее месяца';
        }
        return implode(' и ', $parts);
    }

    public static function plural(int $n, string $one, string $few, string $many): string
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return $many;
        }
        if ($n1 > 1 && $n1 < 5) {
            return $few;
        }
        if ($n1 === 1) {
            return $one;
        }
        return $many;
    }

    /** «08:00 - 20:00» из двух полей времени. */
    public function workTime(string $from, string $to): string
    {
        $f = $this->hm($from);
        $t = $this->hm($to);
        if ($f === '' && $t === '') {
            return '';
        }
        return ($f !== '' ? $f : '—') . ' - ' . ($t !== '' ? $t : '—');
    }

    /** «2 ч 11 мин» — отработанное за смену время. */
    public function workedText(int $minutes): string
    {
        if ($minutes <= 0) {
            return '';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        $parts = [];
        if ($h > 0) {
            $parts[] = $h . ' ' . self::plural($h, 'час', 'часа', 'часов');
        }
        if ($m > 0) {
            $parts[] = $m . ' ' . self::plural($m, 'мин', 'мин', 'мин');
        }
        return implode(' ', $parts);
    }

    /** Текущая дата БД (YYYY-MM-DD) — для присутствия и графиков. */
    public function today(): string
    {
        return (new DateTimeImmutable('today'))->format('Y-m-d');
    }

    /** @return array{0:string,1:array} */
    private function buildWhere(EmployeeFilter $f): array
    {
        $w = ['1=1'];
        $p = [];
        if ($f->search !== null) {
            $w[] = '(e.fio LIKE :q OR e.position LIKE :q OR e.code LIKE :q OR CAST(e.id AS TEXT) = :qs OR CAST(e.id AS TEXT) LIKE :qs)';
            $p[':q'] = '%' . $f->search . '%';
            $p[':qs'] = $f->search;
        }
        if ($f->createdFrom) { $w[] = 'e.created_at >= :cf'; $p[':cf'] = $f->createdFrom . ' 00:00:00'; }
        if ($f->createdTo)   { $w[] = 'e.created_at <= :ct'; $p[':ct'] = $f->createdTo . ' 23:59:59'; }
        if ($f->updatedFrom) { $w[] = 'e.updated_at >= :uf'; $p[':uf'] = $f->updatedFrom . ' 00:00:00'; }
        if ($f->updatedTo)   { $w[] = 'e.updated_at <= :ut'; $p[':ut'] = $f->updatedTo . ' 23:59:59'; }
        if ($f->position)   { $w[] = 'e.position = :pos'; $p[':pos'] = $f->position; }
        if ($f->department) { $w[] = 'e.department = :dep'; $p[':dep'] = $f->department; }
        if ($f->warehouseId) { $w[] = 'e.warehouse_id = :wh'; $p[':wh'] = $f->warehouseId; }
        if ($f->status === 'active')  { $w[] = 'e.is_blocked = 0 AND e.status <> \'fired\''; }
        elseif ($f->status === 'blocked') { $w[] = 'e.is_blocked = 1'; }
        elseif ($f->status === 'fired')   { $w[] = 'e.status = \'fired\''; }
        if ($f->shiftFrom) {
            $w[] = 'EXISTS (SELECT 1 FROM employee_attendance x WHERE x.employee_id = e.id AND x.work_date >= :sf)';
            $p[':sf'] = $f->shiftFrom;
        }
        if ($f->shiftTo) {
            $w[] = 'EXISTS (SELECT 1 FROM employee_attendance x WHERE x.employee_id = e.id AND x.work_date <= :st)';
            $p[':st'] = $f->shiftTo;
        }
        if ($f->hiredFrom) { $w[] = 'e.hired_at >= :hf'; $p[':hf'] = $f->hiredFrom; }
        if ($f->hiredTo)   { $w[] = 'e.hired_at <= :ht'; $p[':ht'] = $f->hiredTo; }
        // Стаж в компании (месяцы): отсекаем по дате приема.
        if ($f->experienceFrom !== null) {
            $w[] = 'e.hired_at <= :ef';
            $p[':ef'] = $this->monthsAgo($f->experienceFrom);
        }
        if ($f->experienceTo !== null) {
            $w[] = 'e.hired_at >= :et';
            $p[':et'] = $this->monthsAgo($f->experienceTo);
        }
        if ($f->scheduleFrom) {
            $w[] = 'EXISTS (SELECT 1 FROM employee_schedules y WHERE y.employee_id = e.id AND y.date_to >= :scf)';
            $p[':scf'] = $f->scheduleFrom;
        }
        if ($f->scheduleTo) {
            $w[] = 'EXISTS (SELECT 1 FROM employee_schedules y WHERE y.employee_id = e.id AND y.date_from <= :sct)';
            $p[':sct'] = $f->scheduleTo;
        }
        if ($f->onShift !== null) {
            $w[] = $f->onShift
                ? 'EXISTS (SELECT 1 FROM employee_attendance z WHERE z.employee_id = e.id AND z.on_shift = 1)'
                : 'NOT EXISTS (SELECT 1 FROM employee_attendance z WHERE z.employee_id = e.id AND z.on_shift = 1)';
        }
        if ($f->isBlocked !== null) {
            $w[] = 'e.is_blocked = ' . (int)$f->isBlocked;
        }
        if ($f->unplannedExit !== null) {
            $w[] = 'e.allow_unplanned_exit = ' . (int)$f->unplannedExit;
        }
        return ['WHERE ' . implode(' AND ', $w), $p];
    }

    /** Дата приёма, соответствующая стажу в месяцах. */
    private function monthsAgo(int $months): string
    {
        try {
            $d = new DateTimeImmutable('today');
            return $d->modify('-' . max(0, $months) . ' months')->format('Y-m-d');
        } catch (Throwable) {
            return $this->today();
        }
    }

    /** Уникальные значения колонки (непустые), по алфавиту. */
    private function distinct(string $column): array
    {
        $allowed = ['department', 'position'];
        if (!in_array($column, $allowed, true)) {
            return [];
        }
        try {
            $st = $this->pdo->prepare(
                "SELECT DISTINCT $column AS v FROM employees WHERE $column IS NOT NULL AND $column <> '' ORDER BY $column"
            );
            $st->execute();
            return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {
            return [];
        }
    }

    /** «08:00» из времени БД (может прийти как «08:00:00»). */
    private function hm(?string $t): string
    {
        $t = trim((string)$t);
        if ($t === '') {
            return '';
        }
        if (preg_match('/^(\d{1,2}):(\d{2})/', $t, $m)) {
            return sprintf('%02d:%s', (int)$m[1], $m[2]);
        }
        return $t;
    }
}
