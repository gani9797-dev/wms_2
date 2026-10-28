<?php
declare(strict_types=1);

// Права сотрудника по модулям WMS и ТСД — вкладка «Права и роли» карточки.
// Область scope: wms — модули WMS, tsd — терминал сбора данных.
// Уровень доступа: full (Полный доступ) / view (Только просмотр) / none (Нет доступа).
final class EmployeeAccess
{
    public const SCOPE_WMS = 'wms';
    public const SCOPE_TSD = 'tsd';

    public const LEVEL_FULL = 'full';
    public const LEVEL_VIEW = 'view';
    public const LEVEL_NONE = 'none';

    /** Ключ модуля ТСД — единственный. */
    public const TSD_MODULE = 'tsd';

    /** Модули WMS, к которым выдаются права: ключ => подпись в карточке. */
    public const WMS_MODULES = [
        'dashboard' => 'Дашборд',
        'reestr' => 'Реестр прибытия ТС',
        'post' => 'Поступления',
        'otg' => 'Отгрузки',
        'orders' => 'Заказы (3.1)',
        'pack' => 'Упаковка заказов (3.2)',
        'check' => 'Проверка заказов (3.3)',
        'int' => 'Внутрискладские операции',
        'yacheyki' => 'Ячейки хранения',
        'karta' => 'Карта склада 3D',
        'inv' => 'Инвентаризация',
        'zad' => 'Задания',
        'nomen' => 'Номенклатура',
        'stock' => 'Товарный запас',
        'cp' => 'Контрагенты и грузополучатели',
        'billing' => 'Биллинг',
        'emp' => 'Сотрудники',
        'kpi' => 'KPI',
        'reports' => 'Отчёты',
        'notif' => 'Уведомления',
        'settings' => 'Настройки',
        'journal' => 'Журнал',
        'labels' => 'Этикетки',
    ];

    /** Уровни доступа: ключ => подпись. */
    public const LEVELS = [
        self::LEVEL_FULL => 'Полный доступ',
        self::LEVEL_VIEW => 'Только просмотр',
        self::LEVEL_NONE => 'Нет доступа',
    ];

    public static function wmsKeys(): array
    {
        return array_keys(self::WMS_MODULES);
    }

    public static function levels(): array
    {
        return self::LEVELS;
    }

    public static function levelLabel(string $level): string
    {
        return self::LEVELS[$level] ?? $level;
    }

    public static function moduleLabel(string $scope, string $key): string
    {
        if ($scope === self::SCOPE_TSD) {
            return 'ТСД';
        }
        return self::WMS_MODULES[$key] ?? $key;
    }

    /** Приводит уровень к допустимому значению (пусто -> нет доступа). */
    public static function normalizeLevel(string $level): string
    {
        $level = trim($level);
        return isset(self::LEVELS[$level]) ? $level : self::LEVEL_NONE;
    }
}
