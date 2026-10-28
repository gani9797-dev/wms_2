<?php
declare(strict_types=1);

require_once __DIR__ . '/EmployeeFilter.php';
require_once __DIR__ . '/EmployeeRepository.php';
require_once __DIR__ . '/EmployeeService.php';
require_once __DIR__ . '/EmployeeStatus.php';
require_once __DIR__ . '/EmployeeAccess.php';
require_once __DIR__ . '/../shared/Roles.php';

// REST-контроллер справочника сотрудников (модуль 10). Маппинг на UI макета:
//  GET  ?action=list&search=&page=1          -> реестр + «Показано X–Y из Z»
//  GET  ?action=show&id=1                    -> карточка (вкладка «Общие данные»)
//  GET  ?action=schedules|access|history|exits&id=1
//  POST ?action=store                        -> «+ Создать»
//  POST ?action=update&id=1                  -> «Редактировать»
//  POST ?action=block|unblock|tsd|unplanned&id=1
//  POST ?action=access_save|access_copy&id=1
//  POST ?action=schedule_add|schedule_delete
//  GET  ?action=export                       -> CSV
final class EmployeeController
{
    public function __construct(
        private EmployeeRepository $repo,
        private EmployeeService $service,
        private ?int $userId = null
    ) {}

    public function index(array $query): void
    {
        $f = EmployeeFilter::fromArray($query);
        $this->json($this->repo->paginate($f));
    }

    public function show(int $id): void
    {
        $row = $this->repo->findById($id);
        if (!$row) {
            $this->json(['error' => 'Не найдено'], 404);
            return;
        }
        $this->json($row);
    }

    public function store(array $body): void
    {
        $id = $this->service->create($body, (int)$this->userId);
        $this->json(['id' => $id], 201);
    }

    public function update(int $id, array $body): void
    {
        $this->service->update($id, $body, (int)$this->userId);
        $this->json(['ok' => true]);
    }

    public function delete(int $id): void
    {
        $this->service->delete($id, (int)$this->userId);
        $this->json(['ok' => true]);
    }

    public function block(int $id): void
    {
        $this->service->setBlocked($id, true, (int)$this->userId);
        $this->json(['ok' => true]);
    }

    public function unblock(int $id): void
    {
        $this->service->setBlocked($id, false, (int)$this->userId);
        $this->json(['ok' => true]);
    }

    /** Вкл/выкл доступ к ТСД («Снять ТСД»). */
    public function tsd(int $id, array $body): void
    {
        $on = !empty($body['on']);
        $this->service->setTsd($id, $on, (int)$this->userId);
        $this->json(['ok' => true]);
    }

    /** Разрешить внеплановый выход. */
    public function unplanned(int $id, array $body): void
    {
        $allow = !empty($body['allow']);
        $this->service->allowUnplannedExit($id, $allow, (int)$this->userId);
        $this->json(['ok' => true]);
    }

    public function access(int $id): void
    {
        $data = $this->service->roleAccessForEmployee($id);
        $this->json([
            'items' => $data['items'],
            'role' => $data['role'],
            'roles' => $this->service->roles(),
            'modules' => EmployeeAccess::WMS_MODULES,
            'levels' => EmployeeAccess::levels(),
            'users' => $this->repo->users(),
        ]);
    }

    public function accessSave(int $id, array $body): void
    {
        $items = [];
        foreach ((array)($body['items'] ?? []) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $items[] = [
                'scope' => (string)($it['scope'] ?? EmployeeAccess::SCOPE_WMS),
                'module_key' => (string)($it['module_key'] ?? ''),
                'level' => (string)($it['level'] ?? EmployeeAccess::LEVEL_NONE),
            ];
        }
        $this->service->saveRoleAccess($id, $items, (int)$this->userId);
        $this->json(['ok' => true]);
    }

    /** «+ добавить по аналогии с пользователем». */
    public function accessCopy(int $id, array $body): void
    {
        $source = (int)($body['user_id'] ?? 0);
        if ($source <= 0) {
            $this->json(['error' => 'Выберите пользователя'], 422);
            return;
        }
        $this->service->copyAccessFromUser($id, $source, (int)$this->userId);
        $this->json(['ok' => true]);
    }

    public function schedules(int $id): void
    {
        $this->json($this->repo->schedules($id));
    }

    public function scheduleAdd(int $id, array $body): void
    {
        $sid = $this->service->addSchedule($id, $body, (int)$this->userId);
        $this->json(['id' => $sid], 201);
    }

    public function scheduleDelete(int $id): void
    {
        $this->service->deleteSchedule($id, (int)$this->userId);
        $this->json(['ok' => true]);
    }

    public function history(int $id): void
    {
        $this->json($this->repo->history($id));
    }

    public function exits(int $id): void
    {
        $this->json($this->repo->exits($id));
    }

    public function defaults(): void
    {
        $this->json($this->service->defaults());
    }

    /** Данные для печати личного ШК. */
    public function print(int $id, array $query = []): void
    {
        $d = $this->service->printData($id);
        $fmt = (string)($query['format'] ?? '');
        if ($fmt === 'zpl' || $fmt === 'label') {
            require_once __DIR__ . '/../shared/Zpl.php';
            $w = (float)($query['w'] ?? 58.0);
            $h = (float)($query['h'] ?? 40.0);
            $copies = max(1, min(999, (int)($query['copies'] ?? 1)));
            $dpi = (int)($query['dpi'] ?? 203);
            try {
                if ($fmt === 'zpl') {
                    $zpl = wms_zpl_product_barcode((string)$d['code'], (string)$d['name'], (string)$d['code'], $w, $h, $copies, $dpi);
                    if (!headers_sent()) {
                        header('Content-Type: text/plain; charset=utf-8');
                        header('Content-Disposition: attachment; filename="employee_' . (int)$d['id'] . '.zpl"');
                    }
                    echo $zpl;
                } else {
                    if (!headers_sent()) {
                        header('Content-Type: text/html; charset=utf-8');
                    }
                    echo wms_label_html(
                        (string)$d['code'],
                        (string)$d['name'],
                        (string)$d['code'],
                        $w,
                        $h,
                        $copies,
                        !empty($query['autoprint'])
                    );
                }
            } catch (Throwable $e) {
                http_response_code(400);
                if (!headers_sent()) {
                    header('Content-Type: text/plain; charset=utf-8');
                }
                echo 'Ошибка печати: ' . $e->getMessage();
            }
            return;
        }
        $this->json($d);
    }

    public function export(array $query): void
    {
        $f = EmployeeFilter::fromArray($query);
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="employees.csv"');
        }
        echo $this->service->exportCsv($f, $this->repo);
    }

    private function json($data, int $code = 200): void
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
