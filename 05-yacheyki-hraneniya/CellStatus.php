<?php
declare(strict_types=1);

// Статусы ячейки из схемы (бейджи реестра).
// Храним в БД как snake_case коды.

final class CellStatus
{
    public const FREE = 'free';           // Свободна
    public const BUSY = 'busy';           // Занята
    public const RESERVED = 'reserved';   // Зарезервирована
    public const BLOCKED = 'blocked';     // Заблокирована

    /** @return string[] */
    public static function values(): array
    {
        return [self::FREE, self::BUSY, self::RESERVED, self::BLOCKED];
    }

    public static function label(string $v): string
    {
        return match ($v) {
            self::FREE     => 'Свободна',
            self::BUSY     => 'Занята',
            self::RESERVED => 'Зарезервирована',
            self::BLOCKED  => 'Заблокирована',
            default        => $v,
        };
    }

    public static function zoneLabel(?string $v): string
    {
        return match ($v) {
            'storage'   => 'Хранение',
            'shipment'  => 'Отгрузка',
            'receiving' => 'Приёмка',
            default     => (string)$v,
        };
    }

    public static function typeLabel(?string $v): string
    {
        return match ($v) {
            'pallet' => 'Паллетная',
            'box'    => 'Коробочная',
            default  => (string)$v,
        };
    }

    // Пересчет статуса по факту: заблокированные и резерв не трогаем.
    public static function actual(string $current, float $qty): string
    {
        if (in_array($current, [self::BLOCKED, self::RESERVED], true)) {
            return $current;
        }
        return $qty > 0 ? self::BUSY : self::FREE;
    }
}
